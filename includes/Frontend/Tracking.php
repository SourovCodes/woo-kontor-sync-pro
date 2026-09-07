<?php
/**
 * Customer-facing display of the tracking details Kontor returns.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Frontend;

use WC_Order;
use WooKontorSync\Sync\DeliverySync;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the shipment tracking pulled back from Kontor to the customer.
 *
 * The delivery sync records the provider, tracking number and tracking URL on the
 * order. Without this, all three sit in order meta where only someone editing the
 * order in the admin would ever see them, which is not who the courier's tracking
 * page is for.
 *
 * Three places, because they are the three a customer looks: the order view in My
 * Account, the order-received page after checkout, and the order emails — the
 * completion email in particular, which the delivery sync itself triggers when
 * Kontor reports an order as shipped.
 */
class Tracking {

	/**
	 * Register the display hooks.
	 *
	 * Registered on the front end and in the admin alike: order emails are rendered
	 * wherever the status changed, and an order completed from the admin, or by the
	 * delivery sync running in a background job, must still carry the tracking block.
	 *
	 * @return void
	 */
	public function register() {
		// Fires on both the My Account order view and the order-received page.
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_order_details' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 10, 3 );
	}

	/**
	 * Every parcel an order carries, oldest first.
	 *
	 * Read through DeliverySync::shipments(), which is the one place that decides how
	 * many parcels there are, so this page and the admin panel cannot disagree about it.
	 * An order can have several: a partial delivery ships what it has and the rest
	 * follows, and before 0.32.0 the shop showed only whichever one Kontor mentioned
	 * last, which is how a customer ended up unable to track a parcel they had been
	 * told about the week before.
	 *
	 * @param mixed $order Value the hook passed, which is not always an order.
	 * @return array List of parcels, each with "provider", "number" and "url".
	 */
	protected function details( $order ) {
		return DeliverySync::shipments( $order );
	}

	/**
	 * Render the tracking table under the order details.
	 *
	 * @param mixed $order Order being displayed.
	 * @return void
	 */
	public function render_order_details( $order ) {
		$parcels = $this->details( $order );

		if ( empty( $parcels ) ) {
			return;
		}

		?>
		<section class="woocommerce-order-tracking wksync-order-tracking">
			<h2><?php echo esc_html__( 'Shipment tracking', 'woo-kontor-sync-pro' ); ?></h2>
			<table class="woocommerce-table shop_table wksync-tracking-table">
				<tbody>
					<?php foreach ( $parcels as $parcel ) : ?>
						<?php if ( '' !== $parcel['provider'] ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html__( 'Carrier', 'woo-kontor-sync-pro' ); ?></th>
								<td><?php echo esc_html( $parcel['provider'] ); ?></td>
							</tr>
						<?php endif; ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Tracking number', 'woo-kontor-sync-pro' ); ?></th>
							<td>
								<?php if ( '' !== $parcel['url'] ) : ?>
									<a href="<?php echo esc_url( $parcel['url'] ); ?>" target="_blank" rel="noopener nofollow">
										<?php echo esc_html( $parcel['number'] ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $parcel['number'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</section>
		<?php
	}

	/**
	 * Render the tracking block in an order email.
	 *
	 * Skipped for the admin copies: the shop already has this on the order screen,
	 * and the tracking link is written for the person waiting for the parcel.
	 *
	 * @param mixed $order         Order the email is about.
	 * @param bool  $sent_to_admin Whether this is an admin copy.
	 * @param bool  $plain_text    Whether the plain-text template is rendering.
	 * @return void
	 */
	public function render_email( $order, $sent_to_admin = false, $plain_text = false ) {
		if ( $sent_to_admin ) {
			return;
		}

		$parcels = $this->details( $order );

		if ( empty( $parcels ) ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_email_plain( $parcels );

			return;
		}

		?>
		<div class="wksync-email-tracking" style="margin-bottom: 24px;">
			<h2><?php echo esc_html__( 'Shipment tracking', 'woo-kontor-sync-pro' ); ?></h2>
			<?php foreach ( $parcels as $parcel ) : ?>
				<p>
					<?php if ( '' !== $parcel['provider'] ) : ?>
						<?php echo esc_html__( 'Carrier', 'woo-kontor-sync-pro' ); ?>:
						<strong><?php echo esc_html( $parcel['provider'] ); ?></strong><br/>
					<?php endif; ?>
					<?php echo esc_html__( 'Tracking number', 'woo-kontor-sync-pro' ); ?>:
					<strong><?php echo esc_html( $parcel['number'] ); ?></strong>
					<?php if ( '' !== $parcel['url'] ) : ?>
						<br/>
						<a href="<?php echo esc_url( $parcel['url'] ); ?>" target="_blank" rel="noopener nofollow">
							<?php echo esc_html__( 'Track your shipment', 'woo-kontor-sync-pro' ); ?>
						</a>
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render the tracking block for the plain-text email template.
	 *
	 * @param array $parcels Parcels, each with "provider", "number" and "url".
	 * @return void
	 */
	protected function render_email_plain( array $parcels ) {
		$lines = array( wc_strtoupper( __( 'Shipment tracking', 'woo-kontor-sync-pro' ) ) );

		foreach ( $parcels as $index => $parcel ) {
			// A blank line between parcels, so several do not read as one run-on block.
			if ( $index > 0 ) {
				$lines[] = '';
			}

			if ( '' !== $parcel['provider'] ) {
				$lines[] = sprintf(
					/* translators: %s: shipping carrier name. */
					__( 'Carrier: %s', 'woo-kontor-sync-pro' ),
					$parcel['provider']
				);
			}

			$lines[] = sprintf(
				/* translators: %s: parcel tracking number. */
				__( 'Tracking number: %s', 'woo-kontor-sync-pro' ),
				$parcel['number']
			);

			if ( '' !== $parcel['url'] ) {
				$lines[] = sprintf(
					/* translators: %s: URL of the carrier's tracking page. */
					__( 'Track your shipment: %s', 'woo-kontor-sync-pro' ),
					esc_url_raw( $parcel['url'] )
				);
			}
		}

		echo "\n" . esc_html( implode( "\n", $lines ) ) . "\n\n";
	}
}
