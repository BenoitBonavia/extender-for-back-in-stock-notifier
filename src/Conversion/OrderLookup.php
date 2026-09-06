<?php
/**
 * Recherche de commandes pour le rattrapage.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Retrouve, pour un lot d'acheteurs, les commandes et les produits achetés.
 *
 * Le rattrapage parcourt les INSCRIPTIONS et vient demander ici les commandes
 * correspondantes — l'inverse de ce que faisaient les snippets, qui balayaient
 * l'historique des commandes en interrogeant les inscriptions une par une.
 *
 * Deux requêtes par lot, quelle que soit sa taille : les commandes, puis leurs
 * lignes. La table `woocommerce_order_items` étant commune aux deux modes de
 * stockage, seule la première requête a besoin de distinguer HPOS du stockage
 * historique en `posts`.
 */
final class OrderLookup {

	/**
	 * Commandes passées par un ensemble d'acheteurs.
	 *
	 * @param string[] $emails   Adresses normalisées.
	 * @param int[]    $user_ids Comptes clients.
	 *
	 * @return array<int, array{id:int, email:string, user_id:int, created_at:int, products:int[]}>
	 */
	public function find_orders_for( array $emails, array $user_ids ): array {
		$emails   = array_values( array_unique( array_filter( $emails ) ) );
		$user_ids = array_values( array_unique( array_filter( array_map( 'intval', $user_ids ) ) ) );

		if ( empty( $emails ) && empty( $user_ids ) ) {
			return array();
		}

		$orders = $this->query_orders( $emails, $user_ids );

		if ( empty( $orders ) ) {
			return array();
		}

		$products = $this->query_order_products( array_keys( $orders ) );

		foreach ( $products as $order_id => $ids ) {
			if ( isset( $orders[ $order_id ] ) ) {
				$orders[ $order_id ]['products'] = $ids;
			}
		}

		return $orders;
	}

	/**
	 * WooCommerce stocke-t-il ses commandes dans ses propres tables ?
	 *
	 * @return bool
	 */
	private function uses_custom_tables(): bool {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ) {
			return false;
		}

		return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/**
	 * Charge les commandes éligibles des acheteurs demandés.
	 *
	 * @param string[] $emails   Adresses normalisées.
	 * @param int[]    $user_ids Comptes clients.
	 *
	 * @return array<int, array{id:int, email:string, user_id:int, created_at:int, products:int[]}>
	 */
	private function query_orders( array $emails, array $user_ids ): array {
		global $wpdb;

		$statuses = OrderMatcher::order_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$custom_tables = $this->uses_custom_tables();

		// Les statuts sont préfixés `wc-` dans les deux schémas de stockage.
		$prefixed = array_map(
			static function ( string $status ): string {
				return 'wc-' . $status;
			},
			$statuses
		);

		$identity = array();
		$params   = array();

		if ( ! empty( $emails ) ) {
			$identity[] = ( $custom_tables ? 'LOWER( o.billing_email )' : 'LOWER( em.meta_value )' )
				. ' IN ( ' . implode( ', ', array_fill( 0, count( $emails ), '%s' ) ) . ' )';
			$params     = array_merge( $params, $emails );
		}

		if ( ! empty( $user_ids ) ) {
			$identity[] = ( $custom_tables ? 'o.customer_id' : 'cm.meta_value' )
				. ' IN ( ' . implode( ', ', array_fill( 0, count( $user_ids ), '%d' ) ) . ' )';
			$params     = array_merge( $params, $user_ids );
		}

		$status_placeholders = implode( ', ', array_fill( 0, count( $prefixed ), '%s' ) );

		if ( $custom_tables ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			$sql = "SELECT o.id AS id,
						   o.billing_email AS email,
						   o.customer_id AS user_id,
						   UNIX_TIMESTAMP( o.date_created_gmt ) AS created_at
					  FROM {$orders_table} o
					 WHERE o.type = 'shop_order'
					   AND o.status IN ( {$status_placeholders} )
					   AND ( " . implode( ' OR ', $identity ) . ' )';

			$values = array_merge( $prefixed, $params );
		} else {
			$sql = "SELECT p.ID AS id,
						   em.meta_value AS email,
						   COALESCE( cm.meta_value, '0' ) AS user_id,
						   UNIX_TIMESTAMP( p.post_date_gmt ) AS created_at
					  FROM {$wpdb->posts} p
					  LEFT JOIN {$wpdb->postmeta} em
							 ON em.post_id = p.ID
							AND em.meta_key = '_billing_email'
					  LEFT JOIN {$wpdb->postmeta} cm
							 ON cm.post_id = p.ID
							AND cm.meta_key = '_customer_user'
					 WHERE p.post_type = 'shop_order'
					   AND p.post_status IN ( {$status_placeholders} )
					   AND ( " . implode( ' OR ', $identity ) . ' )';

			$values = array_merge( $prefixed, $params );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$orders = array();

		foreach ( (array) $rows as $row ) {
			$orders[ (int) $row->id ] = array(
				'id'         => (int) $row->id,
				'email'      => OrderMatcher::normalize_email( (string) $row->email ),
				'user_id'    => (int) $row->user_id,
				'created_at' => (int) $row->created_at,
				'products'   => array(),
			);
		}

		return $orders;
	}

	/**
	 * Produits et variations achetés, par commande.
	 *
	 * @param int[] $order_ids Commandes.
	 *
	 * @return array<int, int[]>
	 */
	private function query_order_products( array $order_ids ): array {
		global $wpdb;

		if ( empty( $order_ids ) ) {
			return array();
		}

		$items_table    = $wpdb->prefix . 'woocommerce_order_items';
		$itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$placeholders   = implode( ', ', array_fill( 0, count( $order_ids ), '%d' ) );

		$sql = "SELECT oi.order_id AS order_id,
					   MAX( CASE WHEN oim.meta_key = '_product_id' THEN oim.meta_value END ) AS product_id,
					   MAX( CASE WHEN oim.meta_key = '_variation_id' THEN oim.meta_value END ) AS variation_id
				  FROM {$items_table} oi
				 INNER JOIN {$itemmeta_table} oim
						 ON oim.order_item_id = oi.order_item_id
				 WHERE oi.order_id IN ( {$placeholders} )
				   AND oi.order_item_type = 'line_item'
				 GROUP BY oi.order_item_id, oi.order_id";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $order_ids ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$products = array();

		foreach ( (array) $rows as $row ) {
			$order_id = (int) $row->order_id;

			foreach ( array( (int) $row->variation_id, (int) $row->product_id ) as $id ) {
				if ( $id > 0 ) {
					$products[ $order_id ][ $id ] = $id;
				}
			}
		}

		return array_map( 'array_values', $products );
	}
}
