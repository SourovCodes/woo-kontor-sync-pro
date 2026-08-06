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

defined( 'ABSPATH' ) || exit;

/**
 * Imports the Kontor article catalogue into WooCommerce products.
 *
 * The catalogue is several thousand articles, so a run is split across chained
 * Action Scheduler actions: one action per page, then a finalising pass.
 *
 * Kontor is the source of truth, and SKU — Kontor's article number, Artnr — is the
 * only key considered. An article with no Artnr is a failed row rather than
 * something to match on another field, and no second identifier is stored, so
 * there is nothing that could quietly become a competing key.
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
	 */
	const IMAGE_TIMEOUT = 20;

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

		$counts = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed'  => 0,
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

		Status::finish(
			self::JOB,
			sprintf(
				/* translators: 1: created count, 2: updated count, 3: unchanged count, 4: drafted count. */
				__( '%1$d created, %2$d updated, %3$d unchanged, %4$d drafted.', 'woo-kontor-sync-pro' ),
				isset( $counts['created'] ) ? (int) $counts['created'] : 0,
				isset( $counts['updated'] ) ? (int) $counts['updated'] : 0,
				isset( $counts['skipped'] ) ? (int) $counts['skipped'] : 0,
				isset( $counts['drafted'] ) ? (int) $counts['drafted'] : 0
			)
		);
	}

	/**
	 * Create or update a single WooCommerce product from a Kontor article.
	 *
	 * @param array $row Article row from the API.
	 * @param int   $run Run identifier.
	 * @return string One of "created", "updated", "skipped" or "failed".
	 */
	public function import_article( array $row, $run ) {
		$sku = isset( $row['Artnr'] ) ? trim( (string) $row['Artnr'] ) : '';

		if ( '' === $sku ) {
			return 'failed';
		}

		$hash       = $this->hash( $row );
		$product_id = wc_get_product_id_by_sku( $sku );
		$existing   = $product_id ? wc_get_product( $product_id ) : null;
		$restored   = $existing ? $this->restore_if_sync_drafted( $existing ) : false;

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
	 * An article can leave the feed and come back. Without this, finalise() drafts
	 * it once and it stays hidden forever even though Kontor lists it again.
	 *
	 * Only drafts this plugin created are restored: the marker meta is what
	 * distinguishes them from a product a shop manager deliberately unpublished,
	 * which must be left alone.
	 *
	 * @param WC_Product_Simple $product Existing product.
	 * @return bool True when the product was restored and therefore needs saving.
	 */
	protected function restore_if_sync_drafted( $product ) {
		if ( 'draft' !== $product->get_status() || ! $product->get_meta( self::META_SYNC_DRAFTED ) ) {
			return false;
		}

		$product->set_status( 'publish' );
		$product->delete_meta_data( self::META_SYNC_DRAFTED );

		$this->log( 'info', sprintf( 'Republished article %s: it is listed by Kontor again.', $product->get_sku() ) );

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
			)
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

		$previous       = $this->attached_image_ids( $product );
		$attachment_ids = array();

		foreach ( $files as $file ) {
			$url           = trailingslashit( $base ) . ltrim( (string) $file, '/' );
			$attachment_id = $this->attachment_for_source( $url );

			if ( ! $attachment_id ) {
				$attachment_id = $this->sideload( $url, (int) $product_id );

				if ( is_wp_error( $attachment_id ) ) {
					$this->log(
						'warning',
						sprintf( 'Could not sideload image %s for article %s: %s', $file, $product->get_sku(), $attachment_id->get_error_message() )
					);

					continue;
				}

				update_post_meta( (int) $attachment_id, self::META_IMAGE_SOURCE, $url );
			}

			$attachment_ids[] = (int) $attachment_id;
		}

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
	 * Download one image and attach it to a product.
	 *
	 * WordPress has media_sideload_image() for this, but it calls download_url() with
	 * a default of 300 seconds and exposes no way to shorten it — the
	 * http_request_timeout filter cannot help, because download_url() passes the
	 * timeout explicitly and an explicit argument beats the filtered default. Running
	 * the two halves here is what makes IMAGE_TIMEOUT possible.
	 *
	 * @param string $url        Absolute URL of the image.
	 * @param int    $product_id Product to attach it to.
	 * @return int|\WP_Error Attachment ID, or WP_Error when the download or the file was rejected.
	 */
	protected function sideload( $url, $product_id ) {
		$temp = download_url( $url, self::IMAGE_TIMEOUT );

		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		$file_array = array(
			'name'     => basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ),
			'tmp_name' => $temp,
		);

		$attachment_id = media_handle_sideload( $file_array, $product_id );

		// A successful sideload moves the file; a rejected one leaves it with us.
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $temp );
		}

		return $attachment_id;
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

		foreach ( array_merge( array( 'MainImageURL' ), $this->image_keys() ) as $key ) {
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
