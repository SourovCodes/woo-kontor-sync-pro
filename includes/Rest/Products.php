<?php
/**
 * Product fields added to the WooCommerce REST API.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Rest;

use WooKontorSync\Frontend\Quantities;
use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes what Kontor says about a product on /wc/v3/products.
 *
 * The values are kept in protected meta keys, which the REST API does not serve:
 * anything reading products over HTTP — a headless storefront, an app, another
 * plugin — could see the price and not the figure beside it, or offer a quantity the
 * shop will refuse. These add them as first-class fields alongside what they belong
 * with.
 *
 * Read-only, because the product sync owns the values. Every run rewrites them from
 * Kontor's feed and deletes them when the feed stops carrying one, so a write through
 * the API would survive only until the next sync and would look like data loss
 * rather than the overwrite it is.
 */
class Products {

	/**
	 * The recommended retail price field.
	 *
	 * Deliberately not prefixed, unlike the meta key it reads. The prefix exists to
	 * keep this plugin's storage out of everyone else's way; this is a published
	 * field name, and a consumer asking for a recommended retail price should not
	 * have to know which plugin filled it in.
	 */
	const FIELD = 'msrp';

	/**
	 * The smallest quantity the product may be bought in.
	 */
	const FIELD_MIN_QTY = 'min_order_quantity';

	/**
	 * The quantity step the product may be bought in.
	 */
	const FIELD_QTY_STEP = 'order_quantity_step';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Add the fields to the product resource.
	 *
	 * Registered for products only. The sync creates simple products and writes the
	 * meta on those alone, so a variation would report null for a figure that was
	 * never going to be there.
	 *
	 * @return void
	 */
	public function register_field() {
		register_rest_field(
			'product',
			self::FIELD,
			array(
				'get_callback' => array( $this, 'value' ),
				'schema'       => array(
					'description' => __( 'Recommended retail price, as supplied by Kontor. Null when there is none.', 'woo-kontor-sync-pro' ),
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);

		register_rest_field(
			'product',
			self::FIELD_MIN_QTY,
			array(
				'get_callback' => array( $this, 'min_quantity' ),
				'schema'       => array(
					'description' => __( 'Smallest quantity this product may be bought in. One when there is no minimum, or when the shop is not enforcing them.', 'woo-kontor-sync-pro' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);

		register_rest_field(
			'product',
			self::FIELD_QTY_STEP,
			array(
				'get_callback' => array( $this, 'quantity_step' ),
				'schema'       => array(
					'description' => __( 'Quantity step this product may be bought in. One when there is no step, or when the shop is not enforcing them.', 'woo-kontor-sync-pro' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * The smallest quantity one product may be bought in.
	 *
	 * One rather than null when there is no minimum: unlike a retail price, every
	 * product has an answer to this, and one is it. That also makes the field safe to
	 * apply blindly, which is the point of publishing it.
	 *
	 * Both quantity fields report what this shop will actually accept rather than what
	 * Kontor stated, which is why they read one while the setting is off. They come
	 * from the same method the cart is held to, so a client obeying them cannot be
	 * refused by a shop that disagrees.
	 *
	 * @param array $response The product's response data.
	 * @return int Smallest quantity, at least one.
	 */
	public function min_quantity( $response ) {
		return Quantities::limits( $this->product( $response ) )['min'];
	}

	/**
	 * The quantity step one product may be bought in.
	 *
	 * @param array $response The product's response data.
	 * @return int Quantity step, at least one.
	 */
	public function quantity_step( $response ) {
		return Quantities::limits( $this->product( $response ) )['step'];
	}

	/**
	 * The product a response is about.
	 *
	 * @param array $response The product's response data.
	 * @return \WC_Product|null The product, or null when it cannot be loaded.
	 */
	protected function product( $response ) {
		$product = isset( $response['id'] ) ? wc_get_product( $response['id'] ) : null;

		return $product ? $product : null;
	}

	/**
	 * Read the recommended retail price for one product.
	 *
	 * Null rather than an empty string or a zero when there is none: an absent price
	 * is a different thing from a price of nothing, and a consumer that renders
	 * whatever it is given must not be handed "0.00" to put next to the real one.
	 *
	 * The stored string is returned as it is, so the field reads exactly like the
	 * price fields beside it — an unformatted decimal, with no currency and no
	 * thousands separator.
	 *
	 * @param array $response The product's response data.
	 * @return string|null Recommended retail price, or null when there is none.
	 */
	public function value( $response ) {
		$product = $this->product( $response );

		if ( ! $product ) {
			return null;
		}

		$msrp = (string) $product->get_meta( ProductSync::META_MSRP );

		return '' === $msrp ? null : $msrp;
	}
}
