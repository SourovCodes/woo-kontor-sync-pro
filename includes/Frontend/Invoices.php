<?php
/**
 * Customer-facing display of the invoices pulled back from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Frontend;

use WC_Email;
use WC_Order;
use WooKontorSync\Invoices\Download;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Sync\InvoiceSync;

defined( 'ABSPATH' ) || exit;

/**
 * Puts a customer's invoice in front of them.
 *
 * The invoice sync downloads the PDF and files it against the order; without this it
 * would sit in a directory nobody can browse, which is worse than not having it. It
 * appears in the same three places as the tracking details — the My Account order
 * view, the order-received page and the order emails — and is attached to the
 * customer's emails outright, because an invoice is a document people expect to
 * receive rather than one they expect to go and fetch.
 *
 * Admin copies of the emails are skipped: the shop can already see every order, and
 * the attachment would be a second copy of something it issued.
 */
class Invoices {

	/**
	 * Register the display hooks.
	 *
	 * Not gated on is_admin(), for the same reason as the tracking display: order
	 * emails render wherever the status changed, including inside the background job
	 * that completes an order.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 3 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 4 );
	}

	/**
	 * Render the invoice list under the order details.
	 *
	 * @param mixed $order Order being displayed.
	 * @return void
	 */
	public function render_order_details( $order ) {
		$invoices = InvoiceSync::for_order( $order );

		if ( empty( $invoices ) ) {
			return;
		}

		?>
		<section class="woocommerce-order-invoices wksync-order-invoices">
			<h2><?php echo esc_html__( 'Invoices', 'woo-kontor-sync-pro' ); ?></h2>
			<table class="woocommerce-table shop_table wksync-invoice-table">
				<tbody>
					<?php foreach ( $invoices as $invoice ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html( self::label( $invoice ) ); ?></th>
							<td>
								<a href="<?php echo esc_url( Download::url( $order, $invoice ) ); ?>" rel="nofollow">
									<?php echo esc_html__( 'Download PDF', 'woo-kontor-sync-pro' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Render the invoice links in an order email.
	 *
	 * The links appear alongside the attachment rather than instead of it: an
	 * attachment a mail client has stripped or hidden still has to be reachable.
	 *
	 * @param mixed $order         Order the email is about.
	 * @param bool  $sent_to_admin Whether this is an admin copy.
	 * @param bool  $plain_text    Whether the plain-text template is rendering.
	 * @return void
	 */
	public function render_email( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin ) {
			return;
		}

		$invoices = InvoiceSync::for_order( $order );

		if ( empty( $invoices ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_email_plain( $order, $invoices );

			return;
		}

		?>
		<div class="wksync-email-invoices" style="margin-bottom: 24px;">
			<h2><?php echo esc_html__( 'Invoices', 'woo-kontor-sync-pro' ); ?></h2>
			<p>
				<?php foreach ( $invoices as $invoice ) : ?>
					<a href="<?php echo esc_url( Download::url( $order, $invoice ) ); ?>" rel="nofollow">
						<?php echo esc_html( self::label( $invoice ) ); ?>
					</a><br/>
				<?php endforeach; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the invoice links for the plain-text email template.
	 *
	 * @param WC_Order $order    Order the email is about.
	 * @param array    $invoices Invoices to list.
	 * @return void
	 */
	protected function render_email_plain( $order, array $invoices ) {
		$lines = array( wc_strtoupper( __( 'Invoices', 'woo-kontor-sync-pro' ) ) );

		foreach ( $invoices as $invoice ) {
			$lines[] = sprintf(
				/* translators: 1: invoice label with its number and date, 2: download URL. */
				__( '%1$s: %2$s', 'woo-kontor-sync-pro' ),
				self::label( $invoice ),
				esc_url_raw( Download::url( $order, $invoice ) )
			);
		}

		echo "\n" . esc_html( implode( "\n", $lines ) ) . "\n\n";
	}

	/**
	 * Attach an order's invoices to the customer's emails.
	 *
	 * @param array  $attachments Attachment paths WooCommerce has collected.
	 * @param string $email_id    Identifier of the email being sent.
	 * @param mixed  $subject      Object the email is about, which is not always an order.
	 * @param mixed  $email       The WC_Email instance, absent on older callers.
	 * @return array Attachment paths.
	 */
	public function attach( $attachments, $email_id = '', $subject = null, $email = null ) {
		if ( ! is_array( $attachments ) ) {
			$attachments = array();
		}

		/*
		 * Customer emails only. WooCommerce sends the admin copies from the same hook,
		 * and attaching there would mail the shop its own invoice back.
		 */
		if ( ! $email instanceof WC_Email || ! $email->is_customer_email() ) {
			return $attachments;
		}

		$invoices = InvoiceSync::for_order( $subject );

		if ( empty( $invoices ) ) {
			return $attachments;
		}

		/**
		 * Filters whether an order's invoices are attached to a given email.
		 *
		 * Every customer email carrying the order gets them by default, which for a
		 * synced order is the completion mail. A shop that would rather send the
		 * invoice only with one specific email can narrow it here.
		 *
		 * @since 0.6.0
		 *
		 * @param bool     $attach   Whether to attach the invoices.
		 * @param string   $email_id Identifier of the email being sent.
		 * @param WC_Order $subject   Order the email is about.
		 */
		if ( ! apply_filters( 'woo_kontor_sync_attach_invoices', true, $email_id, $subject ) ) {
			return $attachments;
		}

		foreach ( $invoices as $invoice ) {
			$path = Storage::resolve( $invoice['file'] );

			// A file that has gone missing is skipped rather than failing the email:
			// the customer would rather have the order confirmation without it.
			if ( ! is_wp_error( $path ) ) {
				$attachments[] = $path;
			}
		}

		return $attachments;
	}

	/**
	 * Describe one invoice in a line.
	 *
	 * @param array $invoice Invoice entry from InvoiceSync::for_order().
	 * @return string Human-readable label.
	 */
	protected static function label( array $invoice ) {
		$number = isset( $invoice['number'] ) ? (string) $invoice['number'] : '';
		$date   = isset( $invoice['date'] ) ? (string) $invoice['date'] : '';

		if ( '' === $number ) {
			return __( 'Invoice', 'woo-kontor-sync-pro' );
		}

		if ( '' === $date ) {
			/* translators: %s: invoice number. */
			return sprintf( __( 'Invoice %s', 'woo-kontor-sync-pro' ), $number );
		}

		/*
		 * mysql2date() rather than wp_date(): the date arrives without a time, and
		 * converting a bare midnight into the site timezone would move it to the day
		 * before wherever the offset is negative.
		 */
		$issued = mysql2date( get_option( 'date_format' ), $date . ' 00:00:00' );

		return sprintf(
			/* translators: 1: invoice number, 2: date the invoice was issued. */
			__( 'Invoice %1$s of %2$s', 'woo-kontor-sync-pro' ),
			$number,
			$issued
		);
	}
}
