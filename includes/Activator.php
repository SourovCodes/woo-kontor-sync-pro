<?php
/**
 * Activation routine.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Scheduler;

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

		// Autoloaded: Plugin::maybe_upgrade() reads it on every request.
		add_option( Plugin::VERSION_KEY, WKSYNC_VERSION, '', true );

		/*
		 * Deactivation cancelled every queued action, and nothing else puts them back
		 * until the schedule check next runs. Reactivating has to restore the schedules
		 * the settings ask for, or the jobs stay silent while the screen shows their
		 * intervals as configured.
		 */
		Scheduler::restore_schedules();

		/**
		 * Fires after the plugin has finished its activation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_kontor_sync_activated' );
	}
}
