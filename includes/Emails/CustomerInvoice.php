<?php
/**
 * The mail telling a customer their invoice has arrived.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Sent when the invoice sync downloads a document the order did not hold.
 *
 * Without this the invoice reaches the customer only by accident. Kontor issues it
 * hours after the order, and the invoice job runs hourly at the very fastest, so the
 * order confirmation is long gone by the time the PDF is filed — and the plugin's
 * only other route to a customer is attaching it to whatever order email happens to
 * be sent next. Frequently there is no next one, and the invoice sits in a directory
 * nobody can browse.
 *
 * The PDF is attached without a line of code here: Frontend\Invoices answers
 * woocommerce_email_attachments for every customer email, and this is one.
 */
class CustomerInvoice extends OrderEmail {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'wksync_customer_invoice';
		$this->title       = __( 'Kontor invoice', 'woo-kontor-sync-pro' );
		$this->description = __( 'Sent to the customer when the invoice sync downloads an invoice Kontor has issued for their order. Switch this on only after the first invoice import has finished: Kontor lists the shop\'s whole invoice history on every run, so the first pass downloads all of it and would mail every customer in it.', 'woo-kontor-sync-pro' );

		parent::__construct();
	}

	/**
	 * The plugin hook this email answers.
	 *
	 * @return string Hook name.
	 */
	protected function arrival_hook() {
		return 'woo_kontor_sync_invoice_downloaded';
	}

	/**
	 * Default subject.
	 *
	 * @return string Subject line.
	 */
	public function get_default_subject() {
		return __( 'Your invoice for order {order_number}', 'woo-kontor-sync-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'Your invoice is ready', 'woo-kontor-sync-pro' );
	}

	/**
	 * The sentence saying what has happened.
	 *
	 * @return string Plain text.
	 */
	protected function intro() {
		return __( 'Your invoice is attached to this email, and you can download it again from the links below.', 'woo-kontor-sync-pro' );
	}
}
