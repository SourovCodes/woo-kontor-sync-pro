<?php
/**
 * Tests for the Kontor API client.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Api\Client;
use WP_UnitTestCase;

/**
 * Covers request shape and envelope handling without touching the network.
 */
class ClientTest extends WP_UnitTestCase {

	/**
	 * The request the client last attempted.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Remove the HTTP short-circuit between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		parent::tear_down();
	}

	/**
	 * Settings pointing at a fake endpoint.
	 *
	 * @return array Settings array.
	 */
	private function settings() {
		return array(
			'api_base_url' => 'https://erp.example.test/api/v1/kontor',
			'api_key'      => 'test-key-123',
			'shoptype'     => 'B2C',
		);
	}

	/**
	 * Short-circuit wp_remote_request() with a canned reply.
	 *
	 * @param int   $status HTTP status to return.
	 * @param array $body   Envelope to encode as the body.
	 * @return void
	 */
	private function fake_response( $status, array $body ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $status, $body ) {
				$this->captured = array(
					'url'  => $url,
					'args' => $args,
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * A successful envelope yields its data and meta.
	 *
	 * @return void
	 */
	public function test_successful_envelope_returns_data_and_meta() {
		$this->fake_response(
			200,
			array(
				'success'   => true,
				'message'   => 'Search completed successfully',
				'meta'      => array(
					'rowCount'   => 1,
					'totalCount' => 4386,
				),
				'data'      => array( array( 'Artnr' => 'abel-AB12' ) ),
				'errorCode' => null,
			)
		);

		$result = ( new Client( $this->settings() ) )->fetch_products( 0, 1 );

		$this->assertIsArray( $result );
		$this->assertSame( 4386, $result['meta']['totalCount'] );
		$this->assertSame( 'abel-AB12', $result['data'][0]['Artnr'] );
	}

	/**
	 * The request goes to /search with the key in the x-api-key header.
	 *
	 * @return void
	 */
	public function test_request_shape_matches_the_kontor_api() {
		$this->fake_response(
			200,
			array(
				'success' => true,
				'data'    => array(),
				'meta'    => array(),
			)
		);

		( new Client( $this->settings() ) )->fetch_products( 500, 250 );

		$this->assertSame( 'https://erp.example.test/api/v1/kontor/search', $this->captured['url'] );
		$this->assertSame( 'POST', $this->captured['args']['method'] );
		$this->assertSame( 'test-key-123', $this->captured['args']['headers']['x-api-key'] );

		// The key must never be sent as a bearer token.
		$this->assertArrayNotHasKey( 'Authorization', $this->captured['args']['headers'] );

		$body = json_decode( $this->captured['args']['body'], true );

		$this->assertSame( 'products', $body['entity'] );
		$this->assertSame( 500, $body['paging']['skip'] );
		$this->assertSame( 250, $body['paging']['take'] );
		$this->assertSame( 'B2C', $body['filter']['shoptype'] );
	}

	/**
	 * The stock entity is sent without paging or filter, as the API expects.
	 *
	 * @return void
	 */
	public function test_stock_request_carries_no_paging_or_filter() {
		$this->fake_response(
			200,
			array(
				'success' => true,
				'data'    => array(),
				'meta'    => array(),
			)
		);

		( new Client( $this->settings() ) )->fetch_stock();

		$body = json_decode( $this->captured['args']['body'], true );

		$this->assertSame( array( 'entity' => 'stock' ), $body );
	}

	/**
	 * Page size is clamped to what the server will actually return.
	 *
	 * Asking for more than 2000 silently returns 2000, which would make the paging
	 * arithmetic skip records.
	 *
	 * @return void
	 */
	public function test_page_size_is_clamped_to_the_server_cap() {
		$this->fake_response(
			200,
			array(
				'success' => true,
				'data'    => array(),
				'meta'    => array(),
			)
		);

		( new Client( $this->settings() ) )->fetch_products( 0, 5000 );

		$body = json_decode( $this->captured['args']['body'], true );

		$this->assertSame( Client::MAX_PAGE_SIZE, $body['paging']['take'] );
	}

	/**
	 * A rejected key surfaces Kontor's own message and error code.
	 *
	 * @return void
	 */
	public function test_invalid_key_surfaces_the_api_error() {
		$this->fake_response(
			401,
			array(
				'success'   => false,
				'message'   => 'Ungültiger API-Key',
				'errorCode' => 'ERR-401-INVALID-API-KEY',
			)
		);

		$result = ( new Client( $this->settings() ) )->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'Ungültiger API-Key', $result->get_error_message() );
		$this->assertSame( 'ERR-401-INVALID-API-KEY', Client::detail( $result, 'error_code' ) );

		// A 401 is a configuration problem, so it must not be retried.
		$this->assertSame( 'fail', Client::detail( $result, 'disposition' ) );
	}

	/**
	 * A 401 is attempted exactly once.
	 *
	 * @return void
	 */
	public function test_client_errors_are_not_retried() {
		$attempts = $this->count_attempts(
			401,
			array(
				'success' => false,
				'message' => 'Ungültiger API-Key',
			)
		);

		$this->assertSame( 1, $attempts );
	}

	/**
	 * A 503 is retried up to the attempt limit.
	 *
	 * This is the regression guard for reading retry state off WP_Error. Fetching it
	 * with get_error_data( 'disposition' ) returns null, which silently turns every
	 * retry into a single attempt.
	 *
	 * @return void
	 */
	public function test_transient_failures_are_retried() {
		$attempts = $this->count_attempts(
			503,
			array(
				'success' => false,
				'message' => 'Service unavailable',
			)
		);

		$this->assertSame( Client::MAX_ATTEMPTS, $attempts );
	}

	/**
	 * Run a request against a failing endpoint and count the attempts made.
	 *
	 * @param int   $status HTTP status to return every time.
	 * @param array $body   Envelope to return.
	 * @return int Number of requests the client issued.
	 */
	private function count_attempts( $status, array $body ) {
		$attempts = 0;

		// Skip the real backoff so the suite does not sleep for six seconds.
		add_filter( 'woo_kontor_sync_retry_delay', '__return_zero' );

		add_filter(
			'pre_http_request',
			function () use ( &$attempts, $status, $body ) {
				++$attempts;

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $status,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		( new Client( $this->settings() ) )->fetch_stock();

		remove_filter( 'woo_kontor_sync_retry_delay', '__return_zero' );

		return $attempts;
	}

	/**
	 * A missing key is reported before any request is made.
	 *
	 * @return void
	 */
	public function test_missing_key_short_circuits() {
		$settings            = $this->settings();
		$settings['api_key'] = '';

		$result = ( new Client( $settings ) )->test_connection();

		$this->assertWPError( $result );
		$this->assertSame( 'woo_kontor_sync_not_configured', $result->get_error_code() );
		$this->assertSame( array(), $this->captured );
	}
}
