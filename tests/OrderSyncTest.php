<?php
/**
 * Tests for the order upload, the delivery import and the preconditions gate.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers pushing orders to Kontor and pulling delivery details back.
 */
class OrderSyncTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Clear state that outlives a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();
		parent::tear_down();
	}

	/**
	 * Fully configured settings, including a shop.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Settings array.
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
				'shoptype'     => 'B2C',
				'shop_id'      => self::SHOP_ID,
				'shop_name'    => 'Edu-Shop',
			),
			$overrides
		);
	}

	/**
	 * Build a paid order with one line item.
	 *
	 * @param string $sku Product SKU for the line.
	 * @return WC_Order The saved order.
	 */
	private function make_order( $sku = 'abel-AB12' ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_currency( 'EUR' );
		$order->set_billing_first_name( 'Max' );
		$order->set_billing_last_name( 'Mustermann' );
		$order->set_billing_email( 'max@example.com' );
		$order->set_billing_address_1( 'Musterstraße 1' );
		$order->set_billing_postcode( '50667' );
		$order->set_billing_city( 'Köln' );
		$order->set_billing_country( 'DE' );
		$order->add_product( $product, 2 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Capture the request and reply with a canned envelope.
	 *
	 * @param array $body Envelope to encode.
	 * @return void
	 */
	private function fake_response( array $body ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $body ) {
				$this->captured = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
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
	 * The last captured request.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * An upsert reply accepting one order.
	 *
	 * @param string $number  Order number to echo back.
	 * @param string $status  Row status, "ok" or "fehler".
	 * @param string $message Row message.
	 * @return array Envelope.
	 */
	private function upsert_reply( $number, $status = 'ok', $message = null ) {
		return array(
			'success' => true,
			'message' => 'Operation completed successfully',
			'meta'    => array( 'rowCount' => 1 ),
			'data'    => array(
				array(
					'orderId'     => $number,
					'orderNumber' => $number,
					'auftrnr'     => 'ok' === $status ? 'AW 214807' : null,
					'status'      => $status,
					'message'     => $message,
				),
			),
		);
	}

	/**
	 * An order is mapped into the shape the upsert endpoint documents.
	 *
	 * @return void
	 */
	public function test_order_is_mapped_to_the_api_shape() {
		$order   = $this->make_order();
		$sync    = new OrderSync( null, $this->settings() );
		$payload = $sync->build_payload( $order );

		$this->assertSame( (string) $order->get_order_number(), $payload['orderNumber'] );
		$this->assertSame( 'EUR', $payload['currency'] );
		$this->assertSame( 'max@example.com', $payload['customerEmail'] );
		$this->assertSame( 'DE', $payload['billingAddress']['countryCode'] );
		$this->assertSame( 'Köln', $payload['billingAddress']['city'] );

		// orderDate is ISO 8601 in UTC, as the schema requires.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $payload['orderDate'] );

		$this->assertCount( 1, $payload['items'] );
		$this->assertSame( 'abel-AB12', $payload['items'][0]['sku'] );
		$this->assertSame( 2.0, $payload['items'][0]['quantity'] );
		$this->assertSame( 20.0, $payload['items'][0]['totalPrice'] );
		$this->assertSame( 10.0, $payload['items'][0]['unitPrice'] );
		$this->assertSame( 1, $payload['items'][0]['position'] );

		// orderPlatformid is optional and deliberately not sent.
		$this->assertArrayNotHasKey( 'orderPlatformid', $payload );
	}

	/**
	 * The upload user ID is fixed rather than derived or filterable.
	 *
	 * The settings screen shows it read-only, so anything able to change it would
	 * make that display disagree with what is actually sent.
	 *
	 * @return void
	 */
	public function test_upload_user_id_is_fixed() {
		$this->assertSame( 'CG', OrderSync::UPLOAD_USER_ID );

		$order = $this->make_order();

		$this->fake_response( $this->upsert_reply( (string) $order->get_order_number() ) );

		add_filter(
			'woo_kontor_sync_upload_user_id',
			static function () {
				return 'TAMPERED';
			}
		);

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$this->assertSame( 'CG', $this->captured['body']['meta']['userId'] );

		remove_all_filters( 'woo_kontor_sync_upload_user_id' );
	}

	/**
	 * No order in a batch carries a platform identifier.
	 *
	 * @return void
	 */
	public function test_platform_id_is_never_sent() {
		$order = $this->make_order();

		$this->fake_response( $this->upsert_reply( (string) $order->get_order_number() ) );

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		foreach ( $this->captured['body']['params']['orders'] as $sent ) {
			$this->assertArrayNotHasKey( 'orderPlatformid', $sent );
		}
	}

	/**
	 * A line with no SKU is left out rather than sent unresolvable.
	 *
	 * SKU is the only key Kontor matches articles on, so a line without one would be
	 * silently dropped at the far end of an otherwise valid order.
	 *
	 * @return void
	 */
	public function test_line_without_a_sku_is_omitted() {
		$product = new WC_Product_Simple();
		$product->set_regular_price( '5.00' );
		$product->save();

		$order = $this->make_order();
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		$payload = ( new OrderSync( null, $this->settings() ) )->build_payload( $order );

		$this->assertCount( 1, $payload['items'] );
		$this->assertSame( 'abel-AB12', $payload['items'][0]['sku'] );
	}

	/**
	 * The upsert request carries the name, userId and shop the API requires.
	 *
	 * @return void
	 */
	public function test_upsert_request_shape() {
		$order = $this->make_order();

		$this->fake_response( $this->upsert_reply( (string) $order->get_order_number() ) );

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$this->assertSame( 'https://erp.example.test/api/v1/kontor/upsert', $this->captured['url'] );
		$this->assertSame( 'orders', $this->captured['body']['name'] );
		$this->assertSame( self::SHOP_ID, $this->captured['body']['params']['shopid'] );

		// meta.userId is required by the API and fixed by agreement with Kontor.
		$this->assertSame( 'CG', $this->captured['body']['meta']['userId'] );

		// overwrite_all stays false, which is what makes a duplicate a no-op.
		$this->assertFalse( $this->captured['body']['params']['overwrite_all'] );
		$this->assertCount( 1, $this->captured['body']['params']['orders'] );
	}

	/**
	 * An accepted order records Kontor's own order number.
	 *
	 * @return void
	 */
	public function test_accepted_order_is_marked_as_pushed() {
		$order = $this->make_order();

		$this->fake_response( $this->upsert_reply( (string) $order->get_order_number() ) );

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$refreshed = wc_get_order( $order->get_id() );

		$this->assertSame( 'AW 214807', $refreshed->get_meta( OrderSync::META_KONTOR_ORDER ) );
		$this->assertNotEmpty( $refreshed->get_meta( OrderSync::META_PUSHED_AT ) );
		$this->assertSame( (string) $order->get_order_number(), $refreshed->get_meta( OrderSync::META_ORDER_NUMBER ) );
	}

	/**
	 * A duplicate counts as delivered rather than as a failure.
	 *
	 * The top-level success stays true on a business failure, so the row status is
	 * the only signal. "Dublette" means the order is already in Kontor, which is the
	 * outcome this job exists to reach — retrying it forever would be wrong.
	 *
	 * @return void
	 */
	public function test_duplicate_is_treated_as_already_delivered() {
		$order = $this->make_order();

		$this->fake_response(
			$this->upsert_reply(
				(string) $order->get_order_number(),
				'fehler',
				'Auftrag bereits vorhanden (Dublette).'
			)
		);

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$refreshed = wc_get_order( $order->get_id() );

		$this->assertNotEmpty( $refreshed->get_meta( OrderSync::META_PUSHED_AT ) );
		$this->assertSame( '', (string) $refreshed->get_meta( OrderSync::META_PUSH_ERROR ) );
	}

	/**
	 * A genuine rejection is recorded and the order stays unsent.
	 *
	 * @return void
	 */
	public function test_rejected_order_is_not_marked_as_pushed() {
		$order = $this->make_order();

		$this->fake_response(
			$this->upsert_reply( (string) $order->get_order_number(), 'fehler', 'Artikel nicht gefunden.' )
		);

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$refreshed = wc_get_order( $order->get_id() );

		$this->assertSame( '', (string) $refreshed->get_meta( OrderSync::META_PUSHED_AT ) );
		$this->assertSame( 'Artikel nicht gefunden.', $refreshed->get_meta( OrderSync::META_PUSH_ERROR ) );
	}

	/**
	 * An order already sent is not sent a second time.
	 *
	 * @return void
	 */
	public function test_pushed_order_is_not_sent_again() {
		$order = $this->make_order();
		$order->update_meta_data( OrderSync::META_PUSHED_AT, time() );
		$order->save();

		$this->captured = array();
		$this->fake_response( $this->upsert_reply( (string) $order->get_order_number() ) );

		( new OrderSync( null, $this->settings() ) )->push_one( $order->get_id() );

		$this->assertSame( array(), $this->captured );
	}

	/**
	 * Delivery rows are matched on the order number that was sent.
	 *
	 * @return void
	 */
	public function test_delivery_details_are_applied() {
		$order = $this->make_order();
		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_order_number() );
		$order->save();

		$counts = ( new DeliverySync( null, $this->settings() ) )->apply(
			array(
				(string) $order->get_order_number() => array(
					'auftrnr'      => 'AW 214805',
					'status'       => 'in_progress',
					'provider'     => 'planzer',
					'tracking'     => '913368990400000188001',
					'tracking_url' => 'https://tracking.example.test/?p=913368990400000188001',
				),
			)
		);

		$refreshed = wc_get_order( $order->get_id() );

		$this->assertSame( 1, $counts['updated'] );
		$this->assertSame( 0, $counts['completed'] );
		$this->assertSame( 'planzer', $refreshed->get_meta( DeliverySync::META_PROVIDER ) );
		$this->assertSame( '913368990400000188001', $refreshed->get_meta( DeliverySync::META_TRACKING ) );
		$this->assertSame( 'in_progress', $refreshed->get_meta( DeliverySync::META_STATUS ) );

		// The Kontor order number is backfilled for anything accepted as a duplicate.
		$this->assertSame( 'AW 214805', $refreshed->get_meta( OrderSync::META_KONTOR_ORDER ) );

		// The order is still being processed, so its status is untouched.
		$this->assertSame( 'processing', $refreshed->get_status() );
	}

	/**
	 * A completed order in Kontor completes the order here.
	 *
	 * @return void
	 */
	public function test_completed_in_kontor_completes_the_order() {
		$order = $this->make_order();
		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_order_number() );
		$order->save();

		$counts = ( new DeliverySync( null, $this->settings() ) )->apply(
			array(
				(string) $order->get_order_number() => array(
					'auftrnr'      => 'AW 214805',
					'status'       => 'completed',
					'provider'     => 'planzer',
					'tracking'     => '913368990400000188001',
					'tracking_url' => '',
				),
			)
		);

		$this->assertSame( 1, $counts['completed'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * A cancelled order is not resurrected by a completed row.
	 *
	 * Completing it would email a customer about an order that is not happening.
	 *
	 * @return void
	 */
	public function test_cancelled_order_is_left_alone() {
		$order = $this->make_order();
		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_order_number() );
		$order->set_status( 'cancelled' );
		$order->save();

		$counts = ( new DeliverySync( null, $this->settings() ) )->apply(
			array(
				(string) $order->get_order_number() => array(
					'auftrnr'      => 'AW 214805',
					'status'       => 'completed',
					'provider'     => '',
					'tracking'     => '',
					'tracking_url' => '',
				),
			)
		);

		$this->assertSame( 0, $counts['completed'] );
		$this->assertSame( 'cancelled', wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * A row for an order this site does not have is counted, not guessed at.
	 *
	 * @return void
	 */
	public function test_unknown_order_number_is_counted_as_missing() {
		$counts = ( new DeliverySync( null, $this->settings() ) )->apply(
			array(
				'NOT-OURS-9999' => array(
					'auftrnr'      => 'AW 111111',
					'status'       => 'completed',
					'provider'     => '',
					'tracking'     => '',
					'tracking_url' => '',
				),
			)
		);

		$this->assertSame( 1, $counts['missing'] );
		$this->assertSame( 0, $counts['updated'] );
	}

	/**
	 * Applying the same row twice does not add a second order note.
	 *
	 * @return void
	 */
	public function test_unchanged_row_is_not_reapplied() {
		$order = $this->make_order();
		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_order_number() );
		$order->save();

		$row = array(
			(string) $order->get_order_number() => array(
				'auftrnr'      => 'AW 214805',
				'status'       => 'in_progress',
				'provider'     => 'planzer',
				'tracking'     => '913368990400000188001',
				'tracking_url' => '',
			),
		);

		$sync = new DeliverySync( null, $this->settings() );

		$this->assertSame( 1, $sync->apply( $row )['updated'] );
		$this->assertSame( 1, $sync->apply( $row )['unchanged'] );
	}

	/**
	 * The orders search is filtered by shop, which is the only filter it honours.
	 *
	 * @return void
	 */
	public function test_orders_search_is_filtered_by_shop() {
		$this->fake_response(
			array(
				'success' => true,
				'meta'    => array( 'rowCount' => 0 ),
				'data'    => array(),
			)
		);

		( new Client( $this->settings() ) )->fetch_orders( self::SHOP_ID );

		$this->assertSame(
			array(
				'entity' => 'orders',
				'filter' => array( 'shopid' => self::SHOP_ID ),
			),
			$this->captured['body']
		);
	}

	/**
	 * Without credentials, nothing may run.
	 *
	 * @return void
	 */
	public function test_jobs_are_blocked_without_credentials() {
		foreach ( array( 'products', 'stock', OrderSync::JOB, DeliverySync::JOB ) as $job ) {
			$blocked = Preflight::check( $job, $this->settings( array( 'api_key' => '' ) ) );

			$this->assertSame( 'wksync_not_configured', $blocked->get_error_code(), $job . ' ran without a key' );
		}

		$blocked = Preflight::check( 'products', $this->settings( array( 'api_base_url' => '' ) ) );

		$this->assertSame( 'wksync_not_configured', $blocked->get_error_code() );
	}

	/**
	 * Without a shop, only the order jobs are blocked.
	 *
	 * @return void
	 */
	public function test_order_jobs_are_blocked_without_a_shop() {
		$settings = $this->settings( array( 'shop_id' => '' ) );

		$this->assertSame( 'wksync_no_shop', Preflight::check( OrderSync::JOB, $settings )->get_error_code() );
		$this->assertSame( 'wksync_no_shop', Preflight::check( DeliverySync::JOB, $settings )->get_error_code() );

		// A malformed shop ID is refused too: Kontor answers one with an HTTP 500.
		$this->assertSame(
			'wksync_no_shop',
			Preflight::check( OrderSync::JOB, $this->settings( array( 'shop_id' => 'not-a-guid' ) ) )->get_error_code()
		);

		// Product and stock sync do not use the shop, so a missing one is irrelevant.
		$this->assertSame( 'wksync_connection_failed', Preflight::check( 'products', $settings )->get_error_code() );
	}

	/**
	 * An invalid connection blocks a job, and is not cached as if it worked.
	 *
	 * @return void
	 */
	public function test_invalid_connection_blocks_a_job() {
		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success'   => false,
							'message'   => 'Ungültiger API-Key',
							'errorCode' => 'ERR-401-INVALID-API-KEY',
						)
					),
					'response' => array(
						'code'    => 401,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$blocked = Preflight::check( 'products', $this->settings() );

		$this->assertSame( 'wksync_connection_failed', $blocked->get_error_code() );

		// The API's own wording and code are surfaced rather than ours.
		$this->assertStringContainsString( 'Ungültiger API-Key', $blocked->get_error_message() );
		$this->assertStringContainsString( 'ERR-401-INVALID-API-KEY', $blocked->get_error_message() );

		// A failure is never cached, so fixing the key takes effect immediately.
		$this->assertFalse( get_transient( Preflight::CONNECTION_CACHE ) );
	}

	/**
	 * A product sync refuses to start unconfigured, and drafts nothing.
	 *
	 * An unauthenticated run would look like "Kontor lists no articles", and the
	 * finalising pass would then draft the entire shop.
	 *
	 * @return void
	 */
	public function test_product_sync_refuses_to_start_unconfigured() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_status( 'publish' );
		$product->save();

		( new \WooKontorSync\Sync\ProductSync( null, $this->settings( array( 'api_key' => '' ) ) ) )->start();

		$status = Status::get( 'products' );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'not configured', $status['message'] );

		// Nothing was touched.
		$this->assertSame( 'publish', wc_get_product( $product->get_id() )->get_status() );
	}

	/**
	 * A stock sync refuses to start unconfigured.
	 *
	 * @return void
	 */
	public function test_stock_sync_refuses_to_start_unconfigured() {
		( new \WooKontorSync\Sync\StockSync( null, $this->settings( array( 'api_base_url' => '' ) ) ) )->start();

		$this->assertSame( 'failed', Status::get( 'stock' )['state'] );
	}

	/**
	 * An order sync refuses to start with no shop selected.
	 *
	 * @return void
	 */
	public function test_order_sync_refuses_to_start_without_a_shop() {
		( new OrderSync( null, $this->settings( array( 'shop_id' => '' ) ) ) )->start();

		$status = Status::get( OrderSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'shop', $status['message'] );
	}

	/**
	 * A delivery sync refuses to start with no shop selected.
	 *
	 * @return void
	 */
	public function test_delivery_sync_refuses_to_start_without_a_shop() {
		( new DeliverySync( null, $this->settings( array( 'shop_id' => '' ) ) ) )->start();

		$this->assertSame( 'failed', Status::get( DeliverySync::JOB )['state'] );
	}

	/**
	 * Only paid statuses are pushed.
	 *
	 * @return void
	 */
	public function test_only_paid_statuses_are_pushed() {
		$statuses = OrderSync::pushable_statuses();

		$this->assertContains( 'processing', $statuses );
		$this->assertContains( 'completed', $statuses );
		$this->assertNotContains( 'pending', $statuses );
		$this->assertNotContains( 'on-hold', $statuses );
		$this->assertNotContains( 'cancelled', $statuses );
		$this->assertNotContains( 'refunded', $statuses );
	}
}
