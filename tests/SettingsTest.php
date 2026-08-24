<?php
/**
 * Tests for the settings screen's data handling.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\HeldProducts;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Sync\ProductSync;
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

		// No shop until someone fetches the list and chooses one.
		$this->assertSame( '', $settings['shop_id'] );
		$this->assertSame( '', $settings['shop_name'] );

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

	/**
	 * A synthetic shop ID with the shape Kontor returns.
	 *
	 * @return string A canonical GUID.
	 */
	private function shop_id() {
		return '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d';
	}

	/**
	 * A chosen set of manufacturers is stored with the labels that go with them.
	 *
	 * @return void
	 */
	public function test_manufacturer_selection_is_stored() {
		$sanitised = ( new Settings() )->sanitize(
			array(
				'manufacturer_choice' => '1',
				'manufacturer_ids'    => array( '084', '104' ),
				'manufacturer_names'  => wp_json_encode(
					array(
						'084' => 'Abel Woodentoys',
						'104' => 'Grimm’s',
					)
				),
			)
		);

		$this->assertSame( array( '084', '104' ), $sanitised['manufacturer_ids'] );
		$this->assertSame( 'Abel Woodentoys', $sanitised['manufacturer_names']['084'] );
	}

	/**
	 * Manufacturer IDs keep their leading zeros.
	 *
	 * Casting to an integer would collide "084" with "84" and silently import a
	 * different manufacturer's catalogue.
	 *
	 * @return void
	 */
	public function test_manufacturer_ids_are_not_cast_to_integers() {
		$sanitised = ( new Settings() )->sanitize(
			array(
				'manufacturer_choice' => '1',
				'manufacturer_ids'    => array( '084' ),
			)
		);

		$this->assertSame( array( '084' ), $sanitised['manufacturer_ids'] );
	}

	/**
	 * Malformed manufacturer IDs are dropped rather than stored.
	 *
	 * A comma would read as two manufacturers once the IDs are joined into the API
	 * filter.
	 *
	 * @return void
	 */
	public function test_malformed_manufacturer_ids_are_dropped() {
		$this->assertTrue( Settings::is_manufacturer_id( '084' ) );
		$this->assertFalse( Settings::is_manufacturer_id( '' ) );
		$this->assertFalse( Settings::is_manufacturer_id( '84,104' ) );
		$this->assertFalse( Settings::is_manufacturer_id( 'a b' ) );

		$sanitised = ( new Settings() )->sanitize(
			array(
				'manufacturer_choice' => '1',
				'manufacturer_ids'    => array( '084', '84,104', '' ),
			)
		);

		$this->assertSame( array( '084' ), $sanitised['manufacturer_ids'] );
	}

	/**
	 * A submission without the marker field keeps the stored manufacturers.
	 *
	 * A multi-select with nothing chosen submits no field at all, so absent cannot
	 * mean "clear" — that would let any partial save silently widen the import to the
	 * whole catalogue.
	 *
	 * @return void
	 */
	public function test_absent_manufacturer_field_keeps_the_stored_selection() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'manufacturer_ids'   => array( '104' ),
				'manufacturer_names' => array( '104' => 'Grimm’s' ),
			)
		);

		$sanitised = ( new Settings() )->sanitize( array( 'shoptype' => 'B2C' ) );

		$this->assertSame( array( '104' ), $sanitised['manufacturer_ids'] );
	}

	/**
	 * The marker field with no selection clears the filter.
	 *
	 * @return void
	 */
	public function test_marker_without_a_selection_clears_the_filter() {
		update_option( Settings::OPTION_KEY, array( 'manufacturer_ids' => array( '104' ) ) );

		$sanitised = ( new Settings() )->sanitize( array( 'manufacturer_choice' => '1' ) );

		$this->assertSame( array(), $sanitised['manufacturer_ids'] );
	}

	/**
	 * A label for a manufacturer that was not selected is discarded.
	 *
	 * @return void
	 */
	public function test_unselected_manufacturer_labels_are_discarded() {
		$sanitised = ( new Settings() )->sanitize(
			array(
				'manufacturer_choice' => '1',
				'manufacturer_ids'    => array( '084' ),
				'manufacturer_names'  => wp_json_encode(
					array(
						'084' => 'Abel Woodentoys',
						'999' => 'Never chosen',
					)
				),
			)
		);

		$this->assertSame( array( '084' => 'Abel Woodentoys' ), $sanitised['manufacturer_names'] );
	}

	/**
	 * Manufacturer rows are reduced to id and name pairs, deduplicated by ID.
	 *
	 * @return void
	 */
	public function test_manufacturers_are_read_from_the_response() {
		$manufacturers = Settings::manufacturers_from_response(
			array(
				'data' => array(
					array(
						'Herstellerid' => '104',
						'Hersteller'   => 'Grimm’s',
					),
					array(
						'Herstellerid' => '084',
						'Hersteller'   => 'Abel Woodentoys',
					),
					// The same manufacturer arriving twice is one choice, not two.
					array(
						'Herstellerid' => '104',
						'Hersteller'   => 'Grimm’s',
					),
					// No usable ID, so it could never be filtered on.
					array( 'Hersteller' => 'Nameless' ),
				),
			)
		);

		$this->assertCount( 2, $manufacturers );

		// Sorted by name, so the list reads the way the admin expects.
		$this->assertSame( '084', $manufacturers[0]['id'] );
		$this->assertSame( 'Abel Woodentoys', $manufacturers[0]['name'] );
		$this->assertSame( '104', $manufacturers[1]['id'] );
	}

	/**
	 * A chosen shop is stored with the label that goes with it.
	 *
	 * @return void
	 */
	public function test_shop_selection_is_stored() {
		$settings  = new Settings();
		$sanitised = $settings->sanitize(
			array(
				'shop_id'   => $this->shop_id(),
				'shop_name' => 'Edu-Shop',
			)
		);

		$this->assertSame( $this->shop_id(), $sanitised['shop_id'] );
		$this->assertSame( 'Edu-Shop', $sanitised['shop_name'] );
	}

	/**
	 * Only well-formed GUIDs are accepted as a shop ID.
	 *
	 * @return void
	 */
	public function test_shop_id_must_be_a_guid() {
		$this->assertTrue( Settings::is_shop_id( $this->shop_id() ) );
		$this->assertTrue( Settings::is_shop_id( strtoupper( $this->shop_id() ) ) );
		$this->assertFalse( Settings::is_shop_id( '' ) );
		$this->assertFalse( Settings::is_shop_id( 'Edu-Shop' ) );
		$this->assertFalse( Settings::is_shop_id( '1a2b3c4d5e6f4a7b8c9d0e1f2a3b4c5d' ) );
		$this->assertFalse( Settings::is_shop_id( $this->shop_id() . '-extra' ) );
	}

	/**
	 * A malformed shop ID keeps whatever was already stored.
	 *
	 * @return void
	 */
	public function test_malformed_shop_id_keeps_the_stored_one() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'shop_id'   => $this->shop_id(),
				'shop_name' => 'Edu-Shop',
			)
		);

		$sanitised = ( new Settings() )->sanitize( array( 'shop_id' => 'not-a-guid' ) );

		$this->assertSame( $this->shop_id(), $sanitised['shop_id'] );
		$this->assertSame( 'Edu-Shop', $sanitised['shop_name'] );
	}

	/**
	 * A submission that omits the shop keeps the stored one.
	 *
	 * The intervals behave the same way: a partial save must never silently unset a
	 * configured value, and order push depends on this one.
	 *
	 * @return void
	 */
	public function test_absent_shop_id_keeps_the_stored_one() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'shop_id'   => $this->shop_id(),
				'shop_name' => 'Edu-Shop',
			)
		);

		$sanitised = ( new Settings() )->sanitize( array( 'shoptype' => 'B2C' ) );

		$this->assertSame( $this->shop_id(), $sanitised['shop_id'] );
		$this->assertSame( 'Edu-Shop', $sanitised['shop_name'] );
	}

	/**
	 * Explicitly choosing no shop clears the selection.
	 *
	 * @return void
	 */
	public function test_empty_shop_id_clears_the_selection() {
		update_option(
			Settings::OPTION_KEY,
			array(
				'shop_id'   => $this->shop_id(),
				'shop_name' => 'Edu-Shop',
			)
		);

		$sanitised = ( new Settings() )->sanitize( array( 'shop_id' => '' ) );

		$this->assertSame( '', $sanitised['shop_id'] );
		$this->assertSame( '', $sanitised['shop_name'] );
	}

	/**
	 * The shop name is a label only and cannot carry markup.
	 *
	 * @return void
	 */
	public function test_shop_name_is_stripped() {
		$sanitised = ( new Settings() )->sanitize(
			array(
				'shop_id'   => $this->shop_id(),
				'shop_name' => '<script>alert(1)</script>Edu-Shop',
			)
		);

		// wp_strip_all_tags() drops a script block whole, contents included.
		$this->assertSame( 'Edu-Shop', $sanitised['shop_name'] );
		$this->assertStringNotContainsString( '<', $sanitised['shop_name'] );
	}

	/**
	 * A shops response is reduced to id and name pairs.
	 *
	 * @return void
	 */
	public function test_shops_are_read_from_the_response() {
		$shops = Settings::shops_from_response(
			array(
				'data' => array(
					array(
						'Shopid' => $this->shop_id(),
						'Name'   => 'Edu-Shop',
					),
					array(
						'Shopid' => '9f8e7d6c-5b4a-4392-8171-6a5b4c3d2e1f',
						'Name'   => 'Retailer',
					),
				),
			)
		);

		$this->assertCount( 2, $shops );
		$this->assertSame( $this->shop_id(), $shops[0]['id'] );
		$this->assertSame( 'Edu-Shop', $shops[0]['name'] );
		$this->assertSame( 'Retailer', $shops[1]['name'] );
	}

	/**
	 * Unusable rows are dropped, and a nameless shop still gets an entry.
	 *
	 * A choice that could never work should not be offered, but a shop with a
	 * missing label is still a shop and must stay selectable.
	 *
	 * @return void
	 */
	public function test_unusable_shop_rows_are_dropped() {
		$shops = Settings::shops_from_response(
			array(
				'data' => array(
					array( 'Name' => 'No ID at all' ),
					array(
						'Shopid' => 'not-a-guid',
						'Name'   => 'Malformed',
					),
					array( 'Shopid' => $this->shop_id() ),
					'not even a row',
				),
			)
		);

		$this->assertCount( 1, $shops );
		$this->assertSame( $this->shop_id(), $shops[0]['id'] );

		// With no Name, the ID stands in as the label.
		$this->assertSame( $this->shop_id(), $shops[0]['name'] );
	}

	/**
	 * A response with no data at all yields no shops rather than an error.
	 *
	 * @return void
	 */
	public function test_empty_shops_response_is_handled() {
		$this->assertSame( array(), Settings::shops_from_response( array() ) );
		$this->assertSame( array(), Settings::shops_from_response( array( 'data' => array() ) ) );
		$this->assertSame( array(), Settings::shops_from_response( array( 'data' => 'nonsense' ) ) );
	}

	/**
	 * The screen says where the two customer emails are switched on.
	 *
	 * They are WooCommerce email types rather than settings of this plugin's, which is
	 * deliberate — but it means somebody looking here for them would otherwise find
	 * nothing at all.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_points_at_the_woocommerce_email_settings() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new Settings() )->render_page();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( 'page=wc-settings&#038;tab=email', $markup );
		$this->assertStringContainsString( 'Both are switched off until you turn them on', $markup );
	}

	/**
	 * The screen counts the products the syncs are holding back and links to them.
	 *
	 * The run summary above it already says how many were held back and why; this is
	 * the only thing on the screen that says which.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_points_at_the_products_being_held_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$product = new WC_Product_Simple();
		$product->set_name( 'Held back' );
		$product->set_status( 'draft' );
		$product->update_meta_data( ProductSync::META_INACTIVE_DRAFTED, 1 );
		$product->save();

		ob_start();
		( new Settings() )->render_page();
		$markup = (string) ob_get_clean();

		$this->assertStringContainsString( '1 product is currently held back as a draft.', $markup );
		$this->assertStringContainsString( 'wksync_held=' . HeldProducts::ANY, $markup );
	}

	/**
	 * A shop holding nothing back is told nothing.
	 *
	 * @return void
	 */
	public function test_the_settings_screen_says_nothing_when_nothing_is_held_back() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new Settings() )->render_page();
		$markup = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'wksync_held=', $markup );
	}
}
