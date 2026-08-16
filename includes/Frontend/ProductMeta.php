<?php
/**
 * Kontor's figures in the product page's meta block.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Frontend;

use WC_Product;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the recommended retail price and the EAN to the single product's meta block.
 *
 * Both figures are already on the product and neither is visible anywhere a customer
 * looks. The retail price is stored under a protected meta key, so nothing renders it
 * at all; the EAN is a first-class WooCommerce field and core still prints only the
 * SKU, the categories and the tags. This is the place both are shown.
 *
 * The block is the one WooCommerce's `single-product/meta.php` draws, entered through
 * `woocommerce_product_meta_end` — the extension point themes keep when they override
 * that template, and the only one that puts these lines where a customer already looks
 * for the article number.
 *
 * Each figure is shown only where there is one: the retail price exists on a wholesale
 * shop alone, because that is the only shop type where Kontor's UVP is a different
 * number from the price, and the EAN only where Kontor supplied one and WooCommerce
 * accepted it as unique.
 *
 * Both labels are settings rather than fixed strings. What this figure is called is a
 * shop's own decision — RRP, UVP, list price, GTIN, EAN — and it is the one part of
 * this that a translation cannot answer, because the wording differs between shops in
 * the same language.
 */
class ProductMeta {

	/**
	 * Register the hooks.
	 *
	 * Not gated on the settings: every callback asks as it runs, so turning either row
	 * on or off takes effect on the next page load rather than on the next sync.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_product_meta_end', array( $this, 'render' ) );
	}

	/**
	 * Draw the rows the shop has asked for.
	 *
	 * The product comes from `$product`, the global WooCommerce's own meta template
	 * reads, so a theme rendering a product other than the queried one still gets the
	 * figures belonging to it.
	 *
	 * @return void
	 */
	public function render() {
		global $product;

		if ( ! $product instanceof WC_Product ) {
			return;
		}

		$settings = Settings::get_settings();

		if ( ! empty( $settings[ Settings::SHOW_MSRP ] ) ) {
			$this->render_msrp( $product );
		}

		if ( ! empty( $settings[ Settings::SHOW_EAN ] ) ) {
			$this->render_ean( $product );
		}
	}

	/**
	 * Draw the recommended retail price.
	 *
	 * Read through `wc_format_decimal()` and checked for a positive amount, which is the
	 * same test the import applies before storing it. The import deletes the meta rather
	 * than writing a zero, so this should never fire — but a figure of nothing presented
	 * to a customer as a retail price is the one failure worth being sure of.
	 *
	 * Formatted with `wc_price()` so it carries the shop's currency and decimal
	 * separator, and reads as the price beside it rather than as a bare number.
	 *
	 * @param WC_Product $product Product being displayed.
	 * @return void
	 */
	protected function render_msrp( WC_Product $product ) {
		$msrp = wc_format_decimal( $product->get_meta( ProductSync::META_MSRP ) );

		if ( '' === $msrp || 0 >= (float) $msrp ) {
			return;
		}

		$this->render_row( 'msrp', self::msrp_label(), wc_price( $msrp ) );
	}

	/**
	 * Draw the EAN.
	 *
	 * The value is WooCommerce's own `global_unique_id`, which is where the sync puts
	 * Kontor's `Artean`. Read from the product rather than from a meta key of this
	 * plugin's own: there is only ever one EAN on a product, and it is WooCommerce's
	 * field.
	 *
	 * @param WC_Product $product Product being displayed.
	 * @return void
	 */
	protected function render_ean( WC_Product $product ) {
		$ean = trim( (string) $product->get_global_unique_id() );

		if ( '' === $ean ) {
			return;
		}

		$this->render_row( 'ean', self::ean_label(), esc_html( $ean ) );
	}

	/**
	 * Print one row in the shape core's own SKU row has.
	 *
	 * The wrapper and value classes mirror `sku_wrapper` and `sku`, and the label sits
	 * in a `meta-label` span, which is what themes overriding the meta template use to
	 * style the words in front of each value. A theme that does not know the class
	 * still renders the label as text.
	 *
	 * The value is filtered against a list of its own rather than through
	 * `wp_kses_post()`, which does not allow `bdi` — the element `wc_price()` wraps every
	 * amount in so a price reads correctly in a right-to-left language. Passing the price
	 * through the post rules would quietly throw that away.
	 *
	 * @param string $slug  Row identifier, used in the CSS classes.
	 * @param string $label Label to show in front of the value.
	 * @param string $value Value, already escaped or built as safe HTML.
	 * @return void
	 */
	protected function render_row( $slug, $label, $value ) {
		$allowed = array(
			'span' => array( 'class' => true ),
			'bdi'  => array(),
		);

		printf(
			'<span class="wksync_%1$s_wrapper"><span class="meta-label">%2$s</span> <span class="wksync-%1$s">%3$s</span></span>',
			esc_attr( $slug ),
			esc_html( $label ),
			wp_kses( $value, $allowed )
		);
	}

	/**
	 * What to call the recommended retail price.
	 *
	 * @return string The shop's label, or the default when it has not set one.
	 */
	public static function msrp_label() {
		return self::label( Settings::MSRP_LABEL, __( 'RRP:', 'woo-kontor-sync-pro' ) );
	}

	/**
	 * What to call the EAN.
	 *
	 * @return string The shop's label, or the default when it has not set one.
	 */
	public static function ean_label() {
		return self::label( Settings::EAN_LABEL, __( 'EAN:', 'woo-kontor-sync-pro' ) );
	}

	/**
	 * Read a label setting, falling back to the translated default.
	 *
	 * The default is resolved here rather than stored, so a shop that never touched the
	 * field reads the label in its own language, and one that did reads exactly what it
	 * typed. That also makes an emptied field a way back to the default rather than a
	 * row with nothing in front of it.
	 *
	 * @param string $key      Setting holding the label.
	 * @param string $fallback Label to use when the setting is empty.
	 * @return string Label to display.
	 */
	protected static function label( $key, $fallback ) {
		$settings = Settings::get_settings();
		$label    = isset( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';

		return '' !== $label ? $label : $fallback;
	}
}
