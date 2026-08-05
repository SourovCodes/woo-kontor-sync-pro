<?php
/**
 * Background sync scheduling.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WooKontorSync\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Queues sync work onto Action Scheduler and routes it to the sync classes.
 *
 * Nothing here talks to Kontor directly. Action Scheduler ships inside WooCommerce
 * and gives us retries, concurrency limits and an admin UI that raw WP-Cron does
 * not. Long runs are split across chained actions so no single request has to walk
 * the whole catalogue.
 */
class Scheduler {

	/**
	 * Action Scheduler group used for every action this plugin queues.
	 */
	const GROUP = 'woo-kontor-sync';

	/**
	 * Entry point for a full product sync.
	 */
	const ACTION_SYNC_PRODUCTS = 'woo_kontor_sync_products';

	/**
	 * Imports one page of products, then chains to the next.
	 */
	const ACTION_SYNC_PRODUCTS_PAGE = 'woo_kontor_sync_products_page';

	/**
	 * Runs after the last product page.
	 */
	const ACTION_SYNC_PRODUCTS_FINALISE = 'woo_kontor_sync_products_finalise';

	/**
	 * Entry point for a stock sync.
	 */
	const ACTION_SYNC_STOCK = 'woo_kontor_sync_stock';

	/**
	 * Applies one chunk of stock levels, then chains to the next.
	 */
	const ACTION_SYNC_STOCK_CHUNK = 'woo_kontor_sync_stock_chunk';

	/**
	 * The jobs this plugin runs, keyed by the slug used in the admin UI.
	 *
	 * @return array Job definitions.
	 */
	public static function get_jobs() {
		return array(
			'products' => array(
				'label'       => __( 'Product sync', 'woo-kontor-sync-pro' ),
				'description' => __( 'Imports articles, titles, descriptions and prices from Kontor.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'From Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_PRODUCTS,
				'setting'     => 'product_sync_interval',
				'intervals'   => 'product_sync_intervals',
			),
			'stock'    => array(
				'label'       => __( 'Stock sync', 'woo-kontor-sync-pro' ),
				'description' => __( 'Updates stock levels for every article Kontor reports.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'From Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_STOCK,
				'setting'     => 'stock_sync_interval',
				'intervals'   => 'stock_sync_intervals',
			),
		);
	}

	/**
	 * Register the Action Scheduler hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::ACTION_SYNC_PRODUCTS, array( $this, 'handle_products' ) );
		add_action( self::ACTION_SYNC_PRODUCTS_PAGE, array( $this, 'handle_products_page' ), 10, 2 );
		add_action( self::ACTION_SYNC_PRODUCTS_FINALISE, array( $this, 'handle_products_finalise' ), 10, 1 );

		add_action( self::ACTION_SYNC_STOCK, array( $this, 'handle_stock' ) );
		add_action( self::ACTION_SYNC_STOCK_CHUNK, array( $this, 'handle_stock_chunk' ), 10, 2 );

		add_action( 'init', array( $this, 'ensure_recurring_actions' ) );

		// Changing an interval on the settings screen has to move the queued action.
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this, 'reschedule' ) );
	}

	/**
	 * Make sure each job has a recurring action queued at its configured interval.
	 *
	 * @return void
	 */
	public function ensure_recurring_actions() {
		if ( ! self::is_available() ) {
			return;
		}

		$settings = Settings::get_settings();

		foreach ( self::get_jobs() as $job ) {
			if ( as_next_scheduled_action( $job['action'], array(), self::GROUP ) ) {
				continue;
			}

			$interval = absint( $settings[ $job['setting'] ] );

			as_schedule_recurring_action( time() + $interval, $interval, $job['action'], array(), self::GROUP );
		}
	}

	/**
	 * Re-queue the recurring actions after the intervals change.
	 *
	 * @return void
	 */
	public function reschedule() {
		if ( ! self::is_available() ) {
			return;
		}

		foreach ( self::get_jobs() as $job ) {
			as_unschedule_all_actions( $job['action'], array(), self::GROUP );
		}

		$this->ensure_recurring_actions();
	}

	/**
	 * Queue a job to run as soon as the queue is next processed.
	 *
	 * @param string $job Job key from get_jobs().
	 * @return bool True when the job was queued.
	 */
	public static function trigger( $job ) {
		$jobs = self::get_jobs();

		if ( ! isset( $jobs[ $job ] ) || ! self::is_available() ) {
			return false;
		}

		as_enqueue_async_action( $jobs[ $job ]['action'], array(), self::GROUP );

		return true;
	}

	/**
	 * When the given job is next due.
	 *
	 * @param string $job Job key from get_jobs().
	 * @return int Unix timestamp, or 0 when nothing is queued.
	 */
	public static function next_run( $job ) {
		$jobs = self::get_jobs();

		if ( ! isset( $jobs[ $job ] ) || ! self::is_available() ) {
			return 0;
		}

		return (int) as_next_scheduled_action( $jobs[ $job ]['action'], array(), self::GROUP );
	}

	/**
	 * Start a product sync.
	 *
	 * @return void
	 */
	public function handle_products() {
		( new ProductSync() )->start();
	}

	/**
	 * Import one page of products.
	 *
	 * @param int $skip Number of records already imported.
	 * @param int $run  Run identifier, used to spot products Kontor no longer lists.
	 * @return void
	 */
	public function handle_products_page( $skip = 0, $run = 0 ) {
		( new ProductSync() )->import_page( absint( $skip ), absint( $run ) );
	}

	/**
	 * Finish a product sync.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function handle_products_finalise( $run = 0 ) {
		( new ProductSync() )->finalise( absint( $run ) );
	}

	/**
	 * Start a stock sync.
	 *
	 * @return void
	 */
	public function handle_stock() {
		( new StockSync() )->start();
	}

	/**
	 * Apply one chunk of stock levels.
	 *
	 * @param int $offset Number of rows already applied.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function handle_stock_chunk( $offset = 0, $run = 0 ) {
		( new StockSync() )->apply_chunk( absint( $offset ), absint( $run ) );
	}

	/**
	 * Queue a follow-up action for the current run.
	 *
	 * @param string $hook Action hook to queue.
	 * @param array  $args Arguments to pass along.
	 * @return void
	 */
	public static function chain( $hook, array $args ) {
		if ( ! self::is_available() ) {
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP );
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
	public static function is_available() {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_next_scheduled_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}
}
