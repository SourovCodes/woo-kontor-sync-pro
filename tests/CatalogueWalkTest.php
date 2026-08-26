<?php
/**
 * Tests for how far the catalogue walk goes, and what it is allowed to draft.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ActionScheduler;
use ActionScheduler_Store;
use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the three ways a run can end early and take the shop with it.
 *
 * The walk deciding where the catalogue stops, a transient failure ending a run that
 * will not be tried again for weeks, and finalise() drafting the difference between a
 * complete catalogue and a partial one.
 */
class CatalogueWalkTest extends WP_UnitTestCase {

	/**
	 * Requests the fake transport has answered.
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Start each test with no stored catalogue measurement.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->requests = array();

		delete_option( ProductSync::CATALOGUE_OPTION );
	}

	/**
	 * Drop the fake transport.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * The walk keeps going past a totalCount that says it should have stopped.
	 *
	 * This is the one that takes a shop down. totalCount defaults to zero when the
	 * field is absent, so the walk used to end after its first page and hand every
	 * article it never read to finalise() as one Kontor had dropped.
	 *
	 * @return void
	 */
	public function test_the_walk_ignores_a_total_that_says_the_catalogue_has_ended() {
		$this->serve( array( $this->page( 3 ), $this->page( 2 ), $this->page( 0 ) ), 0 );

		$run  = Status::start( ProductSync::JOB );
		$sync = $this->sync();

		$sync->import_page( 0, $run );
		$this->assertQueued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE, 'the walk stopped on the first page' );

		$sync->import_page( 3, $run );
		$sync->import_page( 5, $run );

		// Every article was read, and only the empty page ended the walk.
		$this->assertSame( 5, Status::get( ProductSync::JOB )['processed'] );
		$this->assertQueued( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, 'the empty page did not end the walk' );
	}

	/**
	 * A page that comes back short is followed by another, not treated as the end.
	 *
	 * The API caps its own page size, and skip advances by the rows actually returned,
	 * so a short page mid-catalogue is a shape this has to survive.
	 *
	 * @return void
	 */
	public function test_a_short_page_is_not_the_end_of_the_catalogue() {
		$this->serve( array( $this->page( 1 ) ), 4386 );

		$run = Status::start( ProductSync::JOB );
		$this->sync()->import_page( 0, $run );

		$this->assertQueued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE, 'a short page ended the walk' );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE );
	}

	/**
	 * A transient failure is waited out rather than ending the run.
	 *
	 * @return void
	 */
	public function test_a_transient_failure_queues_the_page_again() {
		$this->serve_failure( 503 );

		$run = Status::start( ProductSync::JOB );
		$this->sync()->import_page( 200, $run );

		// The run is still in flight, so nothing else may start one over the top of it.
		$this->assertSame( 'running', Status::get( ProductSync::JOB )['state'] );

		$queued = $this->fetch_queued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE );

		$this->assertNotNull( $queued, 'the failed page was not queued again' );
		$this->assertSame(
			array(
				'skip'    => 200,
				'run'     => $run,
				'attempt' => 2,
			),
			$queued->get_args()
		);

		// Waited out rather than tried again on the next queue pass.
		$this->assertGreaterThan( time() + 60, (int) $queued->get_schedule()->get_date()->format( 'U' ) );
	}

	/**
	 * A refusal Kontor will make again is not retried.
	 *
	 * The Client calls a 4xx final, and asking a bad key again in five minutes is only
	 * a slower way of writing the same message an hour later.
	 *
	 * @return void
	 */
	public function test_a_final_refusal_fails_the_run_at_once() {
		$this->serve_failure( 401 );

		$run = Status::start( ProductSync::JOB );
		$this->sync()->import_page( 0, $run );

		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE );
	}

	/**
	 * The retries are bounded, and running out of them fails the run.
	 *
	 * @return void
	 */
	public function test_the_retries_run_out() {
		$this->serve_failure( 503 );

		$run     = Status::start( ProductSync::JOB );
		$attempt = count( ProductSync::PAGE_RETRY_DELAYS ) + 1;

		$this->sync()->import_page( 0, $run, $attempt );

		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE );
		$this->assertStringContainsString(
			(string) $attempt,
			Status::get( ProductSync::JOB )['message'],
			'the failure does not say how many attempts were made'
		);
	}

	/**
	 * A catalogue that came back a fraction of its usual size drafts nothing.
	 *
	 * @return void
	 */
	public function test_a_shrunken_catalogue_drafts_nothing() {
		$this->remember( 4386 );
		$product = $this->synced_product( 'abel-AB12', 1000 );

		$run = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 300 );

		$this->sync()->finalise( $run );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE );
	}

	/**
	 * A second run reading the same small catalogue is believed.
	 *
	 * A shrink Kontor really made costs one run's delay and then goes through on its
	 * own. Nothing has to be switched off, and nobody has to be at the keyboard.
	 *
	 * @return void
	 */
	public function test_a_second_run_confirms_the_shrink() {
		$this->remember( 4386 );
		$product = $this->synced_product( 'abel-AB12', 1000 );

		$first = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 300 );
		$this->sync()->finalise( $first );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );

		$second = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 305 );
		$this->sync()->finalise( $second );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
		$this->assertSame( 1, (int) wc_get_product( $product )->get_meta( ProductSync::META_SYNC_DRAFTED ) );

		// The confirmed size is settled at once, so the batches after this one take the
		// ordinary path rather than re-deriving the confirmation for every two hundred
		// products they draft.
		$this->assertSame( 305, (int) get_option( ProductSync::CATALOGUE_OPTION )['size'] );
		$this->assertSame( 0, (int) get_option( ProductSync::CATALOGUE_OPTION )['braked'] );
	}

	/**
	 * A run that shrank further still is held back again rather than believed.
	 *
	 * The confirmation is two readings agreeing, not simply a second attempt.
	 *
	 * @return void
	 */
	public function test_a_further_shrink_is_held_back_again() {
		$this->remember( 4386 );
		$product = $this->synced_product( 'abel-AB12', 1000 );

		$first = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 3000 );
		$this->sync()->finalise( $first );

		$second = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 4 );
		$this->sync()->finalise( $second );

		$this->assertSame( 'publish', wc_get_product( $product )->get_status() );
		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
	}

	/**
	 * A complete run drafts as it always did, and records what it read.
	 *
	 * @return void
	 */
	public function test_a_complete_catalogue_drafts_and_is_remembered() {
		$this->remember( 4386 );
		$product = $this->synced_product( 'abel-AB12', 1000 );

		$run = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 4390 );

		$this->sync()->finalise( $run );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
		$this->assertSame( 4390, (int) get_option( ProductSync::CATALOGUE_OPTION )['size'] );
		$this->assertSame( 0, (int) get_option( ProductSync::CATALOGUE_OPTION )['braked'] );
	}

	/**
	 * The very first run has nothing to compare against and is not held back.
	 *
	 * @return void
	 */
	public function test_the_first_run_is_never_held_back() {
		$product = $this->synced_product( 'abel-AB12', 1000 );

		$run = Status::start( ProductSync::JOB );
		Status::advance( ProductSync::JOB, 1 );

		$this->sync()->finalise( $run );

		$this->assertSame( 'draft', wc_get_product( $product )->get_status() );
	}

	/**
	 * Narrowing the manufacturer filter clears the measurement it would trip.
	 *
	 * It is the one thing a shop can do that legitimately takes a fifth of the
	 * catalogue away in one run, and it is documented as doing exactly that.
	 *
	 * @return void
	 */
	public function test_changing_the_manufacturer_filter_forgets_the_catalogue_size() {
		$this->remember( 4386 );

		ProductSync::forget_catalogue_size(
			array( 'manufacturer_ids' => array( '084', '104' ) ),
			array( 'manufacturer_ids' => array( '104' ) )
		);

		$this->assertFalse( get_option( ProductSync::CATALOGUE_OPTION ) );
	}

	/**
	 * Saving something else leaves the measurement alone.
	 *
	 * @return void
	 */
	public function test_saving_an_unrelated_setting_keeps_the_catalogue_size() {
		$this->remember( 4386 );

		ProductSync::forget_catalogue_size(
			array(
				'manufacturer_ids' => array( '104', '084' ),
				'shoptype'         => 'B2B',
			),
			array(
				'manufacturer_ids' => array( '084', '104' ),
				'shoptype'         => 'B2C',
			)
		);

		$this->assertSame( 4386, (int) get_option( ProductSync::CATALOGUE_OPTION )['size'] );
	}

	/**
	 * A walk that never ends is stopped without drafting anything.
	 *
	 * A pager that ignored skip would otherwise run for ever. Stopping it is only half
	 * the answer: the run must fail rather than finalise, or the articles it never
	 * reached are drafted on the strength of a walk that did not finish.
	 *
	 * @return void
	 */
	public function test_a_walk_that_never_ends_is_stopped_short_of_drafting() {
		$run = Status::start( ProductSync::JOB );

		$this->sync()->import_page( ProductSync::MAX_PAGES * 200, $run );

		$this->assertSame( 'failed', Status::get( ProductSync::JOB )['state'] );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE );
		$this->assertNotQueued( Scheduler::ACTION_SYNC_PRODUCTS_PAGE );
	}

	/**
	 * A product sync configured for a retail shop that downloads no images.
	 *
	 * @return ProductSync
	 */
	private function sync() {
		return new ProductSync(
			null,
			array(
				'api_base_url'   => 'https://erp.example.test/api/v1/kontor',
				'api_key'        => 'test-key-123',
				'image_base_url' => '',
				'shoptype'       => 'B2C',
			)
		);
	}

	/**
	 * A product this plugin imported on an earlier run.
	 *
	 * @param string $sku Article number.
	 * @param int    $run Run that imported it.
	 * @return int Product ID.
	 */
	private function synced_product( $sku, $run ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Abel blocks 12' );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, $run );
		$product->save();

		return $product->get_id();
	}

	/**
	 * Record a catalogue size as though a previous run had completed.
	 *
	 * @param int $size Articles that run read.
	 * @return void
	 */
	private function remember( $size ) {
		update_option(
			ProductSync::CATALOGUE_OPTION,
			array(
				'size'   => (int) $size,
				'braked' => 0,
			),
			false
		);
	}

	/**
	 * A page of article rows.
	 *
	 * @param int $rows How many articles.
	 * @return array Article rows.
	 */
	private function page( $rows ) {
		$articles = array();

		for ( $index = 1; $index <= $rows; $index++ ) {
			$articles[] = array(
				'Artnr'        => 'abel-AB' . wp_rand( 1000, 999999 ),
				'Bez1'         => 'Article ' . $index,
				'UVP'          => 10.0,
				'Lagerbestand' => 5,
			);
		}

		return $articles;
	}

	/**
	 * Answer each successive request with the next page in the list.
	 *
	 * The last page is repeated once the list runs out, so a walk that asks for more
	 * than it was given fails on an assertion rather than on a transport error.
	 *
	 * @param array $pages List of article-row lists.
	 * @param int   $total Total the API claims, whatever the pages actually hold.
	 * @return void
	 */
	private function serve( array $pages, $total ) {
		$index = 0;

		add_filter(
			'pre_http_request',
			function () use ( $pages, $total, &$index ) {
				$rows = isset( $pages[ $index ] ) ? $pages[ $index ] : end( $pages );
				++$index;

				return $this->envelope(
					array(
						'success' => true,
						'message' => '',
						'meta'    => array(
							'rowCount'   => count( $rows ),
							'totalCount' => (int) $total,
						),
						'data'    => $rows,
					),
					200
				);
			}
		);
	}

	/**
	 * Answer every request with a refusal.
	 *
	 * @param int $status HTTP status to return.
	 * @return void
	 */
	private function serve_failure( $status ) {
		add_filter(
			'pre_http_request',
			function () use ( $status ) {
				$this->requests[] = $status;

				return $this->envelope(
					array(
						'success'   => false,
						'message'   => 'Nope.',
						'errorCode' => 'ERR-' . $status . '-TEST',
					),
					$status
				);
			}
		);

		// The Client sleeps between its own attempts, and a test has no reason to wait.
		add_filter( 'woo_kontor_sync_retry_delay', '__return_zero' );
	}

	/**
	 * Build a response array in the shape wp_remote_request() returns.
	 *
	 * @param array $body   Response body, to be encoded.
	 * @param int   $status HTTP status.
	 * @return array Response array.
	 */
	private function envelope( array $body, $status ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $status,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The action most recently queued against a hook, if any.
	 *
	 * @param string $hook Action hook.
	 * @return \ActionScheduler_Action|null The action, or null when none is queued.
	 */
	private function fetch_queued( $hook ) {
		$ids = (array) ActionScheduler::store()->query_actions(
			array(
				'hook'     => $hook,
				'group'    => Scheduler::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 10,
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		return ActionScheduler::store()->fetch_action( (int) end( $ids ) );
	}

	/**
	 * Assert that a hook has an action waiting.
	 *
	 * @param string $hook    Action hook.
	 * @param string $because What it would mean if there were none.
	 * @return void
	 */
	private function assertQueued( $hook, $because = '' ) {
		$this->assertNotNull( $this->fetch_queued( $hook ), $because );
	}

	/**
	 * Assert that a hook has nothing waiting.
	 *
	 * @param string $hook Action hook.
	 * @return void
	 */
	private function assertNotQueued( $hook ) {
		$this->assertNull( $this->fetch_queued( $hook ) );
	}
}
