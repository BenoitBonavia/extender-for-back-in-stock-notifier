<?php
/**
 * Signatures de « Back In Stock Notifier for WooCommerce », pour l'analyse
 * statique uniquement.
 *
 * Ce fichier n'est JAMAIS chargé à l'exécution : il est seulement listé dans
 * `scanFiles` de phpstan.neon.dist, et exclu des archives par .gitattributes.
 *
 * Raison d'être : le plugin appelle désormais une classe de l'extension hôte —
 * `CWG_Instock_API::subscriber_unsubscribed()` — plutôt que d'écrire lui-même
 * le statut « Unsubscribed ». Sans ces déclarations, PHPStan signale une classe
 * introuvable, et conclut même que le `method_exists()` qui la garde est
 * toujours faux.
 *
 * Tous les appels restent gardés par `class_exists()` / `method_exists()` à
 * l'exécution : l'extension hôte n'inclut aucun de ses fichiers quand
 * WooCommerce est inactif.
 *
 * Signatures relevées sur la version 7.4.2 (fichier includes/class-api.php).
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
}
