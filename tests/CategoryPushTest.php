<?php
/**
 * Tests for sending this shop's category tree to Kontor.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Categories;
use WooKontorSync\Sync\CategoryPush;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers the payload, the refusals, and the promise that nothing is ever partial.
 *
 * Nothing here reaches Kontor: every request is answered by a pre_http_request stub,
 * which is also the only way this can be exercised at all while the one account it
 * could run against is live.
 */
class CategoryPushTest extends WP_UnitTestCase {

	/**
	 * A shop GUID with the shape Kontor returns.
	 */
	const SHOP_ID = '3ab38157-7269-427c-a9eb-905244c10aaf';

	/**
	 * The request bodies the stub saw.
	 *
	 * @var array[]
	 */
	private $captured = array();

	/**
	 * Start each test with WooCommerce's own default category out of the way.
	 *
	 * It is a real term and it would be sent, correctly, but it makes every assertion
	 * about the payload's contents one term longer than the test is about.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->captured = array();

		foreach ( get_terms(
			array(
				'taxonomy'   => Categories::TAXONOMY,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		) as $term_id ) {
			wp_delete_term( (int) $term_id, Categories::TAXONOMY );
		}
	}

	/**
	 * Remove the HTTP stubs between tests.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'pre_http_request' );

		parent::tear_down();
	}

	/**
	 * Capture every request and answer it as Kontor would on success.
	 *
	 * @return void
	 */
	private function fake_upsert() {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->captured[] = array(
					'url'  => $url,
					'body' => json_decode( $args['body'], true ),
				);

				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success'   => true,
							'message'   => 'Upsert completed successfully',
							'meta'      => array( 'rowCount' => 0 ),
							'data'      => array(),
							'errorCode' => null,
						)
					),
					'response' => array(
						'code'    => 200,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);
	}

	/**
	 * Settings pointing at a shop.
	 *
	 * @param array $overrides Settings to replace.
	 * @return array Settings.
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'api_base_url' => 'https://example.test/api/v1/kontor',
				'api_key'      => 'test-key',
				'shop_id'      => self::SHOP_ID,
			),
			$overrides
		);
	}

	/**
	 * Create a product category.
	 *
	 * @param string $name      Category name.
	 * @param int    $parent_id Parent term ID.
	 * @param string $katid     Kontor ID to stamp, if any.
	 * @return int Term ID.
	 */
	private function category( $name, $parent_id = 0, $katid = '' ) {
		$created = wp_insert_term( $name, Categories::TAXONOMY, array( 'parent' => (int) $parent_id ) );
		$term_id = (int) $created['term_id'];

		if ( '' !== $katid ) {
			update_term_meta( $term_id, Categories::TERM_META_ID, $katid );
		}

		return $term_id;
	}

	/**
	 * A category that came from Kontor is sent back under Kontor's own ID.
	 *
	 * This is the whole of what keeps its product assignments attached through a
	 * replacing push: a new ID would leave Kontor deleting the old category and
	 * everything filed under it.
	 *
	 * @return void
	 */
	public function test_a_kontor_category_keeps_its_own_id() {
		$this->category( 'Kreatives Bauen', 0, 'D444E512-20AB-45B5-B8C8-C968A934DB52' );

		$rows = ( new CategoryPush( null, $this->settings() ) )->payload();

		$this->assertCount( 1, $rows );
		$this->assertSame( 'D444E512-20AB-45B5-B8C8-C968A934DB52', $rows[0]['katid'] );
		$this->assertSame( '', $rows[0]['katidparent'] );
		$this->assertSame( 'Kreatives Bauen', $rows[0]['katname'] );
	}

	/**
	 * A category created here is sent under a minted ID.
	 *
	 * Prefixed rather than a bare term ID: three of the four shops sampled use small
	 * integers as Katids, so an unprefixed one would eventually collide with Kontor's
	 * own and silently merge two categories.
	 *
	 * @return void
	 */
	public function test_a_local_category_is_sent_under_a_prefixed_id() {
		$term_id = $this->category( 'Unsere eigene' );

		$rows = ( new CategoryPush( null, $this->settings() ) )->payload();

		$this->assertSame( CategoryPush::KATID_PREFIX . $term_id, $rows[0]['katid'] );
	}

	/**
	 * Minting is deterministic, so a retry sends the same tree.
	 *
	 * @return void
	 */
	public function test_minting_is_deterministic() {
		$this->category( 'Unsere eigene' );

		$push = new CategoryPush( null, $this->settings() );

		$this->assertSame( $push->payload(), $push->payload() );
	}

	/**
	 * A parent is sent as the parent's Katid, and a root as an empty string.
	 *
	 * @return void
	 */
	public function test_parents_are_sent_as_katids() {
		$parent = $this->category( 'Kategorien', 0, 'F021B820' );
		$this->category( 'Küchen', $parent, '0ACD5E71' );

		$rows = ( new CategoryPush( null, $this->settings() ) )->payload();
		$by   = array_column( $rows, null, 'katid' );

		$this->assertSame( '', $by['F021B820']['katidparent'] );
		$this->assertSame( 'F021B820', $by['0ACD5E71']['katidparent'] );
	}

	/**
	 * Every parent comes before its children.
	 *
	 * @return void
	 */
	public function test_parents_come_before_their_children() {
		$root = $this->category( 'Kategorien', 0, 'A' );
		$mid  = $this->category( 'Küchen', $root, 'B' );
		$this->category( 'Spielküchen', $mid, 'C' );

		$rows   = ( new CategoryPush( null, $this->settings() ) )->payload();
		$katids = array_column( $rows, 'katid' );

		$this->assertSame( array( 'A', 'B', 'C' ), $katids );
	}

	/**
	 * The whole taxonomy goes, including categories Kontor has never seen.
	 *
	 * Anything left out of a replacing push is deleted in the ERP, so "send only what
	 * came from Kontor" would quietly destroy the rest.
	 *
	 * @return void
	 */
	public function test_the_whole_taxonomy_is_sent() {
		$this->category( 'Von Kontor', 0, 'A' );
		$this->category( 'Von uns' );

		$rows = ( new CategoryPush( null, $this->settings() ) )->payload();

		$this->assertCount( 2, $rows );
	}

	/**
	 * A tree too large to send is refused, never truncated.
	 *
	 * @return void
	 */
	public function test_an_oversized_tree_is_refused_rather_than_truncated() {
		$push = new class( null, $this->settings() ) extends CategoryPush {
			/**
			 * Pretend the shop is over the bound without creating a thousand terms.
			 *
			 * @return array|\WP_Error Rows, or the refusal.
			 */
			public function payload() {
				$rows = parent::payload();

				return is_wp_error( $rows ) || count( $rows ) <= 1
					? new \WP_Error( 'wksync_too_many_categories', 'Nothing was sent.' )
					: $rows;
			}
		};

		$this->category( 'Eine' );
		$this->fake_upsert();

		$result = $push->push();

		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( 0, $result['sent'] );
		$this->assertSame( array(), $this->captured, 'A refused push still sent a request.' );
	}

	/**
	 * An empty taxonomy is refused rather than sent as an instruction to delete.
	 *
	 * @return void
	 */
	public function test_an_empty_taxonomy_sends_nothing() {
		$this->fake_upsert();

		$result = ( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * Without a shop nothing is sent.
	 *
	 * @return void
	 */
	public function test_no_shop_sends_nothing() {
		$this->category( 'Eine' );
		$this->fake_upsert();

		$result = ( new CategoryPush( null, $this->settings( array( 'shop_id' => '' ) ) ) )->push();

		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( array(), $this->captured );
	}

	/**
	 * The request carries the shape Kontor documents.
	 *
	 * @return void
	 */
	public function test_the_request_has_the_shape_kontor_asks_for() {
		$this->category( 'Kreatives Bauen', 0, 'D444E512' );
		$this->fake_upsert();

		( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertCount( 1, $this->captured );

		$body = $this->captured[0]['body'];

		$this->assertStringEndsWith( '/upsert', $this->captured[0]['url'] );
		$this->assertSame( 'categories', $body['name'] );
		$this->assertSame( 'WKSP', $body['meta']['userId'] );
		$this->assertSame( self::SHOP_ID, $body['params']['shopid'] );
		$this->assertTrue( $body['params']['overwrite_all'] );
		$this->assertSame( 'D444E512', $body['params']['categories'][0]['katid'] );
	}

	/**
	 * Everything goes in one request, whatever the size of the tree.
	 *
	 * Batching under overwrite_all would have each batch delete the one before it.
	 *
	 * @return void
	 */
	public function test_the_tree_is_sent_in_one_request() {
		for ( $i = 0; $i < 60; $i++ ) {
			$this->category( 'Kategorie ' . $i );
		}

		$this->fake_upsert();

		( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertCount( 1, $this->captured );
		$this->assertCount( 60, $this->captured[0]['body']['params']['categories'] );
	}

	/**
	 * A successful push stamps the IDs it minted onto the terms.
	 *
	 * @return void
	 */
	public function test_a_successful_push_stamps_the_minted_ids() {
		$term_id = $this->category( 'Unsere eigene' );

		$this->fake_upsert();

		( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertSame(
			CategoryPush::KATID_PREFIX . $term_id,
			get_term_meta( $term_id, Categories::TERM_META_ID, true )
		);
	}

	/**
	 * A failed push stamps nothing.
	 *
	 * @return void
	 */
	public function test_a_failed_push_stamps_nothing() {
		$term_id = $this->category( 'Unsere eigene' );

		add_filter(
			'pre_http_request',
			static function () {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode(
						array(
							'success'   => false,
							'message'   => 'Ungültiger Schlüssel',
							'errorCode' => 'ERR-401-INVALID-API-KEY',
						)
					),
					'response' => array(
						'code'    => 401,
						'message' => '',
					),
					'cookies'  => array(),
					'filename' => null,
				);
			},
			10,
			3
		);

		$result = ( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertNotSame( '', $result['error'] );
		$this->assertSame( '', get_term_meta( $term_id, Categories::TERM_META_ID, true ) );
	}

	/**
	 * The push leaves the job status alone.
	 *
	 * A run belongs to a scheduled job. Marking one here would collide with a real
	 * sweep, and a request cut short would strand the job as running for six hours.
	 *
	 * @return void
	 */
	public function test_the_push_leaves_the_job_status_alone() {
		$this->category( 'Eine' );
		$this->fake_upsert();

		$before = Status::get( ProductSync::JOB );

		( new CategoryPush( null, $this->settings() ) )->push();

		$this->assertSame( $before, Status::get( ProductSync::JOB ) );
	}

	/**
	 * The confirmation word is not the order screen's word.
	 *
	 * Two different destructive acts, two different words, so muscle memory from one
	 * cannot fire the other.
	 *
	 * @return void
	 */
	public function test_the_confirmation_word_differs_from_the_order_one() {
		$this->assertNotSame( Settings::FORCE_PUSH_CONFIRMATION, Settings::CATEGORY_PUSH_CONFIRMATION );
	}
}
