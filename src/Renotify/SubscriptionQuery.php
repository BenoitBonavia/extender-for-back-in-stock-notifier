<?php
/**
 * Lectures d'inscriptions pour la renotification.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Renotify;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Retrouve les inscriptions à remettre en attente.
 */
final class SubscriptionQuery {

	/**
	 * Inscriptions notifiées portant sur un produit ou une déclinaison.
	 *
	 * L'appariement se fait sur `cwginstock_pid` — le produit réellement
	 * attendu — et sur `cwginstock_bypass_pid`, que l'hôte pose sur les inscrits
	 * « produit parent » notifiés via une déclinaison précise. Sans ce second
	 * cas, ces personnes ne seraient jamais reprises.
	 *
	 * Les inscriptions ayant épuisé leur quota de renotifications sont écartées
	 * ici, et non à l'écriture : restées en tête du tri, elles occuperaient
	 * indéfiniment le lot et empêcheraient les suivantes d'être vues.
	 *
	 * @param int $product_id Produit ou déclinaison passé en rupture.
	 * @param int $limit      Nombre maximal d'inscriptions retournées.
	 *
	 * @return int[]
	 */
	public function notified_for_product( int $product_id, int $limit = 500 ): array {
		global $wpdb;

		$statuses = RenotifyService::source_statuses();

		if ( $product_id <= 0 || empty( $statuses ) ) {
			return array();
		}

		$max    = RenotifyService::max_cycles();
		$values = array( Host::META_PID, Host::META_BYPASS_PID );
		$join   = '';
		$cap    = '';

		if ( $max > 0 ) {
			$join     = " LEFT JOIN {$wpdb->postmeta} cycles
						 ON cycles.post_id = p.ID
						AND cycles.meta_key = %s";
			$cap      = " AND CAST( COALESCE( NULLIF( cycles.meta_value, '' ), '0' ) AS UNSIGNED ) < %d";
			$values[] = RenotifyService::META_CYCLES;
		}

		$sql = "SELECT DISTINCT p.ID
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key IN ( %s, %s )
				 {$join}
				 WHERE p.post_type = %s
				   AND pid.meta_value = %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . " )
				   {$cap}
				 ORDER BY p.ID ASC
				 LIMIT %d";

		$values = array_merge(
			$values,
			array( Host::SUBSCRIBER_TYPE, $product_id ),
			$statuses,
			$max > 0 ? array( $max ) : array(),
			array( max( 1, $limit ) )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Lot d'inscriptions notifiées, pour le parcours du rattrapage.
	 *
	 * @param int $cursor Dernière inscription traitée.
	 * @param int $limit  Taille du lot.
	 *
	 * @return array<int, array{id:int, pid:int}>
	 */
	public function notified_batch( int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = RenotifyService::source_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$sql = "SELECT p.ID, pid.meta_value AS pid
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.ID > %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				 ORDER BY p.ID ASC
				 LIMIT %d';

		$values = array_merge(
			array( Host::META_PID, Host::SUBSCRIBER_TYPE, $cursor ),
			$statuses,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; parcours par curseur.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$batch = array();

		foreach ( (array) $rows as $row ) {
			$batch[] = array(
				'id'  => (int) $row->ID,
				'pid' => (int) $row->pid,
			);
		}

		return $batch;
	}

	/**
	 * Nombre d'inscriptions notifiées dont le produit est indisponible.
	 *
	 * Sert à annoncer l'ampleur du rattrapage AVANT de le lancer : celui-ci peut
	 * provoquer un envoi massif au prochain réassort de chaque produit concerné.
	 *
	 * Le décompte échantillonne au lieu de tout charger : un chiffre exact
	 * exigerait d'instancier chaque produit, pour un ordre de grandeur qui
	 * suffit à décider.
	 *
	 * @param int $sample Nombre d'inscriptions examinées au plus.
	 *
	 * @return int
	 */
	public function count_out_of_stock( int $sample = 2000 ): int {
		$cursor = 0;
		$found  = 0;
		$seen   = 0;

		while ( $seen < $sample ) {
			$batch = $this->notified_batch( $cursor, 200 );

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $row ) {
				++$seen;

				if ( self::is_out_of_stock( $row['pid'] ) ) {
					++$found;
				}
			}

			$cursor = (int) end( $batch )['id'];
		}

		return $found;
	}

	/**
	 * Le produit attendu est-il indisponible ?
	 *
	 * Un produit supprimé n'est pas « indisponible » : il n'a plus de retour en
	 * stock à espérer, et remettre son inscription en attente ne ferait que la
	 * laisser en suspens indéfiniment.
	 *
	 * @param int $product_id Produit ou déclinaison attendu.
	 *
	 * @return bool
	 */
	public static function is_out_of_stock( int $product_id ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}

		$product = wc_get_product( $product_id );

		return $product instanceof \WC_Product && ! $product->is_in_stock();
	}
}
