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
	 * The key the invoice email is listed under.
	 */
	const INVOICE_KEY = 'WKSYNC_Customer_Invoice';

	/**
	 * The key the tracking email is listed under.
	 */
	const TRACKING_KEY = 'WKSYNC_Customer_Tracking';

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
	 * **The keys must not be class names**, which is the trap this walked into once.
	 * WooCommerce builds the link to an email's settings page with
	 * `strtolower( $email_key )` and then matches the saved section with
	 * `sanitize_title( $email_key )` — and the two agree only while the key has nothing
	 * in it that `sanitize_title()` strips. A namespaced class name has backslashes, so
	 * the link pointed at `wookontorsync\emails\customerinvoice` while the save path
	 * looked for `wookontorsyncemailscustomerinvoice`, never matched, and fell through
	 * to saving the general email settings instead. The screen rendered perfectly and
	 * the Enable checkbox simply would not stick. Core's own keys are class names only
	 * because `WC_Email_Customer_Invoice` survives both functions unchanged.
	 *
	 * @param array $emails Emails WooCommerce has collected.
	 * @return array Emails, with both of this plugin's added.
	 */
	public function add_classes( $emails ) {
		if ( ! is_array( $emails ) ) {
			$emails = array();
		}

		$emails[ self::INVOICE_KEY ]  = new CustomerInvoice();
		$emails[ self::TRACKING_KEY ] = new CustomerTracking();

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
	 * @param string $key One of INVOICE_KEY or TRACKING_KEY.
	 * @return OrderEmail|null The email, or null when WooCommerce does not hold it.
	 */
	public static function get( $key ) {
		if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();

		return isset( $emails[ $key ] ) && $emails[ $key ] instanceof OrderEmail
			? $emails[ $key ]
			: null;
	}
}
