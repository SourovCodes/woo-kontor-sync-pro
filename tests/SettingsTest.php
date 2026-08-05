<?php
/**
 * Tests for the settings screen's data handling.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Admin\Settings;
use WP_UnitTestCase;

/**
 * Covers defaults, merging and sanitisation.
 */
class SettingsTest extends WP_UnitTestCase {

	/**
	 * Remove the option between tests so each one starts from the defaults.
	 *
	 * @return void
	 */
	public function tear_down() {
		delete_option( Settings::OPTION_KEY );
		parent::tear_down();
	}

	/**
	 * Reading settings with nothing stored returns the full default set.
	 *
	 * @return void
	 */
	public function test_defaults_are_returned_when_unset() {
		$settings = Settings::get_settings();

		$this->assertSame( 'https://sp3api.kontor-crm.de/api/v1/kontor', $settings['api_base_url'] );
		$this->assertSame( '', $settings['api_key'] );
		$this->assertSame( 'B2B', $settings['shoptype'] );
		$this->assertSame( '', $settings['image_base_url'] );

		// Both jobs default to Never: a fresh install has no API key, so nothing
		// should contact Kontor or rewrite the catalogue until it is configured.
		$this->assertSame( Settings::INTERVAL_NEVER, $settings['product_sync_interval'] );
		$this->assertSame( Settings::INTERVAL_NEVER, $settings['stock_sync_interval'] );
	}

	/**
	 * Never is offered for both jobs.
	 *
	 * @return void
	 */
	public function test_never_is_an_offered_interval() {
		$this->assertArrayHasKey( Settings::INTERVAL_NEVER, Settings::product_sync_intervals() );
		$this->assertArrayHasKey( Settings::INTERVAL_NEVER, Settings::stock_sync_intervals() );

		$settings = new Settings();

		$this->assertSame( Settings::INTERVAL_NEVER, $settings->sanitize( array( 'product_sync_interval' => 0 ) )['product_sync_interval'] );
		$this->assertSame( Settings::INTERVAL_NEVER, $settings->sanitize( array( 'stock_sync_interval' => 0 ) )['stock_sync_interval'] );
	}

	/**
	 * A submission that omits an interval keeps the stored one.
	 *
	 * Now that 0 is a legitimate choice, defaulting a missing field to 0 would let
	 * any partial submission silently switch a configured schedule to Never.
	 *
	 * @return void
	 */
	public function test_missing_interval_keeps_the_stored_value() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'product_sync_interval' => 14 * DAY_IN_SECONDS,
				'stock_sync_interval'   => HOUR_IN_SECONDS,
			)
		);

		$sanitised = ( new Settings() )->sanitize( array( 'shoptype' => 'B2B' ) );

		$this->assertSame( 14 * DAY_IN_SECONDS, $sanitised['product_sync_interval'] );
		$this->assertSame( HOUR_IN_SECONDS, $sanitised['stock_sync_interval'] );
	}

	/**
	 * The removed fields are gone from the settings entirely.
	 *
	 * @return void
	 */
	public function test_removed_fields_are_absent() {
		$settings = Settings::get_settings();

		$this->assertArrayNotHasKey( 'timeout', $settings );
		$this->assertArrayNotHasKey( 'sync_enabled', $settings );
	}

	/**
	 * A partially stored option is merged over the defaults, so callers never see
	 * a missing key.
	 *
	 * @return void
	 */
	public function test_stored_settings_are_merged_over_defaults() {
		update_option( Settings::OPTION_KEY, array( 'shoptype' => 'EDU' ) );

		$settings = Settings::get_settings();

		$this->assertSame( 'EDU', $settings['shoptype'] );
		$this->assertSame( Settings::INTERVAL_NEVER, $settings['stock_sync_interval'] );
	}

	/**
	 * Submitting a blank key keeps the stored one.
	 *
	 * The settings screen never renders the secret back into the page, so a blank
	 * submission has to mean "unchanged" rather than "clear it".
	 *
	 * @return void
	 */
	public function test_blank_key_submission_keeps_the_stored_key() {
		update_option( Settings::OPTION_KEY, array( 'api_key' => 'stored-secret' ) );

		$settings  = new Settings();
		$sanitised = $settings->sanitize( array( 'api_key' => '' ) );

		$this->assertSame( 'stored-secret', $sanitised['api_key'] );
		$this->assertSame( 'new-secret', $settings->sanitize( array( 'api_key' => 'new-secret' ) )['api_key'] );
	}

	/**
	 * An API key survives sanitisation byte for byte.
	 *
	 * Real Kontor keys contain both non-ASCII characters and percent-encoded
	 * octets. sanitize_text_field() strips "%5a" and similar, which silently
	 * shortens the key and turns every request into a confusing 401.
	 *
	 * The fixture below is synthetic. It only has to reproduce the shape of a real
	 * key — mixed case, punctuation, "ß", and a percent octet — never a real
	 * credential, which must not live in the repository.
	 *
	 * @return void
	 */
	public function test_api_key_survives_sanitisation() {
		$key = '00000000000aa!0000000000AAAAA=?00ß0000000000000a0a0aßa0ßaaA%5a5a';

		$this->assertSame( $key, Settings::sanitize_api_key( $key ) );

		// Guard the specific failure: the generic sanitiser would damage this key.
		$this->assertNotSame( $key, sanitize_text_field( $key ) );

		$settings = new Settings();
		$this->assertSame( $key, $settings->sanitize( array( 'api_key' => $key ) )['api_key'] );
	}

	/**
	 * Control characters are removed from the key, since it becomes a request header.
	 *
	 * @return void
	 */
	public function test_api_key_strips_control_characters() {
		$this->assertSame( 'abc123', Settings::sanitize_api_key( "abc\r\n123" ) );
		$this->assertSame( 'abc123', Settings::sanitize_api_key( "  abc\t123\x00  " ) );

		// A newline would otherwise allow a second header to be injected.
		$this->assertStringNotContainsString( "\n", Settings::sanitize_api_key( "key\nX-Evil: 1" ) );
	}

	/**
	 * Only the three shop types Kontor exposes are accepted.
	 *
	 * @return void
	 */
	public function test_shoptype_is_restricted_to_known_values() {
		$settings = new Settings();

		$this->assertSame( array( 'B2B', 'B2C', 'EDU' ), array_keys( Settings::shoptypes() ) );
		$this->assertSame( 'B2C', $settings->sanitize( array( 'shoptype' => 'B2C' ) )['shoptype'] );

		// An unknown value falls back to what is already stored rather than being accepted.
		$this->assertSame( 'B2B', $settings->sanitize( array( 'shoptype' => 'HACK' ) )['shoptype'] );
	}

	/**
	 * Intervals outside the offered choices are rejected.
	 *
	 * @return void
	 */
	public function test_intervals_are_restricted_to_offered_choices() {
		$settings = new Settings();

		$this->assertSame( 30 * DAY_IN_SECONDS, $settings->sanitize( array( 'product_sync_interval' => 30 * DAY_IN_SECONDS ) )['product_sync_interval'] );
		$this->assertSame( HOUR_IN_SECONDS, $settings->sanitize( array( 'stock_sync_interval' => HOUR_IN_SECONDS ) )['stock_sync_interval'] );

		// One second, or anything else not on the menu, falls back to the stored value.
		update_option(
			Settings::OPTION_KEY,
			array(
				'product_sync_interval' => 21 * DAY_IN_SECONDS,
				'stock_sync_interval'   => 6 * HOUR_IN_SECONDS,
			)
		);

		$this->assertSame( 21 * DAY_IN_SECONDS, $settings->sanitize( array( 'product_sync_interval' => 1 ) )['product_sync_interval'] );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $settings->sanitize( array( 'stock_sync_interval' => 1 ) )['stock_sync_interval'] );
	}

	/**
	 * The product sync interval choices stay inside the 7 to 30 day range, and the
	 * stock ones inside 15 minutes to a day.
	 *
	 * @return void
	 */
	public function test_interval_choices_stay_within_range() {
		$scheduled = static function ( array $intervals ) {
			return array_filter( array_keys( $intervals ) );
		};

		$product = $scheduled( Settings::product_sync_intervals() );
		$stock   = $scheduled( Settings::stock_sync_intervals() );

		$this->assertSame( 7 * DAY_IN_SECONDS, min( $product ) );
		$this->assertSame( 30 * DAY_IN_SECONDS, max( $product ) );
		$this->assertSame( 15 * MINUTE_IN_SECONDS, min( $stock ) );
		$this->assertSame( DAY_IN_SECONDS, max( $stock ) );
	}

	/**
	 * Base URLs are passed through URL sanitisation.
	 *
	 * @return void
	 */
	public function test_base_urls_are_sanitised() {
		$settings  = new Settings();
		$sanitised = $settings->sanitize(
			array(
				'api_base_url'   => ' javascript:alert(1) ',
				'image_base_url' => ' javascript:alert(2) ',
			)
		);

		$this->assertStringNotContainsString( 'javascript:', $sanitised['api_base_url'] );
		$this->assertStringNotContainsString( 'javascript:', $sanitised['image_base_url'] );
	}
}
