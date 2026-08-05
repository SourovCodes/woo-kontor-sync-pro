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
	 * The tracking details for an order, or null when there are none to show.
	 *
	 * Provider and tracking number arrive as null rather than absent from Kontor, so
	 * an order that has been synced but not yet shipped has the meta present and
	 * empty. The tracking number is what decides there is anything worth showing.
	 *
	 * @param mixed $order Value the hook passed, which is not always an order.
	 * @return array|null Provider, number and URL, or null.
	 */
	protected function details( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$number = trim( (string) $order->get_meta( DeliverySync::META_TRACKING ) );

		if ( '' === $number ) {
			return null;
		}

		return array(
			'provider' => trim( (string) $order->get_meta( DeliverySync::META_PROVIDER ) ),
			'number'   => $number,
			'url'      => trim( (string) $order->get_meta( DeliverySync::META_TRACKING_URL ) ),
		);
	}

	/**
	 * Render the tracking table under the order details.
	 *
	 * @param mixed $order Order being displayed.
	 * @return void
	 */
	public function render_order_details( $order ) {
		$details = $this->details( $order );

		if ( null === $details ) {
			return;
		}

		?>
		<section class="woocommerce-order-tracking wksync-order-tracking">
			<h2><?php echo esc_html__( 'Shipment tracking', 'woo-kontor-sync-pro' ); ?></h2>
			<table class="woocommerce-table shop_table wksync-tracking-table">
				<tbody>
					<?php if ( '' !== $details['provider'] ) : ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Carrier', 'woo-kontor-sync-pro' ); ?></th>
							<td><?php echo esc_html( $details['provider'] ); ?></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tracking number', 'woo-kontor-sync-pro' ); ?></th>
						<td>
							<?php if ( '' !== $details['url'] ) : ?>
								<a href="<?php echo esc_url( $details['url'] ); ?>" target="_blank" rel="noopener nofollow">
									<?php echo esc_html( $details['number'] ); ?>
								</a>
							<?php else : ?>
								<?php echo esc_html( $details['number'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
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

		$details = $this->details( $order );

		if ( null === $details ) {
			return;
		}

		if ( $plain_text ) {
			$this->render_email_plain( $details );

			return;
		}

		?>
		<div class="wksync-email-tracking" style="margin-bottom: 24px;">
			<h2><?php echo esc_html__( 'Shipment tracking', 'woo-kontor-sync-pro' ); ?></h2>
			<p>
				<?php if ( '' !== $details['provider'] ) : ?>
					<?php echo esc_html__( 'Carrier', 'woo-kontor-sync-pro' ); ?>:
					<strong><?php echo esc_html( $details['provider'] ); ?></strong><br/>
				<?php endif; ?>
				<?php echo esc_html__( 'Tracking number', 'woo-kontor-sync-pro' ); ?>:
				<strong><?php echo esc_html( $details['number'] ); ?></strong>
				<?php if ( '' !== $details['url'] ) : ?>
					<br/>
					<a href="<?php echo esc_url( $details['url'] ); ?>" target="_blank" rel="noopener nofollow">
						<?php echo esc_html__( 'Track your shipment', 'woo-kontor-sync-pro' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the tracking block for the plain-text email template.
	 *
	 * @param array $details Provider, number and URL.
	 * @return void
	 */
	protected function render_email_plain( array $details ) {
		$lines = array( wc_strtoupper( __( 'Shipment tracking', 'woo-kontor-sync-pro' ) ) );

		if ( '' !== $details['provider'] ) {
			$lines[] = sprintf(
				/* translators: %s: shipping carrier name. */
				__( 'Carrier: %s', 'woo-kontor-sync-pro' ),
				$details['provider']
			);
		}

		$lines[] = sprintf(
			/* translators: %s: parcel tracking number. */
			__( 'Tracking number: %s', 'woo-kontor-sync-pro' ),
			$details['number']
		);

		if ( '' !== $details['url'] ) {
			$lines[] = sprintf(
				/* translators: %s: URL of the carrier's tracking page. */
				__( 'Track your shipment: %s', 'woo-kontor-sync-pro' ),
				esc_url_raw( $details['url'] )
			);
		}

		echo "\n" . esc_html( implode( "\n", $lines ) ) . "\n\n";
	}
}
