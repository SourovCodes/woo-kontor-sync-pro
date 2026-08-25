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
	 * Lengths to report for a HEAD, keyed by URL. A URL absent from this is one the
	 * host would not answer about.
	 *
	 * @var array<string,int>
	 */
	public $head_lengths = array();

	/**
	 * The URL batches the sync asked HEAD about.
	 *
	 * @var array[]
	 */
	public $head_batches = array();

	/**
	 * Answer a HEAD batch from the script rather than the network.
	 *
	 * @param string[] $urls URLs to ask about.
	 * @return array<string,int> URL to the length the host reports.
	 */
	protected function head_batch( array $urls ) {
		$this->head_batches[] = $urls;

		$lengths = array();

		foreach ( $urls as $url ) {
			if ( isset( $this->head_lengths[ $url ] ) ) {
				$lengths[ $url ] = (int) $this->head_lengths[ $url ];
			}
		}

		return $lengths;
	}

	/**
	 * Run the image resolution, so a test can see what was adopted and what was fetched.
	 *
	 * @param string[] $urls     Image URLs in gallery order.
	 * @param int      $product  Product to attach them to.
	 * @param string   $sku      Article number.
	 * @param string   $name     Product name.
	 * @param int[]    $existing Attachments the product already carries.
	 * @return int[] Attachment IDs.
	 */
	public function run_resolve( array $urls, $product, $sku = 'sku', $name = '', array $existing = array() ) {
		return $this->resolve_images( $urls, $product, $sku, $name, $existing );
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
