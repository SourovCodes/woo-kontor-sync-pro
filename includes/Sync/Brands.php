<?php
/**
 * Brand terms mapped from Kontor manufacturers.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Sync;

use WooKontorSync\Api\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Maps Kontor's Herstellerid and Hersteller onto WooCommerce brand terms.
 *
 * Terms are matched on Herstellerid rather than on the name, which is stored as
 * term meta. That way a manufacturer renamed in the ERP updates the existing brand
 * instead of leaving a duplicate behind.
 */
class Brands {

	/**
	 * WooCommerce's brand taxonomy, part of core since 9.6.
	 */
	const TAXONOMY = 'product_brand';

	/**
	 * Term meta holding Kontor's manufacturer ID.
	 *
	 * Stored as a string: the IDs carry leading zeros, such as "084".
	 */
	const TERM_META_ID = '_wksync_kontor_brand_id';

	/**
	 * Assign a product to the brand described by an article row.
	 *
	 * A row with no manufacturer leaves the product's existing brand alone rather
	 * than clearing it, so a brand set by hand is not wiped on every run.
	 *
	 * @param int    $product_id Product to assign.
	 * @param string $kontor_id  Value of Herstellerid.
	 * @param string $name       Value of Hersteller.
	 * @return bool True when a brand was assigned.
	 */
	public static function assign( $product_id, $kontor_id, $name ) {
		$term_id = self::resolve( $kontor_id, $name );

		if ( ! $term_id ) {
			return false;
		}

		$result = wp_set_object_terms( $product_id, array( $term_id ), self::TAXONOMY, false );

		return ! is_wp_error( $result );
	}

	/**
	 * Find, update or create the brand term for a Kontor manufacturer.
	 *
	 * @param string $kontor_id Value of Herstellerid.
	 * @param string $name      Value of Hersteller.
	 * @return int Term ID, or 0 when there is nothing to map.
	 */
	public static function resolve( $kontor_id, $name ) {
		$kontor_id = trim( (string) $kontor_id );
		$name      = trim( wp_strip_all_tags( (string) $name ) );

		if ( '' === $name || ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$existing = self::find_by_kontor_id( $kontor_id );

		if ( $existing ) {
			// The manufacturer was renamed in the ERP; move the existing brand with it.
			if ( $existing->name !== $name ) {
				wp_update_term( $existing->term_id, self::TAXONOMY, array( 'name' => $name ) );
			}

			return (int) $existing->term_id;
		}

		$by_name = get_term_by( 'name', $name, self::TAXONOMY );

		if ( $by_name instanceof \WP_Term ) {
			self::remember_kontor_id( (int) $by_name->term_id, $kontor_id );

			return (int) $by_name->term_id;
		}

		return self::create( $name, $kontor_id );
	}

	/**
	 * Look a brand up by the Kontor manufacturer ID stored against it.
	 *
	 * @param string $kontor_id Value of Herstellerid.
	 * @return \WP_Term|null The term, or null when there is no match.
	 */
	protected static function find_by_kontor_id( $kontor_id ) {
		if ( '' === $kontor_id ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Runs in a background job; the ID mapping only exists as term meta.
				'meta_key'   => self::TERM_META_ID,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- See above.
				'meta_value' => $kontor_id,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		return $terms[0];
	}

	/**
	 * Create a brand term.
	 *
	 * @param string $name      Brand name.
	 * @param string $kontor_id Value of Herstellerid.
	 * @return int Term ID, or 0 on failure.
	 */
	protected static function create( $name, $kontor_id ) {
		$created = wp_insert_term( $name, self::TAXONOMY );

		if ( is_wp_error( $created ) ) {
			// A term with this slug already exists; reuse it rather than failing.
			$data = $created->get_error_data();

			if ( is_array( $data ) && isset( $data['term_id'] ) ) {
				$term_id = (int) $data['term_id'];
			} elseif ( is_numeric( $data ) ) {
				$term_id = (int) $data;
			} else {
				self::log( 'warning', sprintf( 'Could not create brand "%s": %s', $name, $created->get_error_message() ) );

				return 0;
			}

			self::remember_kontor_id( $term_id, $kontor_id );

			return $term_id;
		}

		$term_id = (int) $created['term_id'];

		self::remember_kontor_id( $term_id, $kontor_id );

		return $term_id;
	}

	/**
	 * Record the Kontor manufacturer ID against a brand term.
	 *
	 * @param int    $term_id   Brand term.
	 * @param string $kontor_id Value of Herstellerid.
	 * @return void
	 */
	protected static function remember_kontor_id( $term_id, $kontor_id ) {
		if ( '' === $kontor_id ) {
			return;
		}

		update_term_meta( $term_id, self::TERM_META_ID, $kontor_id );
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
