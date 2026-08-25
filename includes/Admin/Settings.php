<?php
/**
 * Admin settings screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Api\Client;
use WooKontorSync\Frontend\ProductMeta;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;
use WooKontorSync\Updates\Updater;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's settings, its screen under WooCommerce, the connection
 * test and the manual job triggers.
 */
class Settings {

	/**
	 * Option name holding every plugin setting.
	 */
	const OPTION_KEY = 'woo_kontor_sync_settings';

	/**
	 * Settings group used by the Settings API.
	 */
	const OPTION_GROUP = 'woo_kontor_sync_settings_group';

	/**
	 * Menu slug of the settings screen.
	 */
	const PAGE_SLUG = 'woo-kontor-sync';

	/**
	 * Capability required to view or change the settings.
	 */
	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Transient holding the result of a force push until the screen renders it.
	 *
	 * The handler redirects rather than printing, so a reload cannot re-send the
	 * batch. The result is too big for a query argument and must not travel in one
	 * anyway — it carries Kontor's own wording, which would then have to survive a
	 * round trip through a URL anybody could edit. Per user, so two administrators
	 * pressing the button do not read each other's answer.
	 */
	const FORCE_PUSH_RESULT = 'wksync_force_push_result_';

	/**
	 * Word an operator must type to force push every order that has been sent.
	 *
	 * Not translated, and deliberately so: a confirmation is only a confirmation if
	 * what has to be typed is exact, and a translated one would differ between the
	 * screen's language and whatever the operator was told to type. The single-order
	 * path asks for no such thing — one order is a repairable mistake.
	 */
	const FORCE_PUSH_CONFIRMATION = 'OVERWRITE';

	/**
	 * Interval value meaning "do not schedule this job at all".
	 *
	 * This is the default for both jobs: a fresh install has no API key, so nothing
	 * should start reaching out to Kontor, or rewriting the catalogue, until it has
	 * been configured deliberately. Manual "Run now" still works.
	 */
	const INTERVAL_NEVER = 0;

	/**
	 * Setting deciding whether Kontor's sales quantities bind the shop.
	 *
	 * Off by default, like every other setting that changes what the shop does. The
	 * figures are imported either way — this only decides whether a customer is held
	 * to them, so turning it on takes effect at once rather than after a sync.
	 */
	const ENFORCE_QUANTITIES = 'enforce_order_quantities';

	/**
	 * Setting deciding whether the stock sync drafts what its feed leaves out.
	 *
	 * Off by default, like every other setting here that changes what the shop does.
	 * The stock sync drafted these articles until 0.13.0, which stopped: Kontor's
	 * stock list is narrower than its catalogue, so absence from it is a routine gap
	 * rather than a verdict, and treating it as one took a fifth of the catalogue out
	 * of the shop on a single run. That is still the default, and this is for the shop
	 * whose ERP means something stricter by it.
	 *
	 * Turning it off again does not leave the products it drafted stranded. They are
	 * absent from the feed by definition, so nothing in apply() will ever see them —
	 * StockSync::finalise() releases them instead.
	 */
	const DRAFT_MISSING_STOCK = 'draft_missing_stock';

	/**
	 * Setting deciding whether the product page shows the recommended retail price.
	 *
	 * Off by default, like every other setting here that changes what the shop does.
	 * The figure is imported either way, on a wholesale shop; this decides whether a
	 * customer is shown it, which is a public statement about the shop's pricing and
	 * not something an update should start making on its own.
	 */
	const SHOW_MSRP = 'show_msrp';

	/**
	 * Label shown in front of the recommended retail price.
	 *
	 * Empty means "use the translated default", which is why the default is not stored
	 * as a string: storing one would freeze the wording into whichever language the
	 * settings happened to be saved in.
	 */
	const MSRP_LABEL = 'msrp_label';

	/**
	 * Setting deciding whether the product page shows the EAN.
	 *
	 * Off by default for the same reason as the retail price, though a good deal less
	 * consequential: the EAN is an identifier rather than a price.
	 */
	const SHOW_EAN = 'show_ean';

	/**
	 * Label shown in front of the EAN.
	 */
	const EAN_LABEL = 'ean_label';

	/**
	 * Setting deciding whether this shop exchanges orders with Kontor at all.
	 *
	 * On by default, which is the one place this plugin deliberately breaks its own
	 * "a setting that changes what the shop does starts off" rule — and it breaks it
	 * for the reason behind the rule rather than in spite of it. Off is the value that
	 * takes a capability away here, so on is what leaves an upgraded shop doing
	 * exactly what it did before.
	 *
	 * Off means the order push, the delivery import and the invoice import are all
	 * refused by Preflight, their recurring actions are cancelled, no order is queued
	 * at checkout, and no shop needs choosing — which is the whole point. Plenty of
	 * shops run this plugin for the catalogue alone.
	 */
	const SYNC_ORDERS = 'sync_orders';

	/**
	 * Setting deciding when a paid order is sent to Kontor.
	 *
	 * Defaults to PUSH_IMMEDIATE, which is what the plugin has always done. The sweep
	 * catches whatever the chosen moment missed either way, so the choice is about the
	 * ordinary path rather than about reliability.
	 */
	const ORDER_PUSH_MODE = 'order_push_mode';

	/**
	 * Send each order as soon as it reaches a pushable status.
	 */
	const PUSH_IMMEDIATE = 'immediate';

	/**
	 * Leave every order to the scheduled sweep.
	 */
	const PUSH_SWEEP = 'sweep';

	/**
	 * Hook suffix of the settings screen, used to scope asset loading.
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Retrieve the settings, merged over the defaults.
	 *
	 * @return array Complete settings array.
	 */
	public static function get_settings() {
		$stored = get_option( self::OPTION_KEY, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$defaults = self::default_settings();
		$settings = wp_parse_args( $stored, $defaults );

		/*
		 * wp_parse_args() treats a stored empty string as a real value, so an option
		 * saved before the endpoint default existed would keep an empty base URL
		 * forever. A blank endpoint is never useful, so fall back to the default.
		 */
		if ( '' === trim( (string) $settings['api_base_url'] ) ) {
			$settings['api_base_url'] = $defaults['api_base_url'];
		}

		return $settings;
	}

	/**
	 * The default settings.
	 *
	 * @return array Default settings array.
	 */
	public static function default_settings() {
		return array(
			'api_base_url'            => 'https://sp3api.kontor-crm.de/api/v1/kontor',
			'api_key'                 => '',
			'shoptype'                => 'B2B',
			'shop_id'                 => '',
			'shop_name'               => '',
			'manufacturer_ids'        => array(),
			'manufacturer_names'      => array(),
			'image_base_url'          => '',
			'require_main_image'      => false,
			self::ENFORCE_QUANTITIES  => false,
			self::DRAFT_MISSING_STOCK => false,
			self::SHOW_MSRP           => false,
			self::MSRP_LABEL          => '',
			self::SHOW_EAN            => false,
			self::EAN_LABEL           => '',
			self::SYNC_ORDERS         => true,
			self::ORDER_PUSH_MODE     => self::PUSH_IMMEDIATE,
			'product_sync_interval'   => self::INTERVAL_NEVER,
			'stock_sync_interval'     => self::INTERVAL_NEVER,
			'order_sync_interval'     => self::INTERVAL_NEVER,
			'delivery_sync_interval'  => self::INTERVAL_NEVER,
			'invoice_sync_interval'   => self::INTERVAL_NEVER,
		);
	}

	/**
	 * The shop types Kontor exposes.
	 *
	 * The shop type selects a pricing view of the same catalogue rather than a
	 * different set of articles.
	 *
	 * @return array Map of value to label.
	 */
	public static function shoptypes() {
		return array(
			'B2B' => __( 'B2B — wholesale', 'woo-kontor-sync-pro' ),
			'B2C' => __( 'B2C — retail', 'woo-kontor-sync-pro' ),
			'EDU' => __( 'EDU — education', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * The moments a paid order can be sent to Kontor.
	 *
	 * @return array Map of value to label.
	 */
	public static function order_push_modes() {
		return array(
			self::PUSH_IMMEDIATE => __( 'As soon as they are paid', 'woo-kontor-sync-pro' ),
			self::PUSH_SWEEP     => __( 'Only on the scheduled sweep', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Whether this shop exchanges orders with Kontor.
	 *
	 * Only a value that is there and false switches orders off. An absent key reads as
	 * on, matching the default, and the asymmetry is deliberate: the two ways of being
	 * wrong are not equal. Reading "on" as "off" silently stops a working shop sending
	 * its orders to the ERP, which nobody notices until the warehouse asks; the reverse
	 * queues an upload that Preflight refuses one gate later and logs.
	 *
	 * get_settings() fills the key in from the defaults, so this only arises for a
	 * settings array handed in from elsewhere.
	 *
	 * @param array|null $settings Optional settings override, mainly for tests.
	 * @return bool True when the order, delivery and invoice jobs may run.
	 */
	public static function orders_enabled( $settings = null ) {
		$settings = null === $settings ? self::get_settings() : $settings;

		if ( ! array_key_exists( self::SYNC_ORDERS, (array) $settings ) ) {
			return true;
		}

		return ! empty( $settings[ self::SYNC_ORDERS ] );
	}

	/**
	 * When a paid order is sent.
	 *
	 * Anything unrecognised reads as the default rather than as "never send", because
	 * a stored value this does not know is a fault here and silently holding every
	 * order back would be the worse way to report it.
	 *
	 * @param array|null $settings Optional settings override, mainly for tests.
	 * @return string One of PUSH_IMMEDIATE or PUSH_SWEEP.
	 */
	public static function push_mode( $settings = null ) {
		$settings = null === $settings ? self::get_settings() : $settings;
		$mode     = isset( $settings[ self::ORDER_PUSH_MODE ] ) ? (string) $settings[ self::ORDER_PUSH_MODE ] : '';

		return self::PUSH_SWEEP === $mode ? self::PUSH_SWEEP : self::PUSH_IMMEDIATE;
	}

	/**
	 * Allowed intervals for the product sync.
	 *
	 * @return array Map of seconds to label.
	 */
	public static function product_sync_intervals() {
		return array(
			self::INTERVAL_NEVER => __( 'Never — only when run manually', 'woo-kontor-sync-pro' ),
			7 * DAY_IN_SECONDS   => __( 'Every 7 days', 'woo-kontor-sync-pro' ),
			14 * DAY_IN_SECONDS  => __( 'Every 14 days', 'woo-kontor-sync-pro' ),
			21 * DAY_IN_SECONDS  => __( 'Every 21 days', 'woo-kontor-sync-pro' ),
			30 * DAY_IN_SECONDS  => __( 'Every 30 days', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Allowed intervals for the stock sync.
	 *
	 * @return array Map of seconds to label.
	 */
	public static function stock_sync_intervals() {
		return array(
			self::INTERVAL_NEVER   => __( 'Never — only when run manually', 'woo-kontor-sync-pro' ),
			15 * MINUTE_IN_SECONDS => __( 'Every 15 minutes', 'woo-kontor-sync-pro' ),
			30 * MINUTE_IN_SECONDS => __( 'Every 30 minutes', 'woo-kontor-sync-pro' ),
			HOUR_IN_SECONDS        => __( 'Every hour', 'woo-kontor-sync-pro' ),
			3 * HOUR_IN_SECONDS    => __( 'Every 3 hours', 'woo-kontor-sync-pro' ),
			6 * HOUR_IN_SECONDS    => __( 'Every 6 hours', 'woo-kontor-sync-pro' ),
			12 * HOUR_IN_SECONDS   => __( 'Every 12 hours', 'woo-kontor-sync-pro' ),
			DAY_IN_SECONDS         => __( 'Once a day', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Allowed intervals for the order upload sweep.
	 *
	 * Orders are normally sent the moment they are paid, by the status hook. This
	 * sweep only catches what that missed, so it does not need to be frequent.
	 *
	 * @return array Map of seconds to label.
	 */
	public static function order_sync_intervals() {
		return array(
			self::INTERVAL_NEVER   => __( 'Never — only when run manually', 'woo-kontor-sync-pro' ),
			15 * MINUTE_IN_SECONDS => __( 'Every 15 minutes', 'woo-kontor-sync-pro' ),
			30 * MINUTE_IN_SECONDS => __( 'Every 30 minutes', 'woo-kontor-sync-pro' ),
			HOUR_IN_SECONDS        => __( 'Every hour', 'woo-kontor-sync-pro' ),
			6 * HOUR_IN_SECONDS    => __( 'Every 6 hours', 'woo-kontor-sync-pro' ),
			DAY_IN_SECONDS         => __( 'Once a day', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Allowed intervals for the delivery information import.
	 *
	 * @return array Map of seconds to label.
	 */
	public static function delivery_sync_intervals() {
		return array(
			self::INTERVAL_NEVER   => __( 'Never — only when run manually', 'woo-kontor-sync-pro' ),
			30 * MINUTE_IN_SECONDS => __( 'Every 30 minutes', 'woo-kontor-sync-pro' ),
			HOUR_IN_SECONDS        => __( 'Every hour', 'woo-kontor-sync-pro' ),
			3 * HOUR_IN_SECONDS    => __( 'Every 3 hours', 'woo-kontor-sync-pro' ),
			6 * HOUR_IN_SECONDS    => __( 'Every 6 hours', 'woo-kontor-sync-pro' ),
			12 * HOUR_IN_SECONDS   => __( 'Every 12 hours', 'woo-kontor-sync-pro' ),
			DAY_IN_SECONDS         => __( 'Once a day', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Allowed intervals for the invoice document import.
	 *
	 * Nothing shorter than an hour. Every run walks the shop's whole invoice history
	 * — the entity has no incremental filter — and an invoice appears hours after the
	 * order rather than minutes, so a tighter schedule would only re-read the same
	 * list more often.
	 *
	 * @return array Map of seconds to label.
	 */
	public static function invoice_sync_intervals() {
		return array(
			self::INTERVAL_NEVER => __( 'Never — only when run manually', 'woo-kontor-sync-pro' ),
			HOUR_IN_SECONDS      => __( 'Every hour', 'woo-kontor-sync-pro' ),
			3 * HOUR_IN_SECONDS  => __( 'Every 3 hours', 'woo-kontor-sync-pro' ),
			6 * HOUR_IN_SECONDS  => __( 'Every 6 hours', 'woo-kontor-sync-pro' ),
			12 * HOUR_IN_SECONDS => __( 'Every 12 hours', 'woo-kontor-sync-pro' ),
			DAY_IN_SECONDS       => __( 'Once a day', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Register the admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wksync_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'wp_ajax_wksync_fetch_shops', array( $this, 'handle_fetch_shops' ) );
		add_action( 'wp_ajax_wksync_fetch_manufacturers', array( $this, 'handle_fetch_manufacturers' ) );
		add_action( 'wp_ajax_wksync_job_progress', array( $this, 'handle_job_progress' ) );
		add_action( 'admin_post_wksync_run_job', array( $this, 'handle_run_job' ) );
		add_action( 'admin_post_wksync_check_updates', array( $this, 'handle_check_updates' ) );
		add_action( 'admin_post_wksync_force_push', array( $this, 'handle_force_push' ) );
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::default_settings(),
			)
		);
	}

	/**
	 * Add the settings screen under the WooCommerce menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Kontor Sync', 'woo-kontor-sync-pro' ),
			__( 'Kontor Sync', 'woo-kontor-sync-pro' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Load the connection-test script on the settings screen only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wksync-settings',
			WKSYNC_PLUGIN_URL . 'assets/css/settings.css',
			array(),
			WKSYNC_VERSION
		);

		wp_enqueue_script(
			'wksync-settings',
			WKSYNC_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			WKSYNC_VERSION,
			true
		);

		wp_localize_script(
			'wksync-settings',
			'wksyncSettings',
			array(
				'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
				'nonce'                 => wp_create_nonce( 'wksync_test_connection' ),
				'testing'               => __( 'Testing connection…', 'woo-kontor-sync-pro' ),
				'failed'                => __( 'The connection test could not be completed.', 'woo-kontor-sync-pro' ),
				'shopsNonce'            => wp_create_nonce( 'wksync_fetch_shops' ),
				'fetchingShops'         => __( 'Fetching shops…', 'woo-kontor-sync-pro' ),
				'shopsFailed'           => __( 'The shop list could not be fetched.', 'woo-kontor-sync-pro' ),
				'noShop'                => __( '— No shop selected —', 'woo-kontor-sync-pro' ),
				'unsavedShop'           => __( 'Shops loaded. Choose one, then save the settings.', 'woo-kontor-sync-pro' ),
				'manufacturersNonce'    => wp_create_nonce( 'wksync_fetch_manufacturers' ),
				'fetchingManufacturers' => __( 'Fetching manufacturers…', 'woo-kontor-sync-pro' ),
				'manufacturersFailed'   => __( 'The manufacturer list could not be fetched.', 'woo-kontor-sync-pro' ),
				'unsavedManufacturers'  => __( 'Manufacturers loaded. Tick the ones to import, then save the settings.', 'woo-kontor-sync-pro' ),

				/*
				 * Two templates rather than one call to _n(): the count is only known in
				 * the browser. Correct for English and German, which is what this ships
				 * translations for.
				 */
				'progressNonce'         => wp_create_nonce( 'wksync_job_progress' ),

				/*
				 * How often the running jobs are re-read, in milliseconds. One option read
				 * per poll, and only while something is actually running.
				 */
				'progressInterval'      => 5000,

				'summaryNone'           => self::manufacturer_summary( 0 ),
				'summaryOne'            => self::manufacturer_summary( 1, '%s' ),
				'summaryMany'           => self::manufacturer_summary( 2, '%s' ),
			)
		);
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * An empty key submission keeps the stored key, so the screen never has to
	 * render the existing secret back into the page in plaintext.
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array Sanitised settings.
	 */
	public function sanitize( $input ) {
		$existing = self::get_settings();

		if ( ! is_array( $input ) ) {
			return $existing;
		}

		$submitted_key = isset( $input['api_key'] ) ? self::sanitize_api_key( $input['api_key'] ) : '';
		$shoptype      = isset( $input['shoptype'] ) ? sanitize_text_field( $input['shoptype'] ) : '';
		$shop          = $this->pick_shop( $input, $existing );
		$manufacturers = $this->pick_manufacturers( $input, $existing );

		return array(
			'api_base_url'            => isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '',
			'api_key'                 => '' === $submitted_key ? $existing['api_key'] : $submitted_key,
			'shoptype'                => array_key_exists( $shoptype, self::shoptypes() ) ? $shoptype : $existing['shoptype'],
			'shop_id'                 => $shop['shop_id'],
			'shop_name'               => $shop['shop_name'],
			'manufacturer_ids'        => $manufacturers['manufacturer_ids'],
			'manufacturer_names'      => $manufacturers['manufacturer_names'],
			'image_base_url'          => isset( $input['image_base_url'] ) ? esc_url_raw( trim( $input['image_base_url'] ) ) : '',
			'require_main_image'      => $this->pick_toggle( $input, 'require_main_image', $existing ),
			self::ENFORCE_QUANTITIES  => $this->pick_toggle( $input, self::ENFORCE_QUANTITIES, $existing ),
			self::DRAFT_MISSING_STOCK => $this->pick_toggle( $input, self::DRAFT_MISSING_STOCK, $existing ),
			self::SHOW_MSRP           => $this->pick_toggle( $input, self::SHOW_MSRP, $existing ),
			self::MSRP_LABEL          => $this->pick_label( $input, self::MSRP_LABEL, $existing ),
			self::SHOW_EAN            => $this->pick_toggle( $input, self::SHOW_EAN, $existing ),
			self::EAN_LABEL           => $this->pick_label( $input, self::EAN_LABEL, $existing ),
			self::SYNC_ORDERS         => $this->pick_toggle( $input, self::SYNC_ORDERS, $existing ),
			self::ORDER_PUSH_MODE     => $this->pick_mode( $input, $existing ),
			'product_sync_interval'   => $this->pick_interval( $input, 'product_sync_interval', self::product_sync_intervals(), $existing ),
			'stock_sync_interval'     => $this->pick_interval( $input, 'stock_sync_interval', self::stock_sync_intervals(), $existing ),
			'order_sync_interval'     => $this->pick_interval( $input, 'order_sync_interval', self::order_sync_intervals(), $existing ),
			'delivery_sync_interval'  => $this->pick_interval( $input, 'delivery_sync_interval', self::delivery_sync_intervals(), $existing ),
			'invoice_sync_interval'   => $this->pick_interval( $input, 'invoice_sync_interval', self::invoice_sync_intervals(), $existing ),
		);
	}

	/**
	 * Sanitise an API key without damaging it.
	 *
	 * The generic sanitize_text_field() cannot be used here: it strips percent-encoded
	 * octets, so a key containing "%5a" silently loses three characters and then
	 * fails authentication with a confusing 401. Keys also legitimately contain
	 * non-ASCII characters such as "ß".
	 *
	 * What must still be removed is control characters: the key goes straight into
	 * the x-api-key request header, where a carriage return or newline would allow
	 * header injection.
	 *
	 * @param string $raw Raw submitted key.
	 * @return string Key with control characters removed.
	 */
	public static function sanitize_api_key( $raw ) {
		$key = wp_check_invalid_utf8( (string) $raw );

		// Strip C0 and C1 control characters, including CR and LF.
		$key = preg_replace( '/[\p{Cc}]/u', '', $key );

		return trim( (string) $key );
	}

	/**
	 * Validate a submitted interval against the allowed choices.
	 *
	 * @param array  $input     Raw submitted settings.
	 * @param string $key       Setting name.
	 * @param array  $allowed   Allowed intervals, keyed by seconds.
	 * @param array  $existing  Currently stored settings.
	 * @return int Interval in seconds.
	 */
	protected function pick_interval( array $input, $key, array $allowed, array $existing ) {
		/*
		 * An absent field must keep the stored interval. Defaulting to 0 here would
		 * mean any partial submission silently switched a configured schedule to
		 * Never, since 0 is now a legitimate choice.
		 */
		if ( ! isset( $input[ $key ] ) ) {
			return (int) $existing[ $key ];
		}

		$value = absint( $input[ $key ] );

		return isset( $allowed[ $value ] ) ? $value : (int) $existing[ $key ];
	}

	/**
	 * Validate the submitted order push mode against the allowed choices.
	 *
	 * An absent or unrecognised value keeps the stored one, for the reason the
	 * intervals do: a partial submission must never quietly change when a shop's
	 * orders are sent.
	 *
	 * @param array $input    Raw submitted settings.
	 * @param array $existing Currently stored settings.
	 * @return string One of PUSH_IMMEDIATE or PUSH_SWEEP.
	 */
	protected function pick_mode( array $input, array $existing ) {
		if ( ! isset( $input[ self::ORDER_PUSH_MODE ] ) ) {
			return self::push_mode( $existing );
		}

		$mode = sanitize_text_field( $input[ self::ORDER_PUSH_MODE ] );

		return array_key_exists( $mode, self::order_push_modes() ) ? $mode : self::push_mode( $existing );
	}

	/**
	 * Read a submitted checkbox.
	 *
	 * An absent field keeps the stored value, matching the intervals and the shop: a
	 * partial submission must never silently turn a setting off. A browser submits
	 * nothing at all for a cleared checkbox, so the form pairs every one with a hidden
	 * field carrying zero — that is what makes "off" a value that arrives rather than a
	 * value inferred from silence.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $key      Setting name.
	 * @param array  $existing Currently stored settings.
	 * @return bool The value to store.
	 */
	protected function pick_toggle( array $input, $key, array $existing ) {
		if ( ! isset( $input[ $key ] ) ) {
			return ! empty( $existing[ $key ] );
		}

		return (bool) absint( $input[ $key ] );
	}

	/**
	 * Read a submitted label.
	 *
	 * An absent field keeps the stored label, matching the toggles and the intervals: a
	 * partial submission must never silently reset the wording a shop chose. An
	 * explicitly empty one clears it, which is how a shop asks for the translated
	 * default back.
	 *
	 * Stripped rather than passed through sanitize_text_field(), which eats percent
	 * octets — a label along the lines of "UVP inkl. 20% MwSt." would silently lose the
	 * rest of itself.
	 *
	 * @param array  $input    Raw submitted settings.
	 * @param string $key      Setting name.
	 * @param array  $existing Currently stored settings.
	 * @return string The label to store.
	 */
	protected function pick_label( array $input, $key, array $existing ) {
		if ( ! isset( $input[ $key ] ) ) {
			return isset( $existing[ $key ] ) ? (string) $existing[ $key ] : '';
		}

		return trim( wp_strip_all_tags( (string) $input[ $key ] ) );
	}

	/**
	 * Validate the submitted shop selection.
	 *
	 * The choices come from Kontor rather than from a fixed list here, so there is
	 * no allowlist to check membership against; the GUID shape is what can be
	 * verified. A well-formed but unknown ID is harmless — the API rejects it — while
	 * anything malformed is a tampered or broken submission and keeps the stored
	 * value instead.
	 *
	 * An absent field keeps the stored shop, matching the intervals: a partial
	 * submission must never silently unset a configured shop. An explicitly empty
	 * one clears the selection, which is how "no shop chosen" is expressed.
	 *
	 * @param array $input    Raw submitted settings.
	 * @param array $existing Currently stored settings.
	 * @return array The shop_id and shop_name to store.
	 */
	protected function pick_shop( array $input, array $existing ) {
		$stored = array(
			'shop_id'   => isset( $existing['shop_id'] ) ? (string) $existing['shop_id'] : '',
			'shop_name' => isset( $existing['shop_name'] ) ? (string) $existing['shop_name'] : '',
		);

		if ( ! isset( $input['shop_id'] ) ) {
			return $stored;
		}

		$shop_id = trim( (string) $input['shop_id'] );

		if ( '' === $shop_id ) {
			return array(
				'shop_id'   => '',
				'shop_name' => '',
			);
		}

		if ( ! self::is_shop_id( $shop_id ) ) {
			return $stored;
		}

		/*
		 * The name is a label carried alongside the ID so the saved shop still reads
		 * as a name after a reload, without re-querying Kontor. Nothing is decided by
		 * it, and it is stripped rather than passed through sanitize_text_field(),
		 * which would eat percent octets in a shop name.
		 */
		$name = isset( $input['shop_name'] ) ? trim( wp_strip_all_tags( (string) $input['shop_name'] ) ) : '';

		return array(
			'shop_id'   => $shop_id,
			'shop_name' => $shop_id === $stored['shop_id'] && '' === $name ? $stored['shop_name'] : $name,
		);
	}

	/**
	 * Validate the submitted manufacturer selection.
	 *
	 * An empty selection means "import every manufacturer", which is also what a
	 * fresh install has, so absent and empty cannot both mean the same thing here:
	 * a multi-select with nothing chosen submits no field at all, and treating that
	 * as "clear" would make any partial submission silently widen the import. The
	 * form therefore carries a marker field that is always present, and only a
	 * submission carrying it is allowed to clear the list.
	 *
	 * @param array $input    Raw submitted settings.
	 * @param array $existing Currently stored settings.
	 * @return array The manufacturer_ids and manufacturer_names to store.
	 */
	protected function pick_manufacturers( array $input, array $existing ) {
		$stored = array(
			'manufacturer_ids'   => isset( $existing['manufacturer_ids'] ) ? (array) $existing['manufacturer_ids'] : array(),
			'manufacturer_names' => isset( $existing['manufacturer_names'] ) ? (array) $existing['manufacturer_names'] : array(),
		);

		if ( empty( $input['manufacturer_choice'] ) ) {
			return $stored;
		}

		$submitted = isset( $input['manufacturer_ids'] ) ? (array) $input['manufacturer_ids'] : array();
		$ids       = array();

		foreach ( $submitted as $id ) {
			$id = trim( (string) $id );

			if ( self::is_manufacturer_id( $id ) ) {
				$ids[] = $id;
			}
		}

		$ids = array_values( array_unique( $ids ) );

		return array(
			'manufacturer_ids'   => $ids,
			'manufacturer_names' => $this->pick_manufacturer_names( $input, $stored['manufacturer_names'], $ids ),
		);
	}

	/**
	 * Validate the labels submitted alongside the manufacturer selection.
	 *
	 * The names are carried in a hidden field purely so the saved selection still
	 * reads as names after a reload, without asking Kontor again. Nothing is decided
	 * by them, and a name for an ID that was not selected is discarded rather than
	 * accumulated.
	 *
	 * @param array $input    Raw submitted settings.
	 * @param array $stored   Names currently stored.
	 * @param array $selected Manufacturer IDs that survived validation.
	 * @return array Map of manufacturer ID to display name.
	 */
	protected function pick_manufacturer_names( array $input, array $stored, array $selected ) {
		$submitted = isset( $input['manufacturer_names'] ) ? json_decode( (string) $input['manufacturer_names'], true ) : array();

		if ( ! is_array( $submitted ) ) {
			$submitted = array();
		}

		$names = array();

		foreach ( $selected as $id ) {
			// Stripped rather than passed through sanitize_text_field(), which would eat
			// a percent octet in a manufacturer name.
			$name = isset( $submitted[ $id ] ) && is_scalar( $submitted[ $id ] )
				? trim( wp_strip_all_tags( (string) $submitted[ $id ] ) )
				: '';

			if ( '' === $name && isset( $stored[ $id ] ) ) {
				$name = (string) $stored[ $id ];
			}

			if ( '' !== $name ) {
				$names[ $id ] = $name;
			}
		}

		return $names;
	}

	/**
	 * Whether a value has the shape of a Kontor manufacturer ID.
	 *
	 * Kept as a string and never cast: the IDs carry leading zeros, so "084" would
	 * collide with "84" the moment it became an integer. A comma is rejected because
	 * the IDs are joined with commas into the API filter, where one embedded in a
	 * value would read as two manufacturers.
	 *
	 * @param string $value Value to check.
	 * @return bool True when the value could be a manufacturer ID.
	 */
	public static function is_manufacturer_id( $value ) {
		return 1 === preg_match( '/^[A-Za-z0-9._-]{1,64}$/', (string) $value );
	}

	/**
	 * Whether a value has the shape of a Kontor shop ID.
	 *
	 * Kontor returns canonical GUIDs, such as
	 * "72aa5fcd-5296-4c67-908f-3f2cc3bd09e0".
	 *
	 * @param string $value Value to check.
	 * @return bool True when the value is a well-formed GUID.
	 */
	public static function is_shop_id( $value ) {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value );
	}

	/**
	 * Test the API credentials without saving them.
	 *
	 * Uses whatever is currently typed into the form so the settings can be checked
	 * before they are committed. A blank key means "keep the stored one".
	 *
	 * @return void
	 */
	public function handle_test_connection() {
		check_ajax_referer( 'wksync_test_connection', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) ), 403 );
		}

		$settings = $this->credentials_from_request();
		$client   = new Client( $settings );
		$result   = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			$code = Client::detail( $result, 'error_code' );

			wp_send_json_error(
				array(
					'message' => '' === $code
						? $result->get_error_message()
						: sprintf( '%s (%s)', $result->get_error_message(), $code ),
				)
			);
		}

		$total = isset( $result['meta']['totalCount'] ) ? (int) $result['meta']['totalCount'] : 0;

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: number of articles, 2: shop type such as B2B. */
					__( 'Connected. Kontor reports %1$s articles for %2$s.', 'woo-kontor-sync-pro' ),
					number_format_i18n( $total ),
					$settings['shoptype']
				),
			)
		);
	}

	/**
	 * Fetch the list of shops from Kontor for the settings screen.
	 *
	 * Uses whatever credentials are currently typed into the form, so the shops can
	 * be listed before the settings are saved — otherwise choosing a shop on a fresh
	 * install would require saving an untested key first.
	 *
	 * @return void
	 */
	public function handle_fetch_shops() {
		check_ajax_referer( 'wksync_fetch_shops', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) ), 403 );
		}

		$client = new Client( $this->credentials_from_request() );
		$result = $client->fetch_shops();

		if ( is_wp_error( $result ) ) {
			$code = Client::detail( $result, 'error_code' );

			wp_send_json_error(
				array(
					'message' => '' === $code
						? $result->get_error_message()
						: sprintf( '%s (%s)', $result->get_error_message(), $code ),
				)
			);
		}

		$shops = self::shops_from_response( $result );

		if ( empty( $shops ) ) {
			wp_send_json_error( array( 'message' => __( 'Kontor returned no shops for this key.', 'woo-kontor-sync-pro' ) ) );
		}

		wp_send_json_success(
			array(
				'shops'   => $shops,
				'message' => sprintf(
					/* translators: %s: number of shops found. */
					_n( 'Found %s shop.', 'Found %s shops.', count( $shops ), 'woo-kontor-sync-pro' ),
					number_format_i18n( count( $shops ) )
				),
			)
		);
	}

	/**
	 * Fetch the list of manufacturers from Kontor for the settings screen.
	 *
	 * Fetched on demand rather than cached, for the same reason as the shop list: a
	 * stored copy would quietly go stale, and the one thing worse than an empty list
	 * is a list that is confidently wrong.
	 *
	 * @return void
	 */
	public function handle_fetch_manufacturers() {
		check_ajax_referer( 'wksync_fetch_manufacturers', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) ), 403 );
		}

		$client = new Client( $this->credentials_from_request() );
		$result = $client->fetch_manufacturers();

		if ( is_wp_error( $result ) ) {
			$code = Client::detail( $result, 'error_code' );

			wp_send_json_error(
				array(
					'message' => '' === $code
						? $result->get_error_message()
						: sprintf( '%s (%s)', $result->get_error_message(), $code ),
				)
			);
		}

		$manufacturers = self::manufacturers_from_response( $result );

		if ( empty( $manufacturers ) ) {
			wp_send_json_error( array( 'message' => __( 'Kontor returned no manufacturers for this key.', 'woo-kontor-sync-pro' ) ) );
		}

		wp_send_json_success(
			array(
				'manufacturers' => $manufacturers,
				'message'       => sprintf(
					/* translators: %s: number of manufacturers found. */
					_n( 'Found %s manufacturer.', 'Found %s manufacturers.', count( $manufacturers ), 'woo-kontor-sync-pro' ),
					number_format_i18n( count( $manufacturers ) )
				),
			)
		);
	}

	/**
	 * Reduce a manufacturers response to id and name pairs.
	 *
	 * Rows arrive one per article on some accounts rather than one per manufacturer,
	 * so duplicates are collapsed on the ID. A row without a usable ID is dropped: it
	 * could not be filtered on, so offering it as a choice would be offering a filter
	 * that silently returns nothing.
	 *
	 * @param array $response Decoded envelope from the manufacturer entity.
	 * @return array List of arrays with "id" and "name" keys, sorted by name.
	 */
	public static function manufacturers_from_response( array $response ) {
		$rows          = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$manufacturers = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Herstellerid'] ) ) {
				continue;
			}

			$id = trim( (string) $row['Herstellerid'] );

			if ( ! self::is_manufacturer_id( $id ) || isset( $manufacturers[ $id ] ) ) {
				continue;
			}

			$name = isset( $row['Hersteller'] ) ? trim( wp_strip_all_tags( (string) $row['Hersteller'] ) ) : '';

			$manufacturers[ $id ] = array(
				'id'   => $id,
				'name' => '' === $name ? $id : $name,
			);
		}

		uasort(
			$manufacturers,
			static function ( $left, $right ) {
				return strnatcasecmp( $left['name'], $right['name'] );
			}
		);

		return array_values( $manufacturers );
	}

	/**
	 * Reduce a shops response to id and name pairs.
	 *
	 * Rows without a usable Shopid are dropped rather than offered as a choice that
	 * could never work. A row that arrives without a Name still gets an entry, so a
	 * usable shop is never hidden just because its label is missing.
	 *
	 * @param array $response Decoded envelope from the shops entity.
	 * @return array List of arrays with "id" and "name" keys.
	 */
	public static function shops_from_response( array $response ) {
		$rows  = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
		$shops = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Shopid'] ) ) {
				continue;
			}

			$id = trim( (string) $row['Shopid'] );

			if ( ! self::is_shop_id( $id ) ) {
				continue;
			}

			$name = isset( $row['Name'] ) ? trim( wp_strip_all_tags( (string) $row['Name'] ) ) : '';

			$shops[] = array(
				'id'   => $id,
				'name' => '' === $name ? $id : $name,
			);
		}

		return $shops;
	}

	/**
	 * Build a settings array from the credentials typed into the form.
	 *
	 * Shared by the connection test and the shop lookup so the two cannot drift on
	 * how they read a key — the sanitising here is deliberate and easy to get wrong.
	 * A blank field means "use the stored value".
	 *
	 * The caller is responsible for the nonce and capability checks.
	 *
	 * @return array Settings suitable for constructing a Client.
	 */
	protected function credentials_from_request() {
		$stored = self::get_settings();

		/*
		 * The nonce is verified by the calling handler, which is why the sniff cannot
		 * see it from in here.
		 */
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_api_key() is the sanitiser; sanitize_text_field() would corrupt the key.
		$key      = isset( $_POST['api_key'] ) ? self::sanitize_api_key( wp_unslash( $_POST['api_key'] ) ) : '';
		$base     = isset( $_POST['api_base_url'] ) ? trim( esc_url_raw( wp_unslash( $_POST['api_base_url'] ) ) ) : '';
		$shoptype = isset( $_POST['shoptype'] ) ? sanitize_text_field( wp_unslash( $_POST['shoptype'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'api_base_url' => '' === $base ? $stored['api_base_url'] : $base,
			'api_key'      => '' === $key ? $stored['api_key'] : $key,
			'shoptype'     => array_key_exists( $shoptype, self::shoptypes() ) ? $shoptype : $stored['shoptype'],
		);
	}

	/**
	 * Queue a sync job to run immediately.
	 *
	 * @return void
	 */
	public function handle_run_job() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) );
		}

		$job = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';

		check_admin_referer( 'wksync_run_job_' . $job );

		$queued = Scheduler::trigger( $job );
		$args   = array(
			'page'          => self::PAGE_SLUG,
			'wksync_queued' => is_wp_error( $queued ) ? 'failed' : $job,
		);

		/*
		 * The reason travels as a code rather than a message: a message in the URL
		 * would have to be re-escaped on the way out and could be edited into
		 * anything by whoever holds the link.
		 */
		if ( is_wp_error( $queued ) ) {
			$args['wksync_reason'] = $queued->get_error_code();
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Force orders back to Kontor with overwrite enabled, in this request.
	 *
	 * Everything here runs before the redirect, so the operator waits for it and then
	 * reads the reply. That is the point: the sync jobs are queued precisely so that
	 * nobody waits on Kontor, and this is the one action where the answer matters more
	 * than the wait.
	 *
	 * @return void
	 */
	public function handle_force_push() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) );
		}

		check_admin_referer( 'wksync_force_push' );

		$scope     = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
		$order_ids = array();
		$refusal   = '';

		if ( 'all' === $scope ) {
			$typed = isset( $_POST['confirmation'] )
				? trim( sanitize_text_field( wp_unslash( $_POST['confirmation'] ) ) )
				: '';

			/*
			 * Checked on the server rather than with a JavaScript confirm, which is a
			 * courtesy to a browser in exactly the way a min attribute is: it can be
			 * turned off, and this request can be made without ever loading the page.
			 */
			if ( self::FORCE_PUSH_CONFIRMATION !== $typed ) {
				$refusal = sprintf(
					/* translators: %s: the word that must be typed to confirm. */
					__( 'Nothing was sent. Type %s to confirm overwriting every order already in Kontor.', 'woo-kontor-sync-pro' ),
					self::FORCE_PUSH_CONFIRMATION
				);
			} else {
				$order_ids = ( new OrderSync() )->pushed_order_ids( OrderSync::FORCE_LIMIT );

				if ( empty( $order_ids ) ) {
					$refusal = __( 'No orders have been sent to Kontor yet, so there is nothing to overwrite.', 'woo-kontor-sync-pro' );
				}
			}
		} else {
			$order_id = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
			$order    = $order_id ? wc_get_order( $order_id ) : null;

			if ( ! $order ) {
				$refusal = __( 'No order with that number could be found.', 'woo-kontor-sync-pro' );
			} else {
				$order_ids = array( $order_id );
			}
		}

		if ( '' !== $refusal ) {
			$this->store_force_push_result( array( 'error' => $refusal ) );
			$this->redirect_after_force_push();
		}

		/*
		 * A hundred orders is up to four round trips at Client::REQUEST_TIMEOUT each,
		 * and the host's own limit is what would otherwise cut the reply off after the
		 * work had already been done in the ERP. WooCommerce's helper is a no-op where
		 * the host forbids it, which is why the batch is bounded as well.
		 */
		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 0 );
		}

		$this->store_force_push_result( ( new OrderSync() )->force_push( $order_ids ) );
		$this->redirect_after_force_push();
	}

	/**
	 * Keep a force push's result for the screen that follows the redirect.
	 *
	 * @param array $result Result from OrderSync::force_push(), or a bare error.
	 * @return void
	 */
	protected function store_force_push_result( array $result ) {
		set_transient( self::FORCE_PUSH_RESULT . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Return to the settings screen after a force push and stop.
	 *
	 * @return void
	 */
	protected function redirect_after_force_push() {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => self::PAGE_SLUG,
					'wksync_force' => '1',
				),
				admin_url( 'admin.php' )
			)
		);

		exit;
	}

	/**
	 * Report where every job has got to, for the progress bars.
	 *
	 * Deliberately cheap: the whole answer comes from one non-autoloaded option, plus
	 * a single counting query for the image queue. It is polled every few seconds
	 * while something is running, so anything more would be a self-inflicted load.
	 *
	 * @return void
	 */
	public function handle_job_progress() {
		check_ajax_referer( 'wksync_job_progress', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) ), 403 );
		}

		$jobs = array();

		foreach ( array_keys( Scheduler::get_jobs() ) as $key ) {
			$status = Status::get( $key );

			$jobs[ $key ] = array(
				'state'    => (string) $status['state'],
				'running'  => 'running' === $status['state'],
				'percent'  => Status::percentage( $status ),
				'summary'  => $this->describe_status( $status ),
				'detail'   => $this->describe_position( $status ),
				'nextRun'  => Scheduler::next_run( $key ),
				'nextText' => $this->describe_next_run( Scheduler::next_run( $key ) ),
			);
		}

		$images = Scheduler::pending_count( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );

		wp_send_json_success(
			array(
				'jobs'   => $jobs,
				'images' => array(
					'pending' => $images,
					'text'    => $this->describe_image_queue( $images ),
				),
			)
		);
	}

	/**
	 * Look for a new release now, rather than waiting for core's next check.
	 *
	 * Gated on update_plugins rather than this screen's own capability: a shop manager
	 * can run every sync here but cannot install a plugin, and a button offering to
	 * find them an update they are not allowed to apply is only a way to be told no.
	 *
	 * @return void
	 */
	public function handle_check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) );
		}

		check_admin_referer( 'wksync_check_updates' );

		$status = ( new Updater() )->refresh();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE_SLUG,
					'wksync_update' => $status['state'],
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * The reasons a job can refuse to be queued.
	 *
	 * Looked up from the code in the URL so nothing user-supplied is ever echoed.
	 *
	 * @return array Map of error code to message.
	 */
	protected function refusal_messages() {
		return array(
			'wksync_unavailable'     => __( 'The job could not be queued. Check that WooCommerce is active.', 'woo-kontor-sync-pro' ),
			'wksync_already_running' => __( 'That job is already running.', 'woo-kontor-sync-pro' ),
			'wksync_not_configured'  => __( 'Set the API base URL and API key before running a sync.', 'woo-kontor-sync-pro' ),
			'wksync_no_shop'         => __( 'Choose a Kontor shop before syncing orders.', 'woo-kontor-sync-pro' ),
			'wksync_orders_disabled' => __( 'This shop does not exchange orders with Kontor. Turn that on under Orders first.', 'woo-kontor-sync-pro' ),
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage these settings.', 'woo-kontor-sync-pro' ) );
		}

		$settings  = self::get_settings();
		$orders    = self::orders_enabled( $settings );
		$push_mode = self::push_mode( $settings );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kontor Sync', 'woo-kontor-sync-pro' ); ?></h1>

			<?php $this->render_queued_notice(); ?>
			<?php $this->render_update_notice(); ?>

			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<h2><?php echo esc_html__( 'Connection', 'woo-kontor-sync-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wksync-api-base-url"><?php echo esc_html__( 'API base URL', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								class="regular-text code"
								id="wksync-api-base-url"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_base_url]"
								value="<?php echo esc_attr( $settings['api_base_url'] ); ?>"
							/>
							<p class="description"><?php echo esc_html__( 'The search endpoint is appended to this, so omit the trailing "/search".', 'woo-kontor-sync-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-api-key"><?php echo esc_html__( 'API key', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								class="regular-text"
								id="wksync-api-key"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_key]"
								value=""
								autocomplete="new-password"
							/>
							<p class="description">
								<?php
								echo esc_html(
									'' === $settings['api_key']
										? __( 'No key stored yet. Sent as the x-api-key header.', 'woo-kontor-sync-pro' )
										: __( 'A key is stored. Leave this field blank to keep it.', 'woo-kontor-sync-pro' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-shoptype"><?php echo esc_html__( 'Shop type', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<select id="wksync-shoptype" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shoptype]">
								<?php foreach ( self::shoptypes() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $settings['shoptype'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php echo esc_html__( 'Selects which price list is imported. The article list is the same for every shop type; only the selling price differs.', 'woo-kontor-sync-pro' ); ?></p>
							<p class="description"><?php echo esc_html__( 'B2B also imports the retail price as a recommended retail price, stored on each product as _wksync_msrp. It is the figure a business can resell at, and it is left off any article Kontor lists no retail price for.', 'woo-kontor-sync-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Connection test', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<button type="button" class="button" id="wksync-test-connection">
								<?php echo esc_html__( 'Test connection', 'woo-kontor-sync-pro' ); ?>
							</button>
							<p class="description" id="wksync-test-result" aria-live="polite"></p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Products', 'woo-kontor-sync-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Manufacturers', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							$chosen = (array) $settings['manufacturer_ids'];

							/*
							 * Always submitted, so an empty selection can be told apart from a
							 * submission that never had the field. Without it, choosing nothing
							 * would look identical to a partial save and could never clear the
							 * filter.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[manufacturer_choice]" value="1"/>

							<?php
							/*
							 * Checkboxes rather than a multi-select. A multi-select cannot be
							 * emptied without knowing to ctrl-click the last remaining item, and a
							 * plain click on any option silently collapses the whole selection to
							 * that one — so the control both hides the way out and destroys work on
							 * the way in.
							 */
							?>
							<div
								id="wksync-manufacturer-list"
								class="wksync-choice-list"
								role="group"
								aria-label="<?php echo esc_attr__( 'Manufacturers to import', 'woo-kontor-sync-pro' ); ?>"
								data-empty="<?php echo esc_attr__( 'Every manufacturer is imported.', 'woo-kontor-sync-pro' ); ?>"
								data-field="<?php echo esc_attr( self::OPTION_KEY . '[manufacturer_ids][]' ); ?>"
							>
								<?php foreach ( $chosen as $manufacturer_id ) : ?>
									<?php $this->render_manufacturer_choice( $manufacturer_id, self::manufacturer_label( $settings, $manufacturer_id ) ); ?>
								<?php endforeach; ?>
							</div>

							<p class="wksync-choice-actions">
								<button type="button" class="button" id="wksync-fetch-manufacturers">
									<?php echo esc_html__( 'Fetch manufacturers', 'woo-kontor-sync-pro' ); ?>
								</button>
								<button type="button" class="button" id="wksync-clear-manufacturers" <?php disabled( empty( $chosen ) ); ?>>
									<?php echo esc_html__( 'Import everything', 'woo-kontor-sync-pro' ); ?>
								</button>
							</p>

							<input
								type="hidden"
								id="wksync-manufacturer-names"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[manufacturer_names]"
								value="<?php echo esc_attr( (string) wp_json_encode( (array) $settings['manufacturer_names'] ) ); ?>"
							/>

							<?php
							/*
							 * Rendered server-side as well as by the script, so the state is
							 * readable before the script runs and if it never does.
							 */
							?>
							<p class="description" id="wksync-manufacturers-summary" aria-live="polite">
								<?php echo esc_html( self::manufacturer_summary( count( $chosen ) ) ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Tick the manufacturers to import. Fetch the list to add more; leave every box clear to import the whole catalogue.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<strong><?php echo esc_html__( 'Narrowing this drafts products.', 'woo-kontor-sync-pro' ); ?></strong>
								<?php echo esc_html__( 'Articles the filter excludes are no longer in the feed, so the next product sync drafts the ones it previously imported. Widening the filter again republishes them.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description" id="wksync-manufacturers-result" aria-live="polite"></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-image-base-url"><?php echo esc_html__( 'Image base URL', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<input
								type="url"
								class="regular-text code"
								id="wksync-image-base-url"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[image_base_url]"
								value="<?php echo esc_attr( $settings['image_base_url'] ); ?>"
							/>
							<p class="description"><?php echo esc_html__( 'Kontor returns image filenames rather than URLs. Set the folder they live in to import product images; leave blank to skip images.', 'woo-kontor-sync-pro' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Articles without images', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							/*
							 * The hidden field is what makes a cleared box mean "off". A browser
							 * submits nothing for an unticked checkbox, and an absent field has
							 * to keep the stored value, or any partial save would quietly
							 * republish the whole set of articles this holds back.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_main_image]" value="0" />
							<label for="wksync-require-main-image">
								<input
									type="checkbox"
									id="wksync-require-main-image"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[require_main_image]"
									value="1"
									<?php checked( ! empty( $settings['require_main_image'] ) ); ?>
								/>
								<?php echo esc_html__( 'Only import articles that Kontor lists an image for', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'An article with no image is passed over rather than created. The check is on what Kontor sends, not on the shop, so a product whose pictures are still downloading is never caught by it.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<strong><?php echo esc_html__( 'This drafts products already imported.', 'woo-kontor-sync-pro' ); ?></strong>
								<?php echo esc_html__( 'A product this plugin imported whose article now arrives without an image is drafted, exactly as one Kontor stopped listing is. It is republished by itself as soon as the article has an image again, or when this setting is turned off.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Sales quantities', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							/*
							 * Paired with a hidden zero for the same reason as the image
							 * requirement above: a browser sends nothing for a cleared checkbox,
							 * and an absent field has to keep the stored value.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::ENFORCE_QUANTITIES ); ?>]" value="0" />
							<label for="wksync-enforce-quantities">
								<input
									type="checkbox"
									id="wksync-enforce-quantities"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::ENFORCE_QUANTITIES ); ?>]"
									value="1"
									<?php checked( ! empty( $settings[ self::ENFORCE_QUANTITIES ] ) ); ?>
								/>
								<?php echo esc_html__( 'Hold customers to the quantities Kontor sells each article in', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Kontor states a smallest quantity and a step for every article, imported as _wksync_min_qty and _wksync_qty_step. An article sold in sixes with a step of two can then only be bought as 6, 8, 10 and so on — in the quantity box, in the cart and at checkout alike.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Leave it clear to ignore them. The figures are still imported, so turning this on takes effect immediately rather than after the next product sync. Order screens and refunds are never restricted by it.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Both figures are shown on each product\'s Inventory tab, where they are read-only: Kontor supplies them and every sync rewrites them, so they are changed in the ERP.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Articles without stock records', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							/*
							 * Paired with a hidden zero for the same reason as the two above: a
							 * browser sends nothing for a cleared checkbox, and an absent field
							 * has to keep the stored value.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::DRAFT_MISSING_STOCK ); ?>]" value="0" />
							<label for="wksync-draft-missing-stock">
								<input
									type="checkbox"
									id="wksync-draft-missing-stock"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::DRAFT_MISSING_STOCK ); ?>]"
									value="1"
									<?php checked( ! empty( $settings[ self::DRAFT_MISSING_STOCK ] ) ); ?>
								/>
								<?php echo esc_html__( 'Draft imported products the stock feed does not carry', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Kontor\'s stock list is narrower than its catalogue: it holds no record at all for some articles the catalogue lists. Left clear, those products keep the level they last had and stay published, and whether Kontor still sells an article is left to the product sync to answer.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<strong><?php echo esc_html__( 'Ticking this hides a large part of the catalogue.', 'woo-kontor-sync-pro' ); ?></strong>
								<?php echo esc_html__( 'On the account this was built against the catalogue lists 4386 articles and the stock feed carries 2945, so the first run after ticking it drafts some 1400 products. Each one comes back by itself as soon as a stock level for it arrives again.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Clearing it again republishes what it drafted, on the next stock sync — unless the product sync is holding the product back for its own reason.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-product-sync-interval"><?php echo esc_html__( 'Product sync', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<?php
							$this->render_interval_select(
								'wksync-product-sync-interval',
								'product_sync_interval',
								self::product_sync_intervals(),
								(int) $settings['product_sync_interval']
							);
							?>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-stock-sync-interval"><?php echo esc_html__( 'Stock sync', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<?php
							$this->render_interval_select(
								'wksync-stock-sync-interval',
								'stock_sync_interval',
								self::stock_sync_intervals(),
								(int) $settings['stock_sync_interval']
							);
							?>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Product page', 'woo-kontor-sync-pro' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Both rows are added to the product meta block, beside the article number and the categories, and each is shown only on a product that has the figure.', 'woo-kontor-sync-pro' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Recommended retail price', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							/*
							 * Paired with a hidden zero like every other checkbox here: a browser
							 * sends nothing for a cleared box, and an absent field has to keep the
							 * stored value.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SHOW_MSRP ); ?>]" value="0" />
							<label for="wksync-show-msrp">
								<input
									type="checkbox"
									id="wksync-show-msrp"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SHOW_MSRP ); ?>]"
									value="1"
									<?php checked( ! empty( $settings[ self::SHOW_MSRP ] ) ); ?>
								/>
								<?php echo esc_html__( 'Show the recommended retail price on the product page', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p>
								<label for="wksync-msrp-label"><?php echo esc_html__( 'Label', 'woo-kontor-sync-pro' ); ?></label>
								<input
									type="text"
									class="regular-text"
									id="wksync-msrp-label"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::MSRP_LABEL ); ?>]"
									value="<?php echo esc_attr( (string) $settings[ self::MSRP_LABEL ] ); ?>"
									placeholder="<?php echo esc_attr( ProductMeta::msrp_label() ); ?>"
								/>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Kontor supplies this figure on a wholesale shop only, where it sells at Ek and the UVP beside it is the price a business buying here can resell at. It is imported as _wksync_msrp and, until now, nothing rendered it.', 'woo-kontor-sync-pro' ); ?>
							</p>
							<p class="description">
								<?php echo esc_html__( 'The label is shown in front of the amount. Leave it empty for the default wording in the shop\'s language, and remember that it is what the customer reads: the figure is stated raw, not as a saving, and Kontor lists a retail price no higher than the shop\'s own for a small number of articles.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'EAN', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SHOW_EAN ); ?>]" value="0" />
							<label for="wksync-show-ean">
								<input
									type="checkbox"
									id="wksync-show-ean"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SHOW_EAN ); ?>]"
									value="1"
									<?php checked( ! empty( $settings[ self::SHOW_EAN ] ) ); ?>
								/>
								<?php echo esc_html__( 'Show the EAN on the product page', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p>
								<label for="wksync-ean-label"><?php echo esc_html__( 'Label', 'woo-kontor-sync-pro' ); ?></label>
								<input
									type="text"
									class="regular-text"
									id="wksync-ean-label"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::EAN_LABEL ); ?>]"
									value="<?php echo esc_attr( (string) $settings[ self::EAN_LABEL ] ); ?>"
									placeholder="<?php echo esc_attr( ProductMeta::ean_label() ); ?>"
								/>
							</p>
							<p class="description">
								<?php echo esc_html__( 'The EAN Kontor sends as Artean, held in WooCommerce\'s own GTIN field. EANs repeat across articles in the feed and WooCommerce refuses a duplicate, so a product whose EAN another already holds has none and shows no row.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php echo esc_html__( 'Orders', 'woo-kontor-sync-pro' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Orders with Kontor', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php
							/*
							 * Hidden field first, as everywhere else here: a browser submits
							 * nothing for an unticked box, and an absent field keeps the stored
							 * value, so "off" has to be a value that arrives.
							 */
							?>
							<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SYNC_ORDERS ); ?>]" value="0" />
							<label for="wksync-sync-orders">
								<input
									type="checkbox"
									id="wksync-sync-orders"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::SYNC_ORDERS ); ?>]"
									value="1"
									<?php checked( $orders ); ?>
								/>
								<?php echo esc_html__( 'Send orders to Kontor, and bring deliveries and invoices back', 'woo-kontor-sync-pro' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Leave this off for a shop that only imports the catalogue. Nothing is sent at checkout, the three jobs below never run, and no Kontor shop has to be chosen. Turning it back on restores the schedules exactly as they were.', 'woo-kontor-sync-pro' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php
				/*
				 * Rendered whether or not orders are switched on, and hidden rather than
				 * left out, so ticking the box above reveals the rest without a save. The
				 * fields still submit while hidden, which is what keeps a shop's stored
				 * shop and intervals through a save made with the section closed.
				 */
				?>
				<div id="wksync-order-settings" <?php echo $orders ? '' : 'hidden'; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="wksync-shop-id"><?php echo esc_html__( 'Shop', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<select id="wksync-shop-id" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shop_id]">
									<option value=""><?php echo esc_html__( '— No shop selected —', 'woo-kontor-sync-pro' ); ?></option>
									<?php if ( '' !== $settings['shop_id'] ) : ?>
										<option value="<?php echo esc_attr( $settings['shop_id'] ); ?>" selected>
											<?php echo esc_html( '' === $settings['shop_name'] ? $settings['shop_id'] : $settings['shop_name'] ); ?>
										</option>
									<?php endif; ?>
								</select>
								<button type="button" class="button" id="wksync-fetch-shops">
									<?php echo esc_html__( 'Fetch shops', 'woo-kontor-sync-pro' ); ?>
								</button>
								<input
									type="hidden"
									id="wksync-shop-name"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[shop_name]"
									value="<?php echo esc_attr( $settings['shop_name'] ); ?>"
								/>
								<p class="description">
									<?php echo esc_html__( 'Identifies this store in Kontor. All three jobs below need one, and none of them runs until it is chosen. The product and stock syncs do not use it at all.', 'woo-kontor-sync-pro' ); ?>
								</p>
								<p class="description" id="wksync-shops-result" aria-live="polite"></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wksync-upload-user-id"><?php echo esc_html__( 'Upload user ID', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<?php
								/*
								 * Shown for reference only. It carries no name attribute, so it is
								 * never submitted and sanitize() has nothing to validate — the value
								 * lives in OrderSync::UPLOAD_USER_ID and cannot drift from what is
								 * actually sent.
								 */
								?>
								<input
									type="text"
									class="regular-text code"
									id="wksync-upload-user-id"
									value="<?php echo esc_attr( OrderSync::UPLOAD_USER_ID ); ?>"
									readonly
								/>
								<p class="description"><?php echo esc_html__( 'Sent with every order upload as meta.userId. Fixed by agreement with Kontor and not editable here.', 'woo-kontor-sync-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wksync-order-push-mode"><?php echo esc_html__( 'Send orders', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<select id="wksync-order-push-mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[<?php echo esc_attr( self::ORDER_PUSH_MODE ); ?>]">
									<?php foreach ( self::order_push_modes() as $value => $label ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $push_mode, $value ); ?>>
											<?php echo esc_html( $label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php echo esc_html__( 'Sending as they are paid reaches Kontor within a minute of checkout. Either way the sweep below catches whatever that moment missed, so this is a choice about the ordinary path rather than about reliability.', 'woo-kontor-sync-pro' ); ?>
								</p>
								<?php if ( self::PUSH_SWEEP === $push_mode && self::INTERVAL_NEVER === (int) $settings['order_sync_interval'] ) : ?>
									<p class="description">
										<strong><?php echo esc_html__( 'Nothing will send orders on its own.', 'woo-kontor-sync-pro' ); ?></strong>
										<?php echo esc_html__( 'With the sweep below set to Never as well, an order reaches Kontor only when Run now is pressed.', 'woo-kontor-sync-pro' ); ?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wksync-order-sync-interval"><?php echo esc_html__( 'Order sync', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<?php
								$this->render_interval_select(
									'wksync-order-sync-interval',
									'order_sync_interval',
									self::order_sync_intervals(),
									(int) $settings['order_sync_interval']
								);
								?>
								<p class="description"><?php echo esc_html__( 'A sweep for whatever the moment above missed — an order Kontor rejected, or one placed while the site could not reach it.', 'woo-kontor-sync-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wksync-delivery-sync-interval"><?php echo esc_html__( 'Delivery sync', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<?php
								$this->render_interval_select(
									'wksync-delivery-sync-interval',
									'delivery_sync_interval',
									self::delivery_sync_intervals(),
									(int) $settings['delivery_sync_interval']
								);
								?>
								<p class="description"><?php echo esc_html__( 'Pulls tracking details back from Kontor. An order Kontor reports as completed is completed here too, which emails the customer.', 'woo-kontor-sync-pro' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="wksync-invoice-sync-interval"><?php echo esc_html__( 'Invoice sync', 'woo-kontor-sync-pro' ); ?></label>
							</th>
							<td>
								<?php
								$this->render_interval_select(
									'wksync-invoice-sync-interval',
									'invoice_sync_interval',
									self::invoice_sync_intervals(),
									(int) $settings['invoice_sync_interval']
								);
								?>
								<p class="description"><?php echo esc_html__( 'Downloads invoice PDFs from Kontor. Each invoice is stored privately, shown to the customer on their order, and attached to the order emails sent after it arrives.', 'woo-kontor-sync-pro' ); ?></p>
							</td>
						</tr>
					</table>

					<?php
					/*
					 * The two mails these jobs can send are WooCommerce email types rather
					 * than settings of this plugin's, so their switches, subjects and
					 * headings all live where a shop manager already manages email. This
					 * line is what stops that being the same as hiding them.
					 */
					?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the WooCommerce email settings screen. */
							esc_html__( 'The delivery and invoice syncs can also email the customer when tracking details or an invoice arrive. Both are switched off until you turn them on under %s.', 'woo-kontor-sync-pro' ),
							sprintf(
								'<a href="%1$s">%2$s</a>',
								esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ),
								esc_html__( 'WooCommerce → Settings → Emails', 'woo-kontor-sync-pro' )
							)
						);
						?>
					</p>
				</div>

				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Scheduled jobs', 'woo-kontor-sync-pro' ); ?></h2>
			<?php $this->render_jobs_table(); ?>

			<?php $this->render_force_push_section(); ?>

			<?php $this->render_updates_section(); ?>
		</div>
		<?php
	}

	/**
	 * Offer to re-send orders to Kontor with overwrite enabled.
	 *
	 * Two paths, and the single-order one comes first because it is how the bulk one
	 * should be approached: overwrite_all's behaviour was never established against a
	 * live account, so the first press should risk one order rather than the shop.
	 *
	 * Nothing at all on a shop that does not exchange orders with Kontor: there is no
	 * order in the ERP for it to overwrite.
	 *
	 * @return void
	 */
	protected function render_force_push_section() {
		if ( ! self::orders_enabled() ) {
			return;
		}

		?>
		<h2><?php echo esc_html__( 'Force push to Kontor', 'woo-kontor-sync-pro' ); ?></h2>

		<div class="notice notice-warning inline">
			<p>
				<?php echo esc_html__( 'An ordinary push cannot change an order Kontor already holds. Kontor deduplicates on the order number and the sync leaves overwrite off, so a re-send is answered with "Dublette" and an order edited after it was sent never reaches the ERP. This sends it again with overwrite enabled.', 'woo-kontor-sync-pro' ); ?>
			</p>
			<p>
				<strong><?php echo esc_html__( 'Kontor\'s exact behaviour under overwrite has not been established against a live account.', 'woo-kontor-sync-pro' ); ?></strong>
				<?php echo esc_html__( 'Try one order first and read the reply below before using this on the whole shop. It runs in this request rather than in the background, so leave the page open until it answers.', 'woo-kontor-sync-pro' ); ?>
			</p>
		</div>

		<?php $this->render_force_push_result(); ?>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="wksync_force_push"/>
			<input type="hidden" name="scope" value="single"/>
			<?php wp_nonce_field( 'wksync_force_push' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wksync-force-order"><?php echo esc_html__( 'Order number', 'woo-kontor-sync-pro' ); ?></label>
					</th>
					<td>
						<input type="number" min="1" step="1" class="small-text" id="wksync-force-order" name="order_id" value=""/>
						<button type="submit" class="button"><?php echo esc_html__( 'Force push this order', 'woo-kontor-sync-pro' ); ?></button>
						<p class="description">
							<?php echo esc_html__( 'The order ID, which is what this plugin sends as the order number. It is shown in the order screen\'s URL, and is not always the number the shop displays.', 'woo-kontor-sync-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>
		</form>

		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="wksync_force_push"/>
			<input type="hidden" name="scope" value="all"/>
			<?php wp_nonce_field( 'wksync_force_push' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wksync-force-confirm"><?php echo esc_html__( 'Force push every sent order', 'woo-kontor-sync-pro' ); ?></label>
					</th>
					<td>
						<input type="text" class="regular-text" id="wksync-force-confirm" name="confirmation" value="" autocomplete="off"/>
						<button type="submit" class="button"><?php echo esc_html__( 'Force push all', 'woo-kontor-sync-pro' ); ?></button>
						<p class="description">
							<?php
							printf(
								/* translators: 1: the word that must be typed to confirm, 2: largest number of orders one press sends. */
								esc_html__( 'Type %1$s to confirm. This overwrites what Kontor holds for every order this plugin has already sent, oldest first, up to %2$d of them in one press. Orders never sent are left alone — the ordinary sweep is already sending those.', 'woo-kontor-sync-pro' ),
								esc_html( self::FORCE_PUSH_CONFIRMATION ),
								absint( OrderSync::FORCE_LIMIT )
							);
							?>
						</p>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}

	/**
	 * Show what the last force push did, then forget it.
	 *
	 * The raw envelope is printed alongside the counts because the counts are this
	 * plugin's reading of the reply and the envelope is the reply. Under a flag whose
	 * behaviour nobody has pinned down, the second is the one worth having.
	 *
	 * @return void
	 */
	protected function render_force_push_result() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; the push itself was nonce-checked.
		if ( ! isset( $_GET['wksync_force'] ) ) {
			return;
		}

		$key    = self::FORCE_PUSH_RESULT . get_current_user_id();
		$result = get_transient( $key );

		if ( ! is_array( $result ) ) {
			return;
		}

		// Read once. A reload would otherwise keep reporting a push that is long over.
		delete_transient( $key );

		if ( ! empty( $result['error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( $result['error'] )
			);
		}

		if ( empty( $result['rows'] ) && empty( $result['responses'] ) ) {
			return;
		}

		$failed = isset( $result['failed'] ) ? (int) $result['failed'] : 0;
		?>
		<div class="notice <?php echo esc_attr( $failed > 0 ? 'notice-warning' : 'notice-success' ); ?>">
			<p>
				<?php
				printf(
					/* translators: 1: orders overwritten, 2: orders Kontor still called duplicates, 3: orders rejected, 4: orders skipped. */
					esc_html__( '%1$d overwritten, %2$d still reported as duplicates, %3$d rejected, %4$d skipped.', 'woo-kontor-sync-pro' ),
					isset( $result['sent'] ) ? (int) $result['sent'] : 0,
					isset( $result['duplicate'] ) ? (int) $result['duplicate'] : 0,
					absint( $failed ),
					isset( $result['skipped'] ) ? (int) $result['skipped'] : 0
				);
				?>
			</p>
			<?php if ( ! empty( $result['duplicate'] ) ) : ?>
				<p>
					<?php echo esc_html__( 'An order still reported as a duplicate means Kontor declined to overwrite it. The edit has not reached the ERP and has to be made there.', 'woo-kontor-sync-pro' ); ?>
				</p>
			<?php endif; ?>
		</div>

		<?php if ( ! empty( $result['rows'] ) ) : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__( 'Order', 'woo-kontor-sync-pro' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'Result', 'woo-kontor-sync-pro' ); ?></th>
						<th scope="col"><?php echo esc_html__( 'What Kontor said', 'woo-kontor-sync-pro' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['rows'] as $row ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . absint( $row['order'] ) ) ); ?>">
									<?php echo esc_html( '#' . absint( $row['order'] ) ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $this->describe_force_row( (string) $row['status'] ) ); ?></td>
							<td><?php echo esc_html( '' === $row['message'] ? '—' : $row['message'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $result['responses'] ) ) : ?>
			<h3><?php echo esc_html__( 'What Kontor returned', 'woo-kontor-sync-pro' ); ?></h3>
			<?php foreach ( $result['responses'] as $index => $response ) : ?>
				<p>
					<strong>
						<?php
						printf(
							/* translators: %d: request number within this force push. */
							esc_html__( 'Request %d', 'woo-kontor-sync-pro' ),
							absint( $index ) + 1
						);
						?>
					</strong>
					<?php if ( empty( $response['ok'] ) ) : ?>
						&mdash; <?php echo esc_html( $response['message'] ); ?>
						<?php if ( '' !== $response['code'] ) : ?>
							(<?php echo esc_html( $response['code'] ); ?>)
						<?php endif; ?>
					<?php endif; ?>
				</p>
				<pre class="wksync-force-response"><?php echo esc_html( $this->encode_force_response( $response['raw'] ) ); ?></pre>
			<?php endforeach; ?>
			<p class="description">
				<?php echo esc_html__( 'The same replies are in WooCommerce → Status → Logs, under woo-kontor-sync.', 'woo-kontor-sync-pro' ); ?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Name one order's outcome in a force push.
	 *
	 * @param string $status Outcome recorded by OrderSync::force_push().
	 * @return string Wording for the table.
	 */
	protected function describe_force_row( $status ) {
		switch ( $status ) {
			case 'ok':
				return __( 'Overwritten', 'woo-kontor-sync-pro' );
			case 'duplicate':
				return __( 'Refused as a duplicate', 'woo-kontor-sync-pro' );
			default:
				return __( 'Rejected', 'woo-kontor-sync-pro' );
		}
	}

	/**
	 * Render a reply for display.
	 *
	 * @param mixed $raw Decoded envelope, or null when there was nothing to decode.
	 * @return string Pretty-printed JSON, or a note that there was no body.
	 */
	protected function encode_force_response( $raw ) {
		if ( null === $raw ) {
			return __( 'Kontor returned no body that could be decoded.', 'woo-kontor-sync-pro' );
		}

		$encoded = wp_json_encode( $raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return false === $encoded ? __( 'The reply could not be rendered.', 'woo-kontor-sync-pro' ) : $encoded;
	}

	/**
	 * Show which release is installed, and offer to look for a newer one.
	 *
	 * The plugin is not on WordPress.org, so the only thing that ever mentions a new
	 * release is core's twice-daily update check — and it caches the answer, so a
	 * release published in between is invisible for hours with nothing on screen to
	 * say so. This is the way to ask now.
	 *
	 * Hidden from anyone who cannot install a plugin: the answer would be of no use
	 * to them, and the button beneath it does nothing they are allowed to follow up on.
	 *
	 * @return void
	 */
	protected function render_updates_section() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = Updater::status();
		?>
		<h2><?php echo esc_html__( 'Updates', 'woo-kontor-sync-pro' ); ?></h2>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Installed version', 'woo-kontor-sync-pro' ); ?></th>
					<td><?php echo esc_html( WKSYNC_VERSION ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Latest release', 'woo-kontor-sync-pro' ); ?></th>
					<td>
						<?php if ( 'available' === $status['state'] ) : ?>
							<strong><?php echo esc_html( $status['version'] ); ?></strong>
							&mdash;
							<a href="<?php echo esc_url( self_admin_url( 'plugins.php' ) ); ?>">
								<?php echo esc_html__( 'install it from the plugins screen', 'woo-kontor-sync-pro' ); ?>
							</a>
						<?php elseif ( 'current' === $status['state'] ) : ?>
							<?php echo esc_html__( 'This is the newest release.', 'woo-kontor-sync-pro' ); ?>
						<?php else : ?>
							<?php echo esc_html__( 'Not known. Nothing has checked yet, or the last check could not reach GitHub.', 'woo-kontor-sync-pro' ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<form class="wksync-update-check" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="wksync_check_updates"/>
			<?php wp_nonce_field( 'wksync_check_updates' ); ?>
			<button type="submit" class="button"><?php echo esc_html__( 'Check for updates', 'woo-kontor-sync-pro' ); ?></button>
		</form>
		<p class="description">
			<?php echo esc_html__( 'WordPress looks for plugin updates about twice a day and reuses that answer in between, so a release published since the last look does not appear on its own. This discards what was cached and asks again.', 'woo-kontor-sync-pro' ); ?>
		</p>
		<?php
	}

	/**
	 * Report what pressing "Check for updates" found.
	 *
	 * @return void
	 */
	protected function render_update_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; the check itself was nonce-checked.
		$state = isset( $_GET['wksync_update'] ) ? sanitize_key( wp_unslash( $_GET['wksync_update'] ) ) : '';

		if ( '' === $state ) {
			return;
		}

		if ( 'available' === $state ) {
			$status = Updater::status();

			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: version number of the release that was found. */
						__( 'Version %s is available. Install it from the plugins screen.', 'woo-kontor-sync-pro' ),
						$status['version']
					)
				)
			);

			return;
		}

		if ( 'current' === $state ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'This is the newest release.', 'woo-kontor-sync-pro' )
			);

			return;
		}

		/*
		 * Anything else is a check that could not be made. WordPress asks its own API
		 * first and abandons the whole check if that fails, so this covers WordPress.org
		 * being unreachable as well as GitHub — and either way the honest answer is
		 * that nobody could be asked, not that the plugin is up to date.
		 */
		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			esc_html__( 'The release could not be checked. GitHub or WordPress.org could not be reached; try again in a moment.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * The label to show for a stored manufacturer selection.
	 *
	 * Falls back to the ID when no name was stored alongside it, so a selection made
	 * before the names existed still renders as something rather than as a blank row.
	 *
	 * @param array  $settings        Current settings.
	 * @param string $manufacturer_id Manufacturer ID to label.
	 * @return string Display label.
	 */
	protected static function manufacturer_label( array $settings, $manufacturer_id ) {
		$names = isset( $settings['manufacturer_names'] ) ? (array) $settings['manufacturer_names'] : array();

		return isset( $names[ $manufacturer_id ] ) && '' !== $names[ $manufacturer_id ]
			? (string) $names[ $manufacturer_id ]
			: (string) $manufacturer_id;
	}

	/**
	 * Render one manufacturer checkbox.
	 *
	 * Every row rendered here is ticked: the server only ever draws the current
	 * selection, and the unticked rows are added by the script once the full list has
	 * been fetched.
	 *
	 * The ID is shown beside the name because the ID is what is actually sent, and
	 * two manufacturers can share a name.
	 *
	 * @param string $manufacturer_id Manufacturer ID.
	 * @param string $label           Display name.
	 * @return void
	 */
	protected function render_manufacturer_choice( $manufacturer_id, $label ) {
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_KEY ); ?>[manufacturer_ids][]"
				value="<?php echo esc_attr( $manufacturer_id ); ?>"
				checked
			/>
			<span class="wksync-choice-label"><?php echo esc_html( $label ); ?></span>
			<span class="wksync-choice-id"><?php echo esc_html( $manufacturer_id ); ?></span>
		</label>
		<?php
	}

	/**
	 * Describe the current manufacturer selection in one line.
	 *
	 * The count is passed separately from what is printed so the script can be handed
	 * the translated sentence with its placeholder intact: only the browser knows how
	 * many boxes are ticked, but only PHP has the translations.
	 *
	 * @param int         $count   Number of manufacturers selected, which picks the plural form.
	 * @param string|null $display What to print in place of the count; null formats the count itself.
	 * @return string Human-readable summary.
	 */
	protected static function manufacturer_summary( $count, $display = null ) {
		if ( $count < 1 ) {
			return __( 'No manufacturers selected, so the whole catalogue is imported.', 'woo-kontor-sync-pro' );
		}

		return sprintf(
			/* translators: %s: number of manufacturers selected. */
			_n(
				'%s manufacturer selected. Everything else is left out of the import.',
				'%s manufacturers selected. Everything else is left out of the import.',
				$count,
				'woo-kontor-sync-pro'
			),
			null === $display ? number_format_i18n( $count ) : $display
		);
	}

	/**
	 * Render a select for one of the interval settings.
	 *
	 * @param string $id      Element ID.
	 * @param string $key     Setting name.
	 * @param array  $choices Allowed intervals, keyed by seconds.
	 * @param int    $current Currently stored interval.
	 * @return void
	 */
	protected function render_interval_select( $id, $key, array $choices, $current ) {
		?>
		<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>">
			<?php foreach ( $choices as $seconds => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $seconds ); ?>" <?php selected( $current, $seconds ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * Render the job status table with its manual triggers.
	 *
	 * @return void
	 */
	protected function render_jobs_table() {
		$images = Scheduler::pending_count( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );
		$orders = self::orders_enabled();
		?>
		<table class="widefat striped" id="wksync-jobs">
			<thead>
				<tr>
					<th scope="col"><?php echo esc_html__( 'Job', 'woo-kontor-sync-pro' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Next run', 'woo-kontor-sync-pro' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Last result', 'woo-kontor-sync-pro' ); ?></th>
					<th scope="col"><?php echo esc_html__( 'Actions', 'woo-kontor-sync-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( Scheduler::get_jobs() as $key => $job ) : ?>
					<?php
					/*
					 * The order-side jobs are listed only when the shop wants them. A row
					 * whose Run now can do nothing but refuse is worse than no row.
					 */
					if ( ! empty( $job['needs_shop'] ) && ! $orders ) {
						continue;
					}

					$status   = Status::get( $key );
					$next_run = Scheduler::next_run( $key );
					$percent  = Status::percentage( $status );
					$running  = 'running' === $status['state'];
					?>
					<tr data-wksync-job="<?php echo esc_attr( $key ); ?>">
						<td>
							<strong><?php echo esc_html( $job['label'] ); ?></strong>
							<p class="description"><?php echo esc_html( $job['description'] ); ?></p>
							<?php if ( ProductSync::JOB === $key ) : ?>
								<p class="description wksync-image-queue" <?php echo $images > 0 ? '' : 'hidden'; ?>>
									<?php echo esc_html( $this->describe_image_queue( $images ) ); ?>
								</p>
							<?php endif; ?>
						</td>
						<td class="wksync-next-run">
							<?php echo esc_html( $this->describe_next_run( $next_run ) ); ?>
						</td>
						<td>
							<span class="wksync-summary"><?php echo esc_html( $this->describe_status( $status ) ); ?></span>
							<?php
							/*
							 * A bar only where there is something to measure against. A run with
							 * no total — the order sweep before it has counted, or a job that
							 * finished — gets no bar rather than an empty one implying zero
							 * progress, and the element is left in place so the poll can fill it
							 * in without rebuilding the row.
							 */
							?>
							<span class="wksync-progress" <?php echo null === $percent && ! $running ? 'hidden' : ''; ?>>
								<progress
									class="wksync-progress-bar"
									max="100"
									<?php echo null === $percent ? '' : 'value="' . esc_attr( (string) $percent ) . '"'; ?>
								></progress>
								<span class="wksync-position"><?php echo esc_html( $this->describe_position( $status ) ); ?></span>
							</span>
						</td>
						<td>
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
								<input type="hidden" name="action" value="wksync_run_job"/>
								<input type="hidden" name="job" value="<?php echo esc_attr( $key ); ?>"/>
								<?php wp_nonce_field( 'wksync_run_job_' . $key ); ?>
								<button type="submit" class="button"><?php echo esc_html__( 'Run now', 'woo-kontor-sync-pro' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_held_products();
	}

	/**
	 * Point at the products the syncs are holding back, when there are any.
	 *
	 * The run summary above already says how many were held back and why; this is the
	 * only thing on the screen that says which. Rendered under the table rather than in
	 * the product sync's row because more than one job can hold a product back, and a
	 * sentence sitting in one job's row would be claiming all of them for it.
	 *
	 * Gated on edit_products rather than this screen's own capability: a role able to
	 * run every sync here is not necessarily one able to open a product.
	 *
	 * @return void
	 */
	protected function render_held_products() {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}

		$held = HeldProducts::total();

		if ( $held < 1 ) {
			return;
		}

		printf(
			'<p class="description">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html(
				sprintf(
					/* translators: %s: number of products. */
					_n(
						'%s product is currently held back as a draft.',
						'%s products are currently held back as drafts.',
						$held,
						'woo-kontor-sync-pro'
					),
					number_format_i18n( $held )
				)
			),
			esc_url( HeldProducts::url() ),
			esc_html__( 'Show them, with the reason for each.', 'woo-kontor-sync-pro' )
		);
	}

	/**
	 * Summarise a job's last run in one line.
	 *
	 * @param array $status Status array from Status::get().
	 * @return string Human-readable summary.
	 */
	protected function describe_status( array $status ) {
		if ( 'never' === $status['state'] ) {
			return __( 'Never run', 'woo-kontor-sync-pro' );
		}

		if ( 'running' === $status['state'] ) {
			/* translators: %s: how long ago the run started. */
			return sprintf( __( 'Running, started %s ago', 'woo-kontor-sync-pro' ), human_time_diff( $status['started'] ) );
		}

		$when = $status['finished'] > 0 ? wp_date( 'Y-m-d H:i', $status['finished'] ) : '';

		if ( 'failed' === $status['state'] ) {
			/* translators: 1: timestamp, 2: failure reason. */
			return sprintf( __( 'Failed at %1$s — %2$s', 'woo-kontor-sync-pro' ), $when, $status['message'] );
		}

		/* translators: 1: timestamp, 2: run summary. */
		return sprintf( __( 'Succeeded at %1$s — %2$s', 'woo-kontor-sync-pro' ), $when, $status['message'] );
	}

	/**
	 * Say where a running job has got to, in records rather than as a percentage.
	 *
	 * The bar shows the proportion; this says what it is a proportion of, which is the
	 * part that tells somebody whether to wait or come back tomorrow.
	 *
	 * @param array $status Status array from Status::get().
	 * @return string Position, or an empty string when there is nothing to say.
	 */
	protected function describe_position( array $status ) {
		if ( 'running' !== $status['state'] ) {
			return '';
		}

		$total = isset( $status['total'] ) ? (int) $status['total'] : 0;

		// Counted but empty, or not yet counted: the run is busy and that is all anyone
		// can honestly be told.
		if ( $total < 1 ) {
			return __( 'Working…', 'woo-kontor-sync-pro' );
		}

		$processed = isset( $status['processed'] ) ? (int) $status['processed'] : 0;

		return sprintf(
			/* translators: 1: records handled so far, 2: records in total. */
			__( '%1$s of %2$s', 'woo-kontor-sync-pro' ),
			number_format_i18n( min( $processed, $total ) ),
			number_format_i18n( $total )
		);
	}

	/**
	 * Say how many product images are still waiting to be downloaded.
	 *
	 * Deliberately not folded into the product sync's own bar. The downloads outlive
	 * the run that queued them — the catalogue is correct and the job has already
	 * reported success while pictures are still arriving — so counting them as part of
	 * it would hold the bar short of the end for something that is not holding
	 * anything up.
	 *
	 * @param int $pending Actions still queued.
	 * @return string Description, or an empty string when the queue is empty.
	 */
	protected function describe_image_queue( $pending ) {
		$pending = (int) $pending;

		if ( $pending < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: %s: number of products whose images are still queued. */
			_n(
				'Images for %s product still to download.',
				'Images for %s products still to download.',
				$pending,
				'woo-kontor-sync-pro'
			),
			number_format_i18n( $pending )
		);
	}

	/**
	 * When a job is next due.
	 *
	 * @param int $next_run Timestamp, or 0 when nothing is scheduled.
	 * @return string Human-readable time.
	 */
	protected function describe_next_run( $next_run ) {
		return (int) $next_run > 0
			? wp_date( 'Y-m-d H:i', (int) $next_run )
			: __( 'Not scheduled', 'woo-kontor-sync-pro' );
	}

	/**
	 * Show a notice after a manual trigger.
	 *
	 * @return void
	 */
	protected function render_queued_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; the action itself was nonce-checked.
		$queued = isset( $_GET['wksync_queued'] ) ? sanitize_key( wp_unslash( $_GET['wksync_queued'] ) ) : '';

		if ( '' === $queued ) {
			return;
		}

		$jobs = Scheduler::get_jobs();

		if ( ! isset( $jobs[ $queued ] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag set by our own redirect; the action itself was nonce-checked.
			$reason   = isset( $_GET['wksync_reason'] ) ? sanitize_key( wp_unslash( $_GET['wksync_reason'] ) ) : '';
			$messages = $this->refusal_messages();

			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					isset( $messages[ $reason ] )
						? $messages[ $reason ]
						: __( 'The job could not be queued. It may already be running, or WooCommerce may be inactive.', 'woo-kontor-sync-pro' )
				)
			);

			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: job name. */
					__( '%s queued. It runs on the next queue pass, within a minute or so.', 'woo-kontor-sync-pro' ),
					$jobs[ $queued ]['label']
				)
			)
		);
	}
}
