<?php
/**
 * Per-job run status.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Records how each sync job last went, for display on the settings screen.
 *
 * Kept in a single option rather than one per job so a run never has to read or
 * write more than one row.
 */
class Status {

	/**
	 * Option holding the status of every job.
	 */
	const OPTION_KEY = 'woo_kontor_sync_job_status';

	/**
	 * Mark a job as started.
	 *
	 * @param string $job Job key, for example "products".
	 * @return int The run's start timestamp, used to identify the run.
	 */
	public static function start( $job ) {
		$started = time();

		self::write(
			$job,
			array(
				'state'    => 'running',
				'started'  => $started,
				'finished' => 0,
				'message'  => '',
				'counts'   => array(),
			)
		);

		return $started;
	}

	/**
	 * Update the running totals of an in-flight job.
	 *
	 * @param string $job    Job key.
	 * @param array  $counts Counters to merge into the stored totals.
	 * @return void
	 */
	public static function progress( $job, array $counts ) {
		$current = self::get( $job );

		foreach ( $counts as $name => $value ) {
			$existing                   = isset( $current['counts'][ $name ] ) ? (int) $current['counts'][ $name ] : 0;
			$current['counts'][ $name ] = $existing + (int) $value;
		}

		self::write( $job, $current );
	}

	/**
	 * Mark a job as finished successfully.
	 *
	 * @param string $job     Job key.
	 * @param string $message Optional summary message.
	 * @return void
	 */
	public static function finish( $job, $message = '' ) {
		$current             = self::get( $job );
		$current['state']    = 'success';
		$current['finished'] = time();
		$current['message']  = $message;

		self::write( $job, $current );
	}

	/**
	 * Mark a job as failed.
	 *
	 * @param string $job     Job key.
	 * @param string $message Reason for the failure.
	 * @return void
	 */
	public static function fail( $job, $message ) {
		$current             = self::get( $job );
		$current['state']    = 'failed';
		$current['finished'] = time();
		$current['message']  = $message;

		self::write( $job, $current );
	}

	/**
	 * Read the status of a single job.
	 *
	 * @param string $job Job key.
	 * @return array Status array, with defaults filled in.
	 */
	public static function get( $job ) {
		$all = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $all ) || ! isset( $all[ $job ] ) || ! is_array( $all[ $job ] ) ) {
			return self::defaults();
		}

		return wp_parse_args( $all[ $job ], self::defaults() );
	}

	/**
	 * The shape of an unrun job.
	 *
	 * @return array Default status array.
	 */
	public static function defaults() {
		return array(
			'state'    => 'never',
			'started'  => 0,
			'finished' => 0,
			'message'  => '',
			'counts'   => array(),
		);
	}

	/**
	 * Persist the status of a single job.
	 *
	 * @param string $job    Job key.
	 * @param array  $status Status array.
	 * @return void
	 */
	protected static function write( $job, array $status ) {
		$all = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $all ) ) {
			$all = array();
		}

		$all[ $job ] = wp_parse_args( $status, self::defaults() );

		update_option( self::OPTION_KEY, $all, false );
	}
}
