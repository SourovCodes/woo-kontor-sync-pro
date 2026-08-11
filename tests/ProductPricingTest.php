<?php
/**
 * Tests for the shop type's effect on price and recommended retail price.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers which field becomes the price, and the retail price recorded beside it.
 *
 * A wholesale shop sells at Ek — Kontor's B2B price list and the purchase price are
 * the same number — so it is requested with the retail list, whose UVP is then a
 * genuinely different figure worth keeping.
 */
class ProductPricingTest extends WP_UnitTestCase {

	/**
	 * The request bodies the client sent.
	 *
	 * @var array[]
	 */
	private $requests = array();

	/**
	 * Remove the HTTP short-circuit between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		$this->requests = array();

		parent::tear_down();
	}

	/**
	 * An article row of the shape the API returns.
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
				'Bez1'         => 'Abel blocks 12',
				'Shoptitel'    => 'Abel blocks 12',
				'Ek'           => 40.9500,
				'UVP'          => 77.9000,
				'Lagerbestand' => 24,
				'MainImageURL' => null,
			),
			$overrides
		);
	}

	/**
	 * Settings for one shop type, with images switched off.
	 *
	 * @param string $shoptype Shop type to configure.
	 * @return array Settings array.
	 */
	private function settings( $shoptype ) {
		return array(
			'api_base_url'   => 'https://erp.example.test/api/v1/kontor',
			'api_key'        => 'test-key-123',
			'image_base_url' => '',
			'shoptype'       => $shoptype,
		);
	}

	/**
	 * The product a run imported.
	 *
	 * @return \WC_Product|false Product, or false when nothing was imported.
	 */
	private function imported() {
		return wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );
	}

	/**
	 * A wholesale shop sells at Ek and keeps the retail UVP beside it.
	 *
	 * @return void
	 */
	public function test_wholesale_shop_prices_from_ek_and_records_the_retail_price() {
		$sync = new ProductSync( null, $this->settings( 'B2B' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$product = $this->imported();

		$this->assertSame( '40.95', $product->get_regular_price() );
		$this->assertSame( '77.9', (string) $product->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * A retail shop sells at UVP and records no separate retail price.
	 *
	 * The price already is the recommended one, so a second copy of it would be a
	 * figure with nothing to say.
	 *
	 * @return void
	 */
	public function test_retail_shop_prices_from_uvp_and_records_no_retail_price() {
		$sync = new ProductSync( null, $this->settings( 'B2C' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$product = $this->imported();

		$this->assertSame( '77.9', $product->get_regular_price() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * An education shop behaves like a retail one: UVP is the price.
	 *
	 * @return void
	 */
	public function test_education_shop_prices_from_uvp() {
		$sync = new ProductSync( null, $this->settings( 'EDU' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article( array( 'UVP' => 62.3200 ) ), 1000 ) );

		$product = $this->imported();

		$this->assertSame( '62.32', $product->get_regular_price() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * An article Kontor lists no retail price for gets no retail price.
	 *
	 * Confirmed against the live account: UVP comes back null on some articles that
	 * carry a real Ek, and zero on others. Writing either as 0.00 would put a
	 * recommended retail price of nothing on the product.
	 *
	 * @param mixed $uvp The retail price as the feed gives it.
	 * @return void
	 *
	 * @dataProvider missing_retail_prices
	 */
	public function test_missing_retail_price_is_not_recorded( $uvp ) {
		$sync = new ProductSync( null, $this->settings( 'B2B' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article( array( 'UVP' => $uvp ) ), 1000 ) );

		$product = $this->imported();

		$this->assertSame( '40.95', $product->get_regular_price() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * The shapes a missing retail price arrives in.
	 *
	 * @return array[] Test cases.
	 */
	public function missing_retail_prices() {
		return array(
			'null'  => array( null ),
			'zero'  => array( 0 ),
			'empty' => array( '' ),
		);
	}

	/**
	 * A retail price that disappears is cleared rather than left standing.
	 *
	 * @return void
	 */
	public function test_retail_price_is_cleared_when_it_goes_away() {
		$sync = new ProductSync( null, $this->settings( 'B2B' ) );

		$sync->import_article( $this->article(), 1000 );

		$this->assertSame( '77.9', (string) $this->imported()->get_meta( ProductSync::META_MSRP ) );

		$this->assertSame( 'updated', $sync->import_article( $this->article( array( 'UVP' => null ) ), 1001 ) );
		$this->assertSame( '', (string) $this->imported()->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * On a wholesale shop a change to Ek alone is a change.
	 *
	 * Ek is the price there, so leaving it out of the hash would mean a price rise
	 * that moved nothing else never reached the shop.
	 *
	 * @return void
	 */
	public function test_ek_is_a_change_on_a_wholesale_shop() {
		$sync = new ProductSync( null, $this->settings( 'B2B' ) );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );
		$this->assertSame( 'updated', $sync->import_article( $this->article( array( 'Ek' => 44.9500 ) ), 1001 ) );

		$this->assertSame( '44.95', $this->imported()->get_regular_price() );
	}

	/**
	 * Switching shop type reprices the catalogue rather than skipping it.
	 *
	 * Both shop types are sent the same request, so the row coming back is identical
	 * and nothing in it changes. Only the configured shop type being part of the hash
	 * stops the products staying at the old field's price for good.
	 *
	 * @return void
	 */
	public function test_switching_shop_type_reprices_the_product() {
		$wholesale = new ProductSync( null, $this->settings( 'B2B' ) );

		$this->assertSame( 'created', $wholesale->import_article( $this->article(), 1000 ) );
		$this->assertSame( '40.95', $this->imported()->get_regular_price() );

		$retail = new ProductSync( null, $this->settings( 'B2C' ) );

		$this->assertSame( 'updated', $retail->import_article( $this->article(), 1001 ) );

		$product = $this->imported();

		$this->assertSame( '77.9', $product->get_regular_price() );

		// The retail price belongs to a wholesale shop and goes with it.
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_MSRP ) );
	}

	/**
	 * A wholesale shop asks Kontor for the retail price list.
	 *
	 * That one request carries both numbers it needs. Asking for the wholesale list
	 * would return a UVP that merely repeats Ek, leaving no retail price to record.
	 *
	 * @param string $configured Shop type the shop is set to.
	 * @param string $requested  Shop type the filter should carry.
	 * @return void
	 *
	 * @dataProvider requested_shop_types
	 */
	public function test_catalogue_is_requested_with_the_right_shop_type( $configured, $requested ) {
		$this->fake_catalogue();

		$run  = Status::start( ProductSync::JOB );
		$sync = new ProductSync( null, $this->settings( $configured ) );

		$sync->import_page( 0, $run );

		$this->assertNotEmpty( $this->requests, 'The sync made no request.' );
		$this->assertSame( 'products', $this->requests[0]['entity'] );
		$this->assertSame( $requested, $this->requests[0]['filter']['shoptype'] );
	}

	/**
	 * What each configured shop type asks Kontor for.
	 *
	 * @return array[] Test cases.
	 */
	public function requested_shop_types() {
		return array(
			'wholesale asks for retail' => array( 'B2B', 'B2C' ),
			'retail asks for itself'    => array( 'B2C', 'B2C' ),
			'education asks for itself' => array( 'EDU', 'EDU' ),
		);
	}

	/**
	 * Answer the catalogue request with one article, recording what was asked for.
	 *
	 * @return void
	 */
	private function fake_catalogue() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args ) {
				$this->requests[] = json_decode( $args['body'], true );

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'message' => '',
							'meta'    => array(
								'rowCount'   => 1,
								'totalCount' => 1,
							),
							'data'    => array( $this->article() ),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
				);
			},
			10,
			3
		);
	}
}
