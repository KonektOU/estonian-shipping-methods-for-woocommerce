<?php
/**
 * One place that knows every carrier.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';
require_once __DIR__ . '/test-shipment-provider.php';
require_once __DIR__ . '/test-shipment-labels.php';

/**
 * Tests for WC_ESM_Shipment_Registry.
 */
class Test_Shipment_Registry extends WC_ESM_Test_Case {

	/**
	 * A registry holding one carrier of each shape.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry() {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( new WC_ESM_Test_Provider() );
		$registry->register( new WC_ESM_Full_Feature_Test_Provider() );
		$registry->register( new WC_ESM_Bare_Test_Provider() );

		return $registry;
	}

	/**
	 * A carrier is found again by its id.
	 *
	 * @return void
	 */
	public function test_a_carrier_is_found_by_its_id() {
		$this->assertInstanceOf( 'WC_ESM_Bare_Test_Provider', $this->registry()->get_provider( 'bare' ) );
	}

	/**
	 * An id nobody registered is null, not a fatal: a stored setting or an
	 * order can name a carrier that has since been switched off.
	 *
	 * @return void
	 */
	public function test_an_unregistered_id_is_null() {
		$this->assertNull( $this->registry()->get_provider( 'collectnet' ) );
		$this->assertNull( $this->registry()->get_provider( '' ) );
	}

	/**
	 * Carriers come back in the order they were registered, so every screen
	 * lists them the same way twice running.
	 *
	 * @return void
	 */
	public function test_carriers_keep_their_registration_order() {
		$this->assertSame( array( 'testcarrier', 'bare' ), array_keys( $this->registry()->get_providers() ) );
	}

	/**
	 * Registering an id twice replaces the first: a carrier is one
	 * integration, and the later registration is the one that meant to win.
	 *
	 * @return void
	 */
	public function test_registering_an_id_twice_replaces_it() {
		$registry = $this->registry();

		$this->assertInstanceOf( 'WC_ESM_Full_Feature_Test_Provider', $registry->get_provider( 'testcarrier' ) );
	}

	/**
	 * Only the carriers that opted into a capability come back for it.
	 *
	 * @return void
	 */
	public function test_only_carriers_with_the_capability_come_back() {
		$registry = $this->registry();

		$this->assertSame( array( 'testcarrier' ), array_keys( $registry->providers_supporting( 'labels' ) ) );
		$this->assertSame( array( 'testcarrier' ), array_keys( $registry->providers_supporting( 'cod' ) ) );
	}

	/**
	 * A capability nobody offers is an empty list, not a broken screen.
	 *
	 * @return void
	 */
	public function test_a_capability_nobody_offers_is_empty() {
		$this->assertSame( array(), $this->registry()->providers_supporting( 'manifest' ) );
	}

	/**
	 * An order's shipping method finds the carrier that carries it.
	 *
	 * @return void
	 */
	public function test_a_shipping_method_finds_its_carrier() {
		$this->assertSame( 'testcarrier', $this->registry()->provider_for_method( 'testcarrier_terminal' )->get_id() );
	}

	/**
	 * A method no carrier claims is null: the order is not ours to send.
	 *
	 * @return void
	 */
	public function test_an_unclaimed_method_is_null() {
		$this->assertNull( $this->registry()->provider_for_method( 'flat_rate' ) );
	}

	/**
	 * The shared registry is one object, so a carrier registered during boot
	 * is there for every screen afterwards.
	 *
	 * @return void
	 */
	public function test_the_shared_registry_is_one_object() {
		$this->assertSame( WC_ESM_Shipment_Registry::instance(), WC_ESM_Shipment_Registry::instance() );
	}

	/**
	 * An order finds its carrier through its shipping line. Three screens
	 * and the registration all asked this question with their own copy of
	 * the loop; it belongs here, next to provider_for_method().
	 *
	 * @return void
	 */
	public function test_an_order_finds_its_carrier() {
		$this->assertSame(
			'testcarrier',
			$this->registry()->provider_for_order( new WC_ESM_Shipped_Order( 'testcarrier_terminal' ) )->get_id()
		);
	}

	/**
	 * An order shipped by something else is null: not ours.
	 *
	 * @return void
	 */
	public function test_an_order_shipped_by_something_else_is_null() {
		$this->assertNull( $this->registry()->provider_for_order( new WC_ESM_Shipped_Order( 'flat_rate' ) ) );
	}

	/**
	 * An order with no shipping line at all - a local pickup, a free order -
	 * is null rather than a warning.
	 *
	 * @return void
	 */
	public function test_an_order_with_no_shipping_line_is_null() {
		$order           = new WC_ESM_Shipped_Order( 'flat_rate' );
		$order->shipping = array();

		$this->assertNull( $this->registry()->provider_for_order( $order ) );
	}
}
