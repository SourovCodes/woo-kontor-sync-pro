<?php
/**
 * Tests for the retail price and EAN rows on the product page.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Frontend\ProductMeta;
use WooKontorSync\Sync\ProductSync;
use WP_UnitTestCase;

/**
 * Covers what the product meta block shows, and the settings behind it.
 *
 * Both rows are off by default, both labels are the shop's to choose, and each row
 * appears only on a product that actually carries the figure.
 */
class ProductMetaTest extends WP_UnitTestCase {

	/**
	 * Start every test with a shop that has chosen nothing.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Settings::OPTION_KEY );
	}

	/**
	 * Leave no product behind in the global the meta block reads.
	 *
	 * @return void
	 */
	public function tear_down() {
		unset( $GLOBALS['product'] );

		parent::tear_down();
	}

	/**
	 * Store settings over the defaults.
	 *
	 * @param array $settings Settings to store.
	 * @return void
	 */
	private function configure( array $settings ) {
		update_option( Settings::OPTION_KEY, $settings );
	}

	/**
	 * A product carrying both figures.
	 *
	 * @param string $msrp Retail price to record, or an empty string for none.
	 * @param string $ean  EAN to record, or an empty string for none.
	 * @return WC_Product_Simple The saved product.
	 */
	private function product( $msrp = '45.00', $ean = '4006381333931' ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Abel blocks 12' );
		$product->set_regular_price( '22.50' );

		if ( '' !== $msrp ) {
			$product->update_meta_data( ProductSync::META_MSRP, $msrp );
		}

		if ( '' !== $ean ) {
			$product->set_global_unique_id( $ean );
		}

		$product->save();

		return $product;
	}

	/**
	 * Render the meta block for a product.
	 *
	 * The global is WooCommerce's own, which is what the meta template and therefore
	 * the class under test reads, so the prefix sniff has nothing to say about it here.
	 *
	 * @param mixed $product Product to render, which is not always a product.
	 * @return string The markup produced.
	 */
	private function render( $product ) {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's global, not this plugin's.
		$GLOBALS['product'] = $product;

		ob_start();
		( new ProductMeta() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * A shop that has chosen nothing shows nothing, however complete the product is.
	 *
	 * @return void
	 */
	public function test_both_rows_are_off_by_default() {
		$this->assertSame( '', $this->render( $this->product() ) );
	}

	/**
	 * The retail price is shown with the default label and the shop's currency.
	 *
	 * @return void
	 */
	public function test_the_retail_price_is_shown_when_switched_on() {
		$this->configure( array( Settings::SHOW_MSRP => true ) );

		$markup = $this->render( $this->product() );

		$this->assertStringContainsString( 'wksync_msrp_wrapper', $markup );
		$this->assertStringContainsString( 'RRP:', $markup );
		$this->assertStringContainsString( '45.00', $markup );
		$this->assertStringNotContainsString( 'wksync_ean_wrapper', $markup );
	}

	/**
	 * The shop's own wording replaces the default label.
	 *
	 * @return void
	 */
	public function test_the_label_is_the_shops_to_choose() {
		$this->configure(
			array(
				Settings::SHOW_MSRP  => true,
				Settings::MSRP_LABEL => 'UVP inkl. 20% MwSt.',
			)
		);

		$markup = $this->render( $this->product() );

		$this->assertStringContainsString( 'UVP inkl. 20% MwSt.', $markup );
		$this->assertStringNotContainsString( 'RRP:', $markup );
	}

	/**
	 * An emptied label is a way back to the default, not a row with nothing in front.
	 *
	 * @return void
	 */
	public function test_an_empty_label_falls_back_to_the_default() {
		$this->configure(
			array(
				Settings::SHOW_MSRP  => true,
				Settings::MSRP_LABEL => '',
			)
		);

		$this->assertStringContainsString( 'RRP:', $this->render( $this->product() ) );
	}

	/**
	 * A product with no retail price shows no row rather than an empty one.
	 *
	 * @return void
	 */
	public function test_a_product_without_a_retail_price_shows_no_row() {
		$this->configure( array( Settings::SHOW_MSRP => true ) );

		$this->assertStringNotContainsString(
			'wksync_msrp_wrapper',
			$this->render( $this->product( '', '' ) )
		);
	}

	/**
	 * A retail price of nothing is not a retail price.
	 *
	 * The import deletes the meta rather than storing a zero, so this can only arise
	 * from something else writing the key — which is exactly when a customer must not
	 * be shown a recommended price of 0.00.
	 *
	 * @return void
	 */
	public function test_a_zero_retail_price_shows_no_row() {
		$this->configure( array( Settings::SHOW_MSRP => true ) );

		$this->assertStringNotContainsString(
			'wksync_msrp_wrapper',
			$this->render( $this->product( '0.00', '' ) )
		);
	}

	/**
	 * The EAN comes from WooCommerce's own field.
	 *
	 * @return void
	 */
	public function test_the_ean_is_shown_when_switched_on() {
		$this->configure(
			array(
				Settings::SHOW_EAN  => true,
				Settings::EAN_LABEL => 'GTIN:',
			)
		);

		$markup = $this->render( $this->product() );

		$this->assertStringContainsString( 'wksync_ean_wrapper', $markup );
		$this->assertStringContainsString( 'GTIN:', $markup );
		$this->assertStringContainsString( '4006381333931', $markup );
		$this->assertStringNotContainsString( 'wksync_msrp_wrapper', $markup );
	}

	/**
	 * A product with no EAN shows no row.
	 *
	 * @return void
	 */
	public function test_a_product_without_an_ean_shows_no_row() {
		$this->configure( array( Settings::SHOW_EAN => true ) );

		$this->assertStringNotContainsString(
			'wksync_ean_wrapper',
			$this->render( $this->product( '', '' ) )
		);
	}

	/**
	 * Both rows appear together, in the order the product page reads them.
	 *
	 * @return void
	 */
	public function test_both_rows_appear_together() {
		$this->configure(
			array(
				Settings::SHOW_MSRP => true,
				Settings::SHOW_EAN  => true,
			)
		);

		$markup = $this->render( $this->product() );

		$this->assertLessThan(
			strpos( $markup, 'wksync_ean_wrapper' ),
			strpos( $markup, 'wksync_msrp_wrapper' )
		);
	}

	/**
	 * Nothing is rendered without a product, which is what a broken theme leaves.
	 *
	 * @return void
	 */
	public function test_nothing_is_rendered_without_a_product() {
		$this->configure(
			array(
				Settings::SHOW_MSRP => true,
				Settings::SHOW_EAN  => true,
			)
		);

		$this->assertSame( '', $this->render( null ) );
	}

	/**
	 * A label carrying markup is stripped rather than stored as HTML.
	 *
	 * @return void
	 */
	public function test_a_submitted_label_is_stripped() {
		$saved = ( new Settings() )->sanitize(
			array( Settings::MSRP_LABEL => ' <strong>UVP</strong>: ' )
		);

		$this->assertSame( 'UVP:', $saved[ Settings::MSRP_LABEL ] );
	}

	/**
	 * A percent sign survives, which sanitize_text_field() would have eaten.
	 *
	 * @return void
	 */
	public function test_a_submitted_label_keeps_percent_octets() {
		$saved = ( new Settings() )->sanitize(
			array( Settings::EAN_LABEL => 'EAN %2f GTIN:' )
		);

		$this->assertSame( 'EAN %2f GTIN:', $saved[ Settings::EAN_LABEL ] );
	}

	/**
	 * An absent field keeps the stored label, so a partial save cannot reset it.
	 *
	 * @return void
	 */
	public function test_an_absent_label_keeps_the_stored_one() {
		$this->configure( array( Settings::MSRP_LABEL => 'UVP:' ) );

		$saved = ( new Settings() )->sanitize( array() );

		$this->assertSame( 'UVP:', $saved[ Settings::MSRP_LABEL ] );
	}

	/**
	 * An explicitly empty field clears it, which is how the default is asked for back.
	 *
	 * @return void
	 */
	public function test_an_empty_submission_clears_the_label() {
		$this->configure( array( Settings::MSRP_LABEL => 'UVP:' ) );

		$saved = ( new Settings() )->sanitize( array( Settings::MSRP_LABEL => '' ) );

		$this->assertSame( '', $saved[ Settings::MSRP_LABEL ] );
	}

	/**
	 * An absent checkbox keeps the stored value, like every other toggle here.
	 *
	 * @return void
	 */
	public function test_an_absent_toggle_keeps_the_stored_value() {
		$this->configure( array( Settings::SHOW_EAN => true ) );

		$saved = ( new Settings() )->sanitize( array() );

		$this->assertTrue( $saved[ Settings::SHOW_EAN ] );
	}

	/**
	 * The hidden zero the form pairs each checkbox with is what turns one off.
	 *
	 * @return void
	 */
	public function test_a_submitted_zero_turns_a_row_off() {
		$this->configure( array( Settings::SHOW_EAN => true ) );

		$saved = ( new Settings() )->sanitize( array( Settings::SHOW_EAN => '0' ) );

		$this->assertFalse( $saved[ Settings::SHOW_EAN ] );
	}
}
