<?php
/**
 * Indicateurs de valeur des listes d'attente.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Calcule la valeur en attente, le chiffre d'affaires récupéré et le taux de
 * conversion des listes d'attente.
 *
 * Deux familles de chiffres, qui ne se lisent pas de la même façon :
 *
 * - **Valeur catalogue** (en attente, non récupéré) : prix courant du produit
 *   multiplié par les quantités demandées. C'est une estimation de potentiel,
 *   pas un montant encaissé.
 * - **Chiffre d'affaires récupéré** : montants réels, lus dans les commandes.
 *   Deux attributions y sont proposées, parce qu'aucune n'est vraie seule —
 *   la stricte ne compte que la ligne du produit attendu et sous-estime, la
 *   large compte la commande entière et surestime. L'encaissement réel est
 *   entre les deux.
 */
final class Stats {

	/**
	 * Clé du cache.
	 */
	private const TRANSIENT = 'ebisn_stats';

	/**
	 * Durée de vie du cache, en secondes.
	 *
	 * Le cache est de toute façon invalidé à chaque conversion : cette durée
	 * n'est qu'un filet pour les changements que l'on n'observe pas, comme une
	 * modification de prix ou une commande éditée à la main.
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Retourne les indicateurs, depuis le cache si possible.
	 *
	 * @param bool $refresh Forcer le recalcul.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$stats = self::compute();

		set_transient( self::TRANSIENT, $stats, self::TTL );

		return $stats;
	}

	/**
	 * Vide le cache.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Accroche l'invalidation du cache aux événements qui le périment.
	 */
	public static function register_invalidation(): void {
		add_action( 'ebisn_subscription_converted', array( self::class, 'flush' ) );
		add_action( 'ebisn_subscription_reverted', array( self::class, 'flush' ) );
	}

	/**
	 * Calcule l'ensemble des indicateurs.
	 *
	 * @return array<string, mixed>
	 */
	private static function compute(): array {
		$pending   = self::catalogue_value( array( Host::STATUS_SUBSCRIBED, Host::STATUS_QUEUED ) );
		$notified  = self::catalogue_value( array( Host::STATUS_MAILSENT ) );
		$recovered = self::recovered_revenue();

		/*
		 * Le dénominateur ne retient que les inscrits ayant reçu l'alerte : ceux
		 * qui attendent encore n'ont pas eu l'occasion de commander, les compter
		 * écraserait le taux sans rien dire de la performance des alertes.
		 */
		$opportunity = (int) $recovered['subs'] + (int) $notified['subs'];
		$rate        = $opportunity > 0 ? ( (int) $recovered['subs'] / $opportunity ) * 100 : 0.0;

		return array(
			'pending'     => $pending,
			'notified'    => $notified,
			'recovered'   => $recovered,
			'rate'        => $rate,
			'opportunity' => $opportunity,
			'computed_at' => time(),
		);
	}

	/**
	 * Valorise au prix catalogue les inscriptions d'un ensemble de statuts.
	 *
	 * Une seule requête agrégée : une ligne par produit, et non par inscription.
	 *
	 * @param string[] $statuses Statuts d'inscription.
	 *
	 * @return array{value:float, subs:int, units:int, products:int, orphans:int, no_price:int}
	 */
	private static function catalogue_value( array $statuses ): array {
		global $wpdb;

		$out = array(
			'value'    => 0.0,
			'subs'     => 0,
			'units'    => 0,
			'products' => 0,
			'orphans'  => 0,
			'no_price' => 0,
		);

		if ( empty( $statuses ) || ! function_exists( 'wc_get_product' ) ) {
			return $out;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT pid.meta_value AS pid,
					   COUNT(*) AS nb_subs,
					   SUM( GREATEST( CAST( COALESCE( NULLIF( qty.meta_value, '' ), '1' ) AS UNSIGNED ), 1 ) ) AS nb_units
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} qty
						 ON qty.post_id = p.ID
						AND qty.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status IN ( {$placeholders} )
				   AND pid.meta_value <> ''
				 GROUP BY pid.meta_value";

		$values = array_merge(
			array( Host::META_PID, Host::META_QUANTITY, Host::SUBSCRIBER_TYPE ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; résultat mis en cache par l'appelant.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		foreach ( (array) $rows as $row ) {
			$nb_subs  = (int) $row->nb_subs;
			$nb_units = (int) $row->nb_units;

			$out['subs'] += $nb_subs;

			$product = wc_get_product( (int) $row->pid );

			if ( ! $product ) {
				// Produit supprimé depuis l'inscription : rien à valoriser.
				$out['orphans'] += $nb_subs;
				continue;
			}

			++$out['products'];
			$out['units'] += $nb_units;

			$price = $product->get_price();

			if ( '' === $price || null === $price ) {
				$out['no_price'] += $nb_subs;
				continue;
			}

			$out['value'] += (float) $price * $nb_units;
		}

		return $out;
	}

	/**
	 * Chiffre d'affaires récupéré grâce aux alertes, selon deux attributions.
	 *
	 * @return array{strict:float, broad:float, subs:int, orders:int, lines:int, unlinked:int, missing:int}
	 */
	private static function recovered_revenue(): array {
		global $wpdb;

		$data = array(
			'strict'   => 0.0,
			'broad'    => 0.0,
			'subs'     => 0,
			'orders'   => 0,
			'lines'    => 0,
			'unlinked' => 0,
			'missing'  => 0,
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- résultat mis en cache par l'appelant.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID AS sid,
						pid.meta_value AS pid,
						oid.meta_value AS order_id
				   FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pid
						  ON pid.post_id = p.ID
						 AND pid.meta_key = %s
				   LEFT JOIN {$wpdb->postmeta} oid
						  ON oid.post_id = p.ID
						 AND oid.meta_key = %s
				  WHERE p.post_type = %s
					AND p.post_status = %s",
				Host::META_PID,
				MetaKeys::ORDER_ID,
				Host::SUBSCRIBER_TYPE,
				Host::STATUS_CONVERTED
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) || ! function_exists( 'wc_get_order' ) ) {
			return $data;
		}

		$deduct_refunds = Settings::get_bool( 'stats_deduct_refunds', false );
		$orders         = array();
		$counted_lines  = array();

		foreach ( $rows as $row ) {
			++$data['subs'];

			$order_id = (int) $row->order_id;

			if ( $order_id <= 0 ) {
				// Conversion héritée d'un snippet, ou commande jamais rattachée.
				++$data['unlinked'];
				continue;
			}

			if ( ! array_key_exists( $order_id, $orders ) ) {
				$order               = wc_get_order( $order_id );
				$orders[ $order_id ] = $order instanceof \WC_Order ? $order : false;
			}

			$order = $orders[ $order_id ];

			if ( ! $order ) {
				++$data['missing'];
				continue;
			}

			$data['strict'] += self::line_total( $order, (int) $row->pid, $counted_lines, $data['lines'], $deduct_refunds );
		}

		foreach ( $orders as $order ) {
			if ( ! $order ) {
				continue;
			}

			++$data['orders'];

			$total = (float) $order->get_total();

			if ( $deduct_refunds ) {
				$total -= (float) $order->get_total_refunded();
			}

			$data['broad'] += $total;
		}

		return $data;
	}

	/**
	 * Montant de la ligne de commande correspondant au produit attendu.
	 *
	 * @param \WC_Order          $order          Commande.
	 * @param int                $pid            Produit ou variation attendu.
	 * @param array<string,bool> $counted_lines  Lignes déjà attribuées, par référence.
	 * @param int                $lines          Compteur de lignes, par référence.
	 * @param bool               $deduct_refunds Déduire les remboursements.
	 *
	 * @return float
	 */
	private static function line_total( \WC_Order $order, int $pid, array &$counted_lines, int &$lines, bool $deduct_refunds ): float {
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$key = $order->get_id() . ':' . $item_id;

			/*
			 * Un client inscrit à la fois sur le produit parent et sur une de ses
			 * variations produit deux conversions qui désignent la même ligne de
			 * commande : elle ne doit être comptée qu'une fois.
			 */
			if ( isset( $counted_lines[ $key ] ) ) {
				continue;
			}

			if ( (int) $item->get_variation_id() !== $pid && (int) $item->get_product_id() !== $pid ) {
				continue;
			}

			$counted_lines[ $key ] = true;
			++$lines;

			$total = (float) $item->get_total() + (float) $item->get_total_tax();

			if ( $deduct_refunds ) {
				$total -= (float) $order->get_total_refunded_for_item( $item_id );
			}

			return $total;
		}

		return 0.0;
	}
}
