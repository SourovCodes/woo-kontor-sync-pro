<?php
/**
 * Delivery and tracking information import from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WC_Order;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WooKontorSync\Orders\PartialStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Brings order status and tracking details back from Kontor.
 *
 * The orders entity returns every order Kontor holds for the shop — capped around
 * 1000 rows — with no way to ask only for the ones that changed. The reply is
 * therefore fetched once, cached for the run, and applied in chunks across chained
 * actions, the same shape as the stock sync.
 *
 * Rows are matched to WooCommerce orders on the order number this plugin sent,
 * recorded at push time, rather than on whatever the order calls itself now.
 *
 * An order Kontor reports as completed is transitioned to completed in WooCommerce.
 * That fires the "Order complete" email to the customer, which is the point — but
 * it does mean this job sends real mail, so it never runs unattended before a shop
 * has been configured, and it only ever moves an order forwards.
 *
 * One Kontor status has no WooCommerce equivalent: an order partly shipped. That one
 * moves to the plugin's own Partially completed status, which sends no mail.
 */
class DeliverySync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'delivery';

	/**
	 * How many rows to apply per action.
	 */
	const CHUNK_SIZE = 50;

	/**
	 * Prefix for the transient holding a run's payload.
	 */
	const TRANSIENT_PREFIX = 'wksync_delivery_run_';

	/**
	 * How long a run's cached payload stays available.
	 */
	const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Meta holding the status Kontor last reported.
	 */
	const META_STATUS = '_wksync_delivery_status';

	/**
	 * Meta holding the shipping provider.
	 */
	const META_PROVIDER = '_wksync_tracking_provider';

	/**
	 * Meta holding the tracking number.
	 */
	const META_TRACKING = '_wksync_tracking_number';

	/**
	 * Meta holding the tracking URL.
	 */
	const META_TRACKING_URL = '_wksync_tracking_url';

	/**
	 * The status Kontor reports for a finished order.
	 */
	const STATUS_COMPLETED = 'completed';

	/**
	 * The status Kontor reports for an order that has partly shipped.
	 *
	 * Kontor's other two statuses, "canceled" and "in_progress", are deliberately not
	 * acted on. Both would move an order backwards — cancelling one the shop is
	 * working on, or reopening one it has finished — and this job only ever moves an
	 * order forwards.
	 */
	const STATUS_PARTIAL = 'partially_completed';

	/**
	 * The WooCommerce statuses an order may be moved out of.
	 *
	 * Anything else is either already past this point or somewhere the shop put it on
	 * purpose. Completing a cancelled order would resurrect it and email the customer
	 * about an order that is not happening.
	 *
	 * @var array
	 */
	private static $movable = array(
		self::STATUS_COMPLETED => array( 'processing', 'on-hold', PartialStatus::STATUS ),
		self::STATUS_PARTIAL   => array( 'processing', 'on-hold' ),
	);

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Client|null $client   Optional client override, mainly for tests.
	 * @param array|null  $settings Optional settings override, mainly for tests.
	 */
	public function __construct( $client = null, $settings = null ) {
		$this->settings = null === $settings ? Settings::get_settings() : $settings;
		$this->client   = null === $client ? new Client( $this->settings ) : $client;
	}

	/**
	 * Fetch every delivery row and queue it for application.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Delivery sync already running; ignoring the request to start another.' );

			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Delivery sync refused to start: ' . $ready->get_error_message() );

			return;
		}

		$run      = Status::start( self::JOB );
		$response = $this->client->fetch_orders( (string) $this->settings['shop_id'] );

		if ( is_wp_error( $response ) ) {
			Status::fail( self::JOB, $response->get_error_message() );
			$this->log( 'error', 'Delivery sync aborted: ' . $response->get_error_message() );

			return;
		}

		$rows = $this->normalise( $response['data'] );

		if ( empty( $rows ) ) {
			Status::finish( self::JOB, __( 'Kontor reported no orders for this shop.', 'woo-kontor-sync-pro' ) );

			return;
		}

		Status::measure( self::JOB, count( $rows ) );
		set_transient( self::TRANSIENT_PREFIX . $run, $rows, self::TRANSIENT_TTL );

		Scheduler::chain(
			Scheduler::ACTION_SYNC_DELIVERY_CHUNK,
			array(
				'offset' => 0,
				'run'    => $run,
			)
		);
	}

	/**
	 * Apply one chunk of delivery rows, then queue the next.
	 *
	 * @param int $offset Number of rows already applied.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function apply_chunk( $offset, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding delivery chunk at offset %d: run %d has been superseded.', $offset, $run ) );

			return;
		}

		$rows = get_transient( self::TRANSIENT_PREFIX . $run );

		if ( ! is_array( $rows ) ) {
			Status::fail( self::JOB, __( 'The cached delivery payload expired before it could be applied.', 'woo-kontor-sync-pro' ) );

			return;
		}

		$chunk = array_slice( $rows, $offset, self::CHUNK_SIZE, true );

		if ( empty( $chunk ) ) {
			$this->complete( $run );

			return;
		}

		Status::progress( self::JOB, $this->apply( $chunk ) );
		Status::advance( self::JOB, count( $chunk ) );

		$next = $offset + count( $chunk );

		if ( $next >= count( $rows ) ) {
			$this->complete( $run );

			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_DELIVERY_CHUNK,
			array(
				'offset' => $next,
				'run'    => $run,
			)
		);
	}

	/**
	 * Apply a batch of delivery rows to their orders.
	 *
	 * @param array $chunk Rows keyed by order number.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk ) {
		$counts = array(
			'updated'   => 0,
			'completed' => 0,
			'partial'   => 0,
			'missing'   => 0,
			'unchanged' => 0,
		);

		foreach ( $chunk as $number => $row ) {
			$order = $this->find_order( (string) $number );

			if ( ! $order ) {
				++$counts['missing'];

				continue;
			}

			/*
			 * Read before apply_row() writes over it. Its own answer cannot be used: it
			 * reports whether any of four fields moved, so a status that changed or an
			 * Auftrnr backfilled is indistinguishable there from a parcel being sent.
			 */
			$before = trim( (string) $order->get_meta( self::META_TRACKING ) );

			$changed = $this->apply_row( $order, $row );

			if ( ! $changed ) {
				++$counts['unchanged'];

				continue;
			}

			++$counts['updated'];

			$target = $this->target_status( $order, $row );

			if ( 'completed' === $target ) {
				/*
				 * This transition emails the customer. It is deliberate: Kontor saying the
				 * order shipped is exactly when the shop wants that mail to go out. Only
				 * ever forwards — an order already completed, cancelled or refunded is
				 * left where it is.
				 */
				$order->update_status(
					'completed',
					__( 'Kontor reports this order as completed.', 'woo-kontor-sync-pro' )
				);

				++$counts['completed'];
			} elseif ( '' !== $target ) {
				/*
				 * Part of the order has shipped and part has not. No email is attached to
				 * this status, which is the point: the customer has not been told the order
				 * is on its way, because most of it is not.
				 */
				$order->update_status(
					$target,
					__( 'Kontor reports this order as partially completed.', 'woo-kontor-sync-pro' )
				);

				++$counts['partial'];
			}

			// After the transition, so anything listening describes the order as it now
			// stands rather than as it stood a line ago.
			$this->announce_tracking( $order, $before, $row, $target );
		}

		return $counts;
	}

	/**
	 * Say that a parcel is on its way, unless the shop is already saying it.
	 *
	 * Three conditions, and each one is load-bearing:
	 *
	 * - There is a tracking number at all. Kontor sends provider and trackinginfo as
	 *   null rather than omitting them, so a synced but unshipped order carries the
	 *   meta present and empty on every run.
	 * - It is not the number the order already had. The stored meta is the whole
	 *   idempotency mechanism: apply_row() writes it before this runs, so a repeat run
	 *   — or Action Scheduler retrying a chunk that died after the save — reads the
	 *   same value and says nothing. There is deliberately no separate "announced"
	 *   marker: a second record could disagree with the first, and then neither is
	 *   trustworthy.
	 * - The order is not being completed by this run. That transition fires
	 *   WooCommerce's own completion mail, which already carries these details —
	 *   apply_row() wrote the meta before the status moved, so Frontend\Tracking
	 *   renders into it. Announcing here as well would tell the customer twice,
	 *   seconds apart.
	 *
	 * The partial-completion path is deliberately not excluded. That status carries no
	 * email by design, which is exactly the gap this fills: part of the order has
	 * shipped and nothing else would ever say so.
	 *
	 * @param WC_Order $order  Order the row was applied to.
	 * @param string   $before Tracking number the order carried before this run.
	 * @param array    $row    Normalised delivery row.
	 * @param string   $target Status this run moved the order to, if any.
	 * @return void
	 */
	protected function announce_tracking( $order, $before, array $row, $target ) {
		if ( '' === $row['tracking'] || $before === $row['tracking'] || 'completed' === $target ) {
			return;
		}

		/**
		 * Fires when Kontor reports a tracking number the order did not have.
		 *
		 * Registered as a WooCommerce transactional email action, so the mailer is
		 * instantiated before anything listens for it. Scalars only: WooCommerce is
		 * free to defer a transactional email and replay it from a queue, and an order
		 * object carried across that gap would be a stale copy.
		 *
		 * @since 0.20.0
		 *
		 * @param int    $order_id Order the shipment belongs to.
		 * @param string $tracking Tracking number Kontor reported.
		 */
		do_action( 'woo_kontor_sync_tracking_received', (int) $order->get_id(), (string) $row['tracking'] );
	}

	/**
	 * Write one row's details onto an order.
	 *
	 * @param WC_Order $order Order to update.
	 * @param array    $row   Normalised delivery row.
	 * @return bool True when something actually changed.
	 */
	protected function apply_row( $order, array $row ) {
		$fields = array(
			self::META_STATUS       => $row['status'],
			self::META_PROVIDER     => $row['provider'],
			self::META_TRACKING     => $row['tracking'],
			self::META_TRACKING_URL => $row['tracking_url'],
		);

		$changed = false;

		foreach ( $fields as $meta_key => $value ) {
			if ( (string) $order->get_meta( $meta_key ) === $value ) {
				continue;
			}

			$order->update_meta_data( $meta_key, $value );
			$changed = true;
		}

		// Backfill the Kontor order number for anything accepted as a duplicate.
		if ( '' !== $row['auftrnr'] && (string) $order->get_meta( OrderSync::META_KONTOR_ORDER ) !== $row['auftrnr'] ) {
			$order->update_meta_data( OrderSync::META_KONTOR_ORDER, $row['auftrnr'] );
			$changed = true;
		}

		if ( ! $changed ) {
			return false;
		}

		if ( '' !== $row['tracking'] ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: shipping provider, 2: tracking number. */
					__( 'Kontor tracking: %1$s %2$s', 'woo-kontor-sync-pro' ),
					'' === $row['provider'] ? __( 'unknown carrier', 'woo-kontor-sync-pro' ) : $row['provider'],
					$row['tracking']
				)
			);
		}

		$order->save();

		return true;
	}

	/**
	 * The WooCommerce status this row should move the order to.
	 *
	 * Two of Kontor's four statuses move an order, and each only out of the statuses
	 * that sit behind it. An order already partially completed can still complete; one
	 * already completed is never walked back to partial.
	 *
	 * @param WC_Order $order Order being updated.
	 * @param array    $row   Normalised delivery row.
	 * @return string Status slug to move to, or an empty string to leave the order alone.
	 */
	protected function target_status( $order, array $row ) {
		$targets = array(
			self::STATUS_COMPLETED => 'completed',
			self::STATUS_PARTIAL   => PartialStatus::STATUS,
		);

		if ( ! isset( $targets[ $row['status'] ] ) ) {
			return '';
		}

		if ( ! in_array( $order->get_status(), self::$movable[ $row['status'] ], true ) ) {
			return '';
		}

		return $targets[ $row['status'] ];
	}

	/**
	 * Find the order a delivery row belongs to.
	 *
	 * @param string $number Order number from Kontor.
	 * @return WC_Order|null The order, or null when nothing matches.
	 */
	protected function find_order( $number ) {
		return OrderSync::find_by_number( $number );
	}

	/**
	 * Reduce the API rows to a map keyed by order number.
	 *
	 * @param array $rows Rows from the orders entity.
	 * @return array Normalised rows keyed by order number.
	 */
	protected function normalise( array $rows ) {
		$normalised = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$number = isset( $row['ordernumber'] ) ? trim( (string) $row['ordernumber'] ) : '';

			if ( '' === $number ) {
				continue;
			}

			$normalised[ $number ] = array(
				'auftrnr'      => $this->text( $row, 'Auftrnr' ),
				'status'       => strtolower( $this->text( $row, 'orderstatus' ) ),
				'provider'     => $this->text( $row, 'provider' ),
				'tracking'     => $this->text( $row, 'trackinginfo' ),
				'tracking_url' => esc_url_raw( $this->text( $row, 'trackingurl' ) ),
			);
		}

		return $normalised;
	}

	/**
	 * Read a field from a row, treating null as an empty string.
	 *
	 * @param array  $row Delivery row.
	 * @param string $key Field name.
	 * @return string Field value.
	 */
	protected function text( array $row, $key ) {
		if ( ! isset( $row[ $key ] ) || ! is_scalar( $row[ $key ] ) ) {
			return '';
		}

		return trim( wp_strip_all_tags( (string) $row[ $key ] ) );
	}

	/**
	 * Close out a run and drop its cached payload.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	protected function complete( $run ) {
		delete_transient( self::TRANSIENT_PREFIX . $run );

		$counts = Status::get( self::JOB )['counts'];

		Status::finish(
			self::JOB,
			sprintf(
				/* translators: 1: orders updated, 2: orders completed, 3: orders partially completed, 4: order numbers with no matching order. */
				__( '%1$d orders updated, %2$d completed, %3$d partially completed, %4$d not found locally.', 'woo-kontor-sync-pro' ),
				isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
				isset( $counts['completed'] ) ? (int) $counts['completed'] : 0,
				isset( $counts['partial'] ) ? (int) $counts['partial'] : 0,
				isset( $counts['missing'] ) ? (int) $counts['missing'] : 0
			)
		);
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to record.
	 * @return void
	 */
	protected function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => Client::LOG_SOURCE ) );
	}
}
