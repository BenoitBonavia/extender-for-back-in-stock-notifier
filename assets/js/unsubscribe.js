/**
 * Désabonnement d'une alerte de retour en stock.
 *
 * `ebisnUnsubscribe` est fourni par wp_add_inline_script() :
 * { ajaxUrl, action, error }.
 *
 * Sans jQuery : le script est chargé sur toutes les fiches produit, autant ne
 * rien exiger de plus que ce que le navigateur fournit.
 */
( function () {
	'use strict';

	var config = window.ebisnUnsubscribe || {};

	/**
	 * Envoie la demande et met l'encart à jour.
	 *
	 * @param {HTMLElement} box    Conteneur de l'encart.
	 * @param {HTMLElement} button Bouton actionné.
	 */
	function submit( box, button ) {
		var intent = button.dataset.intent || 'unsubscribe';
		var body = new FormData();

		body.append( 'action', config.action );
		body.append( 'subscription', box.dataset.subscription );
		body.append( 'nonce', box.dataset.nonce );
		body.append( 'intent', intent );

		button.disabled = true;

		window.fetch( config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success || ! payload.data ) {
					throw new Error( 'unexpected' );
				}

				var state = box.querySelector( '.ebisn-unsubscribe__state' );

				if ( state ) {
					state.textContent = payload.data.message;
				}

				button.textContent = payload.data.label;
				button.dataset.intent = payload.data.intent;
				box.dataset.state = payload.data.state;

				/*
				 * La confirmation ne vaut que pour le désabonnement : rétablir
				 * une alerte est sans conséquence.
				 */
				box.dataset.skipConfirm = 'resubscribe' === payload.data.intent ? '1' : '';
			} )
			.catch( function () {
				var state = box.querySelector( '.ebisn-unsubscribe__state' );

				if ( state ) {
					state.textContent = config.error;
				}
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

	/*
	 * Écoute déléguée sur le document : sur un produit variable, l'encart est
	 * injecté puis remplacé par WooCommerce à chaque changement de déclinaison.
	 * Un écouteur posé sur le bouton ne survivrait pas au premier changement.
	 */
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest( '.ebisn-unsubscribe__button' );

		if ( ! button ) {
			return;
		}

		var box = button.closest( '.ebisn-unsubscribe' );

		if ( ! box || ! config.ajaxUrl ) {
			return;
		}

		event.preventDefault();

		var confirmation = box.dataset.confirm;

		if ( confirmation && ! box.dataset.skipConfirm && ! window.confirm( confirmation ) ) {
			return;
		}

		submit( box, button );
	} );
}() );
