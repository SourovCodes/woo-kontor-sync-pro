<?php
/**
 * Tests for the three places a broken sync becomes visible.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Admin\Health;
use WooKontorSync\Admin\Notices;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Admin\SiteHealth;
use WooKontorSync\Admin\StatusReport;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers Health, the admin notice, the system status section and the Site Health tests.
 */
class HealthTest extends WP_UnitTestCase {

	/**
	 * A user who may manage the shop.
	 *
	 * @var int
	 */
	private $manager = 0;

	/**
	 * Set up a configured shop with a manager logged in.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->manager = $this->factory->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->manager );

		Health::forget_schedules();

		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::default_settings(),
				array(
					'api_base_url' => 'https://erp.example.test/api/v1/kontor',
					'api_key'      => 'test-key-123',

					// Orders default to on, so a shop with none chosen is legitimately
					// incomplete. A fully configured one is the baseline these tests want.
					'shop_id'      => '3f2504e0-4f89-11d3-9a0c-0305e82c3301',
					'shop_name'    => 'Test shop',
				)
			)
		);
	}

	/**
	 * A failed job is a problem, and its own message is what it reports.
	 *
	 * @return void
	 */
	public function test_a_failed_job_is_reported_with_its_reason() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor listed 300 articles, down from 4386.' );

		$problems = Health::problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( ProductSync::JOB, $problems[0]['job'] );
		$this->assertSame( Health::FAILED, $problems[0]['kind'] );
		$this->assertStringContainsString( '300', $problems[0]['message'] );
	}

	/**
	 * A run stuck past STALE_AFTER is reported, and not as a failure.
	 *
	 * Nothing closed it, so nothing wrote a reason. Reporting it as a failure would put
	 * an empty message in front of somebody as though it were an explanation.
	 *
	 * @return void
	 */
	public function test_a_stranded_run_is_reported_as_stale() {
		$this->strand( ProductSync::JOB );

		$problems = Health::problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( Health::STALE, $problems[0]['kind'] );
		$this->assertNotSame( '', $problems[0]['message'] );
	}

	/**
	 * A job that succeeded is not a problem.
	 *
	 * @return void
	 */
	public function test_a_healthy_shop_reports_nothing() {
		Status::start( ProductSync::JOB );
		Status::finish( ProductSync::JOB, 'All good.' );

		$this->assertSame( array(), Health::problems() );
	}

	/**
	 * An interval with nothing queued to run it is reported.
	 *
	 * The failure nothing else in wp-admin would show: the settings screen reads the
	 * interval out of the settings and calls it configured, whatever the queue holds.
	 *
	 * @return void
	 */
	public function test_an_interval_with_no_recurring_action_is_reported() {
		$this->set_setting( 'product_sync_interval', DAY_IN_SECONDS );

		/*
		 * Saving an interval queues its recurring action, which is the whole point of
		 * the reconciliation — so the state being tested is the one where that queue
		 * entry later went missing on its own, with the setting still saying otherwise.
		 * A live shop reached it through a guard left standing by a request that died.
		 */
		as_unschedule_all_actions( Scheduler::ACTION_SYNC_PRODUCTS, array(), Scheduler::GROUP );
		Health::forget_schedules();

		$problems = Health::problems();

		$this->assertCount( 1, $problems );
		$this->assertSame( Health::UNSCHEDULED, $problems[0]['kind'] );
		$this->assertContains( ProductSync::JOB, Health::unscheduled() );
	}

	/**
	 * A job set to Never is not unscheduled, it is switched off.
	 *
	 * Never is a legitimate choice on every schedule here, and the job stays manual.
	 *
	 * @return void
	 */
	public function test_never_is_not_a_missing_schedule() {
		$this->set_setting( 'product_sync_interval', Settings::INTERVAL_NEVER );

		$this->assertSame( array(), Health::unscheduled() );
	}

	/**
	 * A queued schedule satisfies the check.
	 *
	 * @return void
	 */
	public function test_a_queued_schedule_is_not_reported() {
		$this->set_setting( 'product_sync_interval', DAY_IN_SECONDS );

		as_schedule_recurring_action(
			time() + DAY_IN_SECONDS,
			DAY_IN_SECONDS,
			Scheduler::ACTION_SYNC_PRODUCTS,
			array(),
			Scheduler::GROUP
		);

		$this->assertSame( array(), Health::unscheduled() );
	}

	/**
	 * A shop that does not exchange orders is not scolded about the order jobs.
	 *
	 * @return void
	 */
	public function test_the_order_jobs_are_left_out_when_orders_are_off() {
		$this->set_setting( Settings::SYNC_ORDERS, false );

		Status::start( 'orders' );
		Status::fail( 'orders', 'No Kontor shop has been selected.' );

		$this->assertSame( array(), Health::problems() );
	}

	/**
	 * The notice prints on the dashboard, and names the job and the reason.
	 *
	 * @return void
	 */
	public function test_the_notice_prints_on_the_dashboard() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$html = $this->notice( 'dashboard' );

		$this->assertStringContainsString( 'Kontor Sync needs attention', $html );
		$this->assertStringContainsString( 'HTTP status 500', $html );

		// The two ways out of the notice, both of which used to be nowhere.
		$this->assertStringContainsString( 'page=wc-status', $html );
		$this->assertStringContainsString( 'page=' . Settings::PAGE_SLUG, $html );
	}

	/**
	 * The notice stays off the Kontor Sync screen, which says all this already.
	 *
	 * @return void
	 */
	public function test_the_notice_stays_off_its_own_settings_screen() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$this->assertSame( '', $this->notice( 'woocommerce_page_' . Settings::PAGE_SLUG ) );
	}

	/**
	 * The notice stays off screens that have nothing to do with the shop.
	 *
	 * @return void
	 */
	public function test_the_notice_stays_off_unrelated_screens() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$this->assertSame( '', $this->notice( 'post' ) );
	}

	/**
	 * Somebody who cannot manage the shop is not shown the notice.
	 *
	 * @return void
	 */
	public function test_the_notice_is_not_shown_to_a_subscriber() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', $this->notice( 'dashboard' ) );
	}

	/**
	 * Dismissing a problem puts it away.
	 *
	 * The fingerprints are taken out of the rendered link rather than recomputed, which
	 * is what proves the link carries what the handler needs.
	 *
	 * @return void
	 */
	public function test_a_dismissed_problem_stays_dismissed() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$notices = new Notices();
		$notices->dismiss( $this->fingerprints_in( $this->notice( 'dashboard' ) ) );

		$this->assertSame( '', $this->notice( 'dashboard' ) );
	}

	/**
	 * The same failure happening again does not bring the notice back.
	 *
	 * A failing job records a new finish time every run — every fifteen minutes on the
	 * stock sync — so a dismissal keyed on the time would last until the next page load.
	 *
	 * @return void
	 */
	public function test_the_same_failure_recurring_stays_dismissed() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		( new Notices() )->dismiss( $this->fingerprints_in( $this->notice( 'dashboard' ) ) );

		// The next run fails the same way, at a different moment.
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$this->assertSame( '', $this->notice( 'dashboard' ) );
	}

	/**
	 * A different failure is a different notice.
	 *
	 * @return void
	 */
	public function test_a_new_failure_comes_back_after_a_dismissal() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		( new Notices() )->dismiss( $this->fingerprints_in( $this->notice( 'dashboard' ) ) );

		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'The Kontor API key has not been configured.' );

		$this->assertStringContainsString( 'has not been configured', $this->notice( 'dashboard' ) );
	}

	/**
	 * The status report never carries the API key.
	 *
	 * The report exists to be pasted into a support thread, which is the one place a
	 * credential must not end up.
	 *
	 * @return void
	 */
	public function test_the_status_report_never_prints_the_api_key() {
		$this->set_setting( 'api_key', 'sk-live-%5aSECRET-do-not-leak' );

		$html = $this->report();

		$this->assertStringNotContainsString( 'SECRET', $html );
		$this->assertStringNotContainsString( 'sk-live', $html );
		$this->assertStringContainsString( 'Kontor Sync', $html );
	}

	/**
	 * The status report says how each job is doing.
	 *
	 * @return void
	 */
	public function test_the_status_report_carries_each_job() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$html = $this->report();

		$this->assertStringContainsString( 'Product sync', $html );
		$this->assertStringContainsString( 'HTTP status 500', $html );

		// The measurement the drafting brake compares against, which is otherwise
		// invisible anywhere in wp-admin.
		$this->assertStringContainsString( 'Last catalogue size', $html );
	}

	/**
	 * The report calls a stranded run stranded, as the other two surfaces do.
	 *
	 * Reporting it as still running would have this row disagree with the notice and
	 * with Site Health about the same state.
	 *
	 * @return void
	 */
	public function test_the_status_report_calls_a_stranded_run_stranded() {
		$this->strand( ProductSync::JOB );

		$this->assertStringContainsString( 'stranded', $this->report() );
	}

	/**
	 * Site Health passes a shop with nothing wrong.
	 *
	 * @return void
	 */
	public function test_site_health_passes_a_working_shop() {
		$health = new SiteHealth();

		$this->assertSame( 'good', $health->test_configuration()['status'] );
		$this->assertSame( 'good', $health->test_jobs()['status'] );
	}

	/**
	 * A failing job is critical, because the shop is showing customers stale data.
	 *
	 * @return void
	 */
	public function test_site_health_calls_a_failing_job_critical() {
		Status::start( ProductSync::JOB );
		Status::fail( ProductSync::JOB, 'Kontor returned HTTP status 500.' );

		$result = ( new SiteHealth() )->test_jobs();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'Product sync', $result['description'] );
	}

	/**
	 * An unconfigured plugin is a recommendation, not a critical fault.
	 *
	 * Somebody has not finished, which is not the same as a site that is broken.
	 *
	 * @return void
	 */
	public function test_site_health_is_gentler_about_an_unconfigured_shop() {
		$this->set_setting( 'api_key', '' );

		$result = ( new SiteHealth() )->test_configuration();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'API key', $result['description'] );
	}

	/**
	 * A catalogue-only shop is never asked for a Kontor shop.
	 *
	 * @return void
	 */
	public function test_site_health_does_not_ask_a_catalogue_only_shop_for_a_shop_id() {
		$this->set_setting( Settings::SYNC_ORDERS, false );

		$this->assertSame( 'good', ( new SiteHealth() )->test_configuration()['status'] );
	}

	/**
	 * Both tests are registered as direct ones.
	 *
	 * An async test would put a round trip to Kontor in the middle of a page load, and
	 * neither of these needs the network at all.
	 *
	 * @return void
	 */
	public function test_the_tests_are_registered_without_touching_the_network() {
		$tests = ( new SiteHealth() )->add_tests(
			array(
				'direct' => array(),
				'async'  => array(),
			)
		);

		$this->assertArrayHasKey( 'wksync_configuration', $tests['direct'] );
		$this->assertArrayHasKey( 'wksync_jobs', $tests['direct'] );
		$this->assertSame( array(), $tests['async'] );
	}

	/**
	 * Render the notice on a given screen and hand back what it printed.
	 *
	 * @param string $screen_id Screen to render on.
	 * @return string Markup.
	 */
	private function notice( $screen_id ) {
		set_current_screen( $screen_id );

		ob_start();
		( new Notices() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * Render the system status section and hand back what it printed.
	 *
	 * @return string Markup.
	 */
	private function report() {
		ob_start();
		( new StatusReport() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * The fingerprints the notice's dismiss link is carrying.
	 *
	 * @param string $html Rendered notice.
	 * @return string[] Fingerprints.
	 */
	private function fingerprints_in( $html ) {
		if ( 1 !== preg_match( '/problems=([0-9a-f%2C,]+)/', $html, $matches ) ) {
			return array();
		}

		return explode( ',', str_replace( '%2C', ',', $matches[1] ) );
	}

	/**
	 * Change one setting, leaving the rest as they are.
	 *
	 * @param string $key   Setting key.
	 * @param mixed  $value New value.
	 * @return void
	 */
	private function set_setting( $key, $value ) {
		$settings         = Settings::get_settings();
		$settings[ $key ] = $value;

		update_option( Settings::OPTION_KEY, $settings );

		Health::forget_schedules();
	}

	/**
	 * Leave a job looking as though its chain died mid-run.
	 *
	 * @param string $job Job key.
	 * @return void
	 */
	private function strand( $job ) {
		Status::start( $job );

		$all                    = get_option( Status::OPTION_KEY );
		$all[ $job ]['started'] = time() - ( Status::STALE_AFTER + HOUR_IN_SECONDS );

		update_option( Status::OPTION_KEY, $all, false );
	}
}
