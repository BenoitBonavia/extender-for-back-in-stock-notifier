<?php
/**
 * Choix et ordonnancement de l'attribut servant d'axe à la matrice.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Matrix;

use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Détermine quel attribut de variation porte les colonnes, et dans quel ordre
 * les afficher.
 *
 * L'ordre est le point délicat. Trier des tailles alphabétiquement donne
 * « L, M, S, XL, XS », ce qui ne veut rien dire pour personne. On cherche donc,
 * dans l'ordre : l'ordre que le marchand a lui-même défini dans WooCommerce,
 * puis l'ordre numérique, puis une progression de tailles connue, et seulement
 * en dernier recours l'ordre alphabétique.
 */
final class AttributeResolver {

	/**
	 * Préfixe des métadonnées d'attribut portées par une variation.
	 */
	public const META_PREFIX = 'attribute_';

	/**
	 * Clé de la colonne regroupant les demandes sans valeur d'attribut.
	 */
	public const UNDEFINED = '__nd__';

	/**
	 * Fragments de nom trahissant un attribut de taille.
	 *
	 * Sert uniquement à la détection automatique, quand le marchand n'a rien
	 * choisi.
	 */
	private const SIZE_HINTS = array( 'taille', 'size', 'pointure', 'groesse', 'größe', 'talla', 'taglia' );

	/**
	 * Progression canonique des tailles textuelles.
	 *
	 * Filet pour les attributs locaux, que WooCommerce n'ordonne pas : sans
	 * cette table, « XS, S, M, L, XL » repasserait en ordre alphabétique.
	 */
	private const SIZE_SCALE = array(
		'xxxs',
		'xxs',
		'xs',
		's',
		'm',
		'l',
		'xl',
		'xxl',
		'xxxl',
		'4xl',
		'5xl',
		'tu',
		'unique',
	);

	/**
	 * Attribut retenu, métadonnée complète (`attribute_pa_taille`).
	 *
	 * @var string
	 */
	private $meta_key = '';

	/**
	 * Libellés des valeurs, indexés par valeur brute.
	 *
	 * @var array<string, string>
	 */
	private $labels = array();

	/**
	 * Rang de tri des valeurs, indexé par valeur brute.
	 *
	 * @var array<string, int>
	 */
	private $ranks = array();

	/**
	 * Retient un attribut à partir des occurrences relevées.
	 *
	 * @param array<string, array{n:int, num:int}> $frequencies Occurrences par métadonnée.
	 *
	 * @return string Métadonnée retenue, chaîne vide si aucune.
	 */
	public function resolve( array $frequencies ): string {
		$configured = trim( (string) Settings::get( 'matrix_attribute', '' ) );

		if ( '' !== $configured ) {
			$this->meta_key = $this->normalize_meta_key( $configured );

			return $this->meta_key;
		}

		$this->meta_key = $this->detect( $frequencies );

		return $this->meta_key;
	}

	/**
	 * Métadonnée retenue.
	 *
	 * @return string
	 */
	public function meta_key(): string {
		return $this->meta_key;
	}

	/**
	 * Nom lisible de l'attribut retenu.
	 *
	 * @return string
	 */
	public function label(): string {
		if ( '' === $this->meta_key ) {
			return '';
		}

		$taxonomy = $this->taxonomy();

		if ( '' !== $taxonomy && function_exists( 'wc_attribute_label' ) ) {
			return (string) wc_attribute_label( $taxonomy );
		}

		return substr( $this->meta_key, strlen( self::META_PREFIX ) );
	}

	/**
	 * Taxonomie de l'attribut, si c'est un attribut global.
	 *
	 * @return string Chaîne vide pour un attribut local.
	 */
	public function taxonomy(): string {
		if ( '' === $this->meta_key ) {
			return '';
		}

		$name = substr( $this->meta_key, strlen( self::META_PREFIX ) );

		return 0 === strncmp( $name, 'pa_', 3 ) ? $name : '';
	}

	/**
	 * Charge les libellés et l'ordre des valeurs depuis WooCommerce.
	 *
	 * À appeler une fois l'attribut résolu, avant de trier les colonnes.
	 */
	public function load_terms(): void {
		$this->labels = array();
		$this->ranks  = array();

		$taxonomy = $this->taxonomy();

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				// L'ordre voulu par le marchand, tel qu'il l'a réglé dans
				// Produits → Attributs. C'est lui qui sait que S précède M.
				'orderby'    => $this->term_orderby( $taxonomy ),
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		$rank = 0;

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$this->labels[ $term->slug ] = $term->name;
			$this->ranks[ $term->slug ]  = $rank;
			++$rank;
		}
	}

	/**
	 * Libellé d'affichage d'une valeur.
	 *
	 * @param string $value Valeur brute stockée sur la variation.
	 *
	 * @return string
	 */
	public function value_label( string $value ): string {
		if ( self::UNDEFINED === $value ) {
			return __( 'N/D', 'extender-for-back-in-stock-notifier' );
		}

		return $this->labels[ $value ] ?? $value;
	}

	/**
	 * Ordonne les valeurs de colonnes.
	 *
	 * @param string[] $values Valeurs brutes, sans la colonne « N/D ».
	 *
	 * @return string[]
	 */
	public function sort_values( array $values ): array {
		$values = array_values(
			array_unique(
				array_filter(
					$values,
					static function ( string $value ): bool {
						return '' !== $value;
					}
				)
			)
		);

		if ( count( $values ) < 2 ) {
			return $values;
		}

		// 1. L'ordre défini dans WooCommerce, si toutes les valeurs y figurent.
		if ( array() !== $this->ranks && $this->all_ranked( $values ) ) {
			usort(
				$values,
				function ( string $a, string $b ): int {
					return $this->ranks[ $a ] <=> $this->ranks[ $b ];
				}
			);

			return $values;
		}

		// 2. Ordre numérique, virgule décimale tolérée (« 38,5 »).
		if ( $this->all_numeric( $values ) ) {
			usort(
				$values,
				static function ( string $a, string $b ): int {
					return (float) str_replace( ',', '.', $a ) <=> (float) str_replace( ',', '.', $b );
				}
			);

			return $values;
		}

		// 3. Progression de tailles connue, pour les attributs locaux.
		if ( $this->all_on_scale( $values ) ) {
			usort(
				$values,
				function ( string $a, string $b ): int {
					return $this->scale_index( $a ) <=> $this->scale_index( $b );
				}
			);

			return $values;
		}

		// 4. Ordre naturel, dernier recours.
		natcasesort( $values );

		return array_values( $values );
	}

	/**
	 * Toutes les valeurs sont-elles connues de WooCommerce ?
	 *
	 * Une seule valeur absente — un terme supprimé depuis l'inscription —
	 * disqualifie cet ordre : la placer arbitrairement en tête ou en fin
	 * fausserait la lecture de toute la ligne.
	 *
	 * @param string[] $values Valeurs.
	 *
	 * @return bool
	 */
	private function all_ranked( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! isset( $this->ranks[ $value ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Toutes les valeurs sont-elles numériques ?
	 *
	 * @param string[] $values Valeurs.
	 *
	 * @return bool
	 */
	private function all_numeric( array $values ): bool {
		foreach ( $values as $value ) {
			if ( ! is_numeric( str_replace( ',', '.', $value ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Toutes les valeurs appartiennent-elles à la progression canonique ?
	 *
	 * @param string[] $values Valeurs.
	 *
	 * @return bool
	 */
	private function all_on_scale( array $values ): bool {
		foreach ( $values as $value ) {
			if ( $this->scale_index( $value ) < 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Position d'une valeur dans la progression canonique.
	 *
	 * @param string $value Valeur brute ou libellé.
	 *
	 * @return int `-1` si la valeur n'y figure pas.
	 */
	private function scale_index( string $value ): int {
		$needle = strtolower( trim( remove_accents( $this->value_label( $value ) ) ) );
		$needle = str_replace( array( ' ', '-', '_' ), '', $needle );

		$index = array_search( $needle, self::SIZE_SCALE, true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Critère de tri des termes configuré pour une taxonomie d'attribut.
	 *
	 * @param string $taxonomy Taxonomie.
	 *
	 * @return string Critère accepté par `get_terms()`.
	 */
	private function term_orderby( string $taxonomy ): string {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return 'name';
		}

		$name = 0 === strncmp( $taxonomy, 'pa_', 3 ) ? substr( $taxonomy, 3 ) : $taxonomy;

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! isset( $attribute->attribute_name ) || $attribute->attribute_name !== $name ) {
				continue;
			}

			$orderby = isset( $attribute->attribute_orderby ) ? (string) $attribute->attribute_orderby : '';

			switch ( $orderby ) {
				case 'menu_order':
					return 'menu_order';

				case 'name_num':
					// WooCommerce traite ce cas par un filtre sur les clauses ;
					// le tri numérique de sort_values() prend le relais.
					return 'name';

				case 'id':
					return 'id';

				default:
					return 'name';
			}
		}

		return 'name';
	}

	/**
	 * Complète un nom d'attribut pour en faire une clé de métadonnée.
	 *
	 * Accepte indifféremment « taille », « pa_taille » ou
	 * « attribute_pa_taille » : le marchand ne devrait pas avoir à connaître
	 * la convention de stockage de WooCommerce.
	 *
	 * @param string $name Nom saisi.
	 *
	 * @return string
	 */
	private function normalize_meta_key( string $name ): string {
		$name = trim( $name );

		if ( 0 === strncmp( $name, self::META_PREFIX, strlen( self::META_PREFIX ) ) ) {
			return $name;
		}

		return self::META_PREFIX . $name;
	}

	/**
	 * Devine l'attribut portant la taille.
	 *
	 * Reprend la logique du snippet remplacé : un nom évocateur d'abord, puis
	 * l'attribut majoritairement numérique le plus représenté, enfin le plus
	 * fréquent.
	 *
	 * @param array<string, array{n:int, num:int}> $frequencies Occurrences par métadonnée.
	 *
	 * @return string
	 */
	private function detect( array $frequencies ): string {
		if ( empty( $frequencies ) ) {
			return '';
		}

		foreach ( array_keys( $frequencies ) as $meta_key ) {
			$slug = strtolower( remove_accents( substr( $meta_key, strlen( self::META_PREFIX ) ) ) );

			foreach ( self::SIZE_HINTS as $hint ) {
				if ( false !== strpos( $slug, remove_accents( $hint ) ) ) {
					return $meta_key;
				}
			}
		}

		$best  = '';
		$count = 0;

		foreach ( $frequencies as $meta_key => $data ) {
			if ( $data['n'] > 0 && ( $data['num'] / $data['n'] ) >= 0.8 && $data['n'] > $count ) {
				$best  = $meta_key;
				$count = $data['n'];
			}
		}

		if ( '' !== $best ) {
			return $best;
		}

		foreach ( $frequencies as $meta_key => $data ) {
			if ( $data['n'] > $count ) {
				$best  = $meta_key;
				$count = $data['n'];
			}
		}

		return $best;
	}
}
