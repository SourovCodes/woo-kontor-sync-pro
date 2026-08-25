<?php
/**
 * Tests for the setting that sweeps products Kontor does not list into the trash.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers what the sweep removes, what it deliberately spares, and the fact that it
 * trashes rather than deletes.
 */
class TrashUnmanagedTest extends WP_UnitTestCase {

	/**
	 * A product sync with the sweep switched on.
	 *
	 * @return ProductSync Configured sync.
	 */
	private function sweeping_sync() {
		return new ProductSync(
			null,
			array(
				'image_base_url'          => '',
				'require_main_image'      => false,
				Settings::TRASH_UNMANAGED => true,
			)
		);
	}

	/**
	 * A product sync with the sweep left alone, which is the default.
	 *
	 * @return ProductSync Configured sync.
	 */
	private function ordinary_sync() {
		return new ProductSync(
			null,
			array(
				'image_base_url'     => '',
				'require_main_image' => false,
			)
		);
	}

	/**
	 * A product nothing in this plugin has ever touched.
	 *
	 * @param string $sku    Article number, or an empty string for none.
	 * @param string $status Post status.
	 * @param array  $meta   Extra meta to write.
	 * @return int Product ID.
	 */
	private function stray( $sku = 'made-by-hand', $status = 'publish', array $meta = array() ) {
		$product = new WC_Product_Simple();

		if ( '' !== $sku ) {
			$product->set_sku( $sku );
		}

		$product->set_name( 'A product of the shop\'s own' );
		$product->set_status( $status );

		foreach ( $meta as $key => $value ) {
			$product->update_meta_data( $key, $value );
		}

		return $product->save();
	}

	/**
	 * A product this plugin imported.
	 *
	 * @param string $sku Article number.
	 * @param int    $run Run that last stamped it.
	 * @return int Product ID.
	 */
	private function imported( $sku, $run ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, $run );

		return $product->save();
	}

	/**
	 * The status a product row currently holds, read past the CRUD cache.
	 *
	 * @param int $product_id Product to look at.
	 * @return string Post status.
	 */
	private function status_of( $product_id ) {
		return (string) get_post_status( $product_id );
	}

	/**
	 * Left alone, a product the plugin never imported survives the run untouched.
	 *
	 * This is the default and the behaviour every existing shop relies on.
	 *
	 * @return void
	 */
	public function test_a_stray_product_survives_when_the_setting_is_off() {
		$id  = $this->stray();
		$run = Status::start( ProductSync::JOB );

		$this->ordinary_sync()->finalise( $run );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * With the setting on, a product Kontor does not list goes to the trash.
	 *
	 * @return void
	 */
	public function test_a_stray_product_is_trashed_when_the_setting_is_on() {
		$id  = $this->stray();
		$run = Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'trash', $this->status_of( $id ) );
	}

	/**
	 * Every status is swept, not only the ones a customer cannot see.
	 *
	 * @return void
	 */
	public function test_private_and_draft_strays_are_trashed_too() {
		$private = $this->stray( 'kept-back', 'private' );
		$draft   = $this->stray( 'not-finished', 'draft' );
		$run     = Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'trash', $this->status_of( $private ) );
		$this->assertSame( 'trash', $this->status_of( $draft ) );
	}

	/**
	 * A product with no article number at all is swept as well.
	 *
	 * It cannot be in Kontor's catalogue, which is the whole question being asked.
	 *
	 * @return void
	 */
	public function test_a_product_with_no_sku_is_trashed() {
		$id  = $this->stray( '' );
		$run = Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'trash', $this->status_of( $id ) );
	}

	/**
	 * A product this plugin imported is never trashed by this pass.
	 *
	 * Dropping out of the catalogue is what finalise() drafts a product for. The
	 * sweep is about products that were never ours, and a stale run stamp is not
	 * the same thing as having no stamp at all.
	 *
	 * @return void
	 */
	public function test_a_product_this_plugin_imported_is_never_trashed() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->imported( 'abel-AB12', $run - 500 );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * A product held back for an article this plugin does not own is spared.
	 *
	 * This is the case the whole seen marker exists for. import_article() goes out
	 * of its way to leave such a product alone, and a sweep asking only "is it
	 * ours" would remove exactly the products that branch protects.
	 *
	 * @return void
	 */
	public function test_a_withheld_article_the_shop_already_had_is_spared() {
		$id = $this->stray( 'abel-AB12' );

		$run = Status::start( ProductSync::JOB );

		$outcome = $this->sweeping_sync()->import_article(
			array(
				'Artnr'    => 'abel-AB12',
				'Bez1'     => 'Abel blocks 12',
				'Ws_aktiv' => false,
			),
			$run
		);

		$this->assertSame( 'inactive', $outcome );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertSame( (string) $run, (string) get_post_meta( $id, ProductSync::META_SEEN_AT, true ) );
		$this->assertSame( '', (string) get_post_meta( $id, ProductSync::META_SYNCED_AT, true ) );
	}

	/**
	 * Products sharing an article number are spared as well.
	 *
	 * Nothing is written to them, so they end the run with no stamp — and the
	 * article is in the catalogue, which is what the sweep asks about.
	 *
	 * @return void
	 */
	public function test_products_sharing_an_article_number_are_spared() {
		$first = $this->stray( 'abel-AB12' );

		// WooCommerce rejects a duplicate SKU on save, so the only way to have two is
		// the way the field produces them: something short-circuiting the check.
		add_filter( 'wc_product_pre_has_unique_sku', '__return_true' );

		$second = $this->stray( 'abel-AB12' );

		remove_filter( 'wc_product_pre_has_unique_sku', '__return_true' );

		$run = Status::start( ProductSync::JOB );

		$outcome = $this->sweeping_sync()->import_article(
			array(
				'Artnr'    => 'abel-AB12',
				'Bez1'     => 'Abel blocks 12',
				'Ws_aktiv' => true,
			),
			$run
		);

		$this->assertSame( 'duplicate_sku', $outcome );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'publish', $this->status_of( $first ) );
		$this->assertSame( 'publish', $this->status_of( $second ) );
	}

	/**
	 * A marker from an earlier run does not protect a product for ever.
	 *
	 * An article withheld last month and dropped from the catalogue since is a
	 * product Kontor no longer lists, and the sweep is what removes it.
	 *
	 * @return void
	 */
	public function test_a_marker_from_an_earlier_run_does_not_protect_a_product() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->stray( 'gone-since', 'publish', array( ProductSync::META_SEEN_AT => $run - 500 ) );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'trash', $this->status_of( $id ) );
	}

	/**
	 * The product is trashed, not deleted, and its images stay in the library.
	 *
	 * Trashing is the whole of the safety here: a sweep that went too wide is undone
	 * from Products → Trash, and an attachment deleted alongside could not be.
	 *
	 * @return void
	 */
	public function test_the_product_is_recoverable_and_its_image_is_kept() {
		$attachment_id = $this->factory->attachment->create_object(
			array(
				'file'           => 'made-by-hand.jpg',
				'post_mime_type' => 'image/jpeg',
			)
		);

		$product = new WC_Product_Simple();
		$product->set_sku( 'made-by-hand' );
		$product->set_status( 'publish' );
		$product->set_image_id( $attachment_id );
		$id = $product->save();

		$run = Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 'trash', $this->status_of( $id ) );

		// The row is still there, which is what makes Products → Trash the way back.
		$this->assertNotNull( get_post( $id ) );

		// And the picture a restored product would need is untouched.
		$this->assertNotNull( get_post( $attachment_id ) );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
	}

	/**
	 * A product already in the trash is not picked up again.
	 *
	 * That exclusion is what makes the chain terminate: trashing a product takes it
	 * out of the next batch's reckoning.
	 *
	 * @return void
	 */
	public function test_a_trashed_product_is_not_swept_twice() {
		$id = $this->stray();

		wp_trash_post( $id );

		$run = Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( 0, (int) Status::get( ProductSync::JOB )['counts']['trashed'] );
	}

	/**
	 * Clearing the setting stops the sweep at the next pass, not the next run.
	 *
	 * The action carries only the run; the answer that matters is the one on the
	 * settings screen when the pass actually runs.
	 *
	 * @return void
	 */
	public function test_clearing_the_setting_stops_a_pass_already_queued() {
		$id  = $this->stray();
		$run = Status::start( ProductSync::JOB );

		$this->ordinary_sync()->trash_unmanaged( $run );

		$this->assertSame( 'publish', $this->status_of( $id ) );
		$this->assertSame( 'success', Status::get( ProductSync::JOB )['state'] );
	}

	/**
	 * A superseded run sweeps nothing.
	 *
	 * @return void
	 */
	public function test_a_superseded_run_sweeps_nothing() {
		$id = $this->stray();

		Status::start( ProductSync::JOB );

		$this->sweeping_sync()->trash_unmanaged( 1 );

		$this->assertSame( 'publish', $this->status_of( $id ) );
	}

	/**
	 * The run summary says how many products were swept up.
	 *
	 * @return void
	 */
	public function test_the_summary_reports_what_was_trashed() {
		$this->stray( 'one' );
		$this->stray( 'two' );

		$run = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertStringContainsString(
			'Moved 2 to the trash that Kontor does not list.',
			Status::get( ProductSync::JOB )['message']
		);
	}

	/**
	 * A run that swept nothing up says nothing about it.
	 *
	 * A shop that leaves the setting alone reads the sentence it always read.
	 *
	 * @return void
	 */
	public function test_the_summary_stays_clean_when_nothing_was_trashed() {
		$run = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$this->sweeping_sync()->trash_unmanaged( $run );

		$this->assertSame( '3 created, 0 updated, 0 unchanged, 0 drafted.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * The sweep is off unless a shop asks for it.
	 *
	 * @return void
	 */
	public function test_the_setting_is_off_by_default() {
		$this->assertFalse( Settings::default_settings()[ Settings::TRASH_UNMANAGED ] );
	}
}
