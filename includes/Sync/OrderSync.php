<?php
/**
 * Order upload to Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use Exception;
use WC_Order;
use WC_Order_Item_Product;
use WC_Tax;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Pushes WooCommerce orders into Kontor.
 *
 * The only write this plugin performs. Two entry points feed it: an order reaching
 * a pushable status queues a single-order action, and a periodic sweep catches
 * anything the hook missed — a failed run, a site that was down, an order edited
 * into a pushable status by hand.
 *
 * Kontor deduplicates on orderNumber with overwrite_all left false, so re-sending
 * is safe: the second attempt comes back as a per-order "Dublette" instead of
 * creating a duplicate. That reply is treated as success, because it means the
 * order is in the ERP, which is the outcome this job exists to guarantee.
 */
class OrderSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'orders';

	/**
	 * Meta holding Kontor's internal order number (Auftrnr).
	 */
	const META_KONTOR_ORDER = '_wksync_kontor_order_id';

	/**
	 * Meta holding the time the order reached Kontor.
	 */
	const META_PUSHED_AT = '_wksync_pushed_at';

	/**
	 * Meta holding the order number sent as the deduplication key.
	 *
	 * That key is the order ID, which never changes. It is still recorded rather
	 * than recomputed, so the delivery sync matches on the value Kontor was actually
	 * given even if an order sent by an older version of this plugin used something
	 * else.
	 */
	const META_ORDER_NUMBER = '_wksync_order_number';

	/**
	 * Meta holding the reason the last push attempt was rejected.
	 */
	const META_PUSH_ERROR = '_wksync_push_error';

	/**
	 * The value sent as the required meta.userId.
	 *
	 * Kontor requires the field on every upload and does not validate it; this is the
	 * value agreed for this integration. Fixed rather than filterable, because the
	 * settings screen shows it as read-only and a filter would make that display a
	 * lie about what is actually sent.
	 */
	const UPLOAD_USER_ID = 'WKSP';

	/**
	 * How many orders to send in one request.
	 */
	const BATCH_SIZE = 25;

	/**
	 * How many orders one sweep will look at.
	 */
	const SWEEP_LIMIT = 200;

	/**
	 * Largest number of orders one force push may send.
	 *
	 * The force push runs in the request that asked for it, with no queue behind it,
	 * so this is the one place in the plugin where an execution limit is a real risk
	 * rather than a theoretical one: every BATCH_SIZE orders is a round trip that can
	 * take up to Client::REQUEST_TIMEOUT. Bounded here so a shop with ten thousand
	 * orders gets a press that finishes and reports, rather than a white screen and
	 * no way of knowing how far it got.
	 */
	const FORCE_LIMIT = 100;

	/**
	 * Transient prefix holding the order IDs a run is working through.
	 *
	 * The list is fixed when the sweep starts rather than re-queried per batch:
	 * pending_orders() asks for orders that have never been sent, and a rejected order
	 * still has not been sent, so re-querying would hand the same failures back for
	 * ever instead of finishing.
	 */
	const TRANSIENT_PREFIX = 'wksync_orders_run_';

	/**
	 * How long that list survives, in seconds.
	 */
	const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

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
	 * The order statuses that get pushed to Kontor.
	 *
	 * Paid states only. An order that is still pending or on hold has not been paid
	 * for, and one that is cancelled or refunded should never reach the ERP as a
	 * live order.
	 *
	 * @return string[] Status slugs, without the "wc-" prefix.
	 */
	public static function pushable_statuses() {
		/**
		 * Filters the order statuses that are sent to Kontor.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $statuses Status slugs without the "wc-" prefix.
		 */
		return (array) apply_filters(
			'woo_kontor_sync_pushable_statuses',
			array( 'processing', 'completed' )
		);
	}

	/**
	 * Queue an order for pushing after it reaches a pushable status.
	 *
	 * Called from an order status hook, which runs inside a request a customer may
	 * be waiting on, so this only ever enqueues an action.
	 *
	 * Two settings can decide there is nothing to queue, and they are read here rather
	 * than around the add_action() that leads here: gating the hook would mean reading
	 * the settings option on every request the site serves in order to decide about
	 * the few that are checkouts. This costs nothing until an order is paid, and it
	 * keeps the decision beside pushable_statuses(), which is the other half of the
	 * same rule.
	 *
	 * Nothing is lost by holding an order back. META_PUSHED_AT is only written by a
	 * push that happened, so pending_orders() picks the order up on the next sweep
	 * exactly as it picks up one Kontor rejected.
	 *
	 * @param int $order_id Order that changed status.
	 * @return void
	 */
	public function enqueue( $order_id ) {
		if ( ! Settings::orders_enabled( $this->settings ) ) {
			return;
		}

		if ( Settings::PUSH_SWEEP === Settings::push_mode( $this->settings ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || ! in_array( $order->get_status(), self::pushable_statuses(), true ) ) {
			return;
		}

		if ( $order->get_meta( self::META_PUSHED_AT ) ) {
			return;
		}

		Scheduler::chain( Scheduler::ACTION_SYNC_ORDER, array( 'order_id' => (int) $order->get_id() ) );
	}

	/**
	 * Push a single order, identified by ID.
	 *
	 * @param int $order_id Order to push.
	 * @return void
	 */
	public function push_one( $order_id ) {
		$order = wc_get_order( $order_id );

		// Settled locally first: there is no point paying for a preflight round trip
		// to decide not to send anything.
		if ( ! $order || $order->get_meta( self::META_PUSHED_AT ) ) {
			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			$this->log( 'error', sprintf( 'Order %d not sent: %s', $order_id, $ready->get_error_message() ) );

			return;
		}

		$this->send( array( $order ) );
	}

	/**
	 * Re-send orders with overwrite_all set, in the caller's own request.
	 *
	 * The ordinary push cannot update anything. Kontor deduplicates on orderNumber
	 * and overwrite_all is left false, so an order edited after it reached the ERP
	 * comes back "Dublette" and the edit never lands. This is the deliberate way out,
	 * and everything about it is different from the sync jobs on purpose:
	 *
	 * - It runs synchronously, so the operator sees Kontor's answer on the screen
	 *   they pressed the button on rather than in a log an hour later.
	 * - It never touches Status. A run belongs to a scheduled job, and marking one
	 *   here would collide with a sweep and leave the job "running" if this request
	 *   were cut short.
	 * - It reports every reply verbatim, because overwrite_all's exact behaviour was
	 *   never established against the live API and the reply is the evidence.
	 *
	 * @param int[] $order_ids Orders to force through, already bounded by the caller.
	 * @return array Result for display: counts, per-order rows and the raw replies.
	 */
	public function force_push( array $order_ids ) {
		$result = array(
			'attempted' => 0,
			'sent'      => 0,
			'duplicate' => 0,
			'failed'    => 0,
			'skipped'   => 0,
			'rows'      => array(),
			'responses' => array(),
			'error'     => '',
		);

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			$result['error'] = $ready->get_error_message();
			$this->log( 'error', 'Force push refused: ' . $ready->get_error_message() );

			return $result;
		}

		/*
		 * Chunked even though nothing is queued. The batch size is what the ordinary
		 * sweep sends, so this exercises a request shape Kontor has already accepted
		 * rather than a single body carrying two hundred orders.
		 */
		foreach ( array_chunk( $order_ids, self::BATCH_SIZE ) as $chunk ) {
			$this->force_push_batch( $chunk, $result );
		}

		$this->log(
			'notice',
			sprintf(
				'Force push finished: %d overwritten, %d reported as duplicates, %d rejected, %d skipped.',
				$result['sent'],
				$result['duplicate'],
				$result['failed'],
				$result['skipped']
			)
		);

		return $result;
	}

	/**
	 * Send one chunk of a force push and fold the reply into the running result.
	 *
	 * @param int[] $order_ids Orders in this chunk.
	 * @param array $result    Result being accumulated, by reference.
	 * @return void
	 */
	protected function force_push_batch( array $order_ids, array &$result ) {
		$payload = array();
		$by_key  = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				++$result['skipped'];

				continue;
			}

			try {
				$mapped = $this->build_payload( $order );
			} catch ( Exception $exception ) {
				++$result['failed'];

				$result['rows'][] = array(
					'order'   => (int) $order_id,
					'status'  => 'error',
					'message' => $exception->getMessage(),
				);

				$this->log( 'error', sprintf( 'Order %d could not be mapped for a force push: %s', $order_id, $exception->getMessage() ) );

				continue;
			}

			$payload[]                        = $mapped;
			$by_key[ $mapped['orderNumber'] ] = $order;
		}

		if ( empty( $payload ) ) {
			return;
		}

		$result['attempted'] += count( $payload );

		$response = $this->client->push_orders(
			$payload,
			(string) $this->settings['shop_id'],
			self::UPLOAD_USER_ID,
			true
		);

		if ( is_wp_error( $response ) ) {
			$result['failed']     += count( $payload );
			$result['responses'][] = array(
				'ok'      => false,
				'message' => $response->get_error_message(),
				'code'    => (string) Client::detail( $response, 'error_code', '' ),
				'status'  => (int) Client::detail( $response, 'status', 0 ),
				'raw'     => Client::detail( $response, 'raw', null ),
			);

			foreach ( $by_key as $number => $order ) {
				$order->update_meta_data( self::META_PUSH_ERROR, $response->get_error_message() );
				$order->save();

				$result['rows'][] = array(
					'order'   => (int) $order->get_id(),
					'status'  => 'error',
					'message' => $response->get_error_message(),
				);
			}

			$this->log( 'error', 'Force push batch was not accepted: ' . $response->get_error_message() );

			return;
		}

		$result['responses'][] = array(
			'ok'      => true,
			'message' => '',
			'code'    => '',
			'status'  => 200,
			'raw'     => isset( $response['raw'] ) ? $response['raw'] : $response,
		);

		$this->interpret_force_rows( $response['data'], $by_key, $result );
	}

	/**
	 * Apply the verdicts from a force push.
	 *
	 * Kept apart from interpret_rows() because that one reports into a job's Status
	 * and this has no run to report into. The verdicts themselves read the same way,
	 * with one difference: under overwrite_all a "Dublette" is no longer the happy
	 * ending it is on an ordinary push. It means Kontor declined to overwrite, so it
	 * is surfaced as its own outcome rather than folded into the successes.
	 *
	 * @param array      $rows   Result rows from the upsert reply.
	 * @param WC_Order[] $by_key Orders keyed by the order number that was sent.
	 * @param array      $result Result being accumulated, by reference.
	 * @return void
	 */
	protected function interpret_force_rows( array $rows, array $by_key, array &$result ) {
		$reported = array();

		foreach ( $rows as $row ) {
			$number = isset( $row['orderNumber'] ) ? (string) $row['orderNumber'] : '';
			$order  = isset( $by_key[ $number ] ) ? $by_key[ $number ] : null;

			if ( ! $order ) {
				$this->log( 'warning', sprintf( 'Force push: Kontor reported on an order number we did not send: %s', '' === $number ? '(null)' : $number ) );

				continue;
			}

			$reported[ $number ] = true;

			$status  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';

			if ( 'ok' === $status ) {
				$auftrnr = isset( $row['auftrnr'] ) ? (string) $row['auftrnr'] : '';

				$this->mark_pushed(
					$order,
					$auftrnr,
					$number,
					'' === $auftrnr
						? __( 'Force-pushed to Kontor with overwrite enabled.', 'woo-kontor-sync-pro' )
						: sprintf(
							/* translators: %s: Kontor's internal order number. */
							__( 'Force-pushed to Kontor with overwrite enabled, as %s.', 'woo-kontor-sync-pro' ),
							$auftrnr
						)
				);

				++$result['sent'];

				$result['rows'][] = array(
					'order'   => (int) $order->get_id(),
					'status'  => 'ok',
					'message' => $message,
				);

				continue;
			}

			if ( $this->is_duplicate( $message ) ) {
				++$result['duplicate'];

				$result['rows'][] = array(
					'order'   => (int) $order->get_id(),
					'status'  => 'duplicate',
					'message' => $message,
				);

				$this->log(
					'warning',
					sprintf( 'Force push: Kontor still reported order %s as a duplicate, so the overwrite did not take.', $number )
				);

				continue;
			}

			$order->update_meta_data( self::META_PUSH_ERROR, $message );
			$order->save();

			++$result['failed'];

			$result['rows'][] = array(
				'order'   => (int) $order->get_id(),
				'status'  => 'error',
				'message' => $message,
			);

			$this->log( 'error', sprintf( 'Force push: Kontor rejected order %s: %s', $number, '' === $message ? '(no reason given)' : $message ) );
		}

		foreach ( $by_key as $number => $order ) {
			if ( isset( $reported[ $number ] ) ) {
				continue;
			}

			++$result['failed'];

			$result['rows'][] = array(
				'order'   => (int) $order->get_id(),
				'status'  => 'error',
				'message' => __( 'Kontor accepted the batch but said nothing about this order.', 'woo-kontor-sync-pro' ),
			);

			$this->log( 'error', sprintf( 'Force push: Kontor returned no verdict for order %s.', $number ) );
		}
	}

	/**
	 * Orders this plugin has already sent to Kontor.
	 *
	 * The set a force push is for: the ordinary sweep will never revisit them, so
	 * nothing else can carry a later edit across. Anything never sent is left out —
	 * it is already on its way through the normal path, and pushing it here would
	 * overwrite nothing.
	 *
	 * @param int $limit Largest number of orders to return.
	 * @return int[] Order IDs, oldest first.
	 */
	public function pushed_order_ids( $limit ) {
		$orders = wc_get_orders(
			array(
				'limit'      => (int) $limit,
				'status'     => self::pushable_statuses(),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'return'     => 'ids',

				// As in pending_orders(), this is the order meta table under HPOS, and
				// there is no other way to ask for "orders this plugin has sent".
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				'meta_query' => array(
					array(
						'key'     => self::META_PUSHED_AT,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return is_array( $orders ) ? array_map( 'absint', $orders ) : array();
	}

	/**
	 * Sweep for orders that should have reached Kontor and have not.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Order sync already running; ignoring the request to start another.' );

			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Order sync refused to start: ' . $ready->get_error_message() );

			return;
		}

		$run    = Status::start( self::JOB );
		$orders = $this->pending_orders();

		if ( empty( $orders ) ) {
			Status::finish( self::JOB, __( 'No orders were waiting to be sent.', 'woo-kontor-sync-pro' ) );

			return;
		}

		/*
		 * The IDs are cached rather than re-queried per batch. pending_orders() asks for
		 * orders this plugin has never sent, and a batch that failed still has not been
		 * sent — so re-querying would hand the same rejected orders back for ever.
		 */
		$ids = array_map(
			static function ( $order ) {
				return (int) $order->get_id();
			},
			$orders
		);

		set_transient( self::TRANSIENT_PREFIX . $run, $ids, self::TRANSIENT_TTL );

		Status::measure( self::JOB, count( $ids ) );

		Scheduler::chain(
			Scheduler::ACTION_SYNC_ORDERS_BATCH,
			array(
				'offset' => 0,
				'run'    => $run,
			)
		);
	}

	/**
	 * Send one batch of orders, then queue the next.
	 *
	 * The sweep used to send every batch inside the action that started it. That was
	 * an upload of up to SWEEP_LIMIT orders in one request, each batch a round trip to
	 * Kontor, with nothing to show for it until the whole thing was over — and on a
	 * slow link it was the one job that could be cut short by an execution limit,
	 * which for an upload means the remaining orders silently wait for the next sweep.
	 * Chaining bounds each action at one request and makes the run's position visible,
	 * exactly as the other jobs already were.
	 *
	 * @param int $offset Number of orders already sent.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function send_batch( $offset, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding order batch at offset %d: run %d has been superseded.', $offset, $run ) );

			return;
		}

		$ids = get_transient( self::TRANSIENT_PREFIX . $run );

		if ( ! is_array( $ids ) ) {
			Status::fail( self::JOB, __( 'The list of orders to send expired before they could be sent.', 'woo-kontor-sync-pro' ) );

			return;
		}

		$batch = array_slice( $ids, $offset, self::BATCH_SIZE );

		if ( empty( $batch ) ) {
			$this->complete( $run );

			return;
		}

		$orders = array();

		foreach ( $batch as $order_id ) {
			$order = wc_get_order( $order_id );

			/*
			 * Re-read now rather than carrying the objects across actions. An order can be
			 * cancelled, edited or sent by the single-order hook between the sweep starting
			 * and this batch running, and the copy taken at the start would not know.
			 */
			if ( $order && ! $order->get_meta( self::META_PUSHED_AT ) ) {
				$orders[] = $order;
			}
		}

		if ( ! empty( $orders ) ) {
			$this->send( $orders );
		}

		Status::advance( self::JOB, count( $batch ) );

		$next = $offset + count( $batch );

		if ( $next >= count( $ids ) ) {
			$this->complete( $run );

			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_ORDERS_BATCH,
			array(
				'offset' => $next,
				'run'    => $run,
			)
		);
	}

	/**
	 * Close the run and report what the sweep achieved.
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
				/* translators: 1: orders accepted, 2: orders already present, 3: orders rejected. */
				__( '%1$d sent, %2$d already in Kontor, %3$d rejected.', 'woo-kontor-sync-pro' ),
				isset( $counts['sent'] ) ? (int) $counts['sent'] : 0,
				isset( $counts['duplicate'] ) ? (int) $counts['duplicate'] : 0,
				isset( $counts['failed'] ) ? (int) $counts['failed'] : 0
			)
		);
	}

	/**
	 * Orders in a pushable status that have never reached Kontor.
	 *
	 * @return WC_Order[] Orders to send.
	 */
	protected function pending_orders() {
		$orders = wc_get_orders(
			array(
				'limit'      => self::SWEEP_LIMIT,
				'status'     => self::pushable_statuses(),
				'orderby'    => 'date',
				'order'      => 'ASC',
				'return'     => 'objects',

				/*
				 * Under HPOS this is a query against the order meta table, not postmeta.
				 * It runs in a background sweep, and there is no other way to ask for
				 * "orders this plugin has never sent".
				 */
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				'meta_query' => array(
					array(
						'key'     => self::META_PUSHED_AT,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return is_array( $orders ) ? $orders : array();
	}

	/**
	 * Map a batch of orders, send it, and record what came back.
	 *
	 * @param WC_Order[] $orders Orders to send.
	 * @return void
	 */
	protected function send( array $orders ) {
		$payload = array();
		$by_key  = array();

		foreach ( $orders as $order ) {
			try {
				$mapped = $this->build_payload( $order );
			} catch ( Exception $exception ) {
				Status::progress( self::JOB, array( 'failed' => 1 ) );
				$this->log( 'error', sprintf( 'Order %d could not be mapped: %s', $order->get_id(), $exception->getMessage() ) );

				continue;
			}

			$payload[]                        = $mapped;
			$by_key[ $mapped['orderNumber'] ] = $order;
		}

		if ( empty( $payload ) ) {
			return;
		}

		$response = $this->client->push_orders(
			$payload,
			(string) $this->settings['shop_id'],
			self::UPLOAD_USER_ID
		);

		if ( is_wp_error( $response ) ) {
			Status::progress( self::JOB, array( 'failed' => count( $payload ) ) );
			$this->log( 'error', 'Order batch was not accepted: ' . $response->get_error_message() );

			return;
		}

		$this->interpret_rows( $response['data'], $by_key );
	}

	/**
	 * Apply Kontor's per-order verdicts.
	 *
	 * The batch reply reports success at the top level even when every row failed,
	 * so the rows are the only thing worth reading.
	 *
	 * An order the reply says nothing about at all is counted as failed. Nothing is
	 * written on it, so the next sweep sends it again — but silently dropping it from
	 * the counts would report a batch of twenty-five as "five sent" and leave nobody
	 * any reason to look.
	 *
	 * @param array      $rows   Result rows from the upsert reply.
	 * @param WC_Order[] $by_key Orders keyed by the order number that was sent.
	 * @return void
	 */
	protected function interpret_rows( array $rows, array $by_key ) {
		$counts = array(
			'sent'      => 0,
			'duplicate' => 0,
			'failed'    => 0,
		);

		$reported = array();

		foreach ( $rows as $row ) {
			$number = isset( $row['orderNumber'] ) ? (string) $row['orderNumber'] : '';
			$order  = isset( $by_key[ $number ] ) ? $by_key[ $number ] : null;

			if ( ! $order ) {
				// A row we cannot tie back to an order, including the "no orders in the
				// array" reply, which carries a null order number.
				$this->log( 'warning', sprintf( 'Kontor reported on an order number we did not send: %s', '' === $number ? '(null)' : $number ) );

				continue;
			}

			$reported[ $number ] = true;

			$status  = isset( $row['status'] ) ? strtolower( (string) $row['status'] ) : '';
			$message = isset( $row['message'] ) ? (string) $row['message'] : '';

			if ( 'ok' === $status ) {
				$this->mark_pushed( $order, isset( $row['auftrnr'] ) ? (string) $row['auftrnr'] : '', $number );
				++$counts['sent'];

				continue;
			}

			/*
			 * A duplicate means the order is already in Kontor — the goal of this job —
			 * so it counts as done rather than as a failure to retry forever. Kontor
			 * does not return the existing Auftrnr here; the delivery sync fills it in.
			 */
			if ( $this->is_duplicate( $message ) ) {
				$this->mark_pushed( $order, '', $number );
				++$counts['duplicate'];

				continue;
			}

			$order->update_meta_data( self::META_PUSH_ERROR, $message );
			$order->save();

			++$counts['failed'];

			$this->log(
				'error',
				sprintf( 'Kontor rejected order %s: %s', $number, '' === $message ? '(no reason given)' : $message )
			);
		}

		foreach ( $by_key as $number => $order ) {
			if ( isset( $reported[ $number ] ) ) {
				continue;
			}

			$order->update_meta_data(
				self::META_PUSH_ERROR,
				__( 'Kontor accepted the batch but said nothing about this order.', 'woo-kontor-sync-pro' )
			);
			$order->save();

			++$counts['failed'];

			$this->log(
				'error',
				sprintf( 'Kontor returned no verdict for order %s; it will be sent again on the next sweep.', $number )
			);
		}

		Status::progress( self::JOB, $counts );
	}

	/**
	 * Whether a rejection message means the order was already there.
	 *
	 * @param string $message Message from the result row.
	 * @return bool True when the order exists in Kontor already.
	 */
	protected function is_duplicate( $message ) {
		return false !== stripos( $message, 'Dublette' );
	}

	/**
	 * Record that an order reached Kontor.
	 *
	 * @param WC_Order    $order   Order that was accepted.
	 * @param string      $auftrnr Kontor's internal order number, when it gave one.
	 * @param string      $number  Order number that was sent as the deduplication key.
	 * @param string|null $note    Order note to leave, or null for the ordinary wording.
	 * @return void
	 */
	protected function mark_pushed( $order, $auftrnr, $number, $note = null ) {
		$order->update_meta_data( self::META_PUSHED_AT, time() );
		$order->update_meta_data( self::META_ORDER_NUMBER, $number );
		$order->delete_meta_data( self::META_PUSH_ERROR );

		if ( '' !== $auftrnr ) {
			$order->update_meta_data( self::META_KONTOR_ORDER, $auftrnr );
		}

		if ( null === $note ) {
			$note = '' === $auftrnr
				? __( 'Already present in Kontor; not sent again.', 'woo-kontor-sync-pro' )
				: sprintf(
					/* translators: %s: Kontor's internal order number. */
					__( 'Sent to Kontor as %s.', 'woo-kontor-sync-pro' ),
					$auftrnr
				);
		}

		$order->add_order_note( $note );

		$order->save();
	}

	/**
	 * Find the order a row coming back from Kontor belongs to.
	 *
	 * Matched on the order number this plugin sent, which is recorded at push time
	 * rather than recomputed. get_order_number() is filterable, so comparing against
	 * it directly would stop matching the day a sequential-order-number plugin is
	 * installed — including for orders pushed long before that.
	 *
	 * Lives here because this is where the number is written, and both the delivery
	 * and invoice imports have to read it back the same way.
	 *
	 * @param string $number Order number as Kontor knows it.
	 * @return WC_Order|null The order, or null when nothing matches.
	 */
	public static function find_by_number( $number ) {
		$number = trim( (string) $number );

		if ( '' === $number ) {
			return null;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'status'     => 'any',
				'return'     => 'objects',

				/*
				 * Under HPOS this queries the order meta table. Runs in a background job,
				 * and the number Kontor knows is only recorded as meta.
				 */
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See above.
				'meta_query' => array(
					array(
						'key'     => self::META_ORDER_NUMBER,
						'value'   => $number,
						'compare' => '=',
					),
				),
			)
		);

		return empty( $orders ) ? null : $orders[0];
	}

	/**
	 * Map a WooCommerce order onto the API's order shape.
	 *
	 * @param WC_Order $order Order to map.
	 * @return array Order in the shape the upsert endpoint expects.
	 */
	public function build_payload( $order ) {
		/*
		 * The two order fields carry different values on purpose.
		 *
		 * orderNumber is Kontor's deduplication key and the value the delivery sync
		 * matches rows back on, so it has to be stable for the life of the order: the
		 * order ID is, and get_order_number() is not — it is filterable, and installing
		 * a sequential-order-number plugin would change what every existing order calls
		 * itself.
		 *
		 * orderId carries what the shop displays, so an order can still be found in the
		 * ERP by the number the customer and the shop manager both see.
		 */
		$number  = (string) $order->get_id();
		$display = (string) $order->get_order_number();
		$date    = $order->get_date_created();

		/*
		 * orderPlatformid is optional and deliberately not sent: it identifies the
		 * platform to Kontor, and there is no agreed value for this integration.
		 * Inventing one would put a meaningless string on every order in the ERP.
		 */
		$payload = array(
			'orderId'              => $display,
			'orderNumber'          => $number,
			'orderDate'            => $date ? gmdate( 'Y-m-d\TH:i:s\Z', $date->getTimestamp() ) : gmdate( 'Y-m-d\TH:i:s\Z' ),
			'currency'             => $order->get_currency(),
			'customerName'         => trim( $order->get_formatted_billing_full_name() ),
			'customerEmail'        => $order->get_billing_email(),
			'customerPhone'        => $order->get_billing_phone(),
			'customerGroup'        => isset( $this->settings['shoptype'] ) ? (string) $this->settings['shoptype'] : '',
			'language'             => substr( get_locale(), 0, 2 ),
			'billingAddress'       => $this->address( $order, 'billing' ),
			'deliveryAddress'      => $this->delivery_address( $order ),
			'shippingTotal'        => (float) $order->get_shipping_total(),
			'paymentMethod'        => $order->get_payment_method(),

			/*
			 * The gateway's own title, which is the wording the customer saw and the
			 * only thing that distinguishes two gateways sharing an id. paymentMethod
			 * stays as well: it is the stable slug, and the title is editable prose.
			 */
			'paymentMethodName'    => $order->get_payment_method_title(),

			/*
			 * The gateway's transaction reference, so a payment can be reconciled from
			 * the ERP without going back to the shop. Empty on an order that was never
			 * paid through a gateway, which is what the field expects.
			 */
			'paymentTransactionId' => $order->get_transaction_id(),

			/*
			 * Whether the amounts on this order include tax. Kontor has no way to tell
			 * from the numbers alone, and reading a gross total as net overstates every
			 * line by the VAT rate.
			 */
			'taxStatus'            => $order->get_prices_include_tax() ? 'gross' : 'net',
			'shippingMethod'       => $order->get_shipping_method(),
			'remarks'              => $order->get_customer_note(),
			'items'                => $this->items( $order ),
		);

		$customer_number = $order->get_customer_id();

		if ( $customer_number ) {
			$payload['customerNumber'] = (string) $customer_number;
		}

		/**
		 * Filters the payload for a single order before it is sent to Kontor.
		 *
		 * @since 0.3.0
		 *
		 * @param array    $payload Mapped order.
		 * @param WC_Order $order   Order it was mapped from.
		 */
		return (array) apply_filters( 'woo_kontor_sync_order_payload', $payload, $order );
	}

	/**
	 * Map the address the order ships to.
	 *
	 * Always sent, and always populated: WooCommerce leaves the shipping address
	 * empty on a virtual order, or on one where the customer did not tick "ship to a
	 * different address", and an order arriving in the ERP with no delivery address
	 * is one nobody can pick and pack. Billing is where it goes in that case, which
	 * is what WooCommerce itself falls back to on the order screen.
	 *
	 * Deciding on the street and postcode rather than on the whole address: a partial
	 * shipping address with only a name filled in is not somewhere a parcel can be
	 * sent.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array Address in the shape the API expects.
	 */
	protected function delivery_address( $order ) {
		$has_shipping = '' !== trim( (string) $order->get_shipping_address_1() )
			|| '' !== trim( (string) $order->get_shipping_postcode() );

		return $this->address( $order, $has_shipping ? 'shipping' : 'billing' );
	}

	/**
	 * Map one of an order's addresses.
	 *
	 * @param WC_Order $order Order to read.
	 * @param string   $type  Either "billing" or "shipping".
	 * @return array Address in the shape the API expects.
	 */
	protected function address( $order, $type ) {
		$getter = function ( $field ) use ( $order, $type ) {
			$method = 'get_' . $type . '_' . $field;

			return method_exists( $order, $method ) ? (string) $order->{$method}() : '';
		};

		$address = array(
			'firstName'   => $getter( 'first_name' ),
			'lastName'    => $getter( 'last_name' ),
			'company'     => $getter( 'company' ),
			'name'        => trim( $getter( 'first_name' ) . ' ' . $getter( 'last_name' ) ),
			'street'      => $getter( 'address_1' ),
			'street2'     => $getter( 'address_2' ),
			'zipcode'     => $getter( 'postcode' ),
			'city'        => $getter( 'city' ),
			'countryCode' => $getter( 'country' ),
			'phone'       => $getter( 'phone' ),
		);

		return array_filter(
			$address,
			static function ( $value ) {
				return '' !== $value;
			}
		);
	}

	/**
	 * Map an order's line items.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array Line items in the shape the API expects.
	 */
	protected function items( $order ) {
		$items    = array();
		$position = 1;
		$rates    = $this->order_tax_rates( $order );

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';

			/*
			 * SKU is the only key Kontor matches articles on, so a line without one
			 * cannot be resolved at the other end. Sending it anyway would have the ERP
			 * silently drop the line from an otherwise valid order.
			 */
			if ( '' === $sku ) {
				$this->log(
					'warning',
					sprintf( 'Order %s line %d has no SKU and was left out of the payload.', $order->get_order_number(), $item_id )
				);

				continue;
			}

			$quantity = (float) $item->get_quantity();
			$subtotal = (float) $item->get_subtotal();
			$total    = (float) $item->get_total();

			/*
			 * The three per-unit figures are all derived from the line itself, so they
			 * agree with each other and with totalPrice: regularPrice - discount is
			 * unitPrice, and unitPrice * quantity is totalPrice. Taking regularPrice
			 * from the product instead would read today's price for an order placed
			 * months ago, and would leave the arithmetic inconsistent the moment a
			 * coupon was involved.
			 *
			 * Rounded to four places rather than two for the same reason: two places on
			 * a per-unit figure cannot always be multiplied back up to the line total.
			 */
			$list = $quantity > 0 ? round( $subtotal / $quantity, 4 ) : 0.0;
			$paid = $quantity > 0 ? round( $total / $quantity, 4 ) : 0.0;

			$items[] = array(
				'itemId'       => (string) $item_id,
				'productId'    => (string) $item->get_product_id(),
				'sku'          => $sku,
				'description'  => $item->get_name(),
				'quantity'     => $quantity,
				'unitPrice'    => $paid,
				'regularPrice' => $list,

				/*
				 * The identity factor. Kontor multiplies by this, so what it defaults to
				 * on an absent field is the difference between the right price and none
				 * at all; sending 1 cannot change an amount either way.
				 */
				'priceFaktor'  => 1,
				'discount'     => round( $list - $paid, 4 ),
				'totalPrice'   => $total,
				'position'     => $position,
				'taxRate'      => $this->tax_rate( $item, $rates ),
			);

			++$position;
		}

		return $items;
	}

	/**
	 * Map an order's tax rates by rate ID.
	 *
	 * WooCommerce records the rate that applied on the order itself, as
	 * rate_percent on each tax line, so this is the historical rate the order was
	 * placed under rather than whatever the tax tables say today. An order from
	 * before that property existed carries null, and only then is the rate looked
	 * up; a rate since deleted resolves to nothing and the line falls back to
	 * deriving one.
	 *
	 * Read once per order rather than once per line, because an order with twenty
	 * lines has one or two rates between them.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array<int, float> Rate percentages keyed by tax rate ID.
	 */
	protected function order_tax_rates( $order ) {
		$rates = array();

		foreach ( $order->get_taxes() as $tax ) {
			$rate_id = (int) $tax->get_rate_id();

			if ( 0 === $rate_id ) {
				continue;
			}

			$percent = $tax->get_rate_percent();

			if ( null === $percent ) {
				$stored  = WC_Tax::_get_tax_rate( $rate_id );
				$percent = is_array( $stored ) && isset( $stored['tax_rate'] ) ? $stored['tax_rate'] : null;
			}

			if ( null === $percent ) {
				continue;
			}

			$rates[ $rate_id ] = round( (float) $percent, 4 );
		}

		return $rates;
	}

	/**
	 * Work out a line's tax rate as a percentage.
	 *
	 * Taken from the rates recorded on the order, never computed from the line's
	 * own amounts. A tax amount is money and is stored rounded to two decimals, so
	 * dividing it by the net total and multiplying by a hundred turns a rounding of
	 * half a rappen into a tenth of a percentage point: at 8.1%, a line of 4.15
	 * comes back as 8.19 and one of 9.90 as 8.08. The rate is a property of the
	 * article, so Kontor is right to expect one value rather than one per line.
	 *
	 * A line's tax data names every rate that applied to it, whatever the amounts
	 * came to, so a line discounted to nothing still reports the rate it was sold
	 * under instead of looking tax exempt. Where more than one rate applies the
	 * percentages are added, this field holding a single figure; that is exact
	 * unless the rates compound, which no shop this runs against uses.
	 *
	 * Deriving the rate is kept only for the case where nothing can be resolved —
	 * a manually built line, or an order old enough to predate rate_percent whose
	 * rate has since been deleted. A line no rate applies to is genuinely untaxed
	 * and answers zero, which is that path arriving at the right answer.
	 *
	 * @param WC_Order_Item_Product $item  Line item.
	 * @param array<int, float>     $rates Rate percentages keyed by tax rate ID.
	 * @return float Tax rate percentage.
	 */
	protected function tax_rate( $item, array $rates ) {
		$taxes   = $item->get_taxes();
		$applied = isset( $taxes['total'] ) && is_array( $taxes['total'] ) ? $taxes['total'] : array();
		$percent = null;

		foreach ( array_keys( $applied ) as $rate_id ) {
			if ( ! isset( $rates[ (int) $rate_id ] ) ) {
				continue;
			}

			$percent = ( null === $percent ? 0.0 : $percent ) + $rates[ (int) $rate_id ];
		}

		if ( null !== $percent ) {
			return round( $percent, 4 );
		}

		return $this->derived_tax_rate( $item );
	}

	/**
	 * Derive a line's tax rate from the amounts on it.
	 *
	 * The fallback described on tax_rate(), and inexact for the reason given there.
	 *
	 * @param WC_Order_Item_Product $item Line item.
	 * @return float Tax rate percentage.
	 */
	protected function derived_tax_rate( $item ) {
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();

		if ( $total <= 0.0 || $tax <= 0.0 ) {
			return 0.0;
		}

		return round( $tax / $total * 100, 2 );
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
