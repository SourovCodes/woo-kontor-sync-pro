<?php
/**
 * Tests for the plugin bootstrap.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WooKontorSync\Api\Client;
use WooKontorSync\Plugin;
use WooKontorSync\Sync\Scheduler;
use WP_UnitTestCase;

/**
 * Covers loading, HPOS declaration and the client's unconfigured path.
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * The plugin defines its version and path constants on load.
	 *
	 * @return void
	 */
	public function test_plugin_constants_are_defined() {
		$this->assertTrue( defined( 'WKSYNC_VERSION' ) );
		$this->assertTrue( defined( 'WKSYNC_PLUGIN_FILE' ) );
		$this->assertSame( 'woo-kontor-sync-pro.php', basename( WKSYNC_PLUGIN_FILE ) );
	}

	/**
	 * WooCommerce is loaded and new enough for the plugin to boot.
	 *
	 * @return void
	 */
	public function test_woocommerce_is_supported() {
		$this->assertTrue( class_exists( 'WooCommerce' ) );
		$this->assertTrue( \WooKontorSync\is_woocommerce_supported() );
	}

	/**
	 * Plugin::instance() returns a single shared instance.
	 *
	 * @return void
	 */
	public function test_instance_is_shared() {
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	/**
	 * The plugin declares compatibility with High-Performance Order Storage.
	 *
	 * Without this declaration WooCommerce lists the plugin as incompatible and
	 * refuses to enable HPOS, which is the single most common packaging mistake in
	 * a WooCommerce extension.
	 *
	 * @return void
	 */
	public function test_hpos_compatibility_is_declared() {
		$compatible = \Automattic\WooCommerce\Utilities\FeaturesUtil::get_compatible_plugins_for_feature( 'custom_order_tables' );

		/*
		 * WooCommerce records the declaration under the plugin's slug. Do not use
		 * plugin_basename() here: the suite loads the plugin from its checkout
		 * rather than from WP_PLUGIN_DIR, so it has no prefix to strip.
		 */
		$slug = 'woo-kontor-sync-pro/woo-kontor-sync-pro.php';

		$this->assertContains( $slug, $compatible['compatible'] );
		$this->assertNotContains( $slug, $compatible['incompatible'] );
		$this->assertNotContains( $slug, $compatible['uncertain'] );
	}

	/**
	 * High-Performance Order Storage is a hard requirement, and the suite runs with
	 * it enabled.
	 *
	 * @return void
	 */
	public function test_hpos_is_enabled() {
		$this->assertTrue( \WooKontorSync\is_hpos_enabled() );
		$this->assertTrue( \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() );
	}

	/**
	 * The plugin booted, which is only possible once both the WooCommerce version
	 * check and the HPOS gate have passed.
	 *
	 * @return void
	 */
	public function test_plugin_booted_past_its_requirement_gates() {
		$this->assertGreaterThan( 0, did_action( 'woo_kontor_sync_loaded' ) );
	}

	/**
	 * The declared requirements are the current releases, and the runtime constant
	 * agrees with the plugin header.
	 *
	 * The header and WKSYNC_MIN_WC_VERSION are two copies of the same fact; this
	 * test is what stops them drifting apart.
	 *
	 * @return void
	 */
	public function test_declared_requirements_are_current() {
		$headers = get_file_data(
			WKSYNC_PLUGIN_FILE,
			array(
				'requires_wp'  => 'Requires at least',
				'requires_php' => 'Requires PHP',
				'requires_wc'  => 'WC requires at least',
			)
		);

		$this->assertSame( '7.0', $headers['requires_wp'] );
		$this->assertSame( '8.2', $headers['requires_php'] );
		$this->assertSame( '11.0', $headers['requires_wc'] );
		$this->assertSame( $headers['requires_wc'], WKSYNC_MIN_WC_VERSION );

		// The site under test must itself satisfy the floors the plugin declares.
		$this->assertTrue( version_compare( WC_VERSION, WKSYNC_MIN_WC_VERSION, '>=' ) );
		$this->assertTrue( version_compare( PHP_VERSION, $headers['requires_php'], '>=' ) );
		$this->assertTrue( version_compare( get_bloginfo( 'version' ), $headers['requires_wp'], '>=' ) );
	}

	/**
	 * The scheduler queues work into its own Action Scheduler group.
	 *
	 * @return void
	 */
	public function test_scheduler_uses_a_dedicated_group() {
		$this->assertSame( 'woo-kontor-sync', Scheduler::GROUP );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}

	/**
	 * Every job is registered and maps to an interval setting.
	 *
	 * @return void
	 */
	public function test_registered_jobs() {
		$jobs = Scheduler::get_jobs();

		$this->assertSame( array( 'products', 'stock', 'orders', 'delivery' ), array_keys( $jobs ) );
		$this->assertSame( 'product_sync_interval', $jobs['products']['setting'] );
		$this->assertSame( 'stock_sync_interval', $jobs['stock']['setting'] );
		$this->assertSame( 'order_sync_interval', $jobs['orders']['setting'] );
		$this->assertSame( 'delivery_sync_interval', $jobs['delivery']['setting'] );

		// Only the two order jobs depend on a shop being chosen.
		$this->assertArrayNotHasKey( 'needs_shop', $jobs['products'] );
		$this->assertArrayNotHasKey( 'needs_shop', $jobs['stock'] );
		$this->assertTrue( $jobs['orders']['needs_shop'] );
		$this->assertTrue( $jobs['delivery']['needs_shop'] );

		foreach ( $jobs as $job ) {
			$this->assertTrue( has_action( $job['action'] ) > 0, $job['action'] . ' has no handler' );
		}
	}

	/**
	 * Triggering an unknown job is refused rather than queueing something odd.
	 *
	 * @return void
	 */
	public function test_unknown_job_cannot_be_triggered() {
		$this->assertSame( 'wksync_unavailable', Scheduler::trigger( 'customers' )->get_error_code() );
		$this->assertSame( 'wksync_unavailable', Scheduler::trigger( '' )->get_error_code() );
	}

	/**
	 * The API client refuses to make a request before it has been configured.
	 *
	 * @return void
	 */
	public function test_client_errors_when_unconfigured() {
		$client = new Client(
			array(
				'api_base_url' => '',
				'api_key'      => '',
			)
		);

		$result = $client->fetch_stock();

		$this->assertWPError( $result );
		$this->assertSame( 'woo_kontor_sync_not_configured', $result->get_error_code() );
	}
}
