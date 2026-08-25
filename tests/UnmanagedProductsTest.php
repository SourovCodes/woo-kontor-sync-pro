<?php
/**
 * Tests for the products Kontor lists that this plugin does not manage.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\HeldProducts;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the outcome the run summary counts them under, the marker that is the only
 * record they leave, and the view that turns that marker into a list.
 */
class UnmanagedProductsTest extends WP_UnitTestCase {

	/**
	 * An article row, switched on for the webshop unless the overrides say otherwise.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'        => 'abel-AB12',
				'Bez1'         => 'Abel blocks 12',
				'UVP'          => 81.9,
				'Lagerbestand' => 24,
				'Ws_aktiv'     => true,
			),
			$overrides
		);
	}

	/**
	 * A product sync with nothing but the active flag standing in the way.
	 *
	 * @return ProductSync Configured sync.
	 */
	private function sync() {
		return new ProductSync(
			null,
			array(
				'image_base_url'     => '',
				'require_main_image' => false,
			)
		);
	}

	/**
	 * A product the shop made itself.
	 *
	 * @param string $sku Article number.
	 * @return int Product ID.
	 */
	private function shop_own( $sku = 'abel-AB12' ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_name( 'The shop\'s own' );
		$product->set_status( 'publish' );

		return $product->save();
	}

	/**
	 * The outcome is its own, not the one that means a product was drafted.
	 *
	 * Counting the two together had the run summary reporting drafts that were never
	 * made, on the one case where the shop and the ERP openly disagree.
	 *
	 * @return void
	 */
	public function test_leaving_a_product_alone_is_not_counted_as_drafting_it() {
		$id = $this->shop_own();

		$outcome = $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( 'unmanaged', $outcome );
		$this->assertSame( 'publish', get_post_status( $id ) );
	}

	/**
	 * The summary says what happened, in its own words.
	 *
	 * @return void
	 */
	public function test_the_summary_reports_them_separately() {
		$run = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'unmanaged' => 19 ) );

		$this->sync()->finalise( $run );

		$message = Status::get( ProductSync::JOB )['message'];

		$this->assertStringContainsString(
			'Left 19 alone that Kontor is holding back but this plugin did not import.',
			$message
		);

		// And says nothing about drafting, because nothing was drafted.
		$this->assertStringNotContainsString( 'Held 19 back as drafts', $message );
	}

	/**
	 * A run with none of them says nothing about them.
	 *
	 * @return void
	 */
	public function test_the_summary_stays_clean_without_any() {
		$run = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$this->sync()->finalise( $run );

		$this->assertSame( '3 created, 0 updated, 0 unchanged, 0 drafted.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * The marker is written, and it is the only record the product leaves.
	 *
	 * @return void
	 */
	public function test_the_product_is_marked() {
		$id = $this->shop_own();

		$this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( '1000', (string) get_post_meta( $id, ProductSync::META_UNMANAGED, true ) );

		// None of the drafting markers, because it was not drafted.
		$this->assertSame( '', (string) get_post_meta( $id, ProductSync::META_INACTIVE_DRAFTED, true ) );

		// And not adopted either.
		$this->assertSame( '', (string) get_post_meta( $id, ProductSync::META_SYNCED_AT, true ) );
	}

	/**
	 * Kontor switching the article on adopts the product and clears the marker.
	 *
	 * Left behind, it would have the view naming a product the sync now manages.
	 *
	 * @return void
	 */
	public function test_the_marker_goes_when_the_product_is_adopted() {
		$id = $this->shop_own();

		$this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( '1000', (string) get_post_meta( $id, ProductSync::META_UNMANAGED, true ) );

		// The next run, with Kontor no longer holding the article back.
		$outcome = $this->sync()->import_article( $this->article(), 1001 );

		$this->assertSame( 'updated', $outcome );
		$this->assertSame( '', (string) get_post_meta( $id, ProductSync::META_UNMANAGED, true ) );
		$this->assertSame( '1001', (string) get_post_meta( $id, ProductSync::META_SYNCED_AT, true ) );
	}

	/**
	 * The image requirement reaches the same outcome, for the same reason.
	 *
	 * @return void
	 */
	public function test_the_image_requirement_leaves_the_same_record() {
		$id = $this->shop_own();

		$sync = new ProductSync(
			null,
			array(
				'image_base_url'     => '',
				'require_main_image' => true,
			)
		);

		$this->assertSame( 'unmanaged', $sync->import_article( $this->article(), 1000 ) );
		$this->assertSame( '1000', (string) get_post_meta( $id, ProductSync::META_UNMANAGED, true ) );
	}

	/**
	 * The products list offers a view for them, counted.
	 *
	 * @return void
	 */
	public function test_the_products_list_offers_a_view() {
		$this->shop_own();

		$this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( 1, HeldProducts::unmanaged_counts()[ HeldProducts::UNMANAGED ] );

		$views = ( new HeldProducts() )->add_views( array() );

		$this->assertArrayHasKey( 'wksync_held_' . HeldProducts::UNMANAGED, $views );
		$this->assertStringContainsString( 'wksync_held=unmanaged', $views[ 'wksync_held_' . HeldProducts::UNMANAGED ] );
	}

	/**
	 * They are kept out of the count of products held back as drafts.
	 *
	 * That number feeds a sentence about drafts, and these products are published.
	 *
	 * @return void
	 */
	public function test_they_are_not_counted_as_drafts() {
		$this->shop_own();

		$this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( 0, HeldProducts::total() );
	}

	/**
	 * The reason is named beside the product in the list.
	 *
	 * @return void
	 */
	public function test_the_reason_is_named_beside_the_product() {
		$id = $this->shop_own();

		$this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$states = ( new HeldProducts() )->add_state( array(), get_post( $id ) );

		$this->assertArrayHasKey( 'wksync_held_' . HeldProducts::UNMANAGED, $states );
		$this->assertNotSame( '', HeldProducts::label( HeldProducts::UNMANAGED ) );
	}

	/**
	 * A shop with none of them is offered no view at all.
	 *
	 * @return void
	 */
	public function test_no_view_when_nothing_is_unmanaged() {
		$views = ( new HeldProducts() )->add_views( array() );

		$this->assertArrayNotHasKey( 'wksync_held_' . HeldProducts::UNMANAGED, $views );
	}
}
