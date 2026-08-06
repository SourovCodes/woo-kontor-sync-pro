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
	 * An article with no Artnr is passed over, not matched on another field.
	 *
	 * @return void
	 */
	public function test_article_without_a_sku_is_skipped() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'no_sku', $sync->import_article( $this->article( array( 'Artnr' => '' ) ), 1000 ) );
		$this->assertSame( 'no_sku', $sync->import_article( $this->article( array( 'Artnr' => null ) ), 1000 ) );
		$this->assertSame( 'no_sku', $sync->import_article( $this->article( array( 'Artnr' => '   ' ) ), 1000 ) );

		// Nothing was created from the EAN or the central article number.
		$this->assertSame( 0, wc_get_product_id_by_global_unique_id( '8945005491168' ) );
		$this->assertSame( array(), wc_get_products( array( 'limit' => -1 ) ) );
	}

	/**
	 * A SKU held by two products stops the article dead.
	 *
	 * Updating one of them would write Kontor's data onto whichever sorted first and
	 * leave the other drifting, and creating a third would make it worse.
	 *
	 * @return void
	 */
	public function test_duplicate_sku_updates_nothing() {
		$first  = $this->product_with_duplicate_sku( 'abel-AB12', '5.00' );
		$second = $this->product_with_duplicate_sku( 'abel-AB12', '6.00' );

		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'duplicate_sku', $sync->import_article( $this->article(), 1000 ) );

		// Neither was written to, and no third product was created for the article.
		$this->assertSame( '5.00', wc_get_product( $first )->get_regular_price() );
		$this->assertSame( '6.00', wc_get_product( $second )->get_regular_price() );
		$this->assertCount( 2, wc_get_products( array( 'limit' => -1 ) ) );
	}

	/**
	 * A duplicate SKU is reported, with the products named.
	 *
	 * Nothing else tells the shop the article is being passed over, and the products
	 * have to be named or there is nothing to go and look at.
	 *
	 * @return void
	 */
	public function test_duplicate_sku_is_logged() {
		$first  = $this->product_with_duplicate_sku( 'abel-AB12', '5.00' );
		$second = $this->product_with_duplicate_sku( 'abel-AB12', '6.00' );

		$logged = array();

		$capture = static function ( $message ) use ( &$logged ) {
			$logged[] = $message;

			return $message;
		};

		add_filter( 'woocommerce_logger_log_message', $capture );
		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article(), 1000 );
		remove_filter( 'woocommerce_logger_log_message', $capture );

		$reported = implode( "\n", $logged );

		$this->assertStringContainsString( 'abel-AB12', $reported );
		$this->assertStringContainsString( (string) $first, $reported );
		$this->assertStringContainsString( (string) $second, $reported );
	}

	/**
	 * A duplicate SKU does not get the products drafted by finalise().
	 *
	 * The run stamp still has to move on products this plugin imported: without it the
	 * finalising pass reads them as articles Kontor dropped and unpublishes both, for
	 * an article that is still in the feed.
	 *
	 * @return void
	 */
	public function test_duplicate_sku_keeps_imported_products_published() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		// An earlier run imported the article, so the product is this plugin's.
		$sync->import_article( $this->article(), 1000 );

		$imported = wc_get_product_id_by_sku( 'abel-AB12' );

		// A second product turns up holding the same article number.
		$clone = $this->product_with_duplicate_sku( 'abel-AB12', '6.00' );
		$run   = Status::start( ProductSync::JOB );

		$this->assertSame( 'duplicate_sku', $sync->import_article( $this->article(), $run ) );

		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $imported )->get_status() );
		$this->assertSame( 'publish', wc_get_product( $clone )->get_status() );
		$this->assertSame( (string) $run, (string) get_post_meta( $imported, '_wksync_synced_at', true ) );
	}

	/**
	 * A duplicate SKU does not adopt a product the shop manager created.
	 *
	 * The run stamp doubles as the marker for "this plugin imported this product", so
	 * writing it onto someone else's product would hand finalise() the right to draft
	 * it the moment the article leaves the feed.
	 *
	 * @return void
	 */
	public function test_duplicate_sku_does_not_adopt_a_shop_managers_product() {
		$first  = $this->product_with_duplicate_sku( 'abel-AB12', '5.00' );
		$second = $this->product_with_duplicate_sku( 'abel-AB12', '6.00' );

		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article(), 1000 );

		$this->assertSame( '', (string) get_post_meta( $first, '_wksync_synced_at', true ) );
		$this->assertSame( '', (string) get_post_meta( $second, '_wksync_synced_at', true ) );
	}

	/**
	 * Create a product holding a SKU another product already uses.
	 *
	 * WooCommerce refuses a duplicate SKU on save, which is why the sync should never
	 * meet one — but migrations, CSV imports and anything writing the meta directly
	 * produce them anyway, and that is the case under test. The uniqueness check is
	 * short-circuited through core's own filter rather than by writing the meta behind
	 * WooCommerce's back, so the lookup table ends up populated exactly as it would be
	 * on a real shop carrying the fault.
	 *
	 * @param string $sku   SKU to force onto the product.
	 * @param string $price Regular price, so a rewrite by the sync is visible.
	 * @return int Product ID.
	 */
	private function product_with_duplicate_sku( $sku, $price ) {
		add_filter( 'wc_product_pre_has_unique_sku', '__return_true' );

		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$id = $product->save();

		remove_filter( 'wc_product_pre_has_unique_sku', '__return_true' );

		return $id;
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
	 * A manufacturer renamed in the ERP renames the brand it already has.
	 *
	 * Herstellerid is what makes this recognisable as a rename. Matching on the name
	 * alone cannot tell a renamed manufacturer from a new one, so every product would
	 * move to a fresh term and leave the old one behind, unused.
	 *
	 * @return void
	 */
	public function test_renamed_manufacturer_renames_the_existing_brand() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$sync->import_article( $this->article(), 1000 );

		$first = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		$sync->import_article( $this->article( array( 'Hersteller' => 'Abel Wooden Toys BV' ) ), 1001 );

		$after = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		// The same term, under its new name.
		$this->assertCount( 1, $after );
		$this->assertSame( 'Abel Wooden Toys BV', $after[0]->name );
		$this->assertSame( $first[0]->term_id, $after[0]->term_id );

		// No second brand was left behind.
		$this->assertEmpty(
			get_terms(
				array(
					'taxonomy'   => Brands::TAXONOMY,
					'hide_empty' => false,
					'name'       => 'Abel Woodentoys',
				)
			)
		);
	}

	/**
	 * A manufacturer re-keyed to a different Herstellerid keeps its brand.
	 *
	 * The name is what settles this: a manufacturer arriving under a new ID but the
	 * same name is the ERP renumbering it, not a second company, and splitting the
	 * brand in two would be worse than following the move. The existing term is
	 * adopted and re-stamped with the new ID, so the next rename is still recognised.
	 *
	 * The article has to be re-examined for any of that to happen, which is why the
	 * ID sits in the change hash rather than being ignored as it once was.
	 *
	 * @return void
	 */
	public function test_changed_manufacturer_id_is_a_change() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$first = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		$this->assertSame( '104', get_term_meta( $first[0]->term_id, Brands::TERM_META_ID, true ) );

		// Not skipped: the ID moving is a change worth looking at.
		$this->assertSame( 'updated', $sync->import_article( $this->article( array( 'Herstellerid' => '084' ) ), 1001 ) );

		$after = wp_get_object_terms( wc_get_product_id_by_sku( 'abel-AB12' ), Brands::TAXONOMY );

		$this->assertCount( 1, $after );
		$this->assertSame( $first[0]->term_id, $after[0]->term_id );
		$this->assertSame( '084', get_term_meta( $after[0]->term_id, Brands::TERM_META_ID, true ) );
	}

	/**
	 * Manufacturer IDs are matched as strings, so a leading zero is significant.
	 *
	 * Casting to an integer would collide "084" with "84" and merge two unrelated
	 * manufacturers into one brand.
	 *
	 * @return void
	 */
	public function test_leading_zeros_do_not_collide() {
		$padded = Brands::resolve( 'Padded Manufacturer', '084' );
		$plain  = Brands::resolve( 'Plain Manufacturer', '84' );

		$this->assertNotSame( 0, $padded );
		$this->assertNotSame( $padded, $plain );
		$this->assertSame( '084', get_term_meta( $padded, Brands::TERM_META_ID, true ) );
		$this->assertSame( '84', get_term_meta( $plain, Brands::TERM_META_ID, true ) );
	}

	/**
	 * A brand term that predates the ID being recorded is adopted, not duplicated.
	 *
	 * @return void
	 */
	public function test_existing_brand_without_an_id_is_adopted() {
		$created = wp_insert_term( 'Abel Woodentoys', Brands::TAXONOMY );

		$resolved = Brands::resolve( 'Abel Woodentoys', '104' );

		$this->assertSame( (int) $created['term_id'], $resolved );
		$this->assertSame( '104', get_term_meta( $resolved, Brands::TERM_META_ID, true ) );
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
	 * Articles the run passed over are reported in the summary, not only the log.
	 *
	 * Both are data problems only a person can fix, and the summary line is the one
	 * place anybody looks.
	 *
	 * @return void
	 */
	public function test_summary_reports_articles_that_were_passed_over() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$run  = Status::start( ProductSync::JOB );

		Status::progress(
			ProductSync::JOB,
			array(
				'created'       => 3,
				'no_sku'        => 2,
				'duplicate_sku' => 1,
			)
		);

		$sync->finalise( $run );

		$this->assertStringContainsString(
			'Passed over 2 with no article number and 1 matching more than one product',
			Status::get( ProductSync::JOB )['message']
		);
	}

	/**
	 * A run that passed over nothing says nothing about it.
	 *
	 * @return void
	 */
	public function test_clean_run_summary_stays_clean() {
		$sync = new ProductSync( null, array( 'image_base_url' => '' ) );
		$run  = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$sync->finalise( $run );

		$this->assertSame( '3 created, 0 updated, 0 unchanged, 0 drafted.', Status::get( ProductSync::JOB )['message'] );
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
			),
			time()
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

		$counts = ( new StockSync( null, array() ) )->apply( array( 'KONTOR-OWNED' => 0 ), time() );

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

		$counts = ( new StockSync( null, array() ) )->apply( array( 'HAND-MADE' => 0 ), time() );

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

		( new StockSync( null, array() ) )->apply( array( '430-003-010' => 0 ), time() );

		$refreshed = wc_get_product( $product->get_id() );

		$this->assertSame( 0, $refreshed->get_stock_quantity() );
		$this->assertSame( 'outofstock', $refreshed->get_stock_status() );
	}

	/**
	 * A product this plugin imported, for the stock drafting tests.
	 *
	 * The status is set before the first save rather than afterwards: a product
	 * object handed straight back from its own create() does not persist a later
	 * set_status(), which is a quirk of reusing the object rather than of the code
	 * under test — production always works from a freshly loaded product.
	 *
	 * @param string $sku    SKU to give it.
	 * @param string $status Post status to create it with.
	 * @param array  $meta   Extra meta to write, keyed by meta key.
	 * @return int Product ID.
	 */
	private function imported_product( $sku, $status = 'publish', array $meta = array() ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_status( $status );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 5 );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, 1000 );

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	/**
	 * An article the stock feed no longer carries is drafted.
	 *
	 * @return void
	 */
	public function test_article_missing_from_the_stock_feed_is_drafted() {
		$kept    = $this->imported_product( 'STILL-STOCKED' );
		$dropped = $this->imported_product( 'GONE-FROM-STOCK' );

		$sync = new StockSync( null, array() );
		$run  = Status::start( StockSync::JOB );

		$sync->apply( array( 'STILL-STOCKED' => 3 ), $run );
		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $kept )->get_status() );
		$this->assertSame( 'draft', wc_get_product( $dropped )->get_status() );
		$this->assertSame( '1', (string) get_post_meta( $dropped, StockSync::META_STOCK_DRAFTED, true ) );
	}

	/**
	 * A shop manager's own product is never drafted for missing the stock feed.
	 *
	 * It was never in one. The run stamp the product sync writes is the marker for
	 * "this plugin imported this", and without it the product is not ours to hide.
	 *
	 * @return void
	 */
	public function test_foreign_product_is_not_drafted_by_the_stock_sync() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'HAND-MADE' );
		$product->set_status( 'publish' );
		$product->save();

		$sync = new StockSync( null, array() );
		$run  = Status::start( StockSync::JOB );

		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $product->get_id() )->get_status() );
	}

	/**
	 * An article returning to the stock feed is republished.
	 *
	 * @return void
	 */
	public function test_stock_drafted_product_is_republished_when_it_returns() {
		$product = $this->imported_product( 'BACK-IN-STOCK' );

		$sync = new StockSync( null, array() );
		$run  = Status::start( StockSync::JOB );

		$sync->finalise( $run );
		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );

		$later  = Status::start( StockSync::JOB );
		$counts = $sync->apply( array( 'BACK-IN-STOCK' => 7 ), $later );

		$restored = wc_get_product( $product );

		$this->assertSame( 1, $counts['restored'] );
		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( 7, $restored->get_stock_quantity() );
		$this->assertSame( '', (string) $restored->get_meta( StockSync::META_STOCK_DRAFTED ) );
	}

	/**
	 * A product a person drafted is left drafted when its level arrives.
	 *
	 * @return void
	 */
	public function test_manually_drafted_product_is_not_republished_by_stock() {
		$product = $this->imported_product( 'HIDDEN-ON-PURPOSE', 'draft' );

		$counts = ( new StockSync( null, array() ) )->apply( array( 'HIDDEN-ON-PURPOSE' => 9 ), time() );

		$refreshed = wc_get_product( $product );

		$this->assertSame( 0, $counts['restored'] );
		$this->assertSame( 'draft', $refreshed->get_status() );

		// The level is still applied; the product is hidden, not unmanaged.
		$this->assertSame( 9, $refreshed->get_stock_quantity() );
	}

	/**
	 * A product both feeds dropped stays drafted until both list it again.
	 *
	 * Each sync clears only its own marker. Sharing one would let the catalogue
	 * listing an article again put it back on the shelf with no stock behind it, and
	 * a stock level arriving republish an article Kontor no longer sells.
	 *
	 * @return void
	 */
	public function test_a_product_both_syncs_drafted_needs_both_to_return() {
		$product = $this->imported_product(
			'abel-AB12',
			'draft',
			array(
				ProductSync::META_SYNC_DRAFTED => 1,
				StockSync::META_STOCK_DRAFTED  => 1,
			)
		);

		// The catalogue lists it again, but there is still no stock level for it.
		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article(), 2000 );

		$after_products = wc_get_product( $product );

		$this->assertSame( 'draft', $after_products->get_status() );
		$this->assertSame( '', (string) $after_products->get_meta( ProductSync::META_SYNC_DRAFTED ) );
		$this->assertSame( '1', (string) $after_products->get_meta( StockSync::META_STOCK_DRAFTED ) );

		// Now the stock feed carries it too, and the last marker goes with it.
		( new StockSync( null, array() ) )->apply( array( 'abel-AB12' => 4 ), time() );

		$after_stock = wc_get_product( $product );

		$this->assertSame( 'publish', $after_stock->get_status() );
		$this->assertSame( '', (string) $after_stock->get_meta( StockSync::META_STOCK_DRAFTED ) );
	}

	/**
	 * The other ordering works too: stock first, then the catalogue.
	 *
	 * @return void
	 */
	public function test_stock_returning_first_does_not_republish_a_dropped_article() {
		$product = $this->imported_product(
			'abel-AB12',
			'draft',
			array(
				ProductSync::META_SYNC_DRAFTED => 1,
				StockSync::META_STOCK_DRAFTED  => 1,
			)
		);

		$counts = ( new StockSync( null, array() ) )->apply( array( 'abel-AB12' => 4 ), time() );

		$after_stock = wc_get_product( $product );

		$this->assertSame( 0, $counts['restored'] );
		$this->assertSame( 'draft', $after_stock->get_status() );
		$this->assertSame( '1', (string) $after_stock->get_meta( ProductSync::META_SYNC_DRAFTED ) );

		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article(), 2000 );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * A run with nothing to draft still closes itself.
	 *
	 * This is the steady state — every fifteen minutes, on a shop where the feed has
	 * not changed — so it is the pass that matters most. A finalise that found nothing
	 * and returned without completing would leave the job reporting "running" and
	 * refusing to start another for the next six hours.
	 *
	 * @return void
	 */
	public function test_finalise_with_nothing_stale_completes_the_run() {
		$product = $this->imported_product( 'STILL-STOCKED' );

		$sync = new StockSync( null, array() );
		$run  = Status::start( StockSync::JOB );

		$sync->apply( array( 'STILL-STOCKED' => 3 ), $run );
		$sync->finalise( $run );

		$this->assertSame( 'success', Status::get( StockSync::JOB )['state'] );
		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * A superseded finalise pass drafts nothing.
	 *
	 * @return void
	 */
	public function test_superseded_stock_finalise_is_discarded() {
		$product = $this->imported_product( 'GONE-FROM-STOCK' );
		$current = Status::start( StockSync::JOB );

		// A finalise belonging to an older run must draft nothing at all.
		( new StockSync( null, array() ) )->finalise( $current - 500 );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * The run summary reports what was drafted and what came back.
	 *
	 * @return void
	 */
	public function test_stock_summary_reports_drafting() {
		$this->imported_product( 'GONE-FROM-STOCK' );

		$sync = new StockSync( null, array() );
		$run  = Status::start( StockSync::JOB );

		Status::progress( StockSync::JOB, $sync->apply( array( 'not-in-woo' => 1 ), $run ) );
		$sync->finalise( $run );

		$this->assertSame(
			'0 products updated, 1 article numbers had no matching SKU, 0 skipped as not stock-managed, 1 drafted, 0 republished.',
			Status::get( StockSync::JOB )['message']
		);
	}
}
