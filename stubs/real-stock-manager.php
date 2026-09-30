<?php
/**
 * Signatures de « Real Stock Manager for WooCommerce », pour l'analyse
 * statique uniquement.
 *
 * Ce fichier n'est JAMAIS chargé à l'exécution : il est seulement listé dans
 * `scanFiles` de phpstan.neon.dist, et exclu des archives par .gitattributes.
 *
 * Raison d'être : `EBISN\Integration\RealStockManager` appelle des méthodes du
 * plugin frère — `Supply::get()`, `Stock::get()`, `Demand::map()` — plutôt que
 * de dupliquer leur calcul. Sans ces déclarations, PHPStan signale des classes
 * introuvables.
 *
 * Tous les appels restent gardés par `RealStockManager::is_active()` à
 * l'exécution, qui teste `class_exists( \RSMW\Preparation\Supply::class )` :
 * le plugin frère peut être absent, désactivé, ou ne pas avoir démarré (par
 * exemple si WooCommerce lui manque) sans que rien ne casse.
 *
 * Signatures relevées sur la version 3.8.0 (fichiers
 * src/Preparation/{Supply,Stock,Demand}.php).
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace RSMW\Preparation;

/**
 * Commandé au fournisseur et non encore attribué à une commande client.
 */
final class Supply {

	/**
	 * Quantité commandée et non encore attribuée, pour une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function get( $product_id ) {
		return 0;
	}
}

/**
 * Stock physique réel, produit ou variation.
 */
final class Stock {

	/**
	 * Quantité en stock physique libre, pour une référence.
	 *
	 * @param int $product_id Produit ou variation.
	 *
	 * @return int
	 */
	public static function get( $product_id ) {
		return 0;
	}
}

/**
 * Demande des commandes clients actives, par référence.
 */
final class Demand {

	/**
	 * Table des besoins, indexée par référence de stock.
	 *
	 * Chaque entrée porte au moins `restant` (reste à préparer) et `commande`
	 * (part du restant déjà couverte par une commande fournisseur).
	 *
	 * @param bool $use_cache Lire le transient plutôt que recalculer.
	 *
	 * @return array<int, array<string, int>>
	 */
	public static function map( $use_cache = true ) {
		return array();
	}
}
