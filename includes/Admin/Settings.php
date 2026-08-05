<?php
/**
 * Admin settings screen.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's settings and its screen under WooCommerce.
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
	 * Capability required to view or change the settings.
	 */
	const CAPABILITY = 'manage_woocommerce';

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

		return wp_parse_args( $stored, self::default_settings() );
	}

	/**
	 * The default settings.
	 *
	 * @return array Default settings array.
	 */
	public static function default_settings() {
		return array(
			'api_base_url' => '',
			'api_token'    => '',
			'timeout'      => 10,
			'sync_enabled' => false,
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
		add_submenu_page(
			'woocommerce',
			__( 'Kontor Sync', 'woo-kontor-sync-pro' ),
			__( 'Kontor Sync', 'woo-kontor-sync-pro' ),
			self::CAPABILITY,
			'woo-kontor-sync',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Sanitise the submitted settings.
	 *
	 * An empty token submission keeps the stored token, so the screen never has to
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

		$submitted_token = isset( $input['api_token'] ) ? trim( sanitize_text_field( $input['api_token'] ) ) : '';

		return array(
			'api_base_url' => isset( $input['api_base_url'] ) ? esc_url_raw( trim( $input['api_base_url'] ) ) : '',
			'api_token'    => '' === $submitted_token ? $existing['api_token'] : $submitted_token,
			'timeout'      => isset( $input['timeout'] ) ? max( 1, min( 60, absint( $input['timeout'] ) ) ) : 10,
			'sync_enabled' => ! empty( $input['sync_enabled'] ),
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
			<form action="options.php" method="post">
				<?php settings_fields( self::OPTION_GROUP ); ?>
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
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-api-token"><?php echo esc_html__( 'API token', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<input
								type="password"
								class="regular-text"
								id="wksync-api-token"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[api_token]"
								value=""
								autocomplete="new-password"
							/>
							<p class="description">
								<?php
								echo esc_html(
									'' === $settings['api_token']
										? __( 'No token stored yet.', 'woo-kontor-sync-pro' )
										: __( 'A token is stored. Leave this field blank to keep it.', 'woo-kontor-sync-pro' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wksync-timeout"><?php echo esc_html__( 'Request timeout (seconds)', 'woo-kontor-sync-pro' ); ?></label>
						</th>
						<td>
							<input
								type="number"
								min="1"
								max="60"
								id="wksync-timeout"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[timeout]"
								value="<?php echo esc_attr( (string) $settings['timeout'] ); ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Synchronisation', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<label>
								<input
									type="checkbox"
									name="<?php echo esc_attr( self::OPTION_KEY ); ?>[sync_enabled]"
									value="1"
									<?php checked( $settings['sync_enabled'] ); ?>
								/>
								<?php echo esc_html__( 'Push orders to Kontor', 'woo-kontor-sync-pro' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
