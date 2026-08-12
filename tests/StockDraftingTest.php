<?php
/**
 * Tests for the setting that decides whether the stock sync drafts.
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
 * Covers the setting itself, the drafting it suppresses, and the products it
 * gives back when it is turned off.
 */
class StockDraftingTest extends WP_UnitTestCase {

	/**
	 * Remove the option between tests so each one starts from the defaults.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * A stock sync with the drafting turned off.
	 *
	 * @return StockSync Configured sync.
	 */
	private function sync_without_drafting() {
		return new StockSync( null, array( Settings::DRAFT_MISSING_STOCK => false ) );
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
	 * The drafting is on unless a shop turns it off.
	 *
	 * Unlike every other toggle here, which default to off. This one is not a new
	 * behaviour being offered but an old one being made optional, and defaulting it
	 * to off would change what an existing shop does on the day it updates.
	 *
	 * @return void
	 */
	public function test_drafting_is_on_by_default() {
		$this->assertTrue( Settings::default_settings()[ Settings::DRAFT_MISSING_STOCK ] );
		$this->assertTrue( Settings::get_settings()[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * The checkbox can be turned off and on again.
	 *
	 * @return void
	 */
	public function test_the_toggle_is_stored_both_ways() {
		$settings = new Settings();

		$this->assertFalse( $settings->sanitize( array( Settings::DRAFT_MISSING_STOCK => '0' ) )[ Settings::DRAFT_MISSING_STOCK ] );

		update_option( Settings::OPTION_KEY, array( Settings::DRAFT_MISSING_STOCK => false ) );

		$this->assertTrue( $settings->sanitize( array( Settings::DRAFT_MISSING_STOCK => '1' ) )[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * A submission that omits the field keeps what was stored.
	 *
	 * Same reasoning as the intervals: a partial save must not silently switch a
	 * shop's drafting back on, which for a large catalogue would hide products by
	 * the thousand.
	 *
	 * @return void
	 */
	public function test_missing_field_keeps_the_stored_value() {
		update_option( Settings::OPTION_KEY, array( Settings::DRAFT_MISSING_STOCK => false ) );

		$this->assertFalse( ( new Settings() )->sanitize( array( 'shoptype' => 'B2B' ) )[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * With the setting cleared, an article missing from the feed stays published.
	 *
	 * @return void
	 */
	public function test_missing_article_is_left_alone_when_drafting_is_off() {
		$dropped = $this->imported_product( 'GONE-FROM-STOCK' );

		$sync = $this->sync_without_drafting();
		$run  = Status::start( StockSync::JOB );

		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $dropped )->get_status() );
		$this->assertSame( '', (string) get_post_meta( $dropped, StockSync::META_STOCK_DRAFTED, true ) );
		$this->assertSame( 'success', Status::get( StockSync::JOB )['state'] );
	}

	/**
	 * Turning the setting off gives back the products it had already drafted.
	 *
	 * Nothing else could: a product drafted for missing the stock feed is absent
	 * from that feed by definition, so apply() never reaches it. Without this pass
	 * clearing the setting would leave them hidden for good.
	 *
	 * @return void
	 */
	public function test_products_this_sync_drafted_are_released() {
		$product = $this->imported_product(
			'GONE-FROM-STOCK',
			'draft',
			array( StockSync::META_STOCK_DRAFTED => 1 )
		);

		$run = Status::start( StockSync::JOB );

		$this->sync_without_drafting()->finalise( $run );

		$released = wc_get_product( $product );

		$this->assertSame( 'publish', $released->get_status() );
		$this->assertSame( '', (string) $released->get_meta( StockSync::META_STOCK_DRAFTED ) );
		$this->assertSame( 1, Status::get( StockSync::JOB )['counts']['restored'] );
	}

	/**
	 * A product the product sync is also holding back stays drafted.
	 *
	 * The marker still goes, because this sync's reason has gone, but the catalogue
	 * not listing the article is a different feed's verdict and is not ours to undo.
	 * The product sync republishes it when the article returns to the catalogue.
	 *
	 * @return void
	 */
	public function test_a_product_the_catalogue_dropped_stays_drafted() {
		$product = $this->imported_product(
			'GONE-FROM-BOTH',
			'draft',
			array(
				StockSync::META_STOCK_DRAFTED  => 1,
				ProductSync::META_SYNC_DRAFTED => 1,
			)
		);

		$run = Status::start( StockSync::JOB );

		$this->sync_without_drafting()->finalise( $run );

		$held = wc_get_product( $product );

		$this->assertSame( 'draft', $held->get_status() );
		$this->assertSame( '', (string) $held->get_meta( StockSync::META_STOCK_DRAFTED ) );
		$this->assertSame( '1', (string) $held->get_meta( ProductSync::META_SYNC_DRAFTED ) );
		$this->assertSame( 0, Status::get( StockSync::JOB )['counts']['restored'] );
	}

	/**
	 * An article held back for having no image stays drafted too.
	 *
	 * @return void
	 */
	public function test_an_imageless_product_stays_drafted() {
		$product = $this->imported_product(
			'NO-PICTURE',
			'draft',
			array(
				StockSync::META_STOCK_DRAFTED      => 1,
				ProductSync::META_NO_IMAGE_DRAFTED => 1,
			)
		);

		$run = Status::start( StockSync::JOB );

		$this->sync_without_drafting()->finalise( $run );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
	}

	/**
	 * A product a person drafted is never published by the release pass.
	 *
	 * It carries no marker of ours, which is the whole point of the marker.
	 *
	 * @return void
	 */
	public function test_a_manually_drafted_product_is_not_released() {
		$product = $this->imported_product( 'HIDDEN-ON-PURPOSE', 'draft' );

		$run = Status::start( StockSync::JOB );

		$this->sync_without_drafting()->finalise( $run );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
	}

	/**
	 * A superseded release pass touches nothing.
	 *
	 * @return void
	 */
	public function test_superseded_release_pass_is_discarded() {
		$product = $this->imported_product(
			'GONE-FROM-STOCK',
			'draft',
			array( StockSync::META_STOCK_DRAFTED => 1 )
		);

		$current = Status::start( StockSync::JOB );

		$this->sync_without_drafting()->finalise( $current - 500 );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
	}

	/**
	 * The release pass closes the run, and says what it gave back.
	 *
	 * A pass that returned without completing would leave the job reporting
	 * "running" and refusing to start another for the next six hours.
	 *
	 * @return void
	 */
	public function test_the_release_pass_completes_the_run() {
		$this->imported_product(
			'GONE-FROM-STOCK',
			'draft',
			array( StockSync::META_STOCK_DRAFTED => 1 )
		);

		$sync = $this->sync_without_drafting();
		$run  = Status::start( StockSync::JOB );

		Status::progress( StockSync::JOB, $sync->apply( array( 'not-in-woo' => 1 ), $run ) );
		$sync->finalise( $run );

		$this->assertSame( 'success', Status::get( StockSync::JOB )['state'] );
		$this->assertSame(
			'0 products updated, 1 article numbers had no matching SKU, 0 skipped as not stock-managed, 0 drafted, 1 republished.',
			Status::get( StockSync::JOB )['message']
		);
	}

	/**
	 * A marker left on a product somebody republished by hand is cleared.
	 *
	 * It describes a draft that no longer exists, and the product sync reads it as
	 * this feed still having nothing for the article — so left in place it would keep
	 * the product drafted, long afterwards, for a stock level that has been arriving
	 * the whole time.
	 *
	 * @return void
	 */
	public function test_a_stale_marker_on_a_published_product_is_cleared() {
		$product = $this->imported_product(
			'REPUBLISHED-BY-HAND',
			'publish',
			array( StockSync::META_STOCK_DRAFTED => 1 )
		);

		$counts = ( new StockSync( null, array() ) )->apply( array( 'REPUBLISHED-BY-HAND' => 4 ), time() );

		$refreshed = wc_get_product( $product );

		// Nothing was republished — it was already published — but the marker has gone.
		$this->assertSame( 0, $counts['restored'] );
		$this->assertSame( 'publish', $refreshed->get_status() );
		$this->assertSame( '', (string) $refreshed->get_meta( StockSync::META_STOCK_DRAFTED ) );
		$this->assertSame( 4, $refreshed->get_stock_quantity() );
	}

	/**
	 * With the setting on, the drafting behaves exactly as it always has.
	 *
	 * The default is what an existing shop gets on the day it updates, so this is
	 * the case that must not have moved.
	 *
	 * @return void
	 */
	public function test_the_stored_setting_is_honoured_when_on() {
		$dropped = $this->imported_product( 'GONE-FROM-STOCK' );

		update_option( Settings::OPTION_KEY, array( Settings::DRAFT_MISSING_STOCK => true ) );

		$sync = new StockSync( null, Settings::get_settings() );
		$run  = Status::start( StockSync::JOB );

		$sync->finalise( $run );

		$this->assertSame( 'draft', wc_get_product( $dropped )->get_status() );
		$this->assertSame( '1', (string) get_post_meta( $dropped, StockSync::META_STOCK_DRAFTED, true ) );
	}
}
