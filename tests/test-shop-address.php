<?php
/**
 * The shop's own address, as a carrier's sender.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shop-address.php';

/**
 * Tests for WC_ESM_Shop_Address.
 */
class Test_Shop_Address extends WC_ESM_Test_Case {

	/**
	 * Stand WooCommerce's base-address accessors and the options store up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$countries = new class() {
			/**
			 * Street line.
			 *
			 * @return string
			 */
			public function get_base_address() {
				return 'J. Smuuli tee 43';
			}

			/**
			 * City.
			 *
			 * @return string
			 */
			public function get_base_city() {
				return 'Tallinn';
			}

			/**
			 * Postcode.
			 *
			 * @return string
			 */
			public function get_base_postcode() {
				return '11415';
			}

			/**
			 * Country.
			 *
			 * @return string
			 */
			public function get_base_country() {
				return 'EE';
			}
		};

		$woocommerce                    = new stdClass();
		$woocommerce->countries         = $countries;
		$GLOBALS['wc_esm_shop_options'] = array(
			'woocommerce_email_from_address' => 'pood@example.com',
			'admin_email'                    => 'admin@example.com',
		);

		Brain\Monkey\Functions\when( 'WC' )->justReturn( $woocommerce );
		Brain\Monkey\Functions\when( 'get_bloginfo' )->justReturn( 'Testpood' );
		Brain\Monkey\Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return isset( $GLOBALS['wc_esm_shop_options'][ $key ] ) ? $GLOBALS['wc_esm_shop_options'][ $key ] : $default;
			}
		);
	}

	/**
	 * The common Estonian shape: a street, then the house number.
	 *
	 * @return void
	 */
	public function test_a_trailing_number_is_the_house_number() {
		$this->assertSame(
			array(
				'street' => 'Pikk',
				'house'  => '12',
			),
			WC_ESM_Shop_Address::split_street( 'Pikk 12' )
		);
	}

	/**
	 * A street whose own name carries dots and several words keeps all of it.
	 *
	 * @return void
	 */
	public function test_a_multi_word_street_keeps_its_whole_name() {
		$this->assertSame(
			array(
				'street' => 'J. Smuuli tee',
				'house'  => '43',
			),
			WC_ESM_Shop_Address::split_street( 'J. Smuuli tee 43' )
		);
	}

	/**
	 * House numbers are not plain integers: 12a and 24/2 are both addresses.
	 *
	 * @return void
	 */
	public function test_a_house_number_may_carry_a_letter_or_a_slash() {
		$this->assertSame( '12a', WC_ESM_Shop_Address::split_street( 'Pikk 12a' )['house'] );
		$this->assertSame( '24/2', WC_ESM_Shop_Address::split_street( 'Tartu mnt 24/2' )['house'] );
	}

	/**
	 * An address with no number at all is all street: guessing a house number
	 * out of nothing would be worse than leaving it for a human.
	 *
	 * @return void
	 */
	public function test_a_line_without_a_number_is_all_street() {
		$this->assertSame(
			array(
				'street' => 'Kesklinna küla',
				'house'  => '',
			),
			WC_ESM_Shop_Address::split_street( 'Kesklinna küla' )
		);
	}

	/**
	 * A shop that never filled its address in yields two empty strings, not
	 * a warning.
	 *
	 * @return void
	 */
	public function test_an_empty_line_yields_empty_parts() {
		$this->assertSame(
			array(
				'street' => '',
				'house'  => '',
			),
			WC_ESM_Shop_Address::split_street( '' )
		);
	}

	/**
	 * Stray whitespace is not part of the address.
	 *
	 * @return void
	 */
	public function test_surrounding_whitespace_is_dropped() {
		$this->assertSame(
			array(
				'street' => 'Pikk',
				'house'  => '12',
			),
			WC_ESM_Shop_Address::split_street( '  Pikk   12  ' )
		);
	}

	/**
	 * The shop's address, in the keys a carrier's sender fields use.
	 *
	 * @return void
	 */
	public function test_the_shop_address_becomes_the_sender() {
		$this->assertSame(
			array(
				'sender_name'     => 'Testpood',
				'sender_phone'    => '',
				'sender_email'    => 'pood@example.com',
				'sender_street'   => 'J. Smuuli tee',
				'sender_house'    => '43',
				'sender_postcode' => '11415',
				'sender_city'     => 'Tallinn',
				'sender_country'  => 'EE',
			),
			WC_ESM_Shop_Address::sender_defaults()
		);
	}

	/**
	 * WooCommerce keeps no shop telephone number anywhere, so that one field
	 * is left for a human rather than filled with something invented.
	 *
	 * @return void
	 */
	public function test_the_sender_phone_is_left_for_a_human() {
		$this->assertSame( '', WC_ESM_Shop_Address::sender_defaults()['sender_phone'] );
	}

	/**
	 * A shop that has not set a from-address falls back to the admin's.
	 *
	 * @return void
	 */
	public function test_the_sender_email_falls_back_to_the_admin_address() {
		unset( $GLOBALS['wc_esm_shop_options']['woocommerce_email_from_address'] );

		$this->assertSame( 'admin@example.com', WC_ESM_Shop_Address::sender_defaults()['sender_email'] );
	}

	/**
	 * The fields a carrier splices in are the sender keys, already carrying
	 * the shop's own data as their defaults.
	 *
	 * @return void
	 */
	public function test_the_fields_carry_the_shop_data_as_defaults() {
		$fields = WC_ESM_Shop_Address::fields();

		$this->assertSame( array_keys( WC_ESM_Shop_Address::sender_defaults() ), array_keys( $fields ) );
		$this->assertSame( 'Tallinn', $fields['sender_city']['default'] );
		$this->assertSame( 'sender', $fields['sender_city']['group'] );
	}
}
