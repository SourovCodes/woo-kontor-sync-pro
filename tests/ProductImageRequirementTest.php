<?php
/**
 * Tests for the "only import articles Kontor lists an image for" requirement.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WooKontorSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers the setting, the articles it passes over, and the products it drafts.
 */
class ProductImageRequirementTest extends WP_UnitTestCase {

	/**
	 * An article row, imageless unless the overrides say otherwise.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'        => 'abel-AB12',
				'Bez1'         => 'Abel blocks 12',
				'Shoptitel'    => 'Abel blocks 12',
				'UVP'          => 81.9000,
				'Lagerbestand' => 24,
				'MainImageURL' => null,
			),
			$overrides
		);
	}

	/**
	 * A sync with the requirement turned on.
	 *
	 * The image base URL is left blank throughout: whether the shop can fetch a file
	 * is a different question from whether Kontor has one, and the requirement must
	 * not depend on the two being confused.
	 *
	 * @param bool $required Whether an image is required.
	 * @return ProductSync Configured sync.
	 */
	private function sync( $required = true ) {
		return new ProductSync(
			null,
			array(
				'image_base_url'     => '',
				'require_main_image' => $required,
			)
		);
	}

	/**
	 * A product carrying this plugin's import marker.
	 *
	 * @param string $sku    Article number.
	 * @param string $status Post status.
	 * @param array  $meta   Extra meta to set.
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
	 * The requirement is off on a fresh install, so nothing changes by default.
	 *
	 * @return void
	 */
	public function test_requirement_is_off_by_default() {
		$this->assertFalse( Settings::default_settings()['require_main_image'] );
		$this->assertSame( 'created', $this->sync( false )->import_article( $this->article(), 1000 ) );
	}

	/**
	 * An article Kontor lists no image for is imported as a draft.
	 *
	 * Created rather than passed over: the shop then holds the whole catalogue, and a
	 * picture arriving is one status change away from putting the article on sale
	 * rather than a full import.
	 *
	 * @return void
	 */
	public function test_imageless_article_is_imported_as_a_draft() {
		$this->assertSame( 'no_image', $this->sync()->import_article( $this->article(), 1000 ) );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertInstanceOf( 'WC_Product', $product );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );

		// Imported in full, so nothing is missing when the picture arrives.
		$this->assertSame( 'Abel blocks 12', $product->get_name() );
		$this->assertSame( '1000', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * An article with a main image is imported as usual.
	 *
	 * @return void
	 */
	public function test_article_with_a_main_image_is_imported() {
		$outcome = $this->sync()->import_article(
			$this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ),
			1000
		);

		$this->assertSame( 'created', $outcome );
		$this->assertSame( 'publish', wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) )->get_status() );
	}

	/**
	 * A gallery image with no main image still counts.
	 *
	 * The featured image is the first image the article carries, so an article whose
	 * only picture is a gallery entry does end up with one. Reading MainImageURL alone
	 * would pass over a product that was about to get exactly what the setting asks
	 * for.
	 *
	 * @return void
	 */
	public function test_gallery_image_alone_satisfies_the_requirement() {
		$outcome = $this->sync()->import_article(
			$this->article( array( 'ImageURL_1' => 'abel-AB12_002.jpg' ) ),
			1000
		);

		$this->assertSame( 'created', $outcome );
	}

	/**
	 * An empty string is no more an image than a missing field is.
	 *
	 * @return void
	 */
	public function test_blank_image_fields_are_not_images() {
		$row = $this->article(
			array(
				'MainImageURL' => '   ',
				'ImageURL_1'   => '',
			)
		);

		$this->assertSame( 'no_image', $this->sync()->import_article( $row, 1000 ) );
	}

	/**
	 * A product already imported is drafted when its article loses its image.
	 *
	 * Drafted rather than deleted, so a feed that was briefly incomplete costs the
	 * shop nothing it cannot get back.
	 *
	 * @return void
	 */
	public function test_product_is_drafted_when_the_article_loses_its_image() {
		$sync = $this->sync();

		$sync->import_article( $this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ), 1000 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'no_image', $sync->import_article( $this->article(), 1001 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );

		// The article is still in the feed, so the run stamp has to keep up or
		// finalise() would later draft it for the wrong reason.
		$this->assertSame( '1001', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A product this plugin never imported is left alone.
	 *
	 * The marker meta is the only thing separating our products from a shop manager's
	 * own, and this setting governs the catalogue rather than the shop.
	 *
	 * @return void
	 */
	public function test_shop_managers_own_product_is_not_drafted() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_status( 'publish' );
		$id = $product->save();

		// Not "no_image": that outcome means a product was drafted, and this one is the
		// shop manager's own, left published exactly as it was.
		$this->assertSame( 'unmanaged', $this->sync()->import_article( $this->article(), 1000 ) );

		$untouched = wc_get_product( $id );

		$this->assertSame( 'publish', $untouched->get_status() );
		$this->assertSame( '', (string) $untouched->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
		$this->assertSame( '', (string) $untouched->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A status this sync does not own is neither drafted nor marked.
	 *
	 * Marking it would hand a later run the right to publish something somebody
	 * deliberately took out of the shop.
	 *
	 * @return void
	 */
	public function test_private_product_is_not_marked() {
		$id = $this->imported_product( 'abel-AB12', 'private' );

		$this->assertSame( 'no_image', $this->sync()->import_article( $this->article(), 2000 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'private', $product->get_status() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
		$this->assertSame( '2000', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * The check runs before the unchanged-article shortcut.
	 *
	 * An article that has not moved since the last run is exactly the case turning the
	 * setting on has to catch. Checking after the hash comparison would leave the whole
	 * existing catalogue published until every article in it happened to change.
	 *
	 * @return void
	 */
	public function test_unchanged_imageless_article_is_still_caught() {
		$row = $this->article();

		$this->assertSame( 'created', $this->sync( false )->import_article( $row, 1000 ) );

		// The same row again, so the change hash matches and the shortcut would fire.
		$this->assertSame( 'no_image', $this->sync()->import_article( $row, 1001 ) );
		$this->assertSame( 'draft', wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) )->get_status() );
	}

	/**
	 * The product comes back by itself when the article has an image again.
	 *
	 * @return void
	 */
	public function test_product_is_republished_when_the_image_returns() {
		$sync = $this->sync();

		$sync->import_article( $this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ), 1000 );
		$sync->import_article( $this->article(), 1001 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		$sync->import_article( $this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ), 1002 );

		$restored = wc_get_product( $id );

		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( '', (string) $restored->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
	}

	/**
	 * Turning the setting off republishes what it drafted.
	 *
	 * @return void
	 */
	public function test_turning_the_requirement_off_republishes() {
		$this->sync()->import_article( $this->article( array( 'MainImageURL' => 'x.jpg' ) ), 1000 );
		$this->sync()->import_article( $this->article(), 1001 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		$this->sync( false )->import_article( $this->article(), 1002 );

		$this->assertSame( 'publish', wc_get_product( $id )->get_status() );
	}

	/**
	 * A product held back for its image is not republished by the stock sync.
	 *
	 * The stock sync no longer touches a product's status at all, so a level arriving
	 * for an article Kontor has no picture of leaves the product exactly where the
	 * image requirement put it.
	 *
	 * @return void
	 */
	public function test_stock_sync_does_not_republish_an_imageless_product() {
		$id = $this->imported_product(
			'abel-AB12',
			'draft',
			array( ProductSync::META_NO_IMAGE_DRAFTED => 1 )
		);

		( new StockSync( null, array() ) )->apply( array( 'abel-AB12' => 4 ) );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		// The catalogue listing it with an image is what finally brings it back.
		$this->sync()->import_article( $this->article( array( 'MainImageURL' => 'x.jpg' ) ), 2000 );

		$this->assertSame( 'publish', wc_get_product( $id )->get_status() );
	}

	/**
	 * An article that is still imageless stays drafted, spent marker or not.
	 *
	 * Clearing the old stock marker must not reach past its own reason. The image
	 * requirement is checked before the restore path is ever entered, so a product
	 * carrying both is held back by the live reason and the spent one simply waits.
	 *
	 * @return void
	 */
	public function test_a_still_imageless_product_is_not_freed_by_the_spent_stock_marker() {
		$id = $this->imported_product(
			'abel-AB12',
			'draft',
			array(
				ProductSync::META_NO_IMAGE_DRAFTED     => 1,
				ProductSync::META_LEGACY_STOCK_DRAFTED => 1,
			)
		);

		$this->sync()->import_article( $this->article(), 2000 );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );

		// The picture arriving clears both at once.
		$this->sync()->import_article( $this->article( array( 'MainImageURL' => 'x.jpg' ) ), 2001 );

		$restored = wc_get_product( $id );

		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( '', (string) $restored->get_meta( ProductSync::META_LEGACY_STOCK_DRAFTED ) );
	}

	/**
	 * The run summary says how many articles were held back.
	 *
	 * @return void
	 */
	public function test_summary_reports_imageless_articles() {
		$sync = $this->sync();
		$run  = Status::start( ProductSync::JOB );

		Status::progress(
			ProductSync::JOB,
			array(
				'created'  => 3,
				'no_image' => 7,
			)
		);

		$sync->finalise( $run );

		$this->assertStringContainsString( 'Held 7 back as drafts for having no image.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * A run that passed nothing over says nothing about it.
	 *
	 * @return void
	 */
	public function test_summary_stays_clean_without_imageless_articles() {
		$sync = $this->sync();
		$run  = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$sync->finalise( $run );

		$this->assertSame( '3 created, 0 updated, 0 unchanged, 0 drafted.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * A cleared checkbox turns the setting off.
	 *
	 * @return void
	 */
	public function test_cleared_checkbox_turns_the_requirement_off() {
		update_option( Settings::OPTION_KEY, array( 'require_main_image' => true ) );

		$this->assertFalse( ( new Settings() )->sanitize( array( 'require_main_image' => '0' ) )['require_main_image'] );
		$this->assertTrue( ( new Settings() )->sanitize( array( 'require_main_image' => '1' ) )['require_main_image'] );
	}

	/**
	 * A submission that omits the field keeps the stored value.
	 *
	 * Same reasoning as the intervals and the shop: a partial save must never silently
	 * republish every article this setting is holding back.
	 *
	 * @return void
	 */
	public function test_missing_checkbox_keeps_the_stored_value() {
		update_option( Settings::OPTION_KEY, array( 'require_main_image' => true ) );

		$this->assertTrue( ( new Settings() )->sanitize( array( 'shoptype' => 'B2B' ) )['require_main_image'] );
	}
}
