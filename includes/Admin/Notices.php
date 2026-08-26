<?php
/**
 * The admin notice that says a sync is broken.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Tells a shop manager that a sync has stopped working, away from the settings screen.
 *
 * Every other surface in this plugin has to be visited. A shop whose product sync had
 * failed every night for a week looked entirely normal from the dashboard, the orders
 * list and the products list, and the only place saying otherwise was one line on a
 * screen nobody opens while things are working. This is the one thing here that goes
 * looking for the reader.
 *
 * Shown on WooCommerce's own screens, the dashboard and the plugins screen — where a
 * shop manager already is — and never on the Kontor Sync screen itself, which says all
 * of this in more detail a few lines further down the page.
 */
class Notices {

	/**
	 * User meta holding the failures this user has dismissed.
	 */
	const DISMISSED_META = '_wksync_dismissed_problems';

	/**
	 * Admin-post action behind the dismiss link.
	 */
	const ACTION_DISMISS = 'wksync_dismiss_notice';

	/**
	 * How many dismissals to remember.
	 *
	 * Enough that dismissing the same handful of problems repeatedly cannot lose the
	 * oldest of them, and small enough that the meta stays a line rather than a log.
	 */
	const DISMISSED_LIMIT = 20;

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Print the notice, when there is something to say and somewhere to say it.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( Settings::CAPABILITY ) || ! $this->wanted_here() ) {
			return;
		}

		/*
		 * The schedule half of the answer is read from the cache. This runs on every
		 * admin page load, and asking the queue whether each job's action repeats means
		 * fetching those actions and inspecting them, which is not a thing to do on a
		 * page that only wants to know whether to print a sentence.
		 */
		$problems = Health::problems( true );

		if ( empty( $problems ) ) {
			return;
		}

		$dismissed = $this->dismissed();
		$showing   = array();

		foreach ( $problems as $problem ) {
			if ( ! in_array( $this->fingerprint( $problem ), $dismissed, true ) ) {
				$showing[] = $problem;
			}
		}

		if ( empty( $showing ) ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php echo esc_html__( 'Kontor Sync needs attention.', 'woo-kontor-sync-pro' ); ?></strong>
			</p>
			<ul style="list-style: disc; margin-left: 2em;">
				<?php foreach ( $showing as $problem ) : ?>
					<li><?php echo esc_html( $this->describe( $problem ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<p>
				<a href="<?php echo esc_url( Health::settings_url() ); ?>">
					<?php echo esc_html__( 'Open Kontor Sync', 'woo-kontor-sync-pro' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( Health::log_url() ); ?>">
					<?php echo esc_html__( 'View the log', 'woo-kontor-sync-pro' ); ?>
				</a>
				&nbsp;|&nbsp;
				<a href="<?php echo esc_url( $this->dismiss_url( $showing ) ); ?>">
					<?php echo esc_html__( 'Dismiss', 'woo-kontor-sync-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * One problem, in a sentence.
	 *
	 * @param array $problem Problem from Health::problems().
	 * @return string Human-readable description.
	 */
	protected function describe( array $problem ) {
		switch ( $problem['kind'] ) {
			case Health::UNSCHEDULED:
				return sprintf(
					/* translators: %s: job name. */
					__( '%s has an interval set but nothing queued to run it, so it is not running at all.', 'woo-kontor-sync-pro' ),
					$problem['label']
				);

			case Health::STALE:
				return sprintf(
					/* translators: %s: job name. */
					__( '%s stopped part-way through and nothing recorded why.', 'woo-kontor-sync-pro' ),
					$problem['label']
				);

			default:
				return sprintf(
					/* translators: 1: job name, 2: the reason it failed. */
					__( '%1$s failed: %2$s', 'woo-kontor-sync-pro' ),
					$problem['label'],
					$problem['message']
				);
		}
	}

	/**
	 * Whether this screen is one to interrupt.
	 *
	 * WooCommerce's own screens, the dashboard and the plugins screen. Not every admin
	 * page: a notice on the post editor is a notice in the way of somebody doing
	 * something else, and this one is not urgent enough to earn that.
	 *
	 * The Kontor Sync screen is excluded because it says all of this a few lines
	 * further down, with the run summary and a Run now button beside it.
	 *
	 * @return bool True when the notice belongs on this screen.
	 */
	protected function wanted_here() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		if ( false !== strpos( $screen->id, Settings::PAGE_SLUG ) ) {
			return false;
		}

		$screens = array( 'dashboard', 'plugins' );

		if ( function_exists( 'wc_get_screen_ids' ) ) {
			$screens = array_merge( $screens, (array) wc_get_screen_ids() );
		}

		return in_array( $screen->id, $screens, true );
	}

	/**
	 * What identifies one problem for the purpose of dismissing it.
	 *
	 * The job and the reason, never the time. A failing job records a new finish time
	 * every run — every fifteen minutes, on the stock sync — so a fingerprint carrying
	 * one would change before the reader had finished reading it and the notice would
	 * be back on the next page load. Keyed on the reason instead, dismissing means "I
	 * know about this one", and a different failure is a different notice.
	 *
	 * @param array $problem Problem from Health::problems().
	 * @return string Fingerprint.
	 */
	protected function fingerprint( array $problem ) {
		return md5( $problem['job'] . '|' . $problem['kind'] . '|' . $problem['message'] );
	}

	/**
	 * The fingerprints this user has dismissed.
	 *
	 * Per user rather than per site: one person deciding they know about a failure is
	 * not everybody deciding it, and a shop manager who has never seen the notice
	 * should still be told.
	 *
	 * @return string[] Fingerprints.
	 */
	protected function dismissed() {
		$stored = get_user_meta( get_current_user_id(), self::DISMISSED_META, true );

		return is_array( $stored ) ? array_map( 'strval', $stored ) : array();
	}

	/**
	 * The link that dismisses the problems currently on screen.
	 *
	 * The fingerprints travel in the URL rather than being recomputed on arrival, so
	 * pressing Dismiss puts away what was read and not whatever the state has become
	 * in the meantime.
	 *
	 * @param array $problems Problems being shown.
	 * @return string Admin-post URL.
	 */
	protected function dismiss_url( array $problems ) {
		$keys = array();

		foreach ( $problems as $problem ) {
			$keys[] = $this->fingerprint( $problem );
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => self::ACTION_DISMISS,
					'problems' => implode( ',', $keys ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION_DISMISS
		);
	}

	/**
	 * Record a dismissal and go back where the reader was.
	 *
	 * @return void
	 */
	public function handle_dismiss() {
		check_admin_referer( self::ACTION_DISMISS );

		if ( ! current_user_can( Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'woo-kontor-sync-pro' ) );
		}

		$raw = isset( $_GET['problems'] ) ? sanitize_text_field( wp_unslash( $_GET['problems'] ) ) : '';
		$new = array();

		foreach ( explode( ',', $raw ) as $key ) {
			$key = trim( $key );

			// A fingerprint is an md5 and nothing else is stored, so anything that is not
			// one came from somewhere other than our own link.
			if ( 1 === preg_match( '/^[0-9a-f]{32}$/', $key ) ) {
				$new[] = $key;
			}
		}

		$this->dismiss( $new );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/**
	 * Put these problems away for the current user.
	 *
	 * Apart from the handler above, which authenticates the request and picks the
	 * fingerprints out of it. Kept separate so the work is reachable without a
	 * redirect on the end of it.
	 *
	 * @param string[] $fingerprints Fingerprints to remember as dismissed.
	 * @return void
	 */
	public function dismiss( array $fingerprints ) {
		if ( empty( $fingerprints ) ) {
			return;
		}

		// Newest first, so the cap drops the dismissals nobody has renewed rather than
		// the ones just made.
		$kept = array_slice(
			array_values( array_unique( array_merge( $fingerprints, $this->dismissed() ) ) ),
			0,
			self::DISMISSED_LIMIT
		);

		update_user_meta( get_current_user_id(), self::DISMISSED_META, $kept );
	}
}
