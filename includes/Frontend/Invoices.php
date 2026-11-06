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
 * **Where an invoice has been cancelled, the two are never listed as equals.** Kontor
 * corrects an invoice by issuing a second one, and before this an order simply grew a
 * second download link identical in appearance to the one above it, so a customer
 * holding two invoices for one order had no way at all to tell which they owed. The
 * cancelled document is still offered — somebody who has already paid against it needs
 * it for their own records — but it is offered under a heading saying it no longer
 * counts, and it is not attached to anything.
 *
 * **An order can also owe more than one invoice**, because a partial delivery is billed
 * for what shipped and the rest is billed later. Those are listed plainly, with no
 * headings at all: the headings exist to separate the valid from the void, and putting
 * them in front of two documents that are both owed would invite the reader to hunt for
 * a distinction that is not there. InvoiceSync::is_cancelled() is what decides which is
 * which, from the status Kontor supplies, and is the one place that decides it.
 *
 * **An email carries only the invoices the customer has not been mailed yet.** The
 * order page lists every invoice, because that is where the records are kept. An email
 * is a request to pay, though, and until 0.34.0 each one carried every valid invoice on
 * the order. A part-delivered order re-sent its first invoice with each backorder mail,
 * and the customer could not tell which invoices were new. Each invoice now goes out
 * once, with the first customer email after it arrives; see InvoiceSync::was_emailed().
 */
class Invoices {

	/**
	 * Invoices attached to a mail that has been put together but not yet sent.
	 *
	 * Keyed on the email object, because WooCommerce reuses one instance per email type.
	 * Static so it does not matter which instance of this class answered the filter.
	 *
	 * @var array Map of spl_object_id() to an "order" id and a list of "ids".
	 */
	protected static $pending = array();

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
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 4 );
		add_filter( 'woocommerce_email_attachments', array( $this, 'attach' ), 10, 4 );
		add_action( 'woocommerce_email_sent', array( $this, 'record_sent' ), 10, 3 );
	}

	/**
	 * The invoices one particular email carries.
	 *
	 * The valid invoices the customer has not been emailed yet, plus the one that
	 * currently counts when the mail insists on it: a correction notice, or a resend a
	 * shop manager asked for. Never an invoice that has already gone out alongside a new
	 * one, and never a cancelled one.
	 *
	 * @param mixed $order Order the email is about.
	 * @param mixed $email The WC_Email being sent.
	 * @return array List of invoices, newest first.
	 */
	public static function for_email( $order, $email ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$invoices = InvoiceSync::unsent_for_order( $order );

		if ( $email instanceof \WKSYNC_Order_Email && $email->carries_current_invoice() ) {
			$current = InvoiceSync::current_for_order( $order );

			if ( null !== $current && ! in_array( (string) $current['id'], array_map( 'strval', wp_list_pluck( $invoices, 'id' ) ), true ) ) {
				// The newest valid invoice, so it belongs at the top of a newest-first list.
				array_unshift( $invoices, $current );
			}
		}

		return $invoices;
	}

	/**
	 * Group an order's invoices under the headings that say what each one is worth.
	 *
	 * An order with nothing cancelled gets one untitled group, however many invoices it
	 * has, which is what every order looked like before corrections were distinguished
	 * at all. Naming a group "the valid invoices" where none has been cancelled would
	 * invite the reader to wonder which other ones there are.
	 *
	 * @param mixed $order Order being displayed.
	 * @return array List of groups, each with a "title" and an "invoices" list.
	 */
	protected function groups( $order ) {
		return $this->group_up(
			InvoiceSync::valid_for_order( $order ),
			InvoiceSync::cancelled_for_order( $order )
		);
	}

	/**
	 * Group the invoices one email carries, by the same rules as groups().
	 *
	 * Only what for_email() picked is listed as owed. A correction notice also lists the
	 * cancelled documents, under their heading, because its text points the reader at
	 * them. Any other mail leaves them out: the customer has already been told.
	 *
	 * @param mixed    $order Order the email is about.
	 * @param WC_Email $email Email being rendered.
	 * @return array List of groups.
	 */
	protected function email_groups( $order, $email ) {
		$cancelled = $email instanceof \WKSYNC_Customer_Invoice_Corrected
			? InvoiceSync::cancelled_for_order( $order )
			: array();

		return $this->group_up( self::for_email( $order, $email ), $cancelled );
	}

	/**
	 * Put valid and cancelled invoices under the headings that tell them apart.
	 *
	 * @param array $valid     Invoices still owed, newest first.
	 * @param array $cancelled Cancelled invoices, newest first.
	 * @return array List of groups, each with a "title" and an "invoices" list.
	 */
	protected function group_up( array $valid, array $cancelled ) {
		if ( empty( $valid ) && empty( $cancelled ) ) {
			return array();
		}

		if ( empty( $cancelled ) ) {
			return array(
				array(
					'title'    => '',
					'invoices' => $valid,
				),
			);
		}

		$groups = array();

		if ( ! empty( $valid ) ) {
			$groups[] = array(
				'title'    => _n(
					'Valid invoice',
					'Valid invoices',
					count( $valid ),
					'woo-kontor-sync-pro'
				),
				'invoices' => $valid,
			);
		}

		$groups[] = array(
			'title'    => _n(
				'Cancelled invoice (no longer valid)',
				'Cancelled invoices (no longer valid)',
				count( $cancelled ),
				'woo-kontor-sync-pro'
			),
			'invoices' => $cancelled,
		);

		return $groups;
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
	 * Lists the same invoices the mail attaches, not every one on the order; see
	 * for_email(). A link to an invoice already sent is the same old invoice in the
	 * mail, just one click further away. Without an email object there is no way to
	 * tell which mail this is, so the full list is shown, as before.
	 *
	 * @param mixed $order         Order the email is about.
	 * @param bool  $sent_to_admin Whether this is an admin copy.
	 * @param bool  $plain_text    Whether the plain-text template is rendering.
	 * @param mixed $email         The WC_Email being rendered.
	 * @return void
	 */
	public function render_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {
		if ( $sent_to_admin ) {
			return;
		}

		$groups = $email instanceof WC_Email ? $this->email_groups( $order, $email ) : $this->groups( $order );

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
	 * Attach the order's not-yet-sent invoices to a customer email.
	 *
	 * What for_email() picks, and only that. An invoice goes out once, with the first
	 * customer email after it arrives. Before 0.34.0 every valid invoice went with every
	 * mail, so a part-delivered order re-sent its first invoice with each backorder, and
	 * customers paid invoices twice. A cancelled invoice is never attached. All of them
	 * stay downloadable from the customer's account.
	 *
	 * The invoices attached are recorded against the email object and only marked sent
	 * by record_sent(), once WooCommerce reports the mail went out.
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

		// Whatever an earlier mail of this type recorded has either been sent or failed.
		unset( self::$pending[ spl_object_id( $email ) ] );

		$invoices = self::for_email( $subject, $email );

		if ( empty( $invoices ) ) {
			return $attachments;
		}

		/**
		 * Filters whether an order's invoices are attached to a given email.
		 *
		 * By default the first customer email after an invoice arrives carries it,
		 * whichever email that is. A shop that would rather send invoices only with one
		 * specific email can narrow it here; an invoice held back is not marked sent,
		 * so the next email that is allowed to carry it does.
		 *
		 * Asked once for the email rather than once per document: the question is
		 * whether this mail carries invoices at all, not which of them it carries.
		 *
		 * @since 0.6.0
		 *
		 * @param bool     $attach   Whether to attach the invoices.
		 * @param string   $email_id Identifier of the email being sent.
		 * @param WC_Order $subject  Order the email is about.
		 */
		if ( ! apply_filters( 'woo_kontor_sync_attach_invoices', true, $email_id, $subject ) ) {
			return $attachments;
		}

		$sent = array();

		foreach ( $invoices as $invoice ) {
			$path = Storage::resolve( $invoice['file'] );

			// A file that has gone missing is skipped rather than failing the email:
			// the customer would rather have the order confirmation without it.
			if ( ! is_wp_error( $path ) ) {
				$attachments[] = $path;
				$sent[]        = (string) $invoice['id'];
			}
		}

		if ( ! empty( $sent ) ) {
			self::$pending[ spl_object_id( $email ) ] = array(
				'order' => (int) $subject->get_id(),
				'ids'   => $sent,
			);
		}

		return $attachments;
	}

	/**
	 * Mark the invoices a mail carried as sent, once the mail has actually gone.
	 *
	 * A mail wp_mail() refused leaves its invoices unsent, so the next customer email
	 * carries them instead of the customer never getting them at all.
	 *
	 * @param bool   $sent     Whether wp_mail() accepted the mail.
	 * @param string $email_id Identifier of the email.
	 * @param mixed  $email    The WC_Email that was sent.
	 * @return void
	 */
	public function record_sent( $sent, $email_id = '', $email = null ) {
		if ( ! is_object( $email ) ) {
			return;
		}

		$key = spl_object_id( $email );

		if ( ! isset( self::$pending[ $key ] ) ) {
			return;
		}

		$pending = self::$pending[ $key ];

		unset( self::$pending[ $key ] );

		if ( $sent ) {
			InvoiceSync::mark_emailed( $pending['order'], $pending['ids'] );
		}
	}
}
