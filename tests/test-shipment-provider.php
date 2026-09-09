<?php
/**
 * What every carrier does, and what only some of them do.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-result.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/abstracts/class-wc-esm-shipment-provider.php';

/**
 * A carrier that carries cash on delivery and takes returns.
 */
class WC_ESM_Test_Provider extends WC_ESM_Shipment_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'cod', 'return' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'testcarrier';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Test Carrier';
	}

	/**
	 * Which methods this carries.
	 *
	 * @param string $method_id Method id.
	 *
	 * @return bool
	 */
	public function carries( $method_id ) {
		return in_array( $method_id, array( 'testcarrier_terminal', 'testcarrier_courier' ), true );
	}

	/**
	 * Settings fields.
	 *
	 * @return array
	 */
	public function get_settings_fields() {
		return array(
			'api_key' => array(
				'title'   => 'API key',
				'type'    => 'password',
				'default' => '',
			),
		);
	}
}

/**
 * A carrier that declares nothing at all, so the abstract class's own
 * answers are what shows through.
 */
class WC_ESM_Bare_Test_Provider extends WC_ESM_Shipment_Provider {

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'bare';
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return 'Bare Carrier';
	}
}

/**
 * A carrier offering pickups and manifests.
 */
class WC_ESM_Dispatch_Test_Provider extends WC_ESM_Test_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'pickup', 'manifest' );

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'withmanifest';
	}
}

/**
 * A carrier offering nothing beyond the label.
 */
class WC_ESM_Plain_Test_Provider extends WC_ESM_Test_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array();

	/**
	 * Id.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'plain';
	}
}

/**
 * A carrier that offers everything.
 */
class WC_ESM_Full_Feature_Test_Provider extends WC_ESM_Test_Provider {

	/**
	 * Declared features.
	 *
	 * @var array
	 */
	protected $features = array( 'cod', 'return', 'labels', 'tracking' );
}

/**
 * Tests for WC_ESM_Shipment_Provider.
 */
class Test_Shipment_Provider extends WC_ESM_Test_Case {

	/**
	 * A carrier answers for the features it declared and no others.
	 *
	 * @return void
	 */
	public function test_a_carrier_answers_for_what_it_declared() {
		$provider = new WC_ESM_Test_Provider();

		$this->assertTrue( $provider->supports( 'cod' ) );
		$this->assertTrue( $provider->supports( 'return' ) );
		$this->assertFalse( $provider->supports( 'pickup' ) );
		$this->assertFalse( $provider->supports( 'labels' ) );
	}

	/**
	 * A capability nobody has heard of is not supported, rather than an error.
	 *
	 * @return void
	 */
	public function test_an_unheard_of_capability_is_not_supported() {
		$this->assertFalse( ( new WC_ESM_Test_Provider() )->supports( 'teleportation' ) );
	}

	/**
	 * A carrier that declares nothing supports nothing.
	 *
	 * @return void
	 */
	public function test_a_carrier_declaring_nothing_supports_nothing() {
		$provider = new WC_ESM_Bare_Test_Provider();

		foreach ( array( 'cod', 'cod_report', 'return', 'labels', 'tracking', 'pickup', 'manifest' ) as $feature ) {
			$this->assertFalse( $provider->supports( $feature ), $feature );
		}
	}

	/**
	 * Settings are pushed in and read back one key at a time.
	 *
	 * @return void
	 */
	public function test_settings_are_pushed_in_and_read_back() {
		$provider = new WC_ESM_Test_Provider();
		$provider->set_settings( array( 'api_key' => 'secret' ) );

		$this->assertSame( 'secret', $provider->get_setting( 'api_key' ) );
	}

	/**
	 * A setting the shop never filled in reads as the default.
	 *
	 * @return void
	 */
	public function test_a_missing_setting_reads_as_the_default() {
		$provider = new WC_ESM_Test_Provider();

		$this->assertSame( '', $provider->get_setting( 'api_key' ) );
		$this->assertSame( 'omx.omniva.eu', $provider->get_setting( 'tenant', 'omx.omniva.eu' ) );
	}

	/**
	 * A carrier says which of the plugin's shipping methods it carries.
	 *
	 * @return void
	 */
	public function test_a_carrier_says_which_methods_it_carries() {
		$provider = new WC_ESM_Test_Provider();

		$this->assertTrue( $provider->carries( 'testcarrier_terminal' ) );
		$this->assertFalse( $provider->carries( 'omniva_parcel_machines' ) );
	}

	/**
	 * A carrier that says nothing about methods carries none, rather than
	 * claiming every method in the shop.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_says_nothing_carries_nothing() {
		$this->assertFalse( ( new WC_ESM_Bare_Test_Provider() )->carries( 'testcarrier_terminal' ) );
	}

	/**
	 * Asking a carrier for a capability it does not declare answers with the
	 * unsupported result, not with a fatal or a silent empty array.
	 *
	 * @return void
	 */
	public function test_an_undeclared_capability_answers_unsupported() {
		$provider = new WC_ESM_Test_Provider();

		foreach ( array(
			'fetch_labels'   => array( array( 'ref-1' ) ),
			'request_pickup' => array( array() ),
			'cancel_pickup'  => array( 'P-1' ),
			'close_manifest' => array( array( 'ref-1' ) ),
			'fetch_manifest' => array( 'M-1' ),
		) as $method => $args ) {
			$result = $provider->{$method}( ...$args );

			$this->assertTrue( $result->is_failure(), $method );
			$this->assertSame( 'unsupported', $result->get_code(), $method );
		}
	}

	/**
	 * A carrier that tracks nothing hands back an empty string, so a caller
	 * building a link can tell there is none. The order metabox and the
	 * order note both fall back to plain text on this.
	 *
	 * @return void
	 */
	public function test_a_carrier_that_tracks_nothing_gives_an_empty_url() {
		$this->assertSame( '', ( new WC_ESM_Test_Provider() )->get_tracking_url( '00364300487158212149' ) );
	}

	/**
	 * A carrier that declared tracking is expected to build a URL; the
	 * abstract class does not invent one for it.
	 *
	 * @return void
	 */
	public function test_declaring_tracking_does_not_conjure_a_url() {
		$this->assertSame( '', ( new WC_ESM_Full_Feature_Test_Provider() )->get_tracking_url( '123' ) );
	}

	/**
	 * A carrier with no fields of its own still answers with an array, so
	 * the settings screen can merge into it.
	 *
	 * @return void
	 */
	public function test_a_carrier_with_no_fields_answers_with_an_array() {
		$this->assertSame( array(), ( new WC_ESM_Bare_Test_Provider() )->get_settings_fields() );
	}
}
