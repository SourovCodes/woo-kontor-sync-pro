<?php
/**
 * The mail telling a customer their parcel is on its way.
 *
 * @package WooKontorSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent when the delivery sync learns a tracking number the order did not have.
 *
 * Deliberately not sent on every shipment. When Kontor reports an order as completed
 * the delivery sync completes it here too, and WooCommerce's own completion mail
 * already carries the tracking block — the meta is written before the status moves.
 * DeliverySync::announce_tracking() is what keeps the two apart.
 *
 * What is left is everything that mail does not cover: an order Kontor has only
 * partly shipped, which lands in a status carrying no email at all by design; a
 * tracking number arriving on an order already completed; and one arriving on an
 * order that stays in processing. In each of those the shop knows where the parcel is
 * and the customer does not.
 */
class WKSYNC_Customer_Tracking extends WKSYNC_Order_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'wksync_customer_tracking';
		$this->title       = __( 'Kontor shipment tracking', 'woo-kontor-sync-pro' );
		$this->description = __( 'Sent to the customer when the delivery sync learns a tracking number their order did not have — but not when the same run completes the order, because WooCommerce\'s own completed-order email already carries the tracking details. Switch this on only after the delivery sync has run once: Kontor returns every order for the shop on every run, so the first pass records tracking numbers going back months and would mail all of them.', 'woo-kontor-sync-pro' );

		parent::__construct();
	}

	/**
	 * The plugin hook this email answers.
	 *
	 * @return string Hook name.
	 */
	protected function arrival_hook() {
		return 'woo_kontor_sync_tracking_received';
	}

	/**
	 * Default subject.
	 *
	 * @return string Subject line.
	 */
	public function get_default_subject() {
		return __( 'Your order {order_number} is on its way', 'woo-kontor-sync-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'Your parcel is on its way', 'woo-kontor-sync-pro' );
	}

	/**
	 * The sentence saying what has happened.
	 *
	 * @return string Plain text.
	 */
	protected function intro() {
		return __( 'Your order has been handed to the carrier. The tracking details are below.', 'woo-kontor-sync-pro' );
	}
}
