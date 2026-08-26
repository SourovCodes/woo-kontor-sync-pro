<?php
/**
 * Tests for recovering the schedules when a reconciliation never finished.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ActionScheduler;
use ActionScheduler_Store;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Plugin;
use WooKontorSync\Sync\Scheduler;
use WP_UnitTestCase;

/**
 * Covers the two ways a shop can end up with no recurring action at all while its
 * settings screen shows every interval as configured.
 *
 * Both were found on live shops, and neither is recoverable by hand from the admin:
 * Run now queues a one-off and never touches a schedule, so the obvious thing a shop
 * manager reaches for has no effect at all.
 */
class ScheduleRecoveryTest extends WP_UnitTestCase {

	/**
	 * Start from an empty queue with the save hook off.
	 *
	 * The assertions are about what the reconciliation leaves behind, so the hook
	 * that reschedules on save would otherwise queue the actions being looked for.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		remove_all_actions( 'update_option_' . Settings::OPTION_KEY );

		as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		delete_transient( Scheduler::SCHEDULE_GUARD );
	}

	/**
	 * Leave nothing queued behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		delete_transient( Scheduler::SCHEDULE_GUARD );
		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Store an interval for the stock job, every other job on Never.
	 *
	 * @param int $interval Seconds.
	 * @return void
	 */
	private function store_stock_interval( $interval ) {
		$settings = Settings::get_settings();

		foreach ( array( 'product_sync_interval', 'stock_sync_interval', 'order_sync_interval', 'delivery_sync_interval', 'invoice_sync_interval' ) as $setting ) {
			$settings[ $setting ] = Settings::INTERVAL_NEVER;
		}

		$settings['stock_sync_interval'] = $interval;

		update_option( Settings::OPTION_KEY, $settings );
	}

	/**
	 * How long the guard has left to live.
	 *
	 * @return int Seconds remaining, or 0 when it is not set.
	 */
	private function guard_ttl() {
		$timeout = get_option( '_transient_timeout_' . Scheduler::SCHEDULE_GUARD );

		return $timeout ? (int) $timeout - time() : 0;
	}

	/**
	 * The guard is held for the whole reconciliation, not claimed afterwards.
	 *
	 * Two concurrent requests both finding no guard would both decide the job is
	 * unscheduled and both queue a recurring action, and the shop would then sync
	 * twice as often for ever. So the guard has to be standing by the time anything
	 * is written to the queue.
	 *
	 * @return void
	 */
	public function test_the_guard_is_already_held_while_the_work_runs() {
		$this->store_stock_interval( 900 );

		$held = null;

		add_action(
			'action_scheduler_stored_action',
			function () use ( &$held ) {
				$held = get_transient( Scheduler::SCHEDULE_GUARD );
			}
		);

		( new Scheduler() )->ensure_recurring_actions();

		$this->assertNotFalse( $held, 'the guard should have been claimed before the action was stored' );
		$this->assertTrue( Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ) );
	}

	/**
	 * A finished reconciliation holds the guard for the full hour.
	 *
	 * @return void
	 */
	public function test_a_finished_reconciliation_settles_the_guard() {
		$this->store_stock_interval( 900 );

		( new Scheduler() )->ensure_recurring_actions();

		$this->assertGreaterThan(
			Scheduler::GUARD_ATTEMPT,
			$this->guard_ttl(),
			'the guard should have been extended past the attempt window'
		);
		$this->assertLessThanOrEqual( Scheduler::GUARD_SETTLED, $this->guard_ttl() );
	}

	/**
	 * A reconciliation that dies costs minutes, not an hour.
	 *
	 * This is the failure the two-phase guard exists for: the request is killed
	 * between claiming the guard and finishing the work — a fatal, an execution
	 * limit, or the file swap of a plugin update — so nothing is scheduled and the
	 * guard is all that is left behind.
	 *
	 * Simulated by throwing out of the settings read that sync_schedules() opens
	 * with, which is as close to a dead request as a test can get. Deliberately not
	 * from one of Action Scheduler's own hooks: it catches exceptions raised while
	 * storing an action, so the reconciliation would return normally and settle the
	 * guard, and the test would pass while proving nothing.
	 *
	 * @return void
	 */
	public function test_a_reconciliation_that_dies_does_not_hold_the_guard_for_an_hour() {
		$this->store_stock_interval( 900 );

		add_filter(
			'option_' . Settings::OPTION_KEY,
			function () {
				throw new \RuntimeException( 'killed mid-reconciliation' );
			}
		);

		try {
			( new Scheduler() )->ensure_recurring_actions();
			$this->fail( 'the reconciliation should have been interrupted' );
		} catch ( \RuntimeException $e ) {
			unset( $e );
		}

		$ttl = $this->guard_ttl();

		$this->assertGreaterThan( 0, $ttl, 'the guard should still be claimed' );
		$this->assertLessThanOrEqual(
			Scheduler::GUARD_ATTEMPT,
			$ttl,
			'a reconciliation that never finished must not hold the guard for the settled period'
		);
	}

	/**
	 * A version change puts the schedules back.
	 *
	 * Nothing else does after an update: WordPress does not run the deactivation or
	 * activation hooks when it replaces a plugin, so the only thing left is the
	 * once-an-hour check — which is exactly what an update is most likely to have
	 * interrupted.
	 *
	 * @return void
	 */
	public function test_a_version_change_restores_the_schedules() {
		$this->store_stock_interval( 900 );

		// The state a shop is left in when a reconciliation dies during an update:
		// nothing queued, the guard standing, and the stamp from the old version.
		as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		set_transient( Scheduler::SCHEDULE_GUARD, 1, Scheduler::GUARD_SETTLED );
		update_option( Plugin::VERSION_KEY, '0.0.1' );

		$this->assertFalse( Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ) );

		$upgraded = 0;
		add_action(
			'woo_kontor_sync_upgraded',
			function () use ( &$upgraded ) {
				++$upgraded;
			}
		);

		$this->boot();

		$this->assertTrue(
			Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ),
			'the upgrade should have put the recurring action back'
		);
		$this->assertSame( WKSYNC_VERSION, get_option( Plugin::VERSION_KEY ) );
		$this->assertSame( 1, $upgraded );
	}

	/**
	 * Running the same version again costs one comparison and changes nothing.
	 *
	 * @return void
	 */
	public function test_the_same_version_reconciles_nothing() {
		$this->store_stock_interval( 900 );

		update_option( Plugin::VERSION_KEY, WKSYNC_VERSION );
		set_transient( Scheduler::SCHEDULE_GUARD, 1, Scheduler::GUARD_SETTLED );

		$upgraded = 0;
		add_action(
			'woo_kontor_sync_upgraded',
			function () use ( &$upgraded ) {
				++$upgraded;
			}
		);

		$this->boot();

		$this->assertSame( 0, $upgraded );
		$this->assertNotFalse(
			get_transient( Scheduler::SCHEDULE_GUARD ),
			'an unchanged version should not have cleared the guard'
		);
		$this->assertFalse(
			Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ),
			'nothing should have been queued'
		);
	}

	/**
	 * The version stamp is autoloaded, since it is read on every request.
	 *
	 * @return void
	 */
	public function test_the_version_stamp_is_autoloaded() {
		update_option( Plugin::VERSION_KEY, '0.0.1', false );

		$this->boot();

		global $wpdb;

		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", Plugin::VERSION_KEY )
		);

		$this->assertContains( $autoload, array( 'yes', 'on', 'auto', 'auto-on' ) );
	}

	/**
	 * Run a request the way WordPress does: the upgrade check, then `init`.
	 *
	 * Both halves matter. maybe_upgrade() runs on `plugins_loaded` and only clears
	 * the rate limit, because Action Scheduler's tables are not registered on `$wpdb`
	 * that early; ensure_recurring_actions() is what actually reconciles, on `init`.
	 * Plugin::init() guards itself against running twice and the suite has already
	 * booted the plugin, so the private half is invoked directly rather than
	 * pretending a second request happened.
	 *
	 * @return void
	 */
	private function boot() {
		( new \ReflectionMethod( Plugin::class, 'maybe_upgrade' ) )->invoke( Plugin::instance() );

		( new Scheduler() )->ensure_recurring_actions();
	}

	/**
	 * Booting the plugin is what runs the upgrade check.
	 *
	 * The tests above drive `maybe_upgrade()` directly, so they would go on passing if
	 * the call disappeared from `Plugin::init()` and no shop ever reconciled after an
	 * update again. This is the one that notices. A fresh instance is built rather
	 * than the shared one, whose init() has already run in the suite bootstrap and
	 * refuses to run twice; WP_UnitTestCase restores the hook registry afterwards, so
	 * the second round of add_action() calls does not survive the test.
	 *
	 * @return void
	 */
	public function test_booting_the_plugin_runs_the_upgrade_check() {
		update_option( Plugin::VERSION_KEY, '0.0.1' );

		$plugin = ( new \ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
		$plugin->init();

		$this->assertSame(
			WKSYNC_VERSION,
			get_option( Plugin::VERSION_KEY ),
			'Plugin::init() should have run the upgrade check'
		);
	}

	/**
	 * Nothing queued twice when the queue is already right.
	 *
	 * @return void
	 */
	public function test_an_upgrade_does_not_duplicate_a_schedule_that_is_already_there() {
		$this->store_stock_interval( 900 );

		( new Scheduler() )->sync_schedules();

		$before = (array) ActionScheduler::store()->query_actions(
			array(
				'hook'     => Scheduler::ACTION_SYNC_STOCK,
				'group'    => Scheduler::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 20,
			)
		);

		update_option( Plugin::VERSION_KEY, '0.0.1' );

		$this->boot();

		$after = (array) ActionScheduler::store()->query_actions(
			array(
				'hook'     => Scheduler::ACTION_SYNC_STOCK,
				'group'    => Scheduler::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 20,
			)
		);

		$this->assertCount( 1, $before );
		$this->assertSame( $before, $after, 'the existing schedule should have been left exactly as it was' );
	}
}
