<?php
/**
 * Tell the customer where the parcel is.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The tracking sentence, and the holes it fills.
 *
 * A shop writes one sentence with placeholders in it and chooses which
 * customer e-mails carry it. The same sentence appears under My account.
 *
 * Two things this is careful about. An order with no barcode yet renders
 * nothing at all rather than an empty block - order e-mails go out before the
 * carrier has answered, and "Your parcel is on its way. Track it here:" with
 * nothing after it is worse than silence. And the plain-text half of an
 * e-mail gets no markup and no entities: a customer reading the text version
 * should see an ampersand, not "&amp;".
 */
class WC_ESM_Shipment_Tracking {

	/**
	 * Fill a shop's template in.
	 *
	 * @param string $template   The shop's sentence.
	 * @param array  $context    code, url and carrier.
	 * @param bool   $plain_text Whether this is the text half of an e-mail.
	 *
	 * @return string Empty when there is nothing to say.
	 */
	public static function render( $template, $context, $plain_text = false ) {
		$template = trim( (string) $template );
		$code     = isset( $context['code'] ) ? (string) $context['code'] : '';

		// No barcode, nothing to say. Not an empty block, not a heading with
		// nothing under it: nothing.
		if ( '' === $template || '' === $code ) {
			return '';
		}

		$url     = isset( $context['url'] ) ? (string) $context['url'] : '';
		$carrier = isset( $context['carrier'] ) ? (string) $context['carrier'] : '';

		if ( $plain_text ) {
			return strtr(
				$template,
				array(
					'{tracking_code}' => $code,
					'{tracking_url}'  => $url,
					'{tracking_link}' => '' !== $url ? $url : $code,
					'{carrier}'       => $carrier,
				)
			);
		}

		return strtr(
			$template,
			array(
				'{tracking_code}' => esc_html( $code ),
				'{tracking_url}'  => esc_url( $url ),
				'{tracking_link}' => self::link( $code, $url ),
				'{carrier}'       => esc_html( $carrier ),
			)
		);
	}

	/**
	 * The code as a link, or as itself.
	 *
	 * A carrier that tracks nothing hands back no URL, and an anchor with an
	 * empty href is a link to the page the customer is already on.
	 *
	 * @param string $code Barcode.
	 * @param string $url  Where to follow it.
	 *
	 * @return string
	 */
	protected static function link( $code, $url ) {
		if ( '' === $url ) {
			return esc_html( $code );
		}

		return sprintf(
			'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
			esc_url( $url ),
			esc_html( $code )
		);
	}

	/**
	 * Which customer e-mails the shop chose.
	 *
	 * Stored as one comma-separated string, but an array is accepted too so
	 * a filter can hand one back.
	 *
	 * @param string|array $stored The setting.
	 *
	 * @return array
	 */
	public static function chosen_emails( $stored ) {
		if ( is_array( $stored ) ) {
			return array_values( array_filter( $stored ) );
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $stored ) ) ) );
	}

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'woocommerce_email_order_details', array( __CLASS__, 'add_to_email' ), 20, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'add_to_order_page' ) );
	}

	/**
	 * Put the sentence in a customer e-mail, if the shop asked for it there.
	 *
	 * @param WC_Order $order         Order.
	 * @param bool     $sent_to_admin Whether this is an admin e-mail.
	 * @param bool     $plain_text    Whether this is the text half.
	 * @param WC_Email $email         The e-mail being sent.
	 *
	 * @return void
	 */
	public static function add_to_email( $order, $sent_to_admin, $plain_text = false, $email = null ) {
		if ( $sent_to_admin || ! $email ) {
			return;
		}

		$sentence = self::for_order( $order, $plain_text, $email->id );

		if ( '' === $sentence ) {
			return;
		}

		if ( $plain_text ) {
			// Deliberately not esc_html(): this is the text half, and every
			// escape here reaches the customer as an entity. render() has
			// already left the markup out.
			echo "\n" . $sentence . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text by design; see above.

			return;
		}

		echo '<p>' . $sentence . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
	}

	/**
	 * Put the sentence on the customer's own order page.
	 *
	 * @param WC_Order $order Order.
	 *
	 * @return void
	 */
	public static function add_to_order_page( $order ) {
		$sentence = self::for_order( $order );

		if ( '' === $sentence ) {
			return;
		}

		echo '<p class="wc-esm-tracking">' . $sentence . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render().
	}

	/**
	 * The sentence for one order, if there is one.
	 *
	 * @param WC_Order    $order      Order.
	 * @param bool        $plain_text Whether this is the text half of an e-mail.
	 * @param string|null $email_id   The e-mail being sent, when it is one.
	 *
	 * @return string
	 */
	public static function for_order( $order, $plain_text = false, $email_id = null ) {
		$provider = WC_ESM_Shipment_Registration::provider_for( $order );

		if ( ! $provider ) {
			return '';
		}

		$settings = WC_ESM_Shipment_Settings::settings_for( $provider->get_id() );

		if ( null !== $email_id && ! in_array( $email_id, self::chosen_emails( isset( $settings['tracking_emails'] ) ? $settings['tracking_emails'] : '' ), true ) ) {
			return '';
		}

		$barcodes = WC_ESM_Shipment::barcodes( $order );
		$code     = $barcodes ? reset( $barcodes ) : '';

		return self::render(
			isset( $settings['tracking_template'] ) ? $settings['tracking_template'] : '',
			array(
				'code'    => $code,
				'url'     => '' !== $code ? $provider->get_tracking_url( $code ) : '',
				'carrier' => $provider->get_title(),
			),
			$plain_text
		);
	}
}
