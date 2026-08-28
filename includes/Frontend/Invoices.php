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
 *
 * **Where an invoice has been replaced, the two are never listed as equals.** Kontor
 * corrects an invoice by issuing a second one and saying nothing about the first, so
 * before this an order simply grew a second download link identical in appearance to
 * the one above it, and a customer holding two invoices for one order had no way at
 * all to tell which they owed. The replaced document is still offered — somebody who
 * has already paid against it needs it for their own records — but it is offered
 * under a heading saying it no longer counts, and it is not attached to anything.
 * InvoiceSync::classify() is what decides which is which, and is the one place that
 * decides it.
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
	 * Group an order's invoices under the headings that say what each one is worth.
	 *
	 * An order with a single invoice gets one untitled group, which is what every
	 * order looked like before corrections were distinguished at all. Naming that one
	 * "the valid invoice" would invite the reader to wonder which other one there is.
	 *
	 * @param mixed $order Order being displayed.
	 * @return array List of groups, each with a "title" and an "invoices" list.
	 */
	protected function groups( $order ) {
		$invoices = InvoiceSync::classify( InvoiceSync::for_order( $order ) );

		if ( empty( $invoices ) ) {
			return array();
		}

		if ( 1 === count( $invoices ) ) {
			return array(
				array(
					'title'    => '',
					'invoices' => $invoices,
				),
			);
		}

		$replaced = array_slice( $invoices, 1 );

		return array(
			array(
				'title'    => __( 'Current invoice (valid)', 'woo-kontor-sync-pro' ),
				'invoices' => array( $invoices[0] ),
			),
			array(
				'title'    => _n(
					'Cancelled invoice (no longer valid)',
					'Cancelled invoices (no longer valid)',
					count( $replaced ),
					'woo-kontor-sync-pro'
				),
				'invoices' => $replaced,
			),
		);
	}

	/**
	 * Render the invoice list under the order details.
	 *
	 * @param mixed $order Order being displayed.
	 * @return void
	 */
	public function render_order_details( $order ) {
		$groups = $this->groups( $order );

		if ( empty( $groups ) ) {
			return;
		}

		?>
		<section class="woocommerce-order-invoices wksync-order-invoices">
			<h2><?php echo esc_html__( 'Invoices', 'woo-kontor-sync-pro' ); ?></h2>
			<?php foreach ( $groups as $group ) : ?>
				<?php if ( '' !== $group['title'] ) : ?>
					<h3 class="wksync-invoice-group"><?php echo esc_html( $group['title'] ); ?></h3>
				<?php endif; ?>
				<table class="woocommerce-table shop_table wksync-invoice-table">
					<tbody>
						<?php foreach ( $group['invoices'] as $invoice ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( InvoiceSync::label( $invoice ) ); ?></th>
								<td>
									<a href="<?php echo esc_url( Download::url( $order, $invoice ) ); ?>" rel="nofollow">
										<?php echo esc_html__( 'Download PDF', 'woo-kontor-sync-pro' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
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

		$groups = $this->groups( $order );

		if ( empty( $groups ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_email_plain( $order, $groups );

			return;
		}

		?>
		<div class="wksync-email-invoices" style="margin-bottom: 24px;">
			<h2><?php echo esc_html__( 'Invoices', 'woo-kontor-sync-pro' ); ?></h2>
			<?php foreach ( $groups as $group ) : ?>
				<?php if ( '' !== $group['title'] ) : ?>
					<p style="margin-bottom: 4px;"><strong><?php echo esc_html( $group['title'] ); ?></strong></p>
				<?php endif; ?>
				<p>
					<?php foreach ( $group['invoices'] as $invoice ) : ?>
						<a href="<?php echo esc_url( Download::url( $order, $invoice ) ); ?>" rel="nofollow">
							<?php echo esc_html( InvoiceSync::label( $invoice ) ); ?>
						</a><br/>
					<?php endforeach; ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the invoice links for the plain-text email template.
	 *
	 * @param WC_Order $order  Order the email is about.
	 * @param array    $groups Groups to list, as groups() returns them.
	 * @return void
	 */
	protected function render_email_plain( $order, array $groups ) {
		$lines = array( wc_strtoupper( __( 'Invoices', 'woo-kontor-sync-pro' ) ) );

		foreach ( $groups as $group ) {
			if ( '' !== $group['title'] ) {
				$lines[] = '';
				$lines[] = $group['title'];
			}

			foreach ( $group['invoices'] as $invoice ) {
				$lines[] = sprintf(
					/* translators: 1: invoice label with its number and date, 2: download URL. */
					__( '%1$s: %2$s', 'woo-kontor-sync-pro' ),
					InvoiceSync::label( $invoice ),
					esc_url_raw( Download::url( $order, $invoice ) )
				);
			}
		}

		echo "\n" . esc_html( implode( "\n", $lines ) ) . "\n\n";
	}

	/**
	 * Attach an order's current invoice to the customer's emails.
	 *
	 * Only the invoice that still counts is attached. A cancelled one riding along
	 * beside it would undo the whole point of separating them on the page: the reader
	 * opens the mail, finds two PDFs, and is back to guessing. It stays downloadable
	 * from the links, under the heading that says what it is.
	 *
	 * @param array  $attachments Attachment paths WooCommerce has collected.
	 * @param string $email_id    Identifier of the email being sent.
	 * @param mixed  $subject     Object the email is about, which is not always an order.
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

		$invoice = InvoiceSync::current_for_order( $subject );

		if ( null === $invoice ) {
			return $attachments;
		}

		/**
		 * Filters whether an order's invoice is attached to a given email.
		 *
		 * Every customer email carrying the order gets it by default, which for a
		 * synced order is the completion mail. A shop that would rather send the
		 * invoice only with one specific email can narrow it here.
		 *
		 * @since 0.6.0
		 *
		 * @param bool     $attach   Whether to attach the invoice.
		 * @param string   $email_id Identifier of the email being sent.
		 * @param WC_Order $subject  Order the email is about.
		 */
		if ( ! apply_filters( 'woo_kontor_sync_attach_invoices', true, $email_id, $subject ) ) {
			return $attachments;
		}

		$path = Storage::resolve( $invoice['file'] );

		// A file that has gone missing is skipped rather than failing the email:
		// the customer would rather have the order confirmation without it.
		if ( ! is_wp_error( $path ) ) {
			$attachments[] = $path;
		}

		return $attachments;
	}
}
