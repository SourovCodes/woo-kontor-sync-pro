<?php
/**
 * Deactivation routine.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync;

use WooKontorSync\Sync\Payload;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;

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
	 * A run in flight is closed out first. Cancelling the queue destroys the chain
	 * that would have reported the outcome, so without this a deactivation timed to
	 * land mid-sync leaves the job reading "running" for the next six hours, with
	 * Run now refusing to start another because one is supposedly already going.
	 *
	 * @return void
	 */
	public static function deactivate() {
		Status::abandon( __( 'Interrupted: the plugin was deactivated while this run was in progress.', 'woo-kontor-sync-pro' ) );

		Scheduler::unschedule_all();

		// The chain that would have read them has just been thrown away, so what any run
		// had stored to work through is now a row nothing will ever look at again.
		Payload::forget_all();

		/**
		 * Fires after the plugin has finished its deactivation routine.
		 *
		 * @since 0.1.0
		 */
		do_action( 'woo_kontor_sync_deactivated' );
	}
}
