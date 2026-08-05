<?php
/**
 * Activation routine.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync;

use WooKontorSync\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is activated.
 */
final class Activator {

	/**
	 * Prepare the site for the plugin.
	 *
	 * Keep this idempotent: WordPress runs it on every activation, including
	 * reactivation after an update.
	 *
	 * @return void
	 */
	public static function activate() {
		// Seed the settings so the admin screen always has a complete array to read.
		// Autoload is off because the settings are only needed on the settings screen
		// and inside scheduled sync jobs.
		add_option( Settings::OPTION_KEY, Settings::default_settings(), '', false );

		add_option( 'woo_kontor_sync_version', WKSYNC_VERSION, '', false );

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_kontor_sync_activated' );
	}
}
