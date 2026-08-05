<?php
/**
 * Deactivation routine.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync;

use WooKontorSync\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Runs once when the plugin is deactivated.
 */
final class Deactivator {

	/**
	 * Stop all background work.
	 *
	 * Settings and sync metadata are deliberately left in place so that a
	 * deactivate/reactivate cycle does not lose the mapping to Kontor. Removal
	 * belongs in uninstall.php.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Scheduler::unschedule_all();

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_kontor_sync_deactivated' );
	}
}
