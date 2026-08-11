<?php
/**
 * Tests for the recommended retail price on the WooCommerce REST API.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Sync\ProductSync;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the msrp field on /wc/v3/products.
 *
 * The requests go through the real endpoint rather than calling the callback,
 * because the thing worth proving is that the field survives WooCommerce's own
 * response preparation and context filtering.
 */
class RestProductsTest extends WP_UnitTestCase {

	/**
	 * Act as someone allowed to read products.
	 *
	 * The REST server is left to build itself on the first request, so the field
	 * arrives the way it does on a real site — through the hook Plugin::init()
	 * registered at load time — rather than through one this test wired up.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * A product, optionally carrying a recommended retail price.
	 *
	 * @param string $msrp Recommended retail price, or an empty string for none.
	 * @return int Product ID.
	 */
	private function product( $msrp = '' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Abel blocks 12' );
		$product->set_sku( 'abel-AB12-' . wp_rand( 1000, 9999 ) );
		$product->set_regular_price( '40.95' );

		if ( '' !== $msrp ) {
			$product->update_meta_data( ProductSync::META_MSRP, $msrp );
		}

		return $product->save();
	}

	/**
	 * Fetch one product through the REST API.
	 *
	 * @param int $product_id Product to fetch.
	 * @return array Response data.
	 */
	private function fetch( $product_id ) {
		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id ) );

		$this->assertSame( 200, $response->get_status(), 'The products endpoint did not answer.' );

		return $response->get_data();
	}

	/**
	 * The recommended retail price is returned beside the price.
	 *
	 * @return void
	 */
	public function test_msrp_is_returned_with_the_prices() {
		$data = $this->fetch( $this->product( '77.9' ) );

		$this->assertArrayHasKey( 'msrp', $data );
		$this->assertSame( '77.9', $data['msrp'] );

		// It sits alongside the fields it belongs with, in the same raw shape.
		$this->assertSame( '40.95', $data['regular_price'] );
		$this->assertSame( '40.95', $data['price'] );
	}

	/**
	 * A product with no recommended retail price reports null, not an empty string.
	 *
	 * The field is always present, so a consumer can tell "this shop does not supply
	 * one" from "this response is from a version that did not have the field".
	 *
	 * @return void
	 */
	public function test_missing_msrp_is_null() {
		$data = $this->fetch( $this->product() );

		$this->assertArrayHasKey( 'msrp', $data );
		$this->assertNull( $data['msrp'] );
	}

	/**
	 * Null survives being encoded, rather than becoming an empty string.
	 *
	 * @return void
	 */
	public function test_null_msrp_encodes_as_json_null() {
		$data = $this->fetch( $this->product() );

		$this->assertStringContainsString( '"msrp":null', wp_json_encode( array( 'msrp' => $data['msrp'] ) ) );
	}

	/**
	 * The field appears in the collection response too, not only on one product.
	 *
	 * @return void
	 */
	public function test_msrp_is_returned_in_the_collection() {
		$this->product( '77.9' );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/products' ) );

		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();

		$this->assertNotEmpty( $data );
		$this->assertArrayHasKey( 'msrp', $data[0] );
		$this->assertSame( '77.9', $data[0]['msrp'] );
	}

	/**
	 * The field is advertised in the endpoint's schema.
	 *
	 * Without it the value is dropped by _fields= requests and invisible to any
	 * client that discovers the resource rather than assuming it.
	 *
	 * @return void
	 */
	public function test_msrp_is_in_the_schema() {
		$schema = ( new \WC_REST_Products_Controller() )->get_item_schema()['properties'];

		$this->assertArrayHasKey( 'msrp', $schema );
		$this->assertSame( array( 'string', 'null' ), $schema['msrp']['type'] );
		$this->assertTrue( $schema['msrp']['readonly'] );
	}

	/**
	 * Asking for a narrow set of fields still returns the price.
	 *
	 * @return void
	 */
	public function test_msrp_can_be_requested_on_its_own() {
		$product_id = $this->product( '77.9' );

		$request = new WP_REST_Request( 'GET', '/wc/v3/products/' . $product_id );
		$request->set_param( '_fields', 'id,price,msrp' );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( '77.9', $data['msrp'] );
		$this->assertArrayNotHasKey( 'sku', $data );
	}

	/**
	 * The field is read-only: writing to it does not change the stored value.
	 *
	 * The sync owns the meta and rewrites it on every run, so a write accepted here
	 * would vanish at the next sync and look like data loss.
	 *
	 * @return void
	 */
	public function test_msrp_cannot_be_written() {
		$product_id = $this->product( '77.9' );

		$request = new WP_REST_Request( 'PUT', '/wc/v3/products/' . $product_id );
		$request->set_body_params( array( 'msrp' => '999.99' ) );

		rest_do_request( $request );

		$this->assertSame( '77.9', (string) wc_get_product( $product_id )->get_meta( ProductSync::META_MSRP ) );
		$this->assertSame( '77.9', $this->fetch( $product_id )['msrp'] );
	}
}
