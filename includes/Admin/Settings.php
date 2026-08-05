<?php
/**
 * Admin settings screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Api\Client;
use WooKontorSync\Sync\OrderSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;

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
	 * Interval value meaning "do not schedule this job at all".
	 *
	 * This is the default for both jobs: a fresh install has no API key, so nothing
	 * should start reaching out to Kontor, or rewriting the catalogue, until it has
	 * been configured deliberately. Manual "Run now" still works.
	 */
	const INTERVAL_NEVER = 0;

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
			'api_base_url'           => 'https://sp3api.kontor-crm.de/api/v1/kontor',
			'api_key'                => '',
			'shoptype'               => 'B2B',
			'shop_id'                => '',
			'shop_name'              => '',
			'image_base_url'         => '',
			'product_sync_interval'  => self::INTERVAL_NEVER,
			'stock_sync_interval'    => self::INTERVAL_NEVER,
			'order_sync_interval'    => self::INTERVAL_NEVER,
			'delivery_sync_interval' => self::INTERVAL_NEVER,
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
		add_action( 'admin_post_wksync_run_job', array( $this, 'handle_run_job' ) );
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
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wksync_test_connection' ),
				'testing'       => __( 'Testing connection…', 'woo-kontor-sync-pro' ),
				'failed'        => __( 'The connection test could not be completed.', 'woo-kontor-sync-pro' ),
				'shopsNonce'    => wp_create_nonce( 'wksync_fetch_shops' ),
				'fetchingShops' => __( 'Fetching shops…', 'woo-kontor-sync-pro' ),
				'shopsFailed'   => __( 'The shop list could not be fetched.', 'woo-kontor-sync-pro' ),
				'noShop'        => __( '— No shop selected —', 'woo-kontor-sync-pro' ),
				'unsavedShop'   => __( 'Shops loaded. Choose one, then save the settings.', 'woo-kontor-sync-pro' ),
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

		return array(
			'api_base_url'           => isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '',
			'api_key'                => '' === $submitted_key ? $existing['api_key'] : $submitted_key,
			'shoptype'               => array_key_exists( $shoptype, self::shoptypes() ) ? $shoptype : $existing['shoptype'],
			'shop_id'                => $shop['shop_id'],
			'shop_name'              => $shop['shop_name'],
			'image_base_url'         => isset( $input['image_base_url'] ) ? esc_url_raw( trim( $input['image_base_url'] ) ) : '',
			'product_sync_interval'  => $this->pick_interval( $input, 'product_sync_interval', self::product_sync_intervals(), $existing ),
			'stock_sync_interval'    => $this->pick_interval( $input, 'stock_sync_interval', self::stock_sync_intervals(), $existing ),
			'order_sync_interval'    => $this->pick_interval( $input, 'order_sync_interval', self::order_sync_intervals(), $existing ),
			'delivery_sync_interval' => $this->pick_interval( $input, 'delivery_sync_interval', self::delivery_sync_intervals(), $existing ),
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

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Kontor Sync', 'woo-kontor-sync-pro' ); ?></h1>

			<?php $this->render_queued_notice(); ?>

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
						</td>
					</tr>
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
								<?php echo esc_html__( 'Identifies this store in Kontor when orders are sent and delivery information is fetched back. Neither job runs without it. Product and stock sync do not use it.', 'woo-kontor-sync-pro' ); ?>
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
				</table>

				<h2><?php echo esc_html__( 'Schedules', 'woo-kontor-sync-pro' ); ?></h2>
				<table class="form-table" role="presentation">
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
							<p class="description"><?php echo esc_html__( 'Orders are sent to Kontor as they are paid. This sweep only catches ones that were missed, and needs a shop selected.', 'woo-kontor-sync-pro' ); ?></p>
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
							<p class="description"><?php echo esc_html__( 'Pulls tracking details back from Kontor and needs a shop selected. An order Kontor reports as completed is completed here too, which emails the customer.', 'woo-kontor-sync-pro' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Scheduled jobs', 'woo-kontor-sync-pro' ); ?></h2>
			<?php $this->render_jobs_table(); ?>
		</div>
		<?php
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
		?>
		<table class="widefat striped">
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
					$status   = Status::get( $key );
					$next_run = Scheduler::next_run( $key );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $job['label'] ); ?></strong>
							<p class="description"><?php echo esc_html( $job['description'] ); ?></p>
						</td>
						<td>
							<?php
							echo esc_html(
								$next_run > 0
									? wp_date( 'Y-m-d H:i', $next_run )
									: __( 'Not scheduled', 'woo-kontor-sync-pro' )
							);
							?>
						</td>
						<td><?php echo esc_html( $this->describe_status( $status ) ); ?></td>
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
