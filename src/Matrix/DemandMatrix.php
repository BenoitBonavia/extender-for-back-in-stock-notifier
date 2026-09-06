<?php
/**
 * Croisement des demandes de réassort par produit et par attribut.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Matrix;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Construit la matrice « produit × valeur d'attribut » des demandes en attente.
 *
 * La collecte est intégrale puis mise en cache, plutôt que paginée en base :
 * le résultat agrégé tient en quelques centaines de lignes, et cela permet
 * d'afficher des totaux justes, indépendants de la page consultée.
 */
final class DemandMatrix {

	/**
	 * Préfixe des entrées de cache.
	 */
	private const TRANSIENT_PREFIX = 'ebisn_matrix_';

	/**
	 * Durée de cache par défaut, en minutes.
	 */
	public const DEFAULT_TTL_MINUTES = 5;

	/**
	 * Statut compté par défaut.
	 *
	 * Seul « en attente » : c'est le comportement du snippet remplacé, et la
	 * seule lecture qui décrive une demande réellement en cours.
	 */
	public const DEFAULT_STATUSES = array( Host::STATUS_SUBSCRIBED );

	/**
	 * Statuts d'inscription comptés.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		$configured = Settings::get( 'matrix_statuses', self::DEFAULT_STATUSES );
		$configured = is_array( $configured ) ? $configured : self::DEFAULT_STATUSES;

		// Un statut inconnu de l'extension hôte ne remonterait rien : l'écarter
		// évite une matrice vide sans explication.
		$statuses = array_values( array_intersect( $configured, Host::statuses() ) );

		return empty( $statuses ) ? self::DEFAULT_STATUSES : $statuses;
	}

	/**
	 * Retourne la matrice, depuis le cache si possible.
	 *
	 * @param bool $refresh Forcer le recalcul.
	 *
	 * @return array<string, mixed>
	 */
	public static function get( bool $refresh = false ): array {
		$key = self::cache_key();

		if ( ! $refresh ) {
			$cached = get_transient( $key );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$data = ( new self() )->collect();
		$ttl  = max( 0, (int) Settings::get( 'matrix_cache_minutes', self::DEFAULT_TTL_MINUTES ) );

		if ( $ttl > 0 ) {
			set_transient( $key, $data, $ttl * MINUTE_IN_SECONDS );
		}

		return $data;
	}

	/**
	 * Vide le cache.
	 */
	public static function flush(): void {
		delete_transient( self::cache_key() );
	}

	/**
	 * Accroche l'invalidation du cache aux événements qui le périment.
	 */
	public static function register_invalidation(): void {
		add_action( Host::HOOK_AFTER_INSERT_SUBSCRIBER, array( self::class, 'flush' ) );
		add_action( 'ebisn_subscription_converted', array( self::class, 'flush' ) );
		add_action( 'ebisn_subscription_reverted', array( self::class, 'flush' ) );
	}

	/**
	 * Clé de cache, dépendante des réglages qui changent le résultat.
	 *
	 * @return string
	 */
	private static function cache_key(): string {
		return self::TRANSIENT_PREFIX . substr(
			md5(
				(string) wp_json_encode(
					array(
						self::statuses(),
						(string) Settings::get( 'matrix_attribute', '' ),
					)
				)
			),
			0,
			20
		);
	}

	/**
	 * Nombre de demandes en file d'envoi.
	 *
	 * Elles n'apparaissent pas dans la matrice tant que « En file » n'est pas
	 * coché : le produit est réapprovisionné et l'alerte part sous peu.
	 *
	 * @return int
	 */
	public static function queued_count(): int {
		global $wpdb;

		if ( in_array( Host::STATUS_QUEUED, self::statuses(), true ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- comptage ponctuel pour un avertissement d'écran.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				Host::SUBSCRIBER_TYPE,
				Host::STATUS_QUEUED
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Construit la matrice.
	 *
	 * @return array<string, mixed>
	 */
	public function collect(): array {
		$empty = array(
			'rows'            => array(),
			'columns'         => array(),
			'has_undefined'   => false,
			'labels'          => array(),
			'totals'          => array(),
			'grand'           => 0,
			'products'        => 0,
			'attribute'       => '',
			'attribute_label' => '',
		);

		$demands = $this->fetch_demands();

		if ( empty( $demands ) ) {
			return $empty;
		}

		$product_ids = array_keys( $demands );
		$posts       = $this->fetch_posts( $product_ids );
		$attributes  = $this->fetch_attributes( $product_ids );

		$resolver = new AttributeResolver();
		$resolver->resolve( $attributes['frequencies'] );
		$resolver->load_terms();

		$meta_key = $resolver->meta_key();

		$rows          = array();
		$totals        = array();
		$grand         = 0;
		$values        = array();
		$has_undefined = false;

		foreach ( $demands as $subscribed_id => $entries ) {
			$post   = $posts[ $subscribed_id ] ?? null;
			$is_var = $post && 'product_variation' === $post['type'];

			foreach ( $entries as $entry ) {
				$parent = $this->resolve_parent( $subscribed_id, (int) $entry['product_id'], $post );
				$count  = (int) $entry['count'];

				$raw = ( '' !== $meta_key && $is_var )
					? trim( (string) ( $attributes['values'][ $subscribed_id ][ $meta_key ] ?? '' ) )
					: '';

				$key = '' === $raw ? AttributeResolver::UNDEFINED : $raw;

				if ( AttributeResolver::UNDEFINED === $key ) {
					$has_undefined = true;
				} else {
					$values[ $key ] = true;
				}

				if ( ! isset( $rows[ $parent ] ) ) {
					$rows[ $parent ] = array(
						// L'identifiant est répété dans la ligne : les rendus de
						// colonne ne reçoivent que la valeur, jamais sa clé.
						'parent_id' => $parent,
						'name'      => $this->product_name( $parent, $posts ),
						'cells'     => array(),
						'total'     => 0,
					);
				}

				if ( isset( $rows[ $parent ]['cells'][ $key ] ) ) {
					$rows[ $parent ]['cells'][ $key ]['count'] += $count;
					// Plusieurs variations partagent cette valeur — une taille
					// déclinée en couleurs, par exemple. Toutes sont conservées
					// pour que le lien de la cellule reste exact.
					$rows[ $parent ]['cells'][ $key ]['pids'][] = $subscribed_id;
				} else {
					$rows[ $parent ]['cells'][ $key ] = array(
						'count' => $count,
						'pids'  => $is_var ? array( $subscribed_id ) : array(),
					);
				}

				$rows[ $parent ]['total'] += $count;
				$totals[ $key ]            = ( $totals[ $key ] ?? 0 ) + $count;
				$grand                    += $count;
			}
		}

		$columns = $resolver->sort_values( array_keys( $values ) );

		$labels = array();

		foreach ( $columns as $column ) {
			$labels[ $column ] = $resolver->value_label( $column );
		}

		$labels[ AttributeResolver::UNDEFINED ] = $resolver->value_label( AttributeResolver::UNDEFINED );

		uasort(
			$rows,
			static function ( array $a, array $b ): int {
				if ( $a['total'] === $b['total'] ) {
					return strcasecmp( $a['name'], $b['name'] );
				}

				return $b['total'] <=> $a['total'];
			}
		);

		return array(
			'rows'            => $rows,
			'columns'         => $columns,
			'has_undefined'   => $has_undefined,
			'labels'          => $labels,
			'totals'          => $totals,
			'grand'           => $grand,
			'products'        => count( $rows ),
			'attribute'       => $meta_key,
			'attribute_label' => $resolver->label(),
		);
	}

	/**
	 * Compte les demandes par produit attendu et par produit parent déclaré.
	 *
	 * Le regroupement porte sur les DEUX métadonnées : l'hôte enregistre le
	 * produit effectivement attendu (`cwginstock_pid`) et, séparément, son
	 * parent (`cwginstock_product_id`). Lire le second évite de dépendre de
	 * `post_parent`, qui disparaît avec la variation.
	 *
	 * @return array<int, array<int, array{product_id:int, count:int}>>
	 */
	private function fetch_demands(): array {
		global $wpdb;

		$statuses     = self::statuses();
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$sql = "SELECT pid.meta_value AS subscribed_id,
					   COALESCE( parent.meta_value, '0' ) AS product_id,
					   COUNT(*) AS demands
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} parent
						 ON parent.post_id = p.ID
						AND parent.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status IN ( {$placeholders} )
				   AND pid.meta_value <> ''
				 GROUP BY pid.meta_value, parent.meta_value";

		$values = array_merge(
			array( Host::META_PID, Host::META_PRODUCT_ID, Host::SUBSCRIBER_TYPE ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; résultat mis en cache par l'appelant.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$demands = array();

		foreach ( (array) $rows as $row ) {
			$subscribed_id = (int) $row->subscribed_id;

			if ( $subscribed_id <= 0 ) {
				continue;
			}

			$demands[ $subscribed_id ][] = array(
				'product_id' => (int) $row->product_id,
				'count'      => (int) $row->demands,
			);
		}

		return $demands;
	}

	/**
	 * Type, parent et titre des produits attendus.
	 *
	 * @param int[] $ids Identifiants.
	 *
	 * @return array<int, array{type:string, parent:int, title:string}>
	 */
	private function fetch_posts( array $ids ): array {
		global $wpdb;

		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- les marqueurs %d sont bien présents, mais construits dynamiquement : le sniff ne les voit pas dans le littéral.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_parent, post_type, post_title FROM {$wpdb->posts} WHERE ID IN ( {$placeholders} )",
				$ids
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$posts = array();

		foreach ( (array) $rows as $row ) {
			$posts[ (int) $row->ID ] = array(
				'type'   => (string) $row->post_type,
				'parent' => (int) $row->post_parent,
				'title'  => (string) $row->post_title,
			);
		}

		return $posts;
	}

	/**
	 * Attributs de variation des produits attendus.
	 *
	 * @param int[] $ids Identifiants.
	 *
	 * @return array{values: array<int, array<string, string>>, frequencies: array<string, array{n:int, num:int}>}
	 */
	private function fetch_attributes( array $ids ): array {
		global $wpdb;

		$out = array(
			'values'      => array(),
			'frequencies' => array(),
		);

		if ( empty( $ids ) ) {
			return $out;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; résultat mis en cache par l'appelant.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value
				   FROM {$wpdb->postmeta}
				  WHERE post_id IN ( {$placeholders} )
					AND meta_key LIKE %s",
				array_merge( $ids, array( $wpdb->esc_like( AttributeResolver::META_PREFIX ) . '%' ) )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		foreach ( (array) $rows as $row ) {
			$post_id  = (int) $row->post_id;
			$meta_key = (string) $row->meta_key;
			$value    = (string) $row->meta_value;

			$out['values'][ $post_id ][ $meta_key ] = $value;

			if ( '' === trim( $value ) ) {
				continue;
			}

			if ( ! isset( $out['frequencies'][ $meta_key ] ) ) {
				$out['frequencies'][ $meta_key ] = array(
					'n'   => 0,
					'num' => 0,
				);
			}

			++$out['frequencies'][ $meta_key ]['n'];

			if ( is_numeric( str_replace( ',', '.', $value ) ) ) {
				++$out['frequencies'][ $meta_key ]['num'];
			}
		}

		return $out;
	}

	/**
	 * Détermine le produit parent auquel rattacher une demande.
	 *
	 * Trois sources, par ordre de fiabilité décroissante : la métadonnée de
	 * l'hôte, qui survit à la suppression de la variation ; le parent du post,
	 * qui ne survit pas ; et enfin le produit attendu lui-même, pour un produit
	 * simple.
	 *
	 * @param int                                               $subscribed_id Produit attendu.
	 * @param int                                               $declared      Parent déclaré par l'hôte.
	 * @param array{type:string, parent:int, title:string}|null $post        Post correspondant.
	 *
	 * @return int
	 */
	private function resolve_parent( int $subscribed_id, int $declared, ?array $post ): int {
		if ( $declared > 0 ) {
			return $declared;
		}

		if ( $post && 'product_variation' === $post['type'] && $post['parent'] > 0 ) {
			return $post['parent'];
		}

		return $subscribed_id;
	}

	/**
	 * Nom affiché d'un produit parent.
	 *
	 * @param int                                                      $parent_id Produit parent.
	 * @param array<int, array{type:string, parent:int, title:string}> $posts     Posts déjà chargés.
	 *
	 * @return string
	 */
	private function product_name( int $parent_id, array $posts ): string {
		$title = get_the_title( $parent_id );

		if ( '' !== trim( (string) $title ) ) {
			return (string) $title;
		}

		if ( isset( $posts[ $parent_id ] ) && '' !== trim( $posts[ $parent_id ]['title'] ) ) {
			return $posts[ $parent_id ]['title'];
		}

		return sprintf(
			/* translators: %d: identifiant du produit supprimé. */
			__( '#%d (produit supprimé)', 'extender-for-back-in-stock-notifier' ),
			$parent_id
		);
	}
}
