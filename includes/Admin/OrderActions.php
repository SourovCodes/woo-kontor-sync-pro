<?php
/**
 * The Kontor entries in the order screen's actions dropdown.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WC_Order;
use WooKontorSync\Emails\Emails;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;

defined( 'ABSPATH' ) || exit;

/**
 * Lets a shop manager send either of this plugin's mails again.
 *
 * Both mails are sent once, by a background job, at the moment something arrived from
 * Kontor. That is the right moment and it is also the only one — a customer who
 * deleted the mail, or gave the wrong address and had it corrected since, has no way
 * back to their invoice except somebody here pressing something.
 *
 * Each entry appears only when it can actually do something. A dropdown entry that
 * silently achieves nothing is worse than an absent one, because the person pressing
 * it has no way to tell the difference.
 *
 * WooCommerce's own order-actions box is the mechanism, which means the request is
 * already an order save: WooCommerce verifies its nonce and the screen is gated on
 * edit_shop_order before any of this runs. The capability is checked again here all
 * the same — a nonce is not authorisation, and woocommerce_order_action_* is a public
 * hook anything can fire.
 *
 * There is deliberately no "fetch this order's invoice from Kontor" entry. The
 * invoices and orders entities honour only filter.shopid, so there is no such request
 * to make: it would in fact start the whole shop-wide import behind a label naming one
 * order. Run now on WooCommerce → Kontor Sync is the honest version of that button,
 * on a screen that shows the run's progress and refuses with a reason.
 */
class OrderActions {

	/**
	 * Action key for sending the invoice mail again.
	 */
	const SEND_INVOICE = 'wksync_send_invoice_email';

	/**
	 * Action key for sending the tracking mail again.
	 */
	const SEND_TRACKING = 'wksync_send_tracking_email';

	/**
	 * Register the dropdown entries and their handlers.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_order_actions', array( $this, 'add_actions' ), 10, 2 );
		add_action( 'woocommerce_order_action_' . self::SEND_INVOICE, array( $this, 'send_invoice' ) );
		add_action( 'woocommerce_order_action_' . self::SEND_TRACKING, array( $this, 'send_tracking' ) );
	}

	/**
	 * Add whichever entries this order has something behind.
	 *
	 * @param array $actions Actions WooCommerce has collected.
	 * @param mixed $order   Order being edited, absent on older callers.
	 * @return array Actions, with this plugin's added where they apply.
	 */
	public function add_actions( $actions, $order = null ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		if ( ! $order instanceof WC_Order ) {
			return $actions;
		}

		// for_order() drops entries whose file has gone from disk, so this offers the
		// mail exactly when there is a PDF to attach to it.
		if ( ! empty( InvoiceSync::for_order( $order ) ) ) {
			$actions[ self::SEND_INVOICE ] = __( 'Email the invoice to the customer again', 'woo-kontor-sync-pro' );
		}

		if ( '' !== trim( (string) $order->get_meta( DeliverySync::META_TRACKING ) ) ) {
			$actions[ self::SEND_TRACKING ] = __( 'Email the tracking details to the customer again', 'woo-kontor-sync-pro' );
		}

		return $actions;
	}

	/**
	 * Send the invoice mail again.
	 *
	 * @param mixed $order Order to send about.
	 * @return void
	 */
	public function send_invoice( $order ) {
		$this->resend(
			$order,
			Emails::INVOICE_KEY,
			__( 'Kontor invoice email sent to the customer.', 'woo-kontor-sync-pro' ),
			__( 'The Kontor invoice email could not be sent.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * Send the tracking mail again.
	 *
	 * @param mixed $order Order to send about.
	 * @return void
	 */
	public function send_tracking( $order ) {
		$this->resend(
			$order,
			Emails::TRACKING_KEY,
			__( 'Kontor tracking email sent to the customer.', 'woo-kontor-sync-pro' ),
			__( 'The Kontor tracking email could not be sent.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * Send one of the mails and record what happened.
	 *
	 * The note is the only feedback there is: WooCommerce answers an order save with
	 * "Order updated" whatever was asked for, so without a note nothing would tell the
	 * person who pressed the entry that anything happened at all.
	 *
	 * @param mixed  $order      Order to send about.
	 * @param string $key        Email to send, keyed as WooCommerce lists it.
	 * @param string $sent       Note to leave when the mail went out.
	 * @param string $failed     Note to leave when it did not.
	 * @return void
	 */
	protected function resend( $order, $key, $sent, $failed ) {
		if ( ! $order instanceof WC_Order || ! current_user_can( 'edit_shop_order', $order->get_id() ) ) {
			return;
		}

		$email = Emails::get( $key );

		if ( ! $email instanceof \WKSYNC_Order_Email ) {
			return;
		}

		/*
		 * Sent through resend() rather than the ordinary trigger, so it goes out even
		 * when the email type is switched off. Somebody pressing this has decided; the
		 * toggle governs what the syncs do on their own.
		 */
		$order->add_order_note( $email->resend( $order ) ? $sent : $failed );
	}
}
