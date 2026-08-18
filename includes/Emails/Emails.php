<?php
/**
 * Registration of the plugin's own customer emails.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Puts both emails in front of WooCommerce.
 *
 * Two filters, and the second one is the half that is easy to miss and impossible to
 * notice missing. woocommerce_email_classes is what lists the emails on
 * WooCommerce → Settings → Emails and gives them their own settings page. But the
 * classes are only ever constructed by WC_Emails::init(), which runs when something
 * calls WC()->mailer() — and inside the Action Scheduler job that downloads an
 * invoice, nothing has. A bare do_action() in the sync would fire into a hook with no
 * listeners at all, send nothing, and say nothing about it.
 *
 * woocommerce_email_actions is what closes that. WC_Emails::init_transactional_emails()
 * walks the filtered list on init and hooks every name to
 * WC_Emails::send_transactional_email, whose first act is to instantiate the mailer —
 * and therefore these classes — before re-firing the hook with "_notification"
 * appended, which is what each email actually listens to. It is the only arrangement
 * that works from a background job.
 *
 * Registered outside the admin check in Plugin::init(), twice over: the syncs that
 * fire these hooks run in Action Scheduler, and the classes have to exist in the admin
 * as well or the Emails screen cannot list them.
 */
class Emails {

	/**
	 * Fired when the invoice sync files a document the order did not hold.
	 */
	const INVOICE_ARRIVED = 'woo_kontor_sync_invoice_downloaded';

	/**
	 * Fired when the delivery sync learns a tracking number the order did not have.
	 */
	const TRACKING_ARRIVED = 'woo_kontor_sync_tracking_received';

	/**
	 * Register the two filters.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'woocommerce_email_classes', array( $this, 'add_classes' ) );
		add_filter( 'woocommerce_email_actions', array( $this, 'add_actions' ) );
	}

	/**
	 * Add both emails to WooCommerce's list.
	 *
	 * This is the first and only place either class is referenced. That matters: they
	 * extend WC_Email, which WooCommerce declares late, and PSR-4 loads a class when it
	 * is first referenced rather than when the file is imported. Constructing one any
	 * earlier than this callback is a fatal error, not a load-order inconvenience.
	 *
	 * @param array $emails Emails WooCommerce has collected.
	 * @return array Emails, with both of this plugin's added.
	 */
	public function add_classes( $emails ) {
		if ( ! is_array( $emails ) ) {
			$emails = array();
		}

		$emails[ CustomerInvoice::class ]  = new CustomerInvoice();
		$emails[ CustomerTracking::class ] = new CustomerTracking();

		return $emails;
	}

	/**
	 * Have WooCommerce treat both arrival hooks as transactional email triggers.
	 *
	 * @param array $actions Hook names WooCommerce will listen for.
	 * @return array Hook names, with both of this plugin's added.
	 */
	public function add_actions( $actions ) {
		if ( ! is_array( $actions ) ) {
			$actions = array();
		}

		$actions[] = self::INVOICE_ARRIVED;
		$actions[] = self::TRACKING_ARRIVED;

		return $actions;
	}

	/**
	 * One of this plugin's emails, ready to send.
	 *
	 * Reached through WC()->mailer() rather than constructed, so the object carries the
	 * shop's own settings for it — the subject and heading a shop manager may have
	 * rewritten, and the email type they chose.
	 *
	 * @param string $class_name Email class to fetch.
	 * @return OrderEmail|null The email, or null when WooCommerce does not hold it.
	 */
	public static function get( $class_name ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();

		return isset( $emails[ $class_name ] ) && $emails[ $class_name ] instanceof OrderEmail
			? $emails[ $class_name ]
			: null;
	}
}
