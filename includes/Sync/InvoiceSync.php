<?php
/**
 * Invoice PDF import from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WC_Order;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WooKontorSync\Invoices\Storage;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Brings the invoice documents Kontor issues back into WooCommerce.
 *
 * Two steps per invoice, because that is how the API is built. The invoices entity
 * lists what exists for the shop — a document id, an invoice number, a date and the
 * order number this plugin sent — and getdocument returns one of those documents as
 * base64. The listing is cheap and complete; the downloads are not, which is why the
 * two are separated here: the list is fetched once per run and only the ids that are
 * new are actually downloaded.
 *
 * Like the orders entity, the listing honours only filter.shopid, so every invoice
 * for the shop comes back on every run. The stored document ids are what make the
 * job incremental — without them each run would re-download the whole history.
 *
 * An order can be invoiced more than once, so the invoices are kept as a list rather
 * than a single file. Nothing already downloaded is ever replaced or deleted: an
 * invoice is a financial record, and the corrected one is a second document rather than
 * an edit of the first. Which of them a customer still owes is Kontor's "invoice_status"
 * and not this plugin's guess; see is_cancelled().
 */
class InvoiceSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'invoices';

	/**
	 * How many orders to settle per action.
	 *
	 * Much smaller than the delivery sync's chunk because each new invoice costs an HTTP
	 * round trip and a file write rather than a meta update. Orders rather than rows
	 * since 0.31.0 — see group() — which does not change what a chunk costs, because an
	 * order with more than one invoice is the exception.
	 */
	const CHUNK_SIZE = 10;

	/**
	 * Meta holding every invoice downloaded for an order.
	 *
	 * A list of arrays with "id", "number", "date", "status" and "file" keys, oldest
	 * first. Entries written before 0.31.0 have no "status"; see is_cancelled() for how
	 * that reads, and apply() for what fills it in.
	 */
	const META_INVOICES = '_wksync_invoices';

	/**
	 * The invoice_status Kontor reports for a cancelled document.
	 *
	 * Kontor's own spelling, with one "l", and the only value that withholds an invoice
	 * from a customer. Its opposite is "invoiced", which is not a constant here because
	 * nothing tests for it: anything that is not this is valid.
	 */
	const STATUS_CANCELLED = 'canceled';

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
	 * The invoices held for an order, newest first.
	 *
	 * Entries whose file has gone missing are left out rather than offered as a
	 * download that could only fail.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array List of invoices, each with "id", "number", "date", "status" and "file".
	 */
	public static function for_order( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$stored = $order->get_meta( self::META_INVOICES );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$invoices = array();

		foreach ( $stored as $invoice ) {
			if ( ! is_array( $invoice ) || empty( $invoice['file'] ) || ! Storage::exists( $invoice['file'] ) ) {
				continue;
			}

			$invoices[] = $invoice;
		}

		return array_reverse( $invoices );
	}

	/**
	 * Whether Kontor has cancelled an invoice.
	 *
	 * Up to 0.30.0 there was nothing to ask. The invoice row carried "id", "Belegname",
	 * "Belegnr", "Datum", "Auftrnr" and "ordernumber" and no status of any kind, every
	 * row read "Rechnung", and there was no cancellation entity behind /search — so the
	 * only signal available was that an order had more than one invoice, and this plugin
	 * inferred that the highest Belegnr was the live one and everything below it had been
	 * replaced. Its own docblock named the way that could be wrong: a second invoice
	 * might be a genuine part-delivery document rather than a correction, in which case
	 * the shop was telling a customer a valid, still-owed invoice had been cancelled.
	 *
	 * It was wrong, and Kontor has said so. Asked directly whether a second invoice can
	 * ever bill the rest of a partial delivery, the answer was that there can be several
	 * valid invoices for one order and the rule could not be applied — and the invoices
	 * entity now carries an "invoice_status" of "invoiced" or "canceled" on every row.
	 * So the guess is gone and this reads the field.
	 *
	 * **Only an unmistakable "canceled" cancels an invoice.** A missing key, a null, or a
	 * word this does not recognise reads as valid, for the reason Ws_aktiv is read the
	 * same way: the two ways of being wrong are not equal. Calling a valid invoice
	 * cancelled is a confident false statement about a financial record and tells a
	 * customer not to pay a bill they owe; the reverse leaves two documents listed
	 * without a heading, which is what every order looked like before any of this
	 * existed. Entries stored before 0.31.0 carry no status at all and land here.
	 *
	 * @param array $invoice Invoice entry, stored or normalised.
	 * @return bool True when Kontor reports the invoice as cancelled.
	 */
	public static function is_cancelled( array $invoice ) {
		$status = isset( $invoice['status'] ) && is_scalar( $invoice['status'] ) ? (string) $invoice['status'] : '';

		return self::STATUS_CANCELLED === strtolower( trim( $status ) );
	}

	/**
	 * Mark each of an order's invoices with whether it still counts.
	 *
	 * The split is Kontor's answer rather than ours; see is_cancelled(). An order may
	 * legitimately have several valid invoices, because a partial delivery is billed for
	 * what shipped and the rest is billed later, so this is not a "one current, the rest
	 * replaced" list and nothing reading it may assume index 0 is special.
	 *
	 * The ordering is presentation only — newest issued first, so a customer reads the
	 * most recent document at the top of each group.
	 *
	 * @param array $invoices Invoices as for_order() returns them.
	 * @return array The same invoices, each with a "current" boolean added, newest first.
	 */
	public static function classify( array $invoices ) {
		if ( empty( $invoices ) ) {
			return array();
		}

		usort( $invoices, array( __CLASS__, 'compare_issue' ) );

		$classified = array();

		foreach ( $invoices as $invoice ) {
			$invoice['current'] = ! self::is_cancelled( $invoice );
			$classified[]       = $invoice;
		}

		return $classified;
	}

	/**
	 * Order two invoices with the most recently issued first.
	 *
	 * Belegnr is Kontor's own issue sequence, and it is the only field that survives the
	 * listing coming back newest first — storage follows the feed, so a replacement is
	 * downloaded and appended *before* the document it replaces. It decides display
	 * order alone now; which invoice is valid is a field, not a comparison.
	 *
	 * @param array $a First invoice.
	 * @param array $b Second invoice.
	 * @return int Negative when $a was issued later, positive when $b was.
	 */
	protected static function compare_issue( array $a, array $b ) {
		$a_number = isset( $a['number'] ) ? (string) $a['number'] : '';
		$b_number = isset( $b['number'] ) ? (string) $b['number'] : '';

		if ( is_numeric( $a_number ) && is_numeric( $b_number ) && $a_number !== $b_number ) {
			return ( (float) $b_number <=> (float) $a_number );
		}

		$a_date = isset( $a['date'] ) ? (string) $a['date'] : '';
		$b_date = isset( $b['date'] ) ? (string) $b['date'] : '';

		if ( $a_date !== $b_date ) {
			return strcmp( $b_date, $a_date );
		}

		return strcmp( $b_number, $a_number );
	}

	/**
	 * Every invoice an order still owes, newest first.
	 *
	 * A list rather than one document, because an order billed in parts has more than
	 * one valid invoice and each of them is owed.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array List of invoices.
	 */
	public static function valid_for_order( $order ) {
		$valid = array();

		foreach ( self::classify( self::for_order( $order ) ) as $invoice ) {
			if ( ! self::is_cancelled( $invoice ) ) {
				$valid[] = $invoice;
			}
		}

		return $valid;
	}

	/**
	 * The invoices Kontor has cancelled, newest first.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array List of invoices.
	 */
	public static function cancelled_for_order( $order ) {
		$cancelled = array();

		foreach ( self::classify( self::for_order( $order ) ) as $invoice ) {
			if ( self::is_cancelled( $invoice ) ) {
				$cancelled[] = $invoice;
			}
		}

		return $cancelled;
	}

	/**
	 * The most recently issued invoice an order still owes.
	 *
	 * For the callers that genuinely want a single document to point at — the mail that
	 * says which invoice counts, and the document id the announcement hooks carry.
	 * Anything listing invoices to a reader wants valid_for_order() instead.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array|null The invoice, or null when the order has no valid one.
	 */
	public static function current_for_order( $order ) {
		$valid = self::valid_for_order( $order );

		return empty( $valid ) ? null : $valid[0];
	}

	/**
	 * Whether any invoice on the order has been cancelled.
	 *
	 * Not "more than one invoice", which is what this asked before Kontor supplied a
	 * status: two invoices on an order are as likely to be two part-deliveries as a
	 * correction, and only one of those is worth telling a customer about.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return bool True when the order holds a cancelled invoice.
	 */
	public static function has_correction( $order ) {
		return ! empty( self::cancelled_for_order( $order ) );
	}

	/**
	 * Find one of an order's invoices by its Kontor document id.
	 *
	 * Reads through for_order(), so an invoice whose file has gone missing is not
	 * found rather than found and then failing to open.
	 *
	 * @param mixed  $order  Value that may be an order.
	 * @param string $wanted Kontor document id.
	 * @return array|null The invoice entry, or null when the order does not hold it.
	 */
	public static function find( $order, $wanted ) {
		$wanted = (string) $wanted;

		if ( '' === $wanted ) {
			return null;
		}

		foreach ( self::for_order( $order ) as $invoice ) {
			if ( (string) $invoice['id'] === $wanted ) {
				return $invoice;
			}
		}

		return null;
	}

	/**
	 * Describe one invoice in a line.
	 *
	 * Lives here rather than beside one of the places that displays an invoice,
	 * because there are now three of them — the order page, the order emails and the
	 * admin order screen — and one wording copied twice is two that drift.
	 *
	 * @param array $invoice Invoice entry from for_order().
	 * @return string Human-readable label.
	 */
	public static function label( array $invoice ) {
		$number = isset( $invoice['number'] ) ? (string) $invoice['number'] : '';
		$date   = isset( $invoice['date'] ) ? (string) $invoice['date'] : '';

		if ( '' === $number ) {
			return __( 'Invoice', 'woo-kontor-sync-pro' );
		}

		if ( '' === $date ) {
			/* translators: %s: invoice number. */
			return sprintf( __( 'Invoice %s', 'woo-kontor-sync-pro' ), $number );
		}

		/*
		 * mysql2date() rather than wp_date(): the date arrives without a time, and
		 * converting a bare midnight into the site timezone would move it to the day
		 * before wherever the offset is negative.
		 */
		$issued = mysql2date( get_option( 'date_format' ), $date . ' 00:00:00' );

		return sprintf(
			/* translators: 1: invoice number, 2: date the invoice was issued. */
			__( 'Invoice %1$s of %2$s', 'woo-kontor-sync-pro' ),
			$number,
			$issued
		);
	}

	/**
	 * Fetch the invoice listing and queue it for downloading.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Invoice sync already running; ignoring the request to start another.' );

			return;
		}

		$ready = Preflight::check( self::JOB, $this->settings );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Invoice sync refused to start: ' . $ready->get_error_message() );

			return;
		}

		$run      = Status::start( self::JOB );
		$response = $this->client->fetch_invoices( (string) $this->settings['shop_id'] );

		if ( is_wp_error( $response ) ) {
			Status::fail( self::JOB, $response->get_error_message() );
			$this->log( 'error', 'Invoice sync aborted: ' . $response->get_error_message() );

			return;
		}

		$groups = $this->group( $this->normalise( $response['data'] ) );

		if ( empty( $groups ) ) {
			Status::finish( self::JOB, __( 'Kontor reported no invoices for this shop.', 'woo-kontor-sync-pro' ) );

			return;
		}

		Status::measure( self::JOB, count( $groups ) );

		/*
		 * Settled before a single chunk is queued: a payload that could not be stored
		 * means every chunk after this finds nothing, and saying so here — once — beats
		 * saying it from inside the first chunk, where the honest reason is gone.
		 */
		if ( ! Payload::put( self::JOB, $groups ) ) {
			Status::fail( self::JOB, __( 'The invoice listing could not be stored for the run to work through.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', 'Invoice sync aborted: the payload could not be stored.' );

			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_INVOICES_CHUNK,
			array(
				'offset' => 0,
				'run'    => $run,
			)
		);
	}

	/**
	 * Gather the listing into one entry per order.
	 *
	 * Every invoice an order has must be settled inside a single action, because a
	 * correction is two rows saying different halves of one thing — the document that
	 * has been cancelled and the document replacing it — and Kontor states them
	 * independently. Chunked row by row those two land in different actions, and the
	 * customer is told twice: once that a new invoice is ready, once that the old one is
	 * void. Grouped, announce() sees the whole order and sends one mail.
	 *
	 * Groups are almost always a single row, so this does not change what a chunk costs.
	 *
	 * @param array $rows Normalised invoice rows.
	 * @return array List of groups, each an "order_number" and its "rows".
	 */
	protected function group( array $rows ) {
		$groups = array();

		foreach ( $rows as $row ) {
			$number = $row['order_number'];

			if ( ! isset( $groups[ $number ] ) ) {
				$groups[ $number ] = array(
					'order_number' => $number,
					'rows'         => array(),
				);
			}

			$groups[ $number ]['rows'][] = $row;
		}

		return array_values( $groups );
	}

	/**
	 * Download one chunk of orders' invoices, then queue the next.
	 *
	 * @param int $offset Number of orders already processed.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function apply_chunk( $offset, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding invoice chunk at offset %d: run %d has been superseded.', $offset, $run ) );

			return;
		}

		$groups = Payload::get( self::JOB );

		if ( null === $groups ) {
			Status::fail( self::JOB, __( 'The stored invoice listing could not be read, so the run was stopped part-way.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', sprintf( 'Invoice sync aborted at offset %d: the stored payload could not be read.', $offset ) );

			return;
		}

		/*
		 * A payload written by the version before this one is a flat list of rows rather
		 * than a list of orders, and WordPress replaces a plugin without running the
		 * deactivation hook — so a run in flight when the files are swapped finds a shape
		 * it cannot work through. It is dropped and said out loud, once, rather than
		 * quietly producing nonsense; the next scheduled run fetches the listing again.
		 */
		if ( ! $this->is_grouped( $groups ) ) {
			Payload::forget( self::JOB );
			Status::fail( self::JOB, __( 'The stored invoice listing was written by an earlier version of the plugin, so the run was stopped part-way. The next run will fetch it again.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', sprintf( 'Invoice sync aborted at offset %d: the stored payload predates the grouped listing.', $offset ) );

			return;
		}

		$chunk = array_slice( $groups, $offset, self::CHUNK_SIZE );

		if ( empty( $chunk ) ) {
			$this->complete();

			return;
		}

		Status::progress( self::JOB, $this->apply( $chunk ) );
		Status::advance( self::JOB, count( $chunk ) );

		$next = $offset + count( $chunk );

		if ( $next >= count( $groups ) ) {
			$this->complete();

			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_INVOICES_CHUNK,
			array(
				'offset' => $next,
				'run'    => $run,
			)
		);
	}

	/**
	 * Whether a stored payload is the grouped listing this version works through.
	 *
	 * The first entry answers for the whole payload: it is written in one place by one
	 * version, so a mixture cannot arise.
	 *
	 * @param array $groups Payload read back from storage.
	 * @return bool True when the payload is a list of order groups.
	 */
	protected function is_grouped( array $groups ) {
		if ( empty( $groups ) ) {
			return true;
		}

		$first = reset( $groups );

		return is_array( $first ) && isset( $first['order_number'], $first['rows'] ) && is_array( $first['rows'] );
	}

	/**
	 * Settle a batch of orders' invoices.
	 *
	 * @param array $chunk Groups as group() builds them.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk ) {
		$counts = array(
			'downloaded' => 0,
			'restated'   => 0,
			'missing'    => 0,
			'unchanged'  => 0,
			'failed'     => 0,
		);

		foreach ( $chunk as $group ) {
			if ( ! is_array( $group ) || empty( $group['rows'] ) || ! is_array( $group['rows'] ) ) {
				continue;
			}

			$this->apply_order( $group, $counts );
		}

		return $counts;
	}

	/**
	 * Settle every invoice Kontor lists for one order, then say what happened.
	 *
	 * Two things happen per row and they are not the same thing. A document the order
	 * does not hold is downloaded and filed. A document it does hold is left exactly
	 * where it is and only its status is followed — that is the whole of what makes the
	 * status usable, because the listing has no incremental filter and every invoice
	 * ever issued comes back on every run, so an invoice downloaded before this version
	 * existed would otherwise never learn whether Kontor has since cancelled it.
	 *
	 * @param array $group  One order's rows, as group() builds them.
	 * @param array $counts Counters to add to, by reference.
	 * @return void
	 */
	protected function apply_order( array $group, array &$counts ) {
		$order = OrderSync::find_by_number( $group['order_number'] );

		if ( ! $order ) {
			$counts['missing'] += count( $group['rows'] );

			return;
		}

		$before    = $this->stored( $order );
		$arrived   = array();
		$cancelled = array();

		foreach ( $group['rows'] as $row ) {
			$held = isset( $before[ $row['id'] ] ) ? $before[ $row['id'] ] : null;

			if ( null !== $held ) {
				if ( ! $this->restate( $order, $row ) ) {
					++$counts['unchanged'];

					continue;
				}

				++$counts['restated'];

				/*
				 * Only a document we knew to be valid can become cancelled. An entry
				 * stored before 0.31.0 has no status at all, so the first run after the
				 * upgrade learns the status of the shop's whole invoice history at once —
				 * recording what was already true, which is not news and must not mail
				 * every customer a correction notice for an invoice cancelled months ago.
				 */
				if ( self::is_cancelled( $row ) && $this->was_known_valid( $held ) ) {
					$cancelled[] = $row['id'];
				}

				continue;
			}

			$stored = $this->download( $row );

			if ( is_wp_error( $stored ) ) {
				++$counts['failed'];
				$this->log(
					'error',
					sprintf( 'Invoice %s for order %s could not be stored: %s', $row['number'], $row['order_number'], $stored->get_error_message() )
				);

				continue;
			}

			$this->attach( $order, $row, $stored );

			++$counts['downloaded'];

			if ( ! self::is_cancelled( $row ) ) {
				$arrived[] = $row['id'];
			}
		}

		if ( empty( $arrived ) && empty( $cancelled ) ) {
			return;
		}

		$this->announce( $order, $before, $arrived, $cancelled );
	}

	/**
	 * The invoices an order holds, keyed on their document id.
	 *
	 * Read straight from the meta rather than through for_order(), which drops entries
	 * whose file has gone from disk. A recorded invoice whose file has been deleted is
	 * still held: re-downloading it would be the obvious alternative, but it would also
	 * mean a shop that deliberately purged old invoices silently got them all back on
	 * the next run.
	 *
	 * @param WC_Order $order Order to read.
	 * @return array Map of document id to the stored entry.
	 */
	protected function stored( $order ) {
		$invoices = $order->get_meta( self::META_INVOICES );

		if ( ! is_array( $invoices ) ) {
			return array();
		}

		$held = array();

		foreach ( $invoices as $invoice ) {
			if ( is_array( $invoice ) && isset( $invoice['id'] ) ) {
				$held[ (string) $invoice['id'] ] = $invoice;
			}
		}

		return $held;
	}

	/**
	 * Whether a stored entry recorded an invoice Kontor called valid.
	 *
	 * Deliberately not "is not cancelled". An entry with no status was written before
	 * Kontor supplied one and says nothing either way, and treating silence as a
	 * statement is what would turn the backfill into a mailing.
	 *
	 * @param array $invoice Stored invoice entry.
	 * @return bool True when the entry carried a status and it was not a cancellation.
	 */
	protected function was_known_valid( array $invoice ) {
		$status = isset( $invoice['status'] ) && is_scalar( $invoice['status'] ) ? trim( (string) $invoice['status'] ) : '';

		return '' !== $status && ! self::is_cancelled( $invoice );
	}

	/**
	 * Download one invoice and write it to the private invoice directory.
	 *
	 * @param array $row Normalised invoice row.
	 * @return string|WP_Error Path relative to the uploads directory, or WP_Error.
	 */
	protected function download( array $row ) {
		$response = $this->client->fetch_document( $row['id'] );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		/*
		 * Strict decoding, because the alternative discards anything it does not
		 * recognise and hands back a shorter file that still looks like a success.
		 * Storage::put() then checks the result really is a PDF.
		 */
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The API returns the PDF as base64; this is the only way to read it.
		$contents = base64_decode( (string) $response['data'], true );

		if ( false === $contents || '' === $contents ) {
			return new WP_Error(
				'wksync_invoice_not_base64',
				__( 'Kontor returned a document that could not be decoded.', 'woo-kontor-sync-pro' )
			);
		}

		return Storage::put( $contents, $row['number'] );
	}

	/**
	 * Record a downloaded invoice on its order.
	 *
	 * @param WC_Order $order Order to update.
	 * @param array    $row   Normalised invoice row.
	 * @param string   $file  Path relative to the uploads directory.
	 * @return void
	 */
	protected function attach( $order, array $row, $file ) {
		$invoices = $order->get_meta( self::META_INVOICES );

		if ( ! is_array( $invoices ) ) {
			$invoices = array();
		}

		$invoices[] = array(
			'id'     => $row['id'],
			'number' => $row['number'],
			'date'   => $row['date'],
			'status' => $row['status'],
			'file'   => $file,
		);

		$order->update_meta_data( self::META_INVOICES, array_values( $invoices ) );

		$order->add_order_note(
			sprintf(
				/* translators: %s: invoice number as issued by Kontor. */
				__( 'Invoice %s downloaded from Kontor.', 'woo-kontor-sync-pro' ),
				$row['number']
			)
		);

		$order->save();
	}

	/**
	 * Follow the status Kontor now reports for an invoice the order already holds.
	 *
	 * Nothing else about a held invoice is ever rewritten — the number, the date and the
	 * file it was downloaded to are what they were — because an invoice is a financial
	 * record and this is not the place to edit one. Only the verdict moves.
	 *
	 * @param WC_Order $order Order holding the invoice.
	 * @param array    $row   Normalised invoice row.
	 * @return bool True when the stored status changed.
	 */
	protected function restate( $order, array $row ) {
		$invoices = $order->get_meta( self::META_INVOICES );

		if ( ! is_array( $invoices ) ) {
			return false;
		}

		$changed = false;

		foreach ( $invoices as $index => $invoice ) {
			if ( ! is_array( $invoice ) || ! isset( $invoice['id'] ) || (string) $invoice['id'] !== (string) $row['id'] ) {
				continue;
			}

			$held = isset( $invoice['status'] ) ? (string) $invoice['status'] : '';

			if ( $held === $row['status'] ) {
				continue;
			}

			$invoices[ $index ]['status'] = $row['status'];
			$changed                      = true;

			if ( self::is_cancelled( $row ) && ! self::is_cancelled( $invoice ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: invoice number as issued by Kontor. */
						__( 'Invoice %s has been cancelled in Kontor.', 'woo-kontor-sync-pro' ),
						$row['number']
					)
				);
			}
		}

		if ( ! $changed ) {
			return false;
		}

		$order->update_meta_data( self::META_INVOICES, array_values( $invoices ) );
		$order->save();

		return true;
	}

	/**
	 * Tell the customer the one thing this run has to say about their invoices.
	 *
	 * Decided once for the whole order, against everything it holds after the run
	 * rather than against the row that happened to be handled last. Kontor corrects an
	 * invoice by cancelling one document and issuing another, and states the two
	 * independently, so the arrival on its own is not enough to tell a correction from a
	 * partial delivery being billed for the rest.
	 *
	 * Four outcomes:
	 *
	 * - An invoice we knew to be valid has been cancelled and a valid one remains: a
	 *   correction, whether the replacement arrived in this run or an earlier one.
	 * - A valid invoice arrived at an order that had none and holds a cancelled one:
	 *   also a correction. This is the cancellation whose replacement Kontor issued
	 *   later, which went unannounced at the time because there was nothing to point the
	 *   customer at.
	 * - A valid invoice arrived at an order that already had one: a new document to pay,
	 *   not a correction of anything. This is the partial delivery.
	 * - No valid invoice left at all: silence. There is no version of telling somebody
	 *   their invoice is void that leaves them better off when there is nothing to send
	 *   them instead; the run that downloads the replacement says it.
	 *
	 * @param WC_Order $order     Order the invoices belong to.
	 * @param array    $before    Invoices the order held before this run, keyed on id.
	 * @param array    $arrived   Ids of valid invoices downloaded in this run.
	 * @param array    $cancelled Ids of invoices that became cancelled in this run.
	 * @return void
	 */
	protected function announce( $order, array $before, array $arrived, array $cancelled ) {
		$valid = self::valid_for_order( $order );

		if ( empty( $valid ) ) {
			return;
		}

		$document_id = (string) $valid[0]['id'];
		$replacement = ! empty( $arrived ) && ! $this->held_valid( $before ) && self::has_correction( $order );

		if ( ! empty( $cancelled ) || $replacement ) {
			/**
			 * Fires when an invoice the order held has been cancelled and replaced.
			 *
			 * Fired instead of, never as well as, woo_kontor_sync_invoice_downloaded —
			 * the two are different messages to the same customer and sending both would
			 * be worse than sending neither.
			 *
			 * Registered as a WooCommerce transactional email action, so the mailer is
			 * instantiated before anything listens for it. Scalars only, for the reason
			 * given on the hook below.
			 *
			 * @since 0.30.0
			 *
			 * @param int    $order_id    Order the invoice belongs to.
			 * @param string $document_id Kontor document id of the invoice that now counts.
			 */
			do_action( 'woo_kontor_sync_invoice_corrected', (int) $order->get_id(), $document_id );

			return;
		}

		if ( empty( $arrived ) ) {
			return;
		}

		/**
		 * Fires when an invoice the order did not hold has been downloaded and filed.
		 *
		 * Registered as a WooCommerce transactional email action, so the mailer is
		 * instantiated before anything listens for it. Scalars only: WooCommerce may
		 * defer a transactional email and replay it from a queue, where an order object
		 * would cross the gap stale.
		 *
		 * @since 0.20.0
		 *
		 * @param int    $order_id    Order the invoice belongs to.
		 * @param string $document_id Kontor document id.
		 */
		do_action( 'woo_kontor_sync_invoice_downloaded', (int) $order->get_id(), $document_id );
	}

	/**
	 * Whether an order held a valid invoice before this run touched it.
	 *
	 * @param array $before Stored invoices keyed on their document id.
	 * @return bool True when at least one of them was not cancelled.
	 */
	protected function held_valid( array $before ) {
		foreach ( $before as $invoice ) {
			if ( is_array( $invoice ) && ! self::is_cancelled( $invoice ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce the API rows to the fields this job uses.
	 *
	 * A row without a document id or an order number is dropped: the first cannot be
	 * downloaded and the second cannot be filed anywhere. A row without an
	 * invoice_status is kept and reads as valid; see is_cancelled().
	 *
	 * @param array $rows Rows from the invoices entity.
	 * @return array Normalised rows.
	 */
	protected function normalise( array $rows ) {
		$normalised = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$id     = $this->text( $row, 'id' );
			$number = $this->text( $row, 'ordernumber' );

			if ( '' === $id || '' === $number ) {
				continue;
			}

			$normalised[] = array(
				'id'           => $id,
				'number'       => $this->text( $row, 'Belegnr' ),
				'date'         => $this->date( $this->text( $row, 'Datum' ) ),
				'status'       => $this->text( $row, 'invoice_status' ),
				'order_number' => $number,
			);
		}

		return $normalised;
	}

	/**
	 * Reduce an API timestamp to a date.
	 *
	 * Kontor sends midnight local time on an invoice, so the time carries no
	 * information and only invites a timezone conversion that moves the date.
	 *
	 * @param string $raw Value from the Datum field.
	 * @return string Date as YYYY-MM-DD, or an empty string when unparseable.
	 */
	protected function date( $raw ) {
		return 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})/', $raw, $matches ) ? $matches[1] : '';
	}

	/**
	 * Read a field from a row, treating null as an empty string.
	 *
	 * @param array  $row Invoice row.
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
	 * Close out a run and drop the listing it was working through.
	 *
	 * It takes no run identifier: the payload is keyed on the job, and whichever chunk
	 * gets here has already checked that this run is the current one.
	 *
	 * @return void
	 */
	protected function complete() {
		Payload::forget( self::JOB );

		$counts   = Status::get( self::JOB )['counts'];
		$restated = isset( $counts['restated'] ) ? (int) $counts['restated'] : 0;

		$summary = sprintf(
			/* translators: 1: invoices downloaded, 2: invoices that failed, 3: invoice rows with no matching order. */
			__( '%1$d invoices downloaded, %2$d failed, %3$d not found locally.', 'woo-kontor-sync-pro' ),
			isset( $counts['downloaded'] ) ? (int) $counts['downloaded'] : 0,
			isset( $counts['failed'] ) ? (int) $counts['failed'] : 0,
			isset( $counts['missing'] ) ? (int) $counts['missing'] : 0
		);

		/*
		 * Only mentioned when it happened, so a shop whose invoices are all settled reads
		 * the sentence it has always read. The first run after 0.31.0 is the loud one: it
		 * learns Kontor's verdict on the shop's whole invoice history at once.
		 */
		if ( $restated > 0 ) {
			$summary .= ' ' . sprintf(
				/* translators: %d: invoices whose status changed in Kontor. */
				_n(
					'%d invoice changed status in Kontor.',
					'%d invoices changed status in Kontor.',
					$restated,
					'woo-kontor-sync-pro'
				),
				$restated
			);
		}

		Status::finish( self::JOB, $summary );
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
