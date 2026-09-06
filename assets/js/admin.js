/**
 * Scripts d'administration – Extender for Back In Stock Notifier.
 *
 * `ebisnAdmin` est fourni par wp_add_inline_script() : { ajaxUrl, nonce }.
 */
( function ( $ ) {
	'use strict';

	var EBISN = {
		init: function () {
			// Les interactions des modules viendront se brancher ici.
		}
	};

	$( function () {
		EBISN.init();
	} );

	window.EBISN = EBISN;
}( jQuery ) );
