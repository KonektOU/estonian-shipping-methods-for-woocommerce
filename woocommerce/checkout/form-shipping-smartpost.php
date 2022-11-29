<?php
/**
 * Smartpost terminals dropdown template
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-shipping-smartpost.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package Estonian_Shipping_Methods_For_WooCommerce
 * @version 7.1.0
 */

defined( 'ABSPATH' ) || exit;
?>

<tr class="wc_shipping_smartpost wc-esm-method--smartpost">
	<th><label for="<?php echo esc_attr( $field_id ); ?>"><?php esc_html_e( 'Choose terminal', 'wc-estonian-shipping-methods' ); ?></label></th>
	<td>
		<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_id ); ?>" class="<?php echo esc_attr( wc_esm_get_element_class_name( 'select' ) ); ?>">
			<option value="" <?php selected( $selected, '' ); ?>><?php echo esc_html_x( '- Choose terminal -', 'empty value label for terminals', 'wc-estonian-shipping-methods' ); ?></option>

			<?php foreach ( $terminals as $group_name => $locations ) : ?>
				<optgroup label="<?php echo esc_attr( $group_name ); ?>">
					<?php foreach ( $locations as $location ) : ?>
						<option value="<?php echo esc_attr( $location->place_id ); ?>" <?php selected( $selected, $location->place_id ); ?>><?php echo esc_html( $location->name ); ?></option>
					<?php endforeach; ?>
				</optgroup>
			<?php endforeach; ?>
		</select>
	</td>
</tr>
