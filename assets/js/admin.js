/**
 * Scripts d'administration – Extender for Back In Stock Notifier.
 *
 * `ebisnAdmin` est fourni par wp_add_inline_script() : { ajaxUrl, nonce }.
 */
( function ( $ ) {
	'use strict';

	var EBISN = {
		init: function () {
			/*
			 * Confirmation avant une action de masse. Le message est lu dans
			 * `title` plutôt que dans un attribut `data-*` : les champs `info` de
			 * WooCommerce passent par wp_kses_post(), qui conserve `class` et `title`.
			 */
			$( document ).on( 'click', 'a.ebisn-confirm', function ( event ) {
				var message = $( this ).attr( 'title' );

				if ( message && ! window.confirm( message ) ) {
					event.preventDefault();
				}
			} );
		}
	};

	$( function () {
		EBISN.init();
	} );

	window.EBISN = EBISN;
}( jQuery ) );
