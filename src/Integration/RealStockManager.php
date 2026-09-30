<?php
/**
 * Point d'isolement du plugin frère « Real Stock Manager for WooCommerce ».
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce que ce plugin sait de « Real Stock Manager for WooCommerce ».
 *
 * Aucune autre classe ne doit écrire en dur `RSMW_`, `_rsmw_stock_ordered` ni
 * `\RSMW\…` : le jour où ce plugin frère renomme une classe ou une métadonnée,
 * seul ce fichier bouge. Même contrat que {@see BackInStockNotifier} pour
 * l'extension hôte.
 *
 * Dépendance OPTIONNELLE : un marchand peut utiliser ce plugin sans Real Stock
 * Manager, auquel cas la page « Demandes par taille » se comporte exactement
 * comme si cette classe n'existait pas.
 *
 * Relevé sur la version 3.8.0 (même auteur, fichiers
 * src/Preparation/{Supply,Stock,Demand}.php et src/BackInStock/Coverage.php).
 */
final class RealStockManager {

	/**
	 * Slug du plugin, tel que WordPress.org l'expose.
	 */
	public const SLUG = 'real-stock-manager-for-woocommerce';

	/**
	 * Chemin du fichier principal, relatif au dossier des extensions.
	 */
	public const BASENAME = 'real-stock-manager-for-woocommerce/real-stock-manager-for-woocommerce.php';

	/**
	 * Constante de version posée par le plugin frère.
	 *
	 * Définie dès le chargement de son fichier principal — avant même que ses
	 * propres prérequis (WooCommerce) ne soient vérifiés. Sa seule présence ne
	 * garantit donc pas que les classes `RSMW\*` soient utilisables : voir
	 * is_active().
	 */
	public const VERSION_CONSTANT = 'RSMW_VERSION';

	/**
	 * Version minimale acceptée : celle qui introduit `BackInStock\Coverage`,
	 * dont ce fichier recopie le calcul.
	 */
	public const MIN_VERSION = '3.8';

	/**
	 * Dernière version du plugin frère contre laquelle celui-ci a été relu.
	 *
	 * N'empêche rien — sert uniquement à avertir dans le panneau Diagnostic
	 * qu'un changement de version majeure mériterait une relecture.
	 */
	public const TESTED_VERSION = '3.8.0';

	/**
	 * Le plugin frère est-il chargé ET opérationnel ?
	 *
	 * Trois conditions cumulées, plutôt qu'une seule constante : `RSMW_VERSION`
	 * est définie même si WooCommerce est absent (le plugin frère sort alors de
	 * son amorçage sans rien enregistrer), et un `class_exists()` seul ne dirait
	 * rien de la version installée.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( self::VERSION_CONSTANT )
			&& version_compare( self::version(), self::MIN_VERSION, '>=' )
			&& class_exists( \RSMW\Preparation\Supply::class );
	}

	/**
	 * Version du plugin frère.
	 *
	 * @return string Chaîne vide si la constante n'est pas définie.
	 */
	public static function version(): string {
		if ( ! defined( self::VERSION_CONSTANT ) ) {
			return '';
		}

		$version = constant( self::VERSION_CONSTANT );

		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * Le plugin frère a-t-il changé de version MAJEURE depuis la relecture ?
	 *
	 * @return bool
	 */
	public static function is_untested(): bool {
		$version = self::version();

		if ( '' === $version ) {
			return false;
		}

		$installed = (int) explode( '.', $version )[0];
		$tested    = (int) explode( '.', self::TESTED_VERSION )[0];

		return $installed > $tested;
	}

	/**
	 * Le plugin frère est-il installé, actif ou non ?
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_key_exists( self::BASENAME, get_plugins() );
	}

	/**
	 * Unités réellement disponibles pour la liste d'attente, par référence de
	 * stock (identifiant de variation si le produit est décliné, sinon
	 * identifiant du produit — c'est aussi la clé `cwginstock_pid` de
	 * l'extension hôte, donc directement celle des `pids` de la matrice).
	 *
	 * Recopie de `RSMW\BackInStock\Coverage::available_breakdown()` : les
	 * commandes clients en attente sont servies EN PREMIER, le stock physique
	 * est consommé avant le commandé fournisseur, et seul le surplus revient à
	 * la liste d'attente. Ne jamais dupliquer ce calcul ailleurs — le
	 * recalculer autrement à partir des seules constantes `Supply`/`Stock`
	 * ignorerait les commandes clients et surestimerait le disponible.
	 *
	 * `Demand::map( false )` force le recalcul plutôt que de lire le transient
	 * de Real Stock Manager : comme la propre page de couverture de ce plugin
	 * frère, un écran de réassort doit refléter l'état réel du stock et des
	 * commandes, pas une valeur vieille de deux minutes. Ce coût — deux
	 * `wc_get_orders( array( 'limit' => -1 ) )` — n'est payé qu'à la demande,
	 * quand la case à cocher est active.
	 *
	 * @param int[] $reference_ids Références de stock à confronter.
	 *
	 * @return array<int, int> Référence => unités disponibles.
	 */
	public static function available_for( array $reference_ids ): array {
		$available = array();

		foreach ( self::breakdown_for( $reference_ids ) as $id => $split ) {
			$available[ $id ] = $split['libre'] + $split['a_venir'];
		}

		return $available;
	}

	/**
	 * Valeur de la demande couverte par le SEUL commandé fournisseur — jamais
	 * par le stock physique, consommé en premier pour ne pas la lui attribuer
	 * à tort. Réponse à une question différente de celle d'available_for() :
	 * non pas « combien reste-t-il de disponible au total », mais « combien
	 * de cette demande précise doit-on à la commande fournisseur ».
	 *
	 * Cette méthode ne résout ni la demande ni le prix : c'est à l'appelant de
	 * les fournir, pour rester la seule source de vérité sur la population
	 * comptée (par exemple : inscriptions « en attente » et « non récupéré »
	 * confondues) et sur la résolution du prix catalogue — deux décisions qui
	 * n'ont rien à voir avec le stock.
	 *
	 * @param array<int, array{units:int, price:float}> $demand Unités demandées
	 *        et prix catalogue, par référence de stock.
	 *
	 * @return array{value: float, units: int}
	 */
	public static function value_covered_by_supplier( array $demand ): array {
		$result = array(
			'value' => 0.0,
			'units' => 0,
		);

		if ( empty( $demand ) || ! self::is_active() ) {
			return $result;
		}

		$breakdown = self::breakdown_for( array_keys( $demand ) );

		foreach ( $demand as $pid => $info ) {
			$units       = max( 0, (int) $info['units'] );
			$libre_net   = $breakdown[ $pid ]['libre'] ?? 0;
			$a_venir_net = $breakdown[ $pid ]['a_venir'] ?? 0;

			// Physique d'abord : ce qui en reste de la demande n'a plus besoin
			// du fournisseur pour être honoré.
			$covered_by_supplier = min( max( 0, $units - $libre_net ), $a_venir_net );

			$result['units'] += $covered_by_supplier;
			$result['value'] += $covered_by_supplier * (float) $info['price'];
		}

		return $result;
	}

	/**
	 * Disponible pour la liste d'attente, détaillé par origine — stock
	 * physique et commandé fournisseur — pour chaque référence de stock.
	 *
	 * Recopie de `RSMW\BackInStock\Coverage::available_breakdown()` : les
	 * commandes clients en attente sont servies EN PREMIER, le stock physique
	 * est consommé avant le commandé fournisseur, et seul le surplus revient à
	 * la liste d'attente. Ne jamais dupliquer ce calcul ailleurs — le
	 * recalculer autrement à partir des seules constantes `Supply`/`Stock`
	 * ignorerait les commandes clients et surestimerait le disponible.
	 *
	 * `Demand::map( false )` force le recalcul plutôt que de lire le transient
	 * de Real Stock Manager : comme la propre page de couverture de ce plugin
	 * frère, un écran de réassort doit refléter l'état réel du stock et des
	 * commandes, pas une valeur vieille de deux minutes. Ce coût — deux
	 * `wc_get_orders( array( 'limit' => -1 ) )` — n'est payé qu'à la demande,
	 * jamais à chaque affichage d'un écran.
	 *
	 * @param int[] $reference_ids Références de stock à confronter.
	 *
	 * @return array<int, array{libre: int, a_venir: int}>
	 */
	private static function breakdown_for( array $reference_ids ): array {
		$reference_ids = array_values( array_unique( array_filter( array_map( 'intval', $reference_ids ) ) ) );

		$breakdown = array();

		if ( empty( $reference_ids ) || ! self::is_active() ) {
			return $breakdown;
		}

		$prep = \RSMW\Preparation\Demand::map( false );

		foreach ( $reference_ids as $id ) {
			$restant  = isset( $prep[ $id ]['restant'] ) ? (int) $prep[ $id ]['restant'] : 0;
			$reserved = isset( $prep[ $id ]['commande'] ) ? (int) $prep[ $id ]['commande'] : 0;

			// Ce que les commandes clients réclament encore, sans être déjà
			// couvert par du préparé ni par une commande fournisseur antérieure.
			$to_cover = max( 0, $restant - $reserved );

			$libre   = \RSMW\Preparation\Stock::get( $id );
			$a_venir = \RSMW\Preparation\Supply::get( $id );

			$breakdown[ $id ] = array(
				'libre'   => max( 0, $libre - $to_cover ),
				'a_venir' => max( 0, $a_venir - max( 0, $to_cover - $libre ) ),
			);
		}

		return $breakdown;
	}

	/**
	 * URL de la fiche d'installation du plugin frère.
	 *
	 * @return string
	 */
	public static function install_url(): string {
		return self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( self::SLUG ) );
	}

	/**
	 * URL de la liste des extensions installées.
	 *
	 * @return string
	 */
	public static function plugins_url(): string {
		return self_admin_url( 'plugins.php' );
	}

	/**
	 * Nom lisible du plugin frère.
	 *
	 * Volontairement non traduit : c'est un nom propre, il doit rester
	 * cherchable tel quel dans l'écran d'ajout d'extensions.
	 *
	 * @return string
	 */
	public static function name(): string {
		return 'Real Stock Manager for WooCommerce';
	}
}
