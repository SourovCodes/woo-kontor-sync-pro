<?php
/**
 * Product import from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use Exception;
use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WP_Error;
use WpOrg\Requests\Requests;

defined( 'ABSPATH' ) || exit;

/**
 * Imports the Kontor article catalogue into WooCommerce products.
 *
 * The catalogue is several thousand articles, so a run is split across chained
 * Action Scheduler actions: one action per page, then a finalising pass.
 *
 * Kontor is the source of truth, and SKU — Kontor's article number, Artnr — is the
 * only key considered. An article with no Artnr is passed over rather than matched
 * on another field, an Artnr held by more than one product is passed over rather
 * than guessed at, and no second identifier is stored, so there is nothing that
 * could quietly become a competing key.
 */
class ProductSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'products';

	/**
	 * Meta holding a hash of the last payload seen for this article.
	 */
	const META_HASH = '_wksync_sync_hash';

	/**
	 * Meta holding the run that last saw this article.
	 *
	 * Doubles as the marker for "this plugin imported this product": every import
	 * writes it and nothing else does, so its presence is what separates our
	 * products from a shop manager's own.
	 */
	const META_SYNCED_AT = '_wksync_synced_at';

	/**
	 * Meta holding the run in which the catalogue named this product's article.
	 *
	 * Written only to a product the run declined to adopt — one held back for an
	 * article this plugin never imported, one of several sharing an article number,
	 * one whose save failed. Every other product answering to an article in the feed
	 * carries META_SYNCED_AT instead, which says the same thing and more.
	 *
	 * It exists for trash_unmanaged(), which needs to tell "no product of ours" from
	 * "not in Kontor at all" and cannot ask the feed, running as it does in a later
	 * action with the rows long gone. Without it that pass would trash exactly the
	 * products import_article() goes out of its way not to touch.
	 *
	 * Deliberately not META_SYNCED_AT: that key means "this plugin imported this
	 * product" and writing it here would adopt a product the sync just decided was
	 * none of its business, handing finalise() the right to draft it next run.
	 */
	const META_SEEN_AT = '_wksync_seen_at';

	/**
	 * Meta marking a product Kontor lists but this plugin does not manage.
	 *
	 * Written to a product the shop made itself whose article Kontor is holding back —
	 * switched off for the webshop, or without an image — where import_article()
	 * therefore declines to touch it. The value is the run that last saw it that way.
	 *
	 * It is the only record such a product leaves. It is never drafted, so it carries
	 * none of the drafting markers Admin\HeldProducts is built on, and it is never
	 * stamped, so no later run mentions it again. Without this there was nowhere in
	 * wp-admin to find out that the shop is publicly selling an article the ERP has
	 * switched off.
	 *
	 * Cleared the moment the product is adopted, which is what happens as soon as
	 * Kontor stops holding the article back. A product whose article leaves the
	 * catalogue altogether keeps the marker, because nothing looks at that article
	 * again — the reason it names was true when it was written.
	 */
	const META_UNMANAGED = '_wksync_unmanaged';

	/**
	 * Shop type whose price list Kontor returns as the purchase price.
	 *
	 * See price_field() for why this one is singled out.
	 */
	const SHOPTYPE_WHOLESALE = 'B2B';

	/**
	 * Shop type whose UVP is the recommended retail price.
	 *
	 * This is the list a wholesale shop is requested with, so the retail price
	 * arrives alongside the price it actually sells at.
	 */
	const SHOPTYPE_RETAIL = 'B2C';

	/**
	 * Meta holding the recommended retail price.
	 *
	 * Only written on a wholesale shop, where it is a different number from the
	 * price. Absent whenever Kontor lists no retail price for the article.
	 */
	const META_MSRP = '_wksync_msrp';

	/**
	 * Fields this sync consumes on every shop type.
	 *
	 * The change hash is built from these, so churn in fields we deliberately ignore
	 * — Categories, and Ek on the shop types that do not price from it — cannot
	 * trigger a pointless rewrite of every product. The Categories GUIDs cannot be
	 * resolved at all, because Kontor's categories entity returns no rows.
	 *
	 * mapped_fields() adds Ek for a wholesale shop, which is the one case where the
	 * purchase price is the price the shop charges and so has to be watched.
	 *
	 * Herstellerid is in the list because brands are matched on it. A manufacturer
	 * moved to a different ID has to reach Brands::resolve() to be followed, and an
	 * article skipped as unchanged never gets there.
	 *
	 * @var string[]
	 */
	private static $mapped_fields = array(
		'Artnr',
		'Shoptype',
		'Shoptitel',
		'Bez1',
		'Kurztext',
		'Langtext',
		'UVP',
		'Lagerbestand',
		'Gewnetto',
		'Artean',
		'Mpn',
		'Hersteller',
		'Herstellerid',
		'MainImageURL',
		'Verkaufsmenge',
		'Verkaufsmenge_staffel',
	);

	/**
	 * Meta holding the manufacturer part number.
	 */
	const META_MPN = '_wksync_mpn';

	/**
	 * Meta holding the smallest quantity Kontor sells the article in.
	 *
	 * Read from Verkaufsmenge. Absent whenever the article has no minimum worth
	 * recording, which includes the common case of Kontor sending 1 — that is
	 * WooCommerce's own default, so storing it would put a row of meta on every
	 * product in the catalogue to say nothing at all.
	 */
	const META_MIN_QTY = '_wksync_min_qty';

	/**
	 * Meta holding the quantity step the article is sold in.
	 *
	 * Read from Verkaufsmenge_staffel. Absent on the same terms as META_MIN_QTY.
	 */
	const META_QTY_STEP = '_wksync_qty_step';

	/**
	 * Meta holding a hash of the image filenames last sideloaded.
	 */
	const META_IMAGE_HASH = '_wksync_image_hash';

	/**
	 * Attachment meta holding the URL an imported image came from.
	 *
	 * Doubles as the marker for "this plugin downloaded this file": nothing else
	 * writes it, so it is what separates our media from the shop's own when an
	 * attachment is a candidate for deletion.
	 */
	const META_IMAGE_SOURCE = '_wksync_image_source';

	/**
	 * Marks a product that this sync drafted, rather than a person.
	 */
	const META_SYNC_DRAFTED = '_wksync_drafted_by_sync';

	/**
	 * Marks a product this sync drafted for arriving with no image at all.
	 *
	 * Deliberately not META_SYNC_DRAFTED. That one means "Kontor stopped listing this
	 * article", and this one means "Kontor lists it, without a picture" — two
	 * conditions that clear at different moments and on different feeds. Sharing one
	 * marker would let an article returning to the catalogue republish itself while it
	 * is still imageless, which is exactly what the shop asked not to happen.
	 */
	const META_NO_IMAGE_DRAFTED = '_wksync_drafted_no_image';

	/**
	 * Marks a product this sync drafted for arriving switched off for the webshop.
	 *
	 * Its own marker, for the same reason META_NO_IMAGE_DRAFTED is: it means "Kontor
	 * lists this article and does not want it sold here", which clears the moment
	 * Ws_aktiv comes back true and at no other moment. Sharing a marker with either of
	 * the others would let one sync's reason going away republish a product the shop
	 * has been told to keep off the shelf.
	 */
	const META_INACTIVE_DRAFTED = '_wksync_drafted_inactive';

	/**
	 * Marks a product the stock sync drafted, before it stopped drafting anything.
	 *
	 * The stock feed is narrower than the catalogue, so that pass took a fifth of the
	 * shop dark on its first run for articles Kontor still sells. It is gone, and this
	 * key is kept for one purpose only: to undo it. Every product still carrying it is
	 * hidden for a reason nothing will ever clear again, so restore_if_sync_drafted()
	 * treats it as spent and publishes the product the next time the catalogue lists
	 * the article.
	 *
	 * Nothing writes it. When no shop upgrading from before 0.13.0 is left, it goes.
	 */
	const META_LEGACY_STOCK_DRAFTED = '_wksync_drafted_by_stock';

	/**
	 * How many stale products to draft per finalising pass.
	 */
	const FINALISE_BATCH = 200;

	/**
	 * Seconds to wait for one image download.
	 *
	 * WordPress defaults to 300, and media_sideload_image() offers no way to shorten
	 * it. An image host that accepts the connection and then stops answering would
	 * hold the action open for five minutes per file, so the download is bounded here
	 * instead. Kontor's images are small: a host this slow is not going to answer.
	 *
	 * It bounds the batch as well as the file. A product's images are fetched
	 * together, so the worst an unresponsive host can cost one action is this once per
	 * batch rather than once per image.
	 */
	const IMAGE_TIMEOUT = 20;

	/**
	 * How many of a product's images are downloaded at the same time.
	 *
	 * Sideloading is about 2.2 seconds an image, and only a tenth of that is work this
	 * machine does — the rest is waiting on Kontor's host, which several images can
	 * wait through at once. Four is chosen for the shape of the data rather than for a
	 * benchmark: articles average 2.38 images, so it covers almost every product in
	 * one batch while leaving the connection count somewhere a stranger's web server
	 * would consider reasonable.
	 */
	const IMAGE_CONCURRENCY = 4;

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
	 * Begin a run.
	 *
	 * @return void
	 */
	public function start() {
		// Two concurrent runs would fight over the same products, double-count, and
		// leave the totals meaningless.
		if ( Status::is_running( self::JOB ) ) {
			$this->log( 'info', 'Product sync already running; ignoring the request to start another.' );

			return;
		}

		/*
		 * Never walk the catalogue unconfigured. An unauthenticated run would read as
		 * "Kontor lists nothing", and finalise() would then draft the entire shop.
		 */
		$ready = Preflight::check( self::JOB, $this->settings, $this->client );

		if ( is_wp_error( $ready ) ) {
			Status::fail( self::JOB, $ready->get_error_message() );
			$this->log( 'error', 'Product sync refused to start: ' . $ready->get_error_message() );

			return;
		}

		$run = Status::start( self::JOB );

		Scheduler::chain(
			Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
			array(
				'skip' => 0,
				'run'  => $run,
			)
		);
	}

	/**
	 * Import one page of articles, then queue the next.
	 *
	 * @param int $skip Number of records already requested.
	 * @param int $run  Run identifier.
	 * @return void
	 */
	public function import_page( $skip, $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding product page at offset %d: run %d has been superseded.', $skip, $run ) );

			return;
		}

		$response = $this->client->fetch_products( $skip, Client::PRODUCT_PAGE_SIZE, $this->request_shoptype(), $this->manufacturers() );

		if ( is_wp_error( $response ) ) {
			Status::fail( self::JOB, $response->get_error_message() );
			$this->log( 'error', sprintf( 'Product sync aborted at offset %d: %s', $skip, $response->get_error_message() ) );

			return;
		}

		$rows  = $response['data'];
		$total = isset( $response['meta']['totalCount'] ) ? (int) $response['meta']['totalCount'] : 0;

		// Recorded from the first page only. Kontor repeats totalCount on every page,
		// but a catalogue that changed mid-walk would then move the goalposts under a
		// bar that is already half full.
		if ( 0 === (int) $skip ) {
			Status::measure( self::JOB, $total );
		}

		$counts = array(
			'created'       => 0,
			'updated'       => 0,
			'skipped'       => 0,
			'no_sku'        => 0,
			'no_image'      => 0,
			'inactive'      => 0,
			'duplicate_sku' => 0,
			'unmanaged'     => 0,
			'failed'        => 0,
		);

		foreach ( $rows as $row ) {
			/*
			 * One unimportable article must never take the rest of the page with it.
			 * WooCommerce throws WC_Data_Exception for rejected field values, and a
			 * feed of several thousand rows will always contain a few.
			 */
			try {
				$outcome = $this->import_article( $row, $run );
			} catch ( Exception $exception ) {
				$outcome = 'failed';

				$this->log(
					'error',
					sprintf(
						'Article %s could not be imported: %s',
						isset( $row['Artnr'] ) ? (string) $row['Artnr'] : '(no article number)',
						$exception->getMessage()
					)
				);
			}

			++$counts[ $outcome ];
		}

		Status::progress( self::JOB, $counts );
		Status::advance( self::JOB, count( $rows ) );

		$processed = $skip + count( $rows );

		// An empty page means the catalogue ended earlier than totalCount implied.
		if ( ! empty( $rows ) && $processed < $total ) {
			Scheduler::chain(
				Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
				array(
					'skip' => $processed,
					'run'  => $run,
				)
			);

			return;
		}

		Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, array( 'run' => $run ) );
	}

	/**
	 * Draft any product Kontor no longer lists, then close the run.
	 *
	 * Staleness is decided by the run stamp rather than by collecting every SKU
	 * seen: anything carrying a Kontor ID that this run did not touch is gone from
	 * the feed.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function finalise( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding finalise pass: run %d has been superseded.', $run ) );

			return;
		}

		$stale = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => self::FINALISE_BATCH,
				'fields'           => 'ids',
				'suppress_filters' => false,

				/*
				 * Products carrying an older run stamp are ours and were not in this
				 * run's feed. A product without the stamp has never been synced, so it
				 * belongs to the shop manager and cannot match this comparison at all.
				 */
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in a background job; there is no other way to find products this run did not stamp.
				'meta_query'       => array(
					array(
						'key'     => self::META_SYNCED_AT,
						'value'   => $run,
						'compare' => '<',
						'type'    => 'NUMERIC',
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

			// Marked so a later run can tell this draft apart from a deliberate one
			// and republish it if the article returns to the feed.
			$product->update_meta_data( self::META_SYNC_DRAFTED, 1 );
			$product->save();
		}

		Status::progress( self::JOB, array( 'drafted' => count( $stale ) ) );

		// A full batch means there may be more; come back for another pass.
		if ( count( $stale ) === self::FINALISE_BATCH ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, array( 'run' => $run ) );

			return;
		}

		/*
		 * The drafting is done, so the run either sweeps up what Kontor does not list
		 * or it is finished. Handed to an action of its own rather than run here: it is
		 * unbounded in exactly the way this pass is, and it is the one thing the plugin
		 * does that takes a product out of the shop altogether.
		 */
		if ( $this->trashes_unmanaged() ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_TRASH, array( 'run' => $run ) );

			return;
		}

		$this->complete();
	}

	/**
	 * Whether this shop has asked for products Kontor does not list to be trashed.
	 *
	 * Read from the current settings rather than from anything the action carried, so
	 * clearing the box stops the sweeping at the next pass rather than at the next run.
	 *
	 * @return bool True when the sweep is switched on.
	 */
	protected function trashes_unmanaged() {
		return ! empty( $this->settings[ Settings::TRASH_UNMANAGED ] );
	}

	/**
	 * Move one batch of the products Kontor does not list to the trash.
	 *
	 * Two things have to be true of a product before it is touched, and the second is
	 * what makes this safe. It carries no META_SYNCED_AT, so this plugin never
	 * imported it — the same test finalise() and StockSync::apply() make before
	 * touching anything. And its article number was not in this run's catalogue, which
	 * is what META_SEEN_AT records for the products import_article() deliberately
	 * declines to adopt: one held back for an article we do not own, one of several
	 * sharing an article number, one whose save failed. Asking only the first question
	 * would sweep away precisely those.
	 *
	 * A product is trashed, never deleted, and its images are left in the media
	 * library. Trashing is the whole of the safety here: a run that swept too widely —
	 * a catalogue that came back short, a manufacturer filter narrowed by mistake — is
	 * undone from Products → Trash, and an attachment deleted alongside could not be.
	 *
	 * Asked of the database directly, for StockSync::draft_batch()'s reason: the query
	 * needs an OR ("no marker at all, or one from an earlier run") and WP_Meta_Query
	 * drops meta_key from every ON clause the moment an OR appears, leaving joins that
	 * match every meta row a product has. Both joins below name their key, so each
	 * matches at most one row per product.
	 *
	 * Products already in the trash are excluded, and that is what makes the chain
	 * terminate: trashing a product removes it from the next batch.
	 *
	 * @param int $run Run identifier.
	 * @return void
	 */
	public function trash_unmanaged( $run ) {
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			$this->log( 'info', sprintf( 'Discarding trash pass: run %d has been superseded.', $run ) );

			return;
		}

		/*
		 * Read again rather than trusted from the action that queued this. The box can
		 * be cleared while a run is walking the catalogue, and the answer that matters
		 * is the one on the screen now.
		 */
		if ( ! $this->trashes_unmanaged() ) {
			$this->complete();

			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Explained above; a cached answer would also mean trashing against a stale view of the run's markers.
		$strays = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT posts.ID
				FROM {$wpdb->posts} AS posts
				LEFT JOIN {$wpdb->postmeta} AS ours
					ON ours.post_id = posts.ID AND ours.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} AS seen
					ON seen.post_id = posts.ID AND seen.meta_key = %s
				WHERE posts.post_type = 'product'
					AND posts.post_status NOT IN ( 'trash', 'auto-draft' )
					AND ours.post_id IS NULL
					AND ( seen.post_id IS NULL OR CAST( seen.meta_value AS SIGNED ) < %d )
				ORDER BY posts.ID
				LIMIT %d",
				self::META_SYNCED_AT,
				self::META_SEEN_AT,
				(int) $run,
				self::FINALISE_BATCH
			)
		);

		$strays  = array_map( 'intval', (array) $strays );
		$trashed = array();

		foreach ( $strays as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$sku = (string) $product->get_sku();

			// False is WooCommerce's own way of saying "trash rather than delete". The
			// images are attachments of their own and are not touched by it.
			$product->delete( false );

			$trashed[] = '' === $sku ? sprintf( '#%d', $product_id ) : sprintf( '#%d (%s)', $product_id, $sku );
		}

		if ( ! empty( $trashed ) ) {
			/*
			 * Named rather than counted. This is the only pass that removes a product,
			 * and the log is the only record of which ones: the trash itself says when
			 * they went but not why, and a shop manager finding four hundred products
			 * there needs to be able to check that they were the ones intended.
			 */
			$this->log(
				'info',
				sprintf(
					'Trashed %1$d products Kontor does not list: %2$s.',
					count( $trashed ),
					implode( ', ', $trashed )
				)
			);
		}

		Status::progress( self::JOB, array( 'trashed' => count( $trashed ) ) );

		/*
		 * A full batch means there may be more. Nothing trashed means the whole batch
		 * was products wc_get_product() could not load, which the next pass would find
		 * again — so stop rather than chain for ever.
		 */
		if ( count( $strays ) === self::FINALISE_BATCH && ! empty( $trashed ) ) {
			Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_TRASH, array( 'run' => $run ) );

			return;
		}

		$this->complete();
	}

	/**
	 * Close the run and say what it did.
	 *
	 * Reached from the drafting pass or from the trash pass, whichever ends the chain,
	 * so there is one wording rather than two that could drift apart. It takes no run
	 * identifier: everything it reports was accumulated by Status::progress() as the
	 * run went, and whichever pass gets here has already checked the run is current.
	 *
	 * @return void
	 */
	protected function complete() {
		$counts = Status::get( self::JOB )['counts'];

		$message = sprintf(
			/* translators: 1: created count, 2: updated count, 3: unchanged count, 4: drafted count. */
			__( '%1$d created, %2$d updated, %3$d unchanged, %4$d drafted.', 'woo-kontor-sync-pro' ),
			isset( $counts['created'] ) ? (int) $counts['created'] : 0,
			isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
			isset( $counts['skipped'] ) ? (int) $counts['skipped'] : 0,
			isset( $counts['drafted'] ) ? (int) $counts['drafted'] : 0
		);

		$no_image = isset( $counts['no_image'] ) ? (int) $counts['no_image'] : 0;

		/*
		 * Only when the shop asked for it, so the line never appears on a shop that has
		 * not turned the requirement on. It is not a fault in the way the two below are
		 * — it is the setting doing what it was asked — but it is a number somebody
		 * choosing that setting wants to see.
		 */
		if ( $no_image > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of articles Kontor listed no image for. */
				__( 'Held %d back as drafts for having no image.', 'woo-kontor-sync-pro' ),
				$no_image
			);
		}

		$inactive = isset( $counts['inactive'] ) ? (int) $counts['inactive'] : 0;

		/*
		 * Said out loud because it is the one number here nobody chose: the shop turned
		 * on no setting to get it, and a fifth of a catalogue can arrive switched off.
		 * Without the line, a run that held hundreds of articles back reads exactly like
		 * a run that found hundreds fewer articles to import.
		 */
		if ( $inactive > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of articles Kontor has switched off for the webshop. */
				__( 'Held %d back as drafts, switched off for the webshop in Kontor.', 'woo-kontor-sync-pro' ),
				$inactive
			);
		}

		$trashed = isset( $counts['trashed'] ) ? (int) $counts['trashed'] : 0;

		/*
		 * Only when the sweep actually removed something, so a shop that leaves the
		 * setting alone reads the sentence it read before the setting existed — and one
		 * that has turned it on stops being told about it once the shop is clean.
		 */
		if ( $trashed > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of products moved to the trash for not being in Kontor's catalogue. */
				__( 'Moved %d to the trash that Kontor does not list.', 'woo-kontor-sync-pro' ),
				$trashed
			);
		}

		$unmanaged = isset( $counts['unmanaged'] ) ? (int) $counts['unmanaged'] : 0;

		/*
		 * Its own sentence, and deliberately not the drafting one. These products were
		 * not drafted: they are the shop's own, still published and still on sale, for
		 * articles the ERP is holding back. Folded into the "held back as drafts" count
		 * — which is what happened until this was separated out — the summary reported
		 * a drafting that had not taken place, and the one case where the shop and
		 * Kontor openly disagree read as a case that had been dealt with.
		 */
		if ( $unmanaged > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of the shop's own products whose article Kontor is holding back. */
				__( 'Left %d alone that Kontor is holding back but this plugin did not import.', 'woo-kontor-sync-pro' ),
				$unmanaged
			);
		}

		$no_sku    = isset( $counts['no_sku'] ) ? (int) $counts['no_sku'] : 0;
		$duplicate = isset( $counts['duplicate_sku'] ) ? (int) $counts['duplicate_sku'] : 0;

		/*
		 * Both are data problems in the feed or the shop that only a person can fix, so
		 * they are said out loud rather than left for whoever thinks to open the log.
		 * Only when there are any: a clean run should read as a clean run.
		 */
		if ( $no_sku > 0 || $duplicate > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: articles with no article number, 2: articles whose article number matched more than one product. */
				__( 'Passed over %1$d with no article number and %2$d matching more than one product; see the log.', 'woo-kontor-sync-pro' ),
				$no_sku,
				$duplicate
			);
		}

		Status::finish( self::JOB, $message );
	}

	/**
	 * Create or update a single WooCommerce product from a Kontor article.
	 *
	 * @param array $row Article row from the API.
	 * @param int   $run Run identifier.
	 * @return string One of "created", "updated", "skipped", "no_sku", "no_image", "duplicate_sku" or "failed".
	 */
	public function import_article( array $row, $run ) {
		$sku = isset( $row['Artnr'] ) ? trim( (string) $row['Artnr'] ) : '';

		/*
		 * The SKU is the only key, so an article without one cannot be matched to a
		 * product and must not be created either: the next run would have nothing to
		 * recognise it by and would import it a second time. Passing over the row is
		 * the only outcome that stays correct on every run.
		 */
		if ( '' === $sku ) {
			$this->log(
				'warning',
				sprintf(
					'Skipped an article with no article number (EAN %1$s, name %2$s): SKU is the only key.',
					'' === $this->text( $row, 'Artean', '' ) ? '(none)' : $this->text( $row, 'Artean', '' ),
					'' === $this->text( $row, 'Bez1', '' ) ? '(none)' : $this->text( $row, 'Bez1', '' )
				)
			);

			return 'no_sku';
		}

		$matches = $this->products_for_sku( $sku );

		/*
		 * Two products answering to one article number is a shop that needs repairing,
		 * not a product to update. Picking one would write Kontor's data onto whichever
		 * happened to sort first and leave the other drifting; creating a third would
		 * make it worse. Nothing is written, and the log names the products so someone
		 * can go and resolve it.
		 */
		if ( count( $matches ) > 1 ) {
			$this->log(
				'error',
				sprintf(
					'Skipped article %1$s: products %2$s all carry that SKU. Nothing was written; leave one product holding the article number.',
					$sku,
					implode( ', ', $matches )
				)
			);

			$this->keep_alive( $matches, $run );
			$this->mark_seen( $matches, $run );

			return 'duplicate_sku';
		}

		$product_id = empty( $matches ) ? 0 : $matches[0];
		$existing   = $product_id ? wc_get_product( $product_id ) : null;

		/*
		 * Whether anything is holding this article out of the shop, asked before the
		 * unchanged-article shortcut below rather than after. An article that has not
		 * moved in any other respect is exactly the case this has to catch: Ws_aktiv is
		 * not part of the change hash, and turning the image requirement on has to take
		 * effect on the next run rather than waiting for every article in the shop to
		 * change.
		 */
		$withheld = $this->withheld_reason( $row );

		/*
		 * A product this plugin never imported is left entirely alone — neither drafted
		 * nor rewritten. A shop manager's own product answering to the same article
		 * number was never ours to unpublish, and adopting it here would only mean
		 * drafting it on the run after this one.
		 */
		if ( '' !== $withheld && $existing && '' === (string) $existing->get_meta( self::META_SYNCED_AT ) ) {
			/*
			 * Left alone, but not left looking like a product Kontor has never heard
			 * of: the article is in the catalogue, and trash_unmanaged() decides by
			 * that and nothing else. Without the marker the one pass that removes
			 * products would remove precisely the ones this branch protects.
			 */
			$this->mark_seen( array( $existing->get_id() ), $run );

			// The only record this product leaves. Nothing drafts it and nothing stamps
			// it, so without the marker no screen in wp-admin could name it.
			update_post_meta( $existing->get_id(), self::META_UNMANAGED, (int) $run );

			/*
			 * Deliberately not $withheld. That value means "held back as a draft", and
			 * nothing here was drafted — the product is still published, still on sale,
			 * and still the shop's own. Counting the two together had the run summary
			 * reporting drafts that were never made.
			 */
			return 'unmanaged';
		}

		$hash = $this->hash( $row );

		// Exactly one of these can apply: either something is holding the article back
		// or nothing is.
		$restored = ( '' === $withheld && $existing ) ? $this->restore_if_sync_drafted( $existing ) : false;
		$held     = ( '' !== $withheld && $existing ) ? $this->hold_back( $existing, $sku, $withheld ) : false;

		// Nothing changed since the last run, so only refresh the run stamp. Writing
		// just the meta avoids a full product save for every unchanged article.
		if ( $existing && ! $restored && ! $held && $hash === (string) $existing->get_meta( self::META_HASH ) ) {
			$existing->update_meta_data( self::META_SYNCED_AT, $run );
			$existing->save_meta_data();

			/*
			 * Images are downloaded in their own action and can fail on their own, so an
			 * article that has not changed still has to be offered to the image queue.
			 * Without this, a set that never completed would stay incomplete until the
			 * article itself changed, which for a stable product is never.
			 */
			$this->queue_images( $existing->get_id(), $row, $run );

			return '' === $withheld ? 'skipped' : $withheld;
		}

		$product   = $existing ? $existing : new WC_Product_Simple();
		$is_create = ! $existing;

		if ( $is_create ) {
			$product->set_sku( $sku );
			$product->set_catalog_visibility( 'visible' );

			/*
			 * A new article goes live unless something is holding it back, and then it is
			 * created as a draft rather than not created at all. The shop ends up holding
			 * the whole catalogue, priced, stocked and pictured, with the part it may not
			 * sell sitting one status change away — which is what makes the article
			 * appearing in the shop the moment Kontor switches it on possible.
			 */
			$product->set_status( '' === $withheld ? 'publish' : 'draft' );

			if ( '' !== $withheld ) {
				$product->update_meta_data( $this->marker_for( $withheld ), 1 );

				$this->log(
					'info',
					sprintf( 'Created article %1$s as a draft: %2$s.', $sku, $this->reason_for( $withheld ) )
				);
			}
		}

		$this->apply_fields( $product, $row );

		$product->update_meta_data( self::META_HASH, $hash );
		$product->update_meta_data( self::META_SYNCED_AT, $run );

		/*
		 * Adopting the product answers the marker: whatever was holding the article
		 * back has stopped, or the product was never unmanaged in the first place.
		 * Left behind it would have Admin\HeldProducts naming a product the sync now
		 * manages perfectly well.
		 */
		$product->delete_meta_data( self::META_UNMANAGED );
		$product->update_meta_data( self::META_MPN, $this->text( $row, 'Mpn', '' ) );

		$saved_id = $product->save();

		if ( ! $saved_id ) {
			$this->log( 'error', sprintf( 'Could not save product for article %s.', $sku ) );

			// A save that failed left no stamp behind, so an existing product would read
			// as one Kontor never listed. The article was in the feed either way.
			if ( $existing ) {
				$this->mark_seen( array( $existing->get_id() ), $run );
			}

			return 'failed';
		}

		Brands::assign( $saved_id, $this->text( $row, 'Hersteller', '' ), $this->text( $row, 'Herstellerid', '' ) );

		/*
		 * A withheld product is pictured like any other. It is in the shop, a shop
		 * manager opening it should see the article rather than a placeholder, and one
		 * switched on tomorrow goes in front of customers complete instead of bare while
		 * its downloads catch up. Images are the one thing this plugin queues below
		 * everything else, so the extra work cannot delay a sync that matters.
		 */
		$this->queue_images( $saved_id, $row, $run );

		if ( '' !== $withheld ) {
			return $withheld;
		}

		return $is_create ? 'created' : 'updated';
	}

	/**
	 * Every product answering to a SKU.
	 *
	 * WooCommerce's own wc_get_product_id_by_sku() stops at the first row, which is
	 * the one thing that cannot happen here: knowing whether a second product holds
	 * the same article number is the whole point of the lookup. The query is core's,
	 * without its LIMIT 1.
	 *
	 * WooCommerce rejects a duplicate SKU on save, so this should never find two —
	 * but "should never" is not "cannot". A migration, a CSV import, anything
	 * short-circuiting wc_product_pre_has_unique_sku, and any code writing _sku
	 * directly all produce them, and the sync is what would otherwise quietly pick a
	 * side.
	 *
	 * @param string $sku Article number to look for.
	 * @return int[] Product IDs, oldest first.
	 */
	protected function products_for_sku( $sku ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core's lookup answers "which product", never "how many"; the result decides whether a product is written to and must not be served from cache.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT posts.ID
				FROM {$wpdb->posts} AS posts
				INNER JOIN {$wpdb->wc_product_meta_lookup} AS lookup ON posts.ID = lookup.product_id
				WHERE posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND lookup.sku = %s
				ORDER BY posts.ID ASC",
				$sku
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Move the run stamp on products a duplicate SKU stopped us writing to.
	 *
	 * Nothing about the products changes, but the stamp has to keep up. finalise()
	 * drafts anything this plugin imported that the current run did not stamp, so
	 * passing over a duplicated article number entirely would unpublish both products
	 * for an article Kontor is still listing — turning a line in the log into a hole
	 * in the shop, which is a far worse answer to the same ambiguity.
	 *
	 * Only products already carrying the stamp are touched. It doubles as the marker
	 * for "this plugin imported this product", so stamping a shop manager's own
	 * product would adopt it and hand finalise() the right to draft it later.
	 *
	 * @param int[] $product_ids Products sharing the SKU.
	 * @param int   $run         Run identifier.
	 * @return void
	 */
	protected function keep_alive( array $product_ids, $run ) {
		foreach ( $product_ids as $product_id ) {
			if ( '' === (string) get_post_meta( $product_id, self::META_SYNCED_AT, true ) ) {
				continue;
			}

			update_post_meta( $product_id, self::META_SYNCED_AT, $run );
		}
	}

	/**
	 * Record that this run's catalogue named these products' article number.
	 *
	 * Only for products the run did not adopt. Everything else already carries
	 * META_SYNCED_AT, which answers the same question and is what tells finalise()
	 * a product is ours; this key deliberately says less than that.
	 *
	 * Written whatever the trash setting says, unlike the stock sync's run stamp,
	 * which is skipped when its own pass is off. The two differ in what they cost and
	 * in what going without costs. That stamp is one write per article across a feed
	 * of three thousand, every fifteen minutes; this one reaches only the handful of
	 * products a run declines to adopt. And the gap it would leave is destructive
	 * rather than recoverable: a setting turned on between the walk and the pass would
	 * find no markers at all and trash every product the walk had protected.
	 *
	 * @param int[] $product_ids Products whose article was in the feed.
	 * @param int   $run         Run identifier.
	 * @return void
	 */
	protected function mark_seen( array $product_ids, $run ) {
		foreach ( $product_ids as $product_id ) {
			update_post_meta( (int) $product_id, self::META_SEEN_AT, (int) $run );
		}
	}

	/**
	 * Whether this article is being held back for having no image.
	 *
	 * The decision is made on the feed row alone, never on what the product currently
	 * looks like in the shop. Images are downloaded in an action of their own, minutes
	 * or hours after the article is written, so a product that has just been imported
	 * legitimately has no featured image yet — judging by the shop would draft products
	 * whose pictures were merely still on their way.
	 *
	 * It also does not depend on the image base URL being set. Whether the shop can
	 * fetch the file is a separate question from whether Kontor has one to offer, and
	 * tying the two together would mean clearing the base URL silently drafted the
	 * entire catalogue.
	 *
	 * @param array $row Article row from the API.
	 * @return bool True when the article must not go on sale.
	 */
	protected function image_withheld( array $row ) {
		if ( empty( $this->settings['require_main_image'] ) ) {
			return false;
		}

		return ! $this->has_image( $row );
	}

	/**
	 * Whether Kontor lists any image for an article.
	 *
	 * The featured image is the first image the article carries, which is MainImageURL
	 * when there is one and the first gallery entry when there is not. So the question
	 * the setting really asks — will this product end up with a picture on it — is
	 * answered by the whole list rather than by MainImageURL alone.
	 *
	 * @param array $row Article row from the API.
	 * @return bool True when at least one image field carries a filename.
	 */
	protected function has_image( array $row ) {
		foreach ( $this->all_image_keys() as $key ) {
			if ( '' !== $this->text( $row, $key, '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether Kontor has switched this article off for the webshop.
	 *
	 * Ws_aktiv is the ERP saying whether an article belongs in the shop at all, and it
	 * is not a small number: 827 of the 4386 articles on the account this was built
	 * against arrive false. It is a real boolean there, present on every row.
	 *
	 * Only a value that unmistakably reads as false withholds the article. A missing
	 * key, a null, or a word this does not recognise is treated as active, because the
	 * two ways of being wrong are not equal — reading "active" as "inactive" takes a
	 * fifth of the shop dark on the strength of a field that changed shape, while the
	 * reverse leaves a handful of articles on sale until somebody notices.
	 *
	 * @param array $row Article row from the API.
	 * @return bool True when the article must not be sold here.
	 */
	protected function is_inactive( array $row ) {
		if ( ! array_key_exists( 'Ws_aktiv', $row ) ) {
			return false;
		}

		$flag = $row['Ws_aktiv'];

		if ( is_bool( $flag ) ) {
			return ! $flag;
		}

		if ( ! is_scalar( $flag ) ) {
			return false;
		}

		return in_array( strtolower( trim( (string) $flag ) ), array( '0', 'false', 'no', 'nein' ), true );
	}

	/**
	 * What, if anything, is holding this article out of the shop.
	 *
	 * Kontor's own verdict is asked for first and settles the question on its own: an
	 * article switched off for the webshop is held back whether or not it has a
	 * picture, so the image requirement is only ever asked about an article Kontor is
	 * willing to sell here. Reversing the two would let a shop's own setting decide
	 * which of the ERP's articles were mentioned in the run summary.
	 *
	 * The decision is made on the feed row alone, never on what the product currently
	 * looks like in the shop, and it never asks whether the images can be *fetched* —
	 * see image_withheld() for both.
	 *
	 * @param array $row Article row from the API.
	 * @return string "inactive", "no_image", or an empty string when nothing is.
	 */
	protected function withheld_reason( array $row ) {
		if ( $this->is_inactive( $row ) ) {
			return 'inactive';
		}

		if ( $this->image_withheld( $row ) ) {
			return 'no_image';
		}

		return '';
	}

	/**
	 * The meta key recording that this sync drafted a product for a given reason.
	 *
	 * @param string $withheld Reason from withheld_reason().
	 * @return string Meta key.
	 */
	protected function marker_for( $withheld ) {
		return 'inactive' === $withheld ? self::META_INACTIVE_DRAFTED : self::META_NO_IMAGE_DRAFTED;
	}

	/**
	 * How a reason reads in the log.
	 *
	 * @param string $withheld Reason from withheld_reason().
	 * @return string Sentence fragment naming the reason.
	 */
	protected function reason_for( $withheld ) {
		return 'inactive' === $withheld
			? 'Kontor has switched it off for the webshop'
			: 'Kontor lists no image for it';
	}

	/**
	 * Draft an existing product, marking why.
	 *
	 * Drafted rather than deleted, and the article's data is still written over it:
	 * everything a withheld product needs to go on sale is kept ready, so an article
	 * switched back on tomorrow is published complete rather than as whatever it looked
	 * like the day it was withdrawn. Nothing a shop has built around the product — its
	 * URL, its reviews, its place in an order — is destroyed by a feed that was briefly
	 * incomplete.
	 *
	 * The marker goes on a product already drafted as well as one being drafted now.
	 * Without it, whichever sync drafted the product for its own reason would clear that
	 * reason and republish an article this one is still holding back.
	 *
	 * @param WC_Product_Simple $product  Product holding the article number.
	 * @param string            $sku      Article number, for the log.
	 * @param string            $withheld Reason from withheld_reason().
	 * @return bool True when the product changed and therefore needs saving.
	 */
	protected function hold_back( $product, $sku, $withheld ) {
		$status = $product->get_status();

		/*
		 * Published and draft are the two states this sync owns. Anything else — private,
		 * pending, a status another plugin registered — is a decision somebody made about
		 * the product, and marking it would hand a later run the right to publish it. The
		 * article's data is still written; only the status is left where it was put.
		 */
		if ( 'publish' !== $status && 'draft' !== $status ) {
			return false;
		}

		$marker  = $this->marker_for( $withheld );
		$changed = false;

		if ( ! $product->get_meta( $marker ) ) {
			$product->update_meta_data( $marker, 1 );

			$changed = true;
		}

		// A product already drafted is marked but not logged again.
		if ( 'publish' === $status ) {
			$product->set_status( 'draft' );

			$this->log( 'info', sprintf( 'Drafted article %1$s: %2$s.', $sku, $this->reason_for( $withheld ) ) );

			$changed = true;
		}

		return $changed;
	}

	/**
	 * The manufacturer IDs the import is restricted to.
	 *
	 * An empty list means the whole catalogue, which is what a fresh install has.
	 *
	 * Narrowing this is a destructive-looking but correct operation: the excluded
	 * articles stop arriving, so finalise() drafts the products it imported for them,
	 * exactly as it would if Kontor had dropped them. Widening it again republishes
	 * them, because restore_if_sync_drafted() only ever undoes this sync's own
	 * drafting.
	 *
	 * @return array Manufacturer IDs, as strings.
	 */
	protected function manufacturers() {
		$ids = isset( $this->settings['manufacturer_ids'] ) ? (array) $this->settings['manufacturer_ids'] : array();

		return array_values( array_filter( array_map( 'strval', $ids ), 'strlen' ) );
	}

	/**
	 * The shop type the shop is configured as.
	 *
	 * @return string One of the keys of Settings::shoptypes().
	 */
	protected function shoptype() {
		$shoptype = isset( $this->settings['shoptype'] ) ? (string) $this->settings['shoptype'] : self::SHOPTYPE_WHOLESALE;

		return '' === $shoptype ? self::SHOPTYPE_WHOLESALE : $shoptype;
	}

	/**
	 * The shop type the catalogue is actually requested with.
	 *
	 * A wholesale shop asks for the retail list, because that one request carries
	 * both numbers it needs: Ek is the wholesale price and UVP is the retail price.
	 * Asking for the wholesale list instead would return the same Ek and a UVP that
	 * merely repeats it, so there would be no retail price to show at all.
	 *
	 * Every other shop type is requested as itself, where UVP is the price and there
	 * is no second figure to fetch.
	 *
	 * @return string Shop type to send as the filter.
	 */
	protected function request_shoptype() {
		return self::SHOPTYPE_WHOLESALE === $this->shoptype() ? self::SHOPTYPE_RETAIL : $this->shoptype();
	}

	/**
	 * The field the product price is read from.
	 *
	 * Ek on a wholesale shop, UVP everywhere else. That is not a purchase price
	 * being sold at cost: Kontor's B2B price list *is* Ek, confirmed against the live
	 * account on 986 articles sampled across the catalogue, where Ek equalled the B2B
	 * UVP on every row and stayed constant across all three shop types. A wholesale
	 * shop pricing from Ek therefore charges exactly what it charged when it priced
	 * from the B2B UVP.
	 *
	 * The equality is what the whole arrangement rests on. Should Kontor ever put a
	 * margin between the two, a wholesale shop would sell at whichever is lower with
	 * nothing to say so, so re-check it before trusting this on another account.
	 *
	 * @return string Field name.
	 */
	protected function price_field() {
		return self::SHOPTYPE_WHOLESALE === $this->shoptype() ? 'Ek' : 'UVP';
	}

	/**
	 * The feed fields this run consumes.
	 *
	 * Ek joins the list on a wholesale shop, where it is the price. Leaving it out
	 * there would mean a price change that moved Ek alone never altered the hash, so
	 * the article would read as unchanged and keep its old price for good.
	 *
	 * @return string[] Field names.
	 */
	protected function mapped_fields() {
		$fields = self::$mapped_fields;

		if ( self::SHOPTYPE_WHOLESALE === $this->shoptype() ) {
			$fields[] = 'Ek';
		}

		return $fields;
	}

	/**
	 * Hash the fields this sync maps, so unchanged articles can be skipped.
	 *
	 * Deliberately not a hash of the whole row: the fields this sync ignores move
	 * independently of the ones it imports, so hashing them would rewrite the entire
	 * catalogue whenever a purchase price shifted on a shop that does not sell at it.
	 *
	 * The configured shop type is hashed alongside the row, because it decides which
	 * field becomes the price. Without it, switching a shop from wholesale to retail
	 * would leave every product priced at Ek: both shops are sent the same request,
	 * so the row that comes back is identical and nothing in it would have changed.
	 *
	 * @param array $row Article row from the API.
	 * @return string Hash of the mapped fields.
	 */
	protected function hash( array $row ) {
		$mapped = array( '_wksync_shoptype' => $this->shoptype() );

		foreach ( array_merge( $this->mapped_fields(), $this->image_keys() ) as $field ) {
			$mapped[ $field ] = isset( $row[ $field ] ) ? $row[ $field ] : null;
		}

		return md5( (string) wp_json_encode( $mapped ) );
	}

	/**
	 * Republish a product that an earlier run of this sync drafted.
	 *
	 * An article can leave the feed and come back, one Kontor listed no picture for can
	 * gain one, and one switched off for the webshop can be switched back on. Without
	 * this, any of the three drafts the product once and it stays hidden forever even
	 * though the reason has gone.
	 *
	 * Only drafts this plugin created are restored: the marker meta is what
	 * distinguishes them from a product a shop manager deliberately unpublished,
	 * which must be left alone.
	 *
	 * All three of this sync's reasons are cleared here, because reaching this method
	 * means all three have gone: the article is in the feed, and import_article() has
	 * already turned back anything still imageless or still switched off.
	 *
	 * The stock sync's old marker is cleared as well, and on its own is enough to
	 * bring a product back. It is not a verdict this shop still holds — nothing writes
	 * it and nothing else would ever clear it — so a product carrying only that one is
	 * hidden for a reason that no longer exists, and the next run of this sync is what
	 * puts it back on the shelf.
	 *
	 * Its *current* marker is the opposite, and blocks. A shop that has switched the
	 * drafting back on is saying that an article with no stock record does not belong
	 * in the shop, and the catalogue listing the article again says nothing about
	 * whether Kontor holds any stock of it. That marker is left for the stock sync to
	 * clear when a level arrives, exactly as this sync's markers are left for this one.
	 *
	 * @param WC_Product_Simple $product Existing product.
	 * @return bool True when the product changed and therefore needs saving.
	 */
	protected function restore_if_sync_drafted( $product ) {
		if ( 'draft' !== $product->get_status() ) {
			return false;
		}

		$reasons = array();

		if ( $product->get_meta( self::META_SYNC_DRAFTED ) ) {
			$product->delete_meta_data( self::META_SYNC_DRAFTED );

			$reasons[] = 'Kontor lists it again';
		}

		if ( $product->get_meta( self::META_NO_IMAGE_DRAFTED ) ) {
			$product->delete_meta_data( self::META_NO_IMAGE_DRAFTED );

			$reasons[] = 'it has an image again';
		}

		if ( $product->get_meta( self::META_INACTIVE_DRAFTED ) ) {
			$product->delete_meta_data( self::META_INACTIVE_DRAFTED );

			$reasons[] = 'Kontor has switched it on for the webshop again';
		}

		if ( $product->get_meta( self::META_LEGACY_STOCK_DRAFTED ) ) {
			$product->delete_meta_data( self::META_LEGACY_STOCK_DRAFTED );

			$reasons[] = 'the stock sync no longer drafts articles it does not carry';
		}

		if ( empty( $reasons ) ) {
			return false;
		}

		if ( $product->get_meta( StockSync::META_STOCK_DRAFTED ) ) {
			$this->log(
				'info',
				sprintf( 'Article %s is importable again, but has no stock record; leaving it drafted.', $product->get_sku() )
			);

			return true;
		}

		$product->set_status( 'publish' );

		$this->log(
			'info',
			sprintf( 'Republished article %1$s: %2$s.', $product->get_sku(), implode( ' and ', $reasons ) )
		);

		return true;
	}

	/**
	 * Copy the Kontor fields onto a WooCommerce product.
	 *
	 * @param WC_Product_Simple $product Product to populate.
	 * @param array             $row     Article row from the API.
	 * @return void
	 */
	protected function apply_fields( $product, array $row ) {
		$title = $this->text( $row, 'Shoptitel', '' );

		if ( '' === $title ) {
			$title = $this->text( $row, 'Bez1', '' );
		}

		if ( '' !== $title ) {
			/*
			 * Not sanitize_text_field(): it strips percent-encoded octets, so a title
			 * like "Rabatt 20%ab Lager" silently becomes "Rabatt 20 Lager". Stripping
			 * tags is the actual requirement; WooCommerce escapes on output.
			 */
			$product->set_name( trim( wp_strip_all_tags( $title ) ) );
		}

		$product->set_description( wp_kses_post( $this->text( $row, 'Langtext', '' ) ) );
		$product->set_short_description( wp_kses_post( $this->text( $row, 'Kurztext', '' ) ) );

		/*
		 * The price comes from the field the configured shop type sells at — UVP for a
		 * retail or education shop, Ek for a wholesale one, where Kontor's B2B price
		 * list and the purchase price are the same number. See price_field().
		 */
		$price = wc_format_decimal( $this->text( $row, $this->price_field(), '' ) );

		if ( '' !== $price ) {
			$product->set_regular_price( $price );
		}

		$this->apply_msrp( $product, $row );
		$this->apply_quantities( $product, $row );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) round( (float) $this->text( $row, 'Lagerbestand', '0' ) ) );

		$weight = (float) $this->text( $row, 'Gewnetto', '0' );

		if ( $weight > 0 ) {
			$product->set_weight( wc_format_decimal( $weight ) );
		}

		$this->apply_ean( $product, $this->text( $row, 'Artean', '' ) );
	}

	/**
	 * Record the recommended retail price, where there is one to record.
	 *
	 * Only a wholesale shop has one: it sells at Ek, so the retail UVP arriving in the
	 * same row is a genuinely different figure, and it is the number a business buying
	 * here needs in order to know what it can resell at. On a retail or education shop
	 * the UVP already is the price, so there is nothing to record beside it.
	 *
	 * The value is stored raw rather than as a saving. Kontor lists no retail price at
	 * all for some articles and one no higher than Ek for others — 25 in 986 sampled,
	 * mostly nulls — so whether it can be shown as a discount is a question for
	 * whatever renders it, not one to bake into the import.
	 *
	 * Anything absent, zero or negative deletes the meta rather than writing a
	 * misleading 0.00, which also clears the figure from a shop that has since moved
	 * off wholesale.
	 *
	 * @param WC_Product_Simple $product Product to populate.
	 * @param array             $row     Article row from the API.
	 * @return void
	 */
	protected function apply_msrp( $product, array $row ) {
		$msrp = '';

		if ( self::SHOPTYPE_WHOLESALE === $this->shoptype() ) {
			$msrp = wc_format_decimal( $this->text( $row, 'UVP', '' ) );
		}

		if ( '' === $msrp || 0 >= (float) $msrp ) {
			$product->delete_meta_data( self::META_MSRP );

			return;
		}

		$product->update_meta_data( self::META_MSRP, $msrp );
	}

	/**
	 * Record the quantities Kontor sells the article in.
	 *
	 * Verkaufsmenge is the smallest quantity that may be bought and
	 * Verkaufsmenge_staffel the step it goes up in, so an article sold in sixes with
	 * a step of two is bought as 6, 8, 10 and so on. Both keys are always present in
	 * the feed and either can be null.
	 *
	 * The figures are recorded on every run whatever the enforcement setting says.
	 * They are what Kontor states about the article — a fact about the goods rather
	 * than a decision about this shop — and keeping the import independent of the
	 * setting is what lets the setting be turned on and take effect at once, instead
	 * of waiting for a full catalogue walk to write figures that were skipped.
	 *
	 * Anything absent, zero, negative, fractional or equal to 1 deletes the meta
	 * rather than storing it. One is WooCommerce's own default, so recording it would
	 * add a row per product to say what is already true; a fraction is not a number of
	 * pieces and there is no honest way to round it into one.
	 *
	 * @param WC_Product_Simple $product Product to populate.
	 * @param array             $row     Article row from the API.
	 * @return void
	 */
	protected function apply_quantities( $product, array $row ) {
		$fields = array(
			self::META_MIN_QTY  => 'Verkaufsmenge',
			self::META_QTY_STEP => 'Verkaufsmenge_staffel',
		);

		foreach ( $fields as $meta_key => $field ) {
			$quantity = $this->quantity( $row, $field, (string) $product->get_sku() );

			if ( $quantity < 2 ) {
				$product->delete_meta_data( $meta_key );

				continue;
			}

			$product->update_meta_data( $meta_key, $quantity );
		}
	}

	/**
	 * Read one of the sales-quantity fields as a whole number of pieces.
	 *
	 * A value that is present but not a positive whole number is reported, because it
	 * is a shape the field is not defined to carry and the alternative — rounding it
	 * into something plausible — would quietly change the rule the shop enforces. An
	 * absent or null value is not reported: that is the ordinary case for an article
	 * with nothing special about how it is sold.
	 *
	 * @param array  $row   Article row from the API.
	 * @param string $field Field name.
	 * @param string $sku   Article number, for the log.
	 * @return int Quantity, or 0 when there is none to use.
	 */
	protected function quantity( array $row, $field, $sku ) {
		$value = $this->text( $row, $field, '' );

		if ( '' === $value ) {
			return 0;
		}

		$number = (float) $value;

		if ( ! is_numeric( $value ) || floor( $number ) !== $number ) {
			$this->log(
				'warning',
				sprintf( 'Ignored %1$s "%2$s" on article %3$s: it is not a whole number of pieces.', $field, $value, $sku )
			);

			return 0;
		}

		return max( 0, (int) $number );
	}

	/**
	 * Set the article's EAN, unless another product already claims it.
	 *
	 * WooCommerce enforces that global_unique_id is unique and throws a
	 * WC_Data_Exception on a duplicate. Kontor's feed genuinely repeats EANs across
	 * articles, so the value has to be checked before it is set: without this, one
	 * repeated barcode aborts the entire page of products being imported.
	 *
	 * The lookup here is a collision check and nothing more. The EAN is never a
	 * matching key — it is not unique in the feed, so it could not be one.
	 *
	 * @param WC_Product_Simple $product Product being populated.
	 * @param string            $ean     EAN from the feed, possibly empty.
	 * @return void
	 */
	protected function apply_ean( $product, $ean ) {
		if ( '' === $ean ) {
			return;
		}

		$owner = wc_get_product_id_by_global_unique_id( $ean );

		if ( $owner && $owner !== $product->get_id() ) {
			$this->log(
				'info',
				sprintf( 'Skipped EAN %s for article %s: product %d already uses it.', $ean, $product->get_sku(), $owner )
			);

			return;
		}

		$product->set_global_unique_id( $ean );
	}

	/**
	 * Queue an article's images for download in an action of their own.
	 *
	 * Sideloading is by a wide margin the slowest thing the import does, and the only
	 * part that waits on a host nobody here controls: measured against the live
	 * catalogue it runs about 2.2 seconds per image, and articles average 2.38 of
	 * them. Left inside the page action, a page of 200 articles would spend some
	 * seventeen minutes downloading — past the execution limit of an ordinary host,
	 * where the action is killed, Action Scheduler gives up on the chain, and
	 * finalise() never runs. Chained separately the catalogue walk stays bound by
	 * write speed alone, and a slow image can only ever delay itself.
	 *
	 * Separate is not enough on its own, because Action Scheduler claims in insertion
	 * order and a page queues its images before it queues the next page. They carry
	 * Scheduler::PRIORITY_IMAGES so the whole walk is claimed ahead of them.
	 *
	 * @param int   $product_id Product the images belong to.
	 * @param array $row        Article row from the API.
	 * @param int   $run        Run identifier.
	 * @return void
	 */
	protected function queue_images( $product_id, array $row, $run ) {
		$files = $this->image_files( $row );

		if ( empty( $files ) ) {
			return;
		}

		// Already complete, so there is nothing to fetch and no action worth queueing.
		if ( $this->image_hash( $files ) === (string) get_post_meta( (int) $product_id, self::META_IMAGE_HASH, true ) ) {
			return;
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
			array(
				'product_id' => (int) $product_id,
				'files'      => $files,
				'run'        => (int) $run,
			),
			Scheduler::PRIORITY_IMAGES
		);
	}

	/**
	 * Download one product's images into the media library.
	 *
	 * The filename list is hashed so an unchanged set is never downloaded twice, and
	 * any file already in the media library is reused rather than fetched again — the
	 * same photograph is shared across articles often enough that downloading per
	 * product would multiply the library.
	 *
	 * @param int   $product_id Product to attach images to.
	 * @param array $files      Image filenames, relative to the configured base URL.
	 * @param int   $run        Run identifier.
	 * @return void
	 */
	public function import_images( $product_id, array $files, $run ) {
		/*
		 * A newer run has taken over. Status::finish() leaves the run stamp alone, so
		 * images queued by a run that has already completed are still wanted; only a
		 * fresh run supersedes them.
		 */
		if ( ! Status::is_current_run( self::JOB, $run ) ) {
			return;
		}

		$base    = isset( $this->settings['image_base_url'] ) ? trim( (string) $this->settings['image_base_url'] ) : '';
		$product = wc_get_product( (int) $product_id );

		if ( ! $product || empty( $files ) || '' === $base ) {
			return;
		}

		$hash = $this->image_hash( $files );

		if ( $hash === (string) $product->get_meta( self::META_IMAGE_HASH ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$previous = $this->attached_image_ids( $product );
		$urls     = array();

		foreach ( $files as $file ) {
			$urls[] = trailingslashit( $base ) . ltrim( (string) $file, '/' );
		}

		$attachment_ids = $this->resolve_images( $urls, (int) $product_id, (string) $product->get_sku() );

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$gallery = $attachment_ids;

		$product->set_image_id( array_shift( $gallery ) );
		$product->set_gallery_image_ids( $gallery );

		/*
		 * Only a complete set stamps the hash. Recording the whole list as done after a
		 * partial download would retire the missing images for good: the next run finds
		 * the article unchanged and never asks for them again.
		 */
		if ( count( $attachment_ids ) === count( $files ) ) {
			$product->update_meta_data( self::META_IMAGE_HASH, $hash );
		}

		$product->save();

		// After the save, so an image kept from the previous set reads as in use.
		$this->discard_unused_images( array_diff( $previous, $attachment_ids ) );
	}

	/**
	 * Turn one product's image URLs into attachment IDs, in gallery order.
	 *
	 * The downloads are the only slow part and they are done together: measured
	 * against the live catalogue a sideload takes about 2.2 seconds, of which
	 * media_handle_sideload() — reading the file and building the seven thumbnail
	 * sizes — accounts for roughly 0.1. The other two seconds are spent waiting on
	 * Kontor's image host, which is time several images can wait through at once.
	 *
	 * The attaching is deliberately left serial. It is the CPU-bound tenth of the
	 * cost, so there is nothing to win, and WordPress's media handling is not written
	 * to be re-entered.
	 *
	 * One entry is returned per input URL, so a list naming the same file twice still
	 * counts as complete. Anything that failed is simply absent, which is what stops
	 * import_images() stamping the hash on a partial set.
	 *
	 * @param string[] $urls       Absolute image URLs, in gallery order.
	 * @param int      $product_id Product to attach them to.
	 * @param string   $sku        Article number, for the log.
	 * @return int[] Attachment IDs.
	 */
	protected function resolve_images( array $urls, $product_id, $sku ) {
		$attachments = array();
		$pending     = array();

		foreach ( $urls as $url ) {
			$attachment_id = $this->attachment_for_source( $url );

			// Already in the library, shared with some other article. No HTTP at all.
			if ( $attachment_id ) {
				$attachments[ $url ] = $attachment_id;

				continue;
			}

			$pending[ $url ] = $url;
		}

		foreach ( $this->download( array_values( $pending ) ) as $url => $file ) {
			if ( is_wp_error( $file ) ) {
				$this->log(
					'warning',
					sprintf( 'Could not download image %s for article %s: %s', $url, $sku, $file->get_error_message() )
				);

				continue;
			}

			$attachment_id = $this->attach( $url, $file, $product_id );

			if ( is_wp_error( $attachment_id ) ) {
				$this->log(
					'warning',
					sprintf( 'Could not attach image %s to article %s: %s', $url, $sku, $attachment_id->get_error_message() )
				);

				continue;
			}

			$attachments[ $url ] = $attachment_id;
		}

		$ordered = array();

		foreach ( $urls as $url ) {
			if ( isset( $attachments[ $url ] ) ) {
				$ordered[] = (int) $attachments[ $url ];
			}
		}

		return $ordered;
	}

	/**
	 * Fetch images concurrently, into temporary files.
	 *
	 * WordPress has no way to do this: download_url() is one blocking request per
	 * call. Requests — the library WordPress itself is built on — exposes
	 * request_multiple(),
	 * which runs a batch over curl_multi and streams each response straight to disk.
	 * On a host with no curl extension the fsockopen transport answers the same call
	 * serially, so this degrades to today's behaviour rather than failing.
	 *
	 * Leaving wp_remote_get() behind means leaving the http_request_args filter behind
	 * with it. Two things are kept deliberately, because they are controls a site
	 * actually relies on rather than conveniences: WP_HTTP_BLOCK_EXTERNAL, and the
	 * pre_http_request short-circuit.
	 *
	 * @param string[] $urls URLs to fetch.
	 * @return array Map of URL to a temporary file path, or to a WP_Error.
	 */
	protected function download( array $urls ) {
		$results = array();

		if ( empty( $urls ) ) {
			return $results;
		}

		foreach ( array_chunk( $urls, $this->concurrency() ) as $batch ) {
			$results += $this->download_batch( $batch );
		}

		return $results;
	}

	/**
	 * Fetch one batch of images at the same time.
	 *
	 * @param string[] $urls URLs to fetch together.
	 * @return array Map of URL to a temporary file path, or to a WP_Error.
	 */
	protected function download_batch( array $urls ) {
		$results  = array();
		$requests = array();
		$targets  = array();

		foreach ( $urls as $url ) {
			$refused = $this->refuse_request( $url );

			if ( null !== $refused ) {
				$results[ $url ] = $refused;

				continue;
			}

			$temp = wp_tempnam( $url );

			if ( ! $temp ) {
				$results[ $url ] = new WP_Error( 'wksync_no_temp_file', 'Could not create a temporary file for the download.' );

				continue;
			}

			$targets[ $url ]  = $temp;
			$requests[ $url ] = array(
				'url'     => $url,
				'type'    => Requests::GET,
				'options' => array( 'filename' => $temp ),
			);
		}

		if ( empty( $requests ) ) {
			return $results;
		}

		try {
			$responses = Requests::request_multiple( $requests, $this->request_options() );
		} catch ( Exception $exception ) {
			// A batch that could not even be dispatched fails as a batch; each URL is
			// reported on its own so the caller does not have to know they shared one.
			foreach ( $targets as $url => $temp ) {
				wp_delete_file( $temp );

				$results[ $url ] = new WP_Error( 'wksync_image_download_failed', $exception->getMessage() );
			}

			return $results;
		}

		foreach ( $responses as $url => $response ) {
			$results[ $url ] = $this->interpret_download( $response, $targets[ $url ] );
		}

		return $results;
	}

	/**
	 * Decide whether one response left a usable file behind.
	 *
	 * Failures come back from request_multiple() as exception objects rather than
	 * being thrown, so a dead host arrives here as a value like any other. Either way
	 * the partial file is removed: curl streams whatever the host sent, including an
	 * error page, and a 404 body saved as a .jpg is worse than no file.
	 *
	 * @param object|\Exception $response Response or failure for one URL.
	 * @param string            $temp     Temporary file the download was streamed to.
	 * @return string|WP_Error The file path, or why it is unusable.
	 */
	protected function interpret_download( $response, $temp ) {
		if ( $response instanceof Exception ) {
			wp_delete_file( $temp );

			return new WP_Error( 'wksync_image_download_failed', $response->getMessage() );
		}

		$status = isset( $response->status_code ) ? (int) $response->status_code : 0;

		if ( 200 !== $status ) {
			wp_delete_file( $temp );

			/* translators: not user-facing; this is a log line. */
			return new WP_Error( 'wksync_image_download_failed', sprintf( 'The image host answered HTTP %d.', $status ) );
		}

		return $temp;
	}

	/**
	 * Whether WordPress would have refused to make this request at all.
	 *
	 * Both checks belong to the site rather than to the HTTP library, which is why
	 * they survive the move off wp_remote_get(): WP_HTTP_BLOCK_EXTERNAL is how a site
	 * states that outbound requests are not allowed, and pre_http_request is how
	 * anything from a test to a firewall plugin intercepts one.
	 *
	 * A filter answering with a *response* rather than an error is treated as a
	 * refusal too. The filter's contract is a response in memory, and what is needed
	 * here is bytes on disk; core has the same gap, and download_url() under such a
	 * filter hands back an empty file that fails as "not an image" two steps later.
	 * Saying so here is the same outcome with a reason attached.
	 *
	 * @param string $url URL about to be fetched.
	 * @return WP_Error|null The refusal, or null when the request may proceed.
	 */
	protected function refuse_request( $url ) {
		$args = array(
			'method'     => 'GET',
			'timeout'    => self::IMAGE_TIMEOUT,
			'stream'     => true,
			'user-agent' => $this->user_agent(),
		);

		if ( _wp_http_get_object()->block_request( $url ) ) {
			return new WP_Error( 'http_request_not_executed', 'This site blocks outbound HTTP requests to that host.' );
		}

		/** This filter is documented in wp-includes/class-wp-http.php */
		$pre = apply_filters( 'pre_http_request', false, $args, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own hook, re-applied deliberately so leaving wp_remote_get() does not take the site's interception point with it.

		if ( false === $pre ) {
			return null;
		}

		return is_wp_error( $pre )
			? $pre
			: new WP_Error( 'wksync_image_download_intercepted', 'A filter answered the request in place of the image host.' );
	}

	/**
	 * Options every image request is made with.
	 *
	 * Certificates are WordPress's own bundle, named explicitly rather than left to
	 * the copy inside the Requests package, so verification matches every other
	 * request the site makes.
	 *
	 * @return array Requests options.
	 */
	protected function request_options() {
		return array(
			'timeout'   => self::IMAGE_TIMEOUT,
			'useragent' => $this->user_agent(),
			'redirects' => 5,
			'verify'    => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		);
	}

	/**
	 * How many images are fetched at once.
	 *
	 * Bounded at both ends whatever the filter says. One is the serial behaviour this
	 * replaced; the ceiling is politeness, since the host on the other end belongs to
	 * somebody else and a page of articles opening thirty connections at once is
	 * indistinguishable from an attack.
	 *
	 * @return int Number of simultaneous downloads.
	 */
	protected function concurrency() {
		/**
		 * Filters how many of a product's images are downloaded at the same time.
		 *
		 * @since 0.9.0
		 *
		 * @param int $concurrency Simultaneous downloads, clamped to 1..8.
		 */
		$concurrency = (int) apply_filters( 'woo_kontor_sync_image_concurrency', self::IMAGE_CONCURRENCY );

		return max( 1, min( 8, $concurrency ) );
	}

	/**
	 * How this plugin identifies itself to the image host.
	 *
	 * @return string User agent string.
	 */
	protected function user_agent() {
		return 'WooKontorSyncPro/' . WKSYNC_VERSION . '; ' . home_url( '/' );
	}

	/**
	 * Move a downloaded file into the media library.
	 *
	 * @param string $url        URL it came from, recorded for deduplication.
	 * @param string $file       Temporary file holding the image.
	 * @param int    $product_id Product to attach it to.
	 * @return int|WP_Error Attachment ID, or why the file was rejected.
	 */
	protected function attach( $url, $file, $product_id ) {
		$file_array = array(
			'name'     => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $file,
		);

		$attachment_id = media_handle_sideload( $file_array, $product_id );

		// A successful sideload moves the file; a rejected one leaves it with us.
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $file );

			return $attachment_id;
		}

		update_post_meta( (int) $attachment_id, self::META_IMAGE_SOURCE, $url );

		return (int) $attachment_id;
	}

	/**
	 * The image filenames an article carries, in gallery order.
	 *
	 * Kontor returns bare filenames rather than URLs, so they are only usable once an
	 * image base URL has been configured; without one there is nothing to fetch.
	 *
	 * @param array $row Article row from the API.
	 * @return string[] Filenames, empty when there are none or no base URL is set.
	 */
	protected function image_files( array $row ) {
		$base = isset( $this->settings['image_base_url'] ) ? trim( (string) $this->settings['image_base_url'] ) : '';

		if ( '' === $base ) {
			return array();
		}

		$files = array();

		foreach ( $this->all_image_keys() as $key ) {
			$file = $this->text( $row, $key, '' );

			if ( '' !== $file ) {
				$files[] = $file;
			}
		}

		return $files;
	}

	/**
	 * Hash a filename list, so an unchanged set is recognised without downloading it.
	 *
	 * @param array $files Image filenames.
	 * @return string Hash of the list.
	 */
	protected function image_hash( array $files ) {
		return md5( implode( '|', $files ) );
	}

	/**
	 * The attachments a product currently uses, featured image first.
	 *
	 * @param WC_Product_Simple $product Product to read.
	 * @return int[] Attachment IDs.
	 */
	protected function attached_image_ids( $product ) {
		$ids = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );

		return array_values( array_filter( $ids ) );
	}

	/**
	 * Find an image this plugin has already downloaded from a URL.
	 *
	 * @param string $url Source URL.
	 * @return int Attachment ID, or 0 when the file has not been imported yet.
	 */
	protected function attachment_for_source( $url ) {
		$existing = get_posts(
			array(
				'post_type'        => 'attachment',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The source URL only exists in meta, and this runs in a background job.
				'meta_key'         => self::META_IMAGE_SOURCE,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'meta_value'       => $url,
			)
		);

		return empty( $existing ) ? 0 : (int) $existing[0];
	}

	/**
	 * Delete images this plugin imported that nothing uses any more.
	 *
	 * Two conditions, both required. The attachment has to carry our source meta, so
	 * media the shop uploaded is never touched. And it has to be referenced by no
	 * product at all, because deduplication means one file can be the featured image
	 * of one article and a gallery entry of another — deleting on "this product
	 * dropped it" alone would tear the image out of every other product using it.
	 *
	 * @param int[] $attachment_ids Attachments the product no longer uses.
	 * @return void
	 */
	protected function discard_unused_images( array $attachment_ids ) {
		foreach ( $attachment_ids as $attachment_id ) {
			if ( '' === (string) get_post_meta( $attachment_id, self::META_IMAGE_SOURCE, true ) ) {
				continue;
			}

			if ( $this->image_in_use( $attachment_id ) ) {
				continue;
			}

			wp_delete_attachment( $attachment_id, true );
		}
	}

	/**
	 * Whether any product still points at an attachment.
	 *
	 * The gallery is stored as a comma-separated list, so it is matched with
	 * FIND_IN_SET rather than LIKE: LIKE '%12%' also matches the gallery "123", and
	 * the image that comparison protects is not the one being asked about.
	 *
	 * @param int $attachment_id Attachment to check.
	 * @return bool True when a product references it.
	 */
	protected function image_in_use( $attachment_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No API answers "which products use this attachment"; the result is a deletion decision and must not be cached.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta}
				WHERE ( meta_key = '_thumbnail_id' AND meta_value = %d )
				OR ( meta_key = '_product_image_gallery' AND FIND_IN_SET( %d, meta_value ) )",
				$attachment_id,
				$attachment_id
			)
		);

		return (int) $count > 0;
	}

	/**
	 * Every image field name Kontor returns, in gallery order.
	 *
	 * The first one holding a filename becomes the featured image, so the order here is
	 * what decides which photograph the shop front shows.
	 *
	 * @return string[] Field names.
	 */
	protected function all_image_keys() {
		return array_merge( array( 'MainImageURL' ), $this->image_keys() );
	}

	/**
	 * The gallery image field names Kontor returns.
	 *
	 * @return string[] Field names.
	 */
	protected function image_keys() {
		$keys = array();

		for ( $index = 1; $index <= 9; $index++ ) {
			$keys[] = 'ImageURL_' . $index;
		}

		return $keys;
	}

	/**
	 * Read a field from an article row, treating null as absent.
	 *
	 * @param array  $row      Article row.
	 * @param string $key      Field name.
	 * @param string $fallback Value to use when the field is missing or null.
	 * @return string Field value as a string.
	 */
	protected function text( array $row, $key, $fallback ) {
		if ( ! isset( $row[ $key ] ) || null === $row[ $key ] ) {
			return $fallback;
		}

		return is_scalar( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : $fallback;
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
