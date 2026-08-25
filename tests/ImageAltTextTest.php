<?php
/**
 * Tests for the alt text and title this plugin gives a product image.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ReflectionMethod;
use WooKontorSync\Sync\ProductSync;
use WP_UnitTestCase;

/**
 * Covers the description an image reaches the shop with, and the cases where one is
 * deliberately not written.
 */
class ImageAltTextTest extends WP_UnitTestCase {

	/**
	 * Call a protected method on a configured sync.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments to pass.
	 * @return mixed Whatever the method returns.
	 */
	private function call( $method, array $args = array() ) {
		$sync = new ProductSync( null, array( 'image_base_url' => 'https://images.example.test' ) );

		return ( new ReflectionMethod( ProductSync::class, $method ) )->invokeArgs( $sync, $args );
	}

	/**
	 * An attachment with no alt text of its own.
	 *
	 * @return int Attachment ID.
	 */
	private function attachment() {
		return (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'abel-AB12_001.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);
	}

	/**
	 * The alt text stored against an attachment.
	 *
	 * @param int $attachment_id Attachment to read.
	 * @return string Alt text.
	 */
	private function alt( $attachment_id ) {
		return (string) get_post_meta( $attachment_id, ProductSync::META_ALT, true );
	}

	/**
	 * An image with no description gets the product's name.
	 *
	 * Kontor's images carry no IPTC alt, and media_handle_sideload() writes the field
	 * only when the file supplies one — so without this every image in the shop
	 * reaches a customer with an empty alt attribute.
	 *
	 * @return void
	 */
	public function test_an_image_is_described_with_the_product_name() {
		$id = $this->attachment();

		$this->call( 'describe', array( $id, 'Abel blocks 12' ) );

		$this->assertSame( 'Abel blocks 12', $this->alt( $id ) );
	}

	/**
	 * WordPress's own key is used, not one of this plugin's.
	 *
	 * A key of our own would be invisible to every theme, block and SEO plugin that
	 * reads an alt attribute.
	 *
	 * @return void
	 */
	public function test_the_alt_text_is_written_where_wordpress_reads_it() {
		$this->assertSame( '_wp_attachment_image_alt', ProductSync::META_ALT );

		$id = $this->attachment();

		$this->call( 'describe', array( $id, 'Abel blocks 12' ) );

		$this->assertSame( 'Abel blocks 12', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * A description already there is never overwritten.
	 *
	 * It was either written for the article that first fetched a shared photograph or
	 * typed by a person, and both know more about the picture than this does.
	 *
	 * @return void
	 */
	public function test_an_existing_description_is_left_alone() {
		$id = $this->attachment();

		update_post_meta( $id, ProductSync::META_ALT, 'Written by a person' );

		$this->call( 'describe', array( $id, 'Abel blocks 12' ) );

		$this->assertSame( 'Written by a person', $this->alt( $id ) );
	}

	/**
	 * A product with no name leaves the image undescribed rather than blank-labelled.
	 *
	 * @return void
	 */
	public function test_a_nameless_product_writes_nothing() {
		$id = $this->attachment();

		$this->call( 'describe', array( $id, '   ' ) );

		$this->assertSame( '', $this->alt( $id ) );
	}

	/**
	 * A percent in the name survives being written.
	 *
	 * The generic sanitize_text_field() strips percent-encoded octets, so a product
	 * called "Rabatt 20%ab Lager" would lose three characters out of its description.
	 *
	 * @return void
	 */
	public function test_a_percent_in_the_name_is_not_eaten() {
		$id = $this->attachment();

		$this->call( 'describe', array( $id, 'Rabatt 20%ab Lager' ) );

		$this->assertSame( 'Rabatt 20%ab Lager', $this->alt( $id ) );
	}

	/**
	 * Markup in the name is stripped rather than stored.
	 *
	 * @return void
	 */
	public function test_markup_in_the_name_is_stripped() {
		$id = $this->attachment();

		$this->call( 'describe', array( $id, '<b>Abel</b> blocks' ) );

		$this->assertSame( 'Abel blocks', $this->alt( $id ) );
	}
}
