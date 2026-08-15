<?php
/**
 * Tests for the sync jobs over REST.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Rest\Jobs;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Covers the routes that start a sync and report on a run.
 *
 * The requests go through the real routes rather than calling the callbacks, because
 * half of what is worth proving belongs to WordPress: that the job parameter's enum
 * refuses a job this API does not serve, that a wrong method does not reach a callback
 * at all, and that an unauthenticated request is turned away before one runs.
 *
 * **There is no stubbed HTTP anywhere in this file, and none is needed.**
 * Scheduler::trigger() runs the local gates only — credentials present, not already
 * running — and never asks Kontor anything, so two settings keys are the whole of what a
 * successful trigger requires. Do not copy JobProgressTest's pre_http_request filter or
 * its connection transient in here; those belong to tests whose subject is a job
 * actually starting.
 */
class RestJobsTest extends WP_UnitTestCase {

	/**
	 * Act as someone allowed to manage WooCommerce.
	 *
	 * The REST server is left to build itself on the first request, so the routes arrive
	 * the way they do on a real site — through the hook Plugin::init() registered at load
	 * time — rather than through one this test wired up.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Leave no status, settings or queued actions behind.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( '', array(), Scheduler::GROUP );
		}

		parent::tear_down();
	}

	/**
	 * Store credentials good enough for a trigger to be accepted.
	 *
	 * @return void
	 */
	private function configure() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'api_base_url' => 'https://erp.example.test/api/v1/kontor',
				'api_key'      => 'test-key-123',
			)
		);
	}

	/**
	 * Dispatch a request against the plugin's namespace.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  Route below the namespace.
	 * @return \WP_REST_Response The response.
	 */
	private function request( $method, $route ) {
		return rest_do_request( new WP_REST_Request( $method, '/' . Jobs::REST_NAMESPACE . $route ) );
	}

	/**
	 * The status of one job, as the API reports it.
	 *
	 * @param string $job Job key.
	 * @return array Response data.
	 */
	private function fetch( $job ) {
		$response = $this->request( 'GET', '/jobs/' . $job );

		$this->assertSame( 200, $response->get_status(), 'The job route did not answer.' );

		return $response->get_data();
	}

	/**
	 * Whether an action is queued for one hook.
	 *
	 * @param string $hook Action hook.
	 * @return bool True when something is queued.
	 */
	private function is_queued( $hook ) {
		return as_has_scheduled_action( $hook, array(), Scheduler::GROUP );
	}

	/**
	 * All three routes are registered.
	 *
	 * @return void
	 */
	public function test_the_routes_are_registered() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/' . Jobs::REST_NAMESPACE, $routes, 'The namespace index is missing.' );
		$this->assertArrayHasKey( '/' . Jobs::REST_NAMESPACE . '/jobs', $routes, 'The collection route is missing.' );
		$this->assertArrayHasKey( '/' . Jobs::REST_NAMESPACE . '/jobs/(?P<job>[\w-]+)', $routes, 'The job route is missing.' );
		$this->assertArrayHasKey( '/' . Jobs::REST_NAMESPACE . '/jobs/(?P<job>[\w-]+)/run', $routes, 'The run route is missing.' );
	}

	/**
	 * The namespace keeps WooCommerce's authentication prefix.
	 *
	 * WC_REST_Authentication::is_request_to_rest_api() reads a consumer key only for a
	 * request URI containing "wc/" or "wc-". Renaming this namespace would break no test
	 * but this one, and every client holding a key.
	 *
	 * @return void
	 */
	public function test_the_namespace_keeps_woocommerces_authentication_prefix() {
		$this->assertStringStartsWith( 'wc-', Jobs::REST_NAMESPACE, 'The namespace no longer opts into WooCommerce key authentication.' );
	}

	/**
	 * Exactly the two jobs this API serves are listed.
	 *
	 * @return void
	 */
	public function test_the_collection_lists_the_two_jobs() {
		$response = $this->request( 'GET', '/jobs' );

		$this->assertSame( 200, $response->get_status(), 'The collection did not answer.' );

		$data = $response->get_data();

		$this->assertSame( array( 'products', 'stock' ), wp_list_pluck( $data['jobs'], 'job' ), 'The wrong jobs are served.' );
		$this->assertArrayHasKey( 'image_queue', $data, 'The image queue is missing from the collection.' );
	}

	/**
	 * A request without a login is refused.
	 *
	 * @return void
	 */
	public function test_an_anonymous_request_is_refused() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'GET', '/jobs' )->get_status(), 'An anonymous read was allowed.' );

		$response = $this->request( 'POST', '/jobs/products/run' );

		$this->assertSame( 401, $response->get_status(), 'An anonymous trigger was allowed.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'An anonymous trigger queued a sync.' );
	}

	/**
	 * A logged-in customer is refused.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_is_refused() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$response = $this->request( 'GET', '/jobs' );

		$this->assertSame( 403, $response->get_status(), 'A subscriber could read the jobs.' );
		$this->assertSame( 'wksync_rest_forbidden', $response->get_data()['code'], 'The refusal is not this plugin\'s.' );

		$this->assertSame( 403, $this->request( 'POST', '/jobs/stock/run' )->get_status(), 'A subscriber could trigger a sync.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_STOCK ), 'A subscriber\'s trigger queued a sync.' );
	}

	/**
	 * A shop manager may use the API.
	 *
	 * The capability is manage_woocommerce rather than manage_options, so running a sync
	 * is not reserved to an administrator.
	 *
	 * @return void
	 */
	public function test_a_shop_manager_may_use_the_api() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );

		$this->assertSame( 200, $this->request( 'GET', '/jobs' )->get_status(), 'A shop manager could not read the jobs.' );
	}

	/**
	 * A job this API does not serve is refused by the parameter, not by the scheduler.
	 *
	 * "orders" is a real job with a real action behind it, which is what makes it the
	 * case worth pinning: the enum is the only thing keeping it out.
	 *
	 * @return void
	 */
	public function test_a_job_outside_the_enum_is_refused() {
		$this->configure();

		foreach ( array( 'orders', 'delivery', 'invoices', 'nope' ) as $job ) {
			$read = $this->request( 'GET', '/jobs/' . $job );

			$this->assertSame( 400, $read->get_status(), sprintf( 'Reading "%s" was allowed.', $job ) );
			$this->assertSame( 'rest_invalid_param', $read->get_data()['code'], 'The parameter was not what refused it.' );

			$this->assertSame( 400, $this->request( 'POST', '/jobs/' . $job . '/run' )->get_status(), sprintf( 'Triggering "%s" was allowed.', $job ) );
		}

		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_ORDERS ), 'An unserved job was queued.' );
	}

	/**
	 * The run route takes a POST and nothing else.
	 *
	 * WordPress answers a method it has no handler for with 404 rest_no_route rather than
	 * 405, so that is what a client sees and what this asserts.
	 *
	 * @return void
	 */
	public function test_the_run_route_requires_a_post() {
		$this->configure();

		$response = $this->request( 'GET', '/jobs/products/run' );

		$this->assertSame( 404, $response->get_status(), 'A GET reached the run route.' );
		$this->assertSame( 'rest_no_route', $response->get_data()['code'], 'The refusal was not the route table\'s.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'A GET queued a sync.' );
	}

	/**
	 * A trigger queues the job it names, and says so.
	 *
	 * @return void
	 */
	public function test_a_trigger_queues_the_product_sync() {
		$this->configure();

		$response = $this->request( 'POST', '/jobs/products/run' );

		$this->assertSame( 202, $response->get_status(), 'The trigger was not accepted.' );

		$data = $response->get_data();

		$this->assertSame( 'products', $data['job'], 'The wrong job was reported.' );
		$this->assertSame( 0, $data['previous_run_id'], 'A job that has never run reported a previous run.' );
		$this->assertTrue( $data['progress']['queued'], 'The queued run was not reported as queued.' );
		$this->assertTrue( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'Nothing was queued.' );
	}

	/**
	 * The job named is the job queued.
	 *
	 * @return void
	 */
	public function test_a_trigger_queues_only_the_job_it_names() {
		$this->configure();

		$this->assertSame( 202, $this->request( 'POST', '/jobs/stock/run' )->get_status(), 'The stock trigger was not accepted.' );

		$this->assertTrue( $this->is_queued( Scheduler::ACTION_SYNC_STOCK ), 'The stock sync was not queued.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'The product sync was queued as well.' );
	}

	/**
	 * A job already running is a conflict, and nothing is queued behind it.
	 *
	 * @return void
	 */
	public function test_a_running_job_answers_with_a_conflict() {
		$this->configure();
		Status::start( 'products' );

		$response = $this->request( 'POST', '/jobs/products/run' );

		$this->assertSame( 409, $response->get_status(), 'A second run was not refused as a conflict.' );
		$this->assertSame( 'wksync_already_running', $response->get_data()['code'], 'The scheduler\'s own code did not survive.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'A refused trigger queued a sync anyway.' );
	}

	/**
	 * A run that died holds the job for six hours and no longer.
	 *
	 * The stale run still reads as "running" — that is what it recorded — while `running`
	 * says it is not to be believed, which is the pair of answers that tells a client why
	 * a new trigger was accepted.
	 *
	 * @return void
	 */
	public function test_a_stale_run_does_not_block_a_new_one() {
		$this->configure();
		Status::start( 'products' );

		$all                        = get_option( Status::OPTION_KEY );
		$all['products']['started'] = time() - ( Status::STALE_AFTER + 60 );
		update_option( Status::OPTION_KEY, $all, false );

		$data = $this->fetch( 'products' );

		$this->assertSame( 'running', $data['state'], 'The stale run stopped reporting what it recorded.' );
		$this->assertFalse( $data['running'], 'A stale run was reported as in flight.' );

		$this->assertSame( 202, $this->request( 'POST', '/jobs/products/run' )->get_status(), 'A stale run blocked a new one.' );
	}

	/**
	 * An unconfigured shop is told so, rather than left with a queued sync.
	 *
	 * @return void
	 */
	public function test_an_unconfigured_shop_cannot_trigger_a_sync() {
		$response = $this->request( 'POST', '/jobs/products/run' );

		$this->assertSame( 503, $response->get_status(), 'A sync was accepted without credentials.' );
		$this->assertSame( 'wksync_not_configured', $response->get_data()['code'], 'The reason was not reported.' );
		$this->assertFalse( $this->is_queued( Scheduler::ACTION_SYNC_PRODUCTS ), 'An unconfigured trigger queued a sync.' );
	}

	/**
	 * A run nobody has measured reports no percentage at all.
	 *
	 * Null and zero are different claims, and a client rendering a bar has to be able to
	 * tell them apart, so the null has to survive JSON encoding as a null.
	 *
	 * @return void
	 */
	public function test_an_unmeasured_run_reports_no_percentage() {
		Status::start( 'products' );

		$data = $this->fetch( 'products' );

		$this->assertTrue( $data['running'], 'The run was not reported as in flight.' );
		$this->assertNull( $data['percent'], 'An unmeasurable run reported a percentage.' );
		$this->assertSame( 0, $data['total'], 'An unmeasured run claimed to know its total.' );
		$this->assertStringContainsString( '"percent":null', wp_json_encode( $data ), 'The percentage did not encode as null.' );
	}

	/**
	 * A measured run reports how far through it is.
	 *
	 * @return void
	 */
	public function test_a_measured_run_reports_its_progress() {
		Status::start( 'stock' );
		Status::measure( 'stock', 400 );
		Status::advance( 'stock', 100 );
		Status::progress( 'stock', array( 'updated' => 100 ) );

		$data = $this->fetch( 'stock' );

		$this->assertSame( 25, $data['percent'], 'The percentage is wrong.' );
		$this->assertSame( 400, $data['total'], 'The total is wrong.' );
		$this->assertSame( 100, $data['processed'], 'The processed count is wrong.' );
		$this->assertSame( 100, $data['counts']->updated, 'The outcome counts are wrong.' );
	}

	/**
	 * The run identifier and the time it started are separate fields.
	 *
	 * They hold the same number today, which is exactly why they are published apart: an
	 * identifier is compared, and a client that parsed this one as a clock would be
	 * relying on how Status happens to mint it.
	 *
	 * @return void
	 */
	public function test_the_run_identifier_is_published_apart_from_the_time() {
		$never = $this->fetch( 'products' );

		$this->assertSame( 0, $never['run_id'], 'A job that has never run reported a run.' );
		$this->assertNull( $never['started_gmt'], 'A job that has never run reported a start time.' );
		$this->assertNull( $never['finished_gmt'], 'A job that has never run reported an end time.' );

		$run  = Status::start( 'products' );
		$data = $this->fetch( 'products' );

		$this->assertSame( $run, $data['run_id'], 'The run identifier is not the run.' );
		$this->assertSame( gmdate( 'Y-m-d\TH:i:s', $run ), $data['started_gmt'], 'The start time is not the run in UTC.' );
		$this->assertNull( $data['finished_gmt'], 'A run in flight reported an end time.' );
	}

	/**
	 * Whether a run is queued follows the queue itself.
	 *
	 * @return void
	 */
	public function test_queued_follows_the_queue() {
		$this->assertFalse( $this->fetch( 'stock' )['queued'], 'An empty queue reported a queued run.' );

		as_enqueue_async_action( Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertTrue( $this->fetch( 'stock' )['queued'], 'A queued run was not reported.' );
		$this->assertFalse( $this->fetch( 'products' )['queued'], 'One job\'s queued run was reported against another.' );

		as_unschedule_all_actions( '', array(), Scheduler::GROUP );

		$this->assertFalse( $this->fetch( 'stock' )['queued'], 'An emptied queue still reported a queued run.' );
	}

	/**
	 * A schedule is not a queued run.
	 *
	 * Every job with an interval keeps a recurring action in the queue permanently, so
	 * counting whatever is pending would answer "queued" on every scheduled shop for
	 * ever, and a caller watching for its own trigger to be dealt with would wait for
	 * something that never happens.
	 *
	 * @return void
	 */
	public function test_a_run_scheduled_for_later_is_not_queued_now() {
		as_schedule_recurring_action( time() + DAY_IN_SECONDS, DAY_IN_SECONDS, Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertFalse( $this->fetch( 'stock' )['queued'], 'A run due tomorrow was reported as queued now.' );

		// One that is overdue is a different matter: it is about to run, and it will
		// stamp a run of its own.
		as_schedule_single_action( time() - HOUR_IN_SECONDS, Scheduler::ACTION_SYNC_STOCK, array(), Scheduler::GROUP );

		$this->assertTrue( $this->fetch( 'stock' )['queued'], 'An overdue run was not reported as queued.' );
	}

	/**
	 * An idle job's counts encode as an object, not as a list.
	 *
	 * @return void
	 */
	public function test_empty_counts_encode_as_an_object() {
		$this->assertStringContainsString(
			'"counts":{}',
			wp_json_encode( $this->fetch( 'products' ) ),
			'An idle job handed back a list where a measured one hands back an object.'
		);
	}

	/**
	 * Images still downloading do not make a finished run look unfinished.
	 *
	 * They outlive the run that queued them, which is why they are counted beside the
	 * jobs rather than inside one: a product sync with pictures still arriving is
	 * complete, because the catalogue is right.
	 *
	 * @return void
	 */
	public function test_the_image_queue_is_not_folded_into_the_run() {
		Status::start( 'products' );
		Status::measure( 'products', 10 );
		Status::advance( 'products', 10 );
		Status::finish( 'products', 'Imported 10 articles.' );

		as_enqueue_async_action(
			Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
			array(
				'product_id' => 1,
				'files'      => array(),
				'run'        => 1,
			),
			Scheduler::GROUP
		);

		$job = $this->fetch( 'products' );

		$this->assertSame( 'success', $job['state'], 'The run did not report success.' );
		$this->assertSame( 100, $job['percent'], 'The run did not report as complete.' );
		$this->assertArrayNotHasKey( 'image_queue', $job, 'The image queue leaked into the job.' );

		$collection = $this->request( 'GET', '/jobs' )->get_data();

		$this->assertGreaterThan( 0, $collection['image_queue'], 'The image queue was not counted.' );
	}

	/**
	 * The routes describe themselves.
	 *
	 * The schema is the only place a client discovering the API learns that a percentage
	 * can be null and that the outcome counts have no fixed keys.
	 *
	 * @return void
	 */
	public function test_the_job_route_publishes_a_schema() {
		/*
		 * An artefact of the test harness, not of the routes. WordPress answers OPTIONS
		 * from `rest_handle_options_request` on `rest_pre_dispatch`, which core adds on
		 * `rest_api_init` — and that fires inside whichever test dispatches first, so the
		 * suite's hook backup, taken before it, no longer carries the filter by the time
		 * this test runs. The REST server itself survives, so the route is there and only
		 * the OPTIONS handler is missing. Putting it back is what makes this assert the
		 * same thing a real OPTIONS request would.
		 */
		add_filter( 'rest_pre_dispatch', 'rest_handle_options_request', 10, 3 );

		$response = $this->request( 'OPTIONS', '/jobs/products' );

		$this->assertSame( 200, $response->get_status(), 'The route did not describe itself.' );

		$data = $response->get_data();

		$this->assertSame( array( 'integer', 'null' ), $data['schema']['properties']['percent']['type'], 'The nullable percentage is not published.' );
		$this->assertSame( 'object', $data['schema']['properties']['counts']['type'], 'The outcome counts are not published as an object.' );
		$this->assertSame( array( 'products', 'stock' ), $data['endpoints'][0]['args']['job']['enum'], 'The served jobs are not published.' );
	}
}
