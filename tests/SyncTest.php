<?php
/**
 * Tests for the product and stock sync jobs.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Brands;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
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
				'Herstellerid' => '104',
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
	 * SKU is the only key: a shared EAN does not match an article to a product.
	 *
	 * EANs repeat across articles in the feed, so matching on one would attach an
	 * article to whichever unrelated product happened to claim the barcode first.
	 *
	 * @return void
	 */
	public function test_sku_is_the_only_matching_key() {
		$other = new WC_Product_Simple();
		$other->set_sku( 'SOMETHING-ELSE' );
		$other->set_global_unique_id( '8945005491168' );
		$other->set_regular_price( '5.00' );
		$other->save();

		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		// The article shares that EAN but has its own Artnr, so it is a new product.
		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$imported = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertGreaterThan( 0, $imported );
		$this->assertNotSame( $other->get_id(), $imported );

		// The unrelated product keeps its own price and its claim on the EAN.
		$refreshed = wc_get_product( $other->get_id() );
		$this->assertSame( '5.00', $refreshed->get_regular_price() );
		$this->assertSame( '8945005491168', $refreshed->get_global_unique_id() );
	}

	/**
	 * No identifier other than the SKU is stored on the product.
	 *
	 * A second Kontor ID kept on the side is a key waiting to be used. The SKU is
	 * the article number, so anything else would only ever disagree with it.
	 *
	 * @return void
	 */
	public function test_no_second_identifier_is_stored() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article( array( 'Artzentralnr' => 'CENTRAL-999' ) ), 1000 );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'abel-AB12', $product->get_sku() );
		$this->assertSame( '', (string) $product->get_meta( '_wksync_kontor_id' ) );

		foreach ( $product->get_meta_data() as $meta ) {
			$this->assertNotSame( 'CENTRAL-999', (string) $meta->value, 'Artzentralnr leaked into meta ' . $meta->key );
		}
	}

	/**
	 * A changed Artzentralnr alone is not a change.
	 *
	 * @return void
	 */
	public function test_central_article_number_is_not_considered() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );
		$this->assertSame( 'skipped', $sync->import_article( $this->article( array( 'Artzentralnr' => 'CHANGED' ) ), 1001 ) );
	}

	/**
	 * An article with no Artnr fails rather than falling back to another field.
	 *
	 * @return void
	 */
	public function test_article_without_a_sku_fails() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'failed', $sync->import_article( $this->article( array( 'Artnr' => '' ) ), 1000 ) );
		$this->assertSame( 'failed', $sync->import_article( $this->article( array( 'Artnr' => null ) ), 1000 ) );

		// Nothing was created from the EAN or the central article number.
		$this->assertSame( 0, wc_get_product_id_by_global_unique_id( '8945005491168' ) );
	}

	/**
	 * UVP is the product price, and Ek is not imported at all.
	 *
	 * @return void
	 */
	public function test_uvp_is_the_price_and_ek_is_ignored() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( '81.9', $product->get_regular_price() );
		$this->assertSame( 'AB12', $product->get_meta( '_wksync_mpn' ) );

		// Ek is the purchase price and must not be stored anywhere.
		$this->assertSame( '', (string) $product->get_meta( '_wksync_cost' ) );

		foreach ( $product->get_meta_data() as $meta ) {
			$this->assertNotSame( '40.95', (string) $meta->value, 'Ek leaked into meta ' . $meta->key );
		}
	}

	/**
	 * Ek and Categories changing does not count as a change.
	 *
	 * Hashing the whole row would rewrite the entire catalogue every time purchase
	 * prices moved, even though neither field is imported.
	 *
	 * @return void
	 */
	public function test_ignored_fields_do_not_trigger_an_update() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$churned = $this->article(
			array(
				'Ek'         => 999.9900,
				'Categories' => 'ed36602283b14c329e31f029bdcc7fc9,D444E512-20AB-45B5-B8C8-C968A934DB52',
			)
		);

		$this->assertSame( 'skipped', $sync->import_article( $churned, 1001 ) );

		// A field that is imported still registers as a change.
		$this->assertSame( 'updated', $sync->import_article( $this->article( array( 'UVP' => 12.5 ) ), 1002 ) );
	}

	/**
	 * The manufacturer becomes a WooCommerce brand assigned to the product.
	 *
	 * @return void
	 */
	public function test_manufacturer_becomes_an_assigned_brand() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$product_id = wc_get_product_id_by_sku( 'abel-AB12' );
		$brands     = wp_get_object_terms( $product_id, Brands::TAXONOMY );

		$this->assertCount( 1, $brands );
		$this->assertSame( 'Abel Woodentoys', $brands[0]->name );
	}

	/**
	 * Two articles from one manufacturer share a single brand term.
	 *
	 * @return void
	 */
	public function test_articles_share_one_brand_term() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );
		$sync->import_article(
			$this->article(
				array(
					'Artnr'  => 'abel-AB24',
					'Artean' => '7426870707154',
				)
			),
			1000
		);

		$terms = get_terms(
			array(
				'taxonomy'   => Brands::TAXONOMY,
				'hide_empty' => false,
				'name'       => 'Abel Woodentoys',
			)
		);

		$this->assertCount( 1, $terms );
	}

	/**
	 * A manufacturer renamed in the ERP moves the product to a new brand.
	 *
	 * Brands are matched on the name alone, so a rename cannot be recognised as one:
	 * the product follows the new name and the old term is left behind. Matching on
	 * Herstellerid is what would rename the existing term instead.
	 *
	 * @return void
	 */
	public function test_renamed_manufacturer_creates_a_second_brand() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$first = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		$sync->import_article( $this->article( array( 'Hersteller' => 'Abel Wooden Toys BV' ) ), 1001 );

		$after = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		// The product carries only the new brand.
		$this->assertCount( 1, $after );
		$this->assertSame( 'Abel Wooden Toys BV', $after[0]->name );
		$this->assertNotSame( $first[0]->term_id, $after[0]->term_id );

		// The old term survives, now unused.
		$this->assertInstanceOf( \WP_Term::class, get_term( $first[0]->term_id, Brands::TAXONOMY ) );
	}

	/**
	 * A changed Herstellerid alone is not a change.
	 *
	 * The ID is not consulted, so it must not sit in the change hash either.
	 *
	 * @return void
	 */
	public function test_manufacturer_id_is_not_considered() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );
		$this->assertSame( 'skipped', $sync->import_article( $this->article( array( 'Herstellerid' => '084' ) ), 1001 ) );
	}

	/**
	 * An article with no manufacturer leaves the existing brand alone.
	 *
	 * @return void
	 */
	public function test_missing_manufacturer_does_not_clear_the_brand() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$sync->import_article(
			$this->article(
				array(
					'Hersteller'   => null,
					'Herstellerid' => null,
					'UVP'          => 44.0,
				)
			),
			1001
		);

		$brands = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		$this->assertCount( 1, $brands );
		$this->assertSame( 'Abel Woodentoys', $brands[0]->name );
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
	 * A title containing a percent sequence is not mangled.
	 *
	 * The generic sanitize_text_field() strips percent-encoded octets, so a title
	 * like "Rabatt 20%ab Lager" would become "Rabatt 20 Lager".
	 *
	 * @return void
	 */
	public function test_percent_sequences_in_titles_survive() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article( array( 'Shoptitel' => 'Rabatt 20%ab Lager' ) ), 1000 );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'Rabatt 20%ab Lager', $product->get_name() );
	}

	/**
	 * A product this sync drafted is republished when the article returns.
	 *
	 * @return void
	 */
	public function test_sync_drafted_product_is_republished_when_it_returns() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		// Stand in for finalise() having drafted it on a run where Kontor dropped it.
		$product = wc_get_product( $id );
		$product->set_status( 'draft' );
		$product->update_meta_data( '_wksync_drafted_by_sync', 1 );
		$product->save();

		$sync->import_article( $this->article(), 1001 );

		$restored = wc_get_product( $id );

		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( '', (string) $restored->get_meta( '_wksync_drafted_by_sync' ) );
	}

	/**
	 * A draft someone made by hand is left alone.
	 *
	 * @return void
	 */
	public function test_manually_drafted_product_stays_drafted() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$id      = wc_get_product_id_by_sku( 'abel-AB12' );
		$product = wc_get_product( $id );
		$product->set_status( 'draft' );
		$product->save();

		$sync->import_article( $this->article( array( 'UVP' => 55.0 ) ), 1001 );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );
	}

	/**
	 * A superseded run's chained page is discarded instead of importing.
	 *
	 * An action already executing cannot be cancelled and queues its own successor,
	 * so without this a stale run keeps walking the catalogue underneath a newer
	 * one. Two runs then create and update the same products in parallel.
	 *
	 * @return void
	 */
	public function test_superseded_run_is_discarded() {
		$current = Status::start( ProductSync::JOB );
		$sync    = new ProductSync( null, array( 'image_base_url' => '' ) );

		// A page belonging to an older run must do nothing at all.
		$sync->import_page( 0, $current - 500 );

		$this->assertSame( array(), Status::get( ProductSync::JOB )['counts'] );
	}

	/**
	 * A second run cannot start while one is in flight.
	 *
	 * @return void
	 */
	public function test_second_run_is_refused_while_one_is_running() {
		$first = Status::start( ProductSync::JOB );

		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->start();

		// The run stamp is unchanged, so no new run took over.
		$this->assertSame( $first, Status::get( ProductSync::JOB )['started'] );
		$this->assertSame( 'wksync_already_running', Scheduler::trigger( 'products' )->get_error_code() );
	}

	/**
	 * A run left behind by a crash does not block the job forever.
	 *
	 * @return void
	 */
	public function test_stale_run_does_not_block_forever() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
			)
		);

		Status::start( ProductSync::JOB );

		$all                        = get_option( Status::OPTION_KEY );
		$all['products']['started'] = time() - ( Status::STALE_AFTER + 60 );
		update_option( Status::OPTION_KEY, $all, false );

		$this->assertFalse( Status::is_running( ProductSync::JOB ) );
		$this->assertTrue( Scheduler::trigger( 'products' ) );
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
	 * A product this plugin imported has stock control taken over, rather than
	 * being silently left selling.
	 *
	 * Calling wc_update_product_stock() is a no-op when manage_stock is off: the
	 * quantity stays null and the status stays "instock" whatever Kontor reports.
	 *
	 * @return void
	 */
	public function test_imported_product_without_stock_management_is_taken_over() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'KONTOR-OWNED' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, 999 );
		$product->save();

		$counts = ( new StockSync( null, array() ) )->apply( array( 'KONTOR-OWNED' => 0 ) );

		$refreshed = wc_get_product( $product->get_id() );

		$this->assertSame( 1, $counts['updated'] );
		$this->assertTrue( $refreshed->get_manage_stock() );
		$this->assertSame( 0, $refreshed->get_stock_quantity() );
		$this->assertSame( 'outofstock', $refreshed->get_stock_status() );
	}

	/**
	 * Someone else's product is counted, not quietly reconfigured.
	 *
	 * @return void
	 */
	public function test_foreign_product_without_stock_management_is_left_alone() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'HAND-MADE' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();

		$counts = ( new StockSync( null, array() ) )->apply( array( 'HAND-MADE' => 0 ) );

		$refreshed = wc_get_product( $product->get_id() );

		$this->assertSame( 1, $counts['unmanaged'] );
		$this->assertSame( 0, $counts['updated'] );
		$this->assertFalse( $refreshed->get_manage_stock() );
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
