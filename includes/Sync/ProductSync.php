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
	 * Fields this sync actually consumes.
	 *
	 * The change hash is built from these alone, so churn in fields we deliberately
	 * ignore — Ek and Categories — cannot trigger a pointless rewrite of every
	 * product. Ek is the purchase price and is not imported, and the Categories GUIDs
	 * cannot be resolved, because Kontor's categories entity returns no rows.
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
	);

	/**
	 * Meta holding the manufacturer part number.
	 */
	const META_MPN = '_wksync_mpn';

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

		$response = $this->client->fetch_products( $skip, Client::PRODUCT_PAGE_SIZE, null, $this->manufacturers() );

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
			'duplicate_sku' => 0,
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
				__( 'Passed over %d with no image.', 'woo-kontor-sync-pro' ),
				$no_image
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

			return 'duplicate_sku';
		}

		$product_id = empty( $matches ) ? 0 : $matches[0];
		$existing   = $product_id ? wc_get_product( $product_id ) : null;

		/*
		 * Checked before the unchanged-article shortcut below, not after. An article
		 * that has not moved since the last run is exactly the case this has to catch:
		 * turning the requirement on has to take effect on the next run rather than
		 * waiting for every article in the shop to change.
		 */
		if ( $this->image_withheld( $row ) ) {
			return $this->withhold( $existing, $sku, $run );
		}

		$hash     = $this->hash( $row );
		$restored = $existing ? $this->restore_if_sync_drafted( $existing ) : false;

		// Nothing changed since the last run, so only refresh the run stamp. Writing
		// just the meta avoids a full product save for every unchanged article.
		if ( $existing && ! $restored && $hash === (string) $existing->get_meta( self::META_HASH ) ) {
			$existing->update_meta_data( self::META_SYNCED_AT, $run );
			$existing->save_meta_data();

			/*
			 * Images are downloaded in their own action and can fail on their own, so an
			 * article that has not changed still has to be offered to the image queue.
			 * Without this, a set that never completed would stay incomplete until the
			 * article itself changed, which for a stable product is never.
			 */
			$this->queue_images( $existing->get_id(), $row, $run );

			return 'skipped';
		}

		$product   = $existing ? $existing : new WC_Product_Simple();
		$is_create = ! $existing;

		if ( $is_create ) {
			$product->set_sku( $sku );

			// New articles go live; anything Kontor drops is drafted in finalise().
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
		}

		$this->apply_fields( $product, $row );

		$product->update_meta_data( self::META_HASH, $hash );
		$product->update_meta_data( self::META_SYNCED_AT, $run );
		$product->update_meta_data( self::META_MPN, $this->text( $row, 'Mpn', '' ) );

		$saved_id = $product->save();

		if ( ! $saved_id ) {
			$this->log( 'error', sprintf( 'Could not save product for article %s.', $sku ) );

			return 'failed';
		}

		Brands::assign( $saved_id, $this->text( $row, 'Hersteller', '' ), $this->text( $row, 'Herstellerid', '' ) );

		$this->queue_images( $saved_id, $row, $run );

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
	 * @return bool True when the article must not be imported.
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
	 * Pass over an imageless article, drafting anything already imported for it.
	 *
	 * Drafted rather than deleted, matching every other reason this plugin takes a
	 * product out of the shop: an article that gains a picture tomorrow comes straight
	 * back, and nothing a shop has built around the product — its URL, its reviews, its
	 * place in an order — is destroyed by a feed that was briefly incomplete.
	 *
	 * Only products this plugin imported are touched, which META_SYNCED_AT is the
	 * marker for. A shop manager's own product answering to the same article number was
	 * never ours to unpublish, and drafting it would be this setting reaching past the
	 * catalogue it governs.
	 *
	 * @param \WC_Product|null $existing Product already holding the article number.
	 * @param string           $sku      Article number, for the log.
	 * @param int              $run      Run identifier.
	 * @return string Always "no_image".
	 */
	protected function withhold( $existing, $sku, $run ) {
		if ( ! $existing || '' === (string) $existing->get_meta( self::META_SYNCED_AT ) ) {
			return 'no_image';
		}

		// The article is in the feed, whatever is being done about it, and finalise()
		// must not later read a missing stamp as Kontor having dropped it.
		$existing->update_meta_data( self::META_SYNCED_AT, $run );

		$status = $existing->get_status();

		/*
		 * Published and draft are the two states this sync owns. Anything else — private,
		 * pending, a status another plugin registered — is a decision somebody made about
		 * the product, and marking it would hand a later run the right to publish it.
		 */
		if ( 'publish' !== $status && 'draft' !== $status ) {
			$existing->save_meta_data();

			return 'no_image';
		}

		$existing->update_meta_data( self::META_NO_IMAGE_DRAFTED, 1 );

		/*
		 * A product already drafted is marked but not logged again. The marker still has
		 * to go on: without it, the sync that drafted it for its own reason would clear
		 * that reason and republish an article this one is still holding back.
		 */
		if ( 'publish' === $status ) {
			$existing->set_status( 'draft' );

			$this->log( 'info', sprintf( 'Drafted article %s: Kontor lists no image for it.', $sku ) );
		}

		$existing->save();

		return 'no_image';
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
	 * Hash the fields this sync maps, so unchanged articles can be skipped.
	 *
	 * Deliberately not a hash of the whole row: Ek moves independently of the
	 * selling price and is not imported, so hashing it would rewrite the entire
	 * catalogue whenever purchase prices shifted.
	 *
	 * @param array $row Article row from the API.
	 * @return string Hash of the mapped fields.
	 */
	protected function hash( array $row ) {
		$mapped = array();

		foreach ( array_merge( self::$mapped_fields, $this->image_keys() ) as $field ) {
			$mapped[ $field ] = isset( $row[ $field ] ) ? $row[ $field ] : null;
		}

		return md5( (string) wp_json_encode( $mapped ) );
	}

	/**
	 * Republish a product that an earlier run of this sync drafted.
	 *
	 * An article can leave the feed and come back, and one Kontor listed no picture for
	 * can gain one. Without this, either drafts the product once and it stays hidden
	 * forever even though the reason has gone.
	 *
	 * Only drafts this plugin created are restored: the marker meta is what
	 * distinguishes them from a product a shop manager deliberately unpublished,
	 * which must be left alone.
	 *
	 * Both of this sync's reasons are cleared here, because reaching this method means
	 * both have gone: the article is in the feed, and import_article() has already
	 * turned back anything still imageless. The stock sync's marker is a different
	 * feed's verdict and is left alone — the catalogue listing an article again says
	 * nothing about whether there is any stock of it, so the product comes back only
	 * when the last marker goes.
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

		if ( empty( $reasons ) ) {
			return false;
		}

		if ( $product->get_meta( StockSync::META_STOCK_DRAFTED ) ) {
			$this->log(
				'info',
				sprintf( 'Article %s is importable again, but has no stock level; leaving it drafted.', $product->get_sku() )
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
		 * UVP is the product price. It is the selling price for the configured shop
		 * type: the same article comes back at 22.50 for B2B, 45.00 for B2C and 36.00
		 * for EDU. Ek is the purchase price and is deliberately not imported.
		 */
		$price = wc_format_decimal( $this->text( $row, 'UVP', '' ) );

		if ( '' !== $price ) {
			$product->set_regular_price( $price );
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( (int) round( (float) $this->text( $row, 'Lagerbestand', '0' ) ) );

		$weight = (float) $this->text( $row, 'Gewnetto', '0' );

		if ( $weight > 0 ) {
			$product->set_weight( wc_format_decimal( $weight ) );
		}

		$this->apply_ean( $product, $this->text( $row, 'Artean', '' ) );
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
