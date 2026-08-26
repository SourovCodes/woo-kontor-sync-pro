<?php
/**
 * The orders the sweep has stopped trying to send.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Sync\OrderSync;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the sweep's "set aside" count into a list of orders somebody can open.
 *
 * `OrderSync::MAX_PUSH_ATTEMPTS` is what stops one unsendable order starving every
 * order placed after it, and on its own it would trade a silent failure for a quieter
 * one: the orders would leave the queue with nothing anywhere naming them. The marker
 * is `_wksync_`-prefixed and therefore protected, so the orders list will not show it
 * and no built-in filter can find it.
 *
 * This is deliberately smaller than `Admin\HeldProducts`. There is one condition
 * rather than five, so there is one link and no views apparatus: the count on the
 * settings screen and in the status report has somewhere to go, which is the whole
 * requirement.
 */
class StuckOrders {

	/**
	 * Query argument that narrows the orders list to these orders.
	 */
	const QUERY_ARG = 'wksync_stuck';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_query' ) );
	}

	/**
	 * Narrow the orders list when the query argument is present.
	 *
	 * The meta query is appended rather than assigned. WooCommerce's own screen puts
	 * clauses on the same query, and replacing them would silently widen whatever the
	 * shop manager had already narrowed.
	 *
	 * @param array $args Query arguments for the orders list.
	 * @return array Query arguments, narrowed when asked.
	 */
	public function filter_query( $args ) {
		if ( ! is_array( $args ) || ! self::requested() ) {
			return $args;
		}

		$meta_query = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		$meta_query[] = array(
			'key'     => OrderSync::META_PUSH_GIVEN_UP,
			'compare' => 'EXISTS',
		);

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- The marker is protected meta; there is no other way to ask for these orders.
		$args['meta_query'] = $meta_query;

		return $args;
	}

	/**
	 * Whether the current request asked for these orders.
	 *
	 * @return bool True when the query argument is set.
	 */
	protected static function requested() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- A read-only list filter, exactly like core's own status links.
		return ! empty( $_GET[ self::QUERY_ARG ] );
	}

	/**
	 * How many orders the sweep has set aside.
	 *
	 * @return int Number of orders.
	 */
	public static function total() {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}

		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'status'     => 'any',
				'return'     => 'ids',

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- See the class docblock; the marker is protected meta.
				'meta_query' => array(
					array(
						'key'     => OrderSync::META_PUSH_GIVEN_UP,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return is_array( $orders ) ? count( $orders ) : 0;
	}

	/**
	 * The orders list, narrowed to these orders.
	 *
	 * @return string Admin URL.
	 */
	public static function url() {
		return add_query_arg(
			array(
				'page'          => 'wc-orders',
				self::QUERY_ARG => 1,
			),
			admin_url( 'admin.php' )
		);
	}
}
