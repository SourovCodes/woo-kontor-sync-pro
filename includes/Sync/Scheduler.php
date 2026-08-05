<?php
/**
 * Background sync scheduling.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Queues sync work onto Action Scheduler.
 *
 * Nothing here talks to Kontor directly. WooCommerce hooks enqueue an action and
 * return immediately, so a slow or unavailable ERP can never stall a customer
 * request. Action Scheduler ships inside WooCommerce and gives us retries,
 * concurrency limits and an admin UI that raw WP-Cron does not.
 */
class Scheduler {

	/**
	 * Action Scheduler group used for every action this plugin queues.
	 */
	const GROUP = 'woo-kontor-sync';

	/**
	 * Hook fired for a single order push.
	 */
	const ACTION_SYNC_ORDER = 'woo_kontor_sync_push_order';

	/**
	 * Hook fired for the recurring reconciliation sweep.
	 */
	const ACTION_RECONCILE = 'woo_kontor_sync_reconcile';

	/**
	 * Interval between reconciliation sweeps, in seconds.
	 */
	const RECONCILE_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * Register the WooCommerce and Action Scheduler hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_status_processing', array( $this, 'enqueue_order' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'enqueue_order' ) );

		add_action( self::ACTION_SYNC_ORDER, array( $this, 'handle_order_sync' ) );
		add_action( self::ACTION_RECONCILE, array( $this, 'handle_reconcile' ) );

		add_action( 'init', array( $this, 'ensure_recurring_actions' ) );
	}

	/**
	 * Queue an order for pushing to Kontor.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function enqueue_order( $order_id ) {
		$order_id = absint( $order_id );

		if ( 0 === $order_id || ! $this->is_action_scheduler_available() ) {
			return;
		}

		$args = array( 'order_id' => $order_id );

		// Do not stack duplicate jobs for the same order.
		if ( as_next_scheduled_action( self::ACTION_SYNC_ORDER, $args, self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::ACTION_SYNC_ORDER, $args, self::GROUP );
	}

	/**
	 * Make sure the recurring reconciliation sweep is scheduled.
	 *
	 * @return void
	 */
	public function ensure_recurring_actions() {
		if ( ! $this->is_action_scheduler_available() ) {
			return;
		}

		if ( as_next_scheduled_action( self::ACTION_RECONCILE, array(), self::GROUP ) ) {
			return;
		}

		as_schedule_recurring_action( time() + self::RECONCILE_INTERVAL, self::RECONCILE_INTERVAL, self::ACTION_RECONCILE, array(), self::GROUP );
	}

	/**
	 * Push a single order to Kontor.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public function handle_order_sync( $order_id ) {
		$order = wc_get_order( absint( $order_id ) );

		if ( ! $order ) {
			return;
		}

		/*
		 * Implementation lands here. Read and write order data through the CRUD
		 * object ($order->get_*, $order->update_meta_data, $order->save) so the
		 * plugin keeps working under High-Performance Order Storage, and derive
		 * the idempotency key from the order ID plus a hash of the payload.
		 */
	}

	/**
	 * Reconcile local records against Kontor.
	 *
	 * @return void
	 */
	public function handle_reconcile() {
		/*
		 * Implementation lands here. Use wc_get_orders() with a meta query on
		 * _wksync_synced_at to find records that never synced or drifted.
		 */
	}

	/**
	 * Cancel everything this plugin has queued.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', array(), self::GROUP );
	}

	/**
	 * Determine whether Action Scheduler is loaded.
	 *
	 * It ships inside WooCommerce, but guard anyway so the plugin degrades to a
	 * no-op instead of fatally erroring if that ever changes.
	 *
	 * @return bool True when the Action Scheduler API is available.
	 */
	protected function is_action_scheduler_available() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_next_scheduled_action' );
	}
}
