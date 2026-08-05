<?php
/**
 * Brand terms mapped from Kontor manufacturers.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WooKontorSync\Api\Client;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Kontor's Hersteller onto WooCommerce brand terms.
 *
 * Terms are matched on Herstellerid, recorded in term meta the first time a
 * manufacturer is seen, and only on the name when no ID is available. That is what
 * makes a rename in the ERP a rename here: matching on the name alone cannot tell
 * a renamed manufacturer from a new one, so every product would move to a fresh
 * brand and leave the old term behind, unused and still attached to nothing.
 */
class Brands {

	/**
	 * WooCommerce's brand taxonomy, part of core since 9.6.
	 */
	const TAXONOMY = 'product_brand';

	/**
	 * Term meta holding Kontor's manufacturer ID.
	 */
	const TERM_META_ID = '_wksync_herstellerid';

	/**
	 * Assign a product to the brand named by an article row.
	 *
	 * A row with no manufacturer leaves the product's existing brand alone rather
	 * than clearing it, so a brand set by hand is not wiped on every run.
	 *
	 * @param int    $product_id Product to assign.
	 * @param string $name       Value of Hersteller.
	 * @param string $id         Value of Herstellerid, when the row carries one.
	 * @return bool True when a brand was assigned.
	 */
	public static function assign( $product_id, $name, $id = '' ) {
		$term_id = self::resolve( $name, $id );

		if ( ! $term_id ) {
			return false;
		}

		$result = wp_set_object_terms( $product_id, array( $term_id ), self::TAXONOMY, false );

		return ! is_wp_error( $result );
	}

	/**
	 * Find or create the brand term for a Kontor manufacturer.
	 *
	 * @param string $name Value of Hersteller.
	 * @param string $id   Value of Herstellerid, when the row carries one.
	 * @return int Term ID, or 0 when there is nothing to map.
	 */
	public static function resolve( $name, $id = '' ) {
		/*
		 * Not sanitize_text_field(): it strips percent-encoded octets, so a name
		 * carrying one would silently lose characters. Stripping tags is the actual
		 * requirement; WooCommerce escapes on output.
		 */
		$name = trim( wp_strip_all_tags( (string) $name ) );

		/*
		 * Kept as a string, never cast to an integer. Kontor's manufacturer IDs carry
		 * leading zeros — "084" is a real value — and casting would collide it with 84.
		 */
		$id = trim( (string) $id );

		if ( '' === $name || ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$term_id = '' === $id ? 0 : self::find_by_id( $id );

		if ( $term_id ) {
			self::rename( $term_id, $name );

			return $term_id;
		}

		$existing = get_term_by( 'name', $name, self::TAXONOMY );
		$term_id  = $existing instanceof WP_Term ? (int) $existing->term_id : self::create( $name );

		/*
		 * Stamp the ID onto a term found or created by name, so the next run matches on
		 * the ID and a later rename is followed rather than duplicated. This also adopts
		 * brand terms created before this plugin recorded IDs at all.
		 */
		if ( $term_id && '' !== $id ) {
			update_term_meta( $term_id, self::TERM_META_ID, $id );
		}

		return $term_id;
	}

	/**
	 * Find the brand term carrying a Kontor manufacturer ID.
	 *
	 * @param string $id Value of Herstellerid.
	 * @return int Term ID, or 0 when no term claims that ID.
	 */
	protected static function find_by_id( $id ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- The ID is only in term meta; there are 28 brand terms and this runs in a background job.
				'meta_key'   => self::TERM_META_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'meta_value' => $id,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 0;
		}

		return (int) $terms[0];
	}

	/**
	 * Rename a brand term to follow a rename in the ERP.
	 *
	 * The slug is deliberately left alone. Changing it would break any URL already
	 * pointing at the brand archive, and WordPress keeps a stale slug working
	 * perfectly well alongside a new name.
	 *
	 * @param int    $term_id Term to rename.
	 * @param string $name    Name Kontor now uses.
	 * @return void
	 */
	protected static function rename( $term_id, $name ) {
		$term = get_term( $term_id, self::TAXONOMY );

		if ( ! $term instanceof WP_Term || $term->name === $name ) {
			return;
		}

		$updated = wp_update_term( $term_id, self::TAXONOMY, array( 'name' => $name ) );

		if ( is_wp_error( $updated ) ) {
			self::log(
				'warning',
				sprintf( 'Could not rename brand "%s" to "%s": %s', $term->name, $name, $updated->get_error_message() )
			);

			return;
		}

		self::log( 'info', sprintf( 'Renamed brand "%s" to "%s" to follow Kontor.', $term->name, $name ) );
	}

	/**
	 * Create a brand term.
	 *
	 * @param string $name Brand name.
	 * @return int Term ID, or 0 on failure.
	 */
	protected static function create( $name ) {
		$created = wp_insert_term( $name, self::TAXONOMY );

		if ( is_wp_error( $created ) ) {
			// A term with this slug already exists; reuse it rather than failing.
			$data = $created->get_error_data();

			if ( is_array( $data ) && isset( $data['term_id'] ) ) {
				return (int) $data['term_id'];
			}

			if ( is_numeric( $data ) ) {
				return (int) $data;
			}

			self::log( 'warning', sprintf( 'Could not create brand "%s": %s', $name, $created->get_error_message() ) );

			return 0;
		}

		return (int) $created['term_id'];
	}

	/**
	 * Write a message to the WooCommerce log.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message to record.
	 * @return void
	 */
	protected static function log( $level, $message ) {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log( $level, $message, array( 'source' => Client::LOG_SOURCE ) );
	}
}
