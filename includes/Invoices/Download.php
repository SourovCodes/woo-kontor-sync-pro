<?php
/**
 * Authorised delivery of a stored invoice PDF.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Invoices;

use WC_Order;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\InvoiceSync;

defined( 'ABSPATH' ) || exit;

/**
 * The only way an invoice file leaves the server.
 *
 * Storage keeps the PDFs somewhere the web server will not serve, so every download
 * comes through here and every download is checked. Three ways to be entitled to an
 * invoice, and they are checked in that order:
 *
 * - a shop manager, who can see every order anyway;
 * - the logged-in customer the order belongs to;
 * - anyone holding the order key, which is the same token WooCommerce itself trusts
 *   on the order-received page. Guest checkouts have no account to authenticate
 *   against, and the invoice email has to link to something that works.
 *
 * There is deliberately no nonce. A download changes nothing, and a nonce would make
 * the link in an order email expire within a day of it being sent, which is exactly
 * when a customer files the mail to come back to later.
 */
class Download {

	/**
	 * The admin-post action this handler answers to.
	 */
	const ACTION = 'wksync_invoice';

	/**
	 * Register the download endpoint.
	 *
	 * Registered for logged-out visitors too: an order placed as a guest has no
	 * account behind it, and its invoice link is authorised by the order key.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The download URL for one invoice.
	 *
	 * The order key travels in the URL because that is what makes the link work from
	 * an email, where there may be no session at all. It is a capability token rather
	 * than personal data, and it is the same one WooCommerce puts in the URL of every
	 * order-received page.
	 *
	 * @param WC_Order $order   Order the invoice belongs to.
	 * @param array    $invoice Invoice entry from InvoiceSync::for_order().
	 * @return string Absolute URL.
	 */
	public static function url( $order, array $invoice ) {
		return add_query_arg(
			array(
				'action'  => self::ACTION,
				'order'   => $order->get_id(),
				'invoice' => rawurlencode( (string) $invoice['id'] ),
				'key'     => rawurlencode( $order->get_order_key() ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Whether the current request may read an order's invoices.
	 *
	 * @param WC_Order $order Order being asked for.
	 * @param string   $key   Order key supplied with the request, if any.
	 * @return bool True when the request is entitled to the invoice.
	 */
	public static function permitted( $order, $key = '' ) {
		if ( current_user_can( Settings::CAPABILITY ) ) {
			return true;
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 && $user_id === $order->get_customer_id() ) {
			return true;
		}

		$key = (string) $key;

		// hash_equals() rather than a plain comparison: the key is a secret, and this
		// is a public endpoint anyone can time.
		return '' !== $key && hash_equals( $order->get_order_key(), $key );
	}

	/**
	 * Serve an invoice, or refuse.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A read-only download, authorised by capability or order key; see the class docblock.
		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0;
		$wanted   = isset( $_GET['invoice'] ) ? sanitize_text_field( wp_unslash( $_GET['invoice'] ) ) : '';
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof WC_Order ) {
			$this->refuse( __( 'That order could not be found.', 'woo-kontor-sync-pro' ), 404 );
		}

		if ( ! self::permitted( $order, $key ) ) {
			/*
			 * The same message whether the order exists and is someone else's or the key
			 * is simply wrong. Distinguishing them would let anyone confirm which order
			 * numbers are real.
			 */
			$this->refuse( __( 'You do not have permission to download this invoice.', 'woo-kontor-sync-pro' ), 403 );
		}

		$invoice = $this->find( $order, $wanted );

		if ( null === $invoice ) {
			$this->refuse( __( 'That invoice is not available for this order.', 'woo-kontor-sync-pro' ), 404 );
		}

		$path = Storage::resolve( $invoice['file'] );

		if ( is_wp_error( $path ) ) {
			$this->refuse( $path->get_error_message(), 404 );
		}

		$this->serve( $path, $invoice['number'] );
	}

	/**
	 * Find the requested invoice among the ones an order holds.
	 *
	 * @param WC_Order $order  Order to search.
	 * @param string   $wanted Document id from the request.
	 * @return array|null The invoice entry, or null when it is not this order's.
	 */
	protected function find( $order, $wanted ) {
		if ( '' === $wanted ) {
			return null;
		}

		foreach ( InvoiceSync::for_order( $order ) as $invoice ) {
			if ( (string) $invoice['id'] === $wanted ) {
				return $invoice;
			}
		}

		return null;
	}

	/**
	 * Stream a PDF to the browser.
	 *
	 * @param string $path   Absolute path to the file.
	 * @param string $number Invoice number, used for the download filename.
	 * @return void
	 */
	protected function serve( $path, $number ) {
		/*
		 * An ASCII filename built from the invoice number rather than a translated
		 * one: this is a header value, where a German umlaut needs encoding that not
		 * every mail client and browser agrees on, and the number is what identifies
		 * the document anyway.
		 */
		$filename = sanitize_file_name( sprintf( 'invoice-%s.pdf', '' === $number ? 'kontor' : $number ) );

		nocache_headers();

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		/*
		 * Streamed rather than read through WP_Filesystem, which would pull the whole
		 * PDF into memory to echo it straight back out again.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a file to the browser; see above.
		readfile( $path );

		exit;
	}

	/**
	 * End the request with a message and a status code.
	 *
	 * @param string $message Reason to show.
	 * @param int    $status  HTTP status code.
	 * @return void
	 */
	protected function refuse( $message, $status ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Invoice unavailable', 'woo-kontor-sync-pro' ),
			array( 'response' => absint( $status ) )
		);
	}
}
