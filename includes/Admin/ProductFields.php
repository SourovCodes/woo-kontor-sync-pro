<?php
/**
 * Kontor's figures on the product edit screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WC_Product;
use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the sales quantities on the product's Inventory tab.
 *
 * The meta keys are underscore-prefixed and therefore protected, which keeps this
 * plugin's storage out of the Custom Fields panel and out of everyone else's way. The
 * cost is that a shop manager has nowhere to see why a product refuses a quantity.
 * This is that place.
 *
 * Read-only, and deliberately not a form field at all: no input, no name attribute,
 * nothing submitted and nothing to save. Kontor is the source of truth for the
 * catalogue, and every product sync rewrites both figures — an editable box here would
 * accept a change that quietly disappeared at the next run, which is worse than one
 * that never offered itself. The place to change them is the ERP.
 *
 * Shown for simple products only. WooCommerce reads these figures from the variation
 * rather than the parent, so a value on a variable product would mean nothing anyway.
 */
class ProductFields {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_product_options_inventory_product_data', array( $this, 'render' ) );
	}

	/**
	 * Draw both figures at the foot of the Inventory tab.
	 *
	 * The product comes from `$product_object`, which is what WooCommerce's own
	 * inventory template reads: the meta box sets it to the product being edited, so it
	 * carries unsaved changes that re-fetching from the post ID would not.
	 *
	 * @return void
	 */
	public function render() {
		global $product_object;

		$rows = array(
			__( 'Minimum quantity', 'woo-kontor-sync-pro' ) => $this->value( $product_object, ProductSync::META_MIN_QTY ),
			__( 'Quantity step', 'woo-kontor-sync-pro' ) => $this->value( $product_object, ProductSync::META_QTY_STEP ),
		);

		echo '<div class="options_group show_if_simple">';

		foreach ( $rows as $label => $value ) {
			printf(
				'<p class="form-field"><label>%1$s</label><span>%2$s</span></p>',
				esc_html( $label ),
				esc_html( $value )
			);
		}

		printf(
			'<p class="form-field"><span class="description">%s</span></p>',
			esc_html__( 'Supplied by Kontor and rewritten by every product sync, so they are changed in the ERP rather than here. They only hold customers to anything while "Sales quantities" is switched on under WooCommerce → Kontor Sync.', 'woo-kontor-sync-pro' )
		);

		echo '</div>';
	}

	/**
	 * What to show for one of the figures.
	 *
	 * A product with no constraint reads as having none rather than as one sold in lots
	 * of nothing, and a stored 1 cannot arise: the import treats it as no constraint and
	 * stores nothing at all.
	 *
	 * @param mixed  $product  Product being edited.
	 * @param string $meta_key Meta key to read.
	 * @return string Text to display.
	 */
	protected function value( $product, $meta_key ) {
		$quantity = $product instanceof WC_Product ? (int) $product->get_meta( $meta_key ) : 0;

		return $quantity > 1
			? number_format_i18n( $quantity )
			: __( 'None', 'woo-kontor-sync-pro' );
	}
}
