<?php
/**
 * Tests for the tax rate reported on an uploaded order's lines.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WC_Product_Simple;
use WC_Tax;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\OrderSync;
use WP_UnitTestCase;

/**
 * Covers the taxRate field of the order payload.
 *
 * Kontor holds one rate per article, so every line taxed at 8.1% has to say 8.1.
 * Working the figure out from the line's own amounts cannot do that: a tax amount
 * is money and is stored rounded to two decimals, and multiplying that rounding by
 * a hundred moves the percentage.
 */
class OrderTaxRateTest extends WP_UnitTestCase {

	/**
	 * ID of the tax rate inserted for the test.
	 *
	 * @var int
	 */
	private $rate_id = 0;

	/**
	 * Turn tax on and register a Swiss rate.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_based_on', 'billing' );

		$this->rate_id = WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'CH',
				'tax_rate'          => '8.1000',
				'tax_rate_name'     => 'MWST',
				'tax_rate_priority' => 1,
				'tax_rate_order'    => 1,
			)
		);
	}

	/**
	 * Remove the rate and the tax settings again.
	 *
	 * @return void
	 */
	public function tear_down() {
		WC_Tax::_delete_tax_rate( $this->rate_id );
		delete_option( 'woocommerce_calc_taxes' );
		delete_option( 'woocommerce_prices_include_tax' );
		delete_option( 'woocommerce_tax_based_on' );
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Settings good enough to build a payload.
	 *
	 * @return array Settings array.
	 */
	private function settings() {
		return array(
			'api_base_url' => 'https://erp.example.test/api/v1/kontor',
			'api_key'      => 'test-key-123',
			'shoptype'     => 'B2C',
			'shop_id'      => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
		);
	}

	/**
	 * Build a Swiss order carrying one line per price given.
	 *
	 * @param array $prices Net unit prices, keyed by SKU.
	 * @return WC_Order The saved order.
	 */
	private function make_order( array $prices ) {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_currency( 'CHF' );
		$order->set_billing_first_name( 'Max' );
		$order->set_billing_last_name( 'Muster' );
		$order->set_billing_address_1( 'Musterweg 1' );
		$order->set_billing_postcode( '8001' );
		$order->set_billing_city( 'Zürich' );
		$order->set_billing_country( 'CH' );

		foreach ( $prices as $sku => $price ) {
			$product = new WC_Product_Simple();
			$product->set_sku( $sku );
			$product->set_regular_price( (string) $price );
			$product->save();

			$order->add_product( $product, 1 );
		}

		$order->calculate_totals();
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * Read the taxRate of each line, keyed by SKU.
	 *
	 * @param WC_Order $order Order to map.
	 * @return array Tax rates keyed by SKU.
	 */
	private function rates( $order ) {
		$payload = ( new OrderSync( null, $this->settings() ) )->build_payload( $order );
		$rates   = array();

		foreach ( $payload['items'] as $item ) {
			$rates[ $item['sku'] ] = $item['taxRate'];
		}

		return $rates;
	}

	/**
	 * Every line taxed at the same rate reports that rate.
	 *
	 * The three prices are chosen because the old derivation disagreed on each of
	 * them: 4.15 came back as 8.19, 9.90 as 8.08 and 0.95 as 8.42.
	 *
	 * @return void
	 */
	public function test_lines_under_one_rate_all_report_the_same_rate() {
		$order = $this->make_order(
			array(
				'abel-AB01' => '4.15',
				'abel-AB02' => '9.90',
				'abel-AB03' => '0.95',
			)
		);

		$this->assertSame(
			array(
				'abel-AB01' => 8.1,
				'abel-AB02' => 8.1,
				'abel-AB03' => 8.1,
			),
			$this->rates( $order )
		);
	}

	/**
	 * The rate does not come from dividing the line's own amounts.
	 *
	 * The guard against the derivation creeping back: on this line the two
	 * approaches genuinely disagree, and the tax amount really is the rounded one.
	 *
	 * @return void
	 */
	public function test_the_rate_is_not_derived_from_the_lines_amounts() {
		$order = $this->make_order( array( 'abel-AB01' => '4.15' ) );
		$item  = current( $order->get_items() );

		$this->assertSame( 0.34, (float) $item->get_total_tax(), 'The stored tax is the rounded money amount.' );
		$this->assertSame( 8.19, round( (float) $item->get_total_tax() / (float) $item->get_total() * 100, 2 ), 'Deriving the rate from it gives the wrong answer.' );
		$this->assertSame( 8.1, $this->rates( $order )['abel-AB01'] );
	}

	/**
	 * A line discounted to nothing still reports the rate it was sold under.
	 *
	 * @return void
	 */
	public function test_a_line_discounted_to_nothing_keeps_its_rate() {
		$order = $this->make_order( array( 'abel-AB01' => '4.15' ) );
		$item  = current( $order->get_items() );

		$item->set_total( 0 );
		$item->set_taxes(
			array(
				'total'    => array( $this->rate_id => 0 ),
				'subtotal' => array( $this->rate_id => 0.34 ),
			)
		);
		$item->save();
		$order->calculate_totals( false );
		$order->save();

		$this->assertSame( 8.1, $this->rates( wc_get_order( $order->get_id() ) )['abel-AB01'] );
	}

	/**
	 * A line no rate applies to reports nothing rather than a made-up figure.
	 *
	 * @return void
	 */
	public function test_an_untaxed_line_reports_zero() {
		update_option( 'woocommerce_calc_taxes', 'no' );

		$order = $this->make_order( array( 'abel-AB01' => '4.15' ) );

		$this->assertSame( 0.0, $this->rates( $order )['abel-AB01'] );
	}

	/**
	 * The rate survives the tax tables being edited after the order was placed.
	 *
	 * It is recorded on the order, so a rate changed or deleted since cannot move
	 * what an old order reports.
	 *
	 * @return void
	 */
	public function test_the_rate_is_the_one_the_order_was_placed_under() {
		$order = $this->make_order( array( 'abel-AB01' => '4.15' ) );

		WC_Tax::_update_tax_rate( $this->rate_id, array( 'tax_rate' => '7.7000' ) );

		$this->assertSame( 8.1, $this->rates( wc_get_order( $order->get_id() ) )['abel-AB01'] );
	}
}
