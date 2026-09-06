<?php
/**
 * Lectures sur les inscriptions de l'extension hôte.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Toutes les requêtes de lecture d'inscriptions du module de conversion.
 *
 * En SQL préparé plutôt qu'en `WP_Query` : les `meta_query` de l'hôte imposent
 * une jointure par critère sur `postmeta`, dont `meta_value` n'est pas indexé.
 * On tire aussi parti d'un détail du stockage de l'hôte — l'adresse est
 * recopiée dans `post_title` — ce qui évite une jointure de plus.
 */
final class SubscriptionRepository {

	/**
	 * Inscriptions convertibles correspondant à un acheteur et à des produits.
	 *
	 * @param int[]  $product_ids Produits et variations présents dans la commande.
	 * @param string $email       Adresse de facturation, normalisée.
	 * @param int    $user_id     Compte client, `0` pour une commande invitée.
	 * @param int    $ordered_at  Création de la commande, horodatage UNIX UTC.
	 *
	 * @return int[] Identifiants d'inscriptions, de la plus ancienne à la plus récente.
	 */
	public function find_convertible_for_purchase( array $product_ids, string $email, int $user_id, int $ordered_at ): array {
		global $wpdb;

		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );
		$statuses    = ConversionService::convertible_statuses();

		if ( empty( $product_ids ) || empty( $statuses ) ) {
			return array();
		}

		/*
		 * Identité : l'adresse, ou le compte client s'il existe. Le test sur le
		 * compte n'est ajouté QUE si l'identifiant est réel — le chercher à `0`
		 * remonterait toutes les inscriptions de visiteurs non connectés, quelle
		 * que soit leur adresse.
		 */
		$identity = array();
		$params   = array();

		if ( '' !== $email ) {
			$identity[] = 'p.post_title = %s';
			$params[]   = $email;
		}

		if ( $user_id > 0 ) {
			$identity[] = 'uid.meta_value = %d';
			$params[]   = $user_id;
		}

		if ( empty( $identity ) ) {
			return array();
		}

		$sql = "SELECT p.ID
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
				         ON pid.post_id = p.ID
				        AND pid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} uid
				         ON uid.post_id = p.ID
				        AND uid.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				   AND pid.meta_value IN ( ' . implode( ', ', array_fill( 0, count( $product_ids ), '%d' ) ) . ' )
				   AND p.post_date_gmt <= %s
				   AND ( ' . implode( ' OR ', $identity ) . ' )
				 ORDER BY p.ID ASC';

		$values = array_merge(
			array( Host::META_PID, Host::META_USER_ID, Host::SUBSCRIBER_TYPE ),
			$statuses,
			$product_ids,
			array( gmdate( 'Y-m-d H:i:s', $ordered_at ) ),
			$params
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Lot d'inscriptions convertibles, pour le parcours du rattrapage.
	 *
	 * Le curseur porte sur `ID`, donc sur la clé primaire : la pagination reste
	 * constante quel que soit l'avancement, là où un `OFFSET` coûte de plus en
	 * plus cher à mesure que le travail progresse.
	 *
	 * @param int $cursor Dernier identifiant traité.
	 * @param int $limit  Taille du lot.
	 *
	 * @return array<int, array{id:int, email:string, user_id:int, pid:int, subscribed_at:int}>
	 */
	public function get_convertible_batch( int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = ConversionService::convertible_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$sql = "SELECT p.ID,
					   p.post_title AS email,
					   UNIX_TIMESTAMP( p.post_date_gmt ) AS subscribed_at,
					   pid.meta_value AS pid,
					   COALESCE( uid.meta_value, '0' ) AS user_id
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} uid
						 ON uid.post_id = p.ID
						AND uid.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.ID > %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				 ORDER BY p.ID ASC
				 LIMIT %d';

		$values = array_merge(
			array( Host::META_PID, Host::META_USER_ID, Host::SUBSCRIBER_TYPE, $cursor ),
			$statuses,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$batch = array();

		foreach ( (array) $rows as $row ) {
			$batch[] = array(
				'id'            => (int) $row->ID,
				'email'         => OrderMatcher::normalize_email( (string) $row->email ),
				'user_id'       => (int) $row->user_id,
				'pid'           => (int) $row->pid,
				'subscribed_at' => (int) $row->subscribed_at,
			);
		}

		return $batch;
	}

	/**
	 * Nombre d'inscriptions convertibles restantes.
	 *
	 * @return int
	 */
	public function count_convertible(): int {
		global $wpdb;

		$statuses = ConversionService::convertible_statuses();

		if ( empty( $statuses ) ) {
			return 0;
		}

		$sql = "SELECT COUNT(*)
				  FROM {$wpdb->posts} p
				 WHERE p.post_type = %s
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$count = $wpdb->get_var(
			$wpdb->prepare( $sql, array_merge( array( Host::SUBSCRIBER_TYPE ), $statuses ) )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * Inscriptions converties par une commande donnée.
	 *
	 * Sert au retour en arrière : remboursement, annulation, mise à la corbeille.
	 *
	 * @param int $order_id Commande.
	 *
	 * @return int[]
	 */
	public function find_converted_for_order( int $order_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture ponctuelle déclenchée par un changement de statut de commande.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				   FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pm
						  ON pm.post_id = p.ID
						 AND pm.meta_key = %s
				  WHERE p.post_type = %s
					AND p.post_status = %s
					AND pm.meta_value = %s",
				MetaKeys::ORDER_ID,
				Host::SUBSCRIBER_TYPE,
				Host::STATUS_CONVERTED,
				(string) $order_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array_map( 'intval', (array) $ids );
	}
}
