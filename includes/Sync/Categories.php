<?php
/**
 * Product categories mapped from Kontor's per-shop category tree.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WooKontorSync\Admin\Settings;
use WooKontorSync\Api\Client;
use WP_Error;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Kontor's category tree onto WooCommerce product categories.
 *
 * The tree belongs to a shop rather than to the account: the categories entity returns
 * nothing at all unless it is sent a shopid, which is why this plugin recorded it for a
 * long time as an entity that returns zero rows. Sent one, it answers with that shop's
 * whole tree in a single reply.
 *
 * Three things about the data decide almost everything here, and each of them fails
 * silently rather than loudly if it is ignored:
 *
 * - **Katid is an opaque string.** Across four shops sampled on one account it arrives
 *   as canonical GUIDs, as 32-character hex without hyphens, and as bare integers such
 *   as "15" and "1435". Casting it collides values in exactly the way casting
 *   Herstellerid would collide "084" with "84".
 * - **Names repeat inside one tree.** "Soziales Lernen" appears six times on one shop
 *   and "Piraten" four times on another, and one shop carries two categories called
 *   "Waldtiere" under the *same* parent. A term is therefore matched on its Katid,
 *   recorded in term meta, and never on its name.
 * - **Katname can arrive HTML-encoded**, on 74 of 141 rows on one shop — "Emotionen
 *   &amp; Empathie". It is decoded before it becomes a term name.
 *
 * The tree is also deeper than it looks: five levels on the largest shop sampled. A
 * child cannot be created before its parent exists, so every row is resolved by walking
 * its parent chain first.
 */
class Categories {

	/**
	 * WooCommerce's product category taxonomy.
	 */
	const TAXONOMY = 'product_cat';

	/**
	 * Term meta holding Kontor's category ID.
	 */
	const TERM_META_ID = '_wksync_katid';

	/**
	 * Plugin settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * API client.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Katid to term ID, or the error that stopped it being built.
	 *
	 * Memoised so a page of two hundred articles costs one request and one reconciliation
	 * rather than two hundred. Null means it has not been asked for yet, which is a
	 * different thing from an empty tree.
	 *
	 * @var array|WP_Error|null
	 */
	private $map = null;

	/**
	 * Constructor.
	 *
	 * @param Client|null $client    Optional client override, mainly for tests.
	 * @param array|null  $settings  Optional settings override, mainly for tests.
	 * @param bool        $read_only Whether to answer without creating or editing terms.
	 */
	public function __construct( $client = null, $settings = null, $read_only = false ) {
		$this->settings  = null === $settings ? Settings::get_settings() : $settings;
		$this->client    = null === $client ? new Client( $this->settings ) : $client;
		$this->read_only = (bool) $read_only;
	}

	/**
	 * Whether this instance may create and edit terms.
	 *
	 * Building the tree is a reconciliation: it creates the categories Kontor lists,
	 * adopts the ones this shop already had and renames what moved. That is the right
	 * thing on a run and the wrong thing entirely in a preview, which exists to say
	 * what *would* happen without anything happening.
	 *
	 * Read-only, `map()` answers with the Katids Kontor lists and a term ID of 0 for
	 * each. Nothing downstream minds: `has_category()` only asks whether a key is
	 * there, which is what makes the withheld decision one code path rather than a
	 * preview copy of it that could drift.
	 *
	 * @var bool
	 */
	private $read_only = false;

	/**
	 * The shop's tree, as a map of Katid to term ID.
	 *
	 * Keys are lower-cased, because nothing guarantees that the spelling on an article
	 * matches the spelling in the tree; the two agree on the account this was built
	 * against, and agreeing is not the same as being guaranteed to.
	 *
	 * @return array|WP_Error Map of lower-cased Katid to term ID, or the failure.
	 */
	public function map() {
		if ( null === $this->map ) {
			$this->map = $this->load();
		}

		return $this->map;
	}

	/**
	 * Fetch the tree and reconcile it against the taxonomy.
	 *
	 * An empty reply is an error rather than an empty tree, and that distinction is the
	 * most important line in this class. Zero rows is exactly what a request without a
	 * shopid returns, so treating it as "this shop has no categories" would, with the
	 * category requirement switched on, draft every article in the catalogue on the
	 * strength of a misconfigured request. It is the same trap Preflight exists to keep
	 * the catalogue walk out of.
	 *
	 * @return array|WP_Error Map of lower-cased Katid to term ID, or the failure.
	 */
	protected function load() {
		if ( ! taxonomy_exists( self::TAXONOMY ) ) {
			return new WP_Error(
				'wksync_no_taxonomy',
				__( 'WooCommerce product categories are not available.', 'woo-kontor-sync-pro' )
			);
		}

		$shop_id = isset( $this->settings['shop_id'] ) ? trim( (string) $this->settings['shop_id'] ) : '';

		if ( '' === $shop_id || ! Settings::is_shop_id( $shop_id ) ) {
			return new WP_Error(
				'wksync_no_shop',
				__( 'No Kontor shop has been selected, so there is no category tree to import.', 'woo-kontor-sync-pro' )
			);
		}

		$response = $this->client->fetch_categories( $shop_id );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$rows = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$tree = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$katid = isset( $row['Katid'] ) ? trim( (string) $row['Katid'] ) : '';
			$name  = self::clean( isset( $row['Katname'] ) ? $row['Katname'] : '' );

			// A row this cannot name or recognise is not a category anything could be
			// filed under, and inventing an identity for it would import it twice.
			if ( '' === $katid || '' === $name ) {
				continue;
			}

			$tree[ strtolower( $katid ) ] = array(
				'katid'  => $katid,
				'parent' => isset( $row['Katidparent'] ) ? strtolower( trim( (string) $row['Katidparent'] ) ) : '',
				'name'   => $name,
			);
		}

		if ( empty( $tree ) ) {
			return new WP_Error(
				'wksync_empty_category_tree',
				__( 'Kontor returned no categories for this shop.', 'woo-kontor-sync-pro' )
			);
		}

		/*
		 * Everything above this point is a read of Kontor's tree; everything below it
		 * writes terms. A preview stops here — the keys are the whole of what the
		 * withheld decision reads, and creating a shop's categories is not something to
		 * do on the way to telling somebody what a run would do.
		 */
		if ( $this->read_only ) {
			return array_fill_keys( array_keys( $tree ), 0 );
		}

		$terms = $this->existing_terms();

		foreach ( array_keys( $tree ) as $key ) {
			$this->resolve( $key, $tree, $terms, array() );
		}

		// Only the categories Kontor currently lists. A term whose category has been
		// dropped from the tree keeps its stamp and its place in the shop — nothing here
		// deletes a term, because a term takes its URL and its manual assignments with it.
		return array_intersect_key( $terms, $tree );
	}

	/**
	 * Every term already carrying a Kontor category ID.
	 *
	 * One query for the terms and one for their meta, which get_terms() primes on the
	 * way past. The map is what tells a term this plugin manages from one the shop made.
	 *
	 * @return array Map of lower-cased Katid to term ID.
	 */
	protected function existing_terms() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The ID is only in term meta, and this runs once per page action in a background job.
				'meta_key'   => self::TERM_META_ID,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$map = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			$katid = trim( (string) get_term_meta( $term->term_id, self::TERM_META_ID, true ) );

			if ( '' === $katid ) {
				continue;
			}

			$map[ strtolower( $katid ) ] = (int) $term->term_id;
		}

		return $map;
	}

	/**
	 * Find or create the term for one category, creating its ancestors first.
	 *
	 * @param string $key      Lower-cased Katid to resolve.
	 * @param array  $tree     The whole tree, keyed by lower-cased Katid.
	 * @param array  $terms    Katid to term ID, added to as terms are created.
	 * @param array  $pending  Katids already being resolved further up this chain.
	 * @return int Term ID, or 0 when there is nothing to map.
	 */
	protected function resolve( $key, array $tree, array &$terms, array $pending ) {
		if ( ! isset( $tree[ $key ] ) ) {
			return 0;
		}

		/*
		 * No tree sampled contains a cycle, and a tree is not supposed to. But this walks
		 * parent links supplied by somebody else's database, and a cycle in one would
		 * recurse until PHP died rather than produce a wrong answer somebody could see.
		 */
		if ( isset( $pending[ $key ] ) ) {
			$this->log(
				'warning',
				sprintf( 'Category %s is its own ancestor in Kontor; filing it at the top level.', $tree[ $key ]['katid'] )
			);

			return 0;
		}

		$pending[ $key ] = true;
		$row             = $tree[ $key ];

		// A blank parent is a root. A parent naming a category the tree does not carry is
		// treated as one too: the alternative is dropping the category altogether, and a
		// visible category in the wrong place beats an invisible one.
		$parent_id = '' === $row['parent'] ? 0 : $this->resolve( $row['parent'], $tree, $terms, $pending );

		if ( isset( $terms[ $key ] ) ) {
			$this->follow( $terms[ $key ], $row, $parent_id );

			return $terms[ $key ];
		}

		$term_id = $this->create( $row, $parent_id );

		if ( $term_id ) {
			update_term_meta( $term_id, self::TERM_META_ID, $row['katid'] );

			$terms[ $key ] = $term_id;
		}

		return $term_id;
	}

	/**
	 * Follow a rename or a move made in the ERP.
	 *
	 * The slug is deliberately left alone on a rename, for the reason Brands::rename()
	 * leaves it alone: changing it breaks every URL already pointing at the category
	 * archive, and WordPress keeps a stale slug working perfectly well beside a new name.
	 *
	 * The name is compared against what WordPress would *store* rather than against what
	 * Kontor sent, and that is not a nicety. Core puts every term name through
	 * `pre_term_name`, which carries both sanitize_text_field() and _wp_specialchars() —
	 * so "Emotionen & Empathie" is stored as "Emotionen &amp; Empathie" and
	 * "Rabatt 20%ab Lager" is stored as "Rabatt 20 Lager", whether the name came from
	 * here or was typed into wp-admin. Comparing the raw name against the stored one
	 * therefore never matches, and this would call wp_update_term() on every affected
	 * category on every run for ever. On one live shop that is 74 categories of 141.
	 *
	 * @param int   $term_id   Term to update.
	 * @param array $row       Tree row carrying the name Kontor now uses.
	 * @param int   $parent_id Term ID of the parent Kontor now gives it.
	 * @return void
	 */
	protected function follow( $term_id, array $row, $parent_id ) {
		$term = get_term( $term_id, self::TAXONOMY );

		if ( ! $term instanceof WP_Term ) {
			return;
		}

		$args     = array();
		$storable = sanitize_term_field( 'name', $row['name'], $term_id, self::TAXONOMY, 'db' );

		if ( $term->name !== $storable ) {
			$args['name'] = $row['name'];
		}

		if ( (int) $term->parent !== (int) $parent_id ) {
			$args['parent'] = (int) $parent_id;
		}

		if ( empty( $args ) ) {
			return;
		}

		$updated = wp_update_term( $term_id, self::TAXONOMY, $args );

		if ( is_wp_error( $updated ) ) {
			$this->log(
				'warning',
				sprintf( 'Could not update category "%1$s": %2$s', $term->name, $updated->get_error_message() )
			);

			return;
		}

		$this->log(
			'info',
			sprintf( 'Followed Kontor on category "%1$s" (%2$s).', $row['name'], implode( ', ', array_keys( $args ) ) )
		);
	}

	/**
	 * Create the term for a category.
	 *
	 * WordPress refuses a second term with the same name under the same parent, which is
	 * a real case rather than a theoretical one: one shop sampled carries two categories
	 * called "Waldtiere" under one parent. What happens then depends on whether the term
	 * in the way is already spoken for.
	 *
	 * An unclaimed term is adopted and stamped, which is what stops a shop that already
	 * had "Kuscheltiere" ending up with "Kuscheltiere" and "kuscheltiere-2" side by side.
	 * That is an adoption by name, and a much narrower one than Brands makes: the parent
	 * has to match too, so it cannot pull in an unrelated category that happens to share
	 * a word.
	 *
	 * A term already carrying a *different* Katid is left alone and a distinct one is
	 * created beside it. Stealing it would collapse two of Kontor's categories into one
	 * term, and each run would then re-stamp it for whichever category was resolved last.
	 *
	 * @param array $row       Tree row.
	 * @param int   $parent_id Term ID of the parent, or 0 for a root.
	 * @return int Term ID, or 0 on failure.
	 */
	protected function create( array $row, $parent_id ) {
		$args = array( 'parent' => (int) $parent_id );

		$created = wp_insert_term( $row['name'], self::TAXONOMY, $args );

		if ( ! is_wp_error( $created ) ) {
			return (int) $created['term_id'];
		}

		$blocking = self::term_id_from_error( $created );

		if ( ! $blocking ) {
			$this->log(
				'warning',
				sprintf( 'Could not create category "%1$s": %2$s', $row['name'], $created->get_error_message() )
			);

			return 0;
		}

		$claimed = trim( (string) get_term_meta( $blocking, self::TERM_META_ID, true ) );

		if ( '' === $claimed || 0 === strcasecmp( $claimed, $row['katid'] ) ) {
			return $blocking;
		}

		/*
		 * An explicit slug is what gets past the duplicate check: wp_insert_term() only
		 * refuses a matching name when the caller supplied no slug of its own. The Katid
		 * makes it unique without inventing a counter that would move between runs.
		 */
		$args['slug'] = sanitize_title( $row['name'] . '-' . $row['katid'] );

		$created = wp_insert_term( $row['name'], self::TAXONOMY, $args );

		if ( is_wp_error( $created ) ) {
			$fallback = self::term_id_from_error( $created );

			if ( ! $fallback ) {
				$this->log(
					'warning',
					sprintf( 'Could not create category "%1$s": %2$s', $row['name'], $created->get_error_message() )
				);
			}

			return $fallback;
		}

		return (int) $created['term_id'];
	}

	/**
	 * The term ID WordPress reports alongside a "term already exists" refusal.
	 *
	 * @param WP_Error $error Error from wp_insert_term().
	 * @return int Term ID, or 0 when the error names none.
	 */
	protected static function term_id_from_error( WP_Error $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['term_id'] ) ) {
			return (int) $data['term_id'];
		}

		return is_numeric( $data ) ? (int) $data : 0;
	}

	/**
	 * File a product under the categories an article names.
	 *
	 * Only IDs belonging to this shop's tree are used. An article's Categories field is a
	 * union across every shop on the account unless the request carries a shopid — 334
	 * distinct foreign IDs appear across one catalogue — and the unfiltered value is a
	 * strict superset of the filtered one, so keeping what the tree knows gives the same
	 * answer without changing a product request every existing shop depends on.
	 *
	 * Categories the shop added itself are kept. A term is this plugin's only if the tree
	 * currently accounts for it, so a category a shop manager created, and one Kontor has
	 * since dropped, both survive a sync rather than being swept off the product.
	 *
	 * @param int    $product_id Product to file.
	 * @param string $raw        Value of the article's Categories field.
	 * @return bool True when the terms were set.
	 */
	public function assign( $product_id, $raw ) {
		$map = $this->map();

		if ( is_wp_error( $map ) ) {
			return false;
		}

		$mine = array();

		foreach ( self::katids( $raw ) as $katid ) {
			$key = strtolower( $katid );

			if ( isset( $map[ $key ] ) ) {
				$mine[] = (int) $map[ $key ];
			}
		}

		$managed = array_flip( array_map( 'intval', array_values( $map ) ) );
		$current = wp_get_object_terms( $product_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$keep    = array();

		if ( is_array( $current ) ) {
			foreach ( $current as $term_id ) {
				if ( ! isset( $managed[ (int) $term_id ] ) ) {
					$keep[] = (int) $term_id;
				}
			}
		}

		$terms  = array_values( array_unique( array_merge( $keep, $mine ) ) );
		$result = wp_set_object_terms( $product_id, $terms, self::TAXONOMY, false );

		return ! is_wp_error( $result );
	}

	/**
	 * Whether an article names any category belonging to this shop.
	 *
	 * Asked of the feed row rather than of the product, exactly as the image requirement
	 * is: the terms are written after the product is saved, so judging by the shop would
	 * hold back an article whose categories were merely still on their way.
	 *
	 * @param string $raw Value of the article's Categories field.
	 * @return bool True when at least one category applies here.
	 */
	public function has_category( $raw ) {
		$map = $this->map();

		if ( is_wp_error( $map ) ) {
			return false;
		}

		foreach ( self::katids( $raw ) as $katid ) {
			if ( isset( $map[ strtolower( $katid ) ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Split an article's Categories field into IDs.
	 *
	 * Kept as strings and never cast. The IDs are GUIDs on one shop, bare integers on
	 * another and 32-character hex on a third, and only their equality ever matters.
	 *
	 * @param mixed $raw Value of the Categories field.
	 * @return string[] Category IDs, in the order the article gives them.
	 */
	public static function katids( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return array();
		}

		$ids = array_map( 'trim', explode( ',', (string) $raw ) );

		return array_values( array_filter( $ids, 'strlen' ) );
	}

	/**
	 * Turn a Katname into a term name.
	 *
	 * Entities are decoded first, because Kontor hands them over encoded on some shops
	 * and they would otherwise stack up: WordPress encodes a term name on the way into
	 * the database, so an already-encoded one arriving unchanged would eventually read
	 * as "&amp;amp;". Tags are then stripped, and deliberately not with
	 * sanitize_text_field(), which eats percent-encoded octets.
	 *
	 * Note that WordPress applies both of those itself, on `pre_term_name`, and there is
	 * nothing to be done about it: a category named "Rabatt 20%ab Lager" is stored as
	 * "Rabatt 20 Lager" whether it came from Kontor or was typed into wp-admin. What
	 * this decides is only what is handed over; follow() is where that difference has to
	 * be accounted for.
	 *
	 * @param mixed $name Raw Katname.
	 * @return string Term name.
	 */
	public static function clean( $name ) {
		if ( ! is_scalar( $name ) ) {
			return '';
		}

		$name = wp_specialchars_decode( (string) $name, ENT_QUOTES );

		return trim( wp_strip_all_tags( $name ) );
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to record.
	 * @return void
	 */
	protected function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => Client::LOG_SOURCE ) );
	}
}
