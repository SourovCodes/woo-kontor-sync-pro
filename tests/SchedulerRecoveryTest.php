<?php
/**
 * Tests for closing out runs whose queued work never finished.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use Exception;
use WooKontorSync\Activator;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Deactivator;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * A run only ever reports its outcome from inside its own chained actions. These
 * cover what happens when those actions are cancelled or die: the status has to be
 * closed by whoever destroyed the chain, or the job reads as running for the next
 * six hours and refuses to be started again.
 */
class SchedulerRecoveryTest extends WP_UnitTestCase {

	/**
	 * Start from an empty queue and no stored status.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_option( Status::OPTION_KEY );
		delete_transient( Scheduler::SCHEDULE_GUARD );

		as_unschedule_all_actions( '', array(), Scheduler::GROUP );
	}

	/**
	 * Leave nothing queued behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down() {
		as_unschedule_all_actions( '', array(), Scheduler::GROUP );

		parent::tear_down();
	}

	/**
	 * Queue an action belonging to a run.
	 *
	 * @param string $hook Action hook.
	 * @param array  $args Action arguments.
	 * @return int Action id.
	 */
	private function queue( $hook, array $args ) {
		return (int) as_schedule_single_action( time() + HOUR_IN_SECONDS, $hook, $args, Scheduler::GROUP );
	}

	/**
	 * Deactivating mid-run closes the run rather than leaving it in flight.
	 *
	 * Deactivation cancels every queued action, so nothing survives to report the
	 * outcome. Left alone the job reads as running until STALE_AFTER expires.
	 *
	 * @return void
	 */
	public function test_deactivating_closes_a_run_in_flight() {
		Status::start( ProductSync::JOB );

		Deactivator::deactivate();

		$status = Status::get( ProductSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertNotSame( '', $status['message'] );
		$this->assertFalse( Status::is_running( ProductSync::JOB ) );
	}

	/**
	 * Every in-flight job is closed, not only the first one found.
	 *
	 * @return void
	 */
	public function test_deactivating_closes_every_run_in_flight() {
		Status::start( ProductSync::JOB );
		Status::start( 'stock' );
		Status::finish( 'orders', 'Nothing to send.' );

		Deactivator::deactivate();

		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
		$this->assertSame( 'failed', Status::get( 'stock' )['state'] );

		// A job that had already finished keeps the outcome it reported.
		$this->assertSame( 'success', Status::get( 'orders' )['state'] );
	}

	/**
	 * A run that already reported its own outcome is left as it is.
	 *
	 * @return void
	 */
	public function test_deactivating_does_not_overwrite_a_finished_run() {
		Status::start( ProductSync::JOB );
		Status::finish( ProductSync::JOB, '4386 articles imported.' );

		Deactivator::deactivate();

		$this->assertSame( 'success', Status::get( ProductSync::JOB )['state'] );
		$this->assertSame( '4386 articles imported.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * Emptying the queue drops the guard that says the queue matches the settings.
	 *
	 * Otherwise a deactivate/reactivate cycle leaves no recurring actions at all
	 * until the guard expires, and a job set to fifteen minutes stops running for
	 * the best part of an hour without saying so.
	 *
	 * @return void
	 */
	public function test_emptying_the_queue_drops_the_schedule_guard() {
		set_transient( Scheduler::SCHEDULE_GUARD, 1, HOUR_IN_SECONDS );

		Scheduler::unschedule_all();

		$this->assertFalse( get_transient( Scheduler::SCHEDULE_GUARD ) );
	}

	/**
	 * Reactivating puts the recurring actions back.
	 *
	 * @return void
	 */
	public function test_activating_restores_the_recurring_actions() {
		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::default_settings(),
				array( 'stock_sync_interval' => 900 )
			)
		);

		Deactivator::deactivate();

		$this->assertFalse( as_next_scheduled_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP ) );

		Activator::activate();

		$this->assertNotFalse( as_next_scheduled_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP ) );
	}

	/**
	 * A job set to Never stays unscheduled when the plugin is activated.
	 *
	 * @return void
	 */
	public function test_activating_does_not_schedule_a_job_set_to_never() {
		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::default_settings(),
				array( 'product_sync_interval' => Settings::INTERVAL_NEVER )
			)
		);

		Activator::activate();

		$this->assertFalse( as_next_scheduled_action( Scheduler::ACTION_SYNC_PRODUCTS, array(), Scheduler::GROUP ) );
	}

	/**
	 * A page action that throws closes the run it was carrying.
	 *
	 * Action Scheduler does not retry an action that threw, so the chain ends there
	 * and nothing else will ever report the run.
	 *
	 * @return void
	 */
	public function test_a_failed_page_action_closes_the_run() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
			array(
				'skip' => 200,
				'run'  => $run,
			)
		);

		( new Scheduler() )->handle_failed_execution( $id, new Exception( 'Allowed memory size exhausted' ) );

		$status = Status::get( ProductSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'Allowed memory size exhausted', $status['message'] );
	}

	/**
	 * An action abandoned for running too long closes the run too.
	 *
	 * @return void
	 */
	public function test_a_timed_out_action_closes_the_run() {
		$run = Status::start( 'stock' );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_STOCK_CHUNK,
			array(
				'offset' => 250,
				'run'    => $run,
			)
		);

		( new Scheduler() )->handle_timed_out_action( $id );

		$this->assertSame( 'failed', Status::get( 'stock' )['state'] );
	}

	/**
	 * A fatal error inside an action closes the run.
	 *
	 * @return void
	 */
	public function test_an_unexpected_shutdown_closes_the_run() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_PRODUCTS_FINALISE,
			array( 'run' => $run )
		);

		( new Scheduler() )->handle_unexpected_shutdown( $id, array( 'message' => 'Call to a member function on null' ) );

		$status = Status::get( ProductSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'Call to a member function on null', $status['message'] );
	}

	/**
	 * A failed image download does not fail the product sync.
	 *
	 * Images outlive the run that queued them and the catalogue is already correct
	 * without them, so reporting the sync as failed because a photograph did not
	 * arrive would be wrong — and would do it after the run had reported success.
	 *
	 * @return void
	 */
	public function test_a_failed_image_action_does_not_close_the_run() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
			array(
				'product_id' => 42,
				'files'      => array( 'abel-AB12_001.jpg' ),
				'run'        => $run,
			)
		);

		( new Scheduler() )->handle_failed_execution( $id, new Exception( 'Connection timed out' ) );

		$this->assertSame( 'running', Status::get( ProductSync::JOB )['state'] );
	}

	/**
	 * A superseded action failing says nothing about the run that replaced it.
	 *
	 * @return void
	 */
	public function test_a_superseded_action_does_not_close_the_current_run() {
		$old = Status::start( ProductSync::JOB );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
			array(
				'skip' => 0,
				'run'  => $old,
			)
		);

		// A newer run takes over before the stale action gives up.
		$all                        = get_option( Status::OPTION_KEY );
		$all['products']['started'] = $old + 100;
		update_option( Status::OPTION_KEY, $all, false );

		( new Scheduler() )->handle_failed_execution( $id, new Exception( 'Boom' ) );

		$this->assertSame( 'running', Status::get( ProductSync::JOB )['state'] );
	}

	/**
	 * A failure arriving after the run reported itself does not rewrite the outcome.
	 *
	 * @return void
	 */
	public function test_a_failure_does_not_overwrite_a_reported_outcome() {
		$run = Status::start( ProductSync::JOB );
		$id  = $this->queue(
			Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
			array(
				'skip' => 0,
				'run'  => $run,
			)
		);

		Status::fail( ProductSync::JOB, 'Kontor is not configured.' );

		( new Scheduler() )->handle_failed_execution( $id, new Exception( 'Boom' ) );

		// The job's own reason is the useful one; the scheduler's is a fallback.
		$this->assertSame( 'Kontor is not configured.', Status::get( ProductSync::JOB )['message'] );
	}

	/**
	 * An action that is not part of a run is ignored.
	 *
	 * @return void
	 */
	public function test_an_unknown_action_is_ignored() {
		Status::start( ProductSync::JOB );

		( new Scheduler() )->handle_failed_execution( 0, new Exception( 'Boom' ) );
		( new Scheduler() )->handle_timed_out_action( 99999999 );

		$this->assertSame( 'running', Status::get( ProductSync::JOB )['state'] );
	}
}
