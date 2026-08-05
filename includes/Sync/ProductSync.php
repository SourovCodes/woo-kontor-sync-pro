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
 * Action Scheduler actions: one action per page, then a finalising pass. Products
 * are matched on SKU, which is Kontor's article number (Artnr).
 */
class ProductSync {

	/**
	 * Job key used for status reporting.
	 */
	const JOB = 'products';

	/**
	 * Meta holding Kontor's central article number.
	 */
	const META_KONTOR_ID = '_wksync_kontor_id';

	/**
	 * Meta holding a hash of the last payload seen for this article.
	 */
	const META_HASH = '_wksync_sync_hash';

	/**
	 * Meta holding the run that last saw this article.
	 */
	const META_SYNCED_AT = '_wksync_synced_at';

	/**
	 * Meta holding Kontor's purchase price, which is not the selling price.
	 */
	const META_COST = '_wksync_cost';

	/**
	 * Meta holding the manufacturer part number.
	 */
	const META_MPN = '_wksync_mpn';

	/**
	 * Meta holding the manufacturer name.
	 */
	const META_MANUFACTURER = '_wksync_manufacturer';

	/**
	 * Meta holding a hash of the image filenames last sideloaded.
	 */
	const META_IMAGE_HASH = '_wksync_image_hash';

	/**
	 * Marks a product that this sync drafted, rather than a person.
	 */
	const META_SYNC_DRAFTED = '_wksync_drafted_by_sync';

	/**
	 * How many stale products to draft per finalising pass.
	 */
	const FINALISE_BATCH = 200;

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
		$response = $this->client->fetch_products( $skip, Client::PRODUCT_PAGE_SIZE );

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
		$stale = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => 'publish',
				'posts_per_page'   => self::FINALISE_BATCH,
				'fields'           => 'ids',
				'suppress_filters' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Runs in a background job; there is no other way to find products this run did not stamp.
				'meta_query'       => array(
					array(
						'key'     => self::META_KONTOR_ID,
						'compare' => 'EXISTS',
					),
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

		$hash       = md5( (string) wp_json_encode( $row ) );
		$product_id = wc_get_product_id_by_sku( $sku );
		$existing   = $product_id ? wc_get_product( $product_id ) : null;
		$restored   = $existing ? $this->restore_if_sync_drafted( $existing ) : false;

		// Nothing changed since the last run, so only refresh the run stamp. Writing
		// just the meta avoids a full product save for every unchanged article.
		if ( $existing && ! $restored && $hash === (string) $existing->get_meta( self::META_HASH ) ) {
			$existing->update_meta_data( self::META_SYNCED_AT, $run );
			$existing->save_meta_data();

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

		$product->update_meta_data( self::META_KONTOR_ID, $this->text( $row, 'Artzentralnr', $sku ) );
		$product->update_meta_data( self::META_HASH, $hash );
		$product->update_meta_data( self::META_SYNCED_AT, $run );
		$product->update_meta_data( self::META_COST, wc_format_decimal( $this->text( $row, 'Ek', '' ) ) );
		$product->update_meta_data( self::META_MPN, $this->text( $row, 'Mpn', '' ) );
		$product->update_meta_data( self::META_MANUFACTURER, $this->text( $row, 'Hersteller', '' ) );

		$saved_id = $product->save();

		if ( ! $saved_id ) {
			$this->log( 'error', sprintf( 'Could not save product for article %s.', $sku ) );

			return 'failed';
		}

		$this->sideload_images( $product, $row );

		return $is_create ? 'created' : 'updated';
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
		 * UVP is the selling price for the configured shop type: the same article
		 * comes back at 22.50 for B2B, 45.00 for B2C and 36.00 for EDU, while Ek
		 * stays constant. Ek is the purchase price and is kept as meta only.
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
	 * Sideload the article's images into the media library.
	 *
	 * Kontor returns bare filenames rather than URLs, so this only runs when an
	 * image base URL has been configured. The filename list is hashed so unchanged
	 * images are never downloaded twice.
	 *
	 * @param WC_Product_Simple $product Product to attach images to.
	 * @param array             $row     Article row from the API.
	 * @return void
	 */
	protected function sideload_images( $product, array $row ) {
		$base = isset( $this->settings['image_base_url'] ) ? trim( (string) $this->settings['image_base_url'] ) : '';

		if ( '' === $base ) {
			return;
		}

		$files = array();

		foreach ( array_merge( array( 'MainImageURL' ), $this->image_keys() ) as $key ) {
			$file = $this->text( $row, $key, '' );

			if ( '' !== $file ) {
				$files[] = $file;
			}
		}

		if ( empty( $files ) ) {
			return;
		}

		$hash = md5( implode( '|', $files ) );

		if ( $hash === (string) $product->get_meta( self::META_IMAGE_HASH ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_ids = array();

		foreach ( $files as $file ) {
			$attachment_id = media_sideload_image( trailingslashit( $base ) . ltrim( $file, '/' ), $product->get_id(), null, 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				$this->log(
					'warning',
					sprintf( 'Could not sideload image %s for article %s: %s', $file, $product->get_sku(), $attachment_id->get_error_message() )
				);

				continue;
			}

			$attachment_ids[] = (int) $attachment_id;
		}

		if ( empty( $attachment_ids ) ) {
			return;
		}

		$product->set_image_id( array_shift( $attachment_ids ) );
		$product->set_gallery_image_ids( $attachment_ids );
		$product->update_meta_data( self::META_IMAGE_HASH, $hash );
		$product->save();
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
