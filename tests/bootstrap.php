<?php
/**
 * PHPUnit bootstrap.
 *
 * Uses the wp-phpunit package for the WordPress test library, so no SVN checkout
 * of core is required. Run bin/install-wp-tests.sh once first: it creates the test
 * database and generates tests/wp-tests-config.php.
 *
 * @package WooKontorSync
 */

$wksync_plugin_dir = dirname( __DIR__ );
$wksync_tests_dir  = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $wksync_tests_dir ) {
	$wksync_tests_dir = $wksync_plugin_dir . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $wksync_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library. Run 'composer install' first." . PHP_EOL;
	exit( 1 );
}

if ( ! file_exists( __DIR__ . '/wp-tests-config.php' ) ) {
	echo "Missing tests/wp-tests-config.php. Run './bin/install-wp-tests.sh' first." . PHP_EOL;
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $wksync_plugin_dir . '/vendor/autoload.php';
require_once $wksync_tests_dir . '/includes/functions.php';

/**
 * Load WooCommerce and this plugin into the test site.
 *
 * WooCommerce comes from the WordPress install the tests run against, so the suite
 * exercises the same version the development site runs.
 *
 * @return void
 */
function wksync_manually_load_plugins() {
	$woocommerce = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';

	if ( ! file_exists( $woocommerce ) ) {
		echo 'WooCommerce was not found at ' . $woocommerce . PHP_EOL;
		exit( 1 );
	}

	/*
	 * The plugin requires High-Performance Order Storage, so the suite has to run
	 * with it enabled. Set this before WooCommerce loads: its data stores read the
	 * option while initialising, and the plugin's own HPOS gate runs on
	 * plugins_loaded, which is later than this hook but earlier than setup_theme.
	 */
	update_option( 'woocommerce_feature_custom_order_tables_enabled', 'yes' );
	update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );

	require_once $woocommerce;
	require_once dirname( __DIR__ ) . '/woo-kontor-sync-pro.php';
}
tests_add_filter( 'muplugins_loaded', 'wksync_manually_load_plugins' );

/**
 * Install the WooCommerce database tables and roles before the tests run.
 *
 * @return void
 */
function wksync_install_woocommerce() {
	if ( ! class_exists( 'WC_Install' ) ) {
		return;
	}

	// Suppress the "installed" notices WC_Install emits while creating tables.
	$_SERVER['REQUEST_URI'] = '/';
	WC_Install::install();

	/*
	 * WC_Install() does not provision the orders tables here, because the features
	 * controller resolved before the suite enabled HPOS. Create them explicitly, or
	 * every order touched by a test raises "Table wptests_wc_orders doesn't exist".
	 */
	$synchronizer = \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class;

	if ( class_exists( $synchronizer ) && function_exists( 'wc_get_container' ) ) {
		wc_get_container()->get( $synchronizer )->create_database_tables();
	}

	// WC_Install adds roles, so the global has to be rebuilt for them to be visible.
	$GLOBALS['wp_roles'] = null;
	wp_roles();
}
tests_add_filter( 'setup_theme', 'wksync_install_woocommerce' );

require $wksync_tests_dir . '/includes/bootstrap.php';
