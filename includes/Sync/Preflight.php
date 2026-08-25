<?php
/**
 * Preconditions every sync job has to satisfy before it runs.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WP_Error;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a job is allowed to start.
 *
 * A job that runs unconfigured does not fail cleanly: the product sync would walk
 * the catalogue drafting every product because Kontor "returned nothing", and the
 * order push would queue work that can never be delivered. Checking first turns all
 * of that into one clear refusal.
 *
 * Four gates, cheapest first:
 *
 * 1. Credentials — the API base URL and key are present. No network.
 * 2. Orders — the shop exchanges orders with Kontor at all. Plenty of shops run this
 *    plugin for the catalogue alone, and for one of those the order push, the
 *    delivery import and the invoice import are not misconfigured, they are not
 *    wanted.
 * 3. Shop — every job that talks to Kontor about orders needs a shop chosen: the
 *    order push, and the delivery and invoice imports. Kontor answers a malformed
 *    shop ID with an HTTP 500 and an empty one with an empty list, and neither reads
 *    as "you forgot to configure this".
 * 4. Connection — the credentials actually authenticate. One small request, with
 *    success cached so a frequent job does not pay for it every run.
 *
 * Gates 2 and 3 apply to the same three jobs, which is why one list answers both.
 * Their order matters: "this shop does not do orders" is the truer answer for a shop
 * that has deliberately never chosen one, and "pick a shop" would send whoever read
 * it looking for a field that is not on the screen.
 */
class Preflight {

	/**
	 * Transient recording that the credentials were last seen working.
	 */
	const CONNECTION_CACHE = 'wksync_connection_verified';

	/**
	 * How long a verified connection is trusted for.
	 *
	 * Only success is cached. A failure is never remembered, so fixing a key takes
	 * effect on the very next run rather than after a wait.
	 */
	const CONNECTION_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * The jobs that exchange orders with Kontor.
	 *
	 * Both the orders gate and the shop gate apply to exactly these three, so there is
	 * one list rather than two that could drift apart.
	 *
	 * @var string[]
	 */
	private static $order_jobs = array( OrderSync::JOB, DeliverySync::JOB, InvoiceSync::JOB );

	/**
	 * Check every precondition for a job.
	 *
	 * @param string      $job      Job key.
	 * @param array|null  $settings Optional settings override, mainly for tests.
	 * @param Client|null $client   Optional client override, mainly for tests.
	 * @return true|WP_Error True when the job may run.
	 */
	public static function check( $job, $settings = null, $client = null ) {
		$settings = null === $settings ? Settings::get_settings() : $settings;

		$credentials = self::credentials( $settings );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		if ( in_array( $job, self::$order_jobs, true ) ) {
			$enabled = self::orders_enabled( $settings );

			if ( is_wp_error( $enabled ) ) {
				return $enabled;
			}

			$shop = self::shop( $settings );

			if ( is_wp_error( $shop ) ) {
				return $shop;
			}
		}

		return self::connection( $settings, $client );
	}

	/**
	 * Whether the API base URL and key have both been configured.
	 *
	 * @param array $settings Plugin settings.
	 * @return true|WP_Error True when both are present.
	 */
	public static function credentials( array $settings ) {
		$base = isset( $settings['api_base_url'] ) ? trim( (string) $settings['api_base_url'] ) : '';
		$key  = isset( $settings['api_key'] ) ? trim( (string) $settings['api_key'] ) : '';

		if ( '' === $base || '' === $key ) {
			return new WP_Error(
				'wksync_not_configured',
				__( 'Kontor is not configured: set the API base URL and API key before running a sync.', 'woo-kontor-sync-pro' )
			);
		}

		return true;
	}

	/**
	 * Whether this shop exchanges orders with Kontor at all.
	 *
	 * @param array $settings Plugin settings.
	 * @return true|WP_Error True when the order-side jobs are wanted here.
	 */
	public static function orders_enabled( array $settings ) {
		if ( ! Settings::orders_enabled( $settings ) ) {
			return new WP_Error(
				'wksync_orders_disabled',
				__( 'This shop does not exchange orders with Kontor. Turn that on in the Kontor Sync settings first.', 'woo-kontor-sync-pro' )
			);
		}

		return true;
	}

	/**
	 * Whether a usable shop has been selected.
	 *
	 * @param array $settings Plugin settings.
	 * @return true|WP_Error True when a well-formed shop ID is stored.
	 */
	public static function shop( array $settings ) {
		$shop_id = isset( $settings['shop_id'] ) ? trim( (string) $settings['shop_id'] ) : '';

		if ( '' === $shop_id || ! Settings::is_shop_id( $shop_id ) ) {
			return new WP_Error(
				'wksync_no_shop',
				__( 'No Kontor shop has been selected. Choose one on the settings screen before syncing orders.', 'woo-kontor-sync-pro' )
			);
		}

		return true;
	}

	/**
	 * Whether the stored credentials actually authenticate.
	 *
	 * @param array       $settings Plugin settings.
	 * @param Client|null $client   Optional client override, mainly for tests.
	 * @return true|WP_Error True when Kontor accepted the credentials.
	 */
	public static function connection( array $settings, $client = null ) {
		if ( self::CONNECTION_TTL > 0 && get_transient( self::CONNECTION_CACHE ) ) {
			return true;
		}

		$client = null === $client ? new Client( $settings ) : $client;
		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			$code = Client::detail( $result, 'error_code' );

			return new WP_Error(
				'wksync_connection_failed',
				'' === $code
					? $result->get_error_message()
					: sprintf( '%s (%s)', $result->get_error_message(), $code )
			);
		}

		set_transient( self::CONNECTION_CACHE, 1, self::CONNECTION_TTL );

		return true;
	}

	/**
	 * Forget a cached connection result.
	 *
	 * Called when the settings are saved: a key that has just changed says nothing
	 * about whether the previous one worked.
	 *
	 * @return void
	 */
	public static function forget_connection() {
		delete_transient( self::CONNECTION_CACHE );
	}
}
