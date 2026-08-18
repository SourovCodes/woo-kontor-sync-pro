<?php
/**
 * Tests for the two moments a customer is told something arrived from Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WKSYNC_Customer_Invoice;
use WKSYNC_Customer_Tracking;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Emails\Emails;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Orders\PartialStatus;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers when the arrival hooks fire, and — just as much — when they do not.
 */
class OrderNotificationsTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * A synthetic document GUID.
	 */
	const DOCUMENT_ID = 'd3ebdcea-e0c2-4e6c-9702-a04cc5fe0b92';

	/**
	 * What each hook was fired with, in order.
	 *
	 * @var array
	 */
	private $announced = array();

	/**
	 * Listen for both arrival hooks.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->announced = array(
			'invoice'  => array(),
			'tracking' => array(),
		);

		$this->rebuild_mailer();

		add_action(
			'woo_kontor_sync_invoice_downloaded',
			function ( $order_id, $document_id ) {
				$this->announced['invoice'][] = array( $order_id, $document_id );
			},
			10,
			2
		);

		add_action(
			'woo_kontor_sync_tracking_received',
			function ( $order_id, $tracking ) {
				$this->announced['tracking'][] = array( $order_id, $tracking );
			},
			10,
			2
		);
	}

	/**
	 * Build WC_Emails afresh so its hooks are in place.
	 *
	 * WC_Emails hooks the order-details block onto the actions the email bodies fire,
	 * and it does that from its constructor. It is a singleton, and WP_UnitTestCase
	 * rolls back every hook a test registered — so from the second test onwards the
	 * instance survives while its hooks do not, and a body renders with the order
	 * block missing. Resetting the singleton is what puts the hooks back. In
	 * production nothing does this: WC_Emails::send_transactional_email() instantiates
	 * the mailer before every send and nobody unhooks it afterwards.
	 *
	 * @return void
	 */
	private function rebuild_mailer() {
		$instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
		$instance->setValue( null, null );

		WC()->mailer();
	}

	/**
	 * Remove the listeners, the files and the options a test leaves behind.
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

		remove_all_actions( 'woo_kontor_sync_invoice_downloaded' );
		remove_all_actions( 'woo_kontor_sync_tracking_received' );
		remove_all_filters( 'pre_http_request' );
		delete_option( Storage::OPTION_DIR );
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

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
	 * Settings good enough for a sync to run.
	 *
	 * @return array Settings array.
	 */
	private function settings() {
		return array_merge(
			Settings::default_settings(),
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
				'shop_id'      => self::SHOP_ID,
			)
		);
	}

	/**
	 * An order that has already been pushed to Kontor.
	 *
	 * @param string $status Status to start the order in.
	 * @return WC_Order The saved order.
	 */
	private function make_order( $status = 'processing' ) {
		$order = new WC_Order();
		$order->set_status( $status );
		$order->save();

		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_order_number() );
		$order->save();

		return $order;
	}

	/**
	 * One normalised delivery row.
	 *
	 * @param array $overrides Fields to change.
	 * @return array Row as normalise() produces it.
	 */
	private function delivery_row( array $overrides = array() ) {
		return array_merge(
			array(
				'auftrnr'      => 'AW 214805',
				'status'       => 'in_progress',
				'provider'     => 'planzer',
				'tracking'     => '913368990400000188001',
				'tracking_url' => '',
			),
			$overrides
		);
	}

	/**
	 * Apply one delivery row to an order.
	 *
	 * @param WC_Order $order     Order to apply to.
	 * @param array    $overrides Fields to change on the row.
	 * @return array Counters from the run.
	 */
	private function deliver( $order, array $overrides = array() ) {
		return ( new DeliverySync( null, $this->settings() ) )->apply(
			array( (string) $order->get_order_number() => $this->delivery_row( $overrides ) )
		);
	}

	/**
	 * Answer the document endpoint with a PDF and everything else with an envelope.
	 *
	 * @return void
	 */
	private function fake_api() {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) {
				$document = str_contains( $url, 'getdocument' );

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'message' => '',
							'meta'    => array(
								'rowCount'   => 0,
								'totalCount' => 0,
							),
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API returns documents as base64; the fake has to as well.
							'data'    => $document ? base64_encode( "%PDF-1.4\nsynthetic\n" ) : array(),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Download one invoice onto an order.
	 *
	 * @param WC_Order $order Order the invoice belongs to.
	 * @param string   $id    Kontor document id.
	 * @return array Counters from the run.
	 */
	private function invoice( $order, $id = self::DOCUMENT_ID ) {
		return ( new InvoiceSync( null, $this->settings() ) )->apply(
			array(
				array(
					'id'           => $id,
					'number'       => '141542',
					'date'         => '2025-08-05',
					'order_number' => (string) $order->get_order_number(),
				),
			)
		);
	}

	/**
	 * Store a real PDF and record it on an order, without running the sync.
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
	 * An order that already carried its tracking number announces nothing.
	 *
	 * This is the upgrade case. Every order the delivery sync has ever touched already
	 * holds META_TRACKING, and the orders entity has no incremental filter, so the
	 * first run after this version lands sees all of them again. Reading the stored
	 * number as "already known" is what stops that run mailing the whole back
	 * catalogue about parcels delivered months ago.
	 *
	 * The consequence is that those orders are never announced at all, by design. The
	 * resend entry on the order screen is the only way to tell one of those customers.
	 *
	 * @return void
	 */
	public function test_an_order_that_already_had_its_tracking_announces_nothing() {
		$order = $this->make_order( 'completed' );

		// As an earlier version left it, before either email existed.
		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000188001' );
		$order->update_meta_data( DeliverySync::META_PROVIDER, 'planzer' );
		$order->update_meta_data( DeliverySync::META_STATUS, 'completed' );
		$order->update_meta_data( OrderSync::META_KONTOR_ORDER, 'AW 214805' );
		$order->save();

		$this->deliver( wc_get_order( $order->get_id() ), array( 'status' => 'completed' ) );

		$this->assertSame( array(), $this->announced['tracking'] );
	}

	/**
	 * An order that already held its invoice announces nothing.
	 *
	 * The other half of the upgrade case, and the sharper one: the invoices listing
	 * returns the shop's whole history on every run, so without the stored document id
	 * the first run would re-announce every invoice the shop has ever issued.
	 *
	 * @return void
	 */
	public function test_an_order_that_already_held_its_invoice_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order();

		// As an earlier version left it: downloaded and filed, never announced.
		$this->store_invoice( $order );

		$counts = $this->invoice( wc_get_order( $order->get_id() ) );

		$this->assertSame( 1, $counts['unchanged'] );
		$this->assertSame( array(), $this->announced['invoice'] );
	}

	/**
	 * An invoice the order did not hold announces itself once.
	 *
	 * @return void
	 */
	public function test_a_new_invoice_announces_itself() {
		$this->fake_api();

		$order  = $this->make_order();
		$counts = $this->invoice( $order );

		$this->assertSame( 1, $counts['downloaded'] );
		$this->assertCount( 1, $this->announced['invoice'] );
		$this->assertSame( $order->get_id(), $this->announced['invoice'][0][0] );
		$this->assertSame( self::DOCUMENT_ID, $this->announced['invoice'][0][1] );
	}

	/**
	 * The listing has no incremental filter, so the same invoice arrives every run.
	 *
	 * The stored entry is what stops it being announced every run with it.
	 *
	 * @return void
	 */
	public function test_an_invoice_already_held_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order );
		$counts = $this->invoice( wc_get_order( $order->get_id() ) );

		$this->assertSame( 1, $counts['unchanged'] );
		$this->assertCount( 1, $this->announced['invoice'] );
	}

	/**
	 * A second, genuinely different invoice announces itself too.
	 *
	 * An order can be invoiced more than once, and the second document is a new
	 * record rather than a correction of the first.
	 *
	 * @return void
	 */
	public function test_a_second_invoice_announces_itself() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order );
		$this->invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef' );

		$this->assertCount( 2, $this->announced['invoice'] );
	}

	/**
	 * A tracking number the order did not have announces itself.
	 *
	 * @return void
	 */
	public function test_a_new_tracking_number_announces_itself() {
		$order = $this->make_order();

		$this->deliver( $order );

		$this->assertCount( 1, $this->announced['tracking'] );
		$this->assertSame( $order->get_id(), $this->announced['tracking'][0][0] );
		$this->assertSame( '913368990400000188001', $this->announced['tracking'][0][1] );
	}

	/**
	 * Tracking arriving before the order completes announces at once.
	 *
	 * This is the ordinary shape of a Kontor run: the parcel is handed over while the
	 * order is still in progress, and Kontor reports it as completed later. Nothing in
	 * WooCommerce moves on the first run — in_progress is deliberately not acted on —
	 * so without this the customer would hear nothing until the second.
	 *
	 * The second run then completes the order and WooCommerce mails about that, so the
	 * customer gets two mails for two pieces of news. The guard only stops the same run
	 * doing both.
	 *
	 * @return void
	 */
	public function test_tracking_arriving_before_completion_announces_at_once() {
		$order = $this->make_order();

		$counts = $this->deliver( $order, array( 'status' => 'in_progress' ) );

		$this->assertSame( 1, $counts['updated'] );
		$this->assertSame( 0, $counts['completed'] );

		// Nothing moved the order, so nothing else would have told the customer.
		$this->assertSame( 'processing', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->announced['tracking'] );

		// The later run completes the order and does not announce the same number again.
		$this->deliver( wc_get_order( $order->get_id() ), array( 'status' => 'completed' ) );

		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->announced['tracking'] );
	}

	/**
	 * The same tracking number twice announces once.
	 *
	 * @return void
	 */
	public function test_the_same_tracking_number_twice_announces_once() {
		$order = $this->make_order();

		$this->deliver( $order );
		$this->deliver( wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, $this->announced['tracking'] );
	}

	/**
	 * Completing an order does not also announce its tracking.
	 *
	 * That transition sends WooCommerce's own completion mail, which already carries
	 * the tracking block — the meta was written before the status moved. Announcing
	 * here as well would tell the customer twice, seconds apart.
	 *
	 * @return void
	 */
	public function test_completing_an_order_does_not_also_announce_its_tracking() {
		$order = $this->make_order();

		$counts = $this->deliver( $order, array( 'status' => 'completed' ) );

		$this->assertSame( 1, $counts['completed'] );
		$this->assertSame( 'completed', wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( array(), $this->announced['tracking'] );
	}

	/**
	 * A partially completed order still announces its tracking.
	 *
	 * That status carries no email by design, which is exactly the gap this fills:
	 * part of the order has shipped and nothing else would say so.
	 *
	 * @return void
	 */
	public function test_a_partially_completed_order_still_announces_its_tracking() {
		$order = $this->make_order();

		$counts = $this->deliver( $order, array( 'status' => 'partially_completed' ) );

		$this->assertSame( 1, $counts['partial'] );
		$this->assertSame( PartialStatus::STATUS, wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->announced['tracking'] );
	}

	/**
	 * Tracking arriving on an already-completed order announces.
	 *
	 * Nothing moves the order, so nothing else would tell the customer at all.
	 *
	 * @return void
	 */
	public function test_tracking_on_an_already_completed_order_announces() {
		$order = $this->make_order( 'completed' );

		$this->deliver( $order, array( 'status' => 'completed' ) );

		$this->assertCount( 1, $this->announced['tracking'] );
	}

	/**
	 * A status that moves and nothing else announces nothing.
	 *
	 * The diff in apply_row() reports that something changed for any of four fields,
	 * so this is the guard on reading its answer as "a parcel was sent".
	 *
	 * @return void
	 */
	public function test_a_status_only_change_announces_nothing() {
		$order = $this->make_order();

		$this->deliver( $order );
		$this->deliver( wc_get_order( $order->get_id() ), array( 'status' => 'partially_completed' ) );

		$this->assertCount( 1, $this->announced['tracking'] );
	}

	/**
	 * Backfilling the Kontor order number announces nothing.
	 *
	 * @return void
	 */
	public function test_backfilling_the_auftrnr_announces_nothing() {
		$order = $this->make_order();

		$counts = $this->deliver( $order, array( 'tracking' => '' ) );

		$this->assertSame( 1, $counts['updated'] );
		$this->assertSame( 'AW 214805', wc_get_order( $order->get_id() )->get_meta( OrderSync::META_KONTOR_ORDER ) );
		$this->assertSame( array(), $this->announced['tracking'] );
	}

	/**
	 * Kontor sends provider and trackinginfo as null rather than omitting them.
	 *
	 * So a synced but unshipped order has the meta present and empty on every run, and
	 * none of those runs is a shipment.
	 *
	 * @return void
	 */
	public function test_an_empty_tracking_number_announces_nothing() {
		$order = $this->make_order();

		$this->deliver(
			$order,
			array(
				'provider' => '',
				'tracking' => '',
			)
		);

		$this->assertSame( array(), $this->announced['tracking'] );
	}

	/**
	 * Both emails are on WooCommerce's list.
	 *
	 * @return void
	 */
	public function test_both_emails_are_registered() {
		( new Emails() )->register();

		$emails = apply_filters( 'woocommerce_email_classes', array() );

		$this->assertInstanceOf( WKSYNC_Customer_Invoice::class, $emails[ Emails::INVOICE_KEY ] );
		$this->assertInstanceOf( WKSYNC_Customer_Tracking::class, $emails[ Emails::TRACKING_KEY ] );

		remove_all_filters( 'woocommerce_email_classes' );
	}

	/**
	 * The keys survive both functions WooCommerce identifies a section with.
	 *
	 * This is the guard on a bug that made both emails impossible to switch on, while
	 * the settings screen rendered perfectly. WooCommerce links to an email's own
	 * settings page with strtolower( $key ) and then matches the submitted section with
	 * sanitize_title( $key ). The two agree only while the key holds nothing
	 * sanitize_title() strips — and these were keyed by class name, which under a
	 * namespace carries backslashes. The link pointed one way, the save path looked the
	 * other, no email matched, and WC_Settings_Emails::save() quietly saved the general
	 * email settings instead. The Enable checkbox went nowhere.
	 *
	 * @return void
	 */
	public function test_the_email_keys_survive_woocommerces_section_matching() {
		foreach ( array( Emails::INVOICE_KEY, Emails::TRACKING_KEY ) as $key ) {
			$this->assertSame(
				strtolower( $key ),
				sanitize_title( $key ),
				'WooCommerce would link to one section and save another for ' . $key
			);

			// The preview URL carries the class name itself, and nginx answers 403 to a
			// backslash in a query string before WordPress is reached at all.
			$this->assertStringNotContainsString( '\\', $key );
			$this->assertSame( rawurlencode( $key ), $key );
		}
	}

	/**
	 * Both arrival hooks are registered as transactional email actions.
	 *
	 * Without this the mailer is never instantiated inside the Action Scheduler job
	 * that fires them, so the hooks would have no listeners and nothing would say so.
	 *
	 * @return void
	 */
	public function test_both_email_actions_are_registered() {
		( new Emails() )->register();

		$actions = apply_filters( 'woocommerce_email_actions', array() );

		$this->assertContains( Emails::INVOICE_ARRIVED, $actions );
		$this->assertContains( Emails::TRACKING_ARRIVED, $actions );

		remove_all_filters( 'woocommerce_email_actions' );
	}

	/**
	 * Neither email sends until a shop asks for it.
	 *
	 * Both listings return the shop's whole history on every run, so the first pass
	 * after this version lands sees every invoice and every untouched order at once.
	 * Enabled by default, an update would mail the entire back catalogue in one chain.
	 *
	 * @return void
	 */
	public function test_both_emails_are_disabled_by_default() {
		$this->assertFalse( ( new WKSYNC_Customer_Invoice() )->is_enabled() );
		$this->assertFalse( ( new WKSYNC_Customer_Tracking() )->is_enabled() );
	}

	/**
	 * Both are customer emails, which is what gets the invoice attached.
	 *
	 * @return void
	 */
	public function test_both_emails_are_customer_emails() {
		$this->assertTrue( ( new WKSYNC_Customer_Invoice() )->is_customer_email() );
		$this->assertTrue( ( new WKSYNC_Customer_Tracking() )->is_customer_email() );
	}

	/**
	 * The body carries the invoice links and the tracking block without asking.
	 *
	 * Both are rendered by woocommerce_email_after_order_table, which WooCommerce's own
	 * order-details template fires. Nothing in the email classes puts them there.
	 *
	 * @return void
	 */
	public function test_the_invoice_email_carries_the_invoice_links_and_the_tracking_block() {
		$order = $this->make_order();

		$this->store_invoice( $order );

		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000188001' );
		$order->save();

		$email = new WKSYNC_Customer_Invoice();
		$email->resend( $order );

		$markup = $email->get_content_html();

		$this->assertStringContainsString( 'action=wksync_invoice', $markup );
		$this->assertStringContainsString( '913368990400000188001', $markup );
	}

	/**
	 * The plain-text body is plain text.
	 *
	 * @return void
	 */
	public function test_the_plain_text_body_has_no_markup() {
		$order = $this->make_order();
		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000188001' );
		$order->save();

		$email = new WKSYNC_Customer_Tracking();
		$email->resend( $order );

		$text = $email->get_content_plain();

		$this->assertStringContainsString( '913368990400000188001', $text );
		$this->assertStringNotContainsString( '<p', $text );
		$this->assertStringNotContainsString( '<table', $text );
	}

	/**
	 * The invoice PDF is attached once, not twice.
	 *
	 * The email classes deliberately do not override get_attachments(): the base
	 * implementation is what fires the filter Frontend\Invoices already answers, and
	 * overriding it to append would be how the same file arrives twice.
	 *
	 * @return void
	 */
	public function test_the_invoice_pdf_is_attached_exactly_once() {
		$order = $this->make_order();

		$this->store_invoice( $order );

		$email = new WKSYNC_Customer_Invoice();
		$email->resend( $order );

		$this->assertCount( 1, $email->get_attachments() );
	}

	/**
	 * An email with no order to talk about renders nothing rather than warning.
	 *
	 * @return void
	 */
	public function test_an_email_with_no_order_renders_nothing() {
		$email = new WKSYNC_Customer_Tracking();

		$this->assertSame( '', $email->get_content_html() );
		$this->assertSame( '', $email->get_content_plain() );
		$this->assertFalse( $email->resend( null ) );
	}
}
