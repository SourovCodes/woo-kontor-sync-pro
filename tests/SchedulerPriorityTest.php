<?php
/**
 * Tests for the order in which chained actions are claimed.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use ActionScheduler_DBStore;
use WooKontorSync\Sync\Scheduler;
use WP_UnitTestCase;

/**
 * Covers image downloads yielding to the catalogue walk.
 *
 * Action Scheduler claims by priority and then by insertion order, and a page queues
 * its images before it queues the next page. Keeping the walk ahead of the downloads
 * is therefore a property of the queue rather than of the code that fills it, so what
 * is asserted here is what the store actually hands a queue runner.
 */
class SchedulerPriorityTest extends WP_UnitTestCase {

	/**
	 * Start from an empty queue.
	 *
	 * Activation leaves the recurring actions scheduled, and they are older than
	 * anything queued here, so they would otherwise be claimed first and make every
	 * assertion about order a statement about the bootstrap.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		as_unschedule_all_actions( '', array(), Scheduler::GROUP );
	}

	/**
	 * Empty the queue between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		as_unschedule_all_actions( '', array(), Scheduler::GROUP );

		parent::tear_down();
	}

	/**
	 * The hooks of this plugin's pending actions, in the order they would be run.
	 *
	 * Claimed from the table store directly rather than through ActionScheduler::store().
	 * The suite's database has never run the migration, so the factory hands back the
	 * hybrid store, which consults the legacy post store first; a site on WooCommerce
	 * 11 is long past that and uses the table store alone.
	 *
	 * @param int $limit Most actions to claim.
	 * @return array List of hook names.
	 */
	private function claim_order( $limit = 10 ) {
		$store = new ActionScheduler_DBStore();
		$claim = $store->stake_claim( $limit, null, array(), Scheduler::GROUP );
		$hooks = array();

		foreach ( $claim->get_actions() as $action_id ) {
			$action = $store->fetch_action( $action_id );

			if ( $action && $action->get_hook() ) {
				$hooks[] = $action->get_hook();
			}
		}

		$store->release_claim( $claim );

		return $hooks;
	}

	/**
	 * A page queued after a batch of images is still claimed before them.
	 *
	 * This is the whole point of the priority. Left at the default, the next page sat
	 * behind every image the current one queued, so the walk advanced one page per
	 * image backlog instead of one page per read.
	 *
	 * @return void
	 */
	public function test_the_next_page_is_claimed_before_images_queued_first() {
		foreach ( array( 11, 22, 33 ) as $product_id ) {
			Scheduler::chain(
				Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
				array(
					'product_id' => $product_id,
					'files'      => array( 'abel-AB12_001.jpg' ),
					'run'        => 1,
				),
				Scheduler::PRIORITY_IMAGES
			);
		}

		Scheduler::chain(
			Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
			array(
				'skip' => 200,
				'run'  => 1,
			)
		);

		$hooks = $this->claim_order();

		$this->assertSame( Scheduler::ACTION_SYNC_PRODUCTS_PAGE, $hooks[0], 'The catalogue walk must not wait behind the images it queued.' );
		$this->assertSame( 3, count( array_keys( $hooks, Scheduler::ACTION_SYNC_PRODUCT_IMAGES, true ) ), 'The images are deferred, not dropped.' );
	}

	/**
	 * The action that closes the run is claimed ahead of the last page's images too.
	 *
	 * Drafting the articles Kontor has dropped and reporting the run as done both
	 * happen there, so behind the images it would hold both back for the length of
	 * the tail.
	 *
	 * @return void
	 */
	public function test_finalise_is_claimed_before_images() {
		Scheduler::chain(
			Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
			array(
				'product_id' => 11,
				'files'      => array( 'abel-AB12_001.jpg' ),
				'run'        => 1,
			),
			Scheduler::PRIORITY_IMAGES
		);

		Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, array( 'run' => 1 ) );

		$this->assertSame(
			array( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, Scheduler::ACTION_SYNC_PRODUCT_IMAGES ),
			$this->claim_order()
		);
	}

	/**
	 * Another job's chunk is claimed ahead of an image backlog as well.
	 *
	 * Stock runs every fifteen minutes and a first product sync queues one image
	 * action per article, so at the default priority a routine stock sync would spend
	 * its interval waiting behind several thousand downloads.
	 *
	 * @return void
	 */
	public function test_other_jobs_outrank_images() {
		Scheduler::chain(
			Scheduler::ACTION_SYNC_PRODUCT_IMAGES,
			array(
				'product_id' => 11,
				'files'      => array( 'abel-AB12_001.jpg' ),
				'run'        => 1,
			),
			Scheduler::PRIORITY_IMAGES
		);

		Scheduler::chain(
			Scheduler::ACTION_SYNC_STOCK_CHUNK,
			array(
				'offset' => 0,
				'run'    => 1,
			)
		);

		$this->assertSame(
			array( Scheduler::ACTION_SYNC_STOCK_CHUNK, Scheduler::ACTION_SYNC_PRODUCT_IMAGES ),
			$this->claim_order()
		);
	}

	/**
	 * Actions of the same priority keep the order they were queued in.
	 *
	 * The chain relies on it: nothing about a page walk would work if a later page
	 * could be claimed before an earlier one.
	 *
	 * @return void
	 */
	public function test_equal_priority_keeps_insertion_order() {
		Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_PAGE, array( 'skip' => 0 ) );
		Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_PAGE, array( 'skip' => 200 ) );
		Scheduler::chain( Scheduler::ACTION_SYNC_PRODUCTS_FINALISE, array( 'run' => 1 ) );

		$this->assertSame(
			array(
				Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
				Scheduler::ACTION_SYNC_PRODUCTS_PAGE,
				Scheduler::ACTION_SYNC_PRODUCTS_FINALISE,
			),
			$this->claim_order()
		);
	}
}
