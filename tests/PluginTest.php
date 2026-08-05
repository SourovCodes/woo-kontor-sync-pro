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
	 * The scheduler queues work into its own Action Scheduler group.
	 *
	 * @return void
	 */
	public function test_scheduler_uses_a_dedicated_group() {
		$this->assertSame( 'woo-kontor-sync', Scheduler::GROUP );
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ) );
	}

	/**
	 * Queueing an invalid order ID is a no-op rather than a fatal.
	 *
	 * @return void
	 */
	public function test_enqueue_order_ignores_invalid_ids() {
		$scheduler = new Scheduler();
		$scheduler->enqueue_order( 0 );

		$this->assertFalse( (bool) as_next_scheduled_action( Scheduler::ACTION_SYNC_ORDER, array( 'order_id' => 0 ), Scheduler::GROUP ) );
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
				'api_token'    => '',
				'timeout'      => 10,
			)
		);

		$result = $client->get( '/orders' );

		$this->assertWPError( $result );
		$this->assertSame( 'woo_kontor_sync_not_configured', $result->get_error_code() );
	}
}
