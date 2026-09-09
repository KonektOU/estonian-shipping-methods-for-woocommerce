<?php
/**
 * One DPD token, not one per label.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-dpd-token.php';

/**
 * Tests for WC_ESM_Dpd_Token.
 */
class Test_Dpd_Token extends WC_ESM_Test_Case {

	/**
	 * Stand a transient store up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wc_esm_transients'] = array();

		Brain\Monkey\Functions\when( 'get_transient' )->alias(
			static function ( $key ) {
				return isset( $GLOBALS['wc_esm_transients'][ $key ] ) ? $GLOBALS['wc_esm_transients'][ $key ] : false;
			}
		);

		Brain\Monkey\Functions\when( 'set_transient' )->alias(
			static function ( $key, $value ) {
				$GLOBALS['wc_esm_transients'][ $key ] = $value;

				return true;
			}
		);

		Brain\Monkey\Functions\when( 'delete_transient' )->alias(
			static function ( $key ) {
				unset( $GLOBALS['wc_esm_transients'][ $key ] );

				return true;
			}
		);
	}

	/**
	 * Two shops, or one shop whose credentials changed, must not share a
	 * cached token.
	 *
	 * @return void
	 */
	public function test_different_credentials_cache_separately() {
		$this->assertNotSame(
			WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ),
			WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'other' )
		);
		$this->assertNotSame(
			WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ),
			WC_ESM_Dpd_Token::cache_key( 'eserviss.dpd.lv', 'shop' )
		);
	}

	/**
	 * The same credentials come back to the same cache entry, or nothing
	 * would ever be reused.
	 *
	 * @return void
	 */
	public function test_the_same_credentials_share_one_entry() {
		$this->assertSame(
			WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ),
			WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' )
		);
	}

	/**
	 * A token is remembered, so a run of twenty labels logs in once rather
	 * than twenty times.
	 *
	 * @return void
	 */
	public function test_a_token_is_remembered() {
		$key = WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' );

		WC_ESM_Dpd_Token::remember( $key, 'tok-1' );

		$this->assertSame( 'tok-1', WC_ESM_Dpd_Token::stored( $key ) );
	}

	/**
	 * Nothing remembered reads as empty rather than as a token.
	 *
	 * @return void
	 */
	public function test_nothing_remembered_reads_as_empty() {
		$this->assertSame( '', WC_ESM_Dpd_Token::stored( WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' ) ) );
	}

	/**
	 * A token DPD has stopped accepting is thrown away, so the next call
	 * logs in again instead of retrying the same dead token forever.
	 *
	 * @return void
	 */
	public function test_a_rejected_token_is_thrown_away() {
		$key = WC_ESM_Dpd_Token::cache_key( 'telli.dpd.ee', 'shop' );
		WC_ESM_Dpd_Token::remember( $key, 'tok-1' );

		WC_ESM_Dpd_Token::forget( $key );

		$this->assertSame( '', WC_ESM_Dpd_Token::stored( $key ) );
	}
}
