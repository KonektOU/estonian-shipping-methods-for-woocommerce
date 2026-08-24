<?php
/**
 * Cleveron Office description on the checkout.
 *
 * This template can be overridden by copying it to
 * yourtheme/woocommerce/checkout/form-shipping-cleveron-office.php.
 *
 * @package Estonian_Shipping_Methods_For_WooCommerce
 *
 * @var string $description The zone's description.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<tr class="wc-estonian-shipping-methods cleveron-office-description">
	<td colspan="2">
		<?php echo wp_kses_post( wpautop( $description ) ); ?>
	</td>
</tr>
