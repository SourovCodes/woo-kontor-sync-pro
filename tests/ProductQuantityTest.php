<?php
/**
 * Tests for the sales quantities Kontor states for an article.
 *
 * @package WooKontorSync
 */

namespace WooKontorSync\Tests;

use WC_Product_Simple;
use WooKontorSync\Admin\ProductFields;
use WooKontorSync\Admin\Settings;
use WooKontorSync\Frontend\Quantities;
use WooKontorSync\Sync\ProductSync;
use WP_UnitTestCase;

/**
 * Covers importing Verkaufsmenge and Verkaufsmenge_staffel, and the setting that
 * decides whether a customer is held to them.
 *
 * The import is deliberately independent of the setting: the figures are what Kontor
 * says about the goods, and recording them either way is what lets enforcement be
 * turned on without waiting for a catalogue walk.
 */
class ProductQuantityTest extends WP_UnitTestCase {

	/**
	 * Start every test with the shop not enforcing anything.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		$this->enforce( false );
	}

	/**
	 * Turn enforcement on or off.
	 *
	 * @param bool $enforce Whether the quantities bind the shop.
	 * @return void
	 */
	private function enforce( $enforce ) {
		update_option(
			Settings::OPTION_KEY,
			array( Settings::ENFORCE_QUANTITIES => (bool) $enforce )
		);
	}

	/**
	 * An article row of the shape the API returns.
	 *
	 * @param array $overrides Fields to replace.
	 * @return array Article row.
	 */
	private function article( array $overrides = array() ) {
		return array_merge(
			array(
				'Artnr'                 => 'abel-AB12',
				'Bez1'                  => 'Abel blocks 12',
				'Shoptitel'             => 'Abel blocks 12',
				'Ek'                    => 40.9500,
				'UVP'                   => 77.9000,
				'Lagerbestand'          => 240,
				'MainImageURL'          => null,
				'Verkaufsmenge'         => 6,
				'Verkaufsmenge_staffel' => 2,
			),
			$overrides
		);
	}

	/**
	 * Settings for a sync that touches no images.
	 *
	 * @return array Settings array.
	 */
	private function settings() {
		return array(
			'api_base_url'   => 'https://erp.example.test/api/v1/kontor',
			'api_key'        => 'test-key-123',
			'image_base_url' => '',
			'shoptype'       => 'B2C',
		);
	}

	/**
	 * The product a run imported.
	 *
	 * @return \WC_Product|false Product, or false when nothing was imported.
	 */
	private function imported() {
		return wc_get_product( wc_get_product_id_by_sku( 'abel-AB12' ) );
	}

	/**
	 * A product carrying the quantities directly, without going through a sync.
	 *
	 * @param int $min  Minimum quantity, or 0 for none.
	 * @param int $step Quantity step, or 0 for none.
	 * @return \WC_Product_Simple The saved product.
	 */
	private function product( $min, $step ) {
		$product = new WC_Product_Simple();
		$product->set_name( 'Abel blocks 12' );
		$product->set_sku( 'abel-AB12-' . wp_rand( 1000, 9999 ) );
		$product->set_regular_price( '40.95' );

		if ( $min > 0 ) {
			$product->update_meta_data( ProductSync::META_MIN_QTY, $min );
		}

		if ( $step > 0 ) {
			$product->update_meta_data( ProductSync::META_QTY_STEP, $step );
		}

		$product->save();

		return wc_get_product( $product->get_id() );
	}

	/**
	 * Both quantities are recorded from the feed.
	 *
	 * @return void
	 */
	public function test_quantities_are_imported() {
		$sync = new ProductSync( null, $this->settings() );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );

		$product = $this->imported();

		$this->assertSame( '6', (string) $product->get_meta( ProductSync::META_MIN_QTY ) );
		$this->assertSame( '2', (string) $product->get_meta( ProductSync::META_QTY_STEP ) );
	}

	/**
	 * They are imported even while the shop is ignoring them.
	 *
	 * Enforcement is a decision about this shop; the figures are a fact about the
	 * goods. Skipping the import would mean turning the setting on did nothing until
	 * the next full catalogue walk had been round.
	 *
	 * @return void
	 */
	public function test_quantities_are_imported_while_enforcement_is_off() {
		$this->enforce( false );

		$sync = new ProductSync( null, $this->settings() );

		$sync->import_article( $this->article(), 1000 );

		$this->assertSame( '6', (string) $this->imported()->get_meta( ProductSync::META_MIN_QTY ) );
	}

	/**
	 * A value that says nothing is not stored.
	 *
	 * One is WooCommerce's own default, so recording it would put a row of meta on
	 * every product in the catalogue in order to say what was already true.
	 *
	 * @param mixed $value The quantity as the feed gives it.
	 * @return void
	 *
	 * @dataProvider empty_quantities
	 */
	public function test_quantities_worth_nothing_are_not_stored( $value ) {
		$sync = new ProductSync( null, $this->settings() );

		$row = $this->article(
			array(
				'Verkaufsmenge'         => $value,
				'Verkaufsmenge_staffel' => $value,
			)
		);

		$this->assertSame( 'created', $sync->import_article( $row, 1000 ) );

		$product = $this->imported();

		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_MIN_QTY ) );
		$this->assertSame( '', (string) $product->get_meta( ProductSync::META_QTY_STEP ) );
	}

	/**
	 * The shapes a quantity worth nothing arrives in.
	 *
	 * @return array[] Test cases.
	 */
	public function empty_quantities() {
		return array(
			'null'         => array( null ),
			'one'          => array( 1 ),
			'zero'         => array( 0 ),
			'negative'     => array( -4 ),
			'empty'        => array( '' ),
			'fractional'   => array( 1.5 ),
			'not a number' => array( 'sechs' ),
		);
	}

	/**
	 * A key missing altogether is the same as one that is null.
	 *
	 * @return void
	 */
	public function test_absent_keys_are_not_stored() {
		$row = $this->article();

		unset( $row['Verkaufsmenge'], $row['Verkaufsmenge_staffel'] );

		$sync = new ProductSync( null, $this->settings() );

		$this->assertSame( 'created', $sync->import_article( $row, 1000 ) );
		$this->assertSame( '', (string) $this->imported()->get_meta( ProductSync::META_MIN_QTY ) );
	}

	/**
	 * A quantity that goes away is cleared rather than left standing.
	 *
	 * @return void
	 */
	public function test_quantities_are_cleared_when_they_go_away() {
		$sync = new ProductSync( null, $this->settings() );

		$sync->import_article( $this->article(), 1000 );

		$this->assertSame( '6', (string) $this->imported()->get_meta( ProductSync::META_MIN_QTY ) );

		$row = $this->article(
			array(
				'Verkaufsmenge'         => null,
				'Verkaufsmenge_staffel' => null,
			)
		);

		$this->assertSame( 'updated', $sync->import_article( $row, 1001 ) );
		$this->assertSame( '', (string) $this->imported()->get_meta( ProductSync::META_MIN_QTY ) );
		$this->assertSame( '', (string) $this->imported()->get_meta( ProductSync::META_QTY_STEP ) );
	}

	/**
	 * A change to either quantity alone is a change.
	 *
	 * Left out of the hash, an article whose sales quantity moved and nothing else
	 * would read as unchanged and keep the old rule for good.
	 *
	 * @param string $field The field that moved.
	 * @return void
	 *
	 * @dataProvider quantity_fields
	 */
	public function test_a_changed_quantity_is_a_change( $field ) {
		$sync = new ProductSync( null, $this->settings() );

		$this->assertSame( 'created', $sync->import_article( $this->article(), 1000 ) );
		$this->assertSame( 'skipped', $sync->import_article( $this->article(), 1001 ) );
		$this->assertSame( 'updated', $sync->import_article( $this->article( array( $field => 12 ) ), 1002 ) );
	}

	/**
	 * The feed fields carrying the quantities.
	 *
	 * @return array[] Test cases.
	 */
	public function quantity_fields() {
		return array(
			'minimum' => array( 'Verkaufsmenge' ),
			'step'    => array( 'Verkaufsmenge_staffel' ),
		);
	}

	/**
	 * With the setting off, nothing is constrained.
	 *
	 * @return void
	 */
	public function test_limits_are_ignored_while_enforcement_is_off() {
		$product = $this->product( 6, 2 );

		$this->assertSame(
			array(
				'min'  => 1,
				'step' => 1,
			),
			Quantities::limits( $product )
		);
	}

	/**
	 * With the setting on, the stored figures are the limits.
	 *
	 * @return void
	 */
	public function test_limits_come_from_the_product() {
		$this->enforce( true );

		$this->assertSame(
			array(
				'min'  => 6,
				'step' => 2,
			),
			Quantities::limits( $this->product( 6, 2 ) )
		);
	}

	/**
	 * A minimum that is not a multiple of the step is raised to one.
	 *
	 * WooCommerce judges a quantity by asking whether it is a multiple of the step, so
	 * a minimum of five with a step of two would otherwise let the quantity box offer a
	 * five that the cart block then refuses.
	 *
	 * @return void
	 */
	public function test_minimum_is_raised_to_a_multiple_of_the_step() {
		$this->enforce( true );

		$limits = Quantities::limits( $this->product( 5, 2 ) );

		$this->assertSame( 6, $limits['min'] );
		$this->assertSame( 2, $limits['step'] );
	}

	/**
	 * A step on its own is its own minimum.
	 *
	 * @return void
	 */
	public function test_a_step_alone_sets_the_minimum() {
		$this->enforce( true );

		$this->assertSame( 3, Quantities::limits( $this->product( 0, 3 ) )['min'] );
	}

	/**
	 * WooCommerce's quantity input picks the figures up.
	 *
	 * Asserted through wc_get_quantity_input_args() rather than the filters directly,
	 * because that function is what the classic quantity box and the Store API's own
	 * limits are both built on.
	 *
	 * @return void
	 */
	public function test_quantity_input_carries_the_limits() {
		$this->enforce( true );

		$args = wc_get_quantity_input_args( array(), $this->product( 6, 2 ) );

		$this->assertSame( 6, (int) $args['min_value'] );
		$this->assertSame( 2, (int) $args['step'] );
		$this->assertSame( 6, (int) $args['input_value'] );
	}

	/**
	 * A product with nothing recorded keeps WooCommerce's defaults.
	 *
	 * @return void
	 */
	public function test_quantity_input_is_untouched_without_figures() {
		$this->enforce( true );

		$args = wc_get_quantity_input_args( array(), $this->product( 0, 0 ) );

		$this->assertSame( 1, (int) $args['min_value'] );
		$this->assertSame( 1, (int) $args['step'] );
	}

	/**
	 * The shop loop's add-to-cart button asks for a quantity that will be accepted.
	 *
	 * @return void
	 */
	public function test_loop_button_asks_for_the_minimum() {
		$this->enforce( true );

		$args = apply_filters(
			'woocommerce_loop_add_to_cart_args',
			array( 'quantity' => 1 ),
			$this->product( 6, 2 )
		);

		$this->assertSame( 6, (int) $args['quantity'] );
	}

	/**
	 * The blocks button, which passes no quantity, is left alone.
	 *
	 * @return void
	 */
	public function test_loop_button_without_a_quantity_is_left_alone() {
		$this->enforce( true );

		$args = apply_filters( 'woocommerce_loop_add_to_cart_args', array( 'class' => 'button' ), $this->product( 6, 2 ) );

		$this->assertArrayNotHasKey( 'quantity', $args );
	}

	/**
	 * The order screen's quantity boxes are never restricted.
	 *
	 * Refunding one item of six is an ordinary thing to do, and the shop manager doing
	 * it is not a customer being sold to.
	 *
	 * @return void
	 */
	public function test_the_admin_step_is_left_at_one() {
		$this->enforce( true );

		$product = $this->product( 6, 2 );

		$this->assertSame( 1, (int) apply_filters( 'woocommerce_quantity_input_step_admin', $product->get_purchase_quantity_step(), $product, 'refund' ) );
	}

	/**
	 * A step somebody else imposed on the order screen is left standing.
	 *
	 * Another plugin may have its own reason for restricting it, and taking that away
	 * is not this one's business.
	 *
	 * @return void
	 */
	public function test_another_plugins_admin_step_is_left_alone() {
		$this->enforce( true );

		$this->assertSame( 5, (int) apply_filters( 'woocommerce_quantity_input_step_admin', 5, $this->product( 0, 0 ), 'edit' ) );
	}

	/**
	 * A quantity below the minimum is refused.
	 *
	 * @return void
	 */
	public function test_add_to_cart_below_the_minimum_is_refused() {
		$this->enforce( true );

		$product = $this->product( 6, 2 );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 4, 0 ) );
	}

	/**
	 * A quantity that is not a multiple of the step is refused.
	 *
	 * @return void
	 */
	public function test_add_to_cart_off_the_step_is_refused() {
		$this->enforce( true );

		$product = $this->product( 6, 2 );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 7, 0 ) );
	}

	/**
	 * A valid quantity is allowed through.
	 *
	 * @return void
	 */
	public function test_add_to_cart_of_a_valid_quantity_is_allowed() {
		$this->enforce( true );

		$product = $this->product( 6, 2 );

		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 8, 0 ) );
	}

	/**
	 * With the setting off, any quantity is allowed.
	 *
	 * @return void
	 */
	public function test_add_to_cart_is_unrestricted_while_enforcement_is_off() {
		$product = $this->product( 6, 2 );

		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 1, 0 ) );
	}

	/**
	 * A cart quantity that the article is not sold in is refused.
	 *
	 * @return void
	 */
	public function test_cart_update_off_the_step_is_refused() {
		$this->enforce( true );

		$values = array( 'data' => $this->product( 6, 2 ) );

		$this->assertFalse( apply_filters( 'woocommerce_update_cart_validation', true, 'key', $values, 9 ) );
		$this->assertTrue( apply_filters( 'woocommerce_update_cart_validation', true, 'key', $values, 10 ) );
	}

	/**
	 * Emptying a line is always allowed.
	 *
	 * Zero is how the cart removes something, and an empty cart breaks no rule.
	 *
	 * @return void
	 */
	public function test_cart_update_to_zero_is_allowed() {
		$this->enforce( true );

		$values = array( 'data' => $this->product( 6, 2 ) );

		$this->assertTrue( apply_filters( 'woocommerce_update_cart_validation', true, 'key', $values, 0 ) );
	}

	/**
	 * Validation that already failed is not overturned.
	 *
	 * @return void
	 */
	public function test_an_earlier_refusal_is_left_alone() {
		$this->enforce( true );

		$product = $this->product( 6, 2 );

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', false, $product->get_id(), 6, 0 ) );
	}

	/**
	 * A fresh install enforces nothing.
	 *
	 * Like every other setting that changes what the shop does, this one has to be
	 * chosen: an upgrade must not start refusing quantities that were accepted
	 * yesterday.
	 *
	 * @return void
	 */
	public function test_enforcement_is_off_by_default() {
		$this->assertFalse( Settings::default_settings()[ Settings::ENFORCE_QUANTITIES ] );
	}

	/**
	 * The setting is saved from the checkbox and its hidden zero.
	 *
	 * @return void
	 */
	public function test_the_setting_is_saved() {
		$this->enforce( true );

		$this->assertFalse( ( new Settings() )->sanitize( array( Settings::ENFORCE_QUANTITIES => '0' ) )[ Settings::ENFORCE_QUANTITIES ] );
		$this->assertTrue( ( new Settings() )->sanitize( array( Settings::ENFORCE_QUANTITIES => '1' ) )[ Settings::ENFORCE_QUANTITIES ] );
	}

	/**
	 * A submission that never carried the field keeps what is stored.
	 *
	 * Absent cannot mean off, or a partial save would quietly stop enforcing.
	 *
	 * @return void
	 */
	public function test_an_absent_field_keeps_the_setting() {
		$this->enforce( true );

		$this->assertTrue( ( new Settings() )->sanitize( array( 'shoptype' => 'B2B' ) )[ Settings::ENFORCE_QUANTITIES ] );
	}

	/**
	 * The Inventory tab shows both figures.
	 *
	 * @return void
	 */
	public function test_the_product_screen_shows_the_quantities() {
		$html = $this->panel( $this->product( 6, 2 ) );

		$this->assertStringContainsString( 'Minimum quantity', $html );
		$this->assertStringContainsString( '<span>6</span>', $html );
		$this->assertStringContainsString( 'Quantity step', $html );
		$this->assertStringContainsString( '<span>2</span>', $html );

		// WooCommerce reads these from the variation rather than the parent, so a value
		// on a variable product would mean nothing.
		$this->assertStringContainsString( 'show_if_simple', $html );
	}

	/**
	 * A product with nothing stored reads as having no constraint.
	 *
	 * Rather than as one sold in lots of nothing.
	 *
	 * @return void
	 */
	public function test_the_product_screen_shows_nothing_for_an_unconstrained_product() {
		$html = $this->panel( $this->product( 0, 0 ) );

		$this->assertStringContainsString( '<span>None</span>', $html );
		$this->assertStringNotContainsString( '<span>0</span>', $html );
	}

	/**
	 * Nothing on the tab can be submitted, so nothing can be lost.
	 *
	 * Kontor is the source of truth and every sync rewrites both figures, so an input
	 * here would accept a change that quietly disappeared at the next run. It is not a
	 * form field at all rather than a disabled one: there is no name to post, and no
	 * save handler behind it that could later be made to listen.
	 *
	 * @return void
	 */
	public function test_the_product_screen_offers_nothing_to_edit() {
		$html = $this->panel( $this->product( 6, 2 ) );

		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringNotContainsString( 'name=', $html );
		$this->assertFalse( method_exists( ProductFields::class, 'save' ) );
		$this->assertFalse( has_action( 'woocommerce_admin_process_product_object' ) );
	}

	/**
	 * Render the Inventory tab's addition for one product.
	 *
	 * The meta box helpers are loaded by WC_Admin_Meta_Boxes on the real screen, which
	 * is also what fires the hook this renders on, so requiring them here stands in for
	 * a context the suite does not otherwise have.
	 *
	 * @param \WC_Product $product Product being edited.
	 * @return string The markup.
	 */
	private function panel( $product ) {
		require_once WC_ABSPATH . 'includes/admin/wc-meta-box-functions.php';

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- WooCommerce's global, set here to stand in for the meta box that normally fills it.
		$GLOBALS['product_object'] = $product;

		ob_start();
		( new ProductFields() )->render();

		return (string) ob_get_clean();
	}

	/**
	 * What the cart would end up holding is what is judged.
	 *
	 * Two additions that are each unobjectionable can still leave an invalid total, so
	 * the quantity already in the cart counts towards the sum — which is also what
	 * WooCommerce's own Store API does before it merges a line.
	 *
	 * @return void
	 */
	public function test_the_resulting_cart_quantity_is_what_is_judged() {
		$this->enforce( true );

		$product = $this->product( 5, 2 );

		wc_load_cart();
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $product->get_id(), 6 );

		$this->assertSame( 6.0, (float) WC()->cart->get_cart_contents_count(), 'The cart was not set up.' );

		/*
		 * Six is already there, so three more would leave nine: over the minimum, but
		 * not a multiple of two. Four more leaves ten, which is fine.
		 */

		$this->assertFalse( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 3, 0 ) );
		$this->assertTrue( apply_filters( 'woocommerce_add_to_cart_validation', true, $product->get_id(), 4, 0 ) );

		WC()->cart->empty_cart();
	}

	/**
	 * A cart holding an invalid quantity is caught at the cart and checkout.
	 *
	 * Enforcement turned on after the cart was filled, or a sync that recorded the
	 * figures later, would otherwise let an invalid quantity through to an order Kontor
	 * cannot fulfil.
	 *
	 * @return void
	 */
	public function test_a_cart_filled_before_the_setting_is_caught() {
		$product = $this->product( 6, 2 );

		wc_load_cart();
		WC()->cart->empty_cart();

		// Added while nothing was being enforced, which is the case worth catching.
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->enforce( true );

		wc_clear_notices();

		do_action( 'woocommerce_check_cart_items' );

		$notices = wp_list_pluck( wc_get_notices( 'error' ), 'notice' );

		$this->assertNotEmpty( $notices, 'The cart was not checked.' );
		$this->assertStringContainsString( 'minimum quantity of 6', implode( ' ', $notices ) );

		wc_clear_notices();
		WC()->cart->empty_cart();
	}
}
