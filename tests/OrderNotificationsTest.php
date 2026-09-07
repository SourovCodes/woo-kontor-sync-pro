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
			'invoice'   => array(),
			'corrected' => array(),
			'tracking'  => array(),
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
			'woo_kontor_sync_invoice_corrected',
			function ( $order_id, $document_id ) {
				$this->announced['corrected'][] = array( $order_id, $document_id );
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
		remove_all_actions( 'woo_kontor_sync_invoice_corrected' );
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
	 * Run the invoice sync over one invoice for an order.
	 *
	 * @param WC_Order $order  Order the invoice belongs to.
	 * @param string   $id     Kontor document id.
	 * @param string   $number Belegnr.
	 * @param string   $date   Issue date.
	 * @param string   $status invoice_status Kontor reports.
	 * @return array Counters from the run.
	 */
	private function invoice( $order, $id = self::DOCUMENT_ID, $number = '141542', $date = '2025-08-05', $status = 'invoiced' ) {
		return $this->feed(
			$order,
			array(
				array(
					'id'     => $id,
					'number' => $number,
					'date'   => $date,
					'status' => $status,
				),
			)
		);
	}

	/**
	 * Run the invoice sync over everything Kontor lists for one order.
	 *
	 * The listing is grouped per order before it is chunked, so this is the shape a
	 * chunk really carries — and the shape that lets a cancellation and its replacement
	 * be settled together, which is what keeps the customer to one email.
	 *
	 * @param WC_Order $order Order the invoices belong to.
	 * @param array    $rows  Rows, each an "id", "number", "date" and "status".
	 * @return array Counters from the run.
	 */
	private function feed( $order, array $rows ) {
		$number = (string) $order->get_order_number();
		$group  = array();

		foreach ( $rows as $row ) {
			$group[] = array_merge( $row, array( 'order_number' => $number ) );
		}

		return ( new InvoiceSync( null, $this->settings() ) )->apply(
			array(
				array(
					'order_number' => $number,
					'rows'         => $group,
				),
			)
		);
	}

	/**
	 * Store a real PDF and record it on an order, without running the sync.
	 *
	 * Deliberately writes no status, because that is how every entry an earlier version
	 * left behind looks.
	 *
	 * @param WC_Order $order  Order to record it on.
	 * @param string   $id     Kontor document id.
	 * @param string   $number Belegnr.
	 * @return void
	 */
	private function store_invoice( $order, $id = self::DOCUMENT_ID, $number = '141542' ) {
		$file = Storage::put( "%PDF-1.4\nsynthetic\n", $number );

		$this->assertNotWPError( $file );

		$invoices = $order->get_meta( InvoiceSync::META_INVOICES );

		if ( ! is_array( $invoices ) ) {
			$invoices = array();
		}

		$invoices[] = array(
			'id'     => $id,
			'number' => $number,
			'date'   => '2025-08-05',
			'file'   => $file,
		);

		$order->update_meta_data( InvoiceSync::META_INVOICES, $invoices );
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

		$this->assertSame( 1, $counts['restated'] );
		$this->assertSame( array(), $this->announced['invoice'] );
	}

	/**
	 * Learning that a held invoice was cancelled long ago announces nothing.
	 *
	 * The upgrade case for the status itself, and the one that would be loudest if it
	 * were wrong. Entries written before 0.31.0 carry no status, and the listing has no
	 * incremental filter, so the first run after the upgrade reads Kontor's verdict on
	 * every invoice the shop has ever issued at once. Treating a status seen for the
	 * first time as a change would mail a correction notice to every customer whose
	 * invoice was corrected months ago.
	 *
	 * @return void
	 */
	public function test_a_status_learned_for_the_first_time_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order();

		// Two invoices as an earlier version left them, neither carrying a status.
		$this->store_invoice( $order );
		$this->store_invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef', '141675' );

		$counts = $this->feed(
			wc_get_order( $order->get_id() ),
			array(
				array(
					'id'     => 'f1c0c0de-0000-4000-8000-00000000beef',
					'number' => '141675',
					'date'   => '2025-08-09',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::DOCUMENT_ID,
					'number' => '141542',
					'date'   => '2025-08-05',
					'status' => 'canceled',
				),
			)
		);

		$this->assertSame( 2, $counts['restated'] );
		$this->assertSame( array(), $this->announced['invoice'] );
		$this->assertSame( array(), $this->announced['corrected'] );
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
	 * A cancellation and its replacement are one email, not two.
	 *
	 * This is what the grouping is for. Kontor states the two halves of a correction
	 * independently — one row saying the old document is void, another carrying the new
	 * one — and chunked row by row they would land in different actions and mail the
	 * customer twice: once that an invoice is ready, once that another is cancelled.
	 * Settled together, exactly one mail goes out, and it is the correction.
	 *
	 * @return void
	 */
	public function test_a_cancellation_and_its_replacement_are_one_email() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order );

		$this->announced['invoice'] = array();

		// The listing comes back newest first, which is the harder order to get right.
		$this->feed(
			wc_get_order( $order->get_id() ),
			array(
				array(
					'id'     => 'f1c0c0de-0000-4000-8000-00000000beef',
					'number' => '141675',
					'date'   => '2025-08-09',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::DOCUMENT_ID,
					'number' => '141542',
					'date'   => '2025-08-05',
					'status' => 'canceled',
				),
			)
		);

		$this->assertSame( array(), $this->announced['invoice'] );
		$this->assertCount( 1, $this->announced['corrected'] );
		$this->assertSame( 'f1c0c0de-0000-4000-8000-00000000beef', $this->announced['corrected'][0][1] );
	}

	/**
	 * A second invoice nothing has cancelled is an arrival, not a correction.
	 *
	 * The case that prompted the whole change. A partially delivered order is billed
	 * for what shipped and the rest is billed later, so two invoices on one order are
	 * as likely to be two bills as a correction — and the shop used to call the first
	 * one cancelled and tell the customer to disregard an invoice they still owed.
	 *
	 * @return void
	 */
	public function test_a_second_valid_invoice_announces_an_arrival() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order );

		$this->announced['invoice'] = array();

		$this->invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef', '141675', '2025-08-09' );

		$this->assertCount( 1, $this->announced['invoice'] );
		$this->assertSame( 'f1c0c0de-0000-4000-8000-00000000beef', $this->announced['invoice'][0][1] );
		$this->assertSame( array(), $this->announced['corrected'] );
	}

	/**
	 * A cancellation with nothing to replace it waits for the replacement.
	 *
	 * Kontor can cancel an invoice in one run and issue its replacement in a later one.
	 * Announcing the cancellation on its own would tell a customer their invoice is void
	 * and give them nothing to pay instead; announcing the replacement as an ordinary
	 * arrival would never mention that the document they hold is worthless. So the first
	 * run says nothing and the second says it was a correction.
	 *
	 * @return void
	 */
	public function test_a_cancellation_with_no_replacement_waits_for_one() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order );

		$this->announced['invoice'] = array();

		$this->invoice( wc_get_order( $order->get_id() ), self::DOCUMENT_ID, '141542', '2025-08-05', 'canceled' );

		$this->assertSame( array(), $this->announced['invoice'], 'A cancelled invoice is not an arrival.' );
		$this->assertSame( array(), $this->announced['corrected'], 'There is nothing to point the customer at yet.' );

		$this->invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef', '141675', '2025-08-09' );

		$this->assertSame( array(), $this->announced['invoice'] );
		$this->assertCount( 1, $this->announced['corrected'] );
		$this->assertSame( 'f1c0c0de-0000-4000-8000-00000000beef', $this->announced['corrected'][0][1] );
	}

	/**
	 * An order that already holds both invoices announces nothing on the next run.
	 *
	 * This is the upgrade path on a live shop, and the one worth being sure of: the
	 * production site was already holding the corrected invoice and the one it replaced
	 * on 18 orders before any of this shipped. Every run sees the shop's whole invoice
	 * history, so if a stored document could still announce itself, deploying would
	 * mail every one of those customers at once.
	 *
	 * @return void
	 */
	public function test_an_order_already_holding_both_invoices_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order();

		// As the live shop already has them: the original, cancelled, and its replacement.
		$this->invoice( $order, self::DOCUMENT_ID, '141638', '2026-08-24', 'canceled' );
		$this->invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef', '141675', '2026-08-28' );

		$this->announced['invoice']   = array();
		$this->announced['corrected'] = array();

		// The next run, seeing the whole history again exactly as the listing returns it.
		$counts = $this->feed(
			wc_get_order( $order->get_id() ),
			array(
				array(
					'id'     => 'f1c0c0de-0000-4000-8000-00000000beef',
					'number' => '141675',
					'date'   => '2026-08-28',
					'status' => 'invoiced',
				),
				array(
					'id'     => self::DOCUMENT_ID,
					'number' => '141638',
					'date'   => '2026-08-24',
					'status' => 'canceled',
				),
			)
		);

		$this->assertSame( 2, $counts['unchanged'] );
		$this->assertSame( 0, $counts['downloaded'] );
		$this->assertSame( array(), $this->announced['invoice'] );
		$this->assertSame( array(), $this->announced['corrected'] );
	}

	/**
	 * An invoice that arrives already cancelled announces nothing at all.
	 *
	 * Kontor lists cancelled documents alongside live ones, so a first import downloads
	 * both. Announcing that arrival would tell the customer about a void invoice, which
	 * is worse than silence — and announcing it as a correction would be worse still,
	 * since it is the thing that was corrected.
	 *
	 * @return void
	 */
	public function test_an_invoice_that_arrives_already_cancelled_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order();

		$this->invoice( $order, self::DOCUMENT_ID, '141675', '2025-08-09' );

		$before = count( $this->announced['invoice'] );

		$this->invoice( wc_get_order( $order->get_id() ), 'f1c0c0de-0000-4000-8000-00000000beef', '141638', '2025-08-05', 'canceled' );

		$this->assertCount( $before, $this->announced['invoice'] );
		$this->assertCount( 0, $this->announced['corrected'] );
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
	 * Kontor swinging between an order's two parcels announces once, not once a run.
	 *
	 * This is the regression test for the incident. Order 15339 on the live account
	 * shipped in two parcels, and the orders entity returned them alternately — one per
	 * hourly delivery run, for eleven days. The old code compared the reported number
	 * against the single stored one, so every swing read as a parcel that had just
	 * shipped: **33 tracking emails to one customer.** A list only ever grows, so the
	 * second parcel is announced when it first appears and never again.
	 *
	 * @return void
	 */
	public function test_kontor_alternating_between_two_parcels_announces_each_once() {
		$this->fake_api();

		$order = $this->make_order( 'processing' );
		$first = '913368990400000344001';
		$other = '913368990400000344002';

		$this->deliver( $order, array( 'tracking' => $first ) );

		$this->assertCount( 1, $this->announced['tracking'], 'The first parcel is news.' );

		$this->deliver( wc_get_order( $order->get_id() ), array( 'tracking' => $other ) );

		$this->assertCount( 2, $this->announced['tracking'], 'A second parcel is news too.' );

		// Eleven days of Kontor changing its mind about which one to report.
		for ( $run = 0; $run < 12; $run++ ) {
			$this->deliver( wc_get_order( $order->get_id() ), array( 'tracking' => 0 === $run % 2 ? $first : $other ) );
		}

		$this->assertCount( 2, $this->announced['tracking'], 'Neither parcel is announced twice.' );
	}

	/**
	 * Both parcels are kept, and the customer can reach either.
	 *
	 * The old code wrote one meta key, so a second number removed the first and the
	 * customer lost the link to a parcel they had already been told about.
	 *
	 * @return void
	 */
	public function test_a_second_parcel_never_removes_the_first() {
		$this->fake_api();

		$order = $this->make_order( 'processing' );

		$this->deliver( $order, array( 'tracking' => '913368990400000344001' ) );
		$this->deliver( wc_get_order( $order->get_id() ), array( 'tracking' => '913368990400000344002' ) );

		$parcels = DeliverySync::shipments( wc_get_order( $order->get_id() ) );

		$this->assertCount( 2, $parcels );
		$this->assertSame( '913368990400000344001', $parcels[0]['number'] );
		$this->assertSame( '913368990400000344002', $parcels[1]['number'] );
	}

	/**
	 * A parcel an earlier version already announced is adopted in silence.
	 *
	 * The upgrade case. Every order the delivery sync has ever touched carries the old
	 * single tracking meta and has been mailed about it once. Reading that number into
	 * the new list is bookkeeping, and announcing it would mail the whole back catalogue
	 * about parcels delivered weeks ago.
	 *
	 * @return void
	 */
	public function test_a_parcel_carried_over_from_an_earlier_version_announces_nothing() {
		$this->fake_api();

		$order = $this->make_order( 'processing' );

		// As an earlier version left it: one number, no list.
		$order->update_meta_data( DeliverySync::META_TRACKING, '913368990400000344001' );
		$order->update_meta_data( DeliverySync::META_PROVIDER, 'planzer' );
		$order->save();

		$this->deliver( wc_get_order( $order->get_id() ), array( 'tracking' => '913368990400000344002' ) );

		$this->assertSame( array(), $this->announced['tracking'], 'The migrating run says nothing.' );

		$parcels = DeliverySync::shipments( wc_get_order( $order->get_id() ) );

		$this->assertCount( 2, $parcels, 'Both parcels are kept even though neither was announced.' );

		// A genuinely new parcel after the migration announces normally.
		$this->deliver( wc_get_order( $order->get_id() ), array( 'tracking' => '913368990400000344003' ) );

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
