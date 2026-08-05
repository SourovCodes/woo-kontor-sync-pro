<?php
/**
 * Tests for image deduplication and the removal of unused imported images.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ReflectionMethod;
use WC_Product_Simple;
use WooKontorSync\Sync\ProductSync;
use WP_UnitTestCase;

/**
 * Covers reusing an already-downloaded image and deleting one nothing points at.
 */
class ImageCleanupTest extends WP_UnitTestCase {

	/**
	 * Call one of the sync's protected image helpers.
	 *
	 * Exercised directly rather than through a full import, because sideloading needs
	 * a real HTTP download and a real image file; the decisions worth pinning down
	 * are the lookup and the deletion guard, not the download.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed Whatever the method returns.
	 */
	private function call( $method, array $args = array() ) {
		$sync = new ProductSync( null, array( 'image_base_url' => 'https://images.example.test' ) );

		// No setAccessible() call: it has been a no-op since PHP 8.1 and is deprecated
		// on newer runtimes, which the host CLI already is.
		return ( new ReflectionMethod( ProductSync::class, $method ) )->invokeArgs( $sync, $args );
	}

	/**
	 * Create an attachment, optionally stamped as one this plugin imported.
	 *
	 * @param string $source Source URL to stamp, or an empty string for none.
	 * @return int Attachment ID.
	 */
	private function make_attachment( $source = '' ) {
		$attachment_id = self::factory()->attachment->create_object(
			array(
				'file'           => 'abel-AB12_001.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		if ( '' !== $source ) {
			update_post_meta( $attachment_id, ProductSync::META_IMAGE_SOURCE, $source );
		}

		return (int) $attachment_id;
	}

	/**
	 * An image already downloaded from a URL is found rather than fetched again.
	 *
	 * @return void
	 */
	public function test_existing_image_is_found_by_its_source_url() {
		$url           = 'https://images.example.test/abel-AB12_001.jpg';
		$attachment_id = $this->make_attachment( $url );

		$this->assertSame( $attachment_id, $this->call( 'attachment_for_source', array( $url ) ) );
	}

	/**
	 * A URL that has never been imported yields nothing.
	 *
	 * @return void
	 */
	public function test_unknown_source_url_yields_nothing() {
		$this->make_attachment( 'https://images.example.test/abel-AB12_001.jpg' );

		$this->assertSame(
			0,
			$this->call( 'attachment_for_source', array( 'https://images.example.test/other.jpg' ) )
		);
	}

	/**
	 * An imported image nothing points at is deleted.
	 *
	 * @return void
	 */
	public function test_unused_imported_image_is_deleted() {
		$attachment_id = $this->make_attachment( 'https://images.example.test/gone.jpg' );

		$this->call( 'discard_unused_images', array( array( $attachment_id ) ) );

		$this->assertNull( get_post( $attachment_id ) );
	}

	/**
	 * Media this plugin did not import is never deleted.
	 *
	 * @return void
	 */
	public function test_foreign_media_is_left_alone() {
		$attachment_id = $this->make_attachment();

		$this->call( 'discard_unused_images', array( array( $attachment_id ) ) );

		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * An image another product still uses survives.
	 *
	 * Deduplication means one file can be the featured image of one article and a
	 * gallery entry of another, so "this product dropped it" is not "nobody wants it".
	 *
	 * @return void
	 */
	public function test_image_used_by_another_product_survives() {
		$attachment_id = $this->make_attachment( 'https://images.example.test/shared.jpg' );

		$other = new WC_Product_Simple();
		$other->set_name( 'Another article' );
		$other->set_image_id( $attachment_id );
		$other->save();

		$this->call( 'discard_unused_images', array( array( $attachment_id ) ) );

		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * An image sitting in another product's gallery survives.
	 *
	 * @return void
	 */
	public function test_image_in_another_gallery_survives() {
		$attachment_id = $this->make_attachment( 'https://images.example.test/gallery.jpg' );

		$other = new WC_Product_Simple();
		$other->set_name( 'Another article' );
		$other->set_gallery_image_ids( array( $attachment_id ) );
		$other->save();

		$this->call( 'discard_unused_images', array( array( $attachment_id ) ) );

		$this->assertNotNull( get_post( $attachment_id ) );
	}

	/**
	 * A gallery entry is matched exactly, not as a substring.
	 *
	 * The gallery is a comma-separated list, so a LIKE '%12%' comparison also matches
	 * the gallery "123" — and would then refuse to delete attachment 12 because an
	 * unrelated image happens to contain its digits. The inverse is the dangerous
	 * half: attachment 123 looking "in use" because 12 is somewhere in a list.
	 *
	 * @return void
	 */
	public function test_gallery_membership_is_not_a_substring_match() {
		$decoy   = $this->make_attachment( 'https://images.example.test/decoy.jpg' );
		$orphan  = $this->make_attachment( 'https://images.example.test/orphan.jpg' );
		$product = new WC_Product_Simple();

		$product->set_name( 'Decoy holder' );
		$product->save();

		/*
		 * A gallery whose single entry merely contains the orphan's ID as a substring.
		 * FIND_IN_SET reads the list as members; LIKE would read it as text.
		 */
		update_post_meta( $product->get_id(), '_product_image_gallery', $decoy . $orphan );

		$this->assertFalse( (bool) $this->call( 'image_in_use', array( $orphan ) ) );
		$this->assertFalse( (bool) $this->call( 'image_in_use', array( $decoy ) ) );

		$this->call( 'discard_unused_images', array( array( $orphan ) ) );

		$this->assertNull( get_post( $orphan ) );
	}
}
