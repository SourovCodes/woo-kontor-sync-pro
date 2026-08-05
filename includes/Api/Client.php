<?php
/**
 * HTTP client for the Kontor ERP REST API.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Api;

use WooKontorSync\Admin\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Talks to Kontor's search API over HTTP.
 *
 * Every endpoint is the same POST to /search, distinguished by an "entity" in the
 * body. Requests are retried with exponential backoff on transient failures, and
 * writes carry an idempotency key so a retry cannot duplicate a record.
 */
class Client {

	/**
	 * Log source used for every message this class emits.
	 */
	const LOG_SOURCE = 'woo-kontor-sync';

	/**
	 * Maximum number of attempts per request, including the first.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Base delay in seconds used for exponential backoff between attempts.
	 */
	const BACKOFF_BASE_SECONDS = 2;

	/**
	 * Request timeout in seconds.
	 *
	 * Generous because a full product page can take a couple of seconds server-side,
	 * and these requests only ever run inside a background job.
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Largest page the API will actually return.
	 *
	 * The server silently caps "take": asking for 5000 returns 2000. Requesting more
	 * than this would make the paging arithmetic skip records.
	 */
	const MAX_PAGE_SIZE = 2000;

	/**
	 * Page size used when walking the product catalogue.
	 *
	 * Sized so one page fits comfortably inside an Action Scheduler pass: saving 500
	 * products took around 78 seconds, which is long enough to risk being cut short
	 * on a slower host.
	 */
	const PRODUCT_PAGE_SIZE = 200;

	/**
	 * HTTP status codes worth retrying. Client errors are bugs, not blips.
	 *
	 * @var int[]
	 */
	private static $retryable_statuses = array( 429, 500, 502, 503, 504 );

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param array|null $settings Optional settings override, mainly for tests.
	 */
	public function __construct( $settings = null ) {
		$this->settings = null === $settings ? Settings::get_settings() : $settings;
	}

	/**
	 * Run a search against one of Kontor's entities.
	 *
	 * @param string $entity Entity name, for example "products" or "stock".
	 * @param array  $args   Extra body arguments such as paging and filter.
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function search( $entity, array $args = array() ) {
		$body = array_merge( array( 'entity' => $entity ), $args );

		return $this->request( 'POST', 'search', $body );
	}

	/**
	 * Fetch a page of products for the configured shop type.
	 *
	 * The shop type does not change which articles come back; it changes their
	 * pricing. UVP is the shop type's selling price, while Ek stays constant.
	 *
	 * @param int         $skip          Number of records to skip.
	 * @param int         $take          Page size, capped at MAX_PAGE_SIZE.
	 * @param string|null $shoptype      Optional shop type override.
	 * @param array       $manufacturers Manufacturer IDs to restrict the catalogue to; empty for all.
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function fetch_products( $skip = 0, $take = self::PRODUCT_PAGE_SIZE, $shoptype = null, array $manufacturers = array() ) {
		if ( null === $shoptype ) {
			$shoptype = isset( $this->settings['shoptype'] ) ? (string) $this->settings['shoptype'] : 'B2B';
		}

		$filter = array( 'shoptype' => $shoptype );

		/*
		 * Sent as one comma-separated string rather than a list, which is the shape the
		 * API accepts. The IDs stay strings: they carry leading zeros, and "084" is not
		 * the same manufacturer as "84".
		 */
		if ( ! empty( $manufacturers ) ) {
			$filter['herstellerids'] = implode( ',', array_map( 'strval', $manufacturers ) );
		}

		return $this->search(
			'products',
			array(
				'paging' => array(
					'skip' => max( 0, absint( $skip ) ),
					'take' => min( self::MAX_PAGE_SIZE, max( 1, absint( $take ) ) ),
				),
				'filter' => $filter,
			)
		);
	}

	/**
	 * Fetch the manufacturers Kontor knows about.
	 *
	 * Takes no paging, like stock and shops. Each row carries a Herstellerid and the
	 * Hersteller display name, which is the same pairing that arrives on an article.
	 *
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function fetch_manufacturers() {
		return $this->search( 'manufacturer' );
	}

	/**
	 * Fetch stock levels for every article.
	 *
	 * The stock entity takes no paging and no filter, and returns one row per
	 * article number.
	 *
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function fetch_stock() {
		return $this->search( 'stock' );
	}

	/**
	 * Fetch the shops configured in Kontor.
	 *
	 * A short list — 13 rows on the account this was built against — so the entity
	 * takes no paging, like stock. Each row is a Shopid GUID and a display Name.
	 *
	 * The shop is not used by product or stock sync. It identifies which shop an
	 * order belongs to when orders are pushed and delivery information is pulled
	 * back, so it has to be chosen before either of those can run.
	 *
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function fetch_shops() {
		return $this->search( 'shops' );
	}

	/**
	 * Fetch order status and delivery information for one shop.
	 *
	 * The orders entity honours only filter.shopid — every other filter key is
	 * silently ignored, so there is no point narrowing by order number or date. A
	 * missing or unknown-but-well-formed shop ID comes back as an empty list, but a
	 * malformed one is an HTTP 500, which is why the caller must validate the GUID
	 * before getting here.
	 *
	 * The result set is capped around 1000 rows server-side.
	 *
	 * @param string $shop_id Kontor shop GUID.
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function fetch_orders( $shop_id ) {
		return $this->search( 'orders', array( 'filter' => array( 'shopid' => (string) $shop_id ) ) );
	}

	/**
	 * Upload orders to Kontor.
	 *
	 * The only write endpoint, and it does not fail the way the read ones do: the
	 * top-level success stays true even when every order in the batch was rejected.
	 * The caller has to inspect each row of the reply, which is what
	 * OrderSync::interpret_rows() is for.
	 *
	 * Deduplication is Kontor's own, on orderNumber, with overwrite_all left false:
	 * re-sending an order already there is answered with a per-row "Dublette" rather
	 * than creating a second one. That is what makes a retry safe.
	 *
	 * @param array  $orders   Orders in the API's shape, already mapped.
	 * @param string $shop_id  Kontor shop GUID.
	 * @param string $user_id  Value for the required meta.userId.
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function push_orders( array $orders, $shop_id, $user_id ) {
		$body = array(
			'name'   => 'orders',
			'meta'   => array( 'userId' => (string) $user_id ),
			'params' => array(
				'shopid'        => (string) $shop_id,
				'overwrite_all' => false,
				'orders'        => array_values( $orders ),
			),
		);

		/*
		 * The idempotency key covers the order numbers in the batch. Kontor dedupes on
		 * orderNumber regardless, so this is belt and braces for a retry that never
		 * reaches the application layer.
		 */
		$numbers = array();

		foreach ( $orders as $order ) {
			$numbers[] = isset( $order['orderNumber'] ) ? (string) $order['orderNumber'] : '';
		}

		return $this->request( 'POST', 'upsert', $body, md5( (string) $shop_id . '|' . implode( ',', $numbers ) ) );
	}

	/**
	 * Check that the configured base URL and API key work.
	 *
	 * Asks for a single product so the round trip stays cheap.
	 *
	 * @return array|WP_Error Array with "data" and "meta" keys, or WP_Error on failure.
	 */
	public function test_connection() {
		return $this->fetch_products( 0, 1 );
	}

	/**
	 * Read one of the detail values this class attaches to its errors.
	 *
	 * WP_Error::get_error_data() takes an error *code*, not a data key, so the data
	 * array has to be fetched whole and indexed here. Getting that wrong silently
	 * disables retries, because every lookup comes back null.
	 *
	 * @param mixed  $error    Value that may be a WP_Error.
	 * @param string $key      Detail to read, such as "disposition" or "error_code".
	 * @param string $fallback Value to use when the detail is absent.
	 * @return string The detail value.
	 */
	public static function detail( $error, $key, $fallback = '' ) {
		if ( ! is_wp_error( $error ) ) {
			return $fallback;
		}

		$data = $error->get_error_data();

		return is_array( $data ) && isset( $data[ $key ] ) ? (string) $data[ $key ] : $fallback;
	}

	/**
	 * Perform a request, retrying transient failures.
	 *
	 * @param string      $method          HTTP method.
	 * @param string      $endpoint        Endpoint path, relative to the configured base URL.
	 * @param array|null  $body            Optional request body.
	 * @param string|null $idempotency_key Optional idempotency key for writes.
	 * @return array|WP_Error Decoded payload, or WP_Error on failure.
	 */
	protected function request( $method, $endpoint, $body = null, $idempotency_key = null ) {
		$url = $this->build_url( $endpoint );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$key = $this->get_api_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => $this->build_headers( $key, $idempotency_key ),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		// Reads are selected by "entity", the single write endpoint by "name".
		$selector = '';

		if ( isset( $body['entity'] ) ) {
			$selector = (string) $body['entity'];
		} elseif ( isset( $body['name'] ) ) {
			$selector = (string) $body['name'];
		}

		$label      = '' === $selector ? $endpoint : $endpoint . ':' . $selector;
		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			$result   = $this->interpret_response( $response, $method, $label, $attempt );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( 'retry' !== self::detail( $result, 'disposition', 'fail' ) || self::MAX_ATTEMPTS === $attempt ) {
				break;
			}

			/**
			 * Filters the backoff delay between retries.
			 *
			 * Defaults to exponential backoff: 2s, then 4s. Kept short because this
			 * runs inside an Action Scheduler job, which retries the whole action if
			 * we ultimately fail.
			 *
			 * @since 0.2.0
			 *
			 * @param int $delay   Delay in seconds.
			 * @param int $attempt Attempt number that just failed.
			 */
			$delay = (int) apply_filters(
				'woo_kontor_sync_retry_delay',
				self::BACKOFF_BASE_SECONDS ** $attempt,
				$attempt
			);

			if ( $delay > 0 ) {
				sleep( $delay );
			}
		}

		return $last_error;
	}

	/**
	 * Turn a raw HTTP response into decoded data or a descriptive error.
	 *
	 * Kontor wraps every reply in an envelope: a boolean "success", a "message", a
	 * "meta" block with row counts, the "data" rows, and an "errorCode" on failure.
	 * A non-2xx status still carries that envelope, so the message it contains is
	 * what gets surfaced to the user.
	 *
	 * @param array|WP_Error $response Raw response from wp_remote_request().
	 * @param string         $method   HTTP method, for logging.
	 * @param string         $label    Request label, for logging.
	 * @param int            $attempt  Attempt number, for logging.
	 * @return array|WP_Error Decoded payload, or an error carrying a "disposition" of retry or fail.
	 */
	protected function interpret_response( $response, $method, $label, $attempt ) {
		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				sprintf( '%s %s failed on attempt %d: %s', $method, $label, $attempt, $response->get_error_message() )
			);

			return new WP_Error(
				'woo_kontor_sync_transport_error',
				$response->get_error_message(),
				array( 'disposition' => 'retry' )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			$this->log( 'error', sprintf( '%s %s returned malformed JSON (HTTP %d).', $method, $label, $status ) );

			return new WP_Error(
				'woo_kontor_sync_invalid_json',
				__( 'Kontor returned a response that could not be decoded.', 'woo-kontor-sync-pro' ),
				array( 'disposition' => in_array( $status, self::$retryable_statuses, true ) ? 'retry' : 'fail' )
			);
		}

		$succeeded = ( $status >= 200 && $status < 300 ) && ! empty( $decoded['success'] );

		if ( $succeeded ) {
			return array(
				'data' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? $decoded['data'] : array(),
				'meta' => isset( $decoded['meta'] ) && is_array( $decoded['meta'] ) ? $decoded['meta'] : array(),
			);
		}

		$disposition = in_array( $status, self::$retryable_statuses, true ) ? 'retry' : 'fail';
		$code        = isset( $decoded['errorCode'] ) ? (string) $decoded['errorCode'] : '';
		$message     = isset( $decoded['message'] ) ? (string) $decoded['message'] : '';

		if ( '' === $message ) {
			/* translators: %d: HTTP status code returned by the Kontor API. */
			$message = sprintf( __( 'Kontor returned HTTP status %d.', 'woo-kontor-sync-pro' ), $status );
		}

		$this->log(
			'error',
			sprintf( '%s %s failed on attempt %d: HTTP %d %s (%s).', $method, $label, $attempt, $status, $code, $disposition )
		);

		return new WP_Error(
			'woo_kontor_sync_api_error',
			$message,
			array(
				'disposition' => $disposition,
				'status'      => $status,
				'error_code'  => $code,
			)
		);
	}

	/**
	 * Build the absolute request URL.
	 *
	 * @param string $endpoint Endpoint path, relative to the configured base URL.
	 * @return string|WP_Error Absolute URL, or an error when the plugin is unconfigured.
	 */
	protected function build_url( $endpoint ) {
		$base = isset( $this->settings['api_base_url'] ) ? trim( (string) $this->settings['api_base_url'] ) : '';

		if ( '' === $base ) {
			return new WP_Error(
				'woo_kontor_sync_not_configured',
				__( 'The Kontor API base URL has not been configured.', 'woo-kontor-sync-pro' ),
				array( 'disposition' => 'fail' )
			);
		}

		return trailingslashit( $base ) . ltrim( $endpoint, '/' );
	}

	/**
	 * Retrieve the configured API key.
	 *
	 * @return string|WP_Error The key, or an error when the plugin is unconfigured.
	 */
	protected function get_api_key() {
		$key = isset( $this->settings['api_key'] ) ? trim( (string) $this->settings['api_key'] ) : '';

		if ( '' === $key ) {
			return new WP_Error(
				'woo_kontor_sync_not_configured',
				__( 'The Kontor API key has not been configured.', 'woo-kontor-sync-pro' ),
				array( 'disposition' => 'fail' )
			);
		}

		return $key;
	}

	/**
	 * Build the request headers.
	 *
	 * @param string      $api_key         The Kontor API key.
	 * @param string|null $idempotency_key Optional idempotency key for writes.
	 * @return array Header name/value pairs.
	 */
	protected function build_headers( $api_key, $idempotency_key ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'x-api-key'    => $api_key,
			'User-Agent'   => 'WooKontorSyncPro/' . WKSYNC_VERSION . '; ' . home_url( '/' ),
		);

		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		return $headers;
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * Only decisions and identifiers are logged. Request bodies and headers are
	 * never logged, because they carry the Kontor API key.
	 *
	 * @param string $level   Log level, e.g. "error" or "info".
	 * @param string $message Message to record.
	 * @return void
	 */
	protected function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => self::LOG_SOURCE ) );
	}
}
