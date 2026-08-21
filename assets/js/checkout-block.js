/**
 * Terminal selection for the block checkout.
 *
 * Written against the globals WooCommerce already loads on the checkout, so
 * this plugin has nothing to build. The terminals come from the cart response
 * (see WC_Estonian_Shipping_Blocks), which only ever carries the ones belonging
 * to the shipping method currently chosen.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.blocksCheckout || ! wp || ! wp.element ) {
		return;
	}

	var NAMESPACE = 'wc-estonian-shipping-methods';
	var VALIDATION_ERROR_ID = 'wc-estonian-shipping-terminal';

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) { return text; };

	var ComboboxControl = wp.components && wp.components.ComboboxControl ? wp.components.ComboboxControl : null;
	var registerCheckoutBlock = wc.blocksCheckout.registerCheckoutBlock;
	var extensionCartUpdate = wc.blocksCheckout.extensionCartUpdate;
	var ValidationInputError = wc.blocksCheckout.ValidationInputError;

	var metadata = {
		name: 'wc-estonian-shipping-methods/terminals',
		title: __( 'Parcel terminal', 'wc-estonian-shipping-methods' ),
		category: 'woocommerce',
		parent: [ 'woocommerce/checkout-shipping-methods-block' ],
		attributes: {
			lock: {
				type: 'object',
				default: { remove: true, move: true },
			},
		},
	};

	/**
	 * Tell the checkout it cannot be submitted without a terminal, and stop
	 * saying so once there is one.
	 *
	 * @param {boolean} needed  Whether a terminal has to be chosen at all.
	 * @param {string}  chosen  Terminal chosen so far.
	 */
	function useTerminalValidation( needed, chosen ) {
		useEffect( function () {
			var validation = wp.data && wp.data.dispatch ? wp.data.dispatch( 'wc/store/validation' ) : null;

			if ( ! validation ) {
				return;
			}

			if ( needed && ! chosen ) {
				var errors = {};

				errors[ VALIDATION_ERROR_ID ] = {
					message: __( 'Please select a parcel terminal', 'wc-estonian-shipping-methods' ),
					hidden: true,
				};

				validation.setValidationErrors( errors );
			} else {
				validation.clearValidationError( VALIDATION_ERROR_ID );
			}

			return function () {
				validation.clearValidationError( VALIDATION_ERROR_ID );
			};
		}, [ needed, chosen ] );
	}

	var Terminals = function ( props ) {
		var extensions = props.extensions || {};
		var data = extensions[ NAMESPACE ] || {};
		var groups = data.terminals || [];
		var selected = data.selected || '';
		var needed = !! ( data.method_id && groups.length );

		var setExtensionData = props.checkoutExtensionData && props.checkoutExtensionData.setExtensionData
			? props.checkoutExtensionData.setExtensionData
			: function () {};

		// Whatever is already chosen has to travel with the checkout request,
		// not only sit in the session.
		useEffect( function () {
			if ( needed ) {
				setExtensionData( NAMESPACE, 'terminal_id', selected );
			}
		}, [ needed, selected ] );

		useTerminalValidation( needed, selected );

		// WordPress ships a searchable combobox and the block checkout already
		// loads it: filtering, keyboard handling and the ARIA plumbing come
		// from there rather than from anything written here. Hundreds of
		// terminals in a plain dropdown is a great deal of scrolling - Omniva
		// alone is over four hundred.
		var options = useMemo( function () {
			var flat = [];

			groups.forEach( function ( group ) {
				( group.options || [] ).forEach( function ( option ) {
					// The town, unless the terminal's name already says it -
					// the combobox searches the label, so it has to be in there.
					var label = option.label.toLowerCase().indexOf( group.label.toLowerCase() ) === -1
						? group.label + ' – ' + option.label
						: option.label;

					flat.push( { value: option.value, label: label } );
				} );
			} );

			return flat;
		}, [ groups ] );

		if ( ! needed ) {
			return null;
		}

		var onChange = function ( value ) {
			value = value || '';

			setExtensionData( NAMESPACE, 'terminal_id', value );

			// Keep it where the classic checkout keeps it, so the two agree and
			// a reload does not lose the choice.
			if ( extensionCartUpdate ) {
				extensionCartUpdate( {
					namespace: NAMESPACE,
					data: { terminal_id: value },
				} );
			}
		};

		return el(
			'div',
			{ className: 'wc-block-components-shipping-rates-control wc-esm-terminals' },
			ComboboxControl
				? el( ComboboxControl, {
					className: 'wc-esm-terminals__combobox',
					label: data.label || __( 'Choose terminal', 'wc-estonian-shipping-methods' ),
					placeholder: __( 'Search by town or terminal name', 'wc-estonian-shipping-methods' ),
					value: selected,
					options: options,
					onChange: onChange,
					allowReset: false,
					__next40pxDefaultSize: true,
					__nextHasNoMarginBottom: true,
				} )
				// Nothing to fall back on but a plain dropdown, which is what
				// this was before the combobox existed.
				: el(
					'select',
					{
						id: 'wc-esm-terminal',
						className: 'wc-blocks-components-select__select',
						value: selected,
						onChange: function ( event ) {
							onChange( event.target.value );
						},
					},
					[ el( 'option', { value: '', key: 'none' }, __( '- Choose terminal -', 'wc-estonian-shipping-methods' ) ) ].concat(
						options.map( function ( option ) {
							return el( 'option', { value: option.value, key: option.value }, option.label );
						} )
					)
				),
			ValidationInputError ? el( ValidationInputError, { propertyName: VALIDATION_ERROR_ID } ) : null
		);
	};

	if ( registerCheckoutBlock ) {
		registerCheckoutBlock( {
			metadata: metadata,
			component: Terminals,
		} );
	}
} )( window.wc, window.wp );
