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
	var useState = wp.element.useState;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) { return text; };
	var _n = wp.i18n && wp.i18n._n ? wp.i18n._n : function ( single, plural, number ) { return 1 === number ? single : plural; };
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( format, value ) { return format.replace( '%d', value ); };

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
		var searchState = useState( '' );
		var search = searchState[0];
		var setSearch = searchState[1];

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

		// Grouped exactly as the shipping method's settings group them - by
		// city, today - because that grouping is a setting a shop chose, and
		// the classic checkout honours it. WordPress's ComboboxControl would
		// have brought its own search for free, but it has no notion of groups,
		// so the search here is a filter over the list rather than a component.
		var terms = search.trim().toLowerCase().split( /\s+/ ).filter( Boolean );

		var filtered = useMemo( function () {
			if ( ! terms.length ) {
				return groups;
			}

			var result = [];

			groups.forEach( function ( group ) {
				var options = ( group.options || [] ).filter( function ( option ) {
					var haystack = ( group.label + ' ' + option.label ).toLowerCase();

					// Every word somewhere in the terminal or its city, so
					// "tartu selver" finds "Tartu Anne Selveri pakiautomaat".
					return terms.every( function ( term ) {
						return haystack.indexOf( term ) !== -1;
					} );
				} );

				// How many actually matched: the chosen terminal is kept in the
				// list below whether it matched or not, and counting it would
				// have the search claim a result when there is none.
				var matched = options.length;

				// Never filter away what is already chosen, or the dropdown
				// would look empty while a terminal is in fact selected.
				( group.options || [] ).forEach( function ( option ) {
					if ( option.value === selected && options.indexOf( option ) === -1 ) {
						options.unshift( option );
					}
				} );

				if ( options.length ) {
					result.push( { label: group.label, options: options, matched: matched } );
				}
			} );

			return result;
		}, [ search, groups, selected ] );

		if ( ! needed ) {
			return null;
		}

		var onChange = function ( event ) {
			var value = event.target.value;

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

		var matching = 0;

		var options = [ el( 'option', { value: '', key: 'none' }, __( '- Choose terminal -', 'wc-estonian-shipping-methods' ) ) ];

		filtered.forEach( function ( group, groupIndex ) {
			matching += undefined === group.matched ? group.options.length : group.matched;

			options.push(
				el(
					'optgroup',
					{ label: group.label, key: 'group-' + groupIndex },
					group.options.map( function ( option ) {
						return el( 'option', { value: option.value, key: option.value }, option.label );
					} )
				)
			);
		} );

		return el(
			'div',
			{ className: 'wc-block-components-shipping-rates-control wc-esm-terminals' },
			el(
				'div',
				{ className: 'wc-blocks-components-select' },
				el(
					'div',
					{ className: 'wc-blocks-components-select__container' },
					el(
						'label',
						{ className: 'wc-blocks-components-select__label', htmlFor: 'wc-esm-terminal' },
						data.label || __( 'Choose terminal', 'wc-estonian-shipping-methods' )
					),
					el( 'input', {
						type: 'search',
						className: 'wc-esm-terminals__search',
						value: search,
						placeholder: __( 'Search by town or terminal name', 'wc-estonian-shipping-methods' ),
						'aria-label': __( 'Search terminals', 'wc-estonian-shipping-methods' ),
						'aria-controls': 'wc-esm-terminal',
						onChange: function ( event ) {
							setSearch( event.target.value );
						},
					} ),
					el( 'select', {
						id: 'wc-esm-terminal',
						className: 'wc-blocks-components-select__select',
						value: selected,
						onChange: onChange,
					}, options )
				)
			),
			terms.length
				? el(
					'p',
					{ className: 'wc-esm-terminals__count', 'aria-live': 'polite' },
					matching
						? sprintf( _n( '%d terminal found', '%d terminals found', matching ), matching )
						: __( 'No terminals match that search.', 'wc-estonian-shipping-methods' )
				)
				: null,
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
