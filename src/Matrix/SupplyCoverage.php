<?php
/**
 * Déduit de la matrice ce que le réassort en cours couvre déjà.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Matrix;

use EBISN\Integration\RealStockManager;
use EBISN\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Confronte chaque cellule de la matrice « Demandes par taille » au stock
 * réellement disponible côté Real Stock Manager for WooCommerce (stock
 * physique libre et commandé fournisseur non attribué), pour ne laisser à
 * l'écran que ce qui reste vraiment à commander.
 *
 * Une demande vaut ici une unité : `DemandMatrix::fetch_demands()` compte des
 * INSCRIPTIONS, là où Real Stock Manager compte des UNITÉS de produit. C'est
 * la seule lecture cohérente avec ce que l'écran affiche déjà — il ne prétend
 * pas non plus, sans ce filtre, distinguer une inscription à quantité 1 d'une
 * inscription à quantité 5.
 *
 * Cas volontairement non traité : une inscription posée sur un produit
 * variable entier (réglage hôte `variable_any_variation_backinstock`) a pour
 * référence le parent, qui n'a pas de stock propre — `Stock::get()` et
 * `Supply::get()` y répondent toujours 0. Rien n'est donc déduit et la demande
 * reste affichée. Real Stock Manager traite ce cas par un report du reliquat
 * des variations non demandées vers le parent (`BackInStock\Coverage`), mais
 * le rejouer ici exigerait de charger des variations que la matrice ne
 * connaît pas. En cas de doute, ne jamais faire disparaître une demande.
 */
final class SupplyCoverage {

	/**
	 * Nom du paramètre de requête de la case à cocher.
	 */
	private const REQUEST_KEY = 'ebisn_uncovered';

	/**
	 * La case à cocher a-t-elle un sens à afficher ?
	 *
	 * Suppose la dépendance active ET le module correspondant activé — un
	 * marchand peut désactiver ce croisement sans désinstaller Real Stock
	 * Manager, par exemple si les deux plugins servent des besoins distincts.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return RealStockManager::is_active()
			&& null !== Plugin::instance()->get_module( 'stock_coverage' );
	}

	/**
	 * La case est-elle cochée dans la requête courante ?
	 *
	 * @return bool
	 */
	public static function is_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de lecture, sans effet de bord.
		return isset( $_REQUEST[ self::REQUEST_KEY ] );
	}

	/**
	 * Nom du paramètre de requête, pour les classes qui doivent le propager
	 * (export CSV, pagination).
	 *
	 * @return string
	 */
	public static function request_key(): string {
		return self::REQUEST_KEY;
	}

	/**
	 * Déduit la couverture de la matrice.
	 *
	 * @param array<string, mixed> $matrix Matrice complète.
	 *
	 * @return array{0: array<string, mixed>, 1: int} La matrice ajustée, et le
	 *                                                 nombre de demandes retirées.
	 */
	public static function apply( array $matrix ): array {
		$rows = (array) $matrix['rows'];

		if ( empty( $rows ) ) {
			return array( $matrix, 0 );
		}

		$available = RealStockManager::available_for( self::collect_reference_ids( $rows ) );

		$totals   = array();
		$grand    = 0;
		$removed  = 0;
		$adjusted = array();

		foreach ( $rows as $parent_id => $row ) {
			$row   = self::adjust_row( $row, $available, $removed );
			$total = (int) $row['total'];

			if ( $total <= 0 ) {
				continue;
			}

			foreach ( (array) $row['cells'] as $value => $cell ) {
				$totals[ $value ] = ( $totals[ $value ] ?? 0 ) + (int) $cell['count'];
			}

			$grand                 += $total;
			$adjusted[ $parent_id ] = $row;
		}

		$matrix['rows']     = $adjusted;
		$matrix['totals']   = $totals;
		$matrix['grand']    = $grand;
		$matrix['products'] = count( $adjusted );

		return array( $matrix, $removed );
	}

	/**
	 * Ajuste une ligne, cellule par cellule.
	 *
	 * @param array<string, mixed> $row       Ligne d'origine.
	 * @param array<int, int>      $available Disponible par référence de stock.
	 * @param int                  $removed   Compteur de demandes retirées, modifié sur place.
	 *
	 * @return array<string, mixed>
	 */
	private static function adjust_row( array $row, array $available, int &$removed ): array {
		$cells = array();
		$total = 0;

		foreach ( (array) $row['cells'] as $value => $cell ) {
			$targets = ! empty( $cell['pids'] )
				? array_keys( (array) $cell['pids'] )
				: array( (int) $row['parent_id'] );

			$covered = 0;

			foreach ( $targets as $pid ) {
				$covered += (int) ( $available[ (int) $pid ] ?? 0 );
			}

			$original  = (int) $cell['count'];
			$remaining = max( 0, $original - $covered );

			$removed += $original - $remaining;

			if ( $remaining <= 0 ) {
				continue;
			}

			$cells[ $value ] = array(
				'count' => $remaining,
				'pids'  => $cell['pids'],
			);

			$total += $remaining;
		}

		$row['cells'] = $cells;
		$row['total'] = $total;

		return $row;
	}

	/**
	 * Rassemble toutes les références de stock citées par la matrice.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes de la matrice.
	 *
	 * @return int[]
	 */
	private static function collect_reference_ids( array $rows ): array {
		$ids = array();

		foreach ( $rows as $row ) {
			foreach ( (array) $row['cells'] as $cell ) {
				if ( ! empty( $cell['pids'] ) ) {
					$ids = array_merge( $ids, array_keys( (array) $cell['pids'] ) );
				} else {
					// Produit simple, ou variation supprimée depuis
					// l'inscription : même repli que le lien de cellule
					// (SizeMatrixTable::column_default()).
					$ids[] = (int) $row['parent_id'];
				}
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Constructeur privé : classe utilitaire, jamais instanciée.
	 */
	private function __construct() {}
}
