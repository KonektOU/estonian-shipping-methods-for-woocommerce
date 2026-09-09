<?php
/**
 * Port Cleveron Office onto the shared carrier abstraction.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-cleveron.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/providers/class-wc-esm-provider-cleveron.php';
require_once __DIR__ . '/class-wc-esm-fake-client.php';

/**
 * The provider with the network and the shipping zone taken out.
 */
class WC_ESM_Cleveron_Test_Provider extends WC_ESM_Provider_Cleveron {

	/**
	 * The stand-in for the network.
	 *
	 * @var WC_ESM_Fake_Client
	 */
	public $fake;

	/**
	 * Constructor.
	 *
	 * @param array $answers Answers to give.
	 */
	public function __construct( $answers = array() ) {
		$this->fake = new WC_ESM_Fake_Client( $answers );
	}

	/**
	 * The stand-in.
	 *
	 * @return WC_ESM_Carrier_Client
	 */
	protected function client() {
		return $this->fake;
	}

	/**
	 * The zone's own options, as a test decides them.
	 *
	 * @var array
	 */
	public $zone = array(
		'slot_size'       => 'S',
		'apm_external_id' => 'APM-7',
	);

	/**
	 * The zone's own options.
	 *
	 * @param array $snapshot Order snapshot.
	 *
	 * @return array
	 */
	protected function zone_options( $snapshot ) {
		return $this->zone;
	}
}

/**
 * Tests for WC_ESM_Provider_Cleveron.
 */
class Test_Provider_Cleveron extends WC_ESM_Test_Case {

	/**
	 * A provider that will answer with what the test prepared.
	 *
	 * @param array $answers Answers.
	 *
	 * @return WC_ESM_Cleveron_Test_Provider
	 */
	protected function provider( $answers = array() ) {
		$provider = new WC_ESM_Cleveron_Test_Provider( $answers );
		$provider->set_settings(
			array(
				'api_url'   => 'https://office.cleveron.com',
				'api_key'   => 'key',
				'api_token' => 'token',
			)
		);

		return $provider;
	}

	/**
	 * An order going to a Cleveron robot.
	 *
	 * @return array
	 */
	protected function order() {
		return WC_ESM_Order_Snapshot::make(
			$this->snapshot(
				array( 'method_id' => 'cleveron_office' )
			)
		);
	}

	/**
	 * Identity, and the zone method it carries.
	 *
	 * @return void
	 */
	public function test_it_knows_what_it_is_and_what_it_carries() {
		$provider = new WC_ESM_Provider_Cleveron();

		$this->assertSame( 'cleveron', $provider->get_id() );
		$this->assertTrue( $provider->carries( 'cleveron_office' ) );
		$this->assertFalse( $provider->carries( 'dpd_shops_ee' ) );
	}

	/**
	 * Cleveron declares nothing it cannot do. It prints no labels and tracks
	 * nothing, and the order screen must not offer either.
	 *
	 * @return void
	 */
	public function test_it_declares_nothing_it_cannot_do() {
		$provider = new WC_ESM_Provider_Cleveron();

		foreach ( array( 'labels', 'tracking', 'pickup', 'manifest', 'cod', 'cod_report', 'return' ) as $feature ) {
			$this->assertFalse( $provider->supports( $feature ), $feature );
		}
	}

	/**
	 * Asked for a label anyway, it answers honestly rather than failing like
	 * a network error.
	 *
	 * @return void
	 */
	public function test_asked_for_a_label_it_answers_honestly() {
		$result = ( new WC_ESM_Provider_Cleveron() )->fetch_labels( array( 'x' ) );

		$this->assertSame( 'unsupported', $result->get_code() );
	}

	/**
	 * A customer following the parcel has nowhere to go, and the caller is
	 * told so with an empty string rather than a link to nothing.
	 *
	 * @return void
	 */
	public function test_there_is_nowhere_to_follow_the_parcel() {
		$this->assertSame( '', ( new WC_ESM_Provider_Cleveron() )->get_tracking_url( '1234' ) );
	}

	/**
	 * A created order comes back with the id Cleveron filed it under.
	 *
	 * @return void
	 */
	public function test_a_created_order_comes_back_with_its_id() {
		$provider = $this->provider( array( array( 201, array( 'id' => 'cl-1' ) ) ) );

		$result = $provider->register( $this->order() );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( 'cl-1' ), $result->get( 'label_refs' ) );
		$this->assertSame( array(), $result->get( 'barcodes' ) );
	}

	/**
	 * The order goes to the integration endpoint under the shop's own
	 * Cleveron host.
	 *
	 * @return void
	 */
	public function test_the_order_goes_to_the_integration_endpoint() {
		$provider = $this->provider( array( array( 201, array( 'id' => 'cl-1' ) ) ) );
		$provider->register( $this->order() );

		$this->assertSame( 'orders', $provider->fake->endpoint() );
		$this->assertSame( 'APM-7', $provider->fake->body()['destination']['apm'] );
	}

	/**
	 * The zone's own options reach the payload: the robot, the slot and the
	 * templates are per-zone, not per-carrier.
	 *
	 * @return void
	 */
	public function test_the_zones_options_reach_the_payload() {
		$provider       = $this->provider( array( array( 201, array( 'id' => 'cl-1' ) ) ) );
		$provider->zone = array(
			'slot_size'       => 'M',
			'sms_template'    => 'sms-1',
			'apm_external_id' => 'APM-7',
		);

		$provider->register( $this->order() );

		$this->assertSame( 'M', $provider->fake->body()['slotSize'] );
		$this->assertSame( array( 'sms-1' ), $provider->fake->body()['templates'] );
	}

	/**
	 * A 201 with no id is a failure: without the id the order cannot be
	 * matched to Cleveron's afterwards.
	 *
	 * @return void
	 */
	public function test_a_created_order_without_an_id_is_a_failure() {
		$provider = $this->provider( array( array( 201, array() ) ) );

		$this->assertTrue( $provider->register( $this->order() )->is_failure() );
	}

	/**
	 * A refusal is a failure the shop can act on.
	 *
	 * @return void
	 */
	public function test_a_refusal_is_a_failure() {
		$provider = $this->provider( array( array( 422, array( 'message' => 'apm not found' ) ) ) );

		$result = $provider->register( $this->order() );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( 'apm not found', $result->get_message() );
	}

	/**
	 * Cleveron says which field it disliked in extraData and puts only
	 * "wrong_data" in the message. The field is the half a shopkeeper can act
	 * on, so it is the half they are shown.
	 *
	 * @return void
	 */
	public function test_a_refusal_names_the_field_cleveron_disliked() {
		$provider = $this->provider(
			array(
				array(
					400,
					array(
						'code'      => 11,
						'message'   => 'wrong_data',
						'extraData' => array(
							array( 'field' => 'destination.apm', 'message' => 'Unknown destination.apm' ),
						),
					),
				),
			)
		);

		$message = $provider->register( $this->order() )->get_message();

		$this->assertStringContainsString( 'destination.apm', $message );
		$this->assertStringContainsString( 'Unknown destination.apm', $message );
	}

	/**
	 * Several complaints are all shown, not just the first.
	 *
	 * @return void
	 */
	public function test_every_complaint_is_shown() {
		$provider = $this->provider(
			array(
				array(
					400,
					array(
						'message'   => 'wrong_data',
						'extraData' => array(
							array( 'field' => 'service', 'message' => 'Value must not be null' ),
							array( 'field' => 'destination', 'message' => 'Value must not be null' ),
						),
					),
				),
			)
		);

		$message = $provider->register( $this->order() )->get_message();

		$this->assertStringContainsString( 'service', $message );
		$this->assertStringContainsString( 'destination', $message );
	}

	/**
	 * There is no update path. The old version re-sent an already-sent order
	 * as a PUT; this refuses instead, because a review found the browser back
	 * button registering and billing a second real parcel. Recorded here so
	 * the next reader knows it was decided rather than forgotten.
	 *
	 * @return void
	 */
	public function test_there_is_no_update_path() {
		$this->assertFalse( method_exists( 'WC_ESM_Provider_Cleveron', 'update' ) );

		$provider = $this->provider( array( array( 201, array( 'id' => 'cl-1' ) ) ) );
		$provider->register( $this->order() );

		$this->assertSame( 'orders', $provider->fake->endpoint() );
	}
}
