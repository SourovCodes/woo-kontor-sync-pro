<?php
/**
 * The products this plugin has taken out of the shop, on the products screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\StockSync;
use WP_Post;
use WP_Query;

defined( 'ABSPATH' ) || exit;

/**
 * Names and finds the products the syncs are holding back.
 *
 * Four things can take a product out of the shop here — Kontor switching an article
 * off for the webshop, Kontor listing it without a picture, Kontor dropping it from
 * the catalogue altogether, and a shop that asked for articles with no stock record to
 * be hidden — and each records its own marker so that one reason going away cannot
 * republish a product another reason still applies to. Every one of those keys is
 * underscore-prefixed and therefore protected, which is what keeps this plugin's
 * storage out of the Custom Fields panel, and it is also what left a shop manager
 * looking at eight hundred drafts with nothing anywhere in wp-admin to say why.
 *
 * This is that place: a view on the products list per reason that has any products in
 * it, and the reason spelled out beside the product's own name. Read-only, like every
 * other screen this plugin adds to somebody else's — the markers are rewritten by
 * background jobs, so the way to put a product back in the shop is to change the
 * article in the ERP and let the next sync follow it.
 *
 * A view is offered only for a reason that currently holds something, so a shop where
 * nothing is held back sees no new links at all rather than a row of zeroes.
 *
 * The last view is the inverse of all of them: the drafts nothing here accounts for,
 * which are the ones a person made. Core's Drafts view stops being useful the moment
 * eight hundred of the ERP's are sitting in it, and separating the two is the whole of
 * what a custom post status would have bought — without registering a status the rest
 * of the site has never heard of, and that the editor's own status dropdown would
 * silently overwrite the first time somebody saved a product.
 */
class HeldProducts {

	/**
	 * The query variable naming which reason to filter the list by.
	 */
	const QUERY_VAR = 'wksync_held';

	/**
	 * The value asking for every reason at once.
	 */
	const ANY = 'any';

	/**
	 * The value asking for the drafts no reason of ours accounts for.
	 */
	const NONE = 'none';

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'views_edit-product', array( $this, 'add_views' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_filter( 'display_post_states', array( $this, 'add_state' ), 10, 2 );
	}

	/**
	 * The reasons a product can be held back, keyed by the slug that names them.
	 *
	 * The slugs match the vocabulary ProductSync::withheld_reason() already uses, so
	 * there is one set of names for a condition rather than two. The meta key is what
	 * the list is actually filtered on; it stays out of the URL because it is this
	 * plugin's storage rather than a published name.
	 *
	 * Ordered as the syncs decide them: Kontor's own verdict on the article first, then
	 * this shop's settings, then the markers left by a version that no longer runs.
	 *
	 * @return array<string,string> Slug to meta key.
	 */
	public static function reasons() {
		return array(
			'inactive'     => ProductSync::META_INACTIVE_DRAFTED,
			'no_image'     => ProductSync::META_NO_IMAGE_DRAFTED,
			'delisted'     => ProductSync::META_SYNC_DRAFTED,
			'no_stock'     => StockSync::META_STOCK_DRAFTED,
			'legacy_stock' => ProductSync::META_LEGACY_STOCK_DRAFTED,
		);
	}

	/**
	 * How each reason reads on screen.
	 *
	 * Every one of them names Kontor or the sync rather than the product, because the
	 * product is not at fault and there is nothing to correct on it. "Switched off in
	 * Kontor" tells a shop manager where to go; "hidden" would not.
	 *
	 * @param string $slug Reason slug, or NONE.
	 * @return string Label, or an empty string for a slug that names neither.
	 */
	public static function label( $slug ) {
		$labels = array(
			'inactive'     => __( 'Switched off in Kontor', 'woo-kontor-sync-pro' ),
			'no_image'     => __( 'No image in Kontor', 'woo-kontor-sync-pro' ),
			'delisted'     => __( 'No longer in Kontor’s catalogue', 'woo-kontor-sync-pro' ),
			'no_stock'     => __( 'No stock record in Kontor', 'woo-kontor-sync-pro' ),
			'legacy_stock' => __( 'Held back by an earlier stock sync', 'woo-kontor-sync-pro' ),
			self::NONE     => __( 'Drafts the sync did not make', 'woo-kontor-sync-pro' ),
		);

		return isset( $labels[ $slug ] ) ? $labels[ $slug ] : '';
	}

	/**
	 * The meta clause one value of the query variable stands for.
	 *
	 * Shared by the list filter and by the count beside each link, so a view cannot
	 * promise a number the list it opens then disagrees with.
	 *
	 * One reason is a flat clause rather than a group of one — that is every case but
	 * ANY and NONE, so the commonest filter costs the list one join instead of two.
	 *
	 * ANY is one clause over every key rather than a group of EXISTS clauses joined by
	 * OR, and the difference is the difference between a page that loads and one that
	 * never answers. WP_Meta_Query gives each clause in an OR group its own INNER JOIN
	 * on the meta table, so five of them multiply out: every combination of five meta
	 * rows on the same product, before the WHERE picks any of them. A product carries
	 * a couple of dozen rows, so that is millions of combinations per product, and on
	 * a catalogue of four thousand the query does not return at all. Written as one
	 * `meta_key IN (…)` it is a single indexed join — measured on the development
	 * site's 4386 articles, 829 rows in 5ms against a query that had to be killed.
	 *
	 * NONE cannot be written the same way, and it is not an oversight. `compare_key`
	 * with `NOT EXISTS` builds a LEFT JOIN with no ON clause at all — the SQL is
	 * malformed and the database refuses it — so the inverse stays a group of NOT
	 * EXISTS clauses. That group costs nothing like the same: a NOT EXISTS clause is a
	 * LEFT JOIN tested for NULL, which matches at most one row per key per product
	 * rather than multiplying, and it measures at a tenth of a second on the same
	 * catalogue.
	 *
	 * @param string $slug Reason slug, ANY, or NONE.
	 * @return array Meta query clause.
	 */
	protected static function clauses( $slug ) {
		$reasons = self::reasons();

		if ( self::ANY !== $slug && self::NONE !== $slug ) {
			return array(
				'key'     => $reasons[ $slug ],
				'compare' => 'EXISTS',
			);
		}

		if ( self::ANY === $slug ) {
			return array(
				'key'         => array_values( $reasons ),
				'compare_key' => 'IN',
				'compare'     => 'EXISTS',
			);
		}

		// None of them means every one has to be absent.
		$group = array( 'relation' => 'AND' );

		foreach ( $reasons as $meta_key ) {
			$group[] = array(
				'key'     => $meta_key,
				'compare' => 'NOT EXISTS',
			);
		}

		return $group;
	}

	/**
	 * How many drafts nothing here is holding back.
	 *
	 * The drafts a person made, in other words — which is the question the reason views
	 * leave unanswered once eight hundred of somebody else's are sitting in the same
	 * list. Counted through WP_Query against the same clause the view filters on, so
	 * the number beside the link is the number of rows behind it by construction.
	 *
	 * @return int Number of products.
	 */
	public static function unheld_drafts() {
		$query = new WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'draft',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Protected meta has no CRUD equivalent to ask through, and the alternative is the same joins written by hand.
				'meta_query'             => array( self::clauses( self::NONE ) ),
			)
		);

		return (int) $query->found_posts;
	}

	/**
	 * How many products each reason is holding back.
	 *
	 * One counting query per reason against an indexed column. Deliberately not
	 * memoised: each screen asks once, and a static cache would only mean one test's
	 * answer surviving into the next.
	 *
	 * Counted on the marker alone, never joined to the post status. A product somebody
	 * has published by hand still carries the marker and the next sync will draft it
	 * again, so leaving it out would hide the one case worth seeing.
	 *
	 * @return array<string,int> Slug to number of products, including the zeroes.
	 */
	public static function counts() {
		global $wpdb;

		$counts = array();

		foreach ( self::reasons() as $slug => $meta_key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting protected meta has no CRUD equivalent, and a count served from cache would disagree with the list it labels.
			$total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key ) );

			$counts[ $slug ] = (int) $total;
		}

		return $counts;
	}

	/**
	 * How many products are held back for any reason at all.
	 *
	 * @return int Number of products.
	 */
	public static function total() {
		return array_sum( self::counts() );
	}

	/**
	 * The products list, filtered to one reason or to all of them.
	 *
	 * @param string $slug Reason slug, or ANY for every reason.
	 * @return string Admin URL.
	 */
	public static function url( $slug = self::ANY ) {
		$args = array(
			'post_type'     => 'product',
			self::QUERY_VAR => $slug,
		);

		/*
		 * The inverse view is about drafts, and it says so in the URL rather than by
		 * forcing a status onto the query: core's own status handling stays in charge,
		 * and "nothing here is holding this back" remains a question that can be asked
		 * about a published product too.
		 */
		if ( self::NONE === $slug ) {
			$args['post_status'] = 'draft';
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Add a view per reason to the products list.
	 *
	 * @param array $views Views keyed by name.
	 * @return array Views with this plugin's added.
	 */
	public function add_views( $views ) {
		$current = $this->requested_slug();

		/*
		 * Core marks "All" as the current view by looking for the absence of its own
		 * filters, and ours is not one of them, so both would be highlighted at once.
		 * The links are built and escaped by core; this only takes the marking off them.
		 */
		if ( '' !== $current ) {
			foreach ( $views as $name => $view ) {
				$views[ $name ] = str_replace(
					array( ' class="current"', ' aria-current="page"' ),
					'',
					$view
				);
			}
		}

		foreach ( self::counts() as $slug => $count ) {
			if ( $count < 1 ) {
				continue;
			}

			$views[ self::QUERY_VAR . '_' . $slug ] = $this->view( $slug, $count, $current );
		}

		/*
		 * The inverse, and only where something is actually held back: on a shop where
		 * nothing is, it would say precisely what core's own Drafts view already says.
		 * Asked last for the same reason — a shop holding nothing back never runs the
		 * query at all.
		 */
		if ( array_sum( self::counts() ) > 0 ) {
			$unheld = self::unheld_drafts();

			if ( $unheld > 0 ) {
				$views[ self::QUERY_VAR . '_' . self::NONE ] = $this->view( self::NONE, $unheld, $current );
			}
		}

		return $views;
	}

	/**
	 * One view link, counted and marked current where it is the one being looked at.
	 *
	 * @param string $slug    Reason slug, or NONE.
	 * @param int    $count   How many products are behind it.
	 * @param string $current Slug the request is asking for, if any.
	 * @return string Anchor markup.
	 */
	protected function view( $slug, $count, $current ) {
		return sprintf(
			'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
			esc_url( self::url( $slug ) ),
			$slug === $current ? ' class="current" aria-current="page"' : '',
			esc_html( self::label( $slug ) ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Restrict the products list to the requested reason.
	 *
	 * The meta query is appended rather than assigned: WooCommerce's own stock filter
	 * puts one on the same query, and replacing it would silently widen whatever the
	 * shop manager had already narrowed.
	 *
	 * @param WP_Query $query Query about to run.
	 * @return void
	 */
	public function filter_query( $query ) {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}

		if ( 'product' !== $query->get( 'post_type' ) ) {
			return;
		}

		$slug = $this->requested_slug();

		if ( '' === $slug ) {
			return;
		}

		$existing = $query->get( 'meta_query' );
		$existing = is_array( $existing ) ? $existing : array();

		$existing[] = self::clauses( $slug );

		$query->set( 'meta_query', $existing );
	}

	/**
	 * Say why a product is held back, beside its name in the list.
	 *
	 * A product can carry more than one marker — the conditions are decided on
	 * different feeds and clear at different moments — so every reason that applies is
	 * named rather than the first one found.
	 *
	 * @param array   $states Post states.
	 * @param WP_Post $post   Post being listed.
	 * @return array States with this plugin's added.
	 */
	public function add_state( $states, $post ) {
		if ( ! $post instanceof WP_Post || 'product' !== $post->post_type ) {
			return $states;
		}

		foreach ( self::reasons() as $slug => $meta_key ) {
			if ( '' === (string) get_post_meta( $post->ID, $meta_key, true ) ) {
				continue;
			}

			$states[ self::QUERY_VAR . '_' . $slug ] = self::label( $slug );
		}

		return $states;
	}

	/**
	 * Which reason the request is asking for.
	 *
	 * @return string Reason slug, ANY, or an empty string when the list is unfiltered.
	 */
	protected function requested_slug() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading which view of a list to show changes nothing.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- As above.
		$slug = sanitize_key( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		if ( self::ANY === $slug || self::NONE === $slug ) {
			return $slug;
		}

		return array_key_exists( $slug, self::reasons() ) ? $slug : '';
	}
}
