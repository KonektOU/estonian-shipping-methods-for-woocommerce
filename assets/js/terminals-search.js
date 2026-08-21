/**
 * Making the terminal list searchable on the classic checkout.
 *
 * WooCommerce already ships selectWoo - its own fork of Select2, the same thing
 * the country and state fields use - so the search box, the keyboard handling
 * and the grouping all come from there rather than from anything written here.
 *
 * The order review is replaced wholesale by WooCommerce whenever the cart or
 * the address changes, taking the dropdown with it, so this runs again after.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	var settings = window.wcEsmTerminals || {};

	/**
	 * @param {string} term What was typed.
	 *
	 * @return {Array} The words in it.
	 */
	function words( term ) {
		return term.split( /\s+/ ).filter( Boolean );
	}
	var SELECTOR = 'select[name^="wc_shipping_"][name$="_terminal"]';

	/**
	 * @return {void}
	 */
	function enhance() {
		$( SELECTOR ).each( function () {
			var $select = $( this );

			// selectWoo replaces the element it is given; asking twice leaves
			// two of them behind.
			if ( $select.data( 'esm-searchable' ) ) {
				return;
			}

			var enhancer = $.fn.selectWoo ? 'selectWoo' : ( $.fn.select2 ? 'select2' : null );

			if ( ! enhancer ) {
				return;
			}

			$select.data( 'esm-searchable', true );

			$select[ enhancer ]( {
				placeholder: settings.searchPlaceholder || '',
				width: '100%',
				// A parcel terminal list is hundreds long; the search box is
				// the point of this, so never hide it.
				minimumResultsForSearch: 0,
				language: {
					noResults: function () {
						return settings.nothingFound || '';
					},
				},
				// Every word has to appear somewhere in the terminal's name or
				// its town, rather than the whole phrase in one piece: people
				// type "tartu selver", and the terminal is called "Tartu Anne
				// Selveri pakiautomaat".
				matcher: function ( params, data ) {
					var term = $.trim( params.term || '' ).toLowerCase();

					if ( '' === term ) {
						return data;
					}

					if ( data.children && data.children.length ) {
						var matches = [];

						data.children.forEach( function ( child ) {
							var haystack = ( ( data.text || '' ) + ' ' + ( child.text || '' ) ).toLowerCase();

							if ( words( term ).every( function ( word ) { return haystack.indexOf( word ) !== -1; } ) ) {
								matches.push( child );
							}
						} );

						if ( ! matches.length ) {
							return null;
						}

						var group = $.extend( {}, data, true );

						group.children = matches;

						return group;
					}

					var text = ( data.text || '' ).toLowerCase();

					return words( term ).every( function ( word ) { return text.indexOf( word ) !== -1; } ) ? data : null;
				},
			} );
		} );
	}

	$( function () {
		enhance();

		$( document.body ).on( 'updated_checkout', enhance );
	} );
} )( window.jQuery );
