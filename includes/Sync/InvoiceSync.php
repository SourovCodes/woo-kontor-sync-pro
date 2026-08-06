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
	 * Prefix for the transient holding a run's payload.
	 */
	const TRANSIENT_PREFIX = 'wksync_invoice_run_';

	/**
	 * How long a run's cached payload stays available.
	 */
	const TRANSIENT_TTL = 6 * HOUR_IN_SECONDS;

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
		set_transient( self::TRANSIENT_PREFIX . $run, $rows, self::TRANSIENT_TTL );

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

		$rows = get_transient( self::TRANSIENT_PREFIX . $run );

		if ( ! is_array( $rows ) ) {
			Status::fail( self::JOB, __( 'The cached invoice listing expired before it could be applied.', 'woo-kontor-sync-pro' ) );

			return;
		}

		$chunk = array_slice( $rows, $offset, self::CHUNK_SIZE );

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
	 * Close out a run and drop its cached listing.
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
