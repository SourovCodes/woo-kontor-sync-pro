<?php
/**
 * Tests for adopting a shop's own images instead of downloading them again.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Sync\ProductSync;
use WP_UnitTestCase;

require_once __DIR__ . '/doubles/SpyProductSync.php';

/**
 * A shop moving onto this sync has usually been filled from the same place, so the
 * files Kontor lists are often already in the media library under exactly those
 * names. What matters is that a matching name alone never adopts anything: the host
 * has to agree about the size, and everything else falls through to a download.
 */
class ImageAdoptionTest extends WP_UnitTestCase {

	/**
	 * Base URL the test settings point at.
	 */
	const BASE = 'https://images.example.test/';

	/**
	 * Files written for a test, removed afterwards.
	 *
	 * @var string[]
	 */
	private $temporary = array();

	/**
	 * Remove anything a test left on disk.
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

		remove_all_filters( 'woo_kontor_sync_adopt_existing_images' );

		parent::tear_down();
	}

	/**
	 * A sync whose network calls are answered from a script.
	 *
	 * @return SpyProductSync Configured spy.
	 */
	private function sync() {
		return new SpyProductSync( null, array( 'image_base_url' => self::BASE ) );
	}

	/**
	 * An attachment with a real file of a known size behind it.
	 *
	 * @param string $filename File name to store it under.
	 * @param int    $bytes    How large to make it.
	 * @return int Attachment ID.
	 */
	private function attachment_with_file( $filename, $bytes ) {
		$uploads = wp_upload_dir();
		$path    = trailingslashit( $uploads['path'] ) . $filename;

		file_put_contents( $path, str_repeat( 'x', $bytes ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A fixture file, not a plugin write.

		$this->temporary[] = $path;

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => $path,
				'post_mime_type' => 'image/jpeg',
			)
		);

		update_post_meta( $attachment_id, '_wp_attached_file', _wp_relative_upload_path( $path ) );

		return $attachment_id;
	}

	/**
	 * The shop's own image is adopted when the host reports the same size.
	 *
	 * @return void
	 */
	public function test_a_matching_image_is_adopted_rather_than_downloaded() {
		$existing = $this->attachment_with_file( 'abel-AB12_001.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_001.jpg';

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 512;

		$resolved = $sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( $existing ), $resolved );

		// Nothing was fetched.
		$this->assertSame( array(), $sync->batches );

		// And it is ours now, so the next article sharing the file reuses it.
		$this->assertSame( $url, get_post_meta( $existing, ProductSync::META_IMAGE_SOURCE, true ) );
	}

	/**
	 * A different size is downloaded instead, and nothing is adopted.
	 *
	 * @return void
	 */
	public function test_a_different_size_is_downloaded() {
		$existing = $this->attachment_with_file( 'abel-AB12_002.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_002.jpg';

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 900;

		$sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( array( $url ) ), $sync->batches );
		$this->assertSame( '', (string) get_post_meta( $existing, ProductSync::META_IMAGE_SOURCE, true ) );
	}

	/**
	 * A host that will not answer leaves the image to be downloaded.
	 *
	 * @return void
	 */
	public function test_an_unanswered_head_falls_through_to_a_download() {
		$existing = $this->attachment_with_file( 'abel-AB12_003.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_003.jpg';

		// No length scripted at all, which is what a timeout or a 404 looks like here.
		$sync = $this->sync();

		$sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( array( $url ) ), $sync->batches );
		$this->assertSame( '', (string) get_post_meta( $existing, ProductSync::META_IMAGE_SOURCE, true ) );
	}

	/**
	 * Only the product's own images are candidates.
	 *
	 * A filename is not a globally unique thing, and adopting a stranger's file
	 * because it happened to be called image1.jpg would put someone else's
	 * photograph on a product.
	 *
	 * @return void
	 */
	public function test_another_products_image_is_never_adopted() {
		$stranger = $this->attachment_with_file( 'abel-AB12_004.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_004.jpg';

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 512;

		// The product carries nothing, so there is no candidate to consider.
		$sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array() );

		$this->assertSame( array( array( $url ) ), $sync->batches );
		$this->assertSame( '', (string) get_post_meta( $stranger, ProductSync::META_IMAGE_SOURCE, true ) );
	}

	/**
	 * The name is matched without regard to case.
	 *
	 * The shop's copy need not have kept the feed's capitalisation, though the URL
	 * always uses the feed's: the image host answers 404 to the wrong case.
	 *
	 * @return void
	 */
	public function test_the_filename_match_ignores_case() {
		$existing = $this->attachment_with_file( 'abel-ab12_005.jpg', 512 );
		$url      = self::BASE . 'Abel-AB12_005.jpg';

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 512;

		$resolved = $sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( $existing ), $resolved );
		$this->assertSame( $url, get_post_meta( $existing, ProductSync::META_IMAGE_SOURCE, true ) );
	}

	/**
	 * An adopted image gets alt text like a downloaded one.
	 *
	 * @return void
	 */
	public function test_an_adopted_image_is_described() {
		$existing = $this->attachment_with_file( 'abel-AB12_006.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_006.jpg';

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 512;

		$sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks 12', array( $existing ) );

		$this->assertSame( 'Abel blocks 12', get_post_meta( $existing, ProductSync::META_ALT, true ) );
	}

	/**
	 * The whole thing can be switched off.
	 *
	 * @return void
	 */
	public function test_the_filter_turns_adoption_off() {
		$existing = $this->attachment_with_file( 'abel-AB12_007.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_007.jpg';

		add_filter( 'woo_kontor_sync_adopt_existing_images', '__return_false' );

		$sync                       = $this->sync();
		$sync->head_lengths[ $url ] = 512;

		$sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( array( $url ) ), $sync->batches );
		$this->assertSame( array(), $sync->head_batches, 'The host is never asked when adoption is off.' );
	}

	/**
	 * An image already stamped as ours skips the whole path.
	 *
	 * @return void
	 */
	public function test_an_image_already_ours_is_not_re_verified() {
		$existing = $this->attachment_with_file( 'abel-AB12_008.jpg', 512 );
		$url      = self::BASE . 'abel-AB12_008.jpg';

		update_post_meta( $existing, ProductSync::META_IMAGE_SOURCE, $url );

		$sync = $this->sync();

		$resolved = $sync->run_resolve( array( $url ), 0, 'abel-AB12', 'Abel blocks', array( $existing ) );

		$this->assertSame( array( $existing ), $resolved );
		$this->assertSame( array(), $sync->head_batches, 'No HEAD for a file we already know.' );
		$this->assertSame( array(), $sync->batches );
	}
}
