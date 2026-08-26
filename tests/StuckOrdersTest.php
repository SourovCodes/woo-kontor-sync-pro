<?php
/**
 * Tests for the order the sweep gives up on.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WC_Product_Simple;
use WooKontorSync\Admin\OrderActions;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Admin\StuckOrders;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the starvation guard on the order sweep.
 *
 * `pending_orders()` asks for orders that have never reached Kontor, oldest first,
 * capped at SWEEP_LIMIT. An order Kontor refuses for a reason in its own data never
 * reaches Kontor, so before this it stayed in that set for ever *and sorted to the
 * front of it* — two hundred of them and no order placed afterwards would ever be
 * sent again.
 */
class StuckOrdersTest extends WP_UnitTestCase {

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

		parent::tear_down();
	}

	/**
	 * Repeated refusals eventually take an order out of the queue.
	 *
	 * @return void
	 */
	public function test_an_order_refused_too_often_is_set_aside() {
		$order = $this->make_order();

		$this->refuse( $order, OrderSync::MAX_PUSH_ATTEMPTS - 1 );

		// Still in the queue, and still carrying the reason.
		$this->assertFalse( OrderSync::is_set_aside( $this->reload( $order ) ) );
		$this->assertContains( $order->get_id(), $this->pending() );

		$this->refuse( $order, 1 );

		$this->assertTrue( OrderSync::is_set_aside( $this->reload( $order ) ) );
		$this->assertNotContains( $order->get_id(), $this->pending() );
	}

	/**
	 * A set-aside order does not stand in front of the orders behind it.
	 *
	 * The whole point: the sweep is oldest-first and capped, so an order that can
	 * never succeed used to occupy a place in every sweep for ever.
	 *
	 * @return void
	 */
	public function test_a_set_aside_order_stops_blocking_later_ones() {
		$stuck = $this->make_order();
		$fresh = $this->make_order();

		$this->refuse( $stuck, OrderSync::MAX_PUSH_ATTEMPTS );

		$pending = $this->pending();

		$this->assertNotContains( $stuck->get_id(), $pending );
		$this->assertContains( $fresh->get_id(), $pending );
	}

	/**
	 * The order says what happened, and how many times.
	 *
	 * @return void
	 */
	public function test_the_order_records_the_attempts_and_the_reason() {
		$order = $this->make_order();

		$this->refuse( $order, 2, 'Kundennummer fehlt' );

		$reloaded = $this->reload( $order );

		$this->assertSame( 2, (int) $reloaded->get_meta( OrderSync::META_PUSH_ATTEMPTS ) );
		$this->assertSame( 'Kundennummer fehlt', $reloaded->get_meta( OrderSync::META_PUSH_ERROR ) );
	}

	/**
	 * An order that goes through afterwards carries no trace of the refusals.
	 *
	 * Leaving the count behind would have the order screen say the order was set aside
	 * while it is plainly in Kontor.
	 *
	 * @return void
	 */
	public function test_a_successful_push_clears_the_record() {
		$order = $this->make_order();

		$this->refuse( $order, 2 );
		$this->accept( $order );

		$reloaded = $this->reload( $order );

		$this->assertNotSame( '', (string) $reloaded->get_meta( OrderSync::META_PUSHED_AT ) );
		$this->assertSame( '', (string) $reloaded->get_meta( OrderSync::META_PUSH_ATTEMPTS ) );
		$this->assertSame( '', (string) $reloaded->get_meta( OrderSync::META_PUSH_ERROR ) );
		$this->assertFalse( OrderSync::is_set_aside( $reloaded ) );
	}

	/**
	 * A batch that failed in transit counts against no order in it.
	 *
	 * It says nothing about any particular order, and counting it would set the whole
	 * queue aside over a week of somebody else's network trouble.
	 *
	 * @return void
	 */
	public function test_a_transport_failure_counts_against_nothing() {
		$order = $this->make_order();

		for ( $attempt = 0; $attempt < OrderSync::MAX_PUSH_ATTEMPTS + 2; $attempt++ ) {
			remove_all_filters( 'pre_http_request' );
			add_filter( 'pre_http_request', static fn() => new \WP_Error( 'http_request_failed', 'Connection timed out' ) );
			add_filter( 'woo_kontor_sync_retry_delay', '__return_zero' );

			$this->sync()->push_one( $order->get_id() );
		}

		$reloaded = $this->reload( $order );

		$this->assertSame( '', (string) $reloaded->get_meta( OrderSync::META_PUSH_ATTEMPTS ) );
		$this->assertFalse( OrderSync::is_set_aside( $reloaded ) );
		$this->assertContains( $order->get_id(), $this->pending() );
	}

	/**
	 * An order this plugin cannot map is recorded rather than refused silently for ever.
	 *
	 * Nothing was written here before, so such an order was rejected by our own code on
	 * every sweep with no meta anywhere to say why — the same starvation as a Kontor
	 * rejection, and harder to find, because Kontor never saw the order.
	 *
	 * @return void
	 */
	public function test_an_unmappable_order_is_recorded_and_eventually_set_aside() {
		$order = $this->make_order();

		add_filter(
			'woo_kontor_sync_order_payload',
			static function () {
				throw new \Exception( 'Nope.' );
			}
		);

		for ( $attempt = 0; $attempt < OrderSync::MAX_PUSH_ATTEMPTS; $attempt++ ) {
			$this->sync()->push_one( $order->get_id() );
		}

		remove_all_filters( 'woo_kontor_sync_order_payload' );

		$reloaded = $this->reload( $order );

		$this->assertSame( 'Nope.', $reloaded->get_meta( OrderSync::META_PUSH_ERROR ) );
		$this->assertTrue( OrderSync::is_set_aside( $reloaded ) );
	}

	/**
	 * The order actions box offers the way back, and only where there is one.
	 *
	 * Without it the marker is a one-way door: those orders are out of the sweep's
	 * query by definition, so no sweep would pick them up however thoroughly the order
	 * was fixed.
	 *
	 * @return void
	 */
	public function test_the_way_back_is_offered_only_on_a_set_aside_order() {
		$order   = $this->make_order();
		$actions = new OrderActions();

		$this->assertArrayNotHasKey( OrderActions::RETRY_PUSH, $actions->add_actions( array(), $order ) );

		$this->refuse( $order, OrderSync::MAX_PUSH_ATTEMPTS );

		$this->assertArrayHasKey( OrderActions::RETRY_PUSH, $actions->add_actions( array(), $this->reload( $order ) ) );
	}

	/**
	 * Taking the way back puts the order in front of the sweep again, with a full allowance.
	 *
	 * @return void
	 */
	public function test_retrying_puts_the_order_back_in_the_queue() {
		$order = $this->make_order();

		$this->refuse( $order, OrderSync::MAX_PUSH_ATTEMPTS );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		( new OrderActions() )->retry_push( $this->reload( $order ) );

		$reloaded = $this->reload( $order );

		$this->assertFalse( OrderSync::is_set_aside( $reloaded ) );
		$this->assertSame( '', (string) $reloaded->get_meta( OrderSync::META_PUSH_ATTEMPTS ) );
		$this->assertContains( $order->get_id(), $this->pending() );
	}

	/**
	 * The count has somewhere to go.
	 *
	 * The marker is protected meta, so without this the run summary names orders
	 * nothing in wp-admin could find.
	 *
	 * @return void
	 */
	public function test_the_set_aside_orders_are_countable_and_linked() {
		$this->assertSame( 0, StuckOrders::total() );

		$order = $this->make_order();
		$this->refuse( $order, OrderSync::MAX_PUSH_ATTEMPTS );

		$this->assertSame( 1, StuckOrders::total() );
		$this->assertStringContainsString( StuckOrders::QUERY_ARG, StuckOrders::url() );
	}

	/**
	 * The orders list is narrowed only when asked, and never by replacement.
	 *
	 * WooCommerce's own screen puts clauses on the same query, and assigning over them
	 * would silently widen whatever the shop manager had narrowed.
	 *
	 * @return void
	 */
	public function test_the_orders_list_filter_appends_and_only_when_asked() {
		$stuck    = new StuckOrders();
		$existing = array(
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A fixture standing in for WooCommerce's own clauses.
			'meta_query' => array(
				array(
					'key'   => '_billing_city',
					'value' => 'Köln',
				),
			),
		);

		$this->assertSame( $existing, $stuck->filter_query( $existing ) );

		$_GET[ StuckOrders::QUERY_ARG ] = '1';
		$filtered                       = $stuck->filter_query( $existing );
		unset( $_GET[ StuckOrders::QUERY_ARG ] );

		$this->assertCount( 2, $filtered['meta_query'] );
		$this->assertSame( '_billing_city', $filtered['meta_query'][0]['key'] );
		$this->assertSame( OrderSync::META_PUSH_GIVEN_UP, $filtered['meta_query'][1]['key'] );
	}

	/**
	 * The run summary says so, and only when it happened.
	 *
	 * @return void
	 */
	public function test_the_summary_mentions_orders_it_set_aside() {
		$order = $this->make_order();

		$this->refuse( $order, OrderSync::MAX_PUSH_ATTEMPTS - 1 );

		Status::start( OrderSync::JOB );
		$this->refuse( $order, 1 );

		$this->assertSame( 1, (int) Status::get( OrderSync::JOB )['counts']['set_aside'] );
	}

	/**
	 * A sync wired to the test settings.
	 *
	 * @return OrderSync
	 */
	private function sync() {
		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );

		return new OrderSync(
			null,
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
				'shoptype'     => 'B2C',
				'shop_id'      => self::SHOP_ID,
				'shop_name'    => 'Edu-Shop',
			)
		);
	}

	/**
	 * Have Kontor refuse this order the given number of times.
	 *
	 * @param WC_Order $order   Order to refuse.
	 * @param int      $times   How many refusals.
	 * @param string   $message Reason Kontor gives.
	 * @return void
	 */
	private function refuse( WC_Order $order, $times, $message = 'Kundennummer fehlt' ) {
		for ( $attempt = 0; $attempt < $times; $attempt++ ) {
			remove_all_filters( 'pre_http_request' );
			$this->reply( (string) $order->get_id(), 'fehler', $message );

			$this->sync()->push_one( $order->get_id() );
		}
	}

	/**
	 * Have Kontor accept this order.
	 *
	 * @param WC_Order $order Order to accept.
	 * @return void
	 */
	private function accept( WC_Order $order ) {
		remove_all_filters( 'pre_http_request' );
		$this->reply( (string) $order->get_id(), 'ok', null );

		$this->sync()->push_one( $order->get_id() );
	}

	/**
	 * Answer the upsert with one verdict.
	 *
	 * @param string      $number  Order number to echo back.
	 * @param string      $status  Row status, "ok" or "fehler".
	 * @param string|null $message Row message.
	 * @return void
	 */
	private function reply( $number, $status, $message ) {
		$body = array(
			'success' => true,
			'message' => 'Operation completed successfully',
			'meta'    => array( 'rowCount' => 1 ),
			'data'    => array(
				array(
					'orderId'     => $number,
					'orderNumber' => $number,
					'auftrnr'     => 'ok' === $status ? 'AW 214807' : null,
					'status'      => $status,
					'message'     => $message,
				),
			),
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
	 * The orders the sweep would pick up now.
	 *
	 * @return int[] Order IDs.
	 */
	private function pending() {
		$orders = wc_get_orders(
			array(
				'limit'      => -1,
				'status'     => OrderSync::pushable_statuses(),
				'return'     => 'ids',

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Mirrors pending_orders(); the markers are protected meta.
				'meta_query' => array(
					array(
						'key'     => OrderSync::META_PUSHED_AT,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => OrderSync::META_PUSH_GIVEN_UP,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		return is_array( $orders ) ? array_map( 'absint', $orders ) : array();
	}

	/**
	 * Read an order back from the database.
	 *
	 * @param WC_Order $order Order to reload.
	 * @return WC_Order The order, freshly loaded.
	 */
	private function reload( WC_Order $order ) {
		return wc_get_order( $order->get_id() );
	}

	/**
	 * Build a paid order with one line item.
	 *
	 * @return WC_Order The saved order.
	 */
	private function make_order() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB' . wp_rand( 1000, 999999 ) );
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
}
