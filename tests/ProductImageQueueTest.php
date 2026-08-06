<?php
/**
 * Tests for image downloads running in their own chained action.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WP_Error;
use WP_UnitTestCase;

/**
 * Covers what reaches the image queue and what a partial download records.
 *
 * Downloads are never performed for real: an image already in the library is
 * matched on its source URL and needs no HTTP at all, and a failure is produced by
 * short-circuiting the request. What is worth pinning down is which images are
 * queued and whether the set is recorded as complete, not WordPress's ability to
 * fetch a file.
 */
class ProductImageQueueTest extends WP_UnitTestCase {

	/**
	 * Base URL the test settings point at.
	 */
	const BASE = 'https://images.example.test/';

	/**
	 * Remove the HTTP short-circuit and anything left queued.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );
		}

		parent::tear_down();
	}

	/**
	 * A sync configured with an image base URL.
	 *
	 * @param string $base Base URL, or an empty string for none.
	 * @return ProductSync Sync instance.
	 */
	private function sync( $base = self::BASE ) {
		return new ProductSync( null, array( 'image_base_url' => $base ) );
	}

	/**
	 * An article row carrying the given image filenames.
	 *
	 * @param array $files Image filenames.
	 * @return array Article row.
	 */
	private function article( array $files ) {
		$row = array(
			'Artnr' => 'abel-AB12',
			'Bez1'  => 'Abel blocks 12',
			'UVP'   => 81.9000,
		);

		$keys = array( 'MainImageURL', 'ImageURL_1', 'ImageURL_2' );

		foreach ( $files as $index => $file ) {
			$row[ $keys[ $index ] ] = $file;
		}

		return $row;
	}

	/**
	 * An attachment stamped as one this plugin already downloaded.
	 *
	 * @param string $file Filename, relative to the base URL.
	 * @return int Attachment ID.
	 */
	private function existing_image( $file ) {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => $file,
				'post_mime_type' => 'image/jpeg',
			)
		);

		update_post_meta( $attachment_id, ProductSync::META_IMAGE_SOURCE, self::BASE . $file );

		return (int) $attachment_id;
	}

	/**
	 * Refuse every outbound HTTP request, as an unreachable image host would.
	 *
	 * @return void
	 */
	private function block_downloads() {
		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);
	}

	/**
	 * Whether an image download is queued.
	 *
	 * @return bool True when at least one action is pending.
	 */
	private function is_queued() {
		return as_has_scheduled_action( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );
	}

	/**
	 * An article with images queues a download rather than fetching inline.
	 *
	 * This is the whole point of the split: the page action has to come back quickly
	 * whatever the image host is doing.
	 *
	 * @return void
	 */
	public function test_article_with_images_queues_a_download() {
		$run = Status::start( ProductSync::JOB );

		$this->assertSame( 'created', $this->sync()->import_article( $this->article( array( 'abel-AB12_001.jpg' ) ), $run ) );
		$this->assertTrue( $this->is_queued() );
	}

	/**
	 * An article with no images queues nothing.
	 *
	 * @return void
	 */
	public function test_article_without_images_queues_nothing() {
		$run = Status::start( ProductSync::JOB );

		$this->sync()->import_article( $this->article( array() ), $run );

		$this->assertFalse( $this->is_queued() );
	}

	/**
	 * With no base URL configured the filenames are unusable, so nothing is queued.
	 *
	 * @return void
	 */
	public function test_no_base_url_queues_nothing() {
		$run = Status::start( ProductSync::JOB );

		$this->sync( '' )->import_article( $this->article( array( 'abel-AB12_001.jpg' ) ), $run );

		$this->assertFalse( $this->is_queued() );
	}

	/**
	 * An unchanged article whose images never arrived is queued again.
	 *
	 * The skip path returns before anything else happens, so without this an image
	 * set that failed once would stay incomplete until the article itself changed —
	 * which for a stable product is never.
	 *
	 * @return void
	 */
	public function test_unchanged_article_requeues_an_incomplete_image_set() {
		$run  = Status::start( ProductSync::JOB );
		$sync = $this->sync();
		$row  = $this->article( array( 'abel-AB12_001.jpg' ) );

		$this->assertSame( 'created', $sync->import_article( $row, $run ) );

		as_unschedule_all_actions( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );
		$this->assertFalse( $this->is_queued() );

		// Nothing about the article changed, but the images are still missing.
		$this->assertSame( 'skipped', $sync->import_article( $row, $run ) );
		$this->assertTrue( $this->is_queued() );
	}

	/**
	 * An unchanged article whose images are all present queues nothing.
	 *
	 * @return void
	 */
	public function test_unchanged_article_with_complete_images_queues_nothing() {
		$run      = Status::start( ProductSync::JOB );
		$sync     = $this->sync();
		$file     = 'abel-AB12_001.jpg';
		$row      = $this->article( array( $file ) );
		$existing = $this->existing_image( $file );

		$sync->import_article( $row, $run );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );
		$sync->import_images( $product->get_id(), array( $file ), $run );

		as_unschedule_all_actions( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );

		$this->assertSame( 'skipped', $sync->import_article( $row, $run ) );
		$this->assertFalse( $this->is_queued() );
		$this->assertSame( $existing, (int) wc_get_product( $product->get_id() )->get_image_id() );
	}

	/**
	 * A complete set of images records the hash, so it is never fetched again.
	 *
	 * @return void
	 */
	public function test_complete_download_stamps_the_hash() {
		$run    = Status::start( ProductSync::JOB );
		$files  = array( 'abel-AB12_001.jpg', 'abel-AB12_002.jpg' );
		$first  = $this->existing_image( $files[0] );
		$second = $this->existing_image( $files[1] );

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product_id = $product->save();

		$this->sync()->import_images( $product_id, $files, $run );

		$saved = wc_get_product( $product_id );

		$this->assertSame( $first, (int) $saved->get_image_id() );
		$this->assertSame( array( $second ), array_map( 'intval', $saved->get_gallery_image_ids() ) );
		$this->assertNotSame( '', (string) $saved->get_meta( ProductSync::META_IMAGE_HASH ) );
	}

	/**
	 * A partial download leaves the hash unset so the rest is retried.
	 *
	 * Recording the whole list as done after fetching half of it would retire the
	 * missing images permanently: the next run finds the article unchanged and never
	 * asks for them again.
	 *
	 * @return void
	 */
	public function test_partial_download_does_not_stamp_the_hash() {
		$run   = Status::start( ProductSync::JOB );
		$files = array( 'abel-AB12_001.jpg', 'abel-AB12_002.jpg' );
		$first = $this->existing_image( $files[0] );

		// The second file is not in the library, so it needs a download that will fail.
		$this->block_downloads();

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product_id = $product->save();

		$this->sync()->import_images( $product_id, $files, $run );

		$saved = wc_get_product( $product_id );

		$this->assertSame( $first, (int) $saved->get_image_id(), 'The image that was available should still be attached.' );
		$this->assertSame( '', (string) $saved->get_meta( ProductSync::META_IMAGE_HASH ), 'An incomplete set must not be recorded as done.' );
	}

	/**
	 * Images queued by a superseded run are discarded.
	 *
	 * @return void
	 */
	public function test_superseded_run_is_discarded() {
		$current = Status::start( ProductSync::JOB );
		$file    = 'abel-AB12_001.jpg';

		$this->existing_image( $file );

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product_id = $product->save();

		/*
		 * An identifier from before the current run, which is what a queued action left
		 * over from an earlier pass carries. Started runs are stamped with time(), so
		 * calling start() twice here would not reliably produce two different ones.
		 */
		$this->sync()->import_images( $product_id, array( $file ), $current - 1 );

		$this->assertSame( 0, (int) wc_get_product( $product_id )->get_image_id() );
	}

	/**
	 * Images queued by a run that has finished are still wanted.
	 *
	 * Status::finish() leaves the run stamp alone precisely so the tail of image
	 * actions outlives the page walk that queued them.
	 *
	 * @return void
	 */
	public function test_finished_run_still_imports_its_images() {
		$run  = Status::start( ProductSync::JOB );
		$file = 'abel-AB12_001.jpg';

		$existing = $this->existing_image( $file );

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product_id = $product->save();

		Status::finish( ProductSync::JOB, 'done' );

		$this->sync()->import_images( $product_id, array( $file ), $run );

		$this->assertSame( $existing, (int) wc_get_product( $product_id )->get_image_id() );
	}
}
