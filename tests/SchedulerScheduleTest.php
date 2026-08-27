<?php
/**
 * Tests for keeping the recurring actions in step with the intervals.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ActionScheduler;
use ActionScheduler_Store;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Scheduler;
use WP_UnitTestCase;

/**
 * Covers what sync_schedules() counts as "this job is already scheduled".
 *
 * The question is only ever about the recurring action. Run now queues an async
 * action against the same hook, and on a shop whose queue runs behind, that action
 * can sit there for the best part of an hour — long enough for every schedule
 * reconciliation in the meantime to mistake it for the schedule and leave the job
 * with no recurring action at all. That is not a hypothetical: it is what took the
 * fifteen-minute stock sync off a live shop, with the settings screen still
 * reporting the job as scheduled.
 */
class SchedulerScheduleTest extends WP_UnitTestCase {

	/**
	 * Start from an empty queue, with saving the settings queueing nothing.
	 *
	 * Every assertion here is about which actions `sync_schedules()` leaves behind,
	 * so the save hook that calls `reschedule()` is taken off — otherwise storing an
	 * interval would queue the very action the test is about to look for. The hooks
	 * are restored by WP_UnitTestCase between tests.
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
		delete_option( Settings::OPTION_KEY );

		parent::tear_down();
	}

	/**
	 * Store one interval against a job, leaving every other job on Never.
	 *
	 * @param string $key      Settings key.
	 * @param int    $interval Seconds, or Settings::INTERVAL_NEVER.
	 * @return void
	 */
	private function store_interval( $key, $interval ) {
		$settings = Settings::get_settings();

		foreach ( array( 'product_sync_interval', 'stock_sync_interval', 'order_sync_interval', 'delivery_sync_interval', 'invoice_sync_interval' ) as $setting ) {
			$settings[ $setting ] = Settings::INTERVAL_NEVER;
		}

		$settings[ $key ] = $interval;

		update_option( Settings::OPTION_KEY, $settings );
	}

	/**
	 * Saving the settings does not throw away a Run now somebody has just started.
	 *
	 * `reschedule()` reached for `as_unschedule_all_actions()`, which takes everything
	 * queued on the hook — the manual run included. It went silently, because a
	 * cancelled async action leaves nothing behind to notice, and on a shop whose queue
	 * runs behind the window between pressing Run now and pressing Save is not
	 * milliseconds.
	 *
	 * @return void
	 */
	public function test_saving_the_settings_keeps_a_pending_manual_run() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertCount( 2, $this->actions( Scheduler::ACTION_SYNC_STOCK ) );

		$this->store_interval( 'stock_sync_interval', HOUR_IN_SECONDS );
		$scheduler->reschedule();

		// The schedule was replaced, and the manual run is still waiting beside it.
		$this->assertSame( 1, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );
		$this->assertCount( 2, $this->actions( Scheduler::ACTION_SYNC_STOCK ) );
	}

	/**
	 * Setting a job to Never keeps the manual run too.
	 *
	 * "Never" means the job stays manual, so throwing away the one manual run that was
	 * waiting is the opposite of what was asked for.
	 *
	 * @return void
	 */
	public function test_switching_a_job_to_never_keeps_a_pending_manual_run() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->store_interval( 'stock_sync_interval', Settings::INTERVAL_NEVER );
		$scheduler->sync_schedules();

		$this->assertSame( 0, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );
		$this->assertCount(
			1,
			$this->actions( Scheduler::ACTION_SYNC_STOCK ),
			'the manual run should have survived the schedule being cancelled'
		);
	}

	/**
	 * Re-queueing still moves the schedule onto the new interval.
	 *
	 * The point of cancelling at all: a job left with its old recurring action would
	 * go on running at the interval that was just changed.
	 *
	 * @return void
	 */
	public function test_rescheduling_moves_the_job_onto_its_new_interval() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		$first = Scheduler::next_run( 'stock' );

		$this->store_interval( 'stock_sync_interval', DAY_IN_SECONDS );
		$scheduler->reschedule();

		$this->assertSame( 1, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );
		$this->assertGreaterThan( $first, Scheduler::next_run( 'stock' ) );
	}

	/**
	 * The plural lookup answers for every job, and does not re-scan the queue.
	 *
	 * Reporting when a job is next due is a scan rather than a lookup, so the progress
	 * poll calling it per job was around a hundred row reads every five seconds to
	 * redraw a timestamp that moves once an interval.
	 *
	 * @return void
	 */
	public function test_next_runs_answers_for_every_job_from_one_read() {
		as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$runs = Scheduler::next_runs();

		$this->assertSame( array_keys( Scheduler::get_jobs() ), array_keys( $runs ) );
		$this->assertSame( Scheduler::next_run( 'stock' ), $runs['stock'] );
		$this->assertSame( 0, $runs['products'] );

		// Queued behind its back, and not seen: the answer is the cached one until the
		// queue is touched through sync_schedules() or the minute is up.
		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, Scheduler::ACTION_SYNC_PRODUCTS, array(), Scheduler::GROUP );

		$this->assertSame( 0, Scheduler::next_runs()['products'] );

		Scheduler::forget_next_runs();

		$this->assertGreaterThan( 0, Scheduler::next_runs()['products'] );
	}

	/**
	 * Re-queueing the schedules drops the cached times with them.
	 *
	 * Otherwise a shop that had just changed an interval would read the old one back
	 * for a minute, on the very screen it changed it from.
	 *
	 * @return void
	 */
	public function test_reconciling_the_queue_forgets_the_cached_times() {
		update_option(
			Settings::OPTION_KEY,
			array_merge( Settings::default_settings(), array( 'stock_sync_interval' => HOUR_IN_SECONDS ) )
		);

		( new Scheduler() )->sync_schedules();

		$this->assertGreaterThan( 0, Scheduler::next_runs()['stock'] );
	}

	/**
	 * The actions queued against one hook.
	 *
	 * @param string $hook   Action hook.
	 * @param string $status Action status.
	 * @return array List of action ids.
	 */
	private function actions( $hook, $status = ActionScheduler_Store::STATUS_PENDING ) {
		return array_values(
			(array) ActionScheduler::store()->query_actions(
				array(
					'hook'     => $hook,
					'group'    => Scheduler::GROUP,
					'status'   => $status,
					'per_page' => 50,
				)
			)
		);
	}

	/**
	 * How many of a hook's queued actions repeat.
	 *
	 * @param string $hook Action hook.
	 * @return int Count of recurring actions.
	 */
	private function recurring_count( $hook ) {
		$store = ActionScheduler::store();
		$count = 0;

		foreach ( $this->actions( $hook ) as $id ) {
			$action = $store->fetch_action( $id );

			if ( $action && $action->get_schedule() && $action->get_schedule()->is_recurring() ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * A manual run waiting in the queue is not a schedule.
	 *
	 * This is the whole defect. Run now enqueues an async action, which
	 * `as_next_scheduled_action()` reports as a bare `true` — indistinguishable from
	 * an action already executing — so the reconciliation used to skip the job and
	 * the interval quietly stopped applying.
	 *
	 * @return void
	 */
	public function test_a_pending_manual_run_does_not_stand_in_for_the_schedule() {
		$this->store_interval( 'stock_sync_interval', 900 );

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertTrue(
			(bool) as_next_scheduled_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP ),
			'the manual run should be visible to Action Scheduler'
		);
		$this->assertFalse( Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ) );

		( new Scheduler() )->sync_schedules();

		$this->assertSame(
			1,
			$this->recurring_count( Scheduler::ACTION_SYNC_STOCK ),
			'the interval should have been queued despite the manual run'
		);
		$this->assertCount(
			2,
			$this->actions( Scheduler::ACTION_SYNC_STOCK ),
			'the manual run should still be waiting alongside it'
		);
	}

	/**
	 * The schedule the screen reports is the schedule, not a manual run.
	 *
	 * `as_next_scheduled_action()` answers `true` for a pending async action, and the
	 * cast to int made that a next run of 1 — which the admin screen rendered as
	 * 1 January 1970 and the REST API published as a timestamp in the past.
	 *
	 * @return void
	 */
	public function test_next_run_ignores_a_manual_run() {
		$this->store_interval( 'stock_sync_interval', Settings::INTERVAL_NEVER );

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertSame( 0, Scheduler::next_run( 'stock' ) );
	}

	/**
	 * A queued interval is reported as the time it is due.
	 *
	 * @return void
	 */
	public function test_next_run_reports_the_queued_interval() {
		$this->store_interval( 'stock_sync_interval', 900 );

		( new Scheduler() )->sync_schedules();

		$next = Scheduler::next_run( 'stock' );

		$this->assertGreaterThan( time(), $next );
		$this->assertLessThanOrEqual( time() + 900, $next );
	}

	/**
	 * Reconciling twice does not queue the interval twice.
	 *
	 * @return void
	 */
	public function test_an_existing_schedule_is_left_alone() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		$first = Scheduler::next_run( 'stock' );

		$scheduler->sync_schedules();

		$this->assertSame( 1, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );
		$this->assertSame( $first, Scheduler::next_run( 'stock' ) );
	}

	/**
	 * A schedule that is executing is not queued a second time.
	 *
	 * Action Scheduler queues the next occurrence only once the current one has
	 * finished, so for the length of a run a recurring job has no pending action at
	 * all. Reading that as unscheduled would leave the shop with two recurring
	 * actions the moment the run ended.
	 *
	 * @return void
	 */
	public function test_a_schedule_that_is_running_is_not_queued_again() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		$queued = $this->actions( Scheduler::ACTION_SYNC_STOCK );

		$this->assertCount( 1, $queued );

		ActionScheduler::store()->log_execution( (int) $queued[0] );

		$this->assertSame(
			ActionScheduler_Store::STATUS_RUNNING,
			ActionScheduler::store()->get_status( (int) $queued[0] ),
			'the action should be in progress for this to test anything'
		);
		$this->assertTrue( Scheduler::has_recurring( Scheduler::ACTION_SYNC_STOCK ) );

		$scheduler->sync_schedules();

		$this->assertCount(
			0,
			$this->actions( Scheduler::ACTION_SYNC_STOCK ),
			'nothing new should have been queued while the run is under way'
		);
	}

	/**
	 * Never still takes the schedule out of the queue.
	 *
	 * @return void
	 */
	public function test_never_cancels_a_queued_schedule() {
		$this->store_interval( 'stock_sync_interval', 900 );

		$scheduler = new Scheduler();
		$scheduler->sync_schedules();

		$this->assertSame( 1, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );

		$this->store_interval( 'stock_sync_interval', Settings::INTERVAL_NEVER );
		$scheduler->sync_schedules();

		$this->assertSame( 0, $this->recurring_count( Scheduler::ACTION_SYNC_STOCK ) );
		$this->assertSame( 0, Scheduler::next_run( 'stock' ) );
	}

	/**
	 * Never leaves a manual run alone rather than cancelling it.
	 *
	 * Somebody pressed Run now; a schedule reconciliation deciding the job has no
	 * interval says nothing about whether they still want that run.
	 *
	 * @return void
	 */
	public function test_never_does_not_cancel_a_manual_run() {
		$this->store_interval( 'stock_sync_interval', Settings::INTERVAL_NEVER );

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		( new Scheduler() )->sync_schedules();

		$this->assertCount( 1, $this->actions( Scheduler::ACTION_SYNC_STOCK ) );
	}
}
