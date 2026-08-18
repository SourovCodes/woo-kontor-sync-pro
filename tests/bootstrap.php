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

	/*
	 * Load the plugin the way WordPress loads it: through wp-content/plugins, having
	 * registered the real path behind the symlink first. Requiring the checkout
	 * directly instead leaves plugin_basename() unable to shorten the path to the
	 * plugin slug, and everything keyed on that slug then behaves differently under
	 * test than in production — the HPOS compatibility declaration is recorded under
	 * an absolute path, and load_plugin_textdomain() registers a languages directory
	 * that does not exist, so no translation ever loads.
	 */
	$plugin = WP_PLUGIN_DIR . '/woo-kontor-sync-pro/woo-kontor-sync-pro.php';

	if ( ! file_exists( $plugin ) ) {
		echo 'This checkout is not linked into ' . WP_PLUGIN_DIR . '/woo-kontor-sync-pro.' . PHP_EOL;
		echo 'Link it there and try again.' . PHP_EOL;
		exit( 1 );
	}

	wp_register_plugin_realpath( $plugin );

	require_once $plugin;
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

	wksync_refuse_leftover_orders();
}
tests_add_filter( 'setup_theme', 'wksync_install_woocommerce' );

/**
 * Refuse to run against a database that still holds orders.
 *
 * Every test builds its own orders inside the transaction WP_UnitTestCase rolls back,
 * so the orders table is empty between runs — unless a run died before its rollback,
 * which commits whatever it had reached. Those rows then survive for ever, and the
 * damage is done somewhere else entirely: anything asking wc_get_orders() a question
 * about the whole shop counts them too, so a sweep that queued one order reports
 * seven. Three tests in JobProgressTest failed exactly that way, in a file with
 * nothing wrong with it, for as long as the rows sat there.
 *
 * So this is a hard stop rather than a cleanup. A crashed run is worth knowing about,
 * and a bootstrap that silently deleted rows would hide both the crash and the fact
 * that the previous run's results were never trustworthy.
 *
 * @return void
 */
function wksync_refuse_leftover_orders() {
	global $wpdb;

	$table = $wpdb->prefix . 'wc_orders';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from $wpdb->prefix; there is no CRUD API for this question at bootstrap.
	$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

	if ( 0 === $count ) {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Written to the terminal before WordPress can escape anything.
	printf(
		"\nThe test database still holds %d order(s) in %s.\n\n"
			. "Every test creates its orders inside a transaction that is rolled back, so this\n"
			. "means an earlier run died before its rollback. Left in place the rows are counted\n"
			. "by anything that asks wc_get_orders() about the whole shop, which fails tests in\n"
			. "files that have nothing to do with them.\n\n"
			. "Drop the test database and provision it again — the installer creates the\n"
			. "database only when it is absent, so it will not clear these on its own:\n\n"
			. "  DROP DATABASE %s; then bin/install-wp-tests.sh\n\n",
		$count,
		$table,
		DB_NAME
	);

	exit( 1 );
}

require $wksync_tests_dir . '/includes/bootstrap.php';
