<?php
/**
 * Product fields added to the WooCommerce REST API.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Rest;

use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes the recommended retail price on /wc/v3/products.
 *
 * The value is kept in a protected meta key, which the REST API does not serve:
 * anything reading products over HTTP — a headless storefront, an app, another
 * plugin — could see the price and not the figure beside it. This adds it as a
 * first-class field alongside the prices it belongs with.
 *
 * Read-only, because the product sync owns the value. Every run rewrites it from
 * Kontor's feed and deletes it when the feed stops carrying one, so a write through
 * the API would survive only until the next sync and would look like data loss
 * rather than the overwrite it is.
 */
class Products {

	/**
	 * The field name in the REST response.
	 *
	 * Deliberately not prefixed, unlike the meta key it reads. The prefix exists to
	 * keep this plugin's storage out of everyone else's way; this is a published
	 * field name, and a consumer asking for a recommended retail price should not
	 * have to know which plugin filled it in.
	 */
	const FIELD = 'msrp';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_field' ) );
	}

	/**
	 * Add the field to the product resource.
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
		$product = isset( $response['id'] ) ? wc_get_product( $response['id'] ) : null;

		if ( ! $product ) {
			return null;
		}

		$msrp = (string) $product->get_meta( ProductSync::META_MSRP );

		return '' === $msrp ? null : $msrp;
	}
}
