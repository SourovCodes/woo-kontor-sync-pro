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

		$this->assertSame( '', $settings['api_base_url'] );
		$this->assertSame( 10, $settings['timeout'] );
		$this->assertFalse( $settings['sync_enabled'] );
	}

	/**
	 * A partially stored option is merged over the defaults, so callers never see
	 * a missing key.
	 *
	 * @return void
	 */
	public function test_stored_settings_are_merged_over_defaults() {
		update_option( Settings::OPTION_KEY, array( 'api_base_url' => 'https://erp.example.com/api' ) );

		$settings = Settings::get_settings();

		$this->assertSame( 'https://erp.example.com/api', $settings['api_base_url'] );
		$this->assertSame( 10, $settings['timeout'] );
	}

	/**
	 * The timeout is clamped into a sane range.
	 *
	 * @return void
	 */
	public function test_timeout_is_clamped() {
		$settings = new Settings();

		$this->assertSame( 60, $settings->sanitize( array( 'timeout' => 999 ) )['timeout'] );
		$this->assertSame( 1, $settings->sanitize( array( 'timeout' => 0 ) )['timeout'] );
		$this->assertSame( 30, $settings->sanitize( array( 'timeout' => 30 ) )['timeout'] );
	}

	/**
	 * Submitting a blank token keeps the stored one.
	 *
	 * The settings screen never renders the secret back into the page, so a blank
	 * submission has to mean "unchanged" rather than "clear it".
	 *
	 * @return void
	 */
	public function test_blank_token_submission_keeps_the_stored_token() {
		update_option( Settings::OPTION_KEY, array( 'api_token' => 'stored-secret' ) );

		$settings  = new Settings();
		$sanitised = $settings->sanitize( array( 'api_token' => '' ) );

		$this->assertSame( 'stored-secret', $sanitised['api_token'] );
	}

	/**
	 * A submitted token replaces the stored one.
	 *
	 * @return void
	 */
	public function test_submitted_token_replaces_the_stored_token() {
		update_option( Settings::OPTION_KEY, array( 'api_token' => 'stored-secret' ) );

		$settings  = new Settings();
		$sanitised = $settings->sanitize( array( 'api_token' => 'new-secret' ) );

		$this->assertSame( 'new-secret', $sanitised['api_token'] );
	}

	/**
	 * The base URL is passed through URL sanitisation.
	 *
	 * @return void
	 */
	public function test_base_url_is_sanitised() {
		$settings  = new Settings();
		$sanitised = $settings->sanitize( array( 'api_base_url' => ' javascript:alert(1) ' ) );

		$this->assertStringNotContainsString( 'javascript:', $sanitised['api_base_url'] );
	}

	/**
	 * The sync toggle is stored as a real boolean.
	 *
	 * @return void
	 */
	public function test_sync_enabled_is_boolean() {
		$settings = new Settings();

		$this->assertTrue( $settings->sanitize( array( 'sync_enabled' => '1' ) )['sync_enabled'] );
		$this->assertFalse( $settings->sanitize( array() )['sync_enabled'] );
	}
}
