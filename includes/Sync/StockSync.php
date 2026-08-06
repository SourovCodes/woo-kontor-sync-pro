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
 * Applies Kontor's stock levels to WooCommerce products.
 *
 * Products are matched on SKU against Kontor's Artnr, which is the only key this
 * sync considers. A level whose article number matches no SKU is counted as
 * missing rather than guessed at from any other field.
 *
 * The stock entity is cheap and unpaged: one request returns a level for every
 * article. Applying those levels is the expensive half, so the payload is cached
 * for the run and applied in chunks across chained actions.
 *
 * A product this plugin imported that the stock feed no longer carries is drafted
 * by finalise(), and republished when the article returns — the same treatment the
 * product sync gives an article Kontor drops from the catalogue. The two are
 * tracked separately, because they are separate reasons to hide a product and
 * either one going away must not undo the other.
 */
class StockSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'stock';

	/**
	 * Meta holding the run that last saw this article in the stock feed.
	 *
	 * Written on every product this sync applies a level to, which is what lets
	 * finalise() find the ones the feed did not mention: anything ours carrying an
	 * older stamp, or none at all, is gone from the feed.
	 */
	const META_STOCK_AT = '_wksync_stock_at';

	/**
	 * Marks a product that this sync drafted, rather than a person.
	 *
	 * Deliberately not ProductSync::META_SYNC_DRAFTED. A product can be missing from
	 * the catalogue feed and the stock feed at once, and sharing one marker would let
	 * whichever sync saw the article first publish it again while the other still has
	 * no record of it.
	 */
	const META_STOCK_DRAFTED = '_wksync_drafted_by_stock';

	/**
	 * How many stock levels to apply per action.
	 */
	const CHUNK_SIZE = 250;

	/**
	 * How many stale products to draft per finalising pass.
	 */
	const FINALISE_BATCH = 200;

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

		$ready = Preflight::check( self::JOB, $this->settings, $this->client );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Stock sync refused to start: ' . $ready->get_error_message() );

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

		Status::measure( self::JOB, count( $levels ) );
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
			$this->start_finalise( $run );

			return;
		}

		$counts = $this->apply( $chunk, $run );
		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, count( $chunk ) );

		$next = $offset + count( $chunk );

		if ( $next >= count( $levels ) ) {
			$this->start_finalise( $run );

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
	 * @param int   $run   Run identifier, stamped on every product touched.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk, $run ) {
		$counts     = array(
			'updated'   => 0,
			'missing'   => 0,
			'unmanaged' => 0,
			'restored'  => 0,
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
			 *
			 * Someone else's product is left without the run stamp too, so finalise()
			 * cannot later draft it for being absent from a feed it was never in.
			 */
			if ( ! $product->get_manage_stock() ) {
				if ( ! $product->get_meta( ProductSync::META_SYNCED_AT ) ) {
					++$counts['unmanaged'];
					continue;
				}

				$product->set_manage_stock( true );
			}

			if ( $this->restore_if_stock_drafted( $product ) ) {
				++$counts['restored'];
			}

			$product->set_stock_quantity( $quantity );
			$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );

			// The stamp is what tells finalise() this article is still in the feed.
			$product->update_meta_data( self::META_STOCK_AT, $run );
			$product->save();

			++$counts['updated'];
		}

		return $counts;
	}

	/**
	 * Republish a product that an earlier run of this sync drafted.
	 *
	 * An article can leave the stock feed and come back. Without this, finalise()
	 * drafts it once and it stays hidden forever even though Kontor is reporting a
	 * level for it again.
	 *
	 * Only drafts this sync created are restored: the marker meta is what
	 * distinguishes them from a product a shop manager deliberately unpublished, and
	 * from one the product sync drafted for its own reason. A product missing from
	 * both feeds carries both markers and stays drafted until each sync has seen the
	 * article again — clearing only our own marker is what makes that work.
	 *
	 * @param \WC_Product $product Product being updated.
	 * @return bool True when the product was republished.
	 */
	protected function restore_if_stock_drafted( $product ) {
		if ( 'draft' !== $product->get_status() || ! $product->get_meta( self::META_STOCK_DRAFTED ) ) {
			return false;
		}

		$product->delete_meta_data( self::META_STOCK_DRAFTED );

		if ( $product->get_meta( ProductSync::META_SYNC_DRAFTED ) ) {
			$this->log(
				'info',
				sprintf( 'Article %s has a stock level again, but Kontor still does not list it in the catalogue; leaving it drafted.', $product->get_sku() )
			);

			return false;
		}

		$product->set_status( 'publish' );

		$this->log( 'info', sprintf( 'Republished article %s: Kontor reports a stock level for it again.', $product->get_sku() ) );

		return true;
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
	 * Queue the finalising pass once every level has been applied.
	 *
	 * Drafting is a separate action rather than the tail of the last chunk because it
	 * is its own unbounded walk: a first run against a feed narrower than the
	 * catalogue can have thousands of products to draft, and a chunk action that also
	 * had to get through those would be the one thing a slow host cut short.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	protected function start_finalise( $run ) {
		Scheduler::chain( Scheduler::ACTION_SYNC_STOCK_FINALISE, array( 'run' => $run ) );
	}

	/**
	 * Draft any product the stock feed no longer carries, then close the run.
	 *
	 * Staleness is decided by the run stamp rather than by holding every SKU seen in
	 * memory: anything this plugin imported that the current run did not stamp was not
	 * in the feed. A product with no stamp at all has never appeared in one, which is
	 * the same answer — hence the two branches.
	 *
	 * Only products carrying ProductSync::META_SYNCED_AT are considered. It is the
	 * marker for "this plugin imported this product", so a shop manager's own product
	 * is never drafted for being absent from a feed it was never part of.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding stock finalise pass: run %d has been superseded.', $run ) );

			return;
		}

		$stale = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => self::FINALISE_BATCH,
				'fields'           => 'ids',
				'suppress_filters' => false,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in a background job; there is no other way to find products this run did not stamp.
				'meta_query'       => array(
					'relation' => 'AND',
					array(
						'key'     => ProductSync::META_SYNCED_AT,
						'compare' => 'EXISTS',
					),
					array(
						'relation' => 'OR',
						array(
							'key'     => self::META_STOCK_AT,
							'compare' => 'NOT EXISTS',
						),
						array(
							'key'     => self::META_STOCK_AT,
							'value'   => $run,
							'compare' => '<',
							'type'    => 'NUMERIC',
						),
					),
				),
			)
		);

		foreach ( $stale as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$product->set_status( 'draft' );

			// Marked so a later run can tell this draft apart from a deliberate one,
			// and from one the product sync made, and republish it if the article
			// returns to the stock feed.
			$product->update_meta_data( self::META_STOCK_DRAFTED, 1 );
			$product->save();
		}

		Status::progress( self::JOB, array( 'drafted' => count( $stale ) ) );

		// A full batch means there may be more; come back for another pass.
		if ( count( $stale ) === self::FINALISE_BATCH ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_STOCK_FINALISE, array( 'run' => $run ) );

			return;
		}

		$this->complete( $run );
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

		$message = sprintf(
			/* translators: 1: products updated, 2: article numbers with no matching product, 3: products left alone because they do not manage stock, 4: products drafted, 5: products republished. */
			__( '%1$d products updated, %2$d article numbers had no matching SKU, %3$d skipped as not stock-managed, %4$d drafted, %5$d republished.', 'woo-kontor-sync-pro' ),
			isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
			isset( $counts['missing'] ) ? (int) $counts['missing'] : 0,
			isset( $counts['unmanaged'] ) ? (int) $counts['unmanaged'] : 0,
			isset( $counts['drafted'] ) ? (int) $counts['drafted'] : 0,
			isset( $counts['restored'] ) ? (int) $counts['restored'] : 0
		);

		Status::finish( self::JOB, $message );
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
