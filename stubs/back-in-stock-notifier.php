<?php
/**
 * Signatures de « Back In Stock Notifier for WooCommerce », pour l'analyse
 * statique uniquement.
 *
 * Ce fichier n'est JAMAIS chargé à l'exécution : il est seulement listé dans
 * `scanFiles` de phpstan.neon.dist, et exclu des archives par .gitattributes.
 *
 * Raison d'être : le plugin appelle des méthodes de l'extension hôte —
 * `CWG_Instock_API::subscriber_unsubscribed()`, `subscriber_subscribed()` —
 * plutôt que d'écrire lui-même les statuts d'inscription. Sans ces
 * déclarations, PHPStan signale une classe introuvable, et conclut même que le
 * `method_exists()` qui la garde est toujours faux.
 *
 * Tous les appels restent gardés par `class_exists()` / `method_exists()` à
 * l'exécution : l'extension hôte n'inclut aucun de ses fichiers quand
 * WooCommerce est inactif.
 *
 * Signatures relevées sur la version 7.5.3 (fichier includes/class-api.php).
 *
 * @package ExtenderForBackInStockNotifier
 */

/**
 * Utilitaires de l'extension hôte autour d'une inscription.
 */
class CWG_Instock_API {

	/**
	 * Constructeur.
	 *
	 * @param int    $product_id       Produit parent.
	 * @param int    $variation_id     Variation, `0` pour un produit simple.
	 * @param string $subscriber_email Adresse de l'inscrit.
	 * @param int    $user_id          Compte WordPress, `0` pour un invité.
	 * @param string $language         Locale, inutilisée en version gratuite.
	 */
	public function __construct( $product_id = 0, $variation_id = 0, $subscriber_email = '', $user_id = 0, $language = 'en_US' ) {
	}

	/**
	 * Fait passer une inscription au statut « Subscribed ».
	 *
	 * @param int $subscribe_id Inscription.
	 *
	 * @return int|WP_Error Identifiant du contenu, ou une erreur.
	 */
	public function subscriber_subscribed( $subscribe_id ) {
		return 0;
	}

	/**
	 * Fait passer une inscription au statut « Unsubscribed ».
	 *
	 * @param int $subscribe_id Inscription.
	 *
	 * @return int|WP_Error Identifiant du contenu, ou une erreur.
	 */
	public function subscriber_unsubscribed( $subscribe_id ) {
		return 0;
	}

	/**
	 * Fait passer une inscription au statut « Mail Sent ».
	 *
	 * @param int $subscribe_id Inscription.
	 *
	 * @return int|WP_Error
	 */
	public function mail_sent_status( $subscribe_id ) {
		return 0;
	}

	/**
	 * Nombre d'inscrits d'un produit, pour un statut donné.
	 *
	 * @param int    $pid    Produit ou variation.
	 * @param string $status Statut, ou `any` pour tous.
	 *
	 * @return int
	 */
	public function get_subscribers_count( $pid, $status = 'cwg_subscribed' ) {
		return 0;
	}

	/**
	 * Nom du produit, sans balisage.
	 *
	 * @param int $id Inscription.
	 *
	 * @return string
	 */
	public function display_only_product_name( $id ) {
		return '';
	}

	/**
	 * Nom formaté du produit.
	 *
	 * @param int $id Inscription.
	 *
	 * @return string
	 */
	public function display_product_name( $id ) {
		return '';
	}

	/**
	 * Lien vers le produit attendu.
	 *
	 * @param int $id Inscription.
	 *
	 * @return string
	 */
	public function display_product_link( $id ) {
		return '';
	}

	/**
	 * Prix formaté du produit.
	 *
	 * @param int $id Inscription.
	 *
	 * @return string
	 */
	public function get_product_price( $id ) {
		return '';
	}

	/**
	 * Image du produit, en balise HTML.
	 *
	 * @param int    $id   Inscription.
	 * @param string $size Taille d'image.
	 *
	 * @return string
	 */
	public function get_product_image( $id, $size = 'woocommerce_thumbnail' ) {
		return '';
	}

	/**
	 * Lien d'ajout au panier.
	 *
	 * @param int $id Inscription.
	 *
	 * @return string
	 */
	public function get_cart_link( $id ) {
		return '';
	}

	/**
	 * Nom complet de l'inscrit.
	 *
	 * @param int $subscriber_id Inscription.
	 *
	 * @return string
	 */
	public function get_subscriber_name( $subscriber_id ) {
		return '';
	}

	/**
	 * Prénom de l'inscrit.
	 *
	 * @param int $subscriber_id Inscription.
	 *
	 * @return string
	 */
	public function get_subscriber_firstname( $subscriber_id ) {
		return '';
	}
}

/**
 * Limiteur d'envois de l'hôte (depuis 7.3.0).
 */
class CWG_Instock_Mail_Throttle {

	/**
	 * Réserve un créneau dans la minute courante, sans attendre.
	 *
	 * @return bool
	 */
	public static function try_acquire() {
		return true;
	}
}

/**
 * Coupon automatique de l'hôte (depuis 7.4.0).
 */
class CWG_Instock_Auto_Coupon {

	/**
	 * Le coupon automatique est-il activé ?
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return false;
	}

	/**
	 * Coupon d'une inscription : réutilise l'existant s'il est valable.
	 *
	 * @param int $subscriber_id Inscription.
	 *
	 * @return string Code, ou chaîne vide.
	 */
	public static function get_coupon_for_subscriber( $subscriber_id ) {
		return '';
	}

	/**
	 * Remise formatée, par exemple « 10% ».
	 *
	 * @return string
	 */
	public static function format_amount() {
		return '';
	}
}

/**
 * Ajoute les balises UTM de l'hôte à un lien.
 *
 * @param string $url     Lien.
 * @param string $context Emplacement du lien.
 *
 * @return string
 */
function cwg_instock_add_utm_params( $url, $context = '' ) {
	return $url;
}

/**
 * Tableau « article de commande » du produit attendu.
 *
 * @param int  $subscriber_id Inscription.
 * @param bool $preview       Contenu d'exemple pour l'aperçu.
 *
 * @return string
 */
function cwg_instock_get_product_table_html( $subscriber_id, $preview = false ) {
	return '';
}

/**
 * Bloc de coupon prêt à l'emploi.
 *
 * @param string $code    Code.
 * @param string $amount  Remise formatée.
 * @param string $expiry  Expiration formatée.
 * @param string $link    Lien du bouton.
 * @param bool   $preview Contenu d'exemple pour l'aperçu.
 *
 * @return string
 */
function cwg_instock_get_coupon_box_html( $code, $amount, $expiry = '', $link = '', $preview = false ) {
	return '';
}
