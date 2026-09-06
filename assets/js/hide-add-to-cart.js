/**
 * Masque l'ajout au panier des déclinaisons proposant une alerte de réassort.
 *
 * La décision n'est pas prise ici : le serveur marque chaque déclinaison d'un
 * drapeau `ebisn_alert`, posé si et seulement si l'extension hôte a réellement
 * rendu son formulaire — ou notre encart de désabonnement — pour cette
 * déclinaison. Ce script ne fait qu'appliquer ce que le serveur a constaté.
 *
 * Se fier au HTML produit plutôt qu'à l'état du stock évite de dupliquer les
 * règles de visibilité de l'hôte, qui en compte une dizaine : catégories,
 * étiquettes, prix, réassort, visiteurs connectés ou non.
 */
( function ( $ ) {
	'use strict';

	if ( ! $ ) {
		return;
	}

	/*
	 * Le bloc standard de WooCommerce d'abord ; les deux suivants rattrapent les
	 * thèmes qui sortent la quantité ou le bouton de ce conteneur.
	 */
	var TARGETS = [
		'.woocommerce-variation-add-to-cart',
		'.quantity',
		'.single_add_to_cart_button'
	].join( ', ' );

	/**
	 * Applique ou lève le masquage sur un formulaire de déclinaisons.
	 *
	 * @param {jQuery}  $form Formulaire concerné.
	 * @param {boolean} hide  Faut-il masquer ?
	 */
	function apply( $form, hide ) {
		var $targets = $form.find( TARGETS );

		if ( hide ) {
			$targets.addClass( 'ebisn-hidden' );
		} else {
			$targets.removeClass( 'ebisn-hidden' );
		}
	}

	$( document ).on( 'show_variation', function ( event, variation ) {
		apply(
			$( event.target ).closest( '.variations_form' ),
			!! ( variation && variation.ebisn_alert )
		);
	} );

	/*
	 * Retour à l'état neutre tant qu'aucune déclinaison n'est choisie — au
	 * chargement de la page comme après un « Effacer ».
	 *
	 * On ne sait alors pas encore si une alerte sera proposée : masquer serait
	 * un pari. WooCommerce laisse là le bloc visible mais grisé, ce qui indique
	 * au client qu'on attend un choix de sa part ; c'est ce comportement qu'il
	 * faut lui rendre.
	 */
	$( document ).on( 'hide_variation reset_data', function ( event ) {
		apply( $( event.target ).closest( '.variations_form' ), false );
	} );
}( window.jQuery ) );
