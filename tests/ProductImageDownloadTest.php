<?php
/**
 * Tests for fetching a product's images concurrently.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use Exception;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_Error;
use WP_UnitTestCase;

require_once __DIR__ . '/doubles/SpyProductSync.php';

/**
 * Covers the batching, the ordering and what happens when a download fails.
 *
 * Nothing here goes near the network. What is worth pinning down is that the images
 * end up in the order the feed gave them whatever order they arrived in, that a
 * failure removes one image rather than shifting the rest, and that the two HTTP
 * controls kept from wp_remote_get() still refuse a request.
 */
class ProductImageDownloadTest extends WP_UnitTestCase {

	/**
	 * Base URL the test settings point at.
	 */
	const BASE = 'https://images.example.test/';

	/**
	 * Temporary files created for a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $temporary = array();

	/**
	 * Delete anything a test left on disk.
	 *
	 * @return void
	 */
	public function tear_down() {
		foreach ( $this->temporary as $file ) {
			if ( file_exists( $file ) ) {
				wp_delete_file( $file );
			}
		}

		$this->temporary = array();

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woo_kontor_sync_image_concurrency' );

		parent::tear_down();
	}

	/**
	 * A sync whose downloads are answered from a script rather than a host.
	 *
	 * @param array $canned Map of URL to a temporary file path or a WP_Error.
	 * @return SpyProductSync Sync instance.
	 */
	private function sync( array $canned = array() ) {
		$sync         = new SpyProductSync( null, array( 'image_base_url' => self::BASE ) );
		$sync->canned = $canned;

		return $sync;
	}

	/**
	 * A real JPEG in a temporary file, as a completed download would leave behind.
	 *
	 * Generated rather than committed: media_handle_sideload() has to accept it as an
	 * image, and a fixture would be a binary in the repository for no other reason.
	 *
	 * @return string Path to the file.
	 */
	private function downloaded_image() {
		$image = imagecreatetruecolor( 20, 20 );
		imagefilledrectangle( $image, 0, 0, 20, 20, imagecolorallocate( $image, 120, 60, 30 ) );

		$file = wp_tempnam( 'kontor-image.jpg' );
		imagejpeg( $image, $file );

		$this->temporary[] = $file;

		return $file;
	}

	/**
	 * A product to hang the images off.
	 *
	 * @return WC_Product_Simple Saved product.
	 */
	private function product() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_regular_price( '10.00' );
		$product->save();

		return $product;
	}

	/**
	 * Images are fetched several at a time rather than one after another.
	 *
	 * @return void
	 */
	public function test_downloads_are_batched() {
		$urls = array();

		for ( $index = 1; $index <= 9; $index++ ) {
			$urls[] = self::BASE . 'abel-AB12_00' . $index . '.jpg';
		}

		$sync = $this->sync();
		$sync->run_download( $urls );

		// Nine images at four at a time: 4, 4, 1 — not nine separate requests.
		$this->assertSame( array( 4, 4, 1 ), array_map( 'count', $sync->batches ) );
	}

	/**
	 * The batch size is filterable, and clamped whatever the filter says.
	 *
	 * The ceiling is politeness: the host on the other end belongs to somebody else.
	 *
	 * @return void
	 */
	public function test_concurrency_is_clamped() {
		$sync = $this->sync();

		$this->assertSame( 4, $sync->batch_size() );

		add_filter( 'woo_kontor_sync_image_concurrency', static fn() => 2 );
		$this->assertSame( 2, $sync->batch_size() );
		remove_all_filters( 'woo_kontor_sync_image_concurrency' );

		add_filter( 'woo_kontor_sync_image_concurrency', static fn() => 500 );
		$this->assertSame( 8, $sync->batch_size() );
		remove_all_filters( 'woo_kontor_sync_image_concurrency' );

		add_filter( 'woo_kontor_sync_image_concurrency', static fn() => 0 );
		$this->assertSame( 1, $sync->batch_size() );
	}

	/**
	 * The gallery follows the feed's order, not the order the downloads finished in.
	 *
	 * Concurrency means the responses can arrive in any order at all, and the first
	 * image is the featured one — so an order taken from the replies would put an
	 * arbitrary photograph on the shop front.
	 *
	 * @return void
	 */
	public function test_gallery_order_follows_the_feed() {
		$product = $this->product();
		$files   = array( 'first.jpg', 'second.jpg', 'third.jpg' );

		$canned = array();

		// Answered back to front, as a slow first image and a fast last one would.
		foreach ( array_reverse( $files ) as $file ) {
			$canned[ self::BASE . $file ] = $this->downloaded_image();
		}

		$sync = $this->sync( $canned );
		$sync->import_images( $product->get_id(), $files, $this->current_run() );

		$refreshed = wc_get_product( $product->get_id() );
		$gallery   = array_merge( array( $refreshed->get_image_id() ), $refreshed->get_gallery_image_ids() );

		$sources = array_map(
			static function ( $attachment_id ) {
				return (string) get_post_meta( (int) $attachment_id, ProductSync::META_IMAGE_SOURCE, true );
			},
			$gallery
		);

		$this->assertSame(
			array( self::BASE . 'first.jpg', self::BASE . 'second.jpg', self::BASE . 'third.jpg' ),
			$sources
		);
	}

	/**
	 * One image failing drops that image and leaves the rest in order.
	 *
	 * The set is then incomplete, so the hash is not stamped and the next run asks
	 * again — which is the behaviour the whole partial-download rule exists for.
	 *
	 * @return void
	 */
	public function test_a_failed_download_is_dropped_not_reordered() {
		$product = $this->product();
		$files   = array( 'first.jpg', 'second.jpg', 'third.jpg' );

		$sync = $this->sync(
			array(
				self::BASE . 'first.jpg'  => $this->downloaded_image(),
				self::BASE . 'second.jpg' => new WP_Error( 'wksync_image_download_failed', 'The image host answered HTTP 404.' ),
				self::BASE . 'third.jpg'  => $this->downloaded_image(),
			)
		);

		$sync->import_images( $product->get_id(), $files, $this->current_run() );

		$refreshed = wc_get_product( $product->get_id() );

		$this->assertCount( 1, $refreshed->get_gallery_image_ids() );
		$this->assertSame(
			self::BASE . 'first.jpg',
			(string) get_post_meta( (int) $refreshed->get_image_id(), ProductSync::META_IMAGE_SOURCE, true )
		);
		$this->assertSame(
			self::BASE . 'third.jpg',
			(string) get_post_meta( (int) $refreshed->get_gallery_image_ids()[0], ProductSync::META_IMAGE_SOURCE, true )
		);

		// Incomplete, so the article is offered to the queue again next run.
		$this->assertSame( '', (string) $refreshed->get_meta( ProductSync::META_IMAGE_HASH ) );
	}

	/**
	 * An image already in the library is never downloaded again.
	 *
	 * @return void
	 */
	public function test_a_known_image_is_not_fetched() {
		$product = $this->product();

		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'shared.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		update_post_meta( $attachment_id, ProductSync::META_IMAGE_SOURCE, self::BASE . 'shared.jpg' );

		$sync = $this->sync( array( self::BASE . 'fresh.jpg' => $this->downloaded_image() ) );
		$sync->import_images( $product->get_id(), array( 'shared.jpg', 'fresh.jpg' ), $this->current_run() );

		// Only the unknown one was ever asked for.
		$this->assertSame( array( array( self::BASE . 'fresh.jpg' ) ), $sync->batches );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertSame( (int) $attachment_id, (int) $refreshed->get_image_id() );
	}

	/**
	 * A site that blocks outbound requests still blocks these.
	 *
	 * Leaving wp_remote_get() must not quietly take WP_HTTP_BLOCK_EXTERNAL with it.
	 *
	 * @return void
	 */
	public function test_a_filtered_request_is_refused() {
		$sync = $this->sync();

		add_filter(
			'pre_http_request',
			static function () {
				return new WP_Error( 'http_request_failed', 'Connection timed out' );
			}
		);

		$result = $sync->check_refusal( self::BASE . 'anything.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Connection timed out', $result->get_error_message() );
	}

	/**
	 * A filter answering with a response is a refusal, not an empty file.
	 *
	 * The filter's contract is a response in memory and what is needed here is bytes
	 * on disk. Core has the same gap — download_url() under such a filter hands back
	 * an empty file that fails as "not an image" two steps later — so this is the
	 * same outcome with a reason attached.
	 *
	 * @return void
	 */
	public function test_a_canned_response_is_refused_rather_than_saved_empty() {
		$sync = $this->sync();

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => 'not really a jpeg',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		$result = $sync->check_refusal( self::BASE . 'anything.jpg' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wksync_image_download_intercepted', $result->get_error_code() );
	}

	/**
	 * Nothing standing in the way means the request goes ahead.
	 *
	 * @return void
	 */
	public function test_an_unblocked_request_proceeds() {
		$this->assertNull( $this->sync()->check_refusal( self::BASE . 'anything.jpg' ) );
	}

	/**
	 * A non-200 throws away the bytes the host did send.
	 *
	 * Whatever the host sent is streamed to disk, so an error page would otherwise be
	 * saved under a .jpg name and handed to the media library as a photograph.
	 *
	 * @return void
	 */
	public function test_an_error_response_deletes_the_partial_file() {
		$sync = $this->sync();
		$file = $this->downloaded_image();

		$result = $sync->check_response( (object) array( 'status_code' => 404 ), $file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( '404', $result->get_error_message() );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * A transport failure comes back as a value, and is treated as one.
	 *
	 * Failures are reported by returning the exception rather than throwing it, so a
	 * dead host arrives alongside the successes.
	 *
	 * @return void
	 */
	public function test_a_transport_failure_is_reported_not_thrown() {
		$sync = $this->sync();
		$file = $this->downloaded_image();

		$result = $sync->check_response( new Exception( 'Could not resolve host' ), $file );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'Could not resolve host', $result->get_error_message() );
		$this->assertFileDoesNotExist( $file );
	}

	/**
	 * The run identifier import_images() will accept.
	 *
	 * @return int Current run.
	 */
	private function current_run() {
		return (int) Status::start( ProductSync::JOB );
	}
}
