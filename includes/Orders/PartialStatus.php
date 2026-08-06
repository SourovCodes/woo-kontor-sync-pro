<?php
/**
 * The custom order status backing Kontor's "partially completed".
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Registers a "Partially completed" order status.
 *
 * Kontor reports four order statuses. Three of them — cancelled, in progress and
 * completed — line up with statuses WooCommerce already has. The fourth, an order
 * partly shipped and partly still to come, has no equivalent, and the delivery sync
 * has nowhere to put it: leaving such an order in processing hides that anything
 * shipped at all, and completing it tells the customer the whole order is on its
 * way and emails them to say so.
 *
 * The status is a paid one. Everything a completed order lets a customer do with
 * what they have already paid for — downloads, reports, the order counting as
 * revenue — has to keep working for the part of the order that has shipped.
 *
 * No email is attached to it, deliberately: WooCommerce mails on the transitions it
 * knows about, and this one carries no news the shop has written yet.
 */
class PartialStatus {

	/**
	 * Status slug, without the "wc-" prefix.
	 *
	 * Deliberately short. The prefixed key "wc-partial-complete" is 19 characters,
	 * and the status column orders are stored in holds 20 — a longer name is
	 * truncated on the way in and matches nothing on the way out.
	 */
	const STATUS = 'partial-complete';

	/**
	 * Status key as WooCommerce and WordPress record it.
	 */
	const KEY = 'wc-' . self::STATUS;

	/**
	 * Register the status and the places it has to appear.
	 *
	 * Not gated on is_admin(): an order can only be moved into a status WooCommerce
	 * considers valid, and the delivery sync moves orders from a background job.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'init', array( $this, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( $this, 'add_status' ) );
		add_filter( 'woocommerce_order_is_paid_statuses', array( $this, 'add_paid_status' ) );

		// WooCommerce handles any "mark_<status>" bulk action generically, so only the
		// label needs adding. HPOS is required, so only the HPOS screen is hooked.
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add_bulk_action' ) );
	}

	/**
	 * Register the post status the order status is built on.
	 *
	 * WooCommerce registers its own order statuses this way under HPOS as well, and
	 * a status that is not registered here is missing from the admin filters.
	 *
	 * @return void
	 */
	public function register_status() {
		register_post_status(
			self::KEY,
			array(
				'label'                     => self::label(),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders. */
				'label_count'               => _n_noop(
					'Partially completed <span class="count">(%s)</span>',
					'Partially completed <span class="count">(%s)</span>',
					'woo-kontor-sync-pro'
				),
			)
		);
	}

	/**
	 * Add the status to the list WooCommerce treats as valid.
	 *
	 * Placed after Processing, which is where an order arrives from and the order it
	 * reads in on the orders screen.
	 *
	 * @param array $statuses Status key to label.
	 * @return array Statuses with this one included.
	 */
	public function add_status( $statuses ) {
		$statuses = (array) $statuses;

		if ( isset( $statuses[ self::KEY ] ) ) {
			return $statuses;
		}

		$updated = array();

		foreach ( $statuses as $key => $label ) {
			$updated[ $key ] = $label;

			if ( 'wc-processing' === $key ) {
				$updated[ self::KEY ] = self::label();
			}
		}

		if ( ! isset( $updated[ self::KEY ] ) ) {
			$updated[ self::KEY ] = self::label();
		}

		return $updated;
	}

	/**
	 * Count a partially completed order as paid.
	 *
	 * @param array $statuses Paid status slugs, without the "wc-" prefix.
	 * @return array Statuses with this one included.
	 */
	public function add_paid_status( $statuses ) {
		$statuses   = (array) $statuses;
		$statuses[] = self::STATUS;

		return array_values( array_unique( $statuses ) );
	}

	/**
	 * Offer the status as a bulk action on the orders screen.
	 *
	 * @param array $actions Bulk actions keyed by action name.
	 * @return array Actions with this one included.
	 */
	public function add_bulk_action( $actions ) {
		$actions = (array) $actions;
		$key     = 'mark_' . self::STATUS;

		if ( isset( $actions[ $key ] ) ) {
			return $actions;
		}

		$updated = array();

		foreach ( $actions as $action => $label ) {
			$updated[ $action ] = $label;

			if ( 'mark_processing' === $action ) {
				$updated[ $key ] = __( 'Change status to partially completed', 'woo-kontor-sync-pro' );
			}
		}

		if ( ! isset( $updated[ $key ] ) ) {
			$updated[ $key ] = __( 'Change status to partially completed', 'woo-kontor-sync-pro' );
		}

		return $updated;
	}

	/**
	 * The status label.
	 *
	 * A method rather than a constant, because it is translated and the catalogue is
	 * not loaded when the class is.
	 *
	 * @return string Translated label.
	 */
	public static function label() {
		return __( 'Partially completed', 'woo-kontor-sync-pro' );
	}
}
