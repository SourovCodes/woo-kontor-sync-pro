<?php
/**
 * Enforcement of the quantities Kontor sells an article in.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Frontend;

use WC_Product;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;

defined( 'ABSPATH' ) || exit;

/**
 * Holds customers to the minimum quantity and the quantity step Kontor states.
 *
 * The product sync records both figures on every product it imports. This is what
 * makes the shop obey them: the quantity input offers only valid numbers, and every
 * way a quantity can reach the cart is checked against them on the server, because an
 * HTML min and step attribute is a courtesy to a browser rather than a rule.
 *
 * Everything hangs off two WooCommerce filters, `woocommerce_quantity_input_min` and
 * `woocommerce_quantity_input_step`. They are the single point every part of
 * WooCommerce reads these numbers through — `WC_Product::get_min_purchase_quantity()`
 * and `get_purchase_quantity_step()` apply them, `wc_get_quantity_input_args()` reads
 * those, and the Store API's `QuantityLimits` reads that in turn — so the classic
 * quantity input, the cart block and the checkout block all agree without being
 * addressed separately.
 *
 * What those filters do not do is refuse a quantity that arrives anyway. The Store
 * API validates against its own limits, but the classic add-to-cart and cart-update
 * handlers simply take the number posted to them, so those are checked here.
 *
 * Registered whether or not the setting is on, with every callback asking as it runs.
 * Turning enforcement on or off is then immediate rather than something the next page
 * load discovers, and it never depends on a product sync having run since.
 */
class Quantities {

	/**
	 * Register the hooks.
	 *
	 * Not gated on is_admin(): a Store API cart request is neither an admin screen nor
	 * a template render, and it is the path the block cart and checkout take.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_quantity_input_min', array( $this, 'filter_min' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_step', array( $this, 'filter_step' ), 10, 2 );
		add_filter( 'woocommerce_quantity_input_step_admin', array( $this, 'filter_step_admin' ), 10, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_args', array( $this, 'filter_loop_args' ), 10, 2 );

		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 4 );
		add_filter( 'woocommerce_update_cart_validation', array( $this, 'validate_update' ), 10, 4 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
	}

	/**
	 * Whether the shop has asked for these quantities to be enforced.
	 *
	 * Read on every call rather than remembered. The option is a single cached read,
	 * and paying for it here is what keeps the setting and the shop's behaviour from
	 * disagreeing for the rest of a request.
	 *
	 * @return bool True when the quantities are binding.
	 */
	public static function enforced() {
		$settings = Settings::get_settings();

		return ! empty( $settings[ Settings::ENFORCE_QUANTITIES ] );
	}

	/**
	 * The quantities a product may actually be bought in.
	 *
	 * The stored minimum is raised to a multiple of the step, exactly as WooCommerce's
	 * own `QuantityLimits::get_add_to_cart_limits()` does, because WooCommerce checks a
	 * quantity by asking whether it is a multiple of the step rather than whether it is
	 * the minimum plus some number of steps. An article sold in sixes with a step of two
	 * is bought as 6, 8, 10; one whose minimum was five would be bought as 6, 8, 10 too.
	 * Doing the raising here rather than leaving it to the Store API is what stops the
	 * classic quantity input offering a five the block cart would refuse.
	 *
	 * Both default to one, which is WooCommerce's own default and means "no constraint".
	 *
	 * @param mixed $product Product to read, which is not always a product.
	 * @return array The min and step, both at least 1.
	 */
	public static function limits( $product ) {
		$none = array(
			'min'  => 1,
			'step' => 1,
		);

		if ( ! $product instanceof WC_Product || ! self::enforced() ) {
			return $none;
		}

		$step = max( 1, (int) $product->get_meta( ProductSync::META_QTY_STEP ) );
		$min  = max( 1, (int) $product->get_meta( ProductSync::META_MIN_QTY ) );

		return array(
			'min'  => max( $step, (int) ceil( $min / $step ) * $step ),
			'step' => $step,
		);
	}

	/**
	 * Report the article's minimum quantity to WooCommerce.
	 *
	 * @param mixed $min     Minimum WooCommerce arrived at.
	 * @param mixed $product Product being asked about.
	 * @return mixed The minimum to use.
	 */
	public function filter_min( $min, $product = null ) {
		$limits = self::limits( $product );

		return $limits['min'] > 1 ? $limits['min'] : $min;
	}

	/**
	 * Report the article's quantity step to WooCommerce.
	 *
	 * @param mixed $step    Step WooCommerce arrived at.
	 * @param mixed $product Product being asked about.
	 * @return mixed The step to use.
	 */
	public function filter_step( $step, $product = null ) {
		$limits = self::limits( $product );

		return $limits['step'] > 1 ? $limits['step'] : $step;
	}

	/**
	 * Leave the order screen's quantity boxes alone.
	 *
	 * WooCommerce seeds the admin step from the same product method, so without this a
	 * step of six would follow into the order editor and the refund box, where it does
	 * not belong: refunding one item of six is an ordinary thing to do, and a shop
	 * manager repairing an order is not a customer being sold to.
	 *
	 * Only a step this plugin imposed is undone. Another plugin may have its own reason
	 * for restricting the order screen, and taking that away is not this one's business.
	 *
	 * @param mixed $step    Step WooCommerce arrived at.
	 * @param mixed $product Product being asked about.
	 * @return mixed One where this plugin set the step, otherwise what it was.
	 */
	public function filter_step_admin( $step, $product = null ) {
		return self::limits( $product )['step'] > 1 ? 1 : $step;
	}

	/**
	 * Ask the shop loop's add-to-cart button for a valid quantity.
	 *
	 * The button posts a quantity of one, which for an article sold in sixes is a
	 * refusal waiting to happen with nothing on the page explaining it. The blocks
	 * version of the button passes no quantity at all and takes the minimum from the
	 * product itself, so the key is only replaced where it already exists.
	 *
	 * @param array $args    Template arguments.
	 * @param mixed $product Product the button belongs to.
	 * @return array Template arguments.
	 */
	public function filter_loop_args( $args, $product = null ) {
		if ( ! is_array( $args ) || ! isset( $args['quantity'] ) ) {
			return $args;
		}

		$limits = self::limits( $product );

		if ( (int) $args['quantity'] >= $limits['min'] ) {
			return $args;
		}

		$args['quantity'] = $limits['min'];

		return $args;
	}

	/**
	 * Refuse an add to cart that would leave an invalid quantity in the cart.
	 *
	 * The quantity judged is the one the cart would end up holding, not the one being
	 * added, which is what WooCommerce's own Store API does: adding five and then five
	 * more to an article sold in multiples of two has to be caught, and each half of it
	 * is unobjectionable on its own.
	 *
	 * Lines are matched on the product rather than on the cart item key, because the key
	 * is derived from cart item data this filter is not given. The sync creates simple
	 * products with nothing to split a line on, so the two are the same list.
	 *
	 * @param bool  $passed       Whether validation has passed so far.
	 * @param int   $product_id   Product being added.
	 * @param mixed $quantity     Quantity being added.
	 * @param int   $variation_id Variation being added, if any.
	 * @return bool Whether the item may be added.
	 */
	public function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( ! $passed || ! self::enforced() ) {
			return $passed;
		}

		$product = wc_get_product( $variation_id ? $variation_id : $product_id );

		if ( ! $product instanceof WC_Product ) {
			return $passed;
		}

		$message = $this->violation( $product, (float) $quantity + $this->quantity_in_cart( (int) $product_id, (int) $variation_id ) );

		if ( '' === $message ) {
			return $passed;
		}

		wc_add_notice( $message, 'error' );

		return false;
	}

	/**
	 * Refuse a cart quantity that the article is not sold in.
	 *
	 * @param bool   $passed        Whether validation has passed so far.
	 * @param string $cart_item_key Cart item being changed.
	 * @param array  $values        The cart item.
	 * @param mixed  $quantity      Quantity being set.
	 * @return bool Whether the change may be made.
	 */
	public function validate_update( $passed, $cart_item_key, $values, $quantity ) {
		if ( ! $passed || ! self::enforced() ) {
			return $passed;
		}

		// Zero is how the cart removes a line, and an empty cart breaks no rule.
		if ( (float) $quantity <= 0 ) {
			return $passed;
		}

		$message = $this->violation( isset( $values['data'] ) ? $values['data'] : null, (float) $quantity );

		if ( '' === $message ) {
			return $passed;
		}

		wc_add_notice( $message, 'error' );

		return false;
	}

	/**
	 * Check what is already in the cart, on the cart and checkout screens.
	 *
	 * Without this the rule only applies on the way in. A cart filled before the setting
	 * was turned on, or before the sync had recorded the figures, would otherwise carry
	 * an invalid quantity all the way through checkout and into an order Kontor cannot
	 * fulfil.
	 *
	 * @return void
	 */
	public function check_cart_items() {
		if ( ! self::enforced() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$message = $this->violation(
				isset( $item['data'] ) ? $item['data'] : null,
				isset( $item['quantity'] ) ? (float) $item['quantity'] : 0
			);

			if ( '' !== $message ) {
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * How much of a product the cart already holds.
	 *
	 * @param int $product_id   Product to count.
	 * @param int $variation_id Variation to count, if any.
	 * @return float Quantity already in the cart.
	 */
	protected function quantity_in_cart( $product_id, $variation_id ) {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return 0;
		}

		$quantity = 0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$matches = isset( $item['product_id'] ) && (int) $item['product_id'] === $product_id
				&& (int) ( isset( $item['variation_id'] ) ? $item['variation_id'] : 0 ) === $variation_id;

			if ( $matches ) {
				$quantity += (float) $item['quantity'];
			}
		}

		return $quantity;
	}

	/**
	 * Say why a quantity is not one the article is sold in.
	 *
	 * The minimum is reported before the step, because a customer asking for less than
	 * the minimum has to be told the minimum first — being told the number is not a
	 * multiple of two would send them to four when the answer is six.
	 *
	 * @param mixed $product  Product being bought.
	 * @param float $quantity Quantity being asked for.
	 * @return string The complaint, or an empty string when there is none.
	 */
	protected function violation( $product, $quantity ) {
		$limits = self::limits( $product );

		if ( 1 === $limits['min'] && 1 === $limits['step'] ) {
			return '';
		}

		if ( $quantity < $limits['min'] ) {
			return sprintf(
				/* translators: 1: product name, 2: smallest quantity it is sold in. */
				__( '%1$s is sold in a minimum quantity of %2$s.', 'woo-kontor-sync-pro' ),
				$product->get_name(),
				number_format_i18n( $limits['min'] )
			);
		}

		/*
		 * Compared with a tolerance rather than by remainder, which is how WooCommerce's
		 * own QuantityLimits::is_multiple_of() does it. Quantities are whole numbers
		 * unless a site has filtered wc_stock_amount into floats, and there a remainder
		 * of 1.9999999 would read as a violation of a rule that was in fact met.
		 */
		$multiples = $quantity / $limits['step'];

		if ( abs( $multiples - round( $multiples ) ) > 0.0001 ) {
			return sprintf(
				/* translators: 1: product name, 2: quantity step it is sold in. */
				__( '%1$s is sold in multiples of %2$s.', 'woo-kontor-sync-pro' ),
				$product->get_name(),
				number_format_i18n( $limits['step'] )
			);
		}

		return '';
	}
}
