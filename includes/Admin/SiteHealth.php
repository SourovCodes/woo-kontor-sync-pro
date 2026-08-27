<?php
/**
 * The plugin's Site Health tests.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Answers "is the Kontor sync working" on the one screen built to ask that of everything.
 *
 * Site Health is where somebody looks when a site is behaving oddly and they do not yet
 * know which plugin is at fault, which is exactly the position a shop is in when its
 * catalogue has quietly stopped updating. It is also the surface a host or an agency
 * checks on a site they did not set up.
 *
 * **Both tests are direct, and neither touches the network.** A direct test runs while
 * the page renders, so it has to be cheap; asking Kontor whether the key still works
 * would put a round trip to somebody else's server in the middle of a page load, and
 * the answer is one the jobs themselves already record. Site Health offers async tests
 * for the other kind, and nothing here needs one.
 */
class SiteHealth {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'site_status_tests', array( $this, 'add_tests' ) );
	}

	/**
	 * Add the two tests.
	 *
	 * @param array $tests Tests registered so far.
	 * @return array Tests, with ours added.
	 */
	public function add_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['wksync_configuration'] = array(
			'label' => __( 'Kontor Sync configuration', 'woo-kontor-sync-pro' ),
			'test'  => array( $this, 'test_configuration' ),
		);

		$tests['direct']['wksync_jobs'] = array(
			'label' => __( 'Kontor Sync jobs', 'woo-kontor-sync-pro' ),
			'test'  => array( $this, 'test_jobs' ),
		);

		return $tests;
	}

	/**
	 * Whether the plugin has been told enough to do anything.
	 *
	 * Reported as **recommended** rather than critical, however incomplete it is. An
	 * unconfigured plugin is a site where somebody has not finished, not a site that is
	 * broken — and Site Health's critical section is meant for the things that are.
	 *
	 * @return array Site Health result.
	 */
	public function test_configuration() {
		$settings = Settings::get_settings();
		$missing  = array();

		if ( '' === trim( (string) $settings['api_base_url'] ) ) {
			$missing[] = __( 'the API base URL', 'woo-kontor-sync-pro' );
		}

		if ( '' === trim( (string) $settings['api_key'] ) ) {
			$missing[] = __( 'the API key', 'woo-kontor-sync-pro' );
		}

		/*
		 * A shop is only short of a shop ID if something it has switched on needs one.
		 * Plenty of shops run this plugin for the catalogue alone, and for one of those
		 * an empty shop field is the correct setting rather than an omission.
		 */
		$needs_shop = Settings::orders_enabled( $settings ) || ! empty( $settings[ Settings::SYNC_CATEGORIES ] );

		if ( $needs_shop && ! Settings::is_shop_id( trim( (string) $settings['shop_id'] ) ) ) {
			$missing[] = __( 'the Kontor shop', 'woo-kontor-sync-pro' );
		}

		if ( empty( $missing ) ) {
			return $this->result(
				'wksync_configuration',
				'good',
				__( 'Kontor Sync is configured', 'woo-kontor-sync-pro' ),
				__( 'The API base URL and key are set, and every feature that needs a Kontor shop has one.', 'woo-kontor-sync-pro' )
			);
		}

		return $this->result(
			'wksync_configuration',
			'recommended',
			__( 'Kontor Sync is not fully configured', 'woo-kontor-sync-pro' ),
			sprintf(
				/* translators: %s: a list of settings, for example "the API key and the Kontor shop". */
				__( 'Nothing will sync until these are filled in: %s.', 'woo-kontor-sync-pro' ),
				// A literal separator rather than a translated one: a catalogue entry
				// holding nothing but punctuation is a thing translators cannot act on.
				implode( ', ', $missing )
			)
		);
	}

	/**
	 * Whether the jobs are running and finishing.
	 *
	 * **Critical**, unlike the test above, and deliberately: a job failing or missing
	 * from the queue means a shop is showing customers a catalogue, prices or stock
	 * levels that are no longer true, and sending nothing to the warehouse. That is a
	 * site actively doing the wrong thing rather than one waiting to be finished.
	 *
	 * @return array Site Health result.
	 */
	public function test_jobs() {
		$problems = Health::problems();

		if ( empty( $problems ) ) {
			return $this->result(
				'wksync_jobs',
				'good',
				__( 'The Kontor syncs are running', 'woo-kontor-sync-pro' ),
				__( 'Every job with an interval is queued, and none of them reported a failure on its last run.', 'woo-kontor-sync-pro' )
			);
		}

		$lines = array();

		foreach ( $problems as $problem ) {
			$lines[] = sprintf( '<li>%1$s — %2$s</li>', esc_html( $problem['label'] ), esc_html( $problem['message'] ) );
		}

		return $this->result(
			'wksync_jobs',
			'critical',
			__( 'A Kontor sync is not working', 'woo-kontor-sync-pro' ),
			sprintf(
				'<p>%1$s</p><ul>%2$s</ul>',
				esc_html__( 'These jobs need attention. Until they run, the shop is working from whatever Kontor last told it.', 'woo-kontor-sync-pro' ),
				implode( '', $lines )
			)
		);
	}

	/**
	 * Assemble one result in the shape Site Health expects.
	 *
	 * Every result carries the same badge and the same two links, so the section is
	 * recognisable as one plugin's rather than as a scattering of separate findings.
	 *
	 * @param string $test        Test identifier.
	 * @param string $status      One of good, recommended or critical.
	 * @param string $label       Headline.
	 * @param string $description Body, which may contain markup.
	 * @return array Site Health result.
	 */
	protected function result( $test, $status, $label, $description ) {
		$body = 0 === strpos( $description, '<' ) ? $description : sprintf( '<p>%s</p>', esc_html( $description ) );

		return array(
			'test'        => $test,
			'status'      => $status,
			'label'       => $label,
			'badge'       => array(
				'label' => __( 'Kontor Sync', 'woo-kontor-sync-pro' ),
				'color' => 'blue',
			),
			'description' => $body,
			'actions'     => sprintf(
				'<p><a href="%1$s">%2$s</a> &nbsp;|&nbsp; <a href="%3$s">%4$s</a></p>',
				esc_url( Health::settings_url() ),
				esc_html__( 'Open Kontor Sync', 'woo-kontor-sync-pro' ),
				esc_url( Health::log_url() ),
				esc_html__( 'View the log', 'woo-kontor-sync-pro' )
			),
		);
	}
}
