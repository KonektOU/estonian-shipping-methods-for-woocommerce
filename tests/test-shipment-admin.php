<?php
/**
 * Send it, print it, track it, from the order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-admin.php';
require_once __DIR__ . '/test-shipment-labels.php';

/**
 * A carrier whose capabilities a test decides.
 */
class WC_ESM_Capable_Provider extends WC_ESM_Shipment_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'labels', 'tracking' );

	/**
	 * Whether it can build a tracking URL.
	 *
	 * @var bool
	 */
	public $tracks = true;

	/**
	 * Set the features.
	 *
	 * @param array $features Features.
	 *
	 * @return void
	 */
	public function declare_features( $features ) {
		$this->features = $features;
	}

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'capable';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Capable Carrier';
	}

	/**
	 * Which methods this carries.
	 *
	 * @param string $method_id Method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return 'capable_method' === $method_id;
	}

	/**
	 * Tracking URL.
	 *
	 * @param string $barcode Barcode.
	 *
	 * @return string
	 */
	public function get_tracking_url( $barcode ) {
		return $this->tracks && '' !== $barcode ? 'https://example.com/track/' . $barcode : '';
	}
}

/**
 * Tests for WC_ESM_Shipment_Admin.
 */
class Test_Shipment_Admin extends WC_ESM_Test_Case {

	/**
	 * A registry with one configurable carrier.
	 *
	 * @param WC_ESM_Capable_Provider $provider Provider.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry( $provider ) {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( $provider );

		return $registry;
	}

	/**
	 * An order nobody here carries offers nothing at all: it is not ours.
	 *
	 * @return void
	 */
	public function test_an_order_we_do_not_carry_offers_nothing() {
		$actions = WC_ESM_Shipment_Admin::available_actions(
			new WC_ESM_Shipped_Order( 'flat_rate' ),
			$this->registry( new WC_ESM_Capable_Provider() )
		);

		$this->assertSame( array(), $actions );
	}

	/**
	 * An order that has not been sent offers exactly one thing: sending it.
	 *
	 * @return void
	 */
	public function test_an_unsent_order_offers_only_sending() {
		$actions = WC_ESM_Shipment_Admin::available_actions(
			new WC_ESM_Shipped_Order( 'capable_method' ),
			$this->registry( new WC_ESM_Capable_Provider() )
		);

		$this->assertSame( array( 'register' ), $actions );
	}

	/**
	 * A sent order offers its label and its tracking, and no longer offers
	 * to be sent again.
	 *
	 * @return void
	 */
	public function test_a_sent_order_offers_the_label_and_the_tracking() {
		$order = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::BARCODES ] = array( 'B1' );

		$actions = WC_ESM_Shipment_Admin::available_actions( $order, $this->registry( new WC_ESM_Capable_Provider() ) );

		$this->assertContains( 'label', $actions );
		$this->assertContains( 'track', $actions );
		$this->assertNotContains( 'register', $actions );
	}

	/**
	 * A carrier that prints no labels is not offered a print button. This is
	 * the whole reason the capabilities are optional: Cleveron would have
	 * failed every time somebody pressed it.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_prints_nothing_is_not_offered_a_print_button() {
		$provider = new WC_ESM_Capable_Provider();
		$provider->declare_features( array() );

		$order = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::BARCODES ] = array( 'B1' );

		$actions = WC_ESM_Shipment_Admin::available_actions( $order, $this->registry( $provider ) );

		$this->assertNotContains( 'label', $actions );
		$this->assertNotContains( 'track', $actions );
	}

	/**
	 * A carrier that tracks but has no URL for this parcel offers no
	 * tracking button: it would open an empty link.
	 *
	 * @return void
	 */
	public function test_no_tracking_url_means_no_tracking_button() {
		$provider         = new WC_ESM_Capable_Provider();
		$provider->tracks = false;

		$order = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );
		$order->meta[ WC_ESM_Shipment::BARCODES ] = array( 'B1' );

		$this->assertNotContains( 'track', WC_ESM_Shipment_Admin::available_actions( $order, $this->registry( $provider ) ) );
	}

	/**
	 * A sent order with no barcode - Cleveron gives none - still offers its
	 * label if the carrier prints one, but nothing to track.
	 *
	 * @return void
	 */
	public function test_a_sent_order_without_a_barcode_has_nothing_to_track() {
		$order = new WC_ESM_Shipped_Order( 'capable_method', array( 'ref-1' ) );

		$actions = WC_ESM_Shipment_Admin::available_actions( $order, $this->registry( new WC_ESM_Capable_Provider() ) );

		$this->assertContains( 'label', $actions );
		$this->assertNotContains( 'track', $actions );
	}

	/**
	 * An order the carrier refused offers sending again, and says what went
	 * wrong.
	 *
	 * @return void
	 */
	public function test_a_refused_order_can_be_sent_again() {
		$order                                = new WC_ESM_Shipped_Order( 'capable_method' );
		$order->meta[ WC_ESM_Shipment::ERROR ] = 'Omniva was down.';

		$actions = WC_ESM_Shipment_Admin::available_actions( $order, $this->registry( new WC_ESM_Capable_Provider() ) );

		$this->assertSame( array( 'register' ), $actions );
	}
}
