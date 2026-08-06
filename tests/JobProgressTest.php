<?php
/**
 * Tests for how far through a run the settings screen thinks a job is.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WooKontorSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers the denominator, the numerator and the sweep that now chains.
 *
 * A percentage is worse than no percentage when it is wrong, so what is pinned down
 * here is mostly what the bar refuses to claim: nothing before the work has been
 * counted, and never more than everything.
 */
class JobProgressTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Clear state that outlives a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		}

		parent::tear_down();
	}

	/**
	 * A run nobody has measured reports no percentage at all.
	 *
	 * Zero would be a claim — that the run has started and achieved nothing — and the
	 * screen shows an indeterminate bar instead, which is the truth.
	 *
	 * @return void
	 */
	public function test_an_unmeasured_run_has_no_percentage() {
		Status::start( 'products' );

		$this->assertNull( Status::percentage( Status::get( 'products' ) ) );
	}

	/**
	 * A measured run reports its position as a percentage.
	 *
	 * @return void
	 */
	public function test_a_measured_run_reports_a_percentage() {
		Status::start( 'products' );
		Status::measure( 'products', 400 );

		$this->assertSame( 0, Status::percentage( Status::get( 'products' ) ) );

		Status::advance( 'products', 100 );
		$this->assertSame( 25, Status::percentage( Status::get( 'products' ) ) );

		Status::advance( 'products', 100 );
		$this->assertSame( 50, Status::percentage( Status::get( 'products' ) ) );
	}

	/**
	 * Overshooting the total still reads as finished, not as more than finished.
	 *
	 * The total is what the API claimed at the first page, and the walk advances by the
	 * rows actually returned, so the two genuinely can disagree.
	 *
	 * @return void
	 */
	public function test_progress_is_clamped_at_a_hundred() {
		Status::start( 'products' );
		Status::measure( 'products', 100 );
		Status::advance( 'products', 250 );

		$this->assertSame( 100, Status::percentage( Status::get( 'products' ) ) );
	}

	/**
	 * Starting a run clears the previous one's position.
	 *
	 * @return void
	 */
	public function test_a_new_run_starts_from_nothing() {
		Status::start( 'stock' );
		Status::measure( 'stock', 50 );
		Status::advance( 'stock', 50 );

		$this->assertSame( 100, Status::percentage( Status::get( 'stock' ) ) );

		Status::start( 'stock' );

		$this->assertNull( Status::percentage( Status::get( 'stock' ) ) );
		$this->assertSame( 0, Status::get( 'stock' )['processed'] );
	}

	/**
	 * The product walk measures itself once, from the first page.
	 *
	 * Kontor repeats totalCount on every page. Taking it each time would move the
	 * goalposts under a bar that is already half full, if the catalogue changed while
	 * the walk was running.
	 *
	 * @return void
	 */
	public function test_the_product_walk_measures_itself_from_the_first_page() {
		$sync = new ProductSync(
			null,
			array(
				'api_base_url'   => 'https://erp.example.test/api/v1/kontor',
				'api_key'        => 'test-key-123',
				'image_base_url' => '',
			)
		);

		$run = Status::start( ProductSync::JOB );

		$this->fake_products( 2, 4386 );
		$sync->import_page( 0, $run );

		$status = Status::get( ProductSync::JOB );

		$this->assertSame( 4386, $status['total'] );
		$this->assertSame( 2, $status['processed'] );

		// A later page reporting a different total does not move the denominator.
		remove_all_filters( 'pre_http_request' );
		$this->fake_products( 2, 9999 );
		$sync->import_page( 200, $run );

		$this->assertSame( 4386, Status::get( ProductSync::JOB )['total'] );
		$this->assertSame( 4, Status::get( ProductSync::JOB )['processed'] );
	}

	/**
	 * The stock sync measures the payload it cached.
	 *
	 * @return void
	 */
	public function test_the_stock_sync_measures_its_payload() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
			)
		);

		// Stand in for a connection test that already succeeded, so the gate does not
		// need a stubbed round trip of its own.
		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );

		$levels = array();

		for ( $index = 1; $index <= 7; $index++ ) {
			$levels[] = array(
				'Artnr'        => 'abel-AB' . $index,
				'Lagerbestand' => $index,
			);
		}

		$this->fake_envelope( $levels );

		( new StockSync() )->start();

		$this->assertSame( 7, Status::get( 'stock' )['total'] );
	}

	/**
	 * The order sweep queues its batches rather than sending them inline.
	 *
	 * It used to upload every batch inside the action that started it — up to
	 * SWEEP_LIMIT orders, several round trips to Kontor, in one request that a slow
	 * link could see cut short.
	 *
	 * @return void
	 */
	public function test_the_order_sweep_chains_its_batches() {
		$this->configure_orders();

		$order = $this->make_order();

		( new OrderSync() )->start();

		$status = Status::get( OrderSync::JOB );

		$this->assertSame( 'running', $status['state'] );
		$this->assertSame( 1, $status['total'] );
		$this->assertSame( 0, $status['processed'] );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS_BATCH ) );

		// Nothing was sent yet: the batch is queued, not performed.
		$this->assertSame( '', (string) $order->get_meta( OrderSync::META_PUSHED_AT ) );
	}

	/**
	 * A queued batch sends its orders, advances, and closes the run.
	 *
	 * @return void
	 */
	public function test_a_queued_batch_sends_and_completes() {
		$this->configure_orders();

		$order = $this->make_order();
		$sync  = new OrderSync();

		$sync->start();

		$run = Status::get( OrderSync::JOB )['started'];

		$this->fake_envelope(
			array(
				array(
					'orderId'     => (string) $order->get_id(),
					'orderNumber' => (string) $order->get_id(),
					'auftrnr'     => 'AW 214807',
					'status'      => 'ok',
					'message'     => null,
				),
			)
		);

		$sync->send_batch( 0, $run );

		$status = Status::get( OrderSync::JOB );

		$this->assertSame( 'success', $status['state'] );
		$this->assertSame( 1, $status['processed'] );
		$this->assertSame( 100, Status::percentage( $status ) );

		$refreshed = wc_get_order( $order->get_id() );
		$this->assertNotSame( '', (string) $refreshed->get_meta( OrderSync::META_PUSHED_AT ) );
	}

	/**
	 * A batch belonging to a superseded run is thrown away.
	 *
	 * @return void
	 */
	public function test_a_superseded_batch_is_discarded() {
		$this->configure_orders();

		$order = $this->make_order();
		$sync  = new OrderSync();

		$sync->start();

		$stale = Status::get( OrderSync::JOB )['started'];

		// A newer run takes over before the batch gets its turn.
		Status::start( OrderSync::JOB );

		$sync->send_batch( 0, $stale - 1 );

		$this->assertSame( '', (string) wc_get_order( $order->get_id() )->get_meta( OrderSync::META_PUSHED_AT ) );
	}

	/**
	 * An order sent by the checkout hook meanwhile is not sent twice.
	 *
	 * The sweep fixes its list when it starts, so a batch can reach an order that has
	 * been dealt with in the meantime.
	 *
	 * @return void
	 */
	public function test_an_order_pushed_meanwhile_is_skipped() {
		$this->configure_orders();

		$order = $this->make_order();
		$sync  = new OrderSync();

		$sync->start();

		$run = Status::get( OrderSync::JOB )['started'];

		// The single-order action got there first.
		$order->update_meta_data( OrderSync::META_PUSHED_AT, time() );
		$order->save();

		$sent = false;

		add_filter(
			'pre_http_request',
			static function ( $pre ) use ( &$sent ) {
				$sent = true;

				return $pre;
			}
		);

		$sync->send_batch( 0, $run );

		$this->assertFalse( $sent, 'The sweep re-sent an order that had already been pushed.' );
		$this->assertSame( 'success', Status::get( OrderSync::JOB )['state'] );
	}

	/**
	 * A failed batch is the orders job's failure, so the run does not strand.
	 *
	 * @return void
	 */
	public function test_the_batch_action_belongs_to_the_orders_job() {
		$this->assertSame( 'orders', Scheduler::job_for_action( Scheduler::ACTION_SYNC_ORDERS_BATCH ) );

		// The single checkout push still does not, being one order rather than a sweep.
		$this->assertSame( '', Scheduler::job_for_action( Scheduler::ACTION_SYNC_ORDER ) );
	}

	/**
	 * Settings that let the order jobs run.
	 *
	 * @return void
	 */
	private function configure_orders() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
				'shop_id'      => self::SHOP_ID,
			)
		);

		// Stand in for a connection test that already succeeded, so the gate does not
		// need a stubbed round trip of its own.
		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );
	}

	/**
	 * A paid order with one line item.
	 *
	 * @return WC_Order The saved order.
	 */
	private function make_order() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_regular_price( '10.00' );
		$product->save();

		$order = new WC_Order();
		$order->set_status( 'processing' );
		$order->set_currency( 'EUR' );
		$order->set_billing_first_name( 'Max' );
		$order->set_billing_last_name( 'Mustermann' );
		$order->set_billing_email( 'max@example.com' );
		$order->set_billing_address_1( 'Musterstraße 1' );
		$order->set_billing_postcode( '50667' );
		$order->set_billing_city( 'Köln' );
		$order->set_billing_country( 'DE' );
		$order->add_product( $product, 2 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Answer every request with a well-formed envelope carrying these rows.
	 *
	 * @param array $rows  Rows for the data member.
	 * @param int   $total Total count to report.
	 * @return void
	 */
	private function fake_envelope( array $rows, $total = null ) {
		$body = array(
			'success' => true,
			'message' => '',
			'meta'    => array(
				'rowCount'   => count( $rows ),
				'totalCount' => null === $total ? count( $rows ) : $total,
			),
			'data'    => $rows,
		);

		add_filter(
			'pre_http_request',
			static function () use ( $body ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);
	}

	/**
	 * Answer the catalogue request with a page of articles.
	 *
	 * @param int $rows  Articles on the page.
	 * @param int $total Total the API claims.
	 * @return void
	 */
	private function fake_products( $rows, $total ) {
		$articles = array();

		for ( $index = 1; $index <= $rows; $index++ ) {
			$articles[] = array(
				'Artnr'        => 'abel-AB' . wp_rand( 1000, 999999 ),
				'Bez1'         => 'Article ' . $index,
				'UVP'          => 10.0,
				'Lagerbestand' => 5,
			);
		}

		$this->fake_envelope( $articles, $total );
	}
}
