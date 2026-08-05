<?php
/**
 * Tests for the customer-facing tracking display.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Order;
use WooKontorSync\Frontend\Tracking;
use WooKontorSync\Sync\DeliverySync;
use WP_UnitTestCase;

/**
 * Covers what the customer sees once Kontor reports a shipment.
 */
class TrackingTest extends WP_UnitTestCase {

	/**
	 * An order carrying tracking details.
	 *
	 * @param array $meta Meta to set, keyed by constant value.
	 * @return WC_Order The saved order.
	 */
	private function make_order( array $meta = array() ) {
		$order = new WC_Order();
		$order->set_status( 'completed' );

		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$order->save();

		return $order;
	}

	/**
	 * Capture what a renderer echoes.
	 *
	 * @param callable $renderer Callable that echoes markup.
	 * @return string Captured output.
	 */
	private function capture( callable $renderer ) {
		ob_start();
		$renderer();

		return (string) ob_get_clean();
	}

	/**
	 * The tracking number and carrier appear on the order details page.
	 *
	 * @return void
	 */
	public function test_order_details_show_the_tracking_number() {
		$order = $this->make_order(
			array(
				DeliverySync::META_PROVIDER     => 'DHL',
				DeliverySync::META_TRACKING     => '00340434161094042557',
				DeliverySync::META_TRACKING_URL => 'https://track.example.test/00340434161094042557',
			)
		);

		$tracking = new Tracking();
		$output   = $this->capture(
			static function () use ( $tracking, $order ) {
				$tracking->render_order_details( $order );
			}
		);

		$this->assertStringContainsString( '00340434161094042557', $output );
		$this->assertStringContainsString( 'DHL', $output );
		$this->assertStringContainsString( 'https://track.example.test/00340434161094042557', $output );
	}

	/**
	 * An order with no tracking number renders nothing at all.
	 *
	 * Provider and tracking arrive as null rather than absent, so a synced but
	 * unshipped order has the meta present and empty. Rendering an empty tracking
	 * table would tell the customer their parcel is on its way when it is not.
	 *
	 * @return void
	 */
	public function test_nothing_is_rendered_without_a_tracking_number() {
		$order = $this->make_order(
			array(
				DeliverySync::META_PROVIDER => '',
				DeliverySync::META_TRACKING => '',
			)
		);

		$tracking = new Tracking();

		$this->assertSame(
			'',
			$this->capture(
				static function () use ( $tracking, $order ) {
					$tracking->render_order_details( $order );
				}
			)
		);

		$this->assertSame(
			'',
			$this->capture(
				static function () use ( $tracking, $order ) {
					$tracking->render_email( $order, false, false );
				}
			)
		);
	}

	/**
	 * The admin copy of an order email carries no tracking block.
	 *
	 * @return void
	 */
	public function test_admin_emails_are_skipped() {
		$order = $this->make_order( array( DeliverySync::META_TRACKING => '00340434161094042557' ) );

		$tracking = new Tracking();

		$this->assertSame(
			'',
			$this->capture(
				static function () use ( $tracking, $order ) {
					$tracking->render_email( $order, true, false );
				}
			)
		);
	}

	/**
	 * The plain-text email carries the details without any markup.
	 *
	 * @return void
	 */
	public function test_plain_text_email_has_no_markup() {
		$order = $this->make_order(
			array(
				DeliverySync::META_PROVIDER     => 'DHL',
				DeliverySync::META_TRACKING     => '00340434161094042557',
				DeliverySync::META_TRACKING_URL => 'https://track.example.test/parcel',
			)
		);

		$tracking = new Tracking();
		$output   = $this->capture(
			static function () use ( $tracking, $order ) {
				$tracking->render_email( $order, false, true );
			}
		);

		$this->assertStringContainsString( '00340434161094042557', $output );
		$this->assertStringContainsString( 'https://track.example.test/parcel', $output );
		$this->assertStringNotContainsString( '<', $output );
	}

	/**
	 * A tracking number is escaped rather than trusted.
	 *
	 * The value comes from the ERP, which is not a trusted source of markup.
	 *
	 * @return void
	 */
	public function test_tracking_details_are_escaped() {
		$order = $this->make_order(
			array(
				DeliverySync::META_PROVIDER => '<script>alert(1)</script>',
				DeliverySync::META_TRACKING => '123"><script>alert(1)</script>',
			)
		);

		$tracking = new Tracking();
		$output   = $this->capture(
			static function () use ( $tracking, $order ) {
				$tracking->render_order_details( $order );
			}
		);

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '&lt;script&gt;', $output );
	}

	/**
	 * A hook argument that is not an order is ignored rather than fatal.
	 *
	 * @return void
	 */
	public function test_non_order_argument_is_ignored() {
		$tracking = new Tracking();

		$this->assertSame(
			'',
			$this->capture(
				static function () use ( $tracking ) {
					$tracking->render_order_details( null );
				}
			)
		);
	}
}
