<?php
/**
 * Where a chunked run keeps the payload it is working through.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Holds one job's in-flight payload across the actions that consume it.
 *
 * Four jobs fetch everything Kontor has in one request and then apply it a chunk per
 * action — the stock levels, the delivery rows, the invoice listing, the order IDs a
 * sweep is working through. Something has to hold that between actions, and until
 * 0.29.0 that something was a transient.
 *
 * **A transient is not storage on a site with a persistent object cache.** Redis and
 * Memcached hold it instead of the database, so it can be evicted under memory
 * pressure at any moment, and the eviction says nothing: `set_transient()` returned
 * true hours ago, the run queued its chunks, and the next one finds nothing. On a
 * stock sync that is every fifteen minutes, for ever, reported as the payload having
 * expired — which is the one thing that had not happened.
 *
 * Size is not the danger, whatever the shape of the payload suggests: 3000 stock rows
 * serialise to 68KB, so memcached's default megabyte slab is some forty thousand rows
 * away. It is the cache deciding it wants the memory back, which does not depend on
 * how much of it we asked for.
 *
 * A non-autoloaded option is cache-*backed* rather than cache-*only*: a flush costs a
 * read, not the value. So the payload lives in one of those, and the object cache goes
 * back to being what it is meant to be here, an accelerator.
 *
 * **The key is the job, not the run.** A per-run key needed a TTL to clean up after a
 * run that died, and left a row behind for every one that did. There can only be one
 * run of a job in flight — `start()` refuses otherwise — and every chunked action
 * checks `Status::is_current_run()` before it reads, so a superseded action never gets
 * as far as asking. One row per job, overwritten by the next run, is the whole of it.
 */
class Payload {

	/**
	 * Prefix of the option each job's payload is kept in.
	 */
	const OPTION_PREFIX = 'woo_kontor_sync_payload_';

	/**
	 * Store a run's payload.
	 *
	 * Read back rather than trusted, because the whole point of moving off transients
	 * is that a write which silently did nothing cost a job every run it ever made
	 * afterwards. Paying one read per *run* — not per chunk — turns that into one
	 * accurate failure.
	 *
	 * @param string $job  Job key.
	 * @param array  $data Payload to keep.
	 * @return bool True when the payload can be read back.
	 */
	public static function put( $job, array $data ) {
		update_option( self::key( $job ), $data, false );

		/*
		 * The return of update_option() cannot answer this: it is false both for a write
		 * that failed and for one that stored a value identical to what was already
		 * there, which is exactly what a job re-running over an unchanged feed does.
		 */
		return null !== self::get( $job );
	}

	/**
	 * Read a run's payload.
	 *
	 * @param string $job Job key.
	 * @return array|null The payload, or null when there is none to read.
	 */
	public static function get( $job ) {
		$stored = get_option( self::key( $job ), null );

		return is_array( $stored ) ? $stored : null;
	}

	/**
	 * Drop a run's payload.
	 *
	 * @param string $job Job key.
	 * @return void
	 */
	public static function forget( $job ) {
		delete_option( self::key( $job ) );
	}

	/**
	 * Drop every job's payload.
	 *
	 * For the paths that destroy the queue: whatever is stored describes a run whose
	 * chained actions have just been thrown away, so nothing will ever read it.
	 *
	 * @return void
	 */
	public static function forget_all() {
		foreach ( array_keys( Scheduler::get_jobs() ) as $job ) {
			self::forget( $job );
		}
	}

	/**
	 * The option one job's payload lives in.
	 *
	 * @param string $job Job key.
	 * @return string Option name.
	 */
	protected static function key( $job ) {
		return self::OPTION_PREFIX . sanitize_key( $job );
	}
}
