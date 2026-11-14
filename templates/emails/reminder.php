<?php
/**
 * Relance après l'alerte de retour en stock (HTML).
 *
 * Surchargeable depuis le thème : `{thème}/woocommerce/emails/reminder.php`.
 *
 * @package ExtenderForBackInStockNotifier/Templates/Emails
 * @version 0.9.0
 *
 * @var string $email_heading      Titre.
 * @var string $additional_content Message, balises déjà remplacées.
 * @var string $unsubscribe_url    Lien de désabonnement, vide dans l'aperçu.
 * @var object $email              E-mail.
 */

defined( 'ABSPATH' ) || exit;

// Les hooks `woocommerce_email_*` sont ceux de WooCommerce : ils ne portent pas notre préfixe.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals

do_action( 'woocommerce_email_header', $email_heading, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

/*
 * Toujours présent, même si le marchand a retiré la balise de son texte : une
 * relance sans moyen d'arrêter les suivantes n'a pas lieu d'être.
 */
if ( ! empty( $unsubscribe_url ) ) {
	printf(
		'<p style="font-size:12px;color:#757575;">%1$s <a href="%2$s">%3$s</a></p>',
		esc_html__( 'Vous ne souhaitez plus recevoir de message à propos de ce produit ?', 'extender-for-back-in-stock-notifier' ),
		esc_url( $unsubscribe_url ),
		esc_html__( 'Se désabonner', 'extender-for-back-in-stock-notifier' )
	);
}

do_action( 'woocommerce_email_footer', $email );
