<?php
/**
 * Tests for where a chunked run keeps the payload it is working through.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Payload;
use WooKontorSync\Sync\Preflight;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\StockSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the store, and what a job does when it cannot read what it stored.
 *
 * Until 0.29.0 these payloads lived in transients, which on a site with a persistent
 * object cache is not storage at all: Redis and Memcached hold them instead of the
 * database, so they can be evicted at any moment. The eviction says nothing, and the
 * stock sync would then fail every fifteen minutes for ever, reporting that the
 * payload had expired — which is the one thing that had not happened.
 */
class PayloadTest extends WP_UnitTestCase {

	/**
	 * Clear state that outlives a test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );
		Payload::forget_all();
		delete_option( Settings::OPTION_KEY );
		delete_option( Status::OPTION_KEY );
		Preflight::forget_connection();

		parent::tear_down();
	}

	/**
	 * What goes in comes back out.
	 *
	 * @return void
	 */
	public function test_a_payload_survives_a_round_trip() {
		$this->assertTrue( Payload::put( 'stock', array( 'ABC' => 3 ) ) );
		$this->assertSame( array( 'ABC' => 3 ), Payload::get( 'stock' ) );
	}

	/**
	 * Nothing stored reads as nothing, not as an empty payload.
	 *
	 * A job has to be able to tell "there is no payload" from "the payload is empty",
	 * because the first is a failure and the second is a run with no work in it.
	 *
	 * @return void
	 */
	public function test_an_absent_payload_is_null_and_an_empty_one_is_not() {
		$this->assertNull( Payload::get( 'stock' ) );

		Payload::put( 'stock', array() );

		$this->assertSame( array(), Payload::get( 'stock' ) );
	}

	/**
	 * Storing the same payload twice is a success, not a failed write.
	 *
	 * WordPress returns false from update_option() both for a write that failed and for
	 * one that stored a value identical to what was already there — which is exactly what a job
	 * re-running over an unchanged feed does. Reading the payload back is what tells
	 * the two apart.
	 *
	 * @return void
	 */
	public function test_storing_an_unchanged_payload_still_reports_success() {
		$levels = array( 'ABC' => 3 );

		$this->assertTrue( Payload::put( 'stock', $levels ) );
		$this->assertTrue( Payload::put( 'stock', $levels ) );
	}

	/**
	 * One job's payload is not another's.
	 *
	 * @return void
	 */
	public function test_each_job_has_its_own() {
		Payload::put( 'stock', array( 'ABC' => 3 ) );
		Payload::put( 'delivery', array( 'row' ) );

		$this->assertSame( array( 'ABC' => 3 ), Payload::get( 'stock' ) );
		$this->assertSame( array( 'row' ), Payload::get( 'delivery' ) );

		Payload::forget( 'stock' );

		$this->assertNull( Payload::get( 'stock' ) );
		$this->assertSame( array( 'row' ), Payload::get( 'delivery' ) );
	}

	/**
	 * The payload is not autoloaded.
	 *
	 * It is read by one action per chunk and by nothing else. Autoloaded, a stock feed
	 * of three thousand rows would be unserialised on every request the site serves.
	 *
	 * @return void
	 */
	public function test_the_payload_is_never_autoloaded() {
		global $wpdb;

		Payload::put( 'stock', array( 'ABC' => 3 ) );

		$autoload = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s",
				Payload::OPTION_PREFIX . 'stock'
			)
		);

		$this->assertNotContains( $autoload, array( 'yes', 'on', 'auto' ) );
	}

	/**
	 * Emptying the queue drops what every run had stored.
	 *
	 * @return void
	 */
	public function test_forgetting_them_all_leaves_nothing() {
		foreach ( array_keys( Scheduler::get_jobs() ) as $job ) {
			Payload::put( $job, array( 'something' ) );
		}

		Payload::forget_all();

		foreach ( array_keys( Scheduler::get_jobs() ) as $job ) {
			$this->assertNull( Payload::get( $job ), $job . ' kept its payload' );
		}
	}

	/**
	 * A chunk that cannot read the payload fails the run, and says what happened.
	 *
	 * The old wording blamed expiry, which under a persistent object cache was the one
	 * thing that had not happened.
	 *
	 * @return void
	 */
	public function test_a_chunk_with_no_payload_fails_with_an_honest_reason() {
		$run = Status::start( StockSync::JOB );

		( new StockSync( null, array() ) )->apply_chunk( 0, $run );

		$status = Status::get( StockSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'could not be read', $status['message'] );
	}

	/**
	 * A run whose payload will not store reports that, and queues no chunks.
	 *
	 * Settled at the start rather than left to the first chunk, where the honest
	 * reason is no longer available to give.
	 *
	 * @return void
	 */
	public function test_a_run_that_cannot_store_its_payload_stops_at_the_start() {
		update_option(
			Settings::OPTION_KEY,
			array_merge(
				Settings::default_settings(),
				array(
					'api_base_url' => 'https://erp.example.test/api/v1/kontor',
					'api_key'      => 'test-key-123',
				)
			)
		);

		set_transient( Preflight::CONNECTION_CACHE, 1, Preflight::CONNECTION_TTL );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success' => true,
							'message' => '',
							'meta'    => array( 'rowCount' => 1 ),
							'data'    => array(
								array(
									'Artnr'        => 'ABC',
									'Lagerbestand' => 3,
								),
							),
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			}
		);

		// Stand in for a store that accepts the write and produces nothing, which is
		// what memcached does with a value over its slab limit.
		add_filter( 'option_' . Payload::OPTION_PREFIX . 'stock', '__return_null' );

		( new StockSync() )->start();

		remove_all_filters( 'option_' . Payload::OPTION_PREFIX . 'stock' );

		$status = Status::get( StockSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertStringContainsString( 'could not be stored', $status['message'] );
		$this->assertSame( 0, Scheduler::pending_count( Scheduler::ACTION_SYNC_STOCK_CHUNK ) );
	}
}
