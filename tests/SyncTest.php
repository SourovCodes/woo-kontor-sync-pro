<?php
/**
 * Tests for the product and stock sync jobs.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers the Kontor to WooCommerce field mapping and the stock application path.
 */
class SyncTest extends WP_UnitTestCase {

	/**
	 * A realistic article row, taken from the shape the API actually returns.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'        => 'abel-AB12',
				'Artean'       => '8945005491168',
				'Hersteller'   => 'Abel Woodentoys',
				'Mpn'          => 'AB12',
				'Gewnetto'     => 1.250,
				'Artzentralnr' => 'abel-AB12',
				'Bez1'         => 'Abel blocks 12',
				'Shoptype'     => 'B2C',
				'Shoptitel'    => 'Abel blocks 12',
				'Kurztext'     => '<P>Abel-Blöcke</P>',
				'Langtext'     => 'Abel-Blöcke<BR>Handmade beech blocks.',
				'Ek'           => 40.9500,
				'UVP'          => 81.9000,
				'Lagerbestand' => 24,
				'MainImageURL' => null,
				'Categories'   => '',
			),
			$overrides
		);
	}

	/**
	 * A new article becomes a published product with the Kontor fields mapped over.
	 *
	 * @return void
	 */
	public function test_new_article_creates_a_published_product() {
		$sync    = new ProductSync( null, array( 'image_base_url' => '' ) );
		$outcome = $sync->import_article( $this->article(), 1000 );

		$this->assertSame( 'created', $outcome );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertInstanceOf( WC_Product_Simple::class, $product );
		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( 'Abel blocks 12', $product->get_name() );
		$this->assertSame( '8945005491168', $product->get_global_unique_id() );
		$this->assertTrue( $product->get_manage_stock() );
		$this->assertSame( 24, $product->get_stock_quantity() );
		$this->assertStringContainsString( 'Handmade beech blocks.', $product->get_description() );
	}

	/**
	 * UVP is the selling price and Ek is kept only as cost.
	 *
	 * The same article comes back at a different UVP per shop type while Ek stays
	 * constant, so mapping Ek to the price would sell everything at wholesale.
	 *
	 * @return void
	 */
	public function test_uvp_becomes_the_price_and_ek_is_kept_as_cost() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( '81.9', $product->get_regular_price() );
		$this->assertSame( '40.95', $product->get_meta( '_wksync_cost' ) );
		$this->assertSame( 'AB12', $product->get_meta( '_wksync_mpn' ) );
		$this->assertSame( 'Abel Woodentoys', $product->get_meta( '_wksync_manufacturer' ) );
	}

	/**
	 * Re-importing an unchanged article does not rewrite the product.
	 *
	 * @return void
	 */
	public function test_unchanged_article_is_skipped() {
		$sync    = new ProductSync( null, array( 'image_base_url' => '' ) );
		$article = $this->article();

		$this->assertSame( 'created', $sync->import_article( $article, 1000 ) );
		$this->assertSame( 'skipped', $sync->import_article( $article, 1001 ) );

		// The run stamp still moves, so finalise() does not mistake it for stale.
		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );
		$this->assertSame( '1001', (string) $product->get_meta( '_wksync_synced_at' ) );
	}

	/**
	 * A changed article updates the existing product rather than duplicating it.
	 *
	 * @return void
	 */
	public function test_changed_article_updates_in_place() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$outcome = $sync->import_article( $this->article( array( 'UVP' => 99.5000 ) ), 1001 );

		$this->assertSame( 'updated', $outcome );

		$products = wc_get_products( array( 'sku' => 'abel-AB12' ) );
		$this->assertCount( 1, $products );
		$this->assertSame( '99.5', $products[0]->get_regular_price() );
	}

	/**
	 * An article with no article number cannot be matched, so it is rejected.
	 *
	 * @return void
	 */
	public function test_article_without_a_number_fails() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'failed', $sync->import_article( $this->article( array( 'Artnr' => '' ) ), 1000 ) );
	}

	/**
	 * Stock levels are applied to the matching SKU and counted.
	 *
	 * @return void
	 */
	public function test_stock_is_applied_by_sku() {
		$product = new WC_Product_Simple();
		$product->set_sku( '007-001-001' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 0 );
		$product->save();

		$counts = ( new StockSync( null, array() ) )->apply(
			array(
				'007-001-001' => 111,
				'not-in-woo'  => 5,
			)
		);

		$this->assertSame( 1, $counts['updated'] );
		$this->assertSame( 1, $counts['missing'] );

		$refreshed = wc_get_product( $product->get_id() );
		$this->assertSame( 111, $refreshed->get_stock_quantity() );
		$this->assertSame( 'instock', $refreshed->get_stock_status() );
	}

	/**
	 * Dropping to zero puts the product out of stock.
	 *
	 * @return void
	 */
	public function test_zero_stock_marks_the_product_out_of_stock() {
		$product = new WC_Product_Simple();
		$product->set_sku( '430-003-010' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 12 );
		$product->save();

		( new StockSync( null, array() ) )->apply( array( '430-003-010' => 0 ) );

		$refreshed = wc_get_product( $product->get_id() );

		$this->assertSame( 0, $refreshed->get_stock_quantity() );
		$this->assertSame( 'outofstock', $refreshed->get_stock_status() );
	}
}
