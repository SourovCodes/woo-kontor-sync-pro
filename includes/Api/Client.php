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
 * Talks to Kontor over HTTP.
 *
 * Every request is retried with exponential backoff on transient failures, and
 * every write carries an idempotency key so a retry cannot create a duplicate
 * record in the ERP.
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
	 * Perform a GET request.
	 *
	 * @param string $endpoint Endpoint path, relative to the configured base URL.
	 * @param array  $query    Optional query arguments.
	 * @return array|WP_Error Decoded response body, or WP_Error on failure.
	 */
	public function get( $endpoint, array $query = array() ) {
		if ( ! empty( $query ) ) {
			$endpoint = add_query_arg( $query, $endpoint );
		}

		return $this->request( 'GET', $endpoint );
	}

	/**
	 * Perform a POST request.
	 *
	 * @param string $endpoint        Endpoint path, relative to the configured base URL.
	 * @param array  $body            Request body, encoded as JSON.
	 * @param string $idempotency_key Stable key identifying this logical write.
	 * @return array|WP_Error Decoded response body, or WP_Error on failure.
	 */
	public function post( $endpoint, array $body, $idempotency_key ) {
		return $this->request( 'POST', $endpoint, $body, $idempotency_key );
	}

	/**
	 * Perform a request, retrying transient failures.
	 *
	 * @param string      $method          HTTP method.
	 * @param string      $endpoint        Endpoint path, relative to the configured base URL.
	 * @param array|null  $body            Optional request body.
	 * @param string|null $idempotency_key Optional idempotency key for writes.
	 * @return array|WP_Error Decoded response body, or WP_Error on failure.
	 */
	protected function request( $method, $endpoint, $body = null, $idempotency_key = null ) {
		$url = $this->build_url( $endpoint );

		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$args = array(
			'method'  => $method,
			'timeout' => $this->get_timeout(),
			'headers' => $this->build_headers( $idempotency_key ),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$last_error = null;

		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$response = wp_remote_request( $url, $args );
			$result   = $this->interpret_response( $response, $method, $endpoint, $attempt );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;

			if ( 'retry' !== $result->get_error_data( 'disposition' ) || self::MAX_ATTEMPTS === $attempt ) {
				break;
			}

			// Exponential backoff: 2s, then 4s. Kept short because this runs inside
			// an Action Scheduler job, which will retry the whole action if we fail.
			sleep( self::BACKOFF_BASE_SECONDS ** $attempt );
		}

		return $last_error;
	}

	/**
	 * Turn a raw HTTP response into decoded data or a descriptive error.
	 *
	 * @param array|WP_Error $response Raw response from wp_remote_request().
	 * @param string         $method   HTTP method, for logging.
	 * @param string         $endpoint Endpoint path, for logging.
	 * @param int            $attempt  Attempt number, for logging.
	 * @return array|WP_Error Decoded body, or an error carrying a "disposition" of retry or fail.
	 */
	protected function interpret_response( $response, $method, $endpoint, $attempt ) {
		if ( is_wp_error( $response ) ) {
			$this->log(
				'error',
				sprintf( '%s %s failed on attempt %d: %s', $method, $endpoint, $attempt, $response->get_error_message() )
			);

			return new WP_Error(
				'woo_kontor_sync_transport_error',
				$response->get_error_message(),
				array( 'disposition' => 'retry' )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );

		if ( $status >= 200 && $status < 300 ) {
			$decoded = json_decode( $raw, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$this->log( 'error', sprintf( '%s %s returned malformed JSON.', $method, $endpoint ) );

				return new WP_Error(
					'woo_kontor_sync_invalid_json',
					__( 'Kontor returned a response that could not be decoded.', 'woo-kontor-sync-pro' ),
					array( 'disposition' => 'fail' )
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		$disposition = in_array( $status, self::$retryable_statuses, true ) ? 'retry' : 'fail';

		$this->log(
			'error',
			sprintf( '%s %s returned HTTP %d on attempt %d (%s).', $method, $endpoint, $status, $attempt, $disposition )
		);

		return new WP_Error(
			'woo_kontor_sync_http_error',
			sprintf(
				/* translators: %d: HTTP status code returned by the Kontor API. */
				__( 'Kontor returned HTTP status %d.', 'woo-kontor-sync-pro' ),
				$status
			),
			array(
				'disposition' => $disposition,
				'status'      => $status,
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
	 * Build the request headers.
	 *
	 * @param string|null $idempotency_key Optional idempotency key for writes.
	 * @return array Header name/value pairs.
	 */
	protected function build_headers( $idempotency_key ) {
		$headers = array(
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
			'User-Agent'   => 'WooKontorSyncPro/' . WKSYNC_VERSION . '; ' . home_url( '/' ),
		);

		$token = isset( $this->settings['api_token'] ) ? (string) $this->settings['api_token'] : '';

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		if ( null !== $idempotency_key && '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		return $headers;
	}

	/**
	 * Resolve the configured request timeout.
	 *
	 * @return int Timeout in seconds.
	 */
	protected function get_timeout() {
		$timeout = isset( $this->settings['timeout'] ) ? absint( $this->settings['timeout'] ) : 0;

		return $timeout > 0 ? $timeout : 10;
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * Only decisions and identifiers are logged. Request bodies and headers are
	 * never logged, because they carry the Kontor bearer token.
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
