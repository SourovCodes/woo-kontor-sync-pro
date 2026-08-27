<?php
/**
 * Sending this shop's category tree to Kontor.
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
 * Replaces the category tree Kontor holds for this shop with WooCommerce's own.
 *
 * Kept in its own class rather than beside Categories, for the reason
 * OrderSync::interpret_force_rows() is kept apart from interpret_rows(): this path
 * destroys data in the ERP and the routine one must have no way of drifting into it.
 * Nothing schedules this, nothing calls it from a sync, and it is never reached except
 * by somebody pressing a button and typing a word.
 *
 * The one thing that governs every decision here is what overwrite_all does. Kontor
 * replaces the shop's whole tree with the payload, and a category the payload leaves
 * out does not merely disappear — the product assignments hanging off it go with it.
 * So:
 *
 * - the whole taxonomy goes in one request, and nothing is ever batched;
 * - a tree too large to send is refused rather than truncated, because a truncated
 *   payload under overwrite_all is the destructive outcome and not a smaller one;
 * - a category that already carries a Katid is sent under that Katid, which is what
 *   keeps Kontor's existing product assignments attached across a push.
 *
 * Its behaviour has never been established against a live account. Everything else
 * this plugin knows about the Kontor API was found by probing it; this was not, so the
 * screen says so and the reply is printed verbatim rather than summarised.
 */
class CategoryPush {

	/**
	 * The largest tree this will send.
	 *
	 * A bound rather than a page size: over it, the push is refused and nothing is
	 * sent. Sending the first thousand of a larger tree would ask Kontor to delete
	 * everything after them, along with every product assignment they carry, which is
	 * the one outcome worse than not running at all.
	 */
	const MAX_TERMS = 1000;

	/**
	 * Prefix for the ID minted for a category Kontor has never seen.
	 *
	 * Prefixed rather than a bare term ID, because three of the four shops sampled on
	 * this account use small integers as Katids. A raw term ID would eventually collide
	 * with one of Kontor's own and silently merge two categories.
	 */
	const KATID_PREFIX = 'wc-';

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
	 * Constructor.
	 *
	 * @param Client|null $client   Optional client override, mainly for tests.
	 * @param array|null  $settings Optional settings override, mainly for tests.
	 */
	public function __construct( $client = null, $settings = null ) {
		$this->settings = null === $settings ? Settings::get_settings() : $settings;
		$this->client   = null === $client ? new Client( $this->settings ) : $client;
	}

	/**
	 * The rows that would be sent.
	 *
	 * Public because it is also the preview: the only way to see what a replacing push
	 * will contain, on a live account, without performing one.
	 *
	 * Ordered parents first. Kontor asks only that a parent ID be valid or empty, but a
	 * tree that reads in order is one somebody can check before pressing the button.
	 *
	 * @return array|WP_Error Rows of katid, katidparent and katname, or the refusal.
	 */
	public function payload() {
		if ( ! taxonomy_exists( Categories::TAXONOMY ) ) {
			return new WP_Error(
				'wksync_no_taxonomy',
				__( 'WooCommerce product categories are not available.', 'woo-kontor-sync-pro' )
			);
		}

		$terms = get_terms(
			array(
				'taxonomy'   => Categories::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$terms = array_filter(
			is_array( $terms ) ? $terms : array(),
			static function ( $term ) {
				return $term instanceof WP_Term;
			}
		);

		if ( empty( $terms ) ) {
			return new WP_Error(
				'wksync_no_categories',
				__( 'This shop has no product categories to send.', 'woo-kontor-sync-pro' )
			);
		}

		if ( count( $terms ) > self::MAX_TERMS ) {
			return new WP_Error(
				'wksync_too_many_categories',
				sprintf(
					/* translators: 1: number of categories in the shop, 2: the largest number that can be sent. */
					__( 'Nothing was sent: this shop has %1$d product categories and at most %2$d can be sent at once. Sending part of the tree would ask Kontor to delete the rest, along with the product assignments on it.', 'woo-kontor-sync-pro' ),
					count( $terms ),
					self::MAX_TERMS
				)
			);
		}

		$katids = array();

		foreach ( $terms as $term ) {
			$katids[ (int) $term->term_id ] = self::katid( $term );
		}

		$rows = array();

		foreach ( self::sorted( $terms ) as $term ) {
			$parent = (int) $term->parent;

			/*
			 * The parent is empty at the top level, which is the shape Kontor asks for. A
			 * parent outside the payload cannot arise — every term in the taxonomy is
			 * here — but a term whose parent was deleted underneath it reads as a root,
			 * which is what WordPress shows on the categories screen anyway.
			 */
			$rows[] = array(
				'katid'       => $katids[ (int) $term->term_id ],
				'katidparent' => isset( $katids[ $parent ] ) ? $katids[ $parent ] : '',
				'katname'     => $term->name,
			);
		}

		return $rows;
	}

	/**
	 * Replace Kontor's tree for this shop.
	 *
	 * @return array Result carrying the counts, the reply and any refusal.
	 */
	public function push() {
		$result = array(
			'sent'  => 0,
			'rows'  => array(),
			'raw'   => array(),
			'error' => '',
		);

		$shop_id = isset( $this->settings['shop_id'] ) ? trim( (string) $this->settings['shop_id'] ) : '';

		if ( '' === $shop_id || ! Settings::is_shop_id( $shop_id ) ) {
			$result['error'] = __( 'No Kontor shop has been selected. Choose one before sending categories.', 'woo-kontor-sync-pro' );

			return $result;
		}

		$rows = $this->payload();

		if ( is_wp_error( $rows ) ) {
			$result['error'] = $rows->get_error_message();
			$this->log( 'error', 'Category push refused: ' . $rows->get_error_message() );

			return $result;
		}

		$result['rows'] = $rows;

		/*
		 * One attempt, for the reason OrderSync::force_push() makes one: this runs in the
		 * request that asked for it, and a retried timeout is a longer blank screen
		 * rather than a better answer.
		 */
		$response = $this->client->push_categories( $rows, $shop_id, OrderSync::UPLOAD_USER_ID, true, Client::SINGLE_ATTEMPT );

		if ( is_wp_error( $response ) ) {
			$result['error'] = $response->get_error_message();
			$raw             = $response->get_error_data();

			if ( is_array( $raw ) && isset( $raw['raw'] ) && is_array( $raw['raw'] ) ) {
				$result['raw'] = $raw['raw'];
			}

			$this->log( 'error', 'Category push failed: ' . $response->get_error_message() );

			return $result;
		}

		$result['sent'] = count( $rows );
		$result['raw']  = isset( $response['raw'] ) && is_array( $response['raw'] ) ? $response['raw'] : array();

		$this->stamp();

		$this->log( 'notice', sprintf( 'Category push finished: %d categories sent to Kontor.', count( $rows ) ) );

		return $result;
	}

	/**
	 * Record the minted IDs on the terms that had none.
	 *
	 * Bookkeeping rather than a correctness requirement: the ID is derived from the term
	 * ID, so a second push mints exactly the same one whether this ran or not. What it
	 * buys is the import side — a category sent from here comes back on an article's
	 * Categories field under this ID, and the stamp is what lets Categories::map() match
	 * it instead of creating a second term beside it.
	 *
	 * @return void
	 */
	protected function stamp() {
		$terms = get_terms(
			array(
				'taxonomy'   => Categories::TAXONOMY,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return;
		}

		foreach ( $terms as $term ) {
			if ( ! $term instanceof WP_Term ) {
				continue;
			}

			if ( '' !== trim( (string) get_term_meta( $term->term_id, Categories::TERM_META_ID, true ) ) ) {
				continue;
			}

			update_term_meta( $term->term_id, Categories::TERM_META_ID, self::katid( $term ) );
		}
	}

	/**
	 * The ID a category is sent under.
	 *
	 * A category Kontor already knows keeps its own ID, which is the whole of what
	 * preserves its product assignments through a replacing push. One it has never seen
	 * is minted from the term ID, deterministically, so a retry after a failure sends
	 * the same tree rather than a second copy of it.
	 *
	 * @param WP_Term $term Term to identify.
	 * @return string Katid.
	 */
	public static function katid( WP_Term $term ) {
		$stored = trim( (string) get_term_meta( $term->term_id, Categories::TERM_META_ID, true ) );

		return '' === $stored ? self::KATID_PREFIX . (int) $term->term_id : $stored;
	}

	/**
	 * Order terms so that every parent precedes its children.
	 *
	 * @param WP_Term[] $terms Terms to order.
	 * @return WP_Term[] The same terms, parents first.
	 */
	protected static function sorted( array $terms ) {
		$children = array();

		foreach ( $terms as $term ) {
			$children[ (int) $term->parent ][] = $term;
		}

		$ordered = array();
		$queue   = isset( $children[0] ) ? $children[0] : array();

		/*
		 * Breadth-first from the roots, and a term whose parent is not in the taxonomy is
		 * picked up at the end rather than lost. A cycle would leave its members out of
		 * the payload, which under overwrite_all would delete them in the ERP, so the
		 * sweep below is what makes the list complete whatever shape the tree is in.
		 */
		while ( ! empty( $queue ) ) {
			$term      = array_shift( $queue );
			$ordered[] = $term;
			$id        = (int) $term->term_id;

			if ( isset( $children[ $id ] ) ) {
				foreach ( $children[ $id ] as $child ) {
					$queue[] = $child;
				}

				unset( $children[ $id ] );
			}
		}

		$seen = array();

		foreach ( $ordered as $term ) {
			$seen[ (int) $term->term_id ] = true;
		}

		foreach ( $terms as $term ) {
			if ( ! isset( $seen[ (int) $term->term_id ] ) ) {
				$ordered[] = $term;
			}
		}

		return $ordered;
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
