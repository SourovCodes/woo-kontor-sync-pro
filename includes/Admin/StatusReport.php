<?php
/**
 * The plugin's section of WooCommerce's system status report.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Admin;

use WooKontorSync\Sync\ProductSync;
use WooKontorSync\Sync\Scheduler;
use WooKontorSync\Sync\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Puts how this shop is configured and how its jobs are doing into the status report.
 *
 * WooCommerce → Status → Report is the page a shop manager is asked for when something
 * is wrong, and it has a copy button that turns the whole page into text. Without a
 * section here, supporting a shop from anywhere but in front of it meant asking for
 * screenshots of the Kontor Sync screen — and the questions worth asking are exactly
 * the ones nobody thinks to photograph: which shop type, whether a manufacturer filter
 * is narrowing the catalogue, whether the schedules are actually in the queue.
 *
 * **The API key is never in here**, and neither is anything derived from it. The report
 * is written to be pasted into a support thread, which is the one place a credential
 * must not end up; the key is reported as present or absent and nothing more.
 */
class StatusReport {

	/**
	 * Register the hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'woocommerce_system_status_report', array( $this, 'render' ) );
	}

	/**
	 * Print the section.
	 *
	 * @return void
	 */
	public function render() {
		$settings = Settings::get_settings();
		?>
		<table class="wc_status_table widefat" cellspacing="0">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Kontor Sync">
						<h2><?php echo esc_html__( 'Kontor Sync', 'woo-kontor-sync-pro' ); ?></h2>
					</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $this->configuration( $settings ) as $label => $value ) {
					$this->row( $label, $value );
				}

				foreach ( $this->jobs( $settings ) as $label => $value ) {
					$this->row( $label, $value );
				}
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * How this shop is set up.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Label to value.
	 */
	protected function configuration( array $settings ) {
		$manufacturers = isset( $settings['manufacturer_ids'] ) ? (array) $settings['manufacturer_ids'] : array();

		$rows = array(
			__( 'Version', 'woo-kontor-sync-pro' )         => WKSYNC_VERSION,
			__( 'API base URL', 'woo-kontor-sync-pro' )    => '' === trim( (string) $settings['api_base_url'] )
				? __( 'Not set', 'woo-kontor-sync-pro' )
				: (string) $settings['api_base_url'],

			/*
			 * Present or absent, never the value and never a fragment of it. This report
			 * is written to be pasted somewhere, and the key is a request header.
			 */
			__( 'API key', 'woo-kontor-sync-pro' )         => '' === trim( (string) $settings['api_key'] )
				? __( 'Not set', 'woo-kontor-sync-pro' )
				: __( 'Set', 'woo-kontor-sync-pro' ),
			__( 'Shop type', 'woo-kontor-sync-pro' )       => (string) $settings['shoptype'],
			__( 'Kontor shop', 'woo-kontor-sync-pro' )     => '' === trim( (string) $settings['shop_id'] )
				? __( 'None selected', 'woo-kontor-sync-pro' )
				: sprintf( '%1$s (%2$s)', (string) $settings['shop_name'], (string) $settings['shop_id'] ),
			__( 'Manufacturer filter', 'woo-kontor-sync-pro' ) => empty( $manufacturers )
				? __( 'Whole catalogue', 'woo-kontor-sync-pro' )
				: sprintf(
					/* translators: %d: number of manufacturers chosen. */
					_n( '%d manufacturer', '%d manufacturers', count( $manufacturers ), 'woo-kontor-sync-pro' ),
					count( $manufacturers )
				),
			__( 'Order sync', 'woo-kontor-sync-pro' )      => $this->yes_no( Settings::orders_enabled( $settings ) ),
			__( 'Category import', 'woo-kontor-sync-pro' ) => $this->yes_no( Settings::categories_enabled( $settings ) ),
		);

		// Each of these changes what a run does to the shop, and each is off by default,
		// so a shop reading an unexpected run summary is usually reading one of them.
		$toggles = array(
			Settings::REQUIRE_CATEGORY    => __( 'Require a category', 'woo-kontor-sync-pro' ),
			'require_main_image'          => __( 'Require an image', 'woo-kontor-sync-pro' ),
			Settings::TRASH_UNMANAGED     => __( 'Trash unmanaged products', 'woo-kontor-sync-pro' ),
			Settings::DRAFT_MISSING_STOCK => __( 'Draft articles with no stock record', 'woo-kontor-sync-pro' ),
			Settings::ENFORCE_QUANTITIES  => __( 'Enforce order quantities', 'woo-kontor-sync-pro' ),
		);

		foreach ( $toggles as $key => $label ) {
			$rows[ $label ] = $this->yes_no( ! empty( $settings[ $key ] ) );
		}

		$catalogue = get_option( ProductSync::CATALOGUE_OPTION, array() );

		// What the drafting brake is measuring against. A shop whose runs are being held
		// back reads the reason here rather than inferring it from a failure message.
		$rows[ __( 'Last catalogue size', 'woo-kontor-sync-pro' ) ] = is_array( $catalogue ) && ! empty( $catalogue['size'] )
			? number_format_i18n( (int) $catalogue['size'] )
			: __( 'Not measured yet', 'woo-kontor-sync-pro' );

		$images = Scheduler::pending_count( Scheduler::ACTION_SYNC_PRODUCT_IMAGES );

		$rows[ __( 'Images queued', 'woo-kontor-sync-pro' ) ]      = number_format_i18n( $images );
		$rows[ __( 'Products held back', 'woo-kontor-sync-pro' ) ] = number_format_i18n( HeldProducts::total() );

		if ( Settings::orders_enabled( $settings ) ) {
			$rows[ __( 'Orders no longer being sent', 'woo-kontor-sync-pro' ) ] = number_format_i18n( StuckOrders::total() );
		}

		return $rows;
	}

	/**
	 * Every job, in one row each.
	 *
	 * The interval, whether the queue agrees with it, and how the last run went. The
	 * middle one is the point: a job can read as scheduled everywhere else in wp-admin
	 * while having no recurring action at all, which is a shop that quietly stopped
	 * syncing weeks ago.
	 *
	 * @param array $settings Plugin settings.
	 * @return array Label to value.
	 */
	protected function jobs( array $settings ) {
		$orders      = Settings::orders_enabled( $settings );
		$unscheduled = Health::unscheduled();
		$rows        = array();

		foreach ( Scheduler::get_jobs() as $key => $job ) {
			$status   = Status::get( $key );
			$interval = isset( $settings[ $job['setting'] ] ) ? absint( $settings[ $job['setting'] ] ) : Settings::INTERVAL_NEVER;

			if ( ! empty( $job['needs_shop'] ) && ! $orders ) {
				$rows[ $job['label'] ] = __( 'Off — this shop does not exchange orders with Kontor.', 'woo-kontor-sync-pro' );

				continue;
			}

			$parts = array();

			$parts[] = Settings::INTERVAL_NEVER === $interval
				? __( 'Never', 'woo-kontor-sync-pro' )
				: sprintf(
					/* translators: %s: an interval, for example "15 minutes". */
					__( 'Every %s', 'woo-kontor-sync-pro' ),
					human_time_diff( 0, $interval )
				);

			if ( in_array( $key, $unscheduled, true ) ) {
				$parts[] = __( 'NOT QUEUED', 'woo-kontor-sync-pro' );
			}

			$parts[] = $this->last_run( $key, $status );

			$rows[ $job['label'] ] = implode( ' — ', $parts );
		}

		return $rows;
	}

	/**
	 * How the last run went, in a few words.
	 *
	 * @param string $job    Job key.
	 * @param array  $status Status array from Status::get().
	 * @return string Description.
	 */
	protected function last_run( $job, array $status ) {
		if ( 'never' === $status['state'] ) {
			return __( 'never run', 'woo-kontor-sync-pro' );
		}

		if ( 'running' === $status['state'] ) {
			/*
			 * Past STALE_AFTER the run is not running, it is stranded: the chain behind it
			 * died and nothing closed the status. Reporting it as still going would have
			 * this row disagree with the notice and with Site Health, which both read the
			 * same state through Health and call it what it is.
			 */
			if ( Status::is_running( $job ) ) {
				return sprintf(
					/* translators: %s: how long ago the run started. */
					__( 'running, started %s ago', 'woo-kontor-sync-pro' ),
					human_time_diff( (int) $status['started'] )
				);
			}

			return sprintf(
				/* translators: %s: how long ago the run started. */
				__( 'stranded, started %s ago and never finished', 'woo-kontor-sync-pro' ),
				human_time_diff( (int) $status['started'] )
			);
		}

		return sprintf(
			/* translators: 1: state, either succeeded or failed, 2: timestamp, 3: the run summary. */
			__( '%1$s at %2$s: %3$s', 'woo-kontor-sync-pro' ),
			'failed' === $status['state']
				? __( 'failed', 'woo-kontor-sync-pro' )
				: __( 'succeeded', 'woo-kontor-sync-pro' ),
			wp_date( 'Y-m-d H:i', (int) $status['finished'] ),
			(string) $status['message']
		);
	}

	/**
	 * Print one row in the shape the report's copy button expects.
	 *
	 * The export label is what ends up in the pasted text, so it carries the English
	 * label rather than the translated one: a report is read by whoever is helping,
	 * who is not necessarily reading the same language as the shop.
	 *
	 * @param string $label Row label.
	 * @param string $value Row value.
	 * @return void
	 */
	protected function row( $label, $value ) {
		?>
		<tr>
			<td data-export-label="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $label ); ?>:</td>
			<td class="help">&nbsp;</td>
			<td><?php echo esc_html( $value ); ?></td>
		</tr>
		<?php
	}

	/**
	 * A boolean, spelled out.
	 *
	 * @param bool $value The value.
	 * @return string Yes or No.
	 */
	protected function yes_no( $value ) {
		return $value ? __( 'Yes', 'woo-kontor-sync-pro' ) : __( 'No', 'woo-kontor-sync-pro' );
	}
}
