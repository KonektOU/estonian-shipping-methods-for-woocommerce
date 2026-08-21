/**
 * The same block, as the checkout editor needs to know about it.
 *
 * Nothing is configurable and nothing is saved: the block exists so that the
 * checkout's shipping step has somewhere to put the terminal selection, and so
 * the editor can show what will be there.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.blocks || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) { return text; };

	wp.blocks.registerBlockType( 'wc-estonian-shipping-methods/terminals', {
		title: __( 'Parcel terminal', 'wc-estonian-shipping-methods' ),
		category: 'woocommerce',
		parent: [ 'woocommerce/checkout-shipping-methods-block' ],
		icon: 'location',
		description: __( 'Lets the customer choose a parcel terminal for the shipping method they picked.', 'wc-estonian-shipping-methods' ),
		supports: {
			html: false,
			align: false,
			multiple: false,
			reusable: false,
		},
		attributes: {
			lock: {
				type: 'object',
				default: { remove: true, move: true },
			},
		},
		edit: function () {
			return el(
				'div',
				{ className: 'wc-esm-terminals-placeholder' },
				el( 'strong', null, __( 'Parcel terminal', 'wc-estonian-shipping-methods' ) ),
				el(
					'p',
					null,
					__( 'A terminal list is shown here when the customer chooses a shipping method that delivers to parcel terminals.', 'wc-estonian-shipping-methods' )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
