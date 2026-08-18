<?php
/**
 * Tests for the invoice download job and everything that serves its files.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Email_Customer_Completed_Order;
use WC_Email_New_Order;
use WC_Order;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WooKontorSync\Frontend\Invoices;
use WooKontorSync\Invoices\Download;
use WooKontorSync\Invoices\Storage;
use WooKontorSync\Sync\InvoiceSync;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the two-step download, where the files land, and who may read them.
 */
class InvoiceSyncTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * A synthetic document GUID.
	 */
	const DOCUMENT_ID = 'd3ebdcea-e0c2-4e6c-9702-a04cc5fe0b92';

	/**
	 * Requests the fake API has seen, in order.
	 *
	 * @var array
	 */
	private $captured = array();

	/**
	 * Remove the files and options a test leaves behind.
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

		remove_all_filters( 'pre_http_request' );
		remove_all_filters( 'woo_kontor_sync_attach_invoices' );
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
	 * Fully configured settings, including a shop.
	 *
	 * @return array Settings array.
	 */
	private function settings() {
		return array(
			'api_base_url' => 'https://erp.example.test/api/v1/kontor',
			'api_key'      => 'test-key-123',
			'shoptype'     => 'B2C',
			'shop_id'      => self::SHOP_ID,
			'shop_name'    => 'Edu-Shop',
		);
	}

	/**
	 * The bytes of a small but genuine PDF.
	 *
	 * @return string Raw PDF.
	 */
	private function pdf() {
		return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
	}

	/**
	 * An invoice row in the shape the API returns.
	 *
	 * @param string $order_number Order number to file the invoice against.
	 * @param array  $overrides    Fields to replace.
	 * @return array Invoice row.
	 */
	private function invoice_row( $order_number, array $overrides = array() ) {
		return array_merge(
			array(
				'id'          => self::DOCUMENT_ID,
				'Belegname'   => 'Rechnung',
				'Belegnr'     => 141542,
				'Datum'       => '2026-08-04T00:00:00',
				'Auftrnr'     => 'AW 214841',
				'ordernumber' => (string) $order_number,
			),
			$overrides
		);
	}

	/**
	 * Answer both endpoints with canned replies, recording every request.
	 *
	 * @param array       $rows     Rows for the invoices listing.
	 * @param string|null $document Raw bytes getdocument should return, or null to fail it.
	 * @return void
	 */
	private function fake_api( array $rows, $document = null ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $rows, $document ) {
				$this->captured[] = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);

				if ( str_contains( $url, 'getdocument' ) ) {
					$envelope = null === $document
						? array(
							'success'   => false,
							'message'   => 'Dokument nicht gefunden',
							'errorCode' => 'ERR-404-NOT-FOUND',
						)
						: array(
							'success' => true,
							// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Reproducing the API's own encoding.
							'data'    => base64_encode( $document ),
						);
				} else {
					$envelope = array(
						'success' => true,
						'message' => 'Search completed successfully',
						'meta'    => array(
							'rowCount'   => count( $rows ),
							'totalCount' => count( $rows ),
						),
						'data'    => $rows,
					);
				}

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $envelope ),
					'response' => array(
						'code'    => null === $document && str_contains( $url, 'getdocument' ) ? 404 : 200,
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
	 * An order that has already been pushed to Kontor.
	 *
	 * @param int $customer_id Customer to own the order.
	 * @return WC_Order The saved order.
	 */
	private function make_order( $customer_id = 0 ) {
		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_customer_id( $customer_id );
		$order->save();

		$order->update_meta_data( OrderSync::META_ORDER_NUMBER, (string) $order->get_id() );
		$order->save();

		return $order;
	}

	/**
	 * Capture what a renderer echoes.
	 *
	 * @param callable $renderer Callable that echoes markup.
	 * @return string Captured output.
	 */
	private function capture( callable $renderer ) {
		ob_start();
		$renderer();

		return (string) ob_get_clean();
	}

	/**
	 * The document endpoint is resolved beside the search base, not under it.
	 *
	 * The api_base_url setting ends in Kontor's own segment and getdocument is its
	 * sibling.
	 * Appending would produce /api/v1/kontor/files/dms/getdocument, which does not
	 * exist.
	 *
	 * @return void
	 */
	public function test_the_document_endpoint_is_a_sibling_of_the_search_base() {
		$this->fake_api( array(), $this->pdf() );

		( new Client( $this->settings() ) )->fetch_document( self::DOCUMENT_ID );

		$this->assertSame( 'https://erp.example.test/api/v1/files/dms/getdocument', $this->captured[0]['url'] );
		$this->assertSame( array( 'id' => self::DOCUMENT_ID ), $this->captured[0]['body'] );
	}

	/**
	 * A document's base64 payload survives the envelope.
	 *
	 * The search endpoints return "data" as a list of rows and the client drops
	 * anything that is not an array. Reusing that here would hand back an empty
	 * result with success still true — a download that silently produced no file.
	 *
	 * @return void
	 */
	public function test_a_document_payload_is_not_discarded_as_a_non_row() {
		$this->fake_api( array(), $this->pdf() );

		$result = ( new Client( $this->settings() ) )->fetch_document( self::DOCUMENT_ID );

		$this->assertIsString( $result['data'] );
		$this->assertNotSame( '', $result['data'] );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Asserting the payload round-trips.
		$this->assertSame( $this->pdf(), base64_decode( $result['data'], true ) );
	}

	/**
	 * The listing asks the invoices entity for one shop.
	 *
	 * @return void
	 */
	public function test_the_listing_is_filtered_on_the_shop() {
		$this->fake_api( array() );

		( new Client( $this->settings() ) )->fetch_invoices( self::SHOP_ID );

		$this->assertSame( 'invoices', $this->captured[0]['body']['entity'] );
		$this->assertSame( self::SHOP_ID, $this->captured[0]['body']['filter']['shopid'] );
	}

	/**
	 * A new invoice is downloaded, stored and recorded on its order.
	 *
	 * @return void
	 */
	public function test_a_new_invoice_is_downloaded_and_filed_against_its_order() {
		$order = $this->make_order();

		$this->fake_api( array(), $this->pdf() );

		$sync   = new InvoiceSync( null, $this->settings() );
		$counts = $sync->apply( $this->normalised( $order ) );

		$this->assertSame( 1, $counts['downloaded'] );

		$invoices = InvoiceSync::for_order( wc_get_order( $order->get_id() ) );

		$this->assertCount( 1, $invoices );
		$this->assertSame( self::DOCUMENT_ID, $invoices[0]['id'] );
		$this->assertSame( '141542', $invoices[0]['number'] );
		$this->assertSame( '2026-08-04', $invoices[0]['date'] );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file this test just wrote.
		$this->assertSame( $this->pdf(), file_get_contents( Storage::resolve( $invoices[0]['file'] ) ) );
	}

	/**
	 * An invoice already held is not fetched a second time.
	 *
	 * The listing has no incremental filter, so every run sees the whole history.
	 * Without this the job would re-download and re-file every invoice each time.
	 *
	 * @return void
	 */
	public function test_an_invoice_already_held_is_not_downloaded_again() {
		$order = $this->make_order();

		$this->fake_api( array(), $this->pdf() );

		$sync = new InvoiceSync( null, $this->settings() );
		$sync->apply( $this->normalised( $order ) );

		$requests = count( $this->captured );
		$counts   = $sync->apply( $this->normalised( $order ) );

		$this->assertSame( 1, $counts['unchanged'] );
		$this->assertSame( 0, $counts['downloaded'] );
		$this->assertCount( $requests, $this->captured, 'The second pass must not call getdocument again.' );
		$this->assertCount( 1, InvoiceSync::for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * An invoice for an order this shop never pushed is counted, not downloaded.
	 *
	 * @return void
	 */
	public function test_an_invoice_with_no_matching_order_is_skipped() {
		$this->fake_api( array(), $this->pdf() );

		$sync   = new InvoiceSync( null, $this->settings() );
		$counts = $sync->apply(
			array(
				array(
					'id'           => self::DOCUMENT_ID,
					'number'       => '141542',
					'date'         => '2026-08-04',
					'order_number' => '99999999',
				),
			)
		);

		$this->assertSame( 1, $counts['missing'] );
		$this->assertSame( 0, $counts['downloaded'] );
		$this->assertEmpty( $this->captured, 'A row with no order must not cost a download.' );
	}

	/**
	 * Something that decodes but is not a PDF is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_a_document_that_is_not_a_pdf_is_refused() {
		$order = $this->make_order();

		$this->fake_api( array(), '<html><body>Session expired</body></html>' );

		$sync   = new InvoiceSync( null, $this->settings() );
		$counts = $sync->apply( $this->normalised( $order ) );

		$this->assertSame( 1, $counts['failed'] );
		$this->assertSame( array(), InvoiceSync::for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A failed download leaves the order untouched so the next run retries it.
	 *
	 * @return void
	 */
	public function test_a_failed_download_is_retried_on_the_next_run() {
		$order = $this->make_order();

		$this->fake_api( array(), null );

		$sync = new InvoiceSync( null, $this->settings() );

		$this->assertSame( 1, $sync->apply( $this->normalised( $order ) )['failed'] );

		remove_all_filters( 'pre_http_request' );
		$this->fake_api( array(), $this->pdf() );

		$this->assertSame( 1, $sync->apply( $this->normalised( $order ) )['downloaded'] );
	}

	/**
	 * The invoice directory is unguessable and not served by Apache.
	 *
	 * @return void
	 */
	public function test_the_invoice_directory_is_protected() {
		$directory = Storage::directory();

		$this->assertStringStartsWith( Storage::DIR_PREFIX, $directory['name'] );
		$this->assertGreaterThan( strlen( Storage::DIR_PREFIX ), strlen( $directory['name'] ) );
		$this->assertFileExists( $directory['path'] . '.htaccess' );
		$this->assertFileExists( $directory['path'] . 'index.php' );
		$this->assertFileDoesNotExist( $directory['path'] . 'protection-probe.pdf' );
	}

	/**
	 * The probe an earlier version left behind is cleared out.
	 *
	 * Guards were only ever added, never removed, so without this the file the deleted
	 * exposure check used to fetch would sit among the invoices for good on any site
	 * that ran a version before 0.20.2.
	 *
	 * @return void
	 */
	public function test_a_probe_left_by_an_earlier_version_is_removed() {
		$directory = Storage::directory();
		$probe     = $directory['path'] . 'protection-probe.pdf';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Recreating what an earlier version wrote.
		file_put_contents( $probe, "wksync-protection-probe\n" );
		$this->assertFileExists( $probe );

		Storage::directory();

		$this->assertFileDoesNotExist( $probe );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local file the storage layer just wrote.
		$this->assertStringContainsString( 'denied', file_get_contents( $directory['path'] . '.htaccess' ) );
	}

	/**
	 * A stored path that climbs out of the invoice directory is refused.
	 *
	 * The path comes out of order meta, so it is treated as untrusted input.
	 *
	 * @return void
	 */
	public function test_a_path_outside_the_invoice_directory_is_refused() {
		Storage::directory();

		$this->assertWPError( Storage::resolve( '../../wp-config.php' ) );
		$this->assertWPError( Storage::resolve( '' ) );
	}

	/**
	 * The customer the order belongs to may download it; another customer may not.
	 *
	 * @return void
	 */
	public function test_only_the_orders_own_customer_may_download_it() {
		$customer = $this->factory->user->create( array( 'role' => 'customer' ) );
		$stranger = $this->factory->user->create( array( 'role' => 'customer' ) );
		$order    = $this->make_order( $customer );

		wp_set_current_user( $customer );
		$this->assertTrue( Download::permitted( $order ) );

		wp_set_current_user( $stranger );
		$this->assertFalse( Download::permitted( $order ) );

		wp_set_current_user( 0 );
		$this->assertFalse( Download::permitted( $order ) );
	}

	/**
	 * A guest holding the order key may download it, and only the right key works.
	 *
	 * Guest checkouts have no account to authenticate against, and the key is the
	 * same token WooCommerce trusts on the order-received page.
	 *
	 * @return void
	 */
	public function test_the_order_key_authorises_a_guest() {
		$order = $this->make_order();

		wp_set_current_user( 0 );

		$this->assertTrue( Download::permitted( $order, $order->get_order_key() ) );
		$this->assertFalse( Download::permitted( $order, 'wc_order_wrongkey123' ) );
		$this->assertFalse( Download::permitted( $order, '' ) );
	}

	/**
	 * A shop manager may download any order's invoice.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_may_download_any_invoice() {
		$order = $this->make_order();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertTrue( Download::permitted( $order ) );
	}

	/**
	 * The order details page links to the invoice.
	 *
	 * @return void
	 */
	public function test_the_order_details_link_to_the_invoice() {
		$order = $this->stored_invoice_order();

		$markup = $this->capture(
			function () use ( $order ) {
				( new Invoices() )->render_order_details( $order );
			}
		);

		$this->assertStringContainsString( 'Invoice 141542', $markup );
		$this->assertStringContainsString( 'action=' . Download::ACTION, $markup );
		$this->assertStringContainsString( 'order=' . $order->get_id(), $markup );
	}

	/**
	 * Invoices are attached to the customer's emails and not to the shop's copy.
	 *
	 * @return void
	 */
	public function test_invoices_are_attached_to_customer_emails_only() {
		$order   = $this->stored_invoice_order();
		$display = new Invoices();

		$attached = $display->attach( array(), 'customer_completed_order', $order, new WC_Email_Customer_Completed_Order() );

		$this->assertCount( 1, $attached );
		$this->assertFileExists( $attached[0] );

		$this->assertSame(
			array(),
			$display->attach( array(), 'new_order', $order, new WC_Email_New_Order() ),
			'The admin copy must not carry the invoice.'
		);
	}

	/**
	 * A shop can narrow which emails carry the attachment.
	 *
	 * @return void
	 */
	public function test_the_attachment_can_be_filtered_off() {
		$order = $this->stored_invoice_order();

		add_filter( 'woo_kontor_sync_attach_invoices', '__return_false' );

		$this->assertSame(
			array(),
			( new Invoices() )->attach( array(), 'customer_completed_order', $order, new WC_Email_Customer_Completed_Order() )
		);
	}

	/**
	 * An invoice whose file has been deleted is not offered as a download.
	 *
	 * @return void
	 */
	public function test_an_invoice_whose_file_has_gone_is_not_offered() {
		$order    = $this->stored_invoice_order();
		$invoices = InvoiceSync::for_order( $order );

		wp_delete_file( Storage::resolve( $invoices[0]['file'] ) );

		$this->assertSame( array(), InvoiceSync::for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * A full run reads the listing, queues the work and downloads what it finds.
	 *
	 * Covers the field mapping the API actually sends: Belegnr arrives as an integer
	 * and Datum as a timestamp whose time is always midnight.
	 *
	 * @return void
	 */
	public function test_a_full_run_downloads_what_the_listing_reports() {
		$order = $this->make_order();

		$this->fake_api( array( $this->invoice_row( $order->get_id() ) ), $this->pdf() );

		$sync = new InvoiceSync( null, $this->settings() );
		$sync->start();

		$run = Status::get( InvoiceSync::JOB )['started'];

		$this->assertSame( 'running', Status::get( InvoiceSync::JOB )['state'] );

		$sync->apply_chunk( 0, $run );

		$status   = Status::get( InvoiceSync::JOB );
		$invoices = InvoiceSync::for_order( wc_get_order( $order->get_id() ) );

		$this->assertSame( 'success', $status['state'] );
		$this->assertSame( 1, $status['counts']['downloaded'] );
		$this->assertCount( 1, $invoices );
		$this->assertSame( '141542', $invoices[0]['number'] );
		$this->assertSame( '2026-08-04', $invoices[0]['date'] );
	}

	/**
	 * A listing row missing its document id or order number is dropped.
	 *
	 * Neither can be recovered: one cannot be downloaded and the other cannot be
	 * filed anywhere.
	 *
	 * @return void
	 */
	public function test_an_unusable_listing_row_is_dropped() {
		$order = $this->make_order();

		$this->fake_api(
			array(
				$this->invoice_row( $order->get_id(), array( 'id' => '' ) ),
				$this->invoice_row( $order->get_id(), array( 'ordernumber' => null ) ),
			),
			$this->pdf()
		);

		( new InvoiceSync( null, $this->settings() ) )->start();

		$this->assertSame( 'success', Status::get( InvoiceSync::JOB )['state'] );
		$this->assertSame( array(), InvoiceSync::for_order( wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * One normalised row pointing at an order.
	 *
	 * @param WC_Order $order Order the invoice belongs to.
	 * @return array Rows in the shape apply() takes.
	 */
	private function normalised( $order ) {
		return array(
			array(
				'id'           => self::DOCUMENT_ID,
				'number'       => '141542',
				'date'         => '2026-08-04',
				'order_number' => (string) $order->get_id(),
			),
		);
	}

	/**
	 * An order with one invoice already downloaded onto it.
	 *
	 * @return WC_Order The saved order.
	 */
	private function stored_invoice_order() {
		$order = $this->make_order();

		$this->fake_api( array(), $this->pdf() );
		( new InvoiceSync( null, $this->settings() ) )->apply( $this->normalised( $order ) );

		return wc_get_order( $order->get_id() );
	}
}
