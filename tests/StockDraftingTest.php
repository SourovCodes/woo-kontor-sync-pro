<?php
/**
 * Tests for the optional drafting of articles the stock feed leaves out.
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
 * Covers the setting, the pass it switches on, and what happens to the products
 * it drafted when it is switched off again.
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
	 * A stock sync with the drafting switched on.
	 *
	 * @return StockSync Configured sync.
	 */
	private function sync_with_drafting() {
		return new StockSync( null, array( Settings::DRAFT_MISSING_STOCK => true ) );
	}

	/**
	 * A stock sync left as a fresh install has it.
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
	 * An article row for the product sync, matching imported_product()'s SKU.
	 *
	 * @param string $sku Article number.
	 * @return array Article row.
	 */
	private function article( $sku ) {
		return array(
			'Artnr'        => $sku,
			'Bez1'         => 'An article',
			'Shoptitel'    => 'An article',
			'UVP'          => 12.5,
			'Lagerbestand' => 3,
			'MainImageURL' => null,
		);
	}

	/**
	 * The drafting is off unless a shop asks for it.
	 *
	 * 0.13.0 removed it outright, and this is the behaviour a shop updating into
	 * this version already has: it must not be turned back on for them.
	 *
	 * @return void
	 */
	public function test_drafting_is_off_by_default() {
		$this->assertFalse( Settings::default_settings()[ Settings::DRAFT_MISSING_STOCK ] );
		$this->assertFalse( Settings::get_settings()[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * The checkbox can be turned on and off again.
	 *
	 * @return void
	 */
	public function test_the_toggle_is_stored_both_ways() {
		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array( Settings::DRAFT_MISSING_STOCK => '1' ) )[ Settings::DRAFT_MISSING_STOCK ] );

		update_option( Settings::OPTION_KEY, array( Settings::DRAFT_MISSING_STOCK => true ) );

		$this->assertFalse( $settings->sanitize( array( Settings::DRAFT_MISSING_STOCK => '0' ) )[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * A submission that omits the field keeps what was stored.
	 *
	 * Same reasoning as the intervals: a partial save must not quietly switch the
	 * drafting off and republish a catalogue's worth of products.
	 *
	 * @return void
	 */
	public function test_missing_field_keeps_the_stored_value() {
		update_option( Settings::OPTION_KEY, array( Settings::DRAFT_MISSING_STOCK => true ) );

		$this->assertTrue( ( new Settings() )->sanitize( array( 'shoptype' => 'B2B' ) )[ Settings::DRAFT_MISSING_STOCK ] );
	}

	/**
	 * With the drafting off, an article missing from the feed is left alone.
	 *
	 * @return void
	 */
	public function test_missing_article_is_left_published_by_default() {
		$dropped = $this->imported_product( 'GONE-FROM-STOCK' );

		$sync = $this->sync_without_drafting();
		$run  = Status::start( StockSync::JOB );

		$sync->apply( array( 'SOMETHING-ELSE' => 2 ), $run );
		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $dropped )->get_status() );
		$this->assertSame( '', (string) get_post_meta( $dropped, StockSync::META_STOCK_DRAFTED, true ) );
	}

	/**
	 * Nothing is stamped while the drafting is off.
	 *
	 * The stamp exists for the pass, and writing one per product per run on a feed
	 * of three thousand articles, every fifteen minutes, is not a cost to carry for
	 * a pass that is not going to run.
	 *
	 * @return void
	 */
	public function test_nothing_is_stamped_while_the_drafting_is_off() {
		$product = $this->imported_product( 'IN-STOCK' );

		$this->sync_without_drafting()->apply( array( 'IN-STOCK' => 4 ), 5000 );

		$this->assertSame( '', (string) get_post_meta( $product, StockSync::META_STOCK_AT, true ) );
		$this->assertSame( 4, wc_get_product( $product )->get_stock_quantity() );
	}

	/**
	 * With the drafting on, an article missing from the feed is drafted.
	 *
	 * @return void
	 */
	public function test_missing_article_is_drafted_when_the_setting_is_on() {
		$kept    = $this->imported_product( 'STILL-STOCKED' );
		$dropped = $this->imported_product( 'GONE-FROM-STOCK' );

		$sync = $this->sync_with_drafting();
		$run  = Status::start( StockSync::JOB );

		$sync->apply( array( 'STILL-STOCKED' => 3 ), $run );
		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $kept )->get_status() );
		$this->assertSame( (string) $run, (string) get_post_meta( $kept, StockSync::META_STOCK_AT, true ) );

		$this->assertSame( 'draft', wc_get_product( $dropped )->get_status() );
		$this->assertSame( '1', (string) get_post_meta( $dropped, StockSync::META_STOCK_DRAFTED, true ) );
		$this->assertSame( 'success', Status::get( StockSync::JOB )['state'] );
	}

	/**
	 * A shop manager's own product is never drafted for missing the feed.
	 *
	 * It was never in one. META_SYNCED_AT is the marker for "this plugin imported
	 * this", and without it the product is not ours to hide.
	 *
	 * @return void
	 */
	public function test_a_foreign_product_is_not_drafted() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'HAND-MADE' );
		$product->set_status( 'publish' );
		$product->save();

		$sync = $this->sync_with_drafting();
		$run  = Status::start( StockSync::JOB );

		$sync->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $product->get_id() )->get_status() );
	}

	/**
	 * An article returning to the feed is published again.
	 *
	 * @return void
	 */
	public function test_a_drafted_article_is_republished_when_it_returns() {
		$product = $this->imported_product( 'BACK-IN-STOCK' );

		$sync = $this->sync_with_drafting();
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
	 * A product a person drafted is left alone when its level arrives.
	 *
	 * @return void
	 */
	public function test_a_manually_drafted_product_is_not_republished() {
		$product = $this->imported_product( 'HIDDEN-ON-PURPOSE', 'draft' );

		$counts = $this->sync_with_drafting()->apply( array( 'HIDDEN-ON-PURPOSE' => 9 ), 5000 );

		$refreshed = wc_get_product( $product );

		$this->assertSame( 0, $counts['restored'] );
		$this->assertSame( 'draft', $refreshed->get_status() );

		// The level is still applied: the product is hidden, not unmanaged.
		$this->assertSame( 9, $refreshed->get_stock_quantity() );
	}

	/**
	 * Turning the drafting off gives back the products it drafted.
	 *
	 * Nothing else could. A product drafted for missing the stock feed is absent
	 * from that feed by definition, so apply() never reaches it.
	 *
	 * @return void
	 */
	public function test_turning_it_off_releases_what_it_drafted() {
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
	 * The marker still goes — this sync's reason has gone — but the catalogue not
	 * listing the article is a different feed's verdict and not ours to undo.
	 *
	 * @return void
	 */
	public function test_releasing_leaves_a_product_the_catalogue_dropped_drafted() {
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
	}

	/**
	 * A stale marker on a product republished by hand is cleared.
	 *
	 * It describes a draft that no longer exists, and the product sync reads it as
	 * this feed still having nothing for the article — so left in place it would
	 * hold the product back long afterwards, for a level that has been arriving all
	 * along.
	 *
	 * @return void
	 */
	public function test_a_stale_marker_on_a_published_product_is_cleared() {
		$product = $this->imported_product(
			'REPUBLISHED-BY-HAND',
			'publish',
			array( StockSync::META_STOCK_DRAFTED => 1 )
		);

		$counts = $this->sync_with_drafting()->apply( array( 'REPUBLISHED-BY-HAND' => 4 ), 5000 );

		$refreshed = wc_get_product( $product );

		$this->assertSame( 0, $counts['restored'] );
		$this->assertSame( 'publish', $refreshed->get_status() );
		$this->assertSame( '', (string) $refreshed->get_meta( StockSync::META_STOCK_DRAFTED ) );
	}

	/**
	 * The product sync leaves an article with no stock record drafted.
	 *
	 * The catalogue listing an article again says nothing about whether Kontor
	 * holds any stock of it, so this marker blocks where the pre-0.13.0 one — which
	 * nothing writes any more — releases.
	 *
	 * @return void
	 */
	public function test_the_product_sync_will_not_republish_an_article_with_no_stock_record() {
		$product = $this->imported_product(
			'abel-AB12',
			'draft',
			array(
				StockSync::META_STOCK_DRAFTED  => 1,
				ProductSync::META_SYNC_DRAFTED => 1,
			)
		);

		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article( 'abel-AB12' ), 2000 );

		$after = wc_get_product( $product );

		$this->assertSame( 'draft', $after->get_status() );
		$this->assertSame( '', (string) $after->get_meta( ProductSync::META_SYNC_DRAFTED ) );
		$this->assertSame( '1', (string) $after->get_meta( StockSync::META_STOCK_DRAFTED ) );

		// The stock feed carries it again, and the last reason goes with it.
		$this->sync_with_drafting()->apply( array( 'abel-AB12' => 4 ), 5000 );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * The pre-0.13.0 marker still means the opposite, and still releases.
	 *
	 * A shop that never turns the drafting on must still have the products the old
	 * pass drafted handed back to it.
	 *
	 * @return void
	 */
	public function test_the_legacy_marker_still_releases_a_product() {
		$product = $this->imported_product(
			'abel-AB12',
			'draft',
			array( ProductSync::META_LEGACY_STOCK_DRAFTED => 1 )
		);

		( new ProductSync( null, array( 'image_base_url' => '' ) ) )->import_article( $this->article( 'abel-AB12' ), 2000 );

		$after = wc_get_product( $product );

		$this->assertSame( 'publish', $after->get_status() );
		$this->assertSame( '', (string) $after->get_meta( ProductSync::META_LEGACY_STOCK_DRAFTED ) );
	}

	/**
	 * A run with the drafting off and nothing to release closes itself.
	 *
	 * This is the steady state — every fifteen minutes on an ordinary shop — and a
	 * chunk that returned without completing would leave the job reporting
	 * "running" and refusing to start another for six hours.
	 *
	 * @return void
	 */
	public function test_the_ordinary_run_closes_itself() {
		$product = $this->imported_product( 'IN-STOCK' );

		$sync = $this->sync_without_drafting();
		$run  = Status::start( StockSync::JOB );

		// The chunk action reads the run's payload back out of its transient.
		set_transient( StockSync::TRANSIENT_PREFIX . $run, array( 'IN-STOCK' => 4 ), HOUR_IN_SECONDS );

		$sync->apply_chunk( 0, $run );

		$this->assertSame( 'success', Status::get( StockSync::JOB )['state'] );
		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * A superseded pass touches nothing.
	 *
	 * @return void
	 */
	public function test_a_superseded_pass_is_discarded() {
		$product = $this->imported_product( 'GONE-FROM-STOCK' );
		$current = Status::start( StockSync::JOB );

		$this->sync_with_drafting()->finalise( $current - 500 );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
	}

	/**
	 * The summary reports the drafting, and only when there was some.
	 *
	 * @return void
	 */
	public function test_the_summary_reports_drafting_only_when_it_happened() {
		$this->imported_product( 'GONE-FROM-STOCK' );

		$sync = $this->sync_with_drafting();
		$run  = Status::start( StockSync::JOB );

		Status::progress( StockSync::JOB, $sync->apply( array( 'not-in-woo' => 1 ), $run ) );
		$sync->finalise( $run );

		$this->assertSame(
			'0 products updated, 1 article numbers had no matching SKU, 0 skipped as not stock-managed. 1 drafted for having no stock record, 0 republished.',
			Status::get( StockSync::JOB )['message']
		);
	}

	/**
	 * A shop that leaves the drafting off gets the summary 0.13.0 gave.
	 *
	 * The sentence about drafting is added only when there is something to say, so
	 * the ordinary shop's report is not padded with two zeroes about a pass it does
	 * not run.
	 *
	 * @return void
	 */
	public function test_a_quiet_run_reports_the_plain_summary() {
		$this->imported_product( 'IN-STOCK' );

		$run = Status::start( StockSync::JOB );

		set_transient( StockSync::TRANSIENT_PREFIX . $run, array( 'IN-STOCK' => 4 ), HOUR_IN_SECONDS );

		$this->sync_without_drafting()->apply_chunk( 0, $run );

		$this->assertSame(
			'1 products updated, 0 article numbers had no matching SKU, 0 skipped as not stock-managed.',
			Status::get( StockSync::JOB )['message']
		);
	}
}
