<?php
/**
 * Background sync scheduling.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use Exception;
use WP_Error;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;

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
	 * Action Scheduler's own default priority, and what every action here uses.
	 */
	const PRIORITY_DEFAULT = 10;

	/**
	 * Priority for image downloads, which yield to everything else.
	 *
	 * Action Scheduler claims by priority, then by insertion order, so without this
	 * the page action queued at the end of a page sits behind the two hundred image
	 * actions queued during it: the catalogue walk advances one page per image
	 * backlog rather than one page per read, and finalise() waits behind the last
	 * page's downloads. Sunk below the default, the walk runs straight through and
	 * the downloads drain after it — which is the order that matters, because the
	 * catalogue is right without them.
	 *
	 * Lower numbers run first. Nothing preempts a claimed batch, so a page action can
	 * still wait out images already in flight.
	 */
	const PRIORITY_IMAGES = 20;

	/**
	 * Entry point for a full product sync.
	 */
	const ACTION_SYNC_PRODUCTS = 'woo_kontor_sync_products';

	/**
	 * Imports one page of products, then chains to the next.
	 */
	const ACTION_SYNC_PRODUCTS_PAGE = 'woo_kontor_sync_products_page';

	/**
	 * Downloads the images for one product.
	 *
	 * Separate from the page action because sideloading is the slowest thing the
	 * import does, and the only part that waits on a host we do not control.
	 */
	const ACTION_SYNC_PRODUCT_IMAGES = 'woo_kontor_sync_product_images';

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
	 * The stock finalising pass, removed in 0.13.0 and still answered.
	 *
	 * A run queued one of these after its last chunk, and an upgrade can land while
	 * one is sitting in the queue: WordPress deactivates a plugin *silently* before
	 * replacing it, so Deactivator::deactivate() does not run and the pending action
	 * is not swept up. Left unanswered it fires into nothing, and because a run only
	 * ever leaves the running state from inside its own chain, the job would report
	 * itself running — and refuse to start another — until Status::STALE_AFTER.
	 *
	 * Nothing queues it any more. Remove it when no shop can still be upgrading from
	 * before 0.13.0.
	 */
	const ACTION_LEGACY_STOCK_FINALISE = 'woo_kontor_sync_stock_finalise';

	/**
	 * Entry point for the order upload sweep.
	 */
	const ACTION_SYNC_ORDERS = 'woo_kontor_sync_orders';

	/**
	 * Uploads a single order, queued when it reaches a pushable status.
	 */
	const ACTION_SYNC_ORDER = 'woo_kontor_sync_order';

	/**
	 * Sends one batch of the sweep's orders, then chains to the next.
	 */
	const ACTION_SYNC_ORDERS_BATCH = 'woo_kontor_sync_orders_batch';

	/**
	 * Entry point for the delivery information import.
	 */
	const ACTION_SYNC_DELIVERY = 'woo_kontor_sync_delivery';

	/**
	 * Applies one chunk of delivery rows, then chains to the next.
	 */
	const ACTION_SYNC_DELIVERY_CHUNK = 'woo_kontor_sync_delivery_chunk';

	/**
	 * Entry point for the invoice document import.
	 */
	const ACTION_SYNC_INVOICES = 'woo_kontor_sync_invoices';

	/**
	 * Downloads one chunk of invoices, then chains to the next.
	 */
	const ACTION_SYNC_INVOICES_CHUNK = 'woo_kontor_sync_invoices_chunk';

	/**
	 * Transient that rate limits the schedule reconciliation on `init`.
	 */
	const SCHEDULE_GUARD = 'wksync_schedule_checked';

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
				'description' => __( 'Updates stock levels for every article Kontor reports. An article the stock feed does not carry keeps the level it already had.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'From Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_STOCK,
				'setting'     => 'stock_sync_interval',
				'intervals'   => 'stock_sync_intervals',
			),
			'orders'   => array(
				'label'       => __( 'Order sync', 'woo-kontor-sync-pro' ),
				'description' => __( 'Sends paid orders to Kontor. Orders are normally sent as they are paid; this sweep catches any that were missed.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'To Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_ORDERS,
				'setting'     => 'order_sync_interval',
				'intervals'   => 'order_sync_intervals',
				'needs_shop'  => true,
			),
			'delivery' => array(
				'label'       => __( 'Delivery sync', 'woo-kontor-sync-pro' ),
				'description' => __( 'Brings order status and tracking details back from Kontor. Completing an order emails the customer.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'From Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_DELIVERY,
				'setting'     => 'delivery_sync_interval',
				'intervals'   => 'delivery_sync_intervals',
				'needs_shop'  => true,
			),
			'invoices' => array(
				'label'       => __( 'Invoice sync', 'woo-kontor-sync-pro' ),
				'description' => __( 'Downloads invoice PDFs from Kontor and files them against their orders.', 'woo-kontor-sync-pro' ),
				'direction'   => __( 'From Kontor', 'woo-kontor-sync-pro' ),
				'action'      => self::ACTION_SYNC_INVOICES,
				'setting'     => 'invoice_sync_interval',
				'intervals'   => 'invoice_sync_intervals',
				'needs_shop'  => true,
			),
		);
	}

	/**
	 * The job a queued action belongs to.
	 *
	 * Only the actions that make up a run are listed. ACTION_SYNC_PRODUCT_IMAGES is
	 * left out on purpose: image downloads outlive the run that queued them and the
	 * catalogue is already correct without them, so a failed image must not turn a
	 * finished product sync into a failed one. ACTION_SYNC_ORDER is left out for the
	 * same kind of reason — it is a single order queued at checkout, not part of the
	 * sweep the "orders" status describes.
	 *
	 * @param string $hook Action hook.
	 * @return string Job key, or an empty string when the action is not part of a run.
	 */
	public static function job_for_action( $hook ) {
		$jobs = array(
			self::ACTION_SYNC_PRODUCTS          => 'products',
			self::ACTION_SYNC_PRODUCTS_PAGE     => 'products',
			self::ACTION_SYNC_PRODUCTS_FINALISE => 'products',
			self::ACTION_SYNC_STOCK             => 'stock',
			self::ACTION_SYNC_STOCK_CHUNK       => 'stock',
			self::ACTION_LEGACY_STOCK_FINALISE  => 'stock',
			self::ACTION_SYNC_ORDERS            => 'orders',
			self::ACTION_SYNC_ORDERS_BATCH      => 'orders',
			self::ACTION_SYNC_DELIVERY          => 'delivery',
			self::ACTION_SYNC_DELIVERY_CHUNK    => 'delivery',
			self::ACTION_SYNC_INVOICES          => 'invoices',
			self::ACTION_SYNC_INVOICES_CHUNK    => 'invoices',
		);

		return isset( $jobs[ $hook ] ) ? $jobs[ $hook ] : '';
	}

	/**
	 * Register the Action Scheduler hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::ACTION_SYNC_PRODUCTS, array( $this, 'handle_products' ) );
		add_action( self::ACTION_SYNC_PRODUCTS_PAGE, array( $this, 'handle_products_page' ), 10, 2 );
		add_action( self::ACTION_SYNC_PRODUCT_IMAGES, array( $this, 'handle_product_images' ), 10, 3 );
		add_action( self::ACTION_SYNC_PRODUCTS_FINALISE, array( $this, 'handle_products_finalise' ), 10, 1 );

		add_action( self::ACTION_SYNC_STOCK, array( $this, 'handle_stock' ) );
		add_action( self::ACTION_SYNC_STOCK_CHUNK, array( $this, 'handle_stock_chunk' ), 10, 2 );
		add_action( self::ACTION_LEGACY_STOCK_FINALISE, array( $this, 'handle_legacy_stock_finalise' ), 10, 1 );

		add_action( self::ACTION_SYNC_ORDERS, array( $this, 'handle_orders' ) );
		add_action( self::ACTION_SYNC_ORDERS_BATCH, array( $this, 'handle_orders_batch' ), 10, 2 );
		add_action( self::ACTION_SYNC_ORDER, array( $this, 'handle_order' ), 10, 1 );

		add_action( self::ACTION_SYNC_DELIVERY, array( $this, 'handle_delivery' ) );
		add_action( self::ACTION_SYNC_DELIVERY_CHUNK, array( $this, 'handle_delivery_chunk' ), 10, 2 );

		add_action( self::ACTION_SYNC_INVOICES, array( $this, 'handle_invoices' ) );
		add_action( self::ACTION_SYNC_INVOICES_CHUNK, array( $this, 'handle_invoices_chunk' ), 10, 2 );

		/*
		 * An order reaching a pushable status queues an upload. These fire inside a
		 * request the customer is waiting on, so the handler only ever enqueues.
		 */
		foreach ( OrderSync::pushable_statuses() as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( $this, 'handle_order_status' ), 10, 1 );
		}

		/*
		 * A run reports its own outcome from inside its chained actions. When one of
		 * those dies the chain dies with it and there is nothing left to record the
		 * failure, so Action Scheduler's own verdict has to close the status instead.
		 */
		add_action( 'action_scheduler_failed_execution', array( $this, 'handle_failed_execution' ), 10, 2 );
		add_action( 'action_scheduler_failed_action', array( $this, 'handle_timed_out_action' ), 10, 1 );
		add_action( 'action_scheduler_unexpected_shutdown', array( $this, 'handle_unexpected_shutdown' ), 10, 2 );

		add_action( 'init', array( $this, 'ensure_recurring_actions' ) );

		// Changing an interval on the settings screen has to move the queued action.
		add_action( 'update_option_' . Settings::OPTION_KEY, array( $this, 'reschedule' ) );

		// A saved key says nothing about whether the previous one worked.
		add_action( 'update_option_' . Settings::OPTION_KEY, array( Preflight::class, 'forget_connection' ) );
	}

	/**
	 * Bring the queue in line with the configured intervals, occasionally.
	 *
	 * This is hooked to `init`, so it runs on every request. Querying Action
	 * Scheduler for each job every time would add queries to every page load for a
	 * check that almost never has anything to do, so the real work is rate limited
	 * to once an hour. Saving the settings clears the guard, so a change still takes
	 * effect immediately.
	 *
	 * @return void
	 */
	public function ensure_recurring_actions() {
		if ( ! self::is_available() || get_transient( self::SCHEDULE_GUARD ) ) {
			return;
		}

		set_transient( self::SCHEDULE_GUARD, 1, HOUR_IN_SECONDS );

		$this->sync_schedules();
	}

	/**
	 * Queue, or cancel, each job's recurring action to match its setting.
	 *
	 * @return void
	 */
	public function sync_schedules() {
		$settings = Settings::get_settings();

		foreach ( self::get_jobs() as $job ) {
			$interval = absint( $settings[ $job['setting'] ] );
			$next     = as_next_scheduled_action( $job['action'], array(), self::GROUP );

			// "Never" means no recurring action at all; the job stays manual.
			if ( Settings::INTERVAL_NEVER === $interval ) {
				if ( $next ) {
					as_unschedule_all_actions( $job['action'], array(), self::GROUP );
				}

				continue;
			}

			if ( $next ) {
				continue;
			}

			as_schedule_recurring_action( time() + $interval, $interval, $job['action'], array(), self::GROUP );
		}
	}

	/**
	 * Re-queue the recurring actions after the intervals change.
	 *
	 * Only the top-level job hooks are cancelled, so a sync already walking the
	 * catalogue keeps its chained page actions and runs to completion.
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

		delete_transient( self::SCHEDULE_GUARD );

		$this->sync_schedules();
	}

	/**
	 * Queue a job to run as soon as the queue is next processed.
	 *
	 * Refuses rather than queue an action that could only fail on arrival, so the
	 * admin screen can say why nothing happened. Only the local checks run here —
	 * whether Kontor actually accepts the credentials is settled by the job itself,
	 * which records the answer in its status rather than holding up this request.
	 *
	 * @param string $job Job key from get_jobs().
	 * @return true|WP_Error True when the job was queued.
	 */
	public static function trigger( $job ) {
		$jobs = self::get_jobs();

		if ( ! isset( $jobs[ $job ] ) || ! self::is_available() ) {
			return new WP_Error(
				'wksync_unavailable',
				__( 'The job could not be queued. Check that WooCommerce is active.', 'woo-kontor-sync-pro' )
			);
		}

		if ( Status::is_running( $job ) ) {
			return new WP_Error(
				'wksync_already_running',
				__( 'That job is already running.', 'woo-kontor-sync-pro' )
			);
		}

		$settings    = Settings::get_settings();
		$credentials = Preflight::credentials( $settings );

		if ( is_wp_error( $credentials ) ) {
			return $credentials;
		}

		if ( ! empty( $jobs[ $job ]['needs_shop'] ) ) {
			$shop = Preflight::shop( $settings );

			if ( is_wp_error( $shop ) ) {
				return $shop;
			}
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
	 * Download the images for one product.
	 *
	 * @param int   $product_id Product the images belong to.
	 * @param array $files      Image filenames, relative to the configured base URL.
	 * @param int   $run        Run identifier.
	 * @return void
	 */
	public function handle_product_images( $product_id = 0, $files = array(), $run = 0 ) {
		( new ProductSync() )->import_images( absint( $product_id ), (array) $files, absint( $run ) );
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
	 * Close a run whose finalising action outlived the pass itself.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function handle_legacy_stock_finalise( $run = 0 ) {
		( new StockSync() )->close_legacy_run( absint( $run ) );
	}

	/**
	 * Sweep for orders that have not reached Kontor.
	 *
	 * @return void
	 */
	public function handle_orders() {
		( new OrderSync() )->start();
	}

	/**
	 * Send one batch of the sweep's orders.
	 *
	 * @param int $offset Number of orders already sent.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function handle_orders_batch( $offset = 0, $run = 0 ) {
		( new OrderSync() )->send_batch( absint( $offset ), absint( $run ) );
	}

	/**
	 * Upload a single order.
	 *
	 * @param int $order_id Order to send.
	 * @return void
	 */
	public function handle_order( $order_id = 0 ) {
		( new OrderSync() )->push_one( absint( $order_id ) );
	}

	/**
	 * Queue an order for upload after it reaches a pushable status.
	 *
	 * @param int $order_id Order that changed status.
	 * @return void
	 */
	public function handle_order_status( $order_id = 0 ) {
		( new OrderSync() )->enqueue( absint( $order_id ) );
	}

	/**
	 * Start a delivery information import.
	 *
	 * @return void
	 */
	public function handle_delivery() {
		( new DeliverySync() )->start();
	}

	/**
	 * Apply one chunk of delivery rows.
	 *
	 * @param int $offset Number of rows already applied.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function handle_delivery_chunk( $offset = 0, $run = 0 ) {
		( new DeliverySync() )->apply_chunk( absint( $offset ), absint( $run ) );
	}

	/**
	 * Start an invoice document import.
	 *
	 * @return void
	 */
	public function handle_invoices() {
		( new InvoiceSync() )->start();
	}

	/**
	 * Download one chunk of invoices.
	 *
	 * @param int $offset Number of rows already processed.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function handle_invoices_chunk( $offset = 0, $run = 0 ) {
		( new InvoiceSync() )->apply_chunk( absint( $offset ), absint( $run ) );
	}

	/**
	 * Close out a run whose action threw.
	 *
	 * @param int       $action_id Action that failed.
	 * @param Exception $exception What it threw.
	 * @return void
	 */
	public function handle_failed_execution( $action_id = 0, $exception = null ) {
		$reason = $exception instanceof Exception ? $exception->getMessage() : __( 'an unknown error', 'woo-kontor-sync-pro' );

		$this->abandon_run(
			$action_id,
			/* translators: %s: error message. */
			sprintf( __( 'The run stopped because a background task failed: %s', 'woo-kontor-sync-pro' ), $reason )
		);
	}

	/**
	 * Close out a run whose action ran past Action Scheduler's timeout.
	 *
	 * @param int $action_id Action that was given up on.
	 * @return void
	 */
	public function handle_timed_out_action( $action_id = 0 ) {
		$this->abandon_run(
			$action_id,
			__( 'The run stopped because a background task took too long and was abandoned.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * Close out a run whose action ended the request outright.
	 *
	 * @param int   $action_id Action that was running.
	 * @param array $error     The PHP error that ended the request.
	 * @return void
	 */
	public function handle_unexpected_shutdown( $action_id = 0, $error = array() ) {
		$reason = isset( $error['message'] ) ? (string) $error['message'] : __( 'a fatal error', 'woo-kontor-sync-pro' );

		$this->abandon_run(
			$action_id,
			/* translators: %s: error message. */
			sprintf( __( 'The run stopped because a background task ended unexpectedly: %s', 'woo-kontor-sync-pro' ), $reason )
		);
	}

	/**
	 * Record that a run died with the action that was carrying it.
	 *
	 * @param int    $action_id Action that failed.
	 * @param string $message   Reason to record against the run.
	 * @return void
	 */
	protected function abandon_run( $action_id, $message ) {
		$action = self::fetch_action( absint( $action_id ) );

		if ( null === $action ) {
			return;
		}

		$job = self::job_for_action( $action->get_hook() );

		if ( '' === $job ) {
			return;
		}

		$args = (array) $action->get_args();

		/*
		 * A superseded action failing says nothing about the run that replaced it, and
		 * failing that one on its behalf would report a healthy sync as broken.
		 */
		if ( isset( $args['run'] ) && ! Status::is_current_run( $job, (int) $args['run'] ) ) {
			return;
		}

		// The job may already have recorded its own, better, reason for stopping.
		if ( 'running' !== Status::get( $job )['state'] ) {
			return;
		}

		Status::fail( $job, $message );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				sprintf( '%s run abandoned: %s', $job, $message ),
				array( 'source' => Client::LOG_SOURCE )
			);
		}
	}

	/**
	 * Read a queued action back from Action Scheduler.
	 *
	 * @param int $action_id Action to read.
	 * @return \ActionScheduler_Action|null The action, or null when it cannot be read.
	 */
	protected static function fetch_action( $action_id ) {
		if ( ! $action_id || ! class_exists( '\ActionScheduler' ) ) {
			return null;
		}

		try {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
		} catch ( Exception $exception ) {
			return null;
		}

		// A missing action comes back as a null action rather than as an error.
		if ( ! $action instanceof \ActionScheduler_Action || ! $action->get_hook() ) {
			return null;
		}

		return $action;
	}

	/**
	 * Queue a follow-up action for the current run.
	 *
	 * @param string $hook     Action hook to queue.
	 * @param array  $args     Arguments to pass along.
	 * @param int    $priority Claim priority, lower first. Defaults to Action Scheduler's own.
	 * @return void
	 */
	public static function chain( $hook, array $args, $priority = self::PRIORITY_DEFAULT ) {
		if ( ! self::is_available() ) {
			return;
		}

		as_enqueue_async_action( $hook, $args, self::GROUP, false, (int) $priority );
	}

	/**
	 * Cancel everything this plugin has queued.
	 *
	 * The guard means "the queue matches the settings", which stops being true the
	 * moment the queue is emptied. Leaving it set would keep ensure_recurring_actions()
	 * returning early for the rest of the hour, so a plugin deactivated and
	 * immediately reactivated would sit with no recurring actions at all — a stock
	 * sync set to fifteen minutes silently not running for the best part of an hour.
	 *
	 * @return void
	 */
	public static function unschedule_all() {
		delete_transient( self::SCHEDULE_GUARD );

		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		as_unschedule_all_actions( '', array(), self::GROUP );
	}

	/**
	 * How many actions of one kind are still waiting to run.
	 *
	 * Used for the image queue, which is the one piece of a product sync with no total
	 * to measure against: the downloads outlive the run that queued them, so the
	 * honest thing to show is how many are left rather than a percentage of something.
	 *
	 * Asked as a count rather than by fetching the IDs and counting them — a first run
	 * queues one action per article, and reading four thousand rows to display a
	 * number would be worse than not showing it.
	 *
	 * @param string $hook Action hook.
	 * @return int Actions still pending.
	 */
	public static function pending_count( $hook ) {
		if ( ! class_exists( 'ActionScheduler' ) || ! class_exists( 'ActionScheduler_Store' ) ) {
			return 0;
		}

		return (int) \ActionScheduler::store()->query_actions(
			array(
				'hook'   => $hook,
				'group'  => self::GROUP,
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			),
			'count'
		);
	}

	/**
	 * Put the recurring actions back, now rather than on the next `init`.
	 *
	 * Called from activation. Dropping the guard alone would be enough eventually,
	 * but the queue would then stay empty until some later request happened to run
	 * `init`, and the settings screen would meanwhile report every job as not
	 * scheduled. Action Scheduler may legitimately be absent here — activation can
	 * run before WooCommerce has loaded — in which case the cleared guard is what
	 * makes the next `init` finish the job.
	 *
	 * @return void
	 */
	public static function restore_schedules() {
		delete_transient( self::SCHEDULE_GUARD );

		if ( ! self::is_available() ) {
			return;
		}

		( new self() )->sync_schedules();
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
