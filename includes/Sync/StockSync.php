<?php
/**
 * Stock level import from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Applies Kontor's stock levels to WooCommerce products, matched on SKU.
 *
 * The stock entity is cheap and unpaged: one request returns a level for every
 * article. Applying those levels is the expensive half, so the payload is cached
 * for the run and applied in chunks across chained actions.
 */
class StockSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'stock';

	/**
	 * How many stock levels to apply per action.
	 */
	const CHUNK_SIZE = 250;

	/**
	 * Prefix for the transient holding a run's payload.
	 */
	const TRANSIENT_PREFIX = 'wksync_stock_run_';

	/**
	 * How long a run's cached payload stays available.
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
	 * Fetch every stock level and queue it for application.
	 *
	 * @return void
	 */
	public function start() {
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Stock sync already running; ignoring the request to start another.' );

			return;
		}

		$run      = Status::start( self::JOB );
		$response = $this->client->fetch_stock();

		if ( is_wp_error( $response ) ) {
			Status::fail( self::JOB, $response->get_error_message() );
			$this->log( 'error', 'Stock sync aborted: ' . $response->get_error_message() );

			return;
		}

		$levels = $this->normalise( $response['data'] );

		if ( empty( $levels ) ) {
			Status::finish( self::JOB, __( 'Kontor reported no stock levels.', 'woo-kontor-sync-pro' ) );

			return;
		}

		set_transient( self::TRANSIENT_PREFIX . $run, $levels, self::TRANSIENT_TTL );

		Scheduler::chain(
			Scheduler::ACTION_SYNC_STOCK_CHUNK,
			array(
				'offset' => 0,
				'run'    => $run,
			)
		);
	}

	/**
	 * Apply one chunk of stock levels, then queue the next.
	 *
	 * @param int $offset Number of levels already applied.
	 * @param int $run    Run identifier.
	 * @return void
	 */
	public function apply_chunk( $offset, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding stock chunk at offset %d: run %d has been superseded.', $offset, $run ) );

			return;
		}

		$levels = get_transient( self::TRANSIENT_PREFIX . $run );

		if ( ! is_array( $levels ) ) {
			Status::fail( self::JOB, __( 'The cached stock payload expired before it could be applied.', 'woo-kontor-sync-pro' ) );

			return;
		}

		$chunk = array_slice( $levels, $offset, self::CHUNK_SIZE, true );

		if ( empty( $chunk ) ) {
			$this->complete( $run );

			return;
		}

		$counts = $this->apply( $chunk );
		Status::progress( self::JOB, $counts );

		$next = $offset + count( $chunk );

		if ( $next >= count( $levels ) ) {
			$this->complete( $run );

			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_STOCK_CHUNK,
			array(
				'offset' => $next,
				'run'    => $run,
			)
		);
	}

	/**
	 * Apply a batch of SKU to quantity pairs.
	 *
	 * @param array $chunk Map of SKU to quantity.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk ) {
		$counts     = array(
			'updated'   => 0,
			'missing'   => 0,
			'unmanaged' => 0,
		);
		$by_product = $this->resolve_skus( array_keys( $chunk ) );

		foreach ( $chunk as $sku => $quantity ) {
			if ( ! isset( $by_product[ $sku ] ) ) {
				++$counts['missing'];
				continue;
			}

			$product = wc_get_product( $by_product[ $sku ] );

			if ( ! $product ) {
				++$counts['missing'];
				continue;
			}

			/*
			 * wc_update_product_stock() is a silent no-op on a product that does not
			 * manage stock: the quantity stays null and the status stays "instock",
			 * so it keeps selling however low Kontor says it is. Take stock control
			 * for products this plugin imported, and leave anyone else's alone rather
			 * than changing settings a shop manager chose.
			 */
			if ( ! $product->get_manage_stock() ) {
				if ( ! $product->get_meta( ProductSync::META_KONTOR_ID ) ) {
					++$counts['unmanaged'];
					continue;
				}

				$product->set_manage_stock( true );
			}

			$product->set_stock_quantity( $quantity );
			$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			$product->save();

			++$counts['updated'];
		}

		return $counts;
	}

	/**
	 * Look up product IDs for a batch of SKUs in one query.
	 *
	 * Calling wc_get_product_id_by_sku() per row would be a few thousand queries per
	 * run. WooCommerce maintains a lookup table for exactly this purpose.
	 *
	 * @param string[] $skus SKUs to resolve.
	 * @return array Map of SKU to product ID.
	 */
	protected function resolve_skus( array $skus ) {
		global $wpdb;

		$skus = array_values( array_filter( array_map( 'strval', $skus ), 'strlen' ) );

		if ( empty( $skus ) ) {
			return array();
		}

		$table        = $wpdb->prefix . 'wc_product_meta_lookup';
		$placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );

		/*
		 * The table name and the %s placeholders are both built from code, never from
		 * input, and every SKU goes through prepare(). PHPCS cannot see the
		 * placeholders through the interpolation, hence the suppressions.
		 */
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, sku FROM {$table} WHERE sku IN ({$placeholders})",
				$skus
			)
		);
		// phpcs:enable

		$map = array();

		foreach ( (array) $rows as $row ) {
			$map[ (string) $row->sku ] = (int) $row->product_id;
		}

		return $map;
	}

	/**
	 * Reduce the API rows to a SKU to quantity map.
	 *
	 * @param array $rows Rows from the stock entity.
	 * @return array Map of SKU to integer quantity.
	 */
	protected function normalise( array $rows ) {
		$levels = array();

		foreach ( $rows as $row ) {
			$sku = isset( $row['Artnr'] ) ? trim( (string) $row['Artnr'] ) : '';

			if ( '' === $sku ) {
				continue;
			}

			// Kontor sends quantities as decimals such as 111.000.
			$levels[ $sku ] = (int) round( (float) ( isset( $row['Lagerbestand'] ) ? $row['Lagerbestand'] : 0 ) );
		}

		return $levels;
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
				/* translators: 1: products updated, 2: article numbers with no matching product, 3: products left alone because they do not manage stock. */
				__( '%1$d products updated, %2$d article numbers had no matching SKU, %3$d skipped as not stock-managed.', 'woo-kontor-sync-pro' ),
				isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
				isset( $counts['missing'] ) ? (int) $counts['missing'] : 0,
				isset( $counts['unmanaged'] ) ? (int) $counts['unmanaged'] : 0
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
