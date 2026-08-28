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
 * invoice is a financial record, and a second one arriving is a new document, not a
 * correction of the last.
 */
class InvoiceSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'invoices';

	/**
	 * How many rows to process per action.
	 *
	 * Much smaller than the delivery sync's chunk because each new row costs an HTTP
	 * round trip and a file write rather than a meta update.
	 */
	const CHUNK_SIZE = 10;

	/**
	 * Meta holding every invoice downloaded for an order.
	 *
	 * A list of arrays with "id", "number", "date" and "file" keys, oldest first.
	 */
	const META_INVOICES = '_wksync_invoices';

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
	 * @return array List of invoices, each with "id", "number", "date" and "file".
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
	 * Split an order's invoices into the one that counts and the ones it replaced.
	 *
	 * Kontor says nothing whatever about supersession. The invoice row carries "id",
	 * "Belegname", "Belegnr", "Datum", "Auftrnr" and "ordernumber" and no status of any
	 * kind; every row on the account this was built against reads "Rechnung", there is
	 * no cancellation entity behind /search — storno, gutschrift, creditnotes, belege
	 * and documents all answer ERR-500 — and the cancelled document's own PDF does not
	 * contain the word. So the only signal available is that an order has more than one
	 * invoice, and the rule is that the highest Belegnr is the live one.
	 *
	 * **That is an inference, and it is worth being honest about which way it can be
	 * wrong.** A second invoice for one order could in principle be a genuine second
	 * document — a part-delivery billed separately — in which case this labels a valid
	 * invoice as cancelled, which is a confident false statement about a financial
	 * record rather than a missing one. Measured against the live account before it was
	 * written: 8 orders carried two invoices, and in every pair the two documents billed
	 * the *identical* line items, quantities and unit prices, differing only in the
	 * totals and the VAT rate — corrections, not part-deliveries. `partially_completed`
	 * is meanwhile the commonest order status there (15 of 35) and those orders carry
	 * one invoice each, so Kontor does not issue an invoice per part-delivery on this
	 * account. Should that ever change, this is the assumption to revisit first.
	 *
	 * Ordered on Belegnr rather than on the order the entries were stored in, because
	 * storage follows the feed and the feed comes back newest first — so on a first
	 * import the replacement is downloaded and appended *before* the document it
	 * replaced. Belegnr is Kontor's own issue sequence and is the only field that
	 * survives that. It is compared numerically where both sides are numeric, and the
	 * date is the tiebreak.
	 *
	 * @param array $invoices Invoices as for_order() returns them.
	 * @return array List of invoices, each with a "current" boolean added, newest first.
	 */
	public static function classify( array $invoices ) {
		if ( empty( $invoices ) ) {
			return array();
		}

		usort( $invoices, array( __CLASS__, 'compare_issue' ) );

		$classified = array();

		foreach ( $invoices as $position => $invoice ) {
			$invoice['current'] = 0 === $position;
			$classified[]       = $invoice;
		}

		return $classified;
	}

	/**
	 * Order two invoices with the most recently issued first.
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
	 * The invoice that is currently valid for an order.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array|null The live invoice, or null when the order holds none.
	 */
	public static function current_for_order( $order ) {
		$invoices = self::classify( self::for_order( $order ) );

		return empty( $invoices ) ? null : $invoices[0];
	}

	/**
	 * The invoices an order once had and no longer counts.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return array List of superseded invoices, most recently issued first.
	 */
	public static function superseded_for_order( $order ) {
		$invoices = self::classify( self::for_order( $order ) );

		return array_values( array_slice( $invoices, 1 ) );
	}

	/**
	 * Whether an order's invoice has been replaced by a corrected one.
	 *
	 * @param mixed $order Value that may be an order.
	 * @return bool True when more than one invoice is held.
	 */
	public static function has_correction( $order ) {
		return count( self::for_order( $order ) ) > 1;
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

		$rows = $this->normalise( $response['data'] );

		if ( empty( $rows ) ) {
			Status::finish( self::JOB, __( 'Kontor reported no invoices for this shop.', 'woo-kontor-sync-pro' ) );

			return;
		}

		Status::measure( self::JOB, count( $rows ) );

		/*
		 * Settled before a single chunk is queued: a payload that could not be stored
		 * means every chunk after this finds nothing, and saying so here — once — beats
		 * saying it from inside the first chunk, where the honest reason is gone.
		 */
		if ( ! Payload::put( self::JOB, $rows ) ) {
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
	 * Download one chunk of invoices, then queue the next.
	 *
	 * @param int $offset Number of rows already processed.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function apply_chunk( $offset, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding invoice chunk at offset %d: run %d has been superseded.', $offset, $run ) );

			return;
		}

		$rows = Payload::get( self::JOB );

		if ( null === $rows ) {
			Status::fail( self::JOB, __( 'The stored invoice listing could not be read, so the run was stopped part-way.', 'woo-kontor-sync-pro' ) );
			$this->log( 'error', sprintf( 'Invoice sync aborted at offset %d: the stored payload could not be read.', $offset ) );

			return;
		}

		$chunk = array_slice( $rows, $offset, self::CHUNK_SIZE );

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
			Scheduler::ACTION_SYNC_INVOICES_CHUNK,
			array(
				'offset' => $next,
				'run'    => $run,
			)
		);
	}

	/**
	 * Download a batch of invoices onto their orders.
	 *
	 * @param array $chunk Normalised invoice rows.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk ) {
		$counts = array(
			'downloaded' => 0,
			'missing'    => 0,
			'unchanged'  => 0,
			'failed'     => 0,
		);

		foreach ( $chunk as $row ) {
			$order = OrderSync::find_by_number( $row['order_number'] );

			if ( ! $order ) {
				++$counts['missing'];

				continue;
			}

			// Already downloaded on an earlier run, which is the normal case: the
			// listing has no incremental filter and returns the whole history each time.
			if ( $this->has_invoice( $order, $row['id'] ) ) {
				++$counts['unchanged'];

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
		}

		return $counts;
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

		$this->announce( $order, (string) $row['id'] );
	}

	/**
	 * Tell the shop what kind of invoice has just arrived.
	 *
	 * Three outcomes, and which one fires is decided by classify() *after* the save,
	 * against everything the order now holds — never by whether the order had an
	 * invoice a moment ago. The listing comes back newest first, so on a first import
	 * a correction is downloaded and stored before the document it replaces, and
	 * "did this order already have one" would call the older arrival the correction.
	 *
	 * A document that arrives already superseded announces nothing at all. It is a
	 * cancelled invoice, and there is no version of telling a customer about one that
	 * leaves them better off.
	 *
	 * @param WC_Order $order       Order the invoice belongs to.
	 * @param string   $document_id Kontor document id that was just filed.
	 * @return void
	 */
	protected function announce( $order, $document_id ) {
		$invoices = self::classify( self::for_order( $order ) );

		if ( empty( $invoices ) || (string) $invoices[0]['id'] !== $document_id ) {
			return;
		}

		if ( count( $invoices ) > 1 ) {
			/**
			 * Fires when a downloaded invoice replaces one the order already held.
			 *
			 * Kontor states no such thing; see classify() for what this is inferred
			 * from and how it can be wrong. Fired instead of, never as well as,
			 * woo_kontor_sync_invoice_downloaded — the two are different messages to
			 * the same customer and sending both would be worse than sending neither.
			 *
			 * Registered as a WooCommerce transactional email action, so the mailer is
			 * instantiated before anything listens for it. Scalars only, for the reason
			 * given on the hook below.
			 *
			 * @since 0.30.0
			 *
			 * @param int    $order_id    Order the invoice belongs to.
			 * @param string $document_id Kontor document id of the corrected invoice.
			 */
			do_action( 'woo_kontor_sync_invoice_corrected', (int) $order->get_id(), $document_id );

			return;
		}

		/**
		 * Fires when an invoice the order did not hold has been downloaded and filed.
		 *
		 * After the save, deliberately. The stored entry is what makes this fire once:
		 * apply() checks the document id before it downloads anything, so a listener
		 * that throws before the invoice is recorded would have it downloaded and
		 * announced all over again on the next run.
		 *
		 * Registered as a WooCommerce transactional email action, so the mailer is
		 * instantiated before anything listens for it. Scalars only: WooCommerce is
		 * free to defer a transactional email and replay it from a queue, and an order
		 * object carried across that gap would be a stale copy.
		 *
		 * Since 0.30.0 this is the *first* invoice's hook rather than every invoice's:
		 * a replacement fires woo_kontor_sync_invoice_corrected and an already-
		 * superseded arrival fires nothing.
		 *
		 * @since 0.20.0
		 *
		 * @param int    $order_id    Order the invoice belongs to.
		 * @param string $document_id Kontor document id.
		 */
		do_action( 'woo_kontor_sync_invoice_downloaded', (int) $order->get_id(), $document_id );
	}

	/**
	 * Whether an order already holds a given document.
	 *
	 * Matched on the document id rather than the invoice number: the id is what
	 * getdocument is called with, so it is the value that says "this exact file has
	 * been fetched".
	 *
	 * @param WC_Order $order       Order to check.
	 * @param string   $document_id Kontor document GUID.
	 * @return bool True when the document has already been downloaded.
	 */
	protected function has_invoice( $order, $document_id ) {
		$invoices = $order->get_meta( self::META_INVOICES );

		if ( ! is_array( $invoices ) ) {
			return false;
		}

		foreach ( $invoices as $invoice ) {
			if ( is_array( $invoice ) && isset( $invoice['id'] ) && (string) $invoice['id'] === $document_id ) {
				/*
				 * A recorded invoice whose file has been deleted is treated as still
				 * held. Re-downloading it would be the obvious alternative, but it would
				 * also mean a shop that deliberately purged old invoices silently got
				 * them all back on the next run.
				 */
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduce the API rows to the fields this job uses.
	 *
	 * A row without a document id or an order number is dropped: the first cannot be
	 * downloaded and the second cannot be filed anywhere.
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

		$counts = Status::get( self::JOB )['counts'];

		Status::finish(
			self::JOB,
			sprintf(
				/* translators: 1: invoices downloaded, 2: invoices that failed, 3: invoice rows with no matching order. */
				__( '%1$d invoices downloaded, %2$d failed, %3$d not found locally.', 'woo-kontor-sync-pro' ),
				isset( $counts['downloaded'] ) ? (int) $counts['downloaded'] : 0,
				isset( $counts['failed'] ) ? (int) $counts['failed'] : 0,
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
