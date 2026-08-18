<?php
/**
 * Tests for the Kontor meta box on the order edit screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WooKontorSync\Admin\OrderPanel;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;
use WooKontorSync\Sync\OrderSync;
use WP_UnitTestCase;

/**
 * Covers what the panel shows, what it refuses to show, and that it edits nothing.
 */
class OrderPanelTest extends WP_UnitTestCase {

	/**
	 * A synthetic document GUID.
	 */
	const DOCUMENT_ID = 'd3ebdcea-e0c2-4e6c-9702-a04cc5fe0b92';

	/**
	 * Remove the invoice directory and the meta boxes a test leaves behind.
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

		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core's own registry, emptied so one test's box cannot be seen by the next.
		$GLOBALS['wp_meta_boxes'] = array();

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
	 * A saved order carrying whatever meta a test needs.
	 *
	 * @param array $meta Meta key to value.
	 * @return WC_Order The saved order.
	 */
	private function make_order( array $meta = array() ) {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->save();

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();

		return $order;
	}

	/**
	 * Store a real PDF and return the invoice entry naming it.
	 *
	 * @param string $number Invoice number.
	 * @param string $date   Issue date, or an empty string.
	 * @return array Invoice entry as the sync records it.
	 */
	private function store_invoice( $number = '141542', $date = '2025-08-05' ) {
		$file = Storage::put( "%PDF-1.4\nsynthetic\n", $number );

		$this->assertNotWPError( $file );

		return array(
			'id'     => self::DOCUMENT_ID,
			'number' => $number,
			'date'   => $date,
			'file'   => $file,
		);
	}

	/**
	 * Render the panel for an order.
	 *
	 * The HPOS order screen passes the order itself to the callback rather than the
	 * WP_Post a post-type screen would, so the panel is called the same way here.
	 *
	 * @param mixed $order Value to render for.
	 * @return string Captured markup.
	 */
	private function panel( $order ) {
		ob_start();
		( new OrderPanel() )->render( $order );

		return (string) ob_get_clean();
	}

	/**
	 * The upload group reports what was sent and what Kontor called it.
	 *
	 * @return void
	 */
	public function test_the_panel_shows_what_was_sent_to_kontor() {
		$order = $this->make_order(
			array(
				OrderSync::META_PUSHED_AT    => time(),
				OrderSync::META_ORDER_NUMBER => '4711',
				OrderSync::META_KONTOR_ORDER => 'A-90210',
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'A-90210', $markup );
		$this->assertStringContainsString( '4711', $markup );
		$this->assertStringNotContainsString( 'Not sent yet', $markup );
	}

	/**
	 * An order nobody has pushed says so rather than showing a blank.
	 *
	 * "Nothing has come back from Kontor yet" and "this plugin has nothing to say" are
	 * different statements, and a panel that renders half of itself teaches people it
	 * is unreliable.
	 *
	 * @return void
	 */
	public function test_empty_meta_reads_as_not_sent_rather_than_blank() {
		$markup = $this->panel( $this->make_order() );

		$this->assertStringContainsString( 'Not sent yet', $markup );
		$this->assertStringContainsString( 'Not assigned yet', $markup );
		$this->assertStringContainsString( 'Nothing reported yet', $markup );
		$this->assertStringContainsString( 'Nothing shipped yet', $markup );
		$this->assertStringContainsString( 'None downloaded yet', $markup );
	}

	/**
	 * A sent order with no Kontor number yet is not reported as unsent.
	 *
	 * Kontor does not return the order number in its reply to an upload, so this is
	 * the ordinary state of an order between the push and the first delivery sync.
	 *
	 * @return void
	 */
	public function test_a_sent_order_awaiting_its_kontor_number_says_so() {
		$order = $this->make_order(
			array(
				OrderSync::META_PUSHED_AT    => time(),
				OrderSync::META_ORDER_NUMBER => '4711',
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'Not assigned yet', $markup );
		$this->assertStringNotContainsString( 'Not sent yet', $markup );
	}

	/**
	 * A tracking number Kontor sent a URL for is a link.
	 *
	 * @return void
	 */
	public function test_the_panel_links_the_tracking_number_when_kontor_sent_a_url() {
		$order = $this->make_order(
			array(
				DeliverySync::META_STATUS       => 'partially_completed',
				DeliverySync::META_PROVIDER     => 'DHL',
				DeliverySync::META_TRACKING     => 'JJD000390007123456789',
				DeliverySync::META_TRACKING_URL => 'https://example.test/track/JJD000390007123456789',
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'partially_completed', $markup );
		$this->assertStringContainsString( 'DHL', $markup );
		$this->assertStringContainsString(
			'<a href="https://example.test/track/JJD000390007123456789"',
			$markup
		);
	}

	/**
	 * Kontor's status is printed as its own word, not mapped onto a WooCommerce one.
	 *
	 * @return void
	 */
	public function test_kontors_status_is_shown_raw() {
		$order  = $this->make_order( array( DeliverySync::META_STATUS => 'in_progress' ) );
		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'in_progress', $markup );
		$this->assertStringNotContainsString( 'Processing', $markup );
	}

	/**
	 * A synced but unshipped order has the meta present and empty, and says so.
	 *
	 * @return void
	 */
	public function test_empty_tracking_meta_reads_as_nothing_shipped() {
		$order = $this->make_order(
			array(
				DeliverySync::META_STATUS   => 'in_progress',
				DeliverySync::META_PROVIDER => '',
				DeliverySync::META_TRACKING => '',
			)
		);

		$this->assertStringContainsString( 'Nothing shipped yet', $this->panel( $order ) );
	}

	/**
	 * Every invoice is listed with a link that reaches the download endpoint.
	 *
	 * @return void
	 */
	public function test_the_panel_lists_every_invoice_with_a_working_download_link() {
		$invoice = $this->store_invoice();
		$order   = $this->make_order( array( InvoiceSync::META_INVOICES => array( $invoice ) ) );

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'Invoice 141542', $markup );
		$this->assertStringContainsString( 'action=wksync_invoice', $markup );
		$this->assertStringContainsString( 'order=' . $order->get_id(), $markup );
		$this->assertStringContainsString( self::DOCUMENT_ID, $markup );
		$this->assertStringContainsString( 'Download PDF', $markup );
	}

	/**
	 * An invoice whose file has gone is not offered as a download that could only fail.
	 *
	 * @return void
	 */
	public function test_an_invoice_whose_file_has_gone_is_not_listed() {
		$order = $this->make_order(
			array(
				InvoiceSync::META_INVOICES => array(
					array(
						'id'     => self::DOCUMENT_ID,
						'number' => '141542',
						'date'   => '2025-08-05',
						'file'   => 'wksync-invoices-nowhere/gone.pdf',
					),
				),
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'None downloaded yet', $markup );
		$this->assertStringNotContainsString( 'Download PDF', $markup );
	}

	/**
	 * The reason an order never reached the ERP is shown as a notice.
	 *
	 * @return void
	 */
	public function test_a_push_error_is_shown_as_a_notice() {
		$order = $this->make_order( array( OrderSync::META_PUSH_ERROR => 'Dublette' ) );

		$markup = $this->panel( $order );

		$this->assertStringContainsString( 'notice notice-error', $markup );
		$this->assertStringContainsString( 'Dublette', $markup );
	}

	/**
	 * An order with nothing wrong carries no error notice.
	 *
	 * @return void
	 */
	public function test_an_order_with_no_push_error_shows_no_notice() {
		$this->assertStringNotContainsString( 'notice-error', $this->panel( $this->make_order() ) );
	}

	/**
	 * Everything Kontor sent is escaped on the way out.
	 *
	 * @return void
	 */
	public function test_kontor_values_are_escaped() {
		$order = $this->make_order(
			array(
				DeliverySync::META_PROVIDER  => '<script>alert(1)</script>',
				DeliverySync::META_TRACKING  => '<b>123</b>',
				OrderSync::META_KONTOR_ORDER => '<em>A-1</em>',
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringNotContainsString( '<script>', $markup );
		$this->assertStringContainsString( '&lt;script&gt;', $markup );
		$this->assertStringNotContainsString( '<b>123</b>', $markup );
	}

	/**
	 * The panel offers nothing to edit, and has nothing to save.
	 *
	 * Every value on it is rewritten by a background job, so an editable field would
	 * accept a change that quietly disappeared at the next run.
	 *
	 * @return void
	 */
	public function test_the_panel_offers_nothing_to_edit() {
		$invoice = $this->store_invoice();
		$order   = $this->make_order(
			array(
				InvoiceSync::META_INVOICES  => array( $invoice ),
				DeliverySync::META_TRACKING => '123456',
			)
		);

		$markup = $this->panel( $order );

		$this->assertStringNotContainsString( '<input', $markup );
		$this->assertStringNotContainsString( '<form', $markup );
		$this->assertStringNotContainsString( 'name=', $markup );
		$this->assertStringNotContainsString( '_wpnonce', $markup );
		$this->assertFalse( method_exists( OrderPanel::class, 'save' ) );
	}

	/**
	 * Anything that is not an order renders nothing at all.
	 *
	 * @return void
	 */
	public function test_a_non_order_argument_is_ignored() {
		$this->assertSame( '', $this->panel( null ) );
		$this->assertSame( '', $this->panel( new \stdClass() ) );
	}

	/**
	 * The box lands in the side column of the HPOS order screen.
	 *
	 * @return void
	 */
	public function test_the_meta_box_registers_on_the_hpos_order_screen() {
		( new OrderPanel() )->register();

		$screen = wc_get_page_screen_id( 'shop-order' );

		do_action( 'add_meta_boxes', $screen, null );

		$this->assertArrayHasKey( $screen, $GLOBALS['wp_meta_boxes'] );
		$this->assertArrayHasKey(
			OrderPanel::BOX_ID,
			$GLOBALS['wp_meta_boxes'][ $screen ]['side']['default']
		);

		remove_all_actions( 'add_meta_boxes' );
	}
}
