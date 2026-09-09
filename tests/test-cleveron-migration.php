<?php
/**
 * Orders Cleveron already has must not be sent to it twice.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-cleveron-migration.php';
require_once __DIR__ . '/test-shipment.php';

/**
 * Tests for WC_ESM_Cleveron_Migration.
 */
class Test_Cleveron_Migration extends WC_ESM_Test_Case {

	/**
	 * An order sent by the version this replaces.
	 *
	 * @param string $external_id What Cleveron called it.
	 *
	 * @return WC_ESM_Fake_Order
	 */
	protected function old_order( $external_id = 'cl-1' ) {
		$order = new WC_ESM_Fake_Order();

		if ( '' !== $external_id ) {
			$order->meta[ WC_ESM_Cleveron_Migration::OLD_META ] = $external_id;
		}

		return $order;
	}

	/**
	 * An order Cleveron already has reads as registered afterwards, which is
	 * the whole point: otherwise the shop is offered a button that would
	 * create a second real order.
	 *
	 * @return void
	 */
	public function test_an_already_sent_order_reads_as_registered() {
		$order = $this->old_order();

		$this->assertTrue( WC_ESM_Cleveron_Migration::migrate_order( $order ) );
		$this->assertTrue( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( array( 'cl-1' ), WC_ESM_Shipment::label_refs( $order ) );
	}

	/**
	 * Cleveron issues no barcode, so none is invented.
	 *
	 * @return void
	 */
	public function test_no_barcode_is_invented() {
		$order = $this->old_order();
		WC_ESM_Cleveron_Migration::migrate_order( $order );

		$this->assertSame( array(), WC_ESM_Shipment::barcodes( $order ) );
	}

	/**
	 * The old key is left where it is. It costs nothing, it is what the
	 * order screen showed for years, and leaving it means this migration can
	 * be run again after a restore without losing anything.
	 *
	 * @return void
	 */
	public function test_the_old_key_is_left_alone() {
		$order = $this->old_order();
		WC_ESM_Cleveron_Migration::migrate_order( $order );

		$this->assertSame( 'cl-1', $order->meta[ WC_ESM_Cleveron_Migration::OLD_META ] );
	}

	/**
	 * An order that was never sent has nothing to migrate.
	 *
	 * @return void
	 */
	public function test_an_unsent_order_is_left_alone() {
		$order = $this->old_order( '' );

		$this->assertFalse( WC_ESM_Cleveron_Migration::migrate_order( $order ) );
		$this->assertFalse( WC_ESM_Shipment::is_registered( $order ) );
		$this->assertSame( 0, $order->saves );
	}

	/**
	 * An order already migrated is left alone, so a second pass - after a
	 * stall, or a restore - writes nothing and costs nothing.
	 *
	 * @return void
	 */
	public function test_an_already_migrated_order_is_left_alone() {
		$order = $this->old_order();
		WC_ESM_Cleveron_Migration::migrate_order( $order );
		$saves = $order->saves;

		$this->assertFalse( WC_ESM_Cleveron_Migration::migrate_order( $order ) );
		$this->assertSame( $saves, $order->saves );
	}

	/**
	 * An order that reached 2.0 on its own - registered here, never through
	 * the old code - is not touched either.
	 *
	 * @return void
	 */
	public function test_an_order_registered_by_the_new_code_is_left_alone() {
		$order                                      = $this->old_order( '' );
		$order->meta[ WC_ESM_Shipment::LABEL_REFS ] = array( 'cl-9' );

		$this->assertFalse( WC_ESM_Cleveron_Migration::migrate_order( $order ) );
		$this->assertSame( array( 'cl-9' ), WC_ESM_Shipment::label_refs( $order ) );
	}
}
