<?php
/**
 * Tests for the Kontor category tree and the product filing that follows it.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\Categories;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Status;
use WP_UnitTestCase;

/**
 * Covers building the tree, following it, and filing products against it.
 *
 * The shapes exercised here are the ones the live API actually produces: Katids that
 * are GUIDs on one shop and bare integers on another, names that repeat inside one
 * tree, names arriving HTML-encoded, and articles carrying category IDs belonging to
 * other shops on the same account.
 */
class CategorySyncTest extends WP_UnitTestCase {

	/**
	 * A shop GUID with the shape Kontor returns.
	 */
	const SHOP_ID = '3ab38157-7269-427c-a9eb-905244c10aaf';

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
	 * Answer every Kontor request with a canned category list.
	 *
	 * @param array $rows Rows for the categories entity.
	 * @return void
	 */
	private function fake_tree( array $rows ) {
		$this->fake_envelope(
			array(
				'success'   => true,
				'message'   => 'Search completed successfully',
				'meta'      => array( 'rowCount' => count( $rows ) ),
				'data'      => $rows,
				'errorCode' => null,
			)
		);
	}

	/**
	 * Answer every Kontor request with a canned envelope.
	 *
	 * @param array $body Envelope to encode.
	 * @return void
	 */
	private function fake_envelope( array $body ) {
		add_filter(
			'pre_http_request',
			static function () use ( $body ) {
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
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
	 * One row of the categories entity.
	 *
	 * @param string $katid     Category ID.
	 * @param string $parent_id Parent category ID.
	 * @param string $name      Category name.
	 * @return array Row.
	 */
	private function row( $katid, $parent_id, $name ) {
		return array(
			'Katid'       => $katid,
			'Katidparent' => $parent_id,
			'Katname'     => $name,
		);
	}

	/**
	 * Settings with the category import switched on.
	 *
	 * @param array $overrides Settings to replace.
	 * @return array Settings.
	 */
	private function settings( array $overrides = array() ) {
		return array_merge(
			array(
				'api_base_url'            => 'https://example.test/api/v1/kontor',
				'api_key'                 => 'test-key',
				'shop_id'                 => self::SHOP_ID,
				'shoptype'                => 'B2C',
				'image_base_url'          => '',
				Settings::SYNC_CATEGORIES => true,
			),
			$overrides
		);
	}

	/**
	 * A Categories instance reading a canned tree.
	 *
	 * @param array $rows      Rows for the categories entity.
	 * @param array $overrides Settings to replace.
	 * @return Categories Configured mapper.
	 */
	private function categories( array $rows, array $overrides = array() ) {
		$this->fake_tree( $rows );

		return new Categories( null, $this->settings( $overrides ) );
	}

	/**
	 * The term a Katid resolved to.
	 *
	 * @param array  $map   Map from Categories::map().
	 * @param string $katid Category ID.
	 * @return \WP_Term|null The term, or null.
	 */
	private function term( array $map, $katid ) {
		$key = strtolower( $katid );

		if ( ! isset( $map[ $key ] ) ) {
			return null;
		}

		$term = get_term( $map[ $key ], Categories::TAXONOMY );

		return $term instanceof \WP_Term ? $term : null;
	}

	/**
	 * A tree becomes terms with their parents in place.
	 *
	 * @return void
	 */
	public function test_a_tree_becomes_terms_with_parents() {
		$map = $this->categories(
			array(
				$this->row( 'F021B820', '', 'Kategorien' ),
				$this->row( '0ACD5E71', 'F021B820', 'Küchen' ),
				$this->row( 'D3C6E1CF', '0ACD5E71', 'Spielküchen' ),
			)
		)->map();

		$this->assertIsArray( $map );
		$this->assertCount( 3, $map );

		$root  = $this->term( $map, 'F021B820' );
		$mid   = $this->term( $map, '0ACD5E71' );
		$child = $this->term( $map, 'D3C6E1CF' );

		$this->assertSame( 0, (int) $root->parent );
		$this->assertSame( (int) $root->term_id, (int) $mid->parent );
		$this->assertSame( (int) $mid->term_id, (int) $child->parent );
	}

	/**
	 * A child arriving before its parent still ends up underneath it.
	 *
	 * The rows come back in whatever order Kontor holds them, and a term cannot be
	 * created under a parent that does not exist yet.
	 *
	 * @return void
	 */
	public function test_a_child_listed_before_its_parent_is_still_filed_under_it() {
		$map = $this->categories(
			array(
				$this->row( 'D3C6E1CF', '0ACD5E71', 'Spielküchen' ),
				$this->row( '0ACD5E71', 'F021B820', 'Küchen' ),
				$this->row( 'F021B820', '', 'Kategorien' ),
			)
		)->map();

		$this->assertSame(
			(int) $this->term( $map, '0ACD5E71' )->term_id,
			(int) $this->term( $map, 'D3C6E1CF' )->parent
		);
	}

	/**
	 * A rename in Kontor renames the term and leaves its slug alone.
	 *
	 * @return void
	 */
	public function test_a_rename_is_followed_without_moving_the_slug() {
		$first = $this->categories( array( $this->row( '77', '', 'Kuscheltiere' ) ) )->map();
		$slug  = $this->term( $first, '77' )->slug;

		remove_all_filters( 'pre_http_request' );

		$second = $this->categories( array( $this->row( '77', '', 'Plüschtiere' ) ) )->map();
		$term   = $this->term( $second, '77' );

		$this->assertSame( $this->term( $first, '77' )->term_id, $term->term_id );
		$this->assertSame( 'Plüschtiere', $term->name );
		$this->assertSame( $slug, $term->slug, 'Renaming moved the slug and broke the archive URL.' );
	}

	/**
	 * A category moved in Kontor moves here.
	 *
	 * @return void
	 */
	public function test_a_move_is_followed() {
		$this->categories(
			array(
				$this->row( 'A', '', 'Anlässe' ),
				$this->row( 'B', '', 'Themenwelten' ),
				$this->row( 'C', 'A', 'Ostern' ),
			)
		)->map();

		remove_all_filters( 'pre_http_request' );

		$moved = $this->categories(
			array(
				$this->row( 'A', '', 'Anlässe' ),
				$this->row( 'B', '', 'Themenwelten' ),
				$this->row( 'C', 'B', 'Ostern' ),
			)
		)->map();

		$this->assertSame(
			(int) $this->term( $moved, 'B' )->term_id,
			(int) $this->term( $moved, 'C' )->parent
		);
	}

	/**
	 * Two categories sharing a name stay two categories.
	 *
	 * Real data: one shop carries "Soziales Lernen" six times and another carries two
	 * categories called "Waldtiere" under one parent. Matching on the name would
	 * collapse them, and each run would then re-stamp whichever was resolved last.
	 *
	 * @return void
	 */
	public function test_categories_sharing_a_name_stay_separate() {
		$map = $this->categories(
			array(
				$this->row( 'P1', '', 'Kuscheltiere' ),
				$this->row( 'W1', 'P1', 'Waldtiere' ),
				$this->row( 'W2', 'P1', 'Waldtiere' ),
			)
		)->map();

		$first  = $this->term( $map, 'W1' );
		$second = $this->term( $map, 'W2' );

		$this->assertNotSame( (int) $first->term_id, (int) $second->term_id );
		$this->assertSame( 'Waldtiere', $first->name );
		$this->assertSame( 'Waldtiere', $second->name );
		$this->assertNotSame( $first->slug, $second->slug );
	}

	/**
	 * A Katid is an opaque string and survives a leading zero.
	 *
	 * Across four shops sampled the IDs arrive as GUIDs, as 32-character hex and as
	 * bare integers. Casting any of them would collide "084" with "84".
	 *
	 * @return void
	 */
	public function test_a_katid_is_never_cast_to_a_number() {
		$map = $this->categories(
			array(
				$this->row( '084', '', 'Mit Null' ),
				$this->row( '84', '', 'Ohne Null' ),
			)
		)->map();

		$this->assertNotSame(
			(int) $this->term( $map, '084' )->term_id,
			(int) $this->term( $map, '84' )->term_id
		);
		$this->assertSame( '084', get_term_meta( $map['084'], Categories::TERM_META_ID, true ) );
	}

	/**
	 * An HTML-encoded name reads as its characters rather than as its entities.
	 *
	 * 74 of 141 rows on one live shop arrive encoded. WordPress encodes a term name on
	 * the way into the database itself, so what matters is that the entity is decoded
	 * exactly once and does not stack up into "&amp;amp;".
	 *
	 * @return void
	 */
	public function test_an_encoded_name_is_not_encoded_twice() {
		$map  = $this->categories( array( $this->row( '1435', '', 'Emotionen &amp; Empathie' ) ) )->map();
		$name = $this->term( $map, '1435' )->name;

		$this->assertSame( 'Emotionen & Empathie', html_entity_decode( $name, ENT_QUOTES ) );
		$this->assertStringNotContainsString( '&amp;amp;', $name );
	}

	/**
	 * Reconciling an unchanged tree twice writes nothing.
	 *
	 * The names that would churn are exactly the awkward ones: WordPress puts every term
	 * name through sanitize_text_field() and _wp_specialchars() on the way in, so the
	 * stored name is not the name Kontor sent. Comparing the two directly would rewrite
	 * most of one live shop's tree on every run, for ever.
	 *
	 * @return void
	 */
	public function test_an_unchanged_tree_is_not_rewritten_on_the_next_run() {
		$rows = array(
			$this->row( '1435', '', 'Emotionen &amp; Empathie' ),
			$this->row( '9', '', 'Rabatt 20%ab Lager' ),
			$this->row( '3', '', 'Küchen & Verkaufsläden' ),
		);

		$first = $this->categories( $rows )->map();

		remove_all_filters( 'pre_http_request' );

		$edits = 0;

		add_action(
			'edited_term',
			static function () use ( &$edits ) {
				++$edits;
			}
		);

		$second = $this->categories( $rows )->map();

		/*
		 * The same three terms, so follow() was reached and chose to write nothing,
		 * rather than the terms having been missed and the count being trivially zero.
		 * Sorted first: the second run builds its map from the terms already in the
		 * database, so the keys come back in a different order carrying the same answer.
		 */
		ksort( $first );
		ksort( $second );

		$this->assertSame( $first, $second );
		$this->assertSame( 0, $edits, 'A tree that had not changed was written to anyway.' );
	}

	/**
	 * A cycle in the parent links terminates instead of recursing.
	 *
	 * @return void
	 */
	public function test_a_cycle_does_not_recurse_forever() {
		$map = $this->categories(
			array(
				$this->row( 'X', 'Y', 'Erste' ),
				$this->row( 'Y', 'X', 'Zweite' ),
			)
		)->map();

		$this->assertIsArray( $map );
		$this->assertCount( 2, $map );
	}

	/**
	 * An empty reply is a failure, not an empty tree.
	 *
	 * Zero rows is exactly what the entity returns when the request carries no shop, so
	 * reading it as "this shop has no categories" would, with the requirement switched
	 * on, draft the whole catalogue on the strength of a misconfigured request.
	 *
	 * @return void
	 */
	public function test_an_empty_tree_is_an_error() {
		$this->assertWPError( $this->categories( array() )->map() );
	}

	/**
	 * Without a shop there is no tree to read.
	 *
	 * @return void
	 */
	public function test_no_shop_is_an_error() {
		$categories = $this->categories(
			array( $this->row( '1', '', 'Eins' ) ),
			array( 'shop_id' => '' )
		);

		$this->assertWPError( $categories->map() );
	}

	/**
	 * A product is filed under the categories its article names.
	 *
	 * @return void
	 */
	public function test_a_product_is_filed_under_its_articles_categories() {
		$categories = $this->categories(
			array(
				$this->row( 'D444E512', '', 'Kreatives Bauen' ),
				$this->row( '28151E46', '', 'Abel' ),
				$this->row( '11B0FF33', '', 'Anlässe' ),
			)
		);

		$product_id = $this->product( 'abel-AB12' );

		$this->assertTrue( $categories->assign( $product_id, 'D444E512,28151E46' ) );

		$names = wp_list_pluck( wp_get_object_terms( $product_id, Categories::TAXONOMY ), 'name' );
		sort( $names );

		$this->assertSame( array( 'Abel', 'Kreatives Bauen' ), $names );
	}

	/**
	 * Category IDs belonging to another shop are ignored.
	 *
	 * An article's Categories field is a union across every shop on the account unless
	 * the request carries a shopid — 334 distinct foreign IDs appear across one live
	 * catalogue.
	 *
	 * @return void
	 */
	public function test_another_shops_category_ids_are_ignored() {
		$categories = $this->categories( array( $this->row( 'D444E512', '', 'Kreatives Bauen' ) ) );
		$product_id = $this->product( 'abel-AB12' );

		$categories->assign( $product_id, 'ed36602283b14c329e31f029bdcc7fc9,D444E512' );

		$names = wp_list_pluck( wp_get_object_terms( $product_id, Categories::TAXONOMY ), 'name' );

		$this->assertSame( array( 'Kreatives Bauen' ), $names );
	}

	/**
	 * A category added in WooCommerce survives a sync.
	 *
	 * @return void
	 */
	public function test_a_local_category_is_kept() {
		$categories = $this->categories(
			array(
				$this->row( 'A1', '', 'Kontor eins' ),
				$this->row( 'A2', '', 'Kontor zwei' ),
			)
		);

		$local      = wp_insert_term( 'Unsere eigene', Categories::TAXONOMY );
		$product_id = $this->product( 'abel-AB12' );

		$categories->assign( $product_id, 'A1' );
		wp_set_object_terms( $product_id, array( (int) $local['term_id'] ), Categories::TAXONOMY, true );

		// A second run, filing the product somewhere else in Kontor.
		$categories->assign( $product_id, 'A2' );

		$names = wp_list_pluck( wp_get_object_terms( $product_id, Categories::TAXONOMY ), 'name' );
		sort( $names );

		$this->assertSame( array( 'Kontor zwei', 'Unsere eigene' ), $names );
	}

	/**
	 * A product moved out of every Kontor category loses them.
	 *
	 * @return void
	 */
	public function test_a_product_moved_out_of_every_category_is_emptied() {
		$categories = $this->categories( array( $this->row( 'A1', '', 'Kontor eins' ) ) );
		$product_id = $this->product( 'abel-AB12' );

		$categories->assign( $product_id, 'A1' );
		$categories->assign( $product_id, '' );

		$this->assertSame( array(), wp_get_object_terms( $product_id, Categories::TAXONOMY, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * The change hash watches Categories only on a shop that imports them.
	 *
	 * @return void
	 */
	public function test_categories_are_hashed_only_when_they_are_imported() {
		$this->fake_tree(
			array(
				$this->row( 'A1', '', 'Kontor eins' ),
				$this->row( 'A2', '', 'Kontor zwei' ),
			)
		);

		$on = new ProductSync( null, $this->settings() );

		$this->assertSame( 'created', $on->import_article( $this->article( array( 'Categories' => 'A1' ) ), 1000 ) );
		$this->assertSame( 'updated', $on->import_article( $this->article( array( 'Categories' => 'A2' ) ), 1001 ) );

		$off = new ProductSync(
			null,
			$this->settings( array( Settings::SYNC_CATEGORIES => false ) )
		);

		$off->import_article( $this->article( array( 'Categories' => 'A1' ) ), 1002 );

		$this->assertSame(
			'skipped',
			$off->import_article( $this->article( array( 'Categories' => 'A2' ) ), 1003 ),
			'A shop that does not import categories was rewritten because a tree it ignores moved.'
		);
	}

	/**
	 * Importing an article files its product.
	 *
	 * @return void
	 */
	public function test_importing_an_article_files_its_product() {
		$this->fake_tree( array( $this->row( 'D444E512', '', 'Kreatives Bauen' ) ) );

		$sync = new ProductSync( null, $this->settings() );

		$this->assertSame( 'created', $sync->import_article( $this->article( array( 'Categories' => 'D444E512' ) ), 1000 ) );

		$product_id = wc_get_product_id_by_sku( 'abel-AB12' );
		$names      = wp_list_pluck( wp_get_object_terms( $product_id, Categories::TAXONOMY ), 'name' );

		$this->assertSame( array( 'Kreatives Bauen' ), $names );
	}

	/**
	 * An unreadable tree stops the run rather than drafting the shop.
	 *
	 * The important one. With the category requirement on, a tree that could not be
	 * read reads as "no article has a category", and carrying on would take the whole
	 * catalogue dark on the strength of a failed request.
	 *
	 * @return void
	 */
	public function test_an_unreadable_tree_fails_the_run_and_drafts_nothing() {
		$product = new WC_Product_Simple();
		$product->set_sku( 'abel-AB12' );
		$product->set_status( 'publish' );
		$product->update_meta_data( ProductSync::META_SYNCED_AT, 999 );
		$product->save();

		$this->fake_tree( array() );

		$run  = Status::start( ProductSync::JOB );
		$sync = new ProductSync(
			null,
			$this->settings( array( Settings::REQUIRE_CATEGORY => true ) )
		);

		$sync->import_page( 0, $run );

		$status = Status::get( ProductSync::JOB );

		$this->assertSame( 'failed', $status['state'] );
		$this->assertSame( 'publish', get_post_status( $product->get_id() ) );
	}

	/**
	 * An article with no category is drafted when the shop asks for it.
	 *
	 * @return void
	 */
	public function test_an_article_with_no_category_is_drafted() {
		$this->fake_tree( array( $this->row( 'A1', '', 'Kontor eins' ) ) );

		$sync = new ProductSync(
			null,
			$this->settings( array( Settings::REQUIRE_CATEGORY => true ) )
		);

		$this->assertSame( 'no_category', $sync->import_article( $this->article( array( 'Categories' => '' ) ), 1000 ) );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'draft', $product->get_status() );
		$this->assertSame( '1', (string) $product->get_meta( ProductSync::META_NO_CATEGORY_DRAFTED ) );
	}

	/**
	 * A category arriving later republishes the product.
	 *
	 * @return void
	 */
	public function test_a_category_arriving_republishes_the_product() {
		$this->fake_tree( array( $this->row( 'A1', '', 'Kontor eins' ) ) );

		$sync = new ProductSync(
			null,
			$this->settings( array( Settings::REQUIRE_CATEGORY => true ) )
		);

		$sync->import_article( $this->article( array( 'Categories' => '' ) ), 1000 );
		$sync->import_article( $this->article( array( 'Categories' => 'A1' ) ), 1001 );

		$product = wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );

		$this->assertSame( 'publish', $product->get_status() );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_NO_CATEGORY_DRAFTED ) );
	}

	/**
	 * The requirement does nothing while the category import is off.
	 *
	 * There is no tree to answer the question against, so asking it anyway would draft
	 * every article in the shop.
	 *
	 * @return void
	 */
	public function test_the_requirement_is_inert_without_the_category_import() {
		$sync = new ProductSync(
			null,
			$this->settings(
				array(
					Settings::SYNC_CATEGORIES  => false,
					Settings::REQUIRE_CATEGORY => true,
				)
			)
		);

		$this->assertSame( 'created', $sync->import_article( $this->article( array( 'Categories' => '' ) ), 1000 ) );
	}

	/**
	 * Kontor switching an article off still outranks the category requirement.
	 *
	 * @return void
	 */
	public function test_an_inactive_article_reports_that_rather_than_its_categories() {
		$this->fake_tree( array( $this->row( 'A1', '', 'Kontor eins' ) ) );

		$sync = new ProductSync(
			null,
			$this->settings( array( Settings::REQUIRE_CATEGORY => true ) )
		);

		$row = $this->article(
			array(
				'Categories' => '',
				'Ws_aktiv'   => false,
			)
		);

		$this->assertSame( 'inactive', $sync->import_article( $row, 1000 ) );
	}

	/**
	 * A bare product carrying an article number.
	 *
	 * @param string $sku Article number.
	 * @return int Product ID.
	 */
	private function product( $sku ) {
		$product = new WC_Product_Simple();
		$product->set_sku( $sku );
		$product->set_status( 'publish' );

		return $product->save();
	}

	/**
	 * An article row.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'        => 'abel-AB12',
				'Bez1'         => 'Abel blocks 12',
				'Shoptitel'    => 'Abel blocks 12',
				'UVP'          => 81.9000,
				'Lagerbestand' => 24,
				'MainImageURL' => null,
				'Categories'   => '',
			),
			$overrides
		);
	}
}
