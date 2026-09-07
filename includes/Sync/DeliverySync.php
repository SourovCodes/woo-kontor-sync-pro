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
	 * Every parcel Kontor has ever reported for an order.
	 *
	 * A list of arrays with "provider", "number" and "url" keys, oldest first, and it
	 * only ever grows. The three singular keys above name the most recent parcel and are
	 * what everything read before 0.32.0; this is the record.
	 *
	 * It exists because the listing carries one tracking number per order and Kontor is
	 * not consistent about which. Order 15339 on the live account shipped in two parcels
	 * and the entity alternated between them, one per hourly run, for eleven days —
	 * so the old code overwrote the stored number each time, read the overwrite as a
	 * parcel that had just shipped, and sent the customer **33 tracking emails**. Kontor
	 * described this as returning "only one, but always the first"; it is neither.
	 */
	const META_SHIPMENTS = '_wksync_shipments';

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

		/*
		 * Settled before a single chunk is queued: a payload that could not be stored
		 * means every chunk after this finds nothing, and saying so here — once — beats
		 * saying it from inside the first chunk, where the honest reason is gone.
		 */
		if ( ! Payload::put( self::JOB, $rows ) ) {
			Status::fail( self::JOB, __( 'The delivery rows could not be stored for the run to work through.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', 'Delivery sync aborted: the payload could not be stored.' );

			return;
		}

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

		$rows = Payload::get( self::JOB );

		if ( null === $rows ) {
			Status::fail( self::JOB, __( 'The stored delivery rows could not be read, so the run was stopped part-way.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', sprintf( 'Delivery sync aborted at offset %d: the stored payload could not be read.', $offset ) );

			return;
		}

		$chunk = array_slice( $rows, $offset, self::CHUNK_SIZE, true );

		if ( empty( $chunk ) ) {
			$this->complete();

			return;
		}

		Status::progress( self::JOB, $this->apply( $chunk ) );
		Status::advance( self::JOB, count( $chunk ) );

		$next = $offset + count( $chunk );

		if ( $next >= count( $rows ) ) {
			$this->complete();

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
			 * apply_row()'s own answer cannot decide whether to announce: it reports that
			 * any of four fields moved, so a status that changed or an Auftrnr backfilled
			 * is indistinguishable there from a parcel being sent. record_shipment() is
			 * what knows, and hands its verdict back through $shipment.
			 */
			$shipment = array();
			$changed  = $this->apply_row( $order, $row, $shipment );

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
			$this->announce_tracking( $order, $shipment, $row, $target );
		}

		return $counts;
	}

	/**
	 * Say that a parcel is on its way, unless the shop is already saying it.
	 *
	 * Three conditions, and each one is load-bearing:
	 *
	 * - A parcel this order has not carried before. The stored list is the whole
	 *   idempotency mechanism: record_shipment() writes it before this runs, so a repeat
	 *   run — or Action Scheduler retrying a chunk that died after the save — finds the
	 *   number already there and says nothing. There is deliberately no separate
	 *   "announced" marker: a second record could disagree with the first, and then
	 *   neither is trustworthy.
	 *
	 *   Until 0.32.0 this compared the reported number against the single stored one,
	 *   which is not the same question and is the bug that mailed order 15339 thirty-
	 *   three times: Kontor alternated between that order's two parcels, and every flip
	 *   read as a number the order did not have. A list only ever grows, so a listing
	 *   that swings back to a parcel already recorded is silence.
	 * - The list was not seeded by this very run. An order that already carried a
	 *   tracking number when this version landed has been announced once already, by the
	 *   version before it; adopting that number into the list is bookkeeping rather than
	 *   news. Same rule as an invoice status seen for the first time.
	 * - The order is not being completed by this run. That transition fires
	 *   WooCommerce's own completion mail, which already carries these details —
	 *   apply_row() wrote the meta before the status moved, so Frontend\Tracking renders
	 *   into it. Announcing here as well would tell the customer twice, seconds apart.
	 *
	 * The partial-completion path is deliberately not excluded. That status carries no
	 * email by design, which is exactly the gap this fills: part of the order has
	 * shipped and nothing else would ever say so. It is also the case that produces
	 * several parcels in the first place.
	 *
	 * @param WC_Order $order    Order the row was applied to.
	 * @param array    $shipment Verdict from record_shipment().
	 * @param array    $row      Normalised delivery row.
	 * @param string   $target   Status this run moved the order to, if any.
	 * @return void
	 */
	protected function announce_tracking( $order, array $shipment, array $row, $target ) {
		if ( empty( $shipment['grew'] ) || ! empty( $shipment['seeded'] ) || 'completed' === $target ) {
			return;
		}

		/**
		 * Fires when Kontor reports a parcel the order has not carried before.
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
	 * @param WC_Order $order    Order to update.
	 * @param array    $row      Normalised delivery row.
	 * @param array    $shipment Filled in with record_shipment()'s verdict, by reference,
	 *                           because apply_row()'s own answer cannot tell a parcel
	 *                           from a status change and announce_tracking() needs both.
	 * @return bool True when something actually changed.
	 */
	protected function apply_row( $order, array $row, &$shipment = null ) {
		$changed  = false;
		$shipment = $this->record_shipment( $order, $row );

		$fields = array( self::META_STATUS => $row['status'] );

		/*
		 * The singular keys name the most recent parcel, so they are only rewritten when
		 * one arrives. A listing that swings back to a parcel already recorded leaves
		 * them where they are — otherwise every flip would rewrite three meta rows, save
		 * the order and add a note, for no new information.
		 */
		if ( empty( $shipment['known'] ) ) {
			$fields[ self::META_PROVIDER ]     = $row['provider'];
			$fields[ self::META_TRACKING ]     = $row['tracking'];
			$fields[ self::META_TRACKING_URL ] = $row['tracking_url'];
		}

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

		// Only a parcel this order has not carried before is worth a note. Kontor
		// alternating between two of them is not eleven days of shipments.
		if ( ! empty( $shipment['grew'] ) ) {
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
	 * Record the parcel a row reports, and say whether it is one we had not seen.
	 *
	 * The list only ever grows. Kontor sends one tracking number per order and is not
	 * consistent about which of an order's parcels it picks, so the only safe reading of
	 * a number we have not seen is "another parcel", and of one we have is "the same
	 * parcels, described differently". Removing the old one — which is what writing a
	 * single meta key amounted to — loses a parcel the customer may already be tracking.
	 *
	 * **Seeding is silent.** An order that already carried a tracking number when this
	 * version landed has been announced once by the version before it, so adopting that
	 * number into a fresh list is bookkeeping, not news. A second parcel that happens to
	 * arrive in the same run is adopted silently too: the cost is one missed
	 * announcement, once, on the run that migrates the order, and the alternative is
	 * mailing customers about parcels that shipped weeks ago.
	 *
	 * @param WC_Order $order Order to record against.
	 * @param array    $row   Normalised delivery row.
	 * @return array "grew", "known" and "seeded" booleans.
	 */
	protected function record_shipment( $order, array $row ) {
		$verdict = array(
			'grew'   => false,
			'known'  => false,
			'seeded' => false,
		);

		if ( '' === $row['tracking'] ) {
			return $verdict;
		}

		$stored    = $order->get_meta( self::META_SHIPMENTS );
		$shipments = is_array( $stored ) ? $stored : array();
		$seeding   = empty( $shipments );

		if ( $seeding ) {
			$legacy = trim( (string) $order->get_meta( self::META_TRACKING ) );

			/*
			 * Seeded means "a parcel an earlier version already announced was adopted",
			 * not merely "this order had no list yet". An order that has never carried a
			 * tracking number has told the customer nothing, so its first parcel is news
			 * and must announce — which is the ordinary case and most of what this job
			 * exists for.
			 */
			if ( '' !== $legacy ) {
				$verdict['seeded'] = true;

				$shipments[] = array(
					'provider' => trim( (string) $order->get_meta( self::META_PROVIDER ) ),
					'number'   => $legacy,
					'url'      => trim( (string) $order->get_meta( self::META_TRACKING_URL ) ),
				);
			}
		}

		foreach ( $shipments as $shipped ) {
			if ( is_array( $shipped ) && isset( $shipped['number'] ) && (string) $shipped['number'] === $row['tracking'] ) {
				if ( ! empty( $verdict['seeded'] ) ) {
					$order->update_meta_data( self::META_SHIPMENTS, array_values( $shipments ) );
				}

				$verdict['known'] = true;

				return $verdict;
			}
		}

		$shipments[] = array(
			'provider' => $row['provider'],
			'number'   => $row['tracking'],
			'url'      => $row['tracking_url'],
		);

		$order->update_meta_data( self::META_SHIPMENTS, array_values( $shipments ) );

		$verdict['grew'] = true;

		return $verdict;
	}

	/**
	 * Every parcel an order carries, oldest first.
	 *
	 * The one place anything displaying tracking reads it, so the order page, the order
	 * emails and the admin panel cannot disagree about how many parcels there are.
	 *
	 * Falls back to the three singular meta keys for an order the delivery sync has not
	 * touched since 0.32.0, which is every order on the day this ships. Kontor sends
	 * provider and trackinginfo as null rather than omitting them, so an order synced but
	 * not yet shipped has that meta present and empty; the number is what decides there
	 * is anything to show.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array List of parcels, each with "provider", "number" and "url".
	 */
	public static function shipments( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$stored     = $order->get_meta( self::META_SHIPMENTS );
		$shipments  = array();
		$candidates = is_array( $stored ) && ! empty( $stored ) ? $stored : array(
			array(
				'provider' => $order->get_meta( self::META_PROVIDER ),
				'number'   => $order->get_meta( self::META_TRACKING ),
				'url'      => $order->get_meta( self::META_TRACKING_URL ),
			),
		);

		foreach ( $candidates as $parcel ) {
			if ( ! is_array( $parcel ) ) {
				continue;
			}

			$number = isset( $parcel['number'] ) ? trim( (string) $parcel['number'] ) : '';

			if ( '' === $number ) {
				continue;
			}

			$shipments[] = array(
				'provider' => isset( $parcel['provider'] ) ? trim( (string) $parcel['provider'] ) : '',
				'number'   => $number,
				'url'      => isset( $parcel['url'] ) ? trim( (string) $parcel['url'] ) : '',
			);
		}

		return $shipments;
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
	 * Close out a run and drop the payload it was working through.
	 *
	 * It takes no run identifier: the payload is keyed on the job, and whichever pass
	 * gets here has already checked that this run is the current one.
	 *
	 * @return void
	 */
	protected function complete() {
		Payload::forget( self::JOB );

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
