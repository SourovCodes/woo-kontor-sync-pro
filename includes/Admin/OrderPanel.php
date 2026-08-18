<?php
/**
 * Everything Kontor knows about an order, on the order screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WC_Order;
use WooKontorSync\Invoices\Download;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;
use WooKontorSync\Sync\OrderSync;

defined( 'ABSPATH' ) || exit;

/**
 * A meta box holding the order's whole Kontor side.
 *
 * Every identifier this plugin writes onto an order lives under an underscore-prefixed
 * key, which keeps its storage out of everyone else's way and out of the Custom Fields
 * panel. The cost is that none of it can be seen at all: until this panel there was no
 * way in wp-admin to read the Kontor order number, find out why a particular order
 * never reached the ERP, or reach an invoice PDF, which is only rendered on the
 * customer's own order page.
 *
 * A meta box rather than woocommerce_admin_order_data_after_order_details. That hook
 * fires inside the Order data box's address column, which is sized for a billing
 * address and is the one part of the screen a shop manager is editing — a read-only
 * block wedged in between the shipping fields invites the assumption that it is
 * editable too. A meta box is also the user's to collapse, move or switch off in
 * Screen Options, which is the right answer for staff who never think about the ERP.
 *
 * Read-only, and deliberately not a form at all, for the same reason as ProductFields
 * and more strongly: every value here is rewritten by a background job nobody can see
 * running, so a tracking number typed in by hand would survive until the next delivery
 * sync and then silently revert. The state changes belong to OrderActions, so this
 * class has no side effects whatever.
 */
class OrderPanel {

	/**
	 * The meta box's own id.
	 */
	const BOX_ID = 'wksync-order-panel';

	/**
	 * Register the meta box.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
	}

	/**
	 * Add the box to the order edit screen.
	 *
	 * Only the HPOS screen is named, because that is the only one there is: the plugin
	 * refuses to boot without High-Performance Order Storage, and
	 * wc_get_page_screen_id() answers with the legacy post type when it is off.
	 *
	 * Side rather than normal — the content is a short column of identifiers and
	 * links, the same shape and the same kind of question as Order notes — and default
	 * priority, so the Order actions box keeps the top of the column.
	 *
	 * @return void
	 */
	public function add_box() {
		add_meta_box(
			self::BOX_ID,
			__( 'Kontor', 'woo-kontor-sync-pro' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'default'
		);
	}

	/**
	 * Draw the panel.
	 *
	 * Under HPOS the order edit screen calls do_meta_boxes() with the order itself, so
	 * the callback is handed a WC_Order rather than the WP_Post a post-type screen
	 * would pass. The check is a type guard, not a compatibility path.
	 *
	 * @param mixed $order Order being edited.
	 * @return void
	 */
	public function render( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$this->render_section( __( 'Upload', 'woo-kontor-sync-pro' ), $this->upload_rows( $order ) );
		$this->render_error( $order );
		$this->render_delivery( $order );
		$this->render_invoices( $order );

		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Supplied by Kontor and rewritten by every sync, so it is changed in the ERP rather than here.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * What this plugin has sent to Kontor about the order.
	 *
	 * The order number sent looks redundant beside the order id and is not: it is the
	 * value Kontor deduplicated on, recorded at push time rather than recomputed, so
	 * when a delivery or invoice row goes missing the first question is whether it
	 * still agrees with what the ERP holds.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return array Label to value.
	 */
	protected function upload_rows( $order ) {
		$pushed = (int) $order->get_meta( OrderSync::META_PUSHED_AT );
		$never  = __( 'Not sent yet', 'woo-kontor-sync-pro' );

		return array(
			__( 'Sent to Kontor', 'woo-kontor-sync-pro' ) => $pushed > 0
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $pushed )
				: $never,
			__( 'Kontor order number', 'woo-kontor-sync-pro' ) => $this->text(
				$order,
				OrderSync::META_KONTOR_ORDER,
				// Kontor does not return the order number in its reply to an upload; the
				// delivery sync backfills it, so a sent order legitimately has none yet.
				__( 'Not assigned yet', 'woo-kontor-sync-pro' )
			),
			__( 'Order number sent', 'woo-kontor-sync-pro' ) => $this->text( $order, OrderSync::META_ORDER_NUMBER, $never ),
		);
	}

	/**
	 * Draw what Kontor has reported back about the shipment.
	 *
	 * The status is printed as Kontor's own token rather than mapped onto a
	 * WooCommerce label. It is the ERP's word for where the order is, and translating
	 * it would make it look like it agrees with the order status beside it when it may
	 * well not.
	 *
	 * Provider and tracking number arrive as null rather than absent, so a synced but
	 * unshipped order has all four keys present and empty. The tracking number is what
	 * decides there is a shipment; the status is news either way.
	 *
	 * It has its own renderer rather than a row list because of the one row that is a
	 * link. Handing markup through a list every other value reaches as plain text is
	 * how a value that should have been escaped ends up trusted.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return void
	 */
	protected function render_delivery( $order ) {
		printf( '<h4>%s</h4>', esc_html__( 'Delivery', 'woo-kontor-sync-pro' ) );

		$this->render_row(
			__( 'Kontor status', 'woo-kontor-sync-pro' ),
			$this->text( $order, DeliverySync::META_STATUS, __( 'Nothing reported yet', 'woo-kontor-sync-pro' ) )
		);

		$number = trim( (string) $order->get_meta( DeliverySync::META_TRACKING ) );

		if ( '' === $number ) {
			$this->render_row(
				__( 'Tracking number', 'woo-kontor-sync-pro' ),
				__( 'Nothing shipped yet', 'woo-kontor-sync-pro' )
			);

			return;
		}

		$provider = trim( (string) $order->get_meta( DeliverySync::META_PROVIDER ) );

		if ( '' !== $provider ) {
			$this->render_row( __( 'Carrier', 'woo-kontor-sync-pro' ), $provider );
		}

		$url = trim( (string) $order->get_meta( DeliverySync::META_TRACKING_URL ) );

		if ( '' === $url ) {
			$this->render_row( __( 'Tracking number', 'woo-kontor-sync-pro' ), $number );

			return;
		}

		printf(
			'<p><strong>%1$s:</strong> <a href="%2$s" target="_blank" rel="noopener nofollow">%3$s</a></p>',
			esc_html__( 'Tracking number', 'woo-kontor-sync-pro' ),
			esc_url( $url ),
			esc_html( $number )
		);
	}

	/**
	 * Draw one headed group of rows.
	 *
	 * @param string $heading Group heading.
	 * @param array  $rows    Label to value, both plain text.
	 * @return void
	 */
	protected function render_section( $heading, array $rows ) {
		printf( '<h4>%s</h4>', esc_html( $heading ) );

		foreach ( $rows as $label => $value ) {
			$this->render_row( $label, $value );
		}
	}

	/**
	 * Draw one label and its value.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value, plain text.
	 * @return void
	 */
	protected function render_row( $label, $value ) {
		printf(
			'<p><strong>%1$s:</strong> %2$s</p>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Draw the reason an order never reached Kontor.
	 *
	 * A notice rather than another row: this is the one thing on the panel that wants
	 * doing something about, and it is the only place the reason a specific order was
	 * refused is visible at all.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return void
	 */
	protected function render_error( $order ) {
		$error = trim( (string) $order->get_meta( OrderSync::META_PUSH_ERROR ) );

		if ( '' === $error ) {
			return;
		}

		printf(
			'<div class="notice notice-error inline"><p>%s</p></div>',
			esc_html( $error )
		);
	}

	/**
	 * Draw the invoice list.
	 *
	 * The download links are the ones the customer's own order page builds. No new
	 * endpoint is needed because Download::permitted() already grants anyone holding
	 * this screen's capability an invoice outright.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return void
	 */
	protected function render_invoices( $order ) {
		printf( '<h4>%s</h4>', esc_html__( 'Invoices', 'woo-kontor-sync-pro' ) );

		$invoices = InvoiceSync::for_order( $order );

		if ( empty( $invoices ) ) {
			printf( '<p>%s</p>', esc_html__( 'None downloaded yet', 'woo-kontor-sync-pro' ) );

			if ( current_user_can( Settings::CAPABILITY ) ) {
				printf(
					'<p><a href="%1$s">%2$s</a></p>',
					esc_url( admin_url( 'admin.php?page=' . Settings::PAGE_SLUG ) ),
					esc_html__( 'Run the invoice sync', 'woo-kontor-sync-pro' )
				);
			}

			return;
		}

		// Stacked rather than tabulated: the side column is narrow enough that a label
		// and a link side by side would wrap into each other.
		foreach ( $invoices as $invoice ) {
			printf(
				'<p>%1$s<br/><a href="%2$s">%3$s</a></p>',
				esc_html( InvoiceSync::label( $invoice ) ),
				esc_url( Download::url( $order, $invoice ) ),
				esc_html__( 'Download PDF', 'woo-kontor-sync-pro' )
			);
		}
	}

	/**
	 * Read a meta value, or say there is none.
	 *
	 * @param WC_Order $order    Order being edited.
	 * @param string   $meta_key Meta key to read.
	 * @param string   $fallback What to show when there is nothing stored.
	 * @return string Text to display.
	 */
	protected function text( $order, $meta_key, $fallback ) {
		$value = trim( (string) $order->get_meta( $meta_key ) );

		return '' === $value ? $fallback : $value;
	}
}
