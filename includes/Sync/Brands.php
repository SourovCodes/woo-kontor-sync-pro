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
 * Terms are matched on the manufacturer name. Herstellerid is deliberately not
 * consulted: a manufacturer renamed in the ERP therefore lands on a new brand and
 * leaves the old one behind, rather than renaming it.
 */
class Brands {

	/**
	 * WooCommerce's brand taxonomy, part of core since 9.6.
	 */
	const TAXONOMY = 'product_brand';

	/**
	 * Assign a product to the brand named by an article row.
	 *
	 * A row with no manufacturer leaves the product's existing brand alone rather
	 * than clearing it, so a brand set by hand is not wiped on every run.
	 *
	 * @param int    $product_id Product to assign.
	 * @param string $name       Value of Hersteller.
	 * @return bool True when a brand was assigned.
	 */
	public static function assign( $product_id, $name ) {
		$term_id = self::resolve( $name );

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
	 * @return int Term ID, or 0 when there is nothing to map.
	 */
	public static function resolve( $name ) {
		/*
		 * Not sanitize_text_field(): it strips percent-encoded octets, so a name
		 * carrying one would silently lose characters. Stripping tags is the actual
		 * requirement; WooCommerce escapes on output.
		 */
		$name = trim( wp_strip_all_tags( (string) $name ) );

		if ( '' === $name || ! taxonomy_exists( self::TAXONOMY ) ) {
			return 0;
		}

		$existing = get_term_by( 'name', $name, self::TAXONOMY );

		if ( $existing instanceof WP_Term ) {
			return (int) $existing->term_id;
		}

		return self::create( $name );
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
