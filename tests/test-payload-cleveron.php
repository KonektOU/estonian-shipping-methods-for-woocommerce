<?php
/**
 * What Cleveron wants for an office order.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-cleveron.php';

/**
 * Tests for WC_ESM_Payload_Cleveron.
 */
class Test_Payload_Cleveron extends WC_ESM_Test_Case {

	/**
	 * The request for one order.
	 *
	 * @param array $overrides Snapshot overrides.
	 * @param array $settings  Settings, carrier and zone merged.
	 *
	 * @return array
	 */
	protected function body( $overrides = array(), $settings = array() ) {
		return WC_ESM_Payload_Cleveron::build(
			WC_ESM_Order_Snapshot::make( $this->snapshot( array_merge( array( 'method_id' => 'cleveron_office' ), $overrides ) ) ),
			array_merge(
				array(
					'slot_size'       => 'S',
					'apm_external_id' => 'APM-7',
				),
				$settings
			)
		);
	}

	/**
	 * The parcel robot the zone is configured for is the destination: with
	 * Cleveron the shop picks it, not the customer, so it comes from the
	 * zone's own settings and not from anything chosen at the checkout.
	 *
	 * @return void
	 */
	public function test_the_zones_own_robot_is_the_destination() {
		$this->assertSame( 'APM-7', $this->body()['destination']['apm'] );
	}

	/**
	 * A Cleveron order carries no terminal the customer chose, because there
	 * is none to choose; the robot must still be named.
	 *
	 * @return void
	 */
	public function test_the_robot_is_named_even_though_nothing_was_chosen() {
		$body = $this->body( array( 'terminal_id' => '' ) );

		$this->assertSame( 'APM-7', $body['destination']['apm'] );
	}

	/**
	 * The order number is the barcode Cleveron files the order under.
	 *
	 * @return void
	 */
	public function test_the_order_number_is_the_barcode() {
		$this->assertSame( '1234', $this->body()['barcode'] );
	}

	/**
	 * The customer is reached by phone and e-mail; Cleveron messages them
	 * when the parcel is in the robot.
	 *
	 * @return void
	 */
	public function test_the_customer_is_reachable() {
		$body = $this->body();

		$this->assertSame( '+37255512345', $body['phone'] );
		$this->assertSame( 'mari@example.com', $body['email'] );
	}

	/**
	 * The slot size comes from the zone, because a shop with two offices can
	 * have two robots with different slots.
	 *
	 * @return void
	 */
	public function test_the_slot_size_comes_from_the_zone() {
		$this->assertSame( 'S', $this->body()['slotSize'] );
		$this->assertSame( 'XS', $this->body( array(), array( 'slot_size' => '' ) )['slotSize'] );
	}

	/**
	 * Cleveron is a client-to-client service here: the shop puts a parcel in
	 * and the customer takes it out.
	 *
	 * @return void
	 */
	public function test_it_is_a_client_to_client_order() {
		$this->assertSame( 'C2C', $this->body()['service'] );
	}

	/**
	 * A template the zone names is sent; a zone that names none sends an
	 * empty list rather than a null.
	 *
	 * @return void
	 */
	public function test_templates_are_sent_only_when_the_zone_names_them() {
		$this->assertSame( array(), $this->body()['templates'] );

		$body = $this->body( array(), array( 'sms_template' => 'sms-1', 'email_template' => 'mail-1' ) );

		$this->assertSame( array( 'sms-1', 'mail-1' ), $body['templates'] );
	}

	/**
	 * What the robot's screen shows names the shop and the order.
	 *
	 * @return void
	 */
	public function test_the_description_names_the_shop_and_the_order() {
		$description = $this->body()['extras']['description'];

		$this->assertStringContainsString( 'Testpood', $description );
		$this->assertStringContainsString( '1234', $description );
	}

	/**
	 * The order's own creation time travels with it, in the format Cleveron
	 * reads.
	 *
	 * @return void
	 */
	public function test_the_orders_creation_time_travels_with_it() {
		$this->assertSame( '2026-08-20T10:15:00+03:00', $this->body()['changesTimestamp'] );
	}
}
