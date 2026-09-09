<?php
/**
 * What a carrier call answers with.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';

/**
 * Tests for WC_ESM_Shipment_Result.
 */
class Test_Shipment_Result extends WC_ESM_Test_Case {

	/**
	 * The two outcomes are told apart without reading a message.
	 *
	 * @return void
	 */
	public function test_a_success_and_a_failure_are_told_apart() {
		$this->assertTrue( WC_ESM_Shipment_Result::success()->is_success() );
		$this->assertFalse( WC_ESM_Shipment_Result::success()->is_failure() );
		$this->assertFalse( WC_ESM_Shipment_Result::failure( 'No.' )->is_success() );
		$this->assertTrue( WC_ESM_Shipment_Result::failure( 'No.' )->is_failure() );
	}

	/**
	 * A failure carries what to tell the shopkeeper and what the code was.
	 *
	 * @return void
	 */
	public function test_a_failure_carries_its_message_and_code() {
		$result = WC_ESM_Shipment_Result::failure( 'Omniva refused the parcel.', 'rejected' );

		$this->assertSame( 'Omniva refused the parcel.', $result->get_message() );
		$this->assertSame( 'rejected', $result->get_code() );
	}

	/**
	 * A failure without a code of its own still has one, so a caller reading
	 * the code never has to handle an empty string.
	 *
	 * @return void
	 */
	public function test_a_failure_without_a_code_still_has_one() {
		$this->assertSame( 'error', WC_ESM_Shipment_Result::failure( 'No.' )->get_code() );
	}

	/**
	 * A carrier asked for something it does not do says so in a way a caller
	 * can branch on, rather than failing like a network error.
	 *
	 * @return void
	 */
	public function test_asking_for_an_unsupported_feature_says_which() {
		$result = WC_ESM_Shipment_Result::unsupported( 'labels' );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'unsupported', $result->get_code() );
		$this->assertStringContainsString( 'labels', $result->get_message() );
	}

	/**
	 * What a carrier sent back is read by key.
	 *
	 * @return void
	 */
	public function test_a_success_carries_what_the_carrier_returned() {
		$result = WC_ESM_Shipment_Result::success(
			array(
				'barcodes' => array( '00364300487158212149' ),
				'label'    => 'ref-1',
			)
		);

		$this->assertSame( array( '00364300487158212149' ), $result->get( 'barcodes' ) );
		$this->assertSame( 'ref-1', $result->get( 'label' ) );
	}

	/**
	 * A key the carrier did not send reads as the default, not a warning.
	 *
	 * @return void
	 */
	public function test_a_missing_key_reads_as_the_default() {
		$result = WC_ESM_Shipment_Result::success( array( 'label' => 'ref-1' ) );

		$this->assertNull( $result->get( 'barcodes' ) );
		$this->assertSame( array(), $result->get( 'barcodes', array() ) );
	}

	/**
	 * The whole payload is available for a caller that wants to log it.
	 *
	 * @return void
	 */
	public function test_the_whole_payload_is_available() {
		$data = array( 'label' => 'ref-1' );

		$this->assertSame( $data, WC_ESM_Shipment_Result::success( $data )->get_data() );
	}

	/**
	 * A failure carries no data, so a caller that forgets to check the
	 * outcome reads nothing rather than something stale.
	 *
	 * @return void
	 */
	public function test_a_failure_carries_no_data() {
		$this->assertSame( array(), WC_ESM_Shipment_Result::failure( 'No.' )->get_data() );
	}
}
