<?php
/**
 * Smartposti takes an order and gives back a barcode.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-order-snapshot.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-payload.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/payloads/class-wc-esm-payload-smartpost.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/providers/class-wc-esm-provider-smartpost.php';

/**
 * The provider with the network taken out: every request is recorded and the
 * answer is whatever the test put there.
 */
class WC_ESM_Smartpost_Test_Provider extends WC_ESM_Provider_Smartpost {

	/**
	 * Requests made.
	 *
	 * @var array
	 */
	public $requests = array();

	/**
	 * Answers to give, in order.
	 *
	 * @var array
	 */
	public $answers = array();

	/**
	 * Record the request and hand back the next prepared answer.
	 *
	 * @param string      $endpoint Endpoint.
	 * @param string|null $body     Request body.
	 * @param string      $method   HTTP method.
	 *
	 * @return array
	 */
	protected function request( $endpoint, $body = null, $method = 'POST' ) {
		$this->requests[] = compact( 'endpoint', 'body', 'method' );

		return array_shift( $this->answers );
	}
}

/**
 * Tests for WC_ESM_Provider_Smartpost.
 */
class Test_Provider_Smartpost extends WC_ESM_Test_Case {

	/**
	 * A provider that will answer with what the test prepared.
	 *
	 * @param array $answers Answers.
	 * @param array $settings Carrier settings.
	 *
	 * @return WC_ESM_Smartpost_Test_Provider
	 */
	protected function provider( $answers = array(), $settings = array() ) {
		$provider = new WC_ESM_Smartpost_Test_Provider();
		$provider->answers = $answers;
		$provider->set_settings( array_merge( array( 'api_key' => 'test-key' ), $settings ) );

		return $provider;
	}

	/**
	 * A carrier is known by an id that is written into orders, so it does
	 * not change even when the name on the box does.
	 *
	 * @return void
	 */
	public function test_the_id_outlives_the_rename() {
		$this->assertSame( 'smartpost', ( new WC_ESM_Provider_Smartpost() )->get_id() );
		$this->assertSame( 'Smartposti', ( new WC_ESM_Provider_Smartpost() )->get_title() );
	}

	/**
	 * Smartposti carries the shop's Smartposti methods and nobody else's.
	 *
	 * @return void
	 */
	public function test_it_carries_its_own_methods() {
		$provider = new WC_ESM_Provider_Smartpost();

		foreach ( array( 'smartpost_estonia', 'smartpost_finland', 'smartpost_latvia', 'smartpost_lithuania', 'smartpost_courier' ) as $method ) {
			$this->assertTrue( $provider->carries( $method ), $method );
		}

		$this->assertFalse( $provider->carries( 'omniva_parcel_machines' ) );
		$this->assertFalse( $provider->carries( 'flat_rate' ) );
	}

	/**
	 * It prints labels and it tracks; it books no couriers and closes no
	 * manifests.
	 *
	 * @return void
	 */
	public function test_it_declares_what_it_offers() {
		$provider = new WC_ESM_Provider_Smartpost();

		$this->assertTrue( $provider->supports( 'labels' ) );
		$this->assertTrue( $provider->supports( 'tracking' ) );
		$this->assertFalse( $provider->supports( 'pickup' ) );
		$this->assertFalse( $provider->supports( 'manifest' ) );
	}

	/**
	 * A parcel Smartposti accepted comes back with its barcode.
	 *
	 * @return void
	 */
	public function test_an_accepted_parcel_comes_back_with_its_barcode() {
		$provider = $this->provider(
			array(
				array(
					'code' => 200,
					'body' => '{"orders":{"item":[{"barcode":"00364300487158212149"}]}}',
				),
			)
		);

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array( '00364300487158212149' ), $result->get( 'barcodes' ) );
	}

	/**
	 * The parcel is posted to the orders endpoint, as JSON.
	 *
	 * @return void
	 */
	public function test_the_parcel_is_posted_to_the_orders_endpoint() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => '{"orders":{"item":[{"barcode":"B1"}]}}' ) ) );
		$provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertSame( 'orders', $provider->requests[0]['endpoint'] );
		$this->assertSame( 'POST', $provider->requests[0]['method'] );
		$this->assertSame( '01007220', json_decode( $provider->requests[0]['body'], true )['orders']['item'][0]['destination']['place_id'] );
	}

	/**
	 * A carrier that answers with anything but 200 has refused the parcel,
	 * and the order must not end up looking sent.
	 *
	 * @return void
	 */
	public function test_a_refused_parcel_is_a_failure() {
		$provider = $this->provider( array( array( 'code' => 400, 'body' => 'Bad request' ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertStringContainsString( '400', $result->get_message() );
	}

	/**
	 * Smartposti answering 200 with no barcode in it is still a failure: the
	 * parcel cannot be printed or tracked, so calling it sent would strand
	 * the order.
	 *
	 * @return void
	 */
	public function test_a_200_without_a_barcode_is_a_failure() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => '{"orders":{"item":[{}]}}' ) ) );

		$result = $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) );

		$this->assertTrue( $result->is_failure() );
	}

	/**
	 * A body that is not JSON at all is a failure rather than a fatal.
	 *
	 * @return void
	 */
	public function test_a_body_that_is_not_json_is_a_failure() {
		$provider = $this->provider( array( array( 'code' => 200, 'body' => '<html>maintenance</html>' ) ) );

		$this->assertTrue( $provider->register( WC_ESM_Order_Snapshot::make( $this->snapshot() ) )->is_failure() );
	}

	/**
	 * Labels are fetched by barcode, in the format the shop chose, one
	 * barcode parameter each.
	 *
	 * @return void
	 */
	public function test_labels_are_fetched_by_barcode() {
		$provider = $this->provider(
			array( array( 'code' => 200, 'body' => '%PDF-1.4 label' ) ),
			array( 'label_format' => 'A6' )
		);

		$result = $provider->fetch_labels( array( 'B1', 'B2' ) );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( '%PDF-1.4 label', $result->get( 'pdf' ) );
		$this->assertSame( 'GET', $provider->requests[0]['method'] );
		$this->assertStringContainsString( 'format=A6', $provider->requests[0]['endpoint'] );
		$this->assertStringContainsString( 'barcode=B1&barcode=B2', $provider->requests[0]['endpoint'] );
	}

	/**
	 * Asking for labels with no barcodes asks the carrier nothing.
	 *
	 * @return void
	 */
	public function test_no_barcodes_asks_the_carrier_nothing() {
		$provider = $this->provider();

		$this->assertTrue( $provider->fetch_labels( array() )->is_failure() );
		$this->assertSame( array(), $provider->requests );
	}

	/**
	 * A customer can follow the parcel by its barcode.
	 *
	 * @return void
	 */
	public function test_a_customer_can_follow_the_parcel() {
		$url = ( new WC_ESM_Provider_Smartpost() )->get_tracking_url( '00364300487158212149' );

		$this->assertStringContainsString( '00364300487158212149', $url );
		$this->assertStringStartsWith( 'https://', $url );
	}

	/**
	 * A parcel with no barcode has nowhere to be followed, and the caller is
	 * told so with an empty string rather than a broken link.
	 *
	 * @return void
	 */
	public function test_no_barcode_means_no_tracking_link() {
		$this->assertSame( '', ( new WC_ESM_Provider_Smartpost() )->get_tracking_url( '' ) );
	}
}
