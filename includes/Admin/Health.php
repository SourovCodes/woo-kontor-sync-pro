<?php
/**
 * What is currently wrong with the sync, in one place.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Api\Client;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the state of every job and says which of them need somebody.
 *
 * Three screens ask this question — the admin notice, WooCommerce's system status
 * report and Site Health — and before this none of them asked it at all: a shop whose
 * product sync had failed every night for a week looked completely normal until
 * somebody opened the Kontor Sync screen. Answering it in one place is what stops the
 * three of them describing the same shop differently.
 *
 * Three things count as a problem, and they are genuinely different failures:
 *
 * - **failed** — the job ran and could not finish. Its own message says why.
 * - **stale** — the job has been "running" past Status::STALE_AFTER, which means the
 *   chain behind it died without anything closing the status. The run is not coming
 *   back, and until the state expires Scheduler::trigger() refuses to start another.
 * - **unscheduled** — the job has an interval and no recurring action to match it, so
 *   it is simply not running. This is the one nothing else would ever show: the
 *   settings screen reads the interval out of the settings and reports it as
 *   configured, whatever the queue actually holds.
 */
class Health {

	/**
	 * Transient caching the schedule half of the answer.
	 */
	const SCHEDULE_CACHE = 'wksync_schedule_health';

	/**
	 * How long that answer is trusted for.
	 *
	 * The status half is a single option read and is always taken fresh. The schedule
	 * half is not: Scheduler::has_recurring() has to fetch each of a hook's queued
	 * actions and ask it whether it repeats, because the kind of an action is not in
	 * the queue's index. Five jobs of that on every admin page load would be the same
	 * mistake as polling for it, so the screen that runs on every page load reads a
	 * cached copy and the two that are opened deliberately do not.
	 */
	const SCHEDULE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * A job that ran and could not finish.
	 */
	const FAILED = 'failed';

	/**
	 * A job stuck in "running" long enough that the chain behind it must be dead.
	 */
	const STALE = 'stale';

	/**
	 * A job with an interval and no recurring action in the queue.
	 */
	const UNSCHEDULED = 'unscheduled';

	/**
	 * Everything currently wrong with the sync.
	 *
	 * @param bool $cache_schedules Read the schedule half from the cache rather than the queue.
	 * @return array List of problems, each with job, label, kind and message keys.
	 */
	public static function problems( $cache_schedules = false ) {
		$settings = Settings::get_settings();
		$orders   = Settings::orders_enabled( $settings );
		$problems = array();
		$absent   = $cache_schedules ? self::cached_unscheduled() : self::unscheduled();

		foreach ( Scheduler::get_jobs() as $key => $job ) {
			/*
			 * A shop that does not exchange orders with Kontor is not misconfigured for
			 * having no order sync, and the settings screen does not list those jobs
			 * either. Their stored status can only be left over from before the switch
			 * was turned off.
			 */
			if ( ! empty( $job['needs_shop'] ) && ! $orders ) {
				continue;
			}

			$status = Status::get( $key );

			if ( self::FAILED === $status['state'] ) {
				$problems[] = array(
					'job'     => $key,
					'label'   => $job['label'],
					'kind'    => self::FAILED,
					'message' => (string) $status['message'],
				);
			} elseif ( 'running' === $status['state'] && ! Status::is_running( $key ) ) {
				$problems[] = array(
					'job'     => $key,
					'label'   => $job['label'],
					'kind'    => self::STALE,
					'message' => __( 'The run stopped without saying why, and nothing closed it.', 'woo-kontor-sync-pro' ),
				);
			}

			// Separate from the state above, and a job can be both: one describes the
			// last run, this one says there will not be another.
			if ( in_array( $key, $absent, true ) ) {
				$problems[] = array(
					'job'     => $key,
					'label'   => $job['label'],
					'kind'    => self::UNSCHEDULED,
					'message' => __( 'An interval is set but nothing is queued to run it.', 'woo-kontor-sync-pro' ),
				);
			}
		}

		return $problems;
	}

	/**
	 * The jobs whose interval has no recurring action behind it.
	 *
	 * Asked of the queue, never of the settings. The settings screen reports an
	 * interval as configured because it is stored, which is exactly what made this
	 * failure invisible on a live shop: the guard that rate limits the reconciliation
	 * was left standing by a request that died mid-way, and the site sat with no
	 * recurring action of any kind while every screen said otherwise.
	 *
	 * @return string[] Job keys with an interval and nothing queued.
	 */
	public static function unscheduled() {
		if ( ! Scheduler::is_available() ) {
			return array();
		}

		$settings = Settings::get_settings();
		$orders   = Settings::orders_enabled( $settings );
		$absent   = array();

		foreach ( Scheduler::get_jobs() as $key => $job ) {
			if ( ! empty( $job['needs_shop'] ) && ! $orders ) {
				continue;
			}

			$interval = isset( $settings[ $job['setting'] ] ) ? absint( $settings[ $job['setting'] ] ) : Settings::INTERVAL_NEVER;

			// Never is a legitimate choice on every schedule here, and the job stays
			// manual. It is only the mismatch that is worth reporting.
			if ( Settings::INTERVAL_NEVER === $interval ) {
				continue;
			}

			if ( ! Scheduler::has_recurring( $job['action'] ) ) {
				$absent[] = $key;
			}
		}

		return $absent;
	}

	/**
	 * The same answer, at most once every SCHEDULE_TTL.
	 *
	 * An empty array is a real answer and the common one, so it is cached as a value
	 * of its own rather than left to look like a cache miss.
	 *
	 * @return string[] Job keys with an interval and nothing queued.
	 */
	protected static function cached_unscheduled() {
		$cached = get_transient( self::SCHEDULE_CACHE );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$absent = self::unscheduled();

		set_transient( self::SCHEDULE_CACHE, $absent, self::SCHEDULE_TTL );

		return $absent;
	}

	/**
	 * Forget the cached schedule answer.
	 *
	 * Saving the settings re-queues the recurring actions, so whatever was cached a
	 * moment ago describes a queue that no longer exists.
	 *
	 * @return void
	 */
	public static function forget_schedules() {
		delete_transient( self::SCHEDULE_CACHE );
	}

	/**
	 * WooCommerce's log viewer, filtered to this plugin's messages.
	 *
	 * Every sync logs its decisions there and nothing anywhere pointed at it. The
	 * source parameter is read by both of WooCommerce's log handlers — the file
	 * viewer and the database one — so one URL serves whichever the shop uses.
	 *
	 * @return string Admin URL.
	 */
	public static function log_url() {
		return add_query_arg(
			array(
				'page'   => 'wc-status',
				'tab'    => 'logs',
				'source' => Client::LOG_SOURCE,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The Kontor Sync settings screen.
	 *
	 * @return string Admin URL.
	 */
	public static function settings_url() {
		return add_query_arg( 'page', Settings::PAGE_SLUG, admin_url( 'admin.php' ) );
	}
}
