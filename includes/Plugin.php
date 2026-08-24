<?php
/**
 * Plugin bootstrap.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync;

use WooKontorSync\Admin\HeldProducts;
use WooKontorSync\Admin\OrderActions;
use WooKontorSync\Admin\OrderPanel;
use WooKontorSync\Admin\ProductFields;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Emails\Emails;
use WooKontorSync\Frontend\Invoices;
use WooKontorSync\Frontend\ProductMeta;
use WooKontorSync\Frontend\Quantities;
use WooKontorSync\Frontend\Tracking;
use WooKontorSync\Invoices\Download;
use WooKontorSync\Orders\PartialStatus;
use WooKontorSync\Rest\Jobs as RestJobs;
use WooKontorSync\Rest\Products as RestProducts;
use WooKontorSync\Sync\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's components into WordPress.
 */
final class Plugin {

	/**
	 * The plugin's text domain.
	 *
	 * @var string
	 */
	const TEXT_DOMAIN = 'woo-kontor-sync-pro';

	/**
	 * The German catalogues that ship with the plugin.
	 *
	 * WordPress treats every German locale as unrelated to the others and never falls
	 * back between them, so `de_AT` finds no catalogue and shows English however
	 * complete the German translation is. These two are the ones that are actually
	 * maintained; map_german_locale() points the rest at whichever matches their
	 * register.
	 *
	 * @var string
	 */
	const GERMAN_INFORMAL = 'de_DE';
	const GERMAN_FORMAL   = 'de_DE_formal';

	/**
	 * The single shared instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Whether init() has already registered the plugin's hooks.
	 *
	 * @var bool
	 */
	private $initialised = false;

	/**
	 * Use instance() instead.
	 */
	private function __construct() {}

	/**
	 * Retrieve the shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register the plugin's hooks. Safe to call more than once.
	 *
	 * @return void
	 */
	public function init() {
		if ( $this->initialised ) {
			return;
		}

		$this->initialised = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_filter( 'load_textdomain_mofile', array( $this, 'map_german_locale' ), 10, 2 );

		( new Scheduler() )->register();

		// The status Kontor's "partially completed" maps onto. Registered everywhere,
		// because an order can only be moved into a status WooCommerce knows about and
		// the delivery sync moves orders from a background job.
		( new PartialStatus() )->register();

		// Not gated on is_admin(): order emails render wherever the status changed,
		// including inside the background job the delivery sync runs in.
		( new Tracking() )->register();
		( new Invoices() )->register();

		// The two mails that tell a customer something arrived from Kontor. Outside the
		// admin check twice over: the syncs that fire them run in Action Scheduler, and
		// the classes have to exist in the admin or the Emails screen cannot list them.
		( new Emails() )->register();

		// Holds the cart to the quantities Kontor sells each article in. Registered
		// everywhere: a Store API cart request is neither an admin screen nor a
		// template render, and it is the path the cart and checkout blocks take.
		( new Quantities() )->register();

		// Kontor's retail price and EAN in the product page's meta block. A template
		// render is not an admin screen, so this cannot go behind the check below
		// either.
		( new ProductMeta() )->register();

		// Serves invoice PDFs to whoever is entitled to them, which includes guests
		// holding an order key, so it cannot live behind the admin check either.
		( new Download() )->register();

		// Adds the recommended retail price to /wc/v3/products. Registered here rather
		// than behind is_admin(), because a REST request is neither.
		( new RestProducts() )->register();

		// Starts the product and stock syncs, and reports on a run, for callers with no
		// browser to press Run now in. Not behind the admin check for the same reason.
		( new RestJobs() )->register();

		if ( is_admin() ) {
			( new Settings() )->register();

			// Kontor's sales quantities on the product's Inventory tab. The meta keys
			// are protected, so without this there is nowhere to see them at all.
			( new ProductFields() )->register();

			// The products the syncs have taken out of the shop, on the products list.
			// The markers are protected meta, so without this a shop manager reading
			// "Held 827 back as drafts" has no way to find out which 827.
			( new HeldProducts() )->register();

			// Everything Kontor knows about an order, on the order screen. Same
			// reasoning: the meta keys are protected, so without this there is nowhere
			// in wp-admin to read any of it — and no way to reach an invoice at all.
			( new OrderPanel() )->register();

			// The two Kontor entries in the order actions dropdown. Their own class
			// rather than OrderPanel's, because these change something and that one
			// deliberately cannot.
			( new OrderActions() )->register();
		}

		/**
		 * Fires once the plugin has registered its own hooks.
		 *
		 * @since 0.1.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'woo_kontor_sync_loaded', $this );
	}

	/**
	 * Load the plugin translations.
	 *
	 * Hooked to `init` because loading a text domain earlier is deprecated as of
	 * WordPress 6.7.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( WKSYNC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Serve the German catalogues to every German locale.
	 *
	 * The plugin ships `de_DE` and `de_DE_formal`. A shop set to `de_AT`, `de_CH` or
	 * `de_CH_informal` asks for a catalogue that does not exist and silently falls back
	 * to English — WordPress has no notion of one German locale being close to another.
	 * This points those requests at the catalogue matching their register instead, which
	 * is far better than an English admin screen for an Austrian shop.
	 *
	 * Filtering the `.mo` path is enough to bring the `.l10n.php` along: WordPress
	 * derives that filename from whatever this returns. A locale that does have its own
	 * catalogue — including one a site owner dropped into `wp-content/languages/plugins`
	 * — is left alone.
	 *
	 * @param string $mofile Path to the catalogue WordPress is about to load.
	 * @param string $domain Text domain being loaded.
	 * @return string
	 */
	public function map_german_locale( $mofile, $domain ) {
		if ( self::TEXT_DOMAIN !== $domain || file_exists( $mofile ) ) {
			return $mofile;
		}

		if ( ! preg_match( '/-(de(?:_[A-Za-z]+)*)\.mo$/', basename( $mofile ), $matches ) ) {
			return $mofile;
		}

		$locale   = $matches[1];
		$register = str_ends_with( $locale, '_formal' ) ? self::GERMAN_FORMAL : self::GERMAN_INFORMAL;
		$fallback = dirname( $mofile ) . '/' . self::TEXT_DOMAIN . '-' . $register . '.mo';

		return file_exists( $fallback ) ? $fallback : $mofile;
	}
}
