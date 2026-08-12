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
 * An article the stock feed does not carry is left exactly as it is: it keeps its
 * last known level and stays published. This sync used to draft it, which took a
 * fifth of the catalogue out of the shop on its first run — the feed is narrower
 * than the catalogue, 2945 articles against 4386 on the account this was built
 * against, so absence from it is a routine gap rather than a verdict. Whether
 * Kontor still sells an article is the catalogue's answer to give, and
 * ProductSync::finalise() is the one pass that acts on it.
 *
 * A shop that wants the old behaviour back can have it, with
 * Settings::DRAFT_MISSING_STOCK. It is off by default, so the paragraph above is
 * what a shop does unless somebody decides otherwise: an account where the two
 * feeds agree, or one whose ERP is set up so that no stock record really does mean
 * no longer sellable, can turn it on and get the drafting pass back.
 */
class StockSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'stock';

	/**
	 * Meta holding the run that last saw this article in the stock feed.
	 *
	 * Only written while the drafting is switched on, because only the drafting pass
	 * reads it: stamping every product on a feed of some three thousand articles,
	 * every fifteen minutes, is not a cost to carry for a pass that is not going to
	 * run. Nothing is lost by that — apply() stamps the whole feed before finalise()
	 * looks at anything, so the pass sees a complete set of stamps on the very first
	 * run after the setting is turned on.
	 */
	const META_STOCK_AT = '_wksync_stock_at';

	/**
	 * Marks a product that the drafting pass drafted, rather than a person.
	 *
	 * Deliberately not ProductSync::META_LEGACY_STOCK_DRAFTED, which is the key this
	 * sync used before 0.13.0 and now means the opposite: that key is a draft nothing
	 * will ever clear again, so the product sync treats finding it as reason enough to
	 * publish. Writing it here would have every product this pass drafts republished
	 * by the next product sync.
	 */
	const META_STOCK_DRAFTED = '_wksync_stock_drafted';

	/**
	 * How many stock levels to apply per action.
	 */
	const CHUNK_SIZE = 250;

	/**
	 * How many products to draft or release per pass.
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
			$this->finish_run( $run );

			return;
		}

		$counts = $this->apply( $chunk, $run );
		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, count( $chunk ) );

		$next = $offset + count( $chunk );

		if ( $next >= count( $levels ) ) {
			$this->finish_run( $run );

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
	 * Only the level is written, unless the drafting is switched on. A product's
	 * status is otherwise never touched here: this sync does not decide whether an
	 * article belongs in the shop, so a product a person or the product sync drafted
	 * keeps its level updated behind the scenes and stays drafted.
	 *
	 * With the drafting on, two more things happen: the run is stamped on every
	 * product the feed carries, which is what finalise() judges staleness by, and a
	 * product an earlier pass drafted is published again now that a level for it has
	 * arrived.
	 *
	 * @param array $chunk Map of SKU to quantity.
	 * @param int   $run   Run identifier, stamped on every product touched. Zero
	 *                     means there is no run to stamp, which is what a caller
	 *                     outside the chain passes.
	 * @return array Counters for this batch.
	 */
	public function apply( array $chunk, $run = 0 ) {
		$counts     = array(
			'updated'   => 0,
			'missing'   => 0,
			'unmanaged' => 0,
		);
		$drafting   = $this->drafts_missing_articles();
		$by_product = $this->resolve_skus( array_keys( $chunk ) );

		if ( $drafting ) {
			$counts['restored'] = 0;
		}

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
				if ( ! $product->get_meta( ProductSync::META_SYNCED_AT ) ) {
					++$counts['unmanaged'];
					continue;
				}

				$product->set_manage_stock( true );
			}

			if ( $drafting ) {
				if ( $this->restore_if_stock_drafted( $product ) ) {
					++$counts['restored'];
				}

				// The stamp is what tells finalise() this article is still in the feed.
				if ( $run > 0 ) {
					$product->update_meta_data( self::META_STOCK_AT, $run );
				}
			}

			$product->set_stock_quantity( $quantity );
			$product->set_stock_status( $quantity > 0 ? 'instock' : 'outofstock' );
			$product->save();

			++$counts['updated'];
		}

		return $counts;
	}

	/**
	 * Whether this shop drafts the articles its stock feed leaves out.
	 *
	 * Off unless the setting says otherwise, including when the key is absent — which
	 * is every shop that has not been to the settings screen since this arrived, and
	 * every settings array built by hand. Absence has to read as off: 0.13.0 removed
	 * the drafting outright, and a shop that updated into that behaviour must not have
	 * it turned back on for it by an upgrade.
	 *
	 * @return bool True when the drafting pass should run.
	 */
	protected function drafts_missing_articles() {
		return ! empty( $this->settings[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * Republish a product an earlier drafting pass drafted.
	 *
	 * An article can leave the stock feed and come back. Without this the pass drafts
	 * it once and it stays hidden for good, even though Kontor is reporting a level
	 * for it again.
	 *
	 * Only drafts this pass created are restored — the marker is what distinguishes
	 * them from a product a shop manager deliberately unpublished, and from one the
	 * product sync drafted for its own reason. A product missing from both feeds
	 * carries a marker from each and stays drafted until both have seen the article
	 * again, which is what clearing only our own achieves.
	 *
	 * A product carrying the marker that is no longer a draft was republished by hand
	 * in between. The marker is stale — it describes a draft that no longer exists, and
	 * the product sync reads it as this feed still having nothing for the article — so
	 * it is dropped rather than left to hold the product back later.
	 *
	 * @param \WC_Product $product Product being updated.
	 * @return bool True when the product was republished.
	 */
	protected function restore_if_stock_drafted( $product ) {
		if ( ! $product->get_meta( self::META_STOCK_DRAFTED ) ) {
			return false;
		}

		$product->delete_meta_data( self::META_STOCK_DRAFTED );

		if ( 'draft' !== $product->get_status() ) {
			return false;
		}

		$blocker = '';

		if ( $product->get_meta( ProductSync::META_SYNC_DRAFTED ) ) {
			$blocker = 'Kontor still does not list it in the catalogue';
		} elseif ( $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) ) {
			$blocker = 'Kontor lists no image for it';
		}

		if ( '' !== $blocker ) {
			$this->log(
				'info',
				sprintf( 'Article %1$s has a stock level again, but %2$s; leaving it drafted.', $product->get_sku(), $blocker )
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
	 * End a run: queue a finalising pass if one is needed, or close it now.
	 *
	 * With the drafting off and nothing left over from a time when it was on, there is
	 * nothing to walk and the run is over — which is 0.13.0's behaviour, and what
	 * almost every run does. The check that decides is one indexed lookup for a single
	 * product, not a walk of the catalogue.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	protected function finish_run( $run ) {
		if ( $this->drafts_missing_articles() || $this->has_stock_drafts() ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_STOCK_DRAFT, array( 'run' => $run ) );

			return;
		}

		$this->complete( $run );
	}

	/**
	 * Whether any product is still drafted by a drafting pass.
	 *
	 * Asked only when the drafting is off, to find out whether there is anything to
	 * give back. Cheap on purpose: one row, by meta key, and on the overwhelmingly
	 * common path there is none.
	 *
	 * @return bool True when at least one product carries the marker.
	 */
	protected function has_stock_drafts() {
		$held = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'draft',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in a background job; the marker is the only record of which drafts are ours.
				'meta_query'       => array(
					array(
						'key'     => self::META_STOCK_DRAFTED,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return ! empty( $held );
	}

	/**
	 * Draft what the feed left out, or give back what an earlier pass drafted.
	 *
	 * Which of the two it does is the setting's to decide, and it is asked here rather
	 * than when the action was queued: turning the drafting off has to release the
	 * products it drafted, and nothing else can. Those products are absent from the
	 * stock feed by definition, so apply() never reaches them and
	 * restore_if_stock_drafted() is never called for them — without this they would
	 * stay hidden for good.
	 *
	 * Both halves are batched and chained rather than run to completion in one action.
	 * A first pass on the account this was built against has some 1400 products to get
	 * through, which is exactly the sort of walk a slow host cuts short.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding stock finalise pass: run %d has been superseded.', $run ) );

			return;
		}

		$more = $this->drafts_missing_articles()
			? $this->draft_batch( $run )
			: $this->release_batch();

		if ( $more ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_STOCK_DRAFT, array( 'run' => $run ) );

			return;
		}

		$this->complete( $run );
	}

	/**
	 * Draft one batch of the products this run's feed did not carry.
	 *
	 * Staleness is decided by the run stamp rather than by holding every SKU in
	 * memory: anything ours the current run did not stamp was not in the feed. A
	 * product with no stamp at all has never been in one, which is the same answer —
	 * and is the branch that makes the first pass after the setting is turned on work
	 * at all.
	 *
	 * Only products carrying ProductSync::META_SYNCED_AT are considered. It is the
	 * marker for "this plugin imported this product", so a shop manager's own product
	 * is never drafted for being absent from a feed it was never part of.
	 *
	 * @param int $run Run identifier.
	 * @return bool True when there may be more to do.
	 */
	protected function draft_batch( $run ) {
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

		$drafted = 0;

		foreach ( $stale as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$product->set_status( 'draft' );

			// Marked so a later run can tell this draft from a deliberate one, and from
			// one the product sync made, and republish it if the article returns.
			$product->update_meta_data( self::META_STOCK_DRAFTED, 1 );
			$product->save();

			++$drafted;
		}

		Status::progress( self::JOB, array( 'drafted' => $drafted ) );

		/*
		 * A full batch means there may be more. Nothing drafted means the whole batch
		 * was products that could not be loaded, which the next pass would find again —
		 * so stop rather than chain forever.
		 */
		return count( $stale ) === self::FINALISE_BATCH && $drafted > 0;
	}

	/**
	 * Give back one batch of the products an earlier drafting pass drafted.
	 *
	 * Runs when the drafting has been turned off. The marker goes whatever happens,
	 * because the reason this sync drafted the product has gone; the product itself
	 * only comes back when that was the last reason it was hidden for.
	 *
	 * @return bool True when there may be more to do.
	 */
	protected function release_batch() {
		$held = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'draft',
				'posts_per_page'   => self::FINALISE_BATCH,
				'fields'           => 'ids',
				'suppress_filters' => false,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in a background job; the marker is the only record of which drafts are ours.
				'meta_query'       => array(
					array(
						'key'     => self::META_STOCK_DRAFTED,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$cleared  = 0;
		$restored = 0;

		foreach ( $held as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$product->delete_meta_data( self::META_STOCK_DRAFTED );

			$blocked = $product->get_meta( ProductSync::META_SYNC_DRAFTED )
				|| $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED );

			if ( $blocked ) {
				$this->log(
					'info',
					sprintf( 'Article %s is no longer drafted for want of a stock record, but the product sync is still holding it back.', $product->get_sku() )
				);
			} else {
				$product->set_status( 'publish' );
				++$restored;

				$this->log( 'info', sprintf( 'Republished article %s: this shop no longer drafts articles the stock feed leaves out.', $product->get_sku() ) );
			}

			// Saved either way: the marker has gone, and it is the marker that keeps
			// this query returning the product.
			$product->save();
			++$cleared;
		}

		Status::progress( self::JOB, array( 'restored' => $restored ) );

		return count( $held ) === self::FINALISE_BATCH && $cleared > 0;
	}

	/**
	 * Close a run left mid-chain by the removal of the finalising pass.
	 *
	 * The pass is gone, but an action queuing it can still be sitting in the queue
	 * when the upgrade lands — WordPress deactivates a plugin silently before
	 * replacing it, so nothing sweeps the queue on the way past. All this does is what
	 * the pass did last: close the run behind it. Nothing is drafted.
	 *
	 * A superseded run is left alone, exactly as the pass left it alone: a newer run
	 * owns the status by then, and finishing it here would report the wrong one as
	 * complete.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function close_legacy_run( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding a stock finalise action from run %d: the run has been superseded.', $run ) );

			return;
		}

		$this->log( 'info', sprintf( 'Closing stock run %d: its finalising action was queued before the pass was removed.', $run ) );

		$this->complete( $run );
	}

	/**
	 * Close out a run and drop its cached payload.
	 *
	 * Called straight from the last chunk unless a finalising pass was needed, in
	 * which case that pass calls it instead.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	protected function complete( $run ) {
		delete_transient( self::TRANSIENT_PREFIX . $run );

		$counts = Status::get( self::JOB )['counts'];

		$message = sprintf(
			/* translators: 1: products updated, 2: article numbers with no matching product, 3: products left alone because they do not manage stock. */
			__( '%1$d products updated, %2$d article numbers had no matching SKU, %3$d skipped as not stock-managed.', 'woo-kontor-sync-pro' ),
			isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
			isset( $counts['missing'] ) ? (int) $counts['missing'] : 0,
			isset( $counts['unmanaged'] ) ? (int) $counts['unmanaged'] : 0
		);

		$drafted  = isset( $counts['drafted'] ) ? (int) $counts['drafted'] : 0;
		$restored = isset( $counts['restored'] ) ? (int) $counts['restored'] : 0;

		/*
		 * Only when there is something to report. A shop that leaves the drafting off
		 * never sees this sentence, and its summary reads exactly as it did before the
		 * setting existed.
		 */
		if ( $drafted > 0 || $restored > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: products drafted for having no stock record, 2: products published again. */
				__( '%1$d drafted for having no stock record, %2$d republished.', 'woo-kontor-sync-pro' ),
				$drafted,
				$restored
			);
		}

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
