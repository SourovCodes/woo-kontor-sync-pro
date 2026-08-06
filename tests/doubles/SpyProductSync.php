<?php
/**
 * A ProductSync whose image downloads are answered from a script.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use Exception;
use WooKontorSync\Sync\ProductSync;
use WP_Error;

/**
 * Replaces the one method that touches the network, and nothing else.
 *
 * Everything under test — the batching, the ordering, the failure handling — is the
 * real code. The recorded batches are the only way to see from outside that URLs
 * were fetched together rather than one after another.
 */
class SpyProductSync extends ProductSync {

	/**
	 * Answers to give, keyed by URL: a file path, or a WP_Error.
	 *
	 * @var array
	 */
	public $canned = array();

	/**
	 * The URL batches the sync asked for, in the order it asked.
	 *
	 * @var array[]
	 */
	public $batches = array();

	/**
	 * Answer one batch without going near a host.
	 *
	 * @param string[] $urls URLs to fetch together.
	 * @return array Map of URL to a file path or a WP_Error.
	 */
	protected function download_batch( array $urls ) {
		$this->batches[] = $urls;

		$results = array();

		foreach ( $urls as $url ) {
			$results[ $url ] = isset( $this->canned[ $url ] )
				? $this->canned[ $url ]
				: new WP_Error( 'wksync_image_download_failed', 'No answer was prepared for ' . $url );
		}

		return $results;
	}

	/**
	 * Run the batching, so a test can see how the URLs were split.
	 *
	 * @param string[] $urls URLs to fetch.
	 * @return array Map of URL to a file path or a WP_Error.
	 */
	public function run_download( array $urls ) {
		return $this->download( $urls );
	}

	/**
	 * Ask whether a request would be refused before it is made.
	 *
	 * @param string $url URL about to be fetched.
	 * @return WP_Error|null The refusal, or null when the request may proceed.
	 */
	public function check_refusal( $url ) {
		return $this->refuse_request( $url );
	}

	/**
	 * Ask what one response left behind.
	 *
	 * @param object|Exception $response Response or failure for one URL.
	 * @param string           $temp     Temporary file the download was streamed to.
	 * @return string|WP_Error The file path, or why it is unusable.
	 */
	public function check_response( $response, $temp ) {
		return $this->interpret_download( $response, $temp );
	}

	/**
	 * Ask how many images would be fetched at once.
	 *
	 * @return int Number of simultaneous downloads.
	 */
	public function batch_size() {
		return $this->concurrency();
	}
}
