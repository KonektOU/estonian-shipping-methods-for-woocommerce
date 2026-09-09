<?php
/**
 * Carrier settings.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-settings.php';
require_once __DIR__ . '/test-shipment-provider.php';
require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-registry.php';

/**
 * Tests for WC_ESM_Shipment_Settings.
 */
class Test_Shipment_Settings extends WC_ESM_Test_Case {

	/**
	 * Stub the options store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wc_esm_options'] = array(
			'wc_esm_settings_testcarrier' => array(
				'api_key'             => 'stored-key',
				'registration_status' => 'completed',
			),
		);

		Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return isset( $GLOBALS['wc_esm_options'][ $key ] ) ? $GLOBALS['wc_esm_options'][ $key ] : $default;
			}
		);

		// fields_for() builds the status dropdown out of this.
		Brain\Monkey\Functions\when( 'wc_get_order_statuses' )->justReturn(
			array(
				'wc-processing' => 'Processing',
				'wc-completed'  => 'Completed',
			)
		);
	}

	/**
	 * A registry with one carrier in it.
	 *
	 * @return WC_ESM_Shipment_Registry
	 */
	protected function registry() {
		$registry = new WC_ESM_Shipment_Registry();
		$registry->register( new WC_ESM_Test_Provider() );

		return $registry;
	}

	/**
	 * Each carrier gets its own option row.
	 *
	 * @return void
	 */
	public function test_each_carrier_has_its_own_option() {
		$this->assertSame( 'wc_esm_settings_testcarrier', WC_ESM_Shipment_Settings::option_key( 'testcarrier' ) );
		$this->assertSame( 'wc_esm_settings_dpd', WC_ESM_Shipment_Settings::option_key( 'dpd' ) );
	}

	/**
	 * Stored settings are read back, and an unconfigured carrier reads as empty.
	 *
	 * @return void
	 */
	public function test_stored_settings_are_read_back() {
		$this->assertSame( 'stored-key', WC_ESM_Shipment_Settings::settings_for( 'testcarrier' )['api_key'] );
		$this->assertSame( array(), WC_ESM_Shipment_Settings::settings_for( 'dpd' ) );
	}

	/**
	 * Hydrating pushes each carrier's own row into it and nobody else's.
	 *
	 * @return void
	 */
	public function test_hydrating_fills_the_providers() {
		$registry = $this->registry();
		WC_ESM_Shipment_Settings::hydrate( $registry );

		$this->assertSame( 'stored-key', $registry->get_provider( 'testcarrier' )->get_setting( 'api_key' ) );
	}

	/**
	 * The trigger status is read from the carrier's own settings.
	 *
	 * @return void
	 */
	public function test_the_registration_status_is_a_setting() {
		$this->assertSame( 'completed', WC_ESM_Shipment_Settings::registration_status( 'testcarrier' ) );
	}

	/**
	 * A carrier that never chose one sends nothing automatically.
	 *
	 * @return void
	 */
	public function test_a_carrier_without_a_status_sends_nothing_automatically() {
		$this->assertSame( '', WC_ESM_Shipment_Settings::registration_status( 'dpd' ) );
	}

	/**
	 * The fields every carrier gets are appended to the carrier's own.
	 *
	 * @return void
	 */
	public function test_the_shared_fields_are_appended_to_every_carrier() {
		$fields = WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() );

		$this->assertArrayHasKey( 'api_key', $fields );
		$this->assertArrayHasKey( 'registration_status', $fields );
		$this->assertArrayHasKey( 'tracking_template', $fields );
		$this->assertArrayHasKey( 'tracking_emails', $fields );
	}

	/**
	 * "Never" leads the trigger options, so a carrier nobody configured
	 * cannot send anything by accident.
	 *
	 * @return void
	 */
	public function test_never_leads_the_trigger_options() {
		$options = WC_ESM_Shipment_Settings::fields_for( new WC_ESM_Test_Provider() )['registration_status']['options'];

		$this->assertSame( '', array_key_first( $options ) );
		$this->assertArrayHasKey( 'completed', $options );
		$this->assertArrayNotHasKey( 'wc-completed', $options );
	}

	/**
	 * Each carrier gets a section of its own, named after the carrier.
	 *
	 * @return void
	 */
	public function test_each_carrier_gets_its_own_section() {
		$sections = WC_ESM_Shipment_Settings::add_section( array(), $this->registry() );

		$this->assertArrayHasKey( 'wc_esm_testcarrier', $sections );
		$this->assertSame( 'Test Carrier', $sections['wc_esm_testcarrier'] );
	}

	/**
	 * Sections another plugin added are left alone.
	 *
	 * @return void
	 */
	public function test_other_sections_survive() {
		$sections = WC_ESM_Shipment_Settings::add_section( array( 'options' => 'Options' ), $this->registry() );

		$this->assertSame( 'Options', $sections['options'] );
	}

	/**
	 * A section id resolves back to the carrier that owns it.
	 *
	 * @return void
	 */
	public function test_a_section_resolves_to_its_carrier() {
		$registry = $this->registry();

		$this->assertSame( 'testcarrier', WC_ESM_Shipment_Settings::provider_for_section( 'wc_esm_testcarrier', $registry )->get_id() );
		$this->assertNull( WC_ESM_Shipment_Settings::provider_for_section( 'shipping_options', $registry ) );
		$this->assertNull( WC_ESM_Shipment_Settings::provider_for_section( '', $registry ) );
	}

	/**
	 * A carrier's screen carries that carrier's fields, opened and closed the
	 * way WooCommerce expects.
	 *
	 * @return void
	 */
	public function test_a_section_carries_only_its_own_carriers_fields() {
		$fields = WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() );
		$ids    = array_filter( wp_list_pluck( $fields, 'id' ) );

		$this->assertContains( 'wc_esm_testcarrier_api_key', $ids );
		$this->assertContains( 'wc_esm_testcarrier_registration_status', $ids );
		$this->assertSame( 'title', $fields[0]['type'] );
		$this->assertSame( 'sectionend', $fields[ count( $fields ) - 1 ]['type'] );
	}

	/**
	 * A stored value is what the screen shows; a field never saved shows its
	 * default.
	 *
	 * @return void
	 */
	public function test_the_screen_shows_stored_values_and_defaults() {
		$fields = WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() );
		$values = array();

		foreach ( $fields as $field ) {
			if ( isset( $field['id'] ) ) {
				$values[ $field['id'] ] = isset( $field['value'] ) ? $field['value'] : null;
			}
		}

		$this->assertSame( 'stored-key', $values['wc_esm_testcarrier_api_key'] );
		$this->assertSame( 'completed', $values['wc_esm_testcarrier_registration_status'] );
		$this->assertStringContainsString( '{tracking_link}', $values['wc_esm_testcarrier_tracking_template'] );
	}

	/**
	 * A multiselect is stored as one comma-separated string and handed to
	 * WooCommerce as a list.
	 *
	 * @return void
	 */
	public function test_a_multiselect_reaches_the_screen_as_a_list() {
		$GLOBALS['wc_esm_options']['wc_esm_settings_testcarrier']['tracking_emails'] = 'customer_completed_order,customer_on_hold_order';

		foreach ( WC_ESM_Shipment_Settings::get_settings( array(), 'wc_esm_testcarrier', $this->registry() ) as $field ) {
			if ( isset( $field['id'] ) && 'wc_esm_testcarrier_tracking_emails' === $field['id'] ) {
				$this->assertSame( array( 'customer_completed_order', 'customer_on_hold_order' ), $field['value'] );

				return;
			}
		}

		$this->fail( 'The tracking e-mails field was not on the screen.' );
	}

	/**
	 * A section this class does not own is handed back untouched.
	 *
	 * @return void
	 */
	public function test_a_foreign_section_is_untouched() {
		$this->assertSame(
			array( 'existing' ),
			WC_ESM_Shipment_Settings::get_settings( array( 'existing' ), 'shipping_options', $this->registry() )
		);
	}
}
