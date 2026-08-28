<?php
/**
 * The mail telling a customer their invoice has been replaced by a corrected one.
 *
 * @package WooKontorSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sent when the invoice sync downloads a document that supersedes one already held.
 *
 * A correction is not the same message as an arrival and must not borrow its wording.
 * "Your invoice is ready" in front of a customer who already has an invoice for that
 * order tells them nothing about which of the two they owe — which is the entire
 * problem, since Kontor issues the replacement and says nothing whatever about the
 * document it replaces. So this is a separate email type rather than a variable
 * subject line on the other one: WooCommerce stores the subject and heading as
 * options a shop manager edits, and one class could only ever have one of each.
 *
 * Fired by woo_kontor_sync_invoice_corrected instead of the arrival hook, never as
 * well as it. Two mails about one document would be worse than either alone.
 *
 * Disabled by default, like every mail here, and for a sharper reason than usual: the
 * invoice listing has no incremental filter, so the first run after this version
 * lands sees the shop's whole history at once and every correction ever issued would
 * go out in a single chain. By the time anybody switches it on, that run has been and
 * gone and only genuinely new corrections remain to announce.
 */
class WKSYNC_Customer_Invoice_Corrected extends WKSYNC_Order_Email {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id          = 'wksync_customer_invoice_corrected';
		$this->title       = __( 'Kontor corrected invoice', 'woo-kontor-sync-pro' );
		$this->description = __( 'Sent to the customer when Kontor issues an invoice that replaces one already held for their order. Switch this on only after the first invoice import has finished: Kontor lists the shop\'s whole invoice history on every run, so the first pass sees every correction ever issued and would mail all of them at once.', 'woo-kontor-sync-pro' );

		parent::__construct();
	}

	/**
	 * The plugin hook this email answers.
	 *
	 * @return string Hook name.
	 */
	protected function arrival_hook() {
		return 'woo_kontor_sync_invoice_corrected';
	}

	/**
	 * Default subject.
	 *
	 * @return string Subject line.
	 */
	public function get_default_subject() {
		return __( 'Correction to your invoice - please use the current invoice', 'woo-kontor-sync-pro' );
	}

	/**
	 * Default heading.
	 *
	 * @return string Heading.
	 */
	public function get_default_heading() {
		return __( 'We have corrected your invoice', 'woo-kontor-sync-pro' );
	}

	/**
	 * The first paragraph, which is what the base class asks every mail for.
	 *
	 * @return string Plain text.
	 */
	protected function intro() {
		return __( 'Unfortunately, because of a technical fault, the invoice originally issued for your order used an incorrect VAT rate - and in a few cases two different rates.', 'woo-kontor-sync-pro' );
	}

	/**
	 * The whole body, which needs more than one paragraph.
	 *
	 * It says four things, in this order, because a customer reading it wants them in
	 * this order: what went wrong, which document now counts, what to do about money
	 * already paid, and that it will not happen again. WooCommerce's own order table
	 * follows, rendered by the base class.
	 *
	 * @return array List of paragraphs.
	 */
	protected function paragraphs() {
		return array(
			$this->intro(),
			__( 'We have therefore corrected the invoice. The previous invoice has been cancelled and a new, corrected invoice has been issued.', 'woo-kontor-sync-pro' ),
			__( 'Please note: only the newly issued invoice is valid. You can disregard the invoice issued before it.', 'woo-kontor-sync-pro' ),
			__( 'If you have already paid the original invoice and the correction leaves a difference, please settle only the outstanding difference.', 'woo-kontor-sync-pro' ),
			__( 'The technical fault has since been found and fixed. We apologise for the confusion and thank you for your understanding.', 'woo-kontor-sync-pro' ),
		);
	}
}
