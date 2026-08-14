<?php
/**
 * Tests for Ws_aktiv, the flag Kontor switches an article off for the webshop with.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WooKontorSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers the articles the flag passes over, the products it drafts, and the way
 * back when Kontor switches an article on again.
 */
class ProductActiveFlagTest extends WP_UnitTestCase {

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
				'Shoptitel'    => 'Abel blocks 12',
				'UVP'          => 81.9000,
				'Ek'           => 81.9000,
				'Lagerbestand' => 24,
				'Ws_aktiv'     => true,
				'MainImageURL' => null,
			),
			$overrides
		);
	}

	/**
	 * A product sync with nothing else standing in the way.
	 *
	 * The image requirement is left off throughout, so every outcome here is the
	 * active flag's doing and not another gate's.
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
	 * A product sync with the image requirement turned on as well.
	 *
	 * The image base URL is left blank: whether the shop can fetch a file is a
	 * different question from whether Kontor lists one.
	 *
	 * @return ProductSync Configured sync.
	 */
	private function strict_sync() {
		return new ProductSync(
			null,
			array(
				'image_base_url'     => '',
				'require_main_image' => true,
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
	 * An article Kontor has switched off is imported as a draft.
	 *
	 * The whole article is written, not a placeholder: what makes switching it on in
	 * the ERP put it in the shop on the next run is that there is nothing left to do
	 * but publish it.
	 *
	 * @return void
	 */
	public function test_an_inactive_article_is_imported_as_a_draft() {
		$outcome = $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$this->assertSame( 'inactive', $outcome );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertInstanceOf( 'WC_Product', $product );
		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );

		$this->assertSame( 'Abel blocks 12', $product->get_name() );
		$this->assertSame( '81.9', $product->get_regular_price() );
		$this->assertSame( 24, $product->get_stock_quantity() );
		$this->assertSame( '1000', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A drafted article that has not otherwise moved is not rewritten every run.
	 *
	 * The status and the marker are settled on the run that withholds it; from then
	 * on the change hash answers, exactly as it does for a published product.
	 *
	 * @return void
	 */
	public function test_an_unchanged_inactive_article_is_not_written_again() {
		$sync = $this->sync();
		$row  = $this->article( array( 'Ws_aktiv' => false ) );

		$this->assertSame( 'inactive', $sync->import_article( $row, 1000 ) );

		$id      = wc_get_product_id_by_sku( 'abel-AB12' );
		$written = get_post_field( 'post_modified_gmt', $id );

		$this->assertSame( 'inactive', $sync->import_article( $row, 1001 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( $written, get_post_field( 'post_modified_gmt', $id ) );

		// The stamp still moves, or finalise() would read the article as dropped.
		$this->assertSame( '1001', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A drafted article's data is kept up to date while it is held back.
	 *
	 * @return void
	 */
	public function test_a_drafted_article_still_follows_the_feed() {
		$sync = $this->sync();

		$sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$sync->import_article(
			$this->article(
				array(
					'Ws_aktiv' => false,
					'UVP'      => 90.5,
					'Ek'       => 90.5,
				)
			),
			1001
		);

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '90.5', $product->get_regular_price() );
	}

	/**
	 * An article Kontor has switched on is imported as usual.
	 *
	 * @return void
	 */
	public function test_an_active_article_is_imported() {
		$this->assertSame( 'created', $this->sync()->import_article( $this->article(), 1000 ) );
		$this->assertSame( 'publish', wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) )->get_status() );
	}

	/**
	 * An already published product is left where it is when the article stays active.
	 *
	 * @return void
	 */
	public function test_an_active_article_leaves_a_published_product_published() {
		$id = $this->imported_product( 'abel-AB12' );

		$this->sync()->import_article( $this->article(), 2000 );

		$product = wc_get_product( $id );

		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
	}

	/**
	 * A feed with no Ws_aktiv at all imports everything.
	 *
	 * The two ways of being wrong here are not equal. Treating an absent field as
	 * "switched off" would take the whole catalogue down the day Kontor renamed it,
	 * so absence has to read as active.
	 *
	 * @return void
	 */
	public function test_an_absent_flag_is_treated_as_active() {
		$row = $this->article();
		unset( $row['Ws_aktiv'] );

		$this->assertSame( 'created', $this->sync()->import_article( $row, 1000 ) );
	}

	/**
	 * A null, or a value this does not recognise, is treated as active too.
	 *
	 * @return void
	 */
	public function test_an_unrecognised_flag_is_treated_as_active() {
		$this->assertSame( 'created', $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => null ) ), 1000 ) );
		$this->assertSame(
			'created',
			$this->sync()->import_article(
				$this->article(
					array(
						'Artnr'    => 'A-2',
						'Ws_aktiv' => 'vielleicht',
					)
				),
				1000
			)
		);
		$this->assertSame(
			'created',
			$this->sync()->import_article(
				$this->article(
					array(
						'Artnr'    => 'A-3',
						'Ws_aktiv' => array(),
					)
				),
				1000
			)
		);
	}

	/**
	 * The false a JSON boolean decodes to is not the only way to say no.
	 *
	 * The live API sends a real boolean, but a value that plainly reads as false is
	 * honoured whichever shape it arrives in.
	 *
	 * @return void
	 */
	public function test_the_written_forms_of_false_are_honoured() {
		$this->assertSame( 'inactive', $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => 0 ) ), 1000 ) );
		$this->assertSame(
			'inactive',
			$this->sync()->import_article(
				$this->article(
					array(
						'Artnr'    => 'A-2',
						'Ws_aktiv' => 'false',
					)
				),
				1000
			)
		);
		$this->assertSame(
			'inactive',
			$this->sync()->import_article(
				$this->article(
					array(
						'Artnr'    => 'A-3',
						'Ws_aktiv' => 'Nein',
					)
				),
				1000
			)
		);
	}

	/**
	 * A product already imported is drafted when Kontor switches its article off.
	 *
	 * @return void
	 */
	public function test_a_product_is_drafted_when_its_article_is_switched_off() {
		$sync = $this->sync();

		$sync->import_article( $this->article(), 1000 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'inactive', $sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1001 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );

		// The article is still in the feed, so the run stamp has to keep up or
		// finalise() would later draft it for the wrong reason.
		$this->assertSame( '1001', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A product already drafted for it is left exactly as it is.
	 *
	 * @return void
	 */
	public function test_an_already_drafted_product_is_left_alone() {
		$id = $this->imported_product(
			'abel-AB12',
			'draft',
			array( ProductSync::META_INACTIVE_DRAFTED => 1 )
		);

		$this->assertSame( 'inactive', $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 2000 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
	}

	/**
	 * A product this plugin never imported is left alone.
	 *
	 * The marker meta is the only thing separating our products from a shop manager's
	 * own, and Kontor's flag governs the catalogue rather than the shop.
	 *
	 * @return void
	 */
	public function test_a_shop_managers_own_product_is_not_drafted() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_status( 'publish' );
		$id = $product->save();

		$this->assertSame( 'inactive', $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 ) );

		$untouched = wc_get_product( $id );

		$this->assertSame( 'publish', $untouched->get_status() );
		$this->assertSame( '', (string) $untouched->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
		$this->assertSame( '', (string) $untouched->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * A status this sync does not own is neither drafted nor marked.
	 *
	 * @return void
	 */
	public function test_a_private_product_is_not_marked() {
		$id = $this->imported_product( 'abel-AB12', 'private' );

		$this->assertSame( 'inactive', $this->sync()->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 2000 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'private', $product->get_status() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
		$this->assertSame( '2000', (string) $product->get_meta( ProductSync::META_SYNCED_AT ) );
	}

	/**
	 * The flag is read before the unchanged-article shortcut.
	 *
	 * Ws_aktiv is deliberately not part of the change hash — adding it would rewrite
	 * the whole catalogue once for a decision that is made on the row every run
	 * anyway — so the gate has to sit in front of the shortcut or an article that
	 * moved in no other respect would stay on sale.
	 *
	 * @return void
	 */
	public function test_an_otherwise_unchanged_article_is_still_caught() {
		$sync = $this->sync();

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		// The same row but for the flag, so every hashed field is identical.
		$this->assertSame( 'inactive', $sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1001 ) );
		$this->assertSame( 'draft', wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) )->get_status() );
	}

	/**
	 * The product comes back by itself when the article is switched on again.
	 *
	 * @return void
	 */
	public function test_a_product_is_republished_when_its_article_is_switched_on() {
		$sync = $this->sync();

		$sync->import_article( $this->article(), 1000 );
		$sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1001 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		$sync->import_article( $this->article(), 1002 );

		$restored = wc_get_product( $id );

		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( '', (string) $restored->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );

		// Republishing it takes the full write path, so the product carries the
		// article's current data rather than whatever it was drafted with.
		$this->assertSame( '81.9', $restored->get_regular_price() );
	}

	/**
	 * The flag outranks the image requirement, picture or no picture.
	 *
	 * Kontor's verdict is asked for first and settles the question on its own, so an
	 * article that satisfies the image requirement perfectly well is still held back,
	 * and it is the flag that is recorded as the reason.
	 *
	 * @return void
	 */
	public function test_the_flag_outranks_the_image_requirement() {
		$row = $this->article(
			array(
				'Ws_aktiv'     => false,
				'MainImageURL' => 'abel-AB12_001.jpg',
			)
		);

		$this->assertSame( 'inactive', $this->strict_sync()->import_article( $row, 1000 ) );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
	}

	/**
	 * The image requirement is only ever asked about an article Kontor will sell here.
	 *
	 * @return void
	 */
	public function test_the_image_requirement_answers_for_an_active_article() {
		$sync = $this->strict_sync();

		$this->assertSame( 'no_image', $sync->import_article( $this->article(), 1000 ) );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
	}

	/**
	 * A picture arriving does not put a switched-off article back in the shop.
	 *
	 * @return void
	 */
	public function test_an_image_alone_does_not_republish_an_inactive_article() {
		$sync = $this->strict_sync();

		$id = $this->imported_product( 'abel-AB12' );

		$sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 2000 );

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		$sync->import_article(
			$this->article(
				array(
					'Ws_aktiv'     => false,
					'MainImageURL' => 'abel-AB12_001.jpg',
				)
			),
			2001
		);

		$this->assertSame( 'draft', wc_get_product( $id )->get_status() );

		// Switching the article on is what finally brings it back.
		$sync->import_article( $this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ), 2002 );

		$this->assertSame( 'publish', wc_get_product( $id )->get_status() );
	}

	/**
	 * An article switched on but still imageless stays drafted, under the other marker.
	 *
	 * The reason changes hands rather than clearing: one gate answers per run, and the
	 * product only goes on sale when neither has anything to say.
	 *
	 * @return void
	 */
	public function test_switching_on_an_imageless_article_leaves_it_drafted() {
		$sync = $this->strict_sync();

		$sync->import_article( $this->article( array( 'Ws_aktiv' => false ) ), 1000 );

		$id = wc_get_product_id_by_sku( 'abel-AB12' );

		$this->assertSame( 'no_image', $sync->import_article( $this->article(), 1001 ) );

		$product = wc_get_product( $id );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );

		// The picture is what finally clears both.
		$sync->import_article( $this->article( array( 'MainImageURL' => 'abel-AB12_001.jpg' ) ), 1002 );

		$restored = wc_get_product( $id );

		$this->assertSame( 'publish', $restored->get_status() );
		$this->assertSame( '', (string) $restored->get_meta( ProductSync::META_NO_IMAGE_DRAFTED ) );
		$this->assertSame( '', (string) $restored->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
	}

	/**
	 * The run summary says how many articles Kontor has switched off.
	 *
	 * It is the one number here nobody chose, and without the line a run holding
	 * hundreds of articles back reads like a run that found hundreds fewer.
	 *
	 * @return void
	 */
	public function test_the_summary_reports_switched_off_articles() {
		$run = Status::start( ProductSync::JOB );

		Status::progress(
			ProductSync::JOB,
			array(
				'created'  => 3,
				'inactive' => 827,
			)
		);

		$this->sync()->finalise( $run );

		$this->assertStringContainsString(
			'Held 827 back as drafts, switched off for the webshop in Kontor.',
			Status::get( ProductSync::JOB )['message']
		);
	}

	/**
	 * A run with nothing switched off says nothing about it.
	 *
	 * @return void
	 */
	public function test_the_summary_stays_clean_without_them() {
		$run = Status::start( ProductSync::JOB );

		Status::progress( ProductSync::JOB, array( 'created' => 3 ) );

		$this->sync()->finalise( $run );

		$this->assertSame( '3 created, 0 updated, 0 unchanged, 0 drafted.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * The stock sync never republishes a product held back for the flag.
	 *
	 * A level arriving for an article Kontor has switched off says nothing about
	 * whether the shop may sell it, and this sync's marker is not the stock sync's
	 * to clear.
	 *
	 * @return void
	 */
	public function test_the_stock_sync_leaves_an_inactive_product_drafted() {
		$id = $this->imported_product(
			'abel-AB12',
			'draft',
			array(
				ProductSync::META_INACTIVE_DRAFTED => 1,
				StockSync::META_STOCK_DRAFTED      => 1,
			)
		);

		$run = Status::start( StockSync::JOB );

		// The pass that gives back what the stock sync drafted, with the drafting off.
		( new StockSync( null, array() ) )->finalise( $run );

		$held = wc_get_product( $id );

		$this->assertSame( 'draft', $held->get_status() );
		$this->assertSame( '', (string) $held->get_meta( StockSync::META_STOCK_DRAFTED ) );
		$this->assertSame( '1', (string) $held->get_meta( ProductSync::META_INACTIVE_DRAFTED ) );
	}
}
