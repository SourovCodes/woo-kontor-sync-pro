<?php
/**
 * Tests for telling a cancelled invoice from the ones an order still owes.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WKSYNC_Customer_Invoice_Corrected;
use WooKontorSync\Admin\OrderActions;
use WooKontorSync\Emails\Emails;
use WooKontorSync\Frontend\Invoices;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Sync\InvoiceSync;
use WP_UnitTestCase;

/**
 * Covers what a customer is shown when Kontor cancels an invoice.
 *
 * Up to 0.30.0 this rested on an inference: every row read "Rechnung", there was no
 * status of any kind and no cancellation entity, so the plugin took the highest Belegnr
 * for an order to be the live one and called everything below it cancelled. That was
 * wrong, and expensively so — an order billed in parts owes both documents, and the
 * shop was telling those customers to disregard a bill they still had to pay. Kontor
 * now supplies an "invoice_status" per row, so these tests pin the field being obeyed
 * and, just as much, the cases where two invoices are both valid.
 */
class InvoiceCorrectionTest extends WP_UnitTestCase {

	/**
	 * Document id of the invoice that was replaced.
	 */
	const OLD_ID = '7349bc76-8446-431f-857d-82ef9c4e29cb';

	/**
	 * Document id of the invoice that replaced it.
	 */
	const NEW_ID = 'b7973619-0935-4911-9f81-1fcd8444a782';

	/**
	 * Build WC_Emails so WC_Email itself is declared.
	 *
	 * These classes extend a WooCommerce class that WooCommerce declares late, and the
	 * mailer is what pulls it in. Nothing in a bare test request has called it.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		WC()->mailer();
	}

	/**
	 * Remove the invoice directory a test filled.
	 *
	 * @return void
	 */
	public function tear_down() {
		$name = (string) get_option( Storage::OPTION_DIR, '' );

		if ( '' !== $name ) {
			$uploads = wp_upload_dir( null, false );

			if ( ! empty( $uploads['basedir'] ) ) {
				$this->remove_directory( trailingslashit( $uploads['basedir'] ) . $name );
			}
		}

		delete_option( Storage::OPTION_DIR );

		parent::tear_down();
	}

	/**
	 * Delete a directory and everything in it.
	 *
	 * @param string $path Absolute path.
	 * @return void
	 */
	private function remove_directory( $path ) {
		if ( ! is_dir( $path ) ) {
			return;
		}

		foreach ( (array) glob( trailingslashit( $path ) . '*' ) as $file ) {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}

		// Dotfiles are not matched by the glob above.
		foreach ( array( '.htaccess' ) as $hidden ) {
			if ( is_file( trailingslashit( $path ) . $hidden ) ) {
				wp_delete_file( trailingslashit( $path ) . $hidden );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Test cleanup of a directory this test created.
		rmdir( $path );
	}

	/**
	 * An order holding invoices, stored in the order given.
	 *
	 * @param array $invoices List of "id", "number", "date" and optional "status".
	 * @return WC_Order The saved order.
	 */
	private function order_with( array $invoices ) {
		$order = new WC_Order();
		$order->save();

		$stored = array();

		foreach ( $invoices as $invoice ) {
			$file = Storage::put( "%PDF-1.4\nsynthetic\n", $invoice['number'] );

			$this->assertNotWPError( $file );

			$entry = array(
				'id'     => $invoice['id'],
				'number' => $invoice['number'],
				'date'   => $invoice['date'],
				'file'   => $file,
			);

			// Absent rather than empty when the case under test is a legacy entry.
			if ( array_key_exists( 'status', $invoice ) ) {
				$entry['status'] = $invoice['status'];
			}

			$stored[] = $entry;
		}

		$order->update_meta_data( InvoiceSync::META_INVOICES, $stored );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * An order whose invoice was cancelled, stored newest first as the feed returns it.
	 *
	 * @return WC_Order The saved order.
	 */
	private function corrected_order() {
		return $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'canceled',
				),
			)
		);
	}

	/**
	 * An order billed twice, with nothing cancelled.
	 *
	 * The partial delivery: what shipped was billed, the rest was billed later, and
	 * both documents are owed.
	 *
	 * @return WC_Order The saved order.
	 */
	private function part_delivered_order() {
		return $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'invoiced',
				),
			)
		);
	}

	/**
	 * Kontor's status decides which invoice counts, and nothing else does.
	 *
	 * This is the load-bearing one. Up to 0.30.0 the plugin inferred it from Belegnr —
	 * highest wins, everything below it cancelled — because the listing carried no
	 * status at all. Kontor has since said outright that an order can owe several
	 * invoices and that the rule could not be applied, and supplied the field. A lower
	 * Belegnr that Kontor still calls valid is therefore valid, which is exactly what
	 * the old rule got wrong.
	 *
	 * @return void
	 */
	public function test_kontors_status_decides_which_invoice_is_valid() {
		$classified = InvoiceSync::classify( InvoiceSync::for_order( $this->corrected_order() ) );

		$this->assertSame( '141675', $classified[0]['number'] );
		$this->assertTrue( $classified[0]['current'] );
		$this->assertFalse( $classified[1]['current'] );

		// The same two documents with the verdict the other way round. Belegnr is
		// unchanged; only Kontor's answer moved, and the answer is what decides.
		$reversed = $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'canceled',
				),
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'invoiced',
				),
			)
		);

		$this->assertSame( self::OLD_ID, InvoiceSync::current_for_order( $reversed )['id'] );
	}

	/**
	 * Only an unmistakable "canceled" cancels an invoice.
	 *
	 * The two ways of being wrong are not equal. Telling a customer a valid invoice is
	 * void is a confident false statement about a bill they owe; listing two documents
	 * without a heading is what every order looked like before any of this existed. An
	 * entry stored before 0.31.0 carries no status at all and lands here.
	 *
	 * @return void
	 */
	public function test_an_unrecognised_or_absent_status_reads_as_valid() {
		$this->assertTrue( InvoiceSync::is_cancelled( array( 'status' => 'canceled' ) ) );
		$this->assertTrue( InvoiceSync::is_cancelled( array( 'status' => ' CANCELED ' ) ) );

		$this->assertFalse( InvoiceSync::is_cancelled( array( 'status' => 'invoiced' ) ) );
		$this->assertFalse( InvoiceSync::is_cancelled( array( 'status' => 'cancelled' ) ) );
		$this->assertFalse( InvoiceSync::is_cancelled( array( 'status' => '' ) ) );
		$this->assertFalse( InvoiceSync::is_cancelled( array( 'status' => null ) ) );
		$this->assertFalse( InvoiceSync::is_cancelled( array() ) );
	}

	/**
	 * The display order is still newest issued first.
	 *
	 * Belegnr no longer says which invoice is valid, but it is still Kontor's own issue
	 * sequence and still the only thing that survives the listing coming back newest
	 * first — storage follows the feed, so a replacement is appended before the document
	 * it replaces.
	 *
	 * @return void
	 */
	public function test_invoices_are_listed_newest_issued_first() {
		$oldest_first = $this->order_with(
			array(
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
			)
		);

		$classified = InvoiceSync::classify( InvoiceSync::for_order( $oldest_first ) );

		$this->assertSame( '141675', $classified[0]['number'] );
		$this->assertSame( '141638', $classified[1]['number'] );
	}

	/**
	 * A lone invoice is valid, and its order carries no correction.
	 *
	 * @return void
	 */
	public function test_a_single_invoice_is_valid_and_is_not_a_correction() {
		$order = $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
			)
		);

		$this->assertFalse( InvoiceSync::has_correction( $order ) );
		$this->assertSame( '141675', InvoiceSync::current_for_order( $order )['number'] );
		$this->assertSame( array(), InvoiceSync::cancelled_for_order( $order ) );
	}

	/**
	 * A cancelled invoice is reported as cancelled and the other one as valid.
	 *
	 * @return void
	 */
	public function test_a_cancelled_invoice_is_reported_as_cancelled() {
		$order = $this->corrected_order();

		$this->assertTrue( InvoiceSync::has_correction( $order ) );
		$this->assertSame( self::NEW_ID, InvoiceSync::current_for_order( $order )['id'] );

		$cancelled = InvoiceSync::cancelled_for_order( $order );

		$this->assertCount( 1, $cancelled );
		$this->assertSame( self::OLD_ID, $cancelled[0]['id'] );
	}

	/**
	 * Two invoices Kontor calls valid are both owed, and neither is a correction.
	 *
	 * The case that prompted the change. A partially delivered order is billed for what
	 * shipped and the rest is billed later, and the shop used to call the earlier one
	 * cancelled and tell the customer to disregard a bill they still owed.
	 *
	 * @return void
	 */
	public function test_two_valid_invoices_are_both_owed() {
		$order = $this->part_delivered_order();

		$this->assertFalse( InvoiceSync::has_correction( $order ) );
		$this->assertCount( 2, InvoiceSync::valid_for_order( $order ) );
		$this->assertSame( array(), InvoiceSync::cancelled_for_order( $order ) );
	}

	/**
	 * The order page names both documents and says which one counts.
	 *
	 * @return void
	 */
	public function test_the_order_page_labels_both_documents() {
		ob_start();
		( new Invoices() )->render_order_details( $this->corrected_order() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Valid invoice', $html );
		$this->assertStringContainsString( 'Cancelled invoice (no longer valid)', $html );
		$this->assertStringContainsString( '141675', $html );
		$this->assertStringContainsString( '141638', $html );

		// The valid one has to come first, or the labels are doing half the work.
		$this->assertLessThan(
			strpos( $html, 'Cancelled invoice' ),
			strpos( $html, 'Valid invoice' )
		);
	}

	/**
	 * An order with one invoice reads exactly as it always did.
	 *
	 * Calling that one "the valid invoice" would invite the reader to hunt for the
	 * other one.
	 *
	 * @return void
	 */
	public function test_an_uncorrected_order_gains_no_headings() {
		$order = $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
			)
		);

		ob_start();
		( new Invoices() )->render_order_details( $order );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Valid invoice', $html );
		$this->assertStringNotContainsString( 'Cancelled invoice', $html );
		$this->assertStringContainsString( '141675', $html );
	}

	/**
	 * Two invoices, nothing cancelled, and still no headings.
	 *
	 * The headings exist to separate the valid from the void. Putting them in front of
	 * two documents that are both owed would invite the reader to look for a
	 * distinction that is not there — and the shop has no way of knowing that the
	 * reader would then look for the cancelled one and not find it.
	 *
	 * @return void
	 */
	public function test_a_part_delivered_order_gains_no_headings() {
		ob_start();
		( new Invoices() )->render_order_details( $this->part_delivered_order() );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Valid invoice', $html );
		$this->assertStringNotContainsString( 'Cancelled invoice', $html );
		$this->assertStringContainsString( '141675', $html );
		$this->assertStringContainsString( '141638', $html );
	}

	/**
	 * The plain-text email carries the headings too.
	 *
	 * @return void
	 */
	public function test_the_plain_text_email_labels_both_documents() {
		ob_start();
		( new Invoices() )->render_email( $this->corrected_order(), false, true );
		$text = (string) ob_get_clean();

		$this->assertStringContainsString( 'Valid invoice', $text );
		$this->assertStringContainsString( 'Cancelled invoice (no longer valid)', $text );
	}

	/**
	 * A cancelled invoice is never attached to an email.
	 *
	 * Attaching the cancelled PDF beside the valid one would undo the whole point of
	 * separating them on the page: the reader opens the mail, finds two, and is
	 * guessing again.
	 *
	 * @return void
	 */
	public function test_a_cancelled_invoice_is_not_attached() {
		$order = $this->corrected_order();
		$email = new WKSYNC_Customer_Invoice_Corrected();

		$attachments = ( new Invoices() )->attach( array(), $email->id, $order, $email );

		$this->assertCount( 1, $attachments );

		$current = InvoiceSync::current_for_order( $order );

		$this->assertSame( Storage::resolve( $current['file'] ), $attachments[0] );
	}

	/**
	 * Every invoice an order still owes is attached.
	 *
	 * An order billed in parts owes both, and leaving the earlier one to the links
	 * would hide half of what is due from anybody whose mail client shows attachments
	 * and not much else.
	 *
	 * @return void
	 */
	public function test_every_valid_invoice_is_attached() {
		$order = $this->part_delivered_order();
		$email = new WKSYNC_Customer_Invoice_Corrected();

		$attachments = ( new Invoices() )->attach( array(), $email->id, $order, $email );

		$this->assertCount( 2, $attachments );

		foreach ( InvoiceSync::valid_for_order( $order ) as $invoice ) {
			$this->assertContains( Storage::resolve( $invoice['file'] ), $attachments );
		}
	}

	/**
	 * The order screen offers the correction notice, not the arrival mail.
	 *
	 * @return void
	 */
	public function test_the_order_action_offers_the_correction_notice() {
		$actions = ( new OrderActions() )->add_actions( array(), $this->corrected_order() );

		$this->assertArrayHasKey( OrderActions::SEND_INVOICE, $actions );
		$this->assertStringContainsString( 'corrected', $actions[ OrderActions::SEND_INVOICE ] );
	}

	/**
	 * A part-delivered order is offered the arrival mail, not the correction notice.
	 *
	 * Nothing on it has been cancelled, so a mail telling the customer to disregard an
	 * invoice would be naming one that does not exist.
	 *
	 * @return void
	 */
	public function test_a_part_delivered_order_is_offered_the_arrival_mail() {
		$actions = ( new OrderActions() )->add_actions( array(), $this->part_delivered_order() );

		$this->assertArrayHasKey( OrderActions::SEND_INVOICE, $actions );
		$this->assertStringNotContainsString( 'corrected', $actions[ OrderActions::SEND_INVOICE ] );
	}

	/**
	 * An order whose only invoice is cancelled is offered no invoice mail at all.
	 *
	 * Both mails point at an invoice the customer is meant to use, and there is none.
	 * Kontor cancels a document and issues its replacement as separate events, so between
	 * the two the listing carries only the cancellation — and the day invoice_status
	 * arrived the whole feed was briefly in that state, 18 orders of 30. An entry that
	 * silently achieves nothing is worse than an absent one.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_valid_is_offered_no_invoice_mail() {
		$order = $this->order_with(
			array(
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'canceled',
				),
			)
		);

		$actions = ( new OrderActions() )->add_actions( array(), $order );

		$this->assertArrayNotHasKey( OrderActions::SEND_INVOICE, $actions );
	}

	/**
	 * An order whose only invoice is cancelled still says so, and still offers it.
	 *
	 * Somebody who has already paid against it needs the document, and the shop needs to
	 * see why nothing is owed. Hiding it would solve a labelling problem by taking the
	 * record away.
	 *
	 * @return void
	 */
	public function test_an_order_with_nothing_valid_still_lists_the_cancelled_invoice() {
		$order = $this->order_with(
			array(
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'canceled',
				),
			)
		);

		ob_start();
		( new Invoices() )->render_order_details( $order );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Cancelled invoice (no longer valid)', $html );
		$this->assertStringContainsString( '141638', $html );
		$this->assertStringNotContainsString( 'Valid invoice', $html );

		$email = new WKSYNC_Customer_Invoice_Corrected();

		$this->assertSame( array(), ( new Invoices() )->attach( array(), $email->id, $order, $email ) );
	}

	/**
	 * The correction email is a type of its own, and is off until somebody asks.
	 *
	 * Kontor's listing has no incremental filter, so the first run after this version
	 * lands reads the status of every invoice the shop has ever issued. Enabled by
	 * default, that run would mail every correction among them at once.
	 *
	 * @return void
	 */
	public function test_the_correction_email_is_registered_and_disabled_by_default() {
		$emails = ( new Emails() )->add_classes( array() );

		$this->assertArrayHasKey( Emails::INVOICE_CORRECTED_KEY, $emails );

		$email = $emails[ Emails::INVOICE_CORRECTED_KEY ];

		$this->assertInstanceOf( WKSYNC_Customer_Invoice_Corrected::class, $email );
		$this->assertFalse( $email->is_enabled() );
		$this->assertTrue( $email->is_customer_email() );
	}

	/**
	 * WooCommerce is told to treat the correction hook as a transactional email.
	 *
	 * Skipping this fails silently and completely: the classes are only ever built by
	 * WC_Emails::init(), and inside the Action Scheduler job that downloads an invoice
	 * nothing has called WC()->mailer(), so the hook would fire into no listeners.
	 *
	 * @return void
	 */
	public function test_the_correction_hook_is_a_transactional_email_action() {
		$this->assertContains( Emails::INVOICE_CORRECTED, ( new Emails() )->add_actions( array() ) );
	}

	/**
	 * The correction email says which invoice counts, and names no cause.
	 *
	 * It shipped describing the VAT-rate fault that prompted it, which this plugin had
	 * itself caused and has since fixed. Kontor cancels an invoice whenever it has
	 * reason to, and a mail confidently blaming the wrong thing is worse than one that
	 * says only what is certain.
	 *
	 * @return void
	 */
	public function test_the_correction_email_states_which_invoice_counts() {
		$email = new WKSYNC_Customer_Invoice_Corrected();

		// Protected, and reachable without setAccessible() since PHP 8.1 - which the
		// plugin's 8.2 floor guarantees, and which deprecates the call outright.
		$paragraphs = ( new \ReflectionMethod( $email, 'paragraphs' ) )->invoke( $email );

		$this->assertCount( 4, $paragraphs );
		$this->assertStringContainsString( 'cancelled and replaced', $paragraphs[0] );
		$this->assertStringContainsString( 'Only the current invoice is valid', $paragraphs[1] );
		$this->assertStringContainsString( 'outstanding difference', $paragraphs[2] );

		$this->assertStringNotContainsString( 'VAT', implode( ' ', $paragraphs ) );
	}

	/**
	 * The correction email's key survives WooCommerce putting it in a URL.
	 *
	 * The same assertion the other two carry, for the reason it exists: WooCommerce
	 * publishes the class name in the settings link and the preview query string, and
	 * derives the settings section from it twice with two different functions.
	 *
	 * @return void
	 */
	public function test_the_correction_email_key_survives_woocommerces_section_matching() {
		$key = Emails::INVOICE_CORRECTED_KEY;

		$this->assertSame( strtolower( $key ), sanitize_title( $key ) );
		$this->assertSame( $key, rawurlencode( $key ) );
	}
}
