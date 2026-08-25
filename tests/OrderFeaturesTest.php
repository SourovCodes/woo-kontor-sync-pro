<?php
/**
 * Tests for the shop that only imports a catalogue, and for when orders are sent.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\DeliverySync;
use WooKontorSync\Sync\InvoiceSync;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WooKontorSync\Sync\StockSync;
use WP_UnitTestCase;

/**
 * Covers the two settings a shop that does not use Kontor for orders needs.
 *
 * Both defaults are today's behaviour, so most of what is pinned here is the shape
 * of the opt-out: what refuses, what is never queued, and what a shop gets back when
 * it changes its mind.
 */
class OrderFeaturesTest extends WP_UnitTestCase {

	/**
	 * A well-formed but synthetic shop ID.
	 */
	const SHOP_ID = '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';

	/**
	 * Start each test with an empty queue.
	 *
	 * Action Scheduler's rows outlive the transaction WP_UnitTestCase rolls back,
	 * while the order IDs inside them do not — a rolled-back order's ID is handed
	 * straight back out to the next test. So a push queued for order 42 in one test
	 * is still sitting there when the next test's order is also 42, and an assertion
	 * that nothing was queued reads somebody else's row. Clearing by hook rather than
	 * by group, because an empty hook name cancels nothing.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->empty_queue();
	}

	/**
	 * Clear state that outlives a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

		$this->empty_queue();

		parent::tear_down();
	}

	/**
	 * Cancel everything this plugin has queued.
	 *
	 * @return void
	 */
	private function empty_queue() {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$hooks = array(
			Scheduler::ACTION_SYNC_ORDER,
			Scheduler::ACTION_SYNC_ORDERS,
			Scheduler::ACTION_SYNC_ORDERS_BATCH,
			Scheduler::ACTION_SYNC_DELIVERY,
			Scheduler::ACTION_SYNC_INVOICES,
			Scheduler::ACTION_SYNC_PRODUCTS,
			Scheduler::ACTION_SYNC_STOCK,
		);

		foreach ( $hooks as $hook ) {
			as_unschedule_all_actions( $hook );
		}
	}

	/**
	 * Credentials good enough for every gate but the ones under test.
	 *
	 * @param array $overrides Settings to change.
	 * @return array Settings array.
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
				'shop_id'      => self::SHOP_ID,
			),
			$overrides
		);
	}

	/**
	 * Store settings and pretend the connection test already passed.
	 *
	 * @param array $overrides Settings to change.
	 * @return void
	 */
	private function configure( array $overrides = array() ) {
		update_option( Settings::OPTION_KEY, $this->settings( $overrides ) );

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
	 * Whether this one order has a push waiting for it.
	 *
	 * Asked about the order rather than about the hook. A queue holding somebody
	 * else's action is not this order having been queued, and an assertion that could
	 * not tell the two apart would pass on a dirty queue and fail on a busy one.
	 *
	 * @param WC_Order $order Order to ask about.
	 * @return bool True when a push is queued for it.
	 */
	private function push_queued( WC_Order $order ) {
		return as_has_scheduled_action(
			Scheduler::ACTION_SYNC_ORDER,
			array( 'order_id' => (int) $order->get_id() )
		);
	}

	/**
	 * Settings stored before this version keep exchanging orders.
	 *
	 * The one default in this plugin that starts on, and the reason is upgrades: off
	 * takes a capability away, so on is the value that leaves a shop doing what it
	 * did yesterday.
	 *
	 * @return void
	 */
	public function test_orders_are_enabled_for_a_shop_that_never_saw_the_setting() {
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'test-key-123' ) );

		$this->assertTrue( Settings::orders_enabled() );
		$this->assertSame( Settings::PUSH_IMMEDIATE, Settings::push_mode() );
	}

	/**
	 * A settings array with no such key reads as on, not as off.
	 *
	 * The asymmetry is the point. Reading "on" as "off" would silently stop a working
	 * shop sending orders, which nobody notices until the warehouse asks; the reverse
	 * queues an upload the next gate refuses and logs.
	 *
	 * @return void
	 */
	public function test_a_settings_array_without_the_key_still_exchanges_orders() {
		$settings = $this->settings();

		unset( $settings[ Settings::SYNC_ORDERS ] );

		$this->assertTrue( Settings::orders_enabled( $settings ) );
		$this->assertSame( Settings::PUSH_IMMEDIATE, Settings::push_mode( $settings ) );
	}

	/**
	 * Every order-side job refuses when the shop does not exchange orders.
	 *
	 * @return void
	 */
	public function test_the_order_side_jobs_refuse_when_orders_are_off() {
		$settings = $this->settings( array( Settings::SYNC_ORDERS => false ) );

		foreach ( array( OrderSync::JOB, DeliverySync::JOB, InvoiceSync::JOB ) as $job ) {
			$result = Preflight::check( $job, $settings );

			$this->assertWPError( $result, $job );
			$this->assertSame( 'wksync_orders_disabled', $result->get_error_code(), $job );
		}
	}

	/**
	 * The product and stock syncs are untouched by the setting.
	 *
	 * @return void
	 */
	public function test_the_catalogue_jobs_ignore_the_orders_setting() {
		$settings = $this->settings( array( Settings::SYNC_ORDERS => false ) );

		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );

		$this->assertTrue( Preflight::check( ProductSync::JOB, $settings ) );
		$this->assertTrue( Preflight::check( StockSync::JOB, $settings ) );
	}

	/**
	 * A shop with no Kontor shop chosen still imports its catalogue.
	 *
	 * This is the whole promise the opt-out rests on, and nothing pinned it before.
	 *
	 * @return void
	 */
	public function test_the_catalogue_jobs_need_no_shop() {
		$settings = $this->settings(
			array(
				Settings::SYNC_ORDERS => false,
				'shop_id'             => '',
				'shop_name'           => '',
			)
		);

		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );

		$this->assertTrue( Preflight::check( ProductSync::JOB, $settings ) );
		$this->assertTrue( Preflight::check( StockSync::JOB, $settings ) );
	}

	/**
	 * "This shop does not do orders" outranks "you forgot to pick a shop".
	 *
	 * The shop field is not even on the screen for such a shop, so naming it would
	 * send whoever read the refusal looking for something that is not there.
	 *
	 * @return void
	 */
	public function test_the_orders_gate_answers_before_the_shop_gate() {
		$result = Preflight::check(
			OrderSync::JOB,
			$this->settings(
				array(
					Settings::SYNC_ORDERS => false,
					'shop_id'             => '',
				)
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'wksync_orders_disabled', $result->get_error_code() );
	}

	/**
	 * Run now refuses an order-side job rather than queueing it.
	 *
	 * @return void
	 */
	public function test_run_now_refuses_the_order_side_jobs() {
		$this->configure( array( Settings::SYNC_ORDERS => false ) );

		foreach ( array( OrderSync::JOB, DeliverySync::JOB, InvoiceSync::JOB ) as $job ) {
			$result = Scheduler::trigger( $job );

			$this->assertWPError( $result, $job );
			$this->assertSame( 'wksync_orders_disabled', $result->get_error_code(), $job );
		}

		$this->assertFalse( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS ) );
	}

	/**
	 * Run now still queues the catalogue jobs on such a shop.
	 *
	 * @return void
	 */
	public function test_run_now_still_queues_the_catalogue_jobs() {
		$this->configure(
			array(
				Settings::SYNC_ORDERS => false,
				'shop_id'             => '',
			)
		);

		$this->assertTrue( Scheduler::trigger( ProductSync::JOB ) );
		$this->assertTrue( Scheduler::trigger( StockSync::JOB ) );
	}

	/**
	 * A paid order is queued for pushing on the default settings.
	 *
	 * @return void
	 */
	public function test_a_paid_order_is_queued_immediately_by_default() {
		$this->configure();

		$order = $this->make_order();

		$this->assertTrue( $this->push_queued( $order ) );
	}

	/**
	 * Sweep-only mode queues nothing at checkout.
	 *
	 * @return void
	 */
	public function test_sweep_only_queues_nothing_at_checkout() {
		$this->configure( array( Settings::ORDER_PUSH_MODE => Settings::PUSH_SWEEP ) );

		$order = $this->make_order();

		$this->assertFalse( $this->push_queued( $order ) );
	}

	/**
	 * An order held back from the instant push is still swept up later.
	 *
	 * Nothing marks it as pushed, so it is pending exactly as a rejected order is.
	 *
	 * @return void
	 */
	public function test_an_order_left_to_the_sweep_is_still_pending() {
		$this->configure( array( Settings::ORDER_PUSH_MODE => Settings::PUSH_SWEEP ) );

		$order = $this->make_order();

		$this->assertFalse( $this->push_queued( $order ) );

		( new OrderSync() )->start();

		$this->assertSame( 1, Status::get( OrderSync::JOB )['total'] );
	}

	/**
	 * A shop that does not exchange orders queues nothing at checkout.
	 *
	 * @return void
	 */
	public function test_orders_off_queues_nothing_at_checkout() {
		$this->configure( array( Settings::SYNC_ORDERS => false ) );

		$order = $this->make_order();

		$this->assertFalse( $this->push_queued( $order ) );
	}

	/**
	 * Switching orders off cancels the three recurring actions.
	 *
	 * @return void
	 */
	public function test_the_order_side_schedules_are_cancelled_when_orders_are_off() {
		$intervals = array(
			'product_sync_interval'  => 7 * DAY_IN_SECONDS,
			'stock_sync_interval'    => HOUR_IN_SECONDS,
			'order_sync_interval'    => HOUR_IN_SECONDS,
			'delivery_sync_interval' => HOUR_IN_SECONDS,
			'invoice_sync_interval'  => HOUR_IN_SECONDS,
		);

		update_option( Settings::OPTION_KEY, $this->settings( $intervals ) );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS ) );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_DELIVERY ) );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_INVOICES ) );

		update_option(
			Settings::OPTION_KEY,
			$this->settings( array_merge( $intervals, array( Settings::SYNC_ORDERS => false ) ) )
		);

		$scheduler->sync_schedules();

		$this->assertFalse( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS ) );
		$this->assertFalse( as_has_scheduled_action( Scheduler::ACTION_SYNC_DELIVERY ) );
		$this->assertFalse( as_has_scheduled_action( Scheduler::ACTION_SYNC_INVOICES ) );

		// The catalogue is untouched by any of it.
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_PRODUCTS ) );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_STOCK ) );
	}

	/**
	 * Turning orders back on restores the schedules rather than asking again.
	 *
	 * The intervals were never cleared, only ignored, which is what makes the toggle
	 * safe to press twice.
	 *
	 * @return void
	 */
	public function test_the_order_side_schedules_come_back() {
		$intervals = array(
			'order_sync_interval'    => HOUR_IN_SECONDS,
			'delivery_sync_interval' => HOUR_IN_SECONDS,
			'invoice_sync_interval'  => HOUR_IN_SECONDS,
		);

		$scheduler = new Scheduler();

		update_option(
			Settings::OPTION_KEY,
			$this->settings( array_merge( $intervals, array( Settings::SYNC_ORDERS => false ) ) )
		);
		$scheduler->sync_schedules();

		$this->assertFalse( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS ) );

		update_option( Settings::OPTION_KEY, $this->settings( $intervals ) );
		$scheduler->sync_schedules();

		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_ORDERS ) );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_DELIVERY ) );
		$this->assertTrue( as_has_scheduled_action( Scheduler::ACTION_SYNC_INVOICES ) );
	}

	/**
	 * An absent push mode keeps the stored one.
	 *
	 * Same rule as the intervals and the shop: a partial save must never quietly
	 * change when a shop's orders are sent.
	 *
	 * @return void
	 */
	public function test_an_absent_push_mode_keeps_the_stored_value() {
		update_option(
			Settings::OPTION_KEY,
			$this->settings( array( Settings::ORDER_PUSH_MODE => Settings::PUSH_SWEEP ) )
		);

		$saved = ( new Settings() )->sanitize( array( 'api_base_url' => 'https://erp.example.test/api/v1/kontor' ) );

		$this->assertSame( Settings::PUSH_SWEEP, $saved[ Settings::ORDER_PUSH_MODE ] );
	}

	/**
	 * An unrecognised push mode keeps the stored one too.
	 *
	 * @return void
	 */
	public function test_an_unknown_push_mode_keeps_the_stored_value() {
		update_option(
			Settings::OPTION_KEY,
			$this->settings( array( Settings::ORDER_PUSH_MODE => Settings::PUSH_SWEEP ) )
		);

		$saved = ( new Settings() )->sanitize( array( Settings::ORDER_PUSH_MODE => 'whenever' ) );

		$this->assertSame( Settings::PUSH_SWEEP, $saved[ Settings::ORDER_PUSH_MODE ] );
	}

	/**
	 * The hidden zero beside the checkbox is what switches orders off.
	 *
	 * A browser sends nothing for a cleared box, and an absent field keeps the stored
	 * value, so without the companion field the toggle could never be unticked.
	 *
	 * @return void
	 */
	public function test_the_hidden_field_switches_orders_off() {
		update_option( Settings::OPTION_KEY, $this->settings() );

		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array() )[ Settings::SYNC_ORDERS ] );
		$this->assertFalse( $settings->sanitize( array( Settings::SYNC_ORDERS => '0' ) )[ Settings::SYNC_ORDERS ] );
	}
}
