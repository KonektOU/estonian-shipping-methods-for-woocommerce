<?php
/**
 * The phone a carrier texts the pickup code to.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/class-wc-esm-checkout-phone.php';

/**
 * Tests for WC_ESM_Checkout_Phone.
 */
class Test_Checkout_Phone extends WC_ESM_Test_Case {

	/**
	 * A method that needs a phone and was given none has a missing phone.
	 * DPD texts the pickup code, and refuses a parcel shop delivery without
	 * a number to text it to.
	 *
	 * @return void
	 */
	public function test_a_required_phone_that_is_empty_is_missing() {
		$this->assertSame( WC_ESM_Checkout_Phone::MISSING, WC_ESM_Checkout_Phone::problem( '', true ) );
		$this->assertSame( WC_ESM_Checkout_Phone::MISSING, WC_ESM_Checkout_Phone::problem( '   ', true ) );
	}

	/**
	 * A method that does not need a phone lets an empty one through.
	 *
	 * @return void
	 */
	public function test_an_optional_phone_may_be_empty() {
		$this->assertSame( '', WC_ESM_Checkout_Phone::problem( '', false ) );
	}

	/**
	 * A phone without a country prefix is the problem the classic checkout
	 * already rejects, and it is rejected the same way here.
	 *
	 * @return void
	 */
	public function test_a_phone_without_a_country_prefix_is_rejected() {
		$this->assertSame( WC_ESM_Checkout_Phone::NO_PREFIX, WC_ESM_Checkout_Phone::problem( '55512345', true ) );
	}

	/**
	 * A phone with its prefix is fine, spaces and all.
	 *
	 * @return void
	 */
	public function test_a_prefixed_phone_is_fine() {
		$this->assertSame( '', WC_ESM_Checkout_Phone::problem( '+372 5551 2345', true ) );
	}

	/**
	 * Each problem has words a customer can act on, and no problem has none.
	 *
	 * @return void
	 */
	public function test_each_problem_has_a_message() {
		$this->assertStringContainsString( 'phone', strtolower( WC_ESM_Checkout_Phone::message( WC_ESM_Checkout_Phone::MISSING ) ) );
		$this->assertStringContainsString( '+372', WC_ESM_Checkout_Phone::message( WC_ESM_Checkout_Phone::NO_PREFIX ) );
		$this->assertSame( '', WC_ESM_Checkout_Phone::message( '' ) );
	}

	/**
	 * A customer who gave a phone only for delivery is still reachable.
	 *
	 * @return void
	 */
	public function test_the_first_phone_given_is_the_one_used() {
		$this->assertSame( '+3725001', WC_ESM_Checkout_Phone::pick( '+3725001', '+3726002' ) );
		$this->assertSame( '+3726002', WC_ESM_Checkout_Phone::pick( '', '+3726002' ) );
		$this->assertSame( '', WC_ESM_Checkout_Phone::pick( '', '' ) );
	}
}
