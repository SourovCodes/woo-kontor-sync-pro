<?php
/**
 * Shared behaviour for the mails that report something arriving from Kontor.
 *
 * @package WooKontorSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * A customer email about one order, sent when a background job learns something.
 *
 * Both of this plugin's emails are the same shape — an order, a sentence saying what
 * has happened, and then WooCommerce's own order block — so the composition lives
 * here and the subclasses carry only what makes them different.
 *
 * The body is assembled from the same actions every core email template fires rather
 * than from template files of this plugin's own. That is what earns everything else:
 * woocommerce_email_order_details ends by firing woocommerce_email_after_order_table,
 * so Frontend\Tracking and Frontend\Invoices render the tracking rows and the invoice
 * links into these mails with nothing written here, in HTML and plain text alike.
 * Everything a theme would realistically override is a core template and stays
 * overridable; what is not overridable is five lines of scaffolding.
 *
 * Attachments are likewise inherited rather than written. WC_Email::get_attachments()
 * fires woocommerce_email_attachments, which Frontend\Invoices already answers for any
 * customer email — so overriding it here is not merely unnecessary, it is how the PDF
 * would end up attached twice.
 *
 *
 * **In the global namespace, and named the way core names its own emails.** That is
 * not a style choice. WooCommerce puts the class name straight into the email
 * preview URL — `?preview_woocommerce_mail=true&type=<class>` — and identifies the
 * email by an exact `get_class()` match with no filter anywhere in the path. A
 * namespaced name puts backslashes in that query string, which nginx refuses with a
 * 403 before WordPress is reached at all. The same character broke the settings
 * section, where `strtolower()` and `sanitize_title()` disagreed about it. The class
 * name is a public identifier here, so it has to survive being put in a URL.
 *
 * Both are disabled by default. What they change is what the shop sends to customers,
 * which is the strongest form of the rule that governs every other setting here — and
 * neither Kontor listing has an incremental filter, so the first run after this
 * version lands sees the shop's whole invoice history and every order the delivery
 * sync has not yet touched. Enabled by default, an update would mail the entire back
 * catalogue in one chain. Disabled, by the time anybody switches them on that first
 * run has recorded everything and there is nothing left to announce.
 */
abstract class WKSYNC_Order_Email extends WC_Email {

	/**
	 * Constructor.
	 *
	 * Subclasses set their id, title and description and then call this.
	 */
	public function __construct() {
		$this->customer_email = true;
		$this->email_group    = 'order-updates';
		$this->placeholders   = array(
			'{order_date}'   => '',
			'{order_number}' => '',
		);

		/*
		 * No template_html or template_plain: there are no template files behind these
		 * emails, and naming ones that do not exist would offer the shop manager a
		 * template editor for a file WooCommerce would then fail to find.
		 */

		add_action( $this->arrival_hook() . '_notification', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * Disable the email until somebody asks for it.
	 *
	 * WC_Email reads "enabled" through get_option(), which falls back to the field's
	 * default, so changing the default here is all it takes.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		parent::init_form_fields();

		$this->form_fields['enabled']['default'] = 'no';
	}

	/**
	 * The plugin hook this email is sent in answer to.
	 *
	 * @return string Hook name, without WooCommerce's "_notification" suffix.
	 */
	abstract protected function arrival_hook();

	/**
	 * The sentence saying what has happened.
	 *
	 * @return string Plain text, one sentence.
	 */
	abstract protected function intro();

	/**
	 * The body text, one entry per paragraph.
	 *
	 * Most of these mails say one thing and intro() is the whole of it. A correction
	 * notice is not one sentence — it has to say what went wrong, which document now
	 * counts, and what to do about a payment already made — so the body is a list here
	 * and a subclass with more than one paragraph overrides this instead of intro().
	 *
	 * @return array List of paragraphs, as plain text.
	 */
	protected function paragraphs() {
		return array( $this->intro() );
	}

	/**
	 * Send the email in answer to the sync.
	 *
	 * @param int   $order_id Order something arrived for.
	 * @param mixed $detail   The document id or tracking number, unused here.
	 * @return void
	 */
	public function trigger( $order_id, $detail = null ) {
		$this->setup_locale();

		if ( $this->prepare( wc_get_order( $order_id ) ) ) {
			$this->send_notification();
		}

		$this->restore_locale();
	}

	/**
	 * Send the email because a shop manager asked for it.
	 *
	 * Deliberately not through send_notification(), which refuses when the email type
	 * is switched off. A button that silently does nothing is worse than no button,
	 * and core bypasses the same check for the same reason on its own invoice email.
	 *
	 * @param mixed $order Order to send about.
	 * @return bool True when the mail was handed to WordPress.
	 */
	public function resend( $order ) {
		$this->setup_locale();

		$sent = false;

		if ( $this->prepare( $order ) && $this->get_recipient() ) {
			$sent = $this->send(
				$this->get_recipient(),
				$this->get_subject(),
				$this->get_content(),
				$this->get_headers(),
				$this->get_attachments()
			);
		}

		$this->restore_locale();

		return $sent;
	}

	/**
	 * Point the email at an order.
	 *
	 * @param mixed $order Value that should be an order.
	 * @return bool True when there is an order to send about.
	 */
	protected function prepare( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$this->object                         = $order;
		$this->recipient                      = $order->get_billing_email();
		$this->placeholders['{order_date}']   = wc_format_datetime( $order->get_date_created() );
		$this->placeholders['{order_number}'] = $order->get_order_number();

		return true;
	}

	/**
	 * The HTML body.
	 *
	 * @return string Rendered markup.
	 */
	public function get_content_html() {
		if ( ! $this->object instanceof WC_Order ) {
			return '';
		}

		ob_start();

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hooks, fired in the same order and with the same arguments as its own templates. Prefixing or documenting them here would describe somebody else's contract.
		do_action( 'woocommerce_email_header', $this->get_heading(), $this );

		printf( '<p>%s</p>', esc_html( $this->greeting() ) );

		foreach ( $this->paragraphs() as $paragraph ) {
			printf( '<p>%s</p>', esc_html( $paragraph ) );
		}

		do_action( 'woocommerce_email_order_details', $this->object, false, false, $this );
		do_action( 'woocommerce_email_order_meta', $this->object, false, false, $this );
		do_action( 'woocommerce_email_customer_details', $this->object, false, false, $this );

		$additional = $this->get_additional_content();

		if ( '' !== $additional ) {
			echo wp_kses_post( wpautop( wptexturize( $additional ) ) );
		}

		do_action( 'woocommerce_email_footer', $this );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment

		return (string) ob_get_clean();
	}

	/**
	 * The plain-text body.
	 *
	 * @return string Rendered text.
	 */
	public function get_content_plain() {
		if ( ! $this->object instanceof WC_Order ) {
			return '';
		}

		ob_start();

		echo esc_html( wp_strip_all_tags( $this->get_heading() ) ) . "\n\n";
		echo esc_html( $this->greeting() ) . "\n\n";

		foreach ( $this->paragraphs() as $paragraph ) {
			echo esc_html( $paragraph ) . "\n\n";
		}

		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment -- WooCommerce's own email hooks, fired in the same order and with the same arguments as its own templates. Prefixing or documenting them here would describe somebody else's contract.
		do_action( 'woocommerce_email_order_details', $this->object, false, true, $this );

		echo "\n----------------------------------------\n\n";

		do_action( 'woocommerce_email_order_meta', $this->object, false, true, $this );
		do_action( 'woocommerce_email_customer_details', $this->object, false, true, $this );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WooCommerce.Commenting.CommentHooks.MissingHookComment

		$additional = $this->get_additional_content();

		if ( '' !== $additional ) {
			echo "\n\n----------------------------------------\n\n";
			echo esc_html( wp_strip_all_tags( wptexturize( $additional ) ) ) . "\n";
		}

		return (string) ob_get_clean();
	}

	/**
	 * How the mail opens.
	 *
	 * @return string Plain text.
	 */
	protected function greeting() {
		$name = $this->object instanceof WC_Order ? trim( (string) $this->object->get_billing_first_name() ) : '';

		if ( '' === $name ) {
			return __( 'Hi,', 'woo-kontor-sync-pro' );
		}

		/* translators: %s: customer's first name. */
		return sprintf( __( 'Hi %s,', 'woo-kontor-sync-pro' ), $name );
	}
}
