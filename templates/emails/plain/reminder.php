<?php
/**
 * Relance après l'alerte de retour en stock (texte brut).
 *
 * Surchargeable depuis le thème : `{thème}/woocommerce/emails/plain/reminder.php`.
 *
 * @package ExtenderForBackInStockNotifier/Templates/Emails/Plain
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

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( str_replace( array( '<br/>', '<br />', '<br>' ), "\n", $additional_content ) ) ) ) . "\n\n";
}

if ( ! empty( $unsubscribe_url ) ) {
	echo esc_html__( 'Pour ne plus recevoir de message à propos de ce produit :', 'extender-for-back-in-stock-notifier' ) . ' ' . esc_url_raw( $unsubscribe_url ) . "\n\n";
}

echo "----------\n\n";

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
