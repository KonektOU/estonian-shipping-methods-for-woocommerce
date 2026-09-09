<?php
/**
 * Tell the customer where the parcel is.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

require_once WC_ESM_PLUGIN_DIR . '/includes/shipments/class-wc-esm-shipment-tracking.php';

/**
 * Tests for WC_ESM_Shipment_Tracking.
 */
class Test_Shipment_Tracking extends WC_ESM_Test_Case {

	/**
	 * The base test case stubs esc_html() and esc_url() as passthroughs,
	 * which would make an escaping assertion prove nothing. These tests care
	 * about exactly that, so here they do what WordPress does.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Brain\Monkey\Functions\when( 'esc_html' )->alias(
			static function ( $text ) {
				return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
			}
		);

		Brain\Monkey\Functions\when( 'esc_url' )->alias(
			static function ( $url ) {
				return str_replace( '&', '&amp;', (string) $url );
			}
		);
	}

	/**
	 * What a rendered sentence has to work from.
	 *
	 * @param array $overrides Overrides.
	 *
	 * @return array
	 */
	protected function context( $overrides = array() ) {
		return array_merge(
			array(
				'code'    => '00364300487158212149',
				'url'     => 'https://itella.ee/jalgimine?trackingCode=00364300487158212149',
				'carrier' => 'Smartposti',
			),
			$overrides
		);
	}

	/**
	 * The placeholders a shop can put in the template are filled in.
	 *
	 * @return void
	 */
	public function test_the_placeholders_are_filled_in() {
		$sentence = WC_ESM_Shipment_Tracking::render( '{carrier}: {tracking_code} at {tracking_url}', $this->context() );

		$this->assertSame(
			'Smartposti: 00364300487158212149 at https://itella.ee/jalgimine?trackingCode=00364300487158212149',
			$sentence
		);
	}

	/**
	 * The link placeholder becomes an anchor a customer can click.
	 *
	 * @return void
	 */
	public function test_the_link_placeholder_becomes_an_anchor() {
		$sentence = WC_ESM_Shipment_Tracking::render( 'Track it: {tracking_link}', $this->context() );

		$this->assertStringContainsString( '<a href="https://itella.ee/jalgimine?trackingCode=00364300487158212149"', $sentence );
		$this->assertStringContainsString( '>00364300487158212149</a>', $sentence );
	}

	/**
	 * A carrier that tracks nothing has no URL, and the link falls back to
	 * the bare code rather than an anchor pointing nowhere.
	 *
	 * @return void
	 */
	public function test_no_url_means_no_anchor() {
		$sentence = WC_ESM_Shipment_Tracking::render( 'Track it: {tracking_link}', $this->context( array( 'url' => '' ) ) );

		$this->assertStringNotContainsString( '<a', $sentence );
		$this->assertStringContainsString( '00364300487158212149', $sentence );
	}

	/**
	 * A parcel with no barcode yet has nothing to say, and says nothing -
	 * an order e-mail sent before the carrier answered must not carry an
	 * empty "your parcel is on its way" block.
	 *
	 * @return void
	 */
	public function test_no_barcode_says_nothing_at_all() {
		$this->assertSame( '', WC_ESM_Shipment_Tracking::render( 'Track it: {tracking_link}', $this->context( array( 'code' => '' ) ) ) );
	}

	/**
	 * A shop that emptied the template says nothing either.
	 *
	 * @return void
	 */
	public function test_an_empty_template_says_nothing() {
		$this->assertSame( '', WC_ESM_Shipment_Tracking::render( '', $this->context() ) );
		$this->assertSame( '', WC_ESM_Shipment_Tracking::render( '   ', $this->context() ) );
	}

	/**
	 * The plain-text version of an e-mail carries no markup: a customer
	 * reading it sees a URL, not an anchor tag.
	 *
	 * @return void
	 */
	public function test_the_plain_text_version_carries_no_markup() {
		$sentence = WC_ESM_Shipment_Tracking::render( 'Track it: {tracking_link}', $this->context(), true );

		$this->assertStringNotContainsString( '<a', $sentence );
		$this->assertStringContainsString( 'https://itella.ee/jalgimine?trackingCode=00364300487158212149', $sentence );
	}

	/**
	 * And no entities either. A URL with an ampersand in it reads as an
	 * ampersand in plain text; "&amp;" in a text e-mail is a bug a customer
	 * sees.
	 *
	 * @return void
	 */
	public function test_the_plain_text_version_carries_no_entities() {
		$sentence = WC_ESM_Shipment_Tracking::render(
			'Track it: {tracking_url}',
			$this->context( array( 'url' => 'https://tracking.dpd.de/status?query=P1&locale=et_EE' ) ),
			true
		);

		$this->assertStringContainsString( 'query=P1&locale=et_EE', $sentence );
		$this->assertStringNotContainsString( '&amp;', $sentence );
	}

	/**
	 * An apostrophe in a carrier's name is an apostrophe in plain text.
	 *
	 * @return void
	 */
	public function test_an_apostrophe_survives_plain_text() {
		$sentence = WC_ESM_Shipment_Tracking::render( '{carrier}', $this->context( array( 'carrier' => "Bob's Parcels" ) ), true );

		$this->assertSame( "Bob's Parcels", $sentence );
	}

	/**
	 * The HTML version escapes what came from a carrier: a name or a code is
	 * data, and data does not get to write markup.
	 *
	 * @return void
	 */
	public function test_the_html_version_escapes_carrier_data() {
		$sentence = WC_ESM_Shipment_Tracking::render( '{carrier}', $this->context( array( 'carrier' => '<script>x</script>' ) ) );

		$this->assertStringNotContainsString( '<script>', $sentence );
	}

	/**
	 * Which e-mails a shop chose is a list, however it was stored.
	 *
	 * @return void
	 */
	public function test_the_chosen_emails_read_as_a_list() {
		$this->assertSame( array( 'customer_completed_order' ), WC_ESM_Shipment_Tracking::chosen_emails( 'customer_completed_order' ) );
		$this->assertSame(
			array( 'customer_completed_order', 'customer_on_hold_order' ),
			WC_ESM_Shipment_Tracking::chosen_emails( 'customer_completed_order,customer_on_hold_order' )
		);
		$this->assertSame( array(), WC_ESM_Shipment_Tracking::chosen_emails( '' ) );
		$this->assertSame( array( 'a', 'b' ), WC_ESM_Shipment_Tracking::chosen_emails( array( 'a', '', 'b' ) ) );
	}
}
