<?php
/**
 * Tests for telling a corrected invoice from the one it replaced.
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
 * Covers what a customer is shown when Kontor replaces an invoice.
 *
 * Kontor states nothing at all about supersession — every row it returns reads
 * "Rechnung", there is no cancellation entity, and the replaced document's own PDF
 * does not contain the word. The whole feature rests on the inference that the
 * highest Belegnr for an order is the live one, so these tests pin the inference
 * itself as much as the display built on it.
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
	 * @param array $invoices List of "id", "number" and "date" triples.
	 * @return WC_Order The saved order.
	 */
	private function order_with( array $invoices ) {
		$order = new WC_Order();
		$order->save();

		$stored = array();

		foreach ( $invoices as $invoice ) {
			$file = Storage::put( "%PDF-1.4\nsynthetic\n", $invoice['number'] );

			$this->assertNotWPError( $file );

			$stored[] = array(
				'id'     => $invoice['id'],
				'number' => $invoice['number'],
				'date'   => $invoice['date'],
				'file'   => $file,
			);
		}

		$order->update_meta_data( InvoiceSync::META_INVOICES, $stored );
		$order->save();

		return wc_get_order( $order->get_id() );
	}

	/**
	 * An order whose invoice was replaced, stored newest first as the feed returns it.
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
				),
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
				),
			)
		);
	}

	/**
	 * The highest Belegnr is the live invoice whichever order they were stored in.
	 *
	 * This is the load-bearing one. The listing comes back newest first, so the
	 * replacement is downloaded and appended *before* the document it replaces —
	 * anything reading position instead of Belegnr gets it exactly backwards on a
	 * first import, and tells every affected customer to use the cancelled invoice.
	 *
	 * @return void
	 */
	public function test_the_highest_belegnr_is_current_whatever_the_storage_order() {
		$newest_first = InvoiceSync::classify( InvoiceSync::for_order( $this->corrected_order() ) );

		$this->assertSame( '141675', $newest_first[0]['number'] );
		$this->assertTrue( $newest_first[0]['current'] );
		$this->assertFalse( $newest_first[1]['current'] );

		$oldest_first = $this->order_with(
			array(
				array(
					'id'     => self::OLD_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
				),
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
				),
			)
		);

		$classified = InvoiceSync::classify( InvoiceSync::for_order( $oldest_first ) );

		$this->assertSame( '141675', $classified[0]['number'] );
		$this->assertTrue( $classified[0]['current'] );
	}

	/**
	 * A lone invoice is current, and its order carries no correction.
	 *
	 * @return void
	 */
	public function test_a_single_invoice_is_current_and_is_not_a_correction() {
		$order = $this->order_with(
			array(
				array(
					'id'     => self::NEW_ID,
					'number' => '141675',
					'date'   => '2026-08-28',
				),
			)
		);

		$this->assertFalse( InvoiceSync::has_correction( $order ) );
		$this->assertSame( '141675', InvoiceSync::current_for_order( $order )['number'] );
		$this->assertSame( array(), InvoiceSync::superseded_for_order( $order ) );
	}

	/**
	 * The replaced invoice is reported as superseded and the newer one as current.
	 *
	 * @return void
	 */
	public function test_a_replaced_invoice_is_reported_as_superseded() {
		$order = $this->corrected_order();

		$this->assertTrue( InvoiceSync::has_correction( $order ) );
		$this->assertSame( self::NEW_ID, InvoiceSync::current_for_order( $order )['id'] );

		$superseded = InvoiceSync::superseded_for_order( $order );

		$this->assertCount( 1, $superseded );
		$this->assertSame( self::OLD_ID, $superseded[0]['id'] );
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

		$this->assertStringContainsString( 'Current invoice (valid)', $html );
		$this->assertStringContainsString( 'Cancelled invoice (no longer valid)', $html );
		$this->assertStringContainsString( '141675', $html );
		$this->assertStringContainsString( '141638', $html );

		// The valid one has to come first, or the labels are doing half the work.
		$this->assertLessThan(
			strpos( $html, 'Cancelled invoice' ),
			strpos( $html, 'Current invoice' )
		);
	}

	/**
	 * An order with one invoice reads exactly as it always did.
	 *
	 * Fifteen of the thirty-five orders measured on the live account have a single
	 * invoice. Calling that one "the valid invoice" would invite the reader to hunt
	 * for the other one.
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
				),
			)
		);

		ob_start();
		( new Invoices() )->render_order_details( $order );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'Current invoice', $html );
		$this->assertStringNotContainsString( 'Cancelled invoice', $html );
		$this->assertStringContainsString( '141675', $html );
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

		$this->assertStringContainsString( 'Current invoice (valid)', $text );
		$this->assertStringContainsString( 'Cancelled invoice (no longer valid)', $text );
	}

	/**
	 * Only the invoice that counts is attached to an email.
	 *
	 * Attaching the cancelled PDF beside it would undo the whole point of separating
	 * them on the page: the reader opens the mail, finds two, and is guessing again.
	 *
	 * @return void
	 */
	public function test_only_the_current_invoice_is_attached() {
		$order = $this->corrected_order();
		$email = new WKSYNC_Customer_Invoice_Corrected();

		$attachments = ( new Invoices() )->attach( array(), $email->id, $order, $email );

		$this->assertCount( 1, $attachments );

		$current = InvoiceSync::current_for_order( $order );

		$this->assertSame( Storage::resolve( $current['file'] ), $attachments[0] );
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
	 * The correction email is a type of its own, and is off until somebody asks.
	 *
	 * Kontor's listing has no incremental filter, so the first run after this version
	 * lands sees every correction the shop has ever issued. Enabled by default, that
	 * run would mail all of them at once.
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
	 * The correction email says the four things the shop asked it to say.
	 *
	 * @return void
	 */
	public function test_the_correction_email_states_which_invoice_counts() {
		$email = new WKSYNC_Customer_Invoice_Corrected();

		// Protected, and reachable without setAccessible() since PHP 8.1 - which the
		// plugin's 8.2 floor guarantees, and which deprecates the call outright.
		$paragraphs = ( new \ReflectionMethod( $email, 'paragraphs' ) )->invoke( $email );

		$this->assertCount( 5, $paragraphs );
		$this->assertStringContainsString( 'VAT rate', $paragraphs[0] );
		$this->assertStringContainsString( 'cancelled', $paragraphs[1] );
		$this->assertStringContainsString( 'only the newly issued invoice is valid', $paragraphs[2] );
		$this->assertStringContainsString( 'outstanding difference', $paragraphs[3] );
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
