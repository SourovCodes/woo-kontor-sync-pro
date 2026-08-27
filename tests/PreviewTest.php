<?php
/**
 * Tests for the catalogue preview and the setup checklist.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Categories;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers what a run would do, said without doing any of it.
 *
 * The settings that decide what a product sync writes are the ones hardest to check by
 * reading them — the shop type picks the price field, the manufacturer filter decides
 * which articles arrive — and getting one wrong is a successful run that prices the
 * catalogue wrong or drafts a fifth of it.
 */
class PreviewTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Clear state that outlives a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

		parent::tear_down();
	}

	/**
	 * The preview writes nothing at all.
	 *
	 * **The promise the whole feature rests on.** Somebody presses this to find out what
	 * a run would do precisely because they are not sure they want it to happen.
	 *
	 * @return void
	 */
	public function test_a_preview_creates_no_products_terms_or_actions() {
		$this->configure( array( Settings::SYNC_CATEGORIES => true ) );
		$this->serve( array( $this->article( 'abel-AB12' ), $this->article( 'abel-AB13' ) ) );

		$products = count(
			wc_get_products(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			)
		);
		$terms    = count(
			(array) get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);

		$result = $this->sync()->preview();

		$this->assertIsArray( $result );
		$this->assertCount( 2, $result['rows'] );

		$this->assertSame(
			$products,
			count(
				wc_get_products(
					array(
						'limit'  => -1,
						'return' => 'ids',
					)
				)
			),
			'the preview created a product'
		);
		$this->assertSame(
			$terms,
			count(
				(array) get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				)
			),
			'the preview created a category term'
		);

		// And no run was marked, so nothing on the settings screen moved either.
		$this->assertSame( 'never', Status::get( ProductSync::JOB )['state'] );
	}

	/**
	 * A category tree is read rather than reconciled.
	 *
	 * Building it is what creates the shop's categories, which is exactly the write a
	 * preview must not make — and the map it returns still answers the one question the
	 * withheld decision asks of it.
	 *
	 * @return void
	 */
	public function test_a_read_only_tree_creates_no_terms() {
		$this->configure( array( Settings::SYNC_CATEGORIES => true ) );
		$this->serve_categories();

		$before = count(
			(array) get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);

		$read_only = new Categories( null, $this->settings( array( Settings::SYNC_CATEGORIES => true ) ), true );
		$map       = $read_only->map();

		$this->assertIsArray( $map );
		$this->assertArrayHasKey( '15', $map );
		$this->assertTrue( $read_only->has_category( '15' ) );
		$this->assertFalse( $read_only->has_category( '999' ) );

		$this->assertSame(
			$before,
			count(
				(array) get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				)
			),
			'reading the tree created terms'
		);
	}

	/**
	 * The read-only promise does not depend on the instance being fresh.
	 *
	 * The tree is memoised on first call and takes the flag at that moment, so an
	 * instance that had already built a writing one would go on using it — and the
	 * preview would reconcile the shop's categories. The inverse is worse: an instance
	 * left read-only after a preview would stop reconciling on a real run, and with the
	 * category requirement on that drafts the shop.
	 *
	 * @return void
	 */
	public function test_a_preview_neither_inherits_nor_leaves_behind_a_tree() {
		$this->configure( array( Settings::SYNC_CATEGORIES => true ) );
		$this->serve_categories();

		$sync = $this->sync( array( Settings::SYNC_CATEGORIES => true ) );

		// Build a writing tree first, exactly as a run would.
		$this->assertIsArray( $sync->categories_for_test()->map() );

		$terms = count(
			(array) get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			)
		);

		$sync->preview();

		$this->assertSame(
			$terms,
			count(
				(array) get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'hide_empty' => false,
						'fields'     => 'ids',
					)
				)
			),
			'the preview reused a writing tree and created terms'
		);

		// And the instance is handed back able to reconcile again.
		$again = $sync->categories_for_test();

		$this->assertIsArray( $again->map() );
		$this->assertFalse( $again->is_read_only(), 'the instance was left read-only after the preview' );
	}

	/**
	 * An article nothing here has yet reads as one that would be created.
	 *
	 * @return void
	 */
	public function test_an_unknown_article_would_be_created() {
		$this->configure();
		$this->serve( array( $this->article( 'abel-NEW1' ) ) );

		$result = $this->sync()->preview();

		$this->assertSame( 1, $result['counts']['create'] );
		$this->assertSame( 'create', $result['rows'][0]['outcome'] );
		$this->assertSame( 'abel-NEW1', $result['rows'][0]['sku'] );
	}

	/**
	 * An article whose product this plugin imported reads as an update.
	 *
	 * @return void
	 */
	public function test_a_known_article_would_be_updated() {
		$this->configure();

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_regular_price( '5.00' );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, 1000 );
		$product->update_meta_data( ProductSync::META_HASH, 'something-else' );
		$product->save();

		$this->serve( array( $this->article( 'abel-AB12' ) ) );

		$result = $this->sync()->preview();

		$this->assertSame( 1, $result['counts']['update'] );
		$this->assertSame( 'update', $result['rows'][0]['outcome'] );
	}

	/**
	 * A product this plugin never imported is reported as left alone.
	 *
	 * It is the case import_article() goes out of its way to protect, so the preview
	 * has to say so rather than promising an update that will not happen.
	 *
	 * @return void
	 */
	public function test_somebody_elses_product_is_reported_as_passed_over() {
		$this->configure();

		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_regular_price( '5.00' );
		$product->save();

		$this->serve( array( $this->article( 'abel-AB12' ) ) );

		$result = $this->sync()->preview();

		$this->assertSame( 1, $result['counts']['skip'] );
		$this->assertSame( 'skip', $result['rows'][0]['outcome'] );
	}

	/**
	 * An article Kontor has switched off is reported as held back, with the reason.
	 *
	 * @return void
	 */
	public function test_an_inactive_article_is_reported_as_held_back() {
		$this->configure();
		$this->serve( array( $this->article( 'abel-AB12', array( 'Ws_aktiv' => false ) ) ) );

		$result = $this->sync()->preview();

		$this->assertSame( 1, $result['counts']['withheld'] );
		$this->assertSame( 'withheld', $result['rows'][0]['outcome'] );
		$this->assertStringContainsString( 'switched it off', $result['rows'][0]['detail'] );
	}

	/**
	 * An article with no number is reported as passed over.
	 *
	 * @return void
	 */
	public function test_an_article_with_no_number_is_passed_over() {
		$this->configure();
		$this->serve( array( $this->article( '' ) ) );

		$result = $this->sync()->preview();

		$this->assertSame( 1, $result['counts']['skip'] );
		$this->assertStringContainsString( 'no article number', $result['rows'][0]['detail'] );
	}

	/**
	 * The preview reports which price it read, and from where.
	 *
	 * The shop type is the setting this exists to check: a wholesale shop is requested
	 * with the retail list and priced from Ek, which is not something reading the
	 * dropdown tells anybody.
	 *
	 * @return void
	 */
	public function test_the_preview_names_the_shop_type_and_the_price_field() {
		$this->configure( array( 'shoptype' => ProductSync::SHOPTYPE_WHOLESALE ) );
		$this->serve( array( $this->article( 'abel-AB12' ) ) );

		$result = $this->sync( array( 'shoptype' => ProductSync::SHOPTYPE_WHOLESALE ) )->preview();

		$this->assertSame( ProductSync::SHOPTYPE_WHOLESALE, $result['shoptype'] );
		$this->assertSame( ProductSync::SHOPTYPE_RETAIL, $result['requested'] );
		$this->assertSame( 'Ek', $result['price'] );
	}

	/**
	 * An unconfigured shop is refused rather than asked to guess.
	 *
	 * @return void
	 */
	public function test_a_preview_refuses_without_credentials() {
		update_option( Settings::OPTION_KEY, Settings::default_settings() );

		$result = ( new ProductSync( null, Settings::default_settings() ) )->preview();

		$this->assertWPError( $result );
	}

	/**
	 * The preview never reads more than its own limit.
	 *
	 * It is rendered as a table somebody reads, and it is the whole cost of the
	 * feature.
	 *
	 * @return void
	 */
	public function test_the_preview_is_bounded() {
		$this->configure();

		$asked = 0;

		add_filter(
			'pre_http_request',
			function ( $pre, $args ) use ( &$asked ) {
				$body  = json_decode( $args['body'], true );
				$asked = (int) $body['paging']['take'];

				return $this->envelope( array() );
			},
			10,
			2
		);

		$this->sync()->preview( 5000 );

		$this->assertSame( ProductSync::PREVIEW_LIMIT, $asked );
	}

	/**
	 * A product sync wired to the test settings.
	 *
	 * @param array $overrides Settings to replace.
	 * @return ProductSync
	 */
	private function sync( array $overrides = array() ) {
		return new ProductSync( null, $this->settings( $overrides ) );
	}

	/**
	 * Fully configured settings.
	 *
	 * @param array $overrides Settings to replace.
	 * @return array Settings array.
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			Settings::default_settings(),
			array(
				'api_base_url'   => 'https://erp.example.test/api/v1/kontor',
				'api_key'        => 'test-key-123',
				'shoptype'       => 'B2C',
				'shop_id'        => self::SHOP_ID,
				'image_base_url' => '',
			),
			$overrides
		);
	}

	/**
	 * Store those settings, and stand in for a connection test that already passed.
	 *
	 * @param array $overrides Settings to replace.
	 * @return void
	 */
	private function configure( array $overrides = array() ) {
		update_option( Settings::OPTION_KEY, $this->settings( $overrides ) );
		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );
	}

	/**
	 * One article row, in the shape the API returns.
	 *
	 * @param string $sku       Article number.
	 * @param array  $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( $sku, array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'        => $sku,
				'Bez1'         => 'Abel blocks',
				'Shoptitel'    => 'Abel blocks',
				'Ek'           => 40.95,
				'UVP'          => 81.90,
				'Lagerbestand' => 5,
				'Ws_aktiv'     => true,
				'Categories'   => '15',
				'MainImageURL' => 'abel-AB12_001.jpg',
			),
			$overrides
		);
	}

	/**
	 * Answer the product request with these rows.
	 *
	 * @param array $rows Article rows.
	 * @return void
	 */
	private function serve( array $rows ) {
		add_filter(
			'pre_http_request',
			function () use ( $rows ) {
				return $this->envelope( $rows );
			}
		);
	}

	/**
	 * Answer every request with a small category tree.
	 *
	 * @return void
	 */
	private function serve_categories() {
		add_filter(
			'pre_http_request',
			function () {
				return $this->envelope(
					array(
						array(
							'Katid'       => '15',
							'Katidparent' => '',
							'Katname'     => 'Kuscheltiere',
						),
					)
				);
			}
		);
	}

	/**
	 * A well-formed envelope carrying these rows.
	 *
	 * @param array $rows Rows for the data member.
	 * @return array Response array.
	 */
	private function envelope( array $rows ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'success' => true,
					'message' => '',
					'meta'    => array(
						'rowCount'   => count( $rows ),
						'totalCount' => count( $rows ),
					),
					'data'    => $rows,
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
