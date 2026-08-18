<?php
/**
 * Tests for the Kontor entries in the order actions dropdown.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WooKontorSync\Admin\OrderActions;
use WooKontorSync\Emails\Emails;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;
use WP_UnitTestCase;

/**
 * Covers when each entry is offered, what it does, and what is deliberately absent.
 */
class OrderActionsTest extends WP_UnitTestCase {

	/**
	 * A synthetic document GUID.
	 */
	const DOCUMENT_ID = 'd3ebdcea-e0c2-4e6c-9702-a04cc5fe0b92';

	/**
	 * Emails WordPress was asked to send during a test.
	 *
	 * @var array
	 */
	private $sent = array();

	/**
	 * Start each test from a clean invoice directory and an empty outbox.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		/*
		 * Each test runs in a transaction that is rolled back, but the object cache is
		 * not, so wc_get_order() can hand back an order a previous test built — with
		 * invoice meta naming a directory that test has since deleted from disk.
		 */
		wp_cache_flush();
		delete_option( Storage::OPTION_DIR );

		$this->sent = array();

		( new Emails() )->register();

		add_filter(
			'pre_wp_mail',
			function ( $pre, $atts ) {
				$this->sent[] = $atts;

				return true;
			},
			10,
			2
		);
	}

	/**
	 * Remove the files, filters and options a test leaves behind.
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

		remove_all_filters( 'pre_wp_mail' );
		remove_all_filters( 'woocommerce_email_classes' );
		remove_all_filters( 'woocommerce_email_actions' );
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
	 * A saved order with a billing email to send to.
	 *
	 * @return WC_Order The saved order.
	 */
	private function make_order() {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_billing_email( 'kundin@example.test' );
		$order->set_billing_first_name( 'Erika' );
		$order->save();

		return $order;
	}

	/**
	 * Store a real PDF and record it on an order.
	 *
	 * @param WC_Order $order Order to record it on.
	 * @return void
	 */
	private function store_invoice( $order ) {
		$file = Storage::put( "%PDF-1.4\nsynthetic\n", '141542' );

		$this->assertNotWPError( $file );

		$order->update_meta_data(
			InvoiceSync::META_INVOICES,
			array(
				array(
					'id'     => self::DOCUMENT_ID,
					'number' => '141542',
					'date'   => '2025-08-05',
					'file'   => $file,
				),
			)
		);
		$order->save();
	}

	/**
	 * The entries offered for an order.
	 *
	 * @param WC_Order $order Order being edited.
	 * @return array Action key to label.
	 */
	private function offered( $order ) {
		( new OrderActions() )->register();

		$actions = apply_filters( 'woocommerce_order_actions', array(), $order );

		remove_all_filters( 'woocommerce_order_actions' );

		return $actions;
	}

	/**
	 * An order with nothing back from Kontor is offered neither entry.
	 *
	 * @return void
	 */
	public function test_neither_entry_is_offered_on_a_bare_order() {
		$offered = $this->offered( $this->make_order() );

		$this->assertArrayNotHasKey( OrderActions::SEND_INVOICE, $offered );
		$this->assertArrayNotHasKey( OrderActions::SEND_TRACKING, $offered );
	}

	/**
	 * The invoice entry appears once there is a PDF to attach.
	 *
	 * @return void
	 */
	public function test_the_invoice_resend_is_offered_only_when_an_invoice_is_held() {
		$order = $this->make_order();

		$this->assertArrayNotHasKey( OrderActions::SEND_INVOICE, $this->offered( $order ) );

		$this->store_invoice( $order );

		$this->assertArrayHasKey( OrderActions::SEND_INVOICE, $this->offered( $order ) );
	}

	/**
	 * An invoice whose file has gone is not something to offer sending.
	 *
	 * @return void
	 */
	public function test_the_invoice_resend_is_not_offered_when_the_file_has_gone() {
		$order = $this->make_order();
		$order->update_meta_data(
			InvoiceSync::META_INVOICES,
			array(
				array(
					'id'     => self::DOCUMENT_ID,
					'number' => '141542',
					'date'   => '2025-08-05',
					'file'   => 'wksync-invoices-nowhere/gone.pdf',
				),
			)
		);
		$order->save();

		$this->assertArrayNotHasKey( OrderActions::SEND_INVOICE, $this->offered( $order ) );
	}

	/**
	 * The tracking entry appears once there is a tracking number.
	 *
	 * @return void
	 */
	public function test_the_tracking_resend_is_offered_only_when_a_tracking_number_is_held() {
		$order = $this->make_order();

		// Kontor sends the field as null rather than omitting it, so present and empty
		// is the ordinary state of an order that has not shipped.
		$order->update_meta_data( DeliverySync::META_TRACKING, '' );
		$order->save();

		$this->assertArrayNotHasKey( OrderActions::SEND_TRACKING, $this->offered( $order ) );

		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000188001' );
		$order->save();

		$this->assertArrayHasKey( OrderActions::SEND_TRACKING, $this->offered( $order ) );
	}

	/**
	 * Nothing offers to fetch one order's documents from Kontor.
	 *
	 * The invoices and orders entities honour only filter.shopid, so such an entry
	 * would start the whole shop-wide import behind a label naming one order.
	 *
	 * @return void
	 */
	public function test_no_per_order_kontor_fetch_is_offered() {
		$order = $this->make_order();
		$this->store_invoice( $order );

		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000188001' );
		$order->save();

		$offered = $this->offered( $order );

		$this->assertSame(
			array( OrderActions::SEND_INVOICE, OrderActions::SEND_TRACKING ),
			array_keys( $offered )
		);
	}

	/**
	 * Pressing the entry sends the mail even with the email type switched off.
	 *
	 * A button that silently refuses because of a global toggle is worse than no
	 * button: nobody pressing it can tell the difference from a failure.
	 *
	 * @return void
	 */
	public function test_a_resend_sends_even_when_the_email_type_is_disabled() {
		$order = $this->make_order();
		$this->store_invoice( $order );

		$this->assertFalse( Emails::get( Emails::INVOICE_KEY )->is_enabled() );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		( new OrderActions() )->send_invoice( $order );

		$this->assertCount( 1, $this->sent );
		$this->assertSame( 'kundin@example.test', $this->sent[0]['to'] );
	}

	/**
	 * A resend leaves a note, because nothing else reports that it happened.
	 *
	 * @return void
	 */
	public function test_a_resend_leaves_an_order_note() {
		$order = $this->make_order();
		$this->store_invoice( $order );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		( new OrderActions() )->send_invoice( $order );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$texts = wp_list_pluck( $notes, 'content' );

		$this->assertNotEmpty( preg_grep( '/invoice email sent/i', $texts ) );
	}

	/**
	 * A user who may not edit the order changes nothing.
	 *
	 * @return void
	 */
	public function test_a_user_without_edit_shop_order_changes_nothing() {
		$order = $this->make_order();
		$this->store_invoice( $order );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		( new OrderActions() )->send_invoice( $order );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );

		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), preg_grep( '/invoice email/i', wp_list_pluck( $notes, 'content' ) ) );
	}

	/**
	 * Anything that is not an order is ignored.
	 *
	 * @return void
	 */
	public function test_a_non_order_argument_is_ignored() {
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		( new OrderActions() )->send_invoice( null );
		( new OrderActions() )->send_tracking( null );

		$this->assertSame( array(), $this->sent );
		$this->assertSame( array(), $this->offered( null ) );
	}
}
