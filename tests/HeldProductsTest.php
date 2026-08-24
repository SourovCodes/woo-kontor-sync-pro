<?php
/**
 * Tests for the held-back products view on the products screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\HeldProducts;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\StockSync;
use WP_Query;
use WP_UnitTestCase;

/**
 * Covers the counts, the views, the filtering and the labels beside each product.
 */
class HeldProductsTest extends WP_UnitTestCase {

	/**
	 * Forget any reason left in the query string by a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $_GET[ HeldProducts::QUERY_VAR ] );

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's own registry, restored so one test's list query cannot be seen by the next.
		$GLOBALS['wp_the_query'] = new WP_Query();

		parent::tear_down();
	}

	/**
	 * A drafted product carrying one of the markers.
	 *
	 * @param string $meta_key Marker to write.
	 * @param string $sku      Article number.
	 * @return int Product ID.
	 */
	private function held( $meta_key, $sku ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Held ' . $sku );
		$product->set_sku( $sku );
		$product->set_status( 'draft' );
		$product->update_meta_data( $meta_key, 1 );
		$product->save();

		return $product->get_id();
	}

	/**
	 * An ordinary published product nothing is holding back.
	 *
	 * @param string $sku Article number.
	 * @return int Product ID.
	 */
	private function published( $sku ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Selling ' . $sku );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Ask for a reason, as the products screen's query string would.
	 *
	 * @param string $slug Reason slug.
	 * @return void
	 */
	private function request( $slug ) {
		$_GET[ HeldProducts::QUERY_VAR ] = $slug;
	}

	/**
	 * A query standing in for the one the products screen runs.
	 *
	 * The filter only acts on the main query on an admin screen, so both have to hold
	 * here or it would be tested doing nothing.
	 *
	 * @return WP_Query Query registered as the main one.
	 */
	private function main_query() {
		set_current_screen( 'edit-product' );

		$query = new WP_Query();

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- What is_main_query() compares against; there is no other way to be one.
		$GLOBALS['wp_the_query'] = $query;

		return $query;
	}

	/**
	 * Run the products list query the way the screen does.
	 *
	 * @return int[] Product IDs the list would show.
	 */
	private function listed() {
		$panel = new HeldProducts();

		add_action( 'pre_get_posts', array( $panel, 'filter_query' ) );

		$ids = $this->main_query()->query(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);

		remove_action( 'pre_get_posts', array( $panel, 'filter_query' ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * Every reason is counted, including the ones holding nothing.
	 *
	 * @return void
	 */
	public function test_each_reason_is_counted_separately() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-2' );
		$this->held( ProductSync::META_SYNC_DRAFTED, 'de-1' );
		$this->held( StockSync::META_STOCK_DRAFTED, 'st-1' );
		$this->published( 'ok-1' );

		$counts = HeldProducts::counts();

		$this->assertSame( 2, $counts['inactive'] );
		$this->assertSame( 1, $counts['delisted'] );
		$this->assertSame( 1, $counts['no_stock'] );
		$this->assertSame( 0, $counts['no_image'] );
		$this->assertSame( 0, $counts['legacy_stock'] );
		$this->assertSame( 4, HeldProducts::total() );
	}

	/**
	 * A shop with nothing held back gets no counts and no views.
	 *
	 * @return void
	 */
	public function test_a_shop_holding_nothing_back_is_left_alone() {
		$this->published( 'ok-1' );

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertSame( 0, HeldProducts::total() );
		$this->assertSame( array( 'all' ), array_keys( $views ) );
	}

	/**
	 * A reason holding products gets a view; a reason holding none does not.
	 *
	 * @return void
	 */
	public function test_only_reasons_holding_something_get_a_view() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertArrayHasKey( 'wksync_held_inactive', $views );
		$this->assertArrayNotHasKey( 'wksync_held_no_image', $views );
		$this->assertStringContainsString( 'wksync_held=inactive', $views['wksync_held_inactive'] );
		$this->assertStringContainsString( '(1)', $views['wksync_held_inactive'] );
	}

	/**
	 * The view being looked at is the one marked current, and core's is not.
	 *
	 * @return void
	 */
	public function test_the_chosen_view_takes_the_current_marking_off_the_others() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$this->request( 'inactive' );

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#" class="current" aria-current="page">All</a>' ) );

		$this->assertStringNotContainsString( 'current', $views['all'] );
		$this->assertStringContainsString( 'aria-current="page"', $views['wksync_held_inactive'] );
	}

	/**
	 * An unfiltered list keeps core's own marking exactly as it was.
	 *
	 * @return void
	 */
	public function test_an_unfiltered_list_leaves_cores_views_untouched() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#" class="current" aria-current="page">All</a>' ) );

		$this->assertStringContainsString( 'class="current"', $views['all'] );
	}

	/**
	 * Asking for one reason lists that reason's products and nothing else.
	 *
	 * @return void
	 */
	public function test_a_reason_narrows_the_list_to_its_own_products() {
		$inactive  = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$delisted  = $this->held( ProductSync::META_SYNC_DRAFTED, 'de-1' );
		$published = $this->published( 'ok-1' );

		$this->request( 'inactive' );

		$listed = $this->listed();

		$this->assertContains( $inactive, $listed );
		$this->assertNotContains( $delisted, $listed );
		$this->assertNotContains( $published, $listed );
	}

	/**
	 * Asking for all of them lists every held product and no others.
	 *
	 * @return void
	 */
	public function test_every_reason_at_once_lists_them_all() {
		$inactive  = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$delisted  = $this->held( ProductSync::META_SYNC_DRAFTED, 'de-1' );
		$stock     = $this->held( StockSync::META_STOCK_DRAFTED, 'st-1' );
		$published = $this->published( 'ok-1' );

		$this->request( HeldProducts::ANY );

		$listed = $this->listed();

		$this->assertContains( $inactive, $listed );
		$this->assertContains( $delisted, $listed );
		$this->assertContains( $stock, $listed );
		$this->assertNotContains( $published, $listed );
	}

	/**
	 * A slug that is not a reason filters nothing rather than emptying the list.
	 *
	 * @return void
	 */
	public function test_an_unknown_reason_leaves_the_list_alone() {
		$held      = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$published = $this->published( 'ok-1' );

		$this->request( 'whatever' );

		$listed = $this->listed();

		$this->assertContains( $held, $listed );
		$this->assertContains( $published, $listed );
	}

	/**
	 * A product held back for two reasons is named for both.
	 *
	 * @return void
	 */
	public function test_a_product_held_for_two_reasons_says_both() {
		$id = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		update_post_meta( $id, ProductSync::META_NO_IMAGE_DRAFTED, 1 );

		$panel  = new HeldProducts();
		$states = $panel->add_state( array(), get_post( $id ) );

		$this->assertSame(
			array( HeldProducts::label( 'inactive' ), HeldProducts::label( 'no_image' ) ),
			array_values( $states )
		);
	}

	/**
	 * A product nothing is holding back is not labelled.
	 *
	 * @return void
	 */
	public function test_an_ordinary_product_is_not_labelled() {
		$id = $this->published( 'ok-1' );

		$panel  = new HeldProducts();
		$states = $panel->add_state( array( 'draft' => 'Draft' ), get_post( $id ) );

		$this->assertSame( array( 'draft' ), array_keys( $states ) );
	}

	/**
	 * A post that is not a product is left entirely alone.
	 *
	 * @return void
	 */
	public function test_a_post_that_is_not_a_product_is_left_alone() {
		$id = self::factory()->post->create();

		update_post_meta( $id, ProductSync::META_INACTIVE_DRAFTED, 1 );

		$panel  = new HeldProducts();
		$states = $panel->add_state( array(), get_post( $id ) );

		$this->assertSame( array(), $states );
	}

	/**
	 * A meta query already on the list survives the filter being added to it.
	 *
	 * WooCommerce's own stock filter puts one there, and replacing it would silently
	 * widen whatever the shop manager had already narrowed.
	 *
	 * @return void
	 */
	public function test_a_meta_query_already_on_the_list_is_kept() {
		$this->request( 'inactive' );

		$query = $this->main_query();
		$query->set( 'post_type', 'product' );
		$query->set(
			'meta_query',
			array(
				array(
					'key'   => '_stock_status',
					'value' => 'outofstock',
				),
			)
		);

		$panel = new HeldProducts();
		$panel->filter_query( $query );

		$meta_query = $query->get( 'meta_query' );

		$this->assertCount( 2, $meta_query );
		$this->assertSame( '_stock_status', $meta_query[0]['key'] );
		$this->assertSame( ProductSync::META_INACTIVE_DRAFTED, $meta_query[1]['key'] );
	}

	/**
	 * A draft nothing here accounts for is counted as the shop's own.
	 *
	 * @return void
	 */
	public function test_the_drafts_nobody_synced_are_counted_apart() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );
		$this->held( ProductSync::META_SYNC_DRAFTED, 'de-1' );
		$this->published( 'ok-1' );

		$own = new WC_Product_Simple();
		$own->set_name( 'Half written' );
		$own->set_status( 'draft' );
		$own->save();

		$this->assertSame( 1, HeldProducts::unheld_drafts() );
	}

	/**
	 * A product held back for one reason is not one of the shop's own drafts.
	 *
	 * Every marker has to be absent, not merely the one being looked at, or a product
	 * held back for a second reason would be counted as somebody's work in progress.
	 *
	 * @return void
	 */
	public function test_a_product_held_for_any_reason_is_not_counted_as_the_shops_own() {
		$id = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		update_post_meta( $id, ProductSync::META_NO_IMAGE_DRAFTED, 1 );

		$this->assertSame( 0, HeldProducts::unheld_drafts() );
	}

	/**
	 * The inverse view lists the shop's own drafts and none of the held ones.
	 *
	 * @return void
	 */
	public function test_the_inverse_view_lists_only_the_drafts_nobody_synced() {
		$held = $this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		$own = new WC_Product_Simple();
		$own->set_name( 'Half written' );
		$own->set_status( 'draft' );
		$own->save();

		$this->request( HeldProducts::NONE );

		$listed = $this->listed();

		$this->assertContains( $own->get_id(), $listed );
		$this->assertNotContains( $held, $listed );
	}

	/**
	 * The inverse view appears once something is held back, and says it is about drafts.
	 *
	 * @return void
	 */
	public function test_the_inverse_view_is_offered_alongside_the_reasons() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		$own = new WC_Product_Simple();
		$own->set_name( 'Half written' );
		$own->set_status( 'draft' );
		$own->save();

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertArrayHasKey( 'wksync_held_none', $views );
		$this->assertStringContainsString( 'wksync_held=none', $views['wksync_held_none'] );
		$this->assertStringContainsString( 'post_status=draft', $views['wksync_held_none'] );
		$this->assertStringContainsString( '(1)', $views['wksync_held_none'] );
	}

	/**
	 * A shop holding nothing back is not offered the inverse either.
	 *
	 * With no reason in play it would say exactly what core's own Drafts view says.
	 *
	 * @return void
	 */
	public function test_the_inverse_view_is_withheld_when_nothing_is_held_back() {
		$own = new WC_Product_Simple();
		$own->set_name( 'Half written' );
		$own->set_status( 'draft' );
		$own->save();

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertSame( array( 'all' ), array_keys( $views ) );
	}

	/**
	 * Nothing left to hold back means no inverse view, however many drafts there are.
	 *
	 * @return void
	 */
	public function test_the_inverse_view_needs_drafts_of_its_own_to_show() {
		$this->held( ProductSync::META_INACTIVE_DRAFTED, 'in-1' );

		$panel = new HeldProducts();
		$views = $panel->add_views( array( 'all' => '<a href="#">All</a>' ) );

		$this->assertArrayHasKey( 'wksync_held_inactive', $views );
		$this->assertArrayNotHasKey( 'wksync_held_none', $views );
	}

	/**
	 * The inverse view is not a reason, so it labels no product.
	 *
	 * @return void
	 */
	public function test_the_inverse_view_labels_nothing() {
		$own = new WC_Product_Simple();
		$own->set_name( 'Half written' );
		$own->set_status( 'draft' );
		$own->save();

		$panel  = new HeldProducts();
		$states = $panel->add_state( array(), get_post( $own->get_id() ) );

		$this->assertSame( array(), $states );
	}

	/**
	 * Every reason has a label, so no view can render with a blank name.
	 *
	 * @return void
	 */
	public function test_every_reason_has_a_label() {
		foreach ( array_keys( HeldProducts::reasons() ) as $slug ) {
			$this->assertNotSame( '', HeldProducts::label( $slug ), $slug );
		}
	}
}
