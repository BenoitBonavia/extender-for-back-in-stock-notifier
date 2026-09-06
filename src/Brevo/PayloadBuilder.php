<?php
/**
 * Construction des attributs de contact Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Conversion\OrderMatcher;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Integration\Brevo;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Compose les attributs Brevo d'une adresse, à partir de TOUTES ses attentes.
 *
 * C'est le correctif central de la synchronisation. Les snippets construisaient
 * les attributs depuis la seule inscription qui venait d'être créée : une
 * personne inscrite sur trois produits voyait donc les attributs du dernier
 * écraser les précédents, sans qu'aucun ordre ne soit garanti — et la valeur
 * restait figée sur un produit déjà réapprovisionné.
 *
 * Ici, les attributs décrivent l'état courant de l'attente : ils sont
 * recalculés à chaque synchronisation depuis toutes les inscriptions actives,
 * et se vident d'eux-mêmes quand la personne n'attend plus rien.
 */
final class PayloadBuilder {

	/**
	 * Nombre maximal de produits listés dans l'attribut.
	 *
	 * Un attribut texte Brevo n'est pas extensible à l'infini, et une liste de
	 * cinquante produits n'a aucune valeur dans un e-mail.
	 */
	private const MAX_LISTED_PRODUCTS = 5;

	/**
	 * Séparateur des produits listés.
	 */
	private const SEPARATOR = ' | ';

	/**
	 * Statuts d'inscription considérés comme une attente en cours.
	 *
	 * @return string[]
	 */
	public static function active_statuses(): array {
		/**
		 * Statuts d'inscription synchronisés vers Brevo.
		 *
		 * @param string[] $statuses Statuts considérés comme une attente active.
		 */
		return (array) apply_filters(
			'ebisn_brevo_active_statuses',
			array( Host::STATUS_SUBSCRIBED, Host::STATUS_QUEUED, Host::STATUS_MAILSENT )
		);
	}

	/**
	 * Construit les attributs d'une adresse.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return array<string, scalar> Attributs, vide si l'adresse n'attend rien.
	 */
	public function build( string $email ): array {
		$waits  = $this->active_waits( $email );
		$prefix = Brevo::attribute_prefix();

		if ( empty( $waits ) ) {
			return array();
		}

		$names = array();
		$skus  = array();
		$sizes = array();
		$urls  = array();

		foreach ( $waits as $wait ) {
			$product = wc_get_product( $wait['pid'] );

			if ( ! $product ) {
				// Produit supprimé : rien de fiable à envoyer pour cette attente.
				continue;
			}

			$names[] = $this->product_name( $product );
			$sku     = (string) $product->get_sku();
			$size    = $this->variation_size( $product );
			$url     = (string) $product->get_permalink();

			if ( '' !== $sku ) {
				$skus[] = $sku;
			}

			if ( '' !== $size ) {
				$sizes[] = $size;
			}

			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$attributes = array(
			'PRODUIT' => $this->join( $names ),
			'SKU'     => $this->join( $skus ),
			'TAILLE'  => $this->join( $sizes ),
			// Une seule URL : celle de l'attente la plus récente, la seule qui
			// puisse servir de lien d'appel à l'action dans un e-mail.
			'URL'     => $urls[0] ?? '',
			'NB'      => count( $waits ),
			'DATE'    => gmdate( 'Y-m-d', (int) $waits[0]['subscribed_at'] ),
		);

		$prefixed = array();

		foreach ( $attributes as $key => $value ) {
			/*
			 * Un attribut vide n'est PAS envoyé : sur `POST /contacts`, une chaîne
			 * vide écrase la valeur déjà présente chez Brevo. Omettre la clé la
			 * laisse intacte.
			 */
			if ( '' === $value ) {
				continue;
			}

			$prefixed[ $prefix . $key ] = $value;
		}

		/**
		 * Attributs Brevo d'une adresse.
		 *
		 * @param array<string, scalar> $prefixed Attributs préfixés.
		 * @param string                $email    Adresse concernée.
		 * @param array<int, array>     $waits    Attentes actives de cette adresse.
		 */
		return (array) apply_filters( 'ebisn_brevo_attributes', $prefixed, $email, $waits );
	}

	/**
	 * Noms d'attributs gérés par ce plugin, préfixe compris.
	 *
	 * @return array<string, string> Nom d'attribut => type Brevo.
	 */
	public static function attribute_types(): array {
		$prefix = Brevo::attribute_prefix();

		return array(
			$prefix . 'PRODUIT' => 'text',
			$prefix . 'SKU'     => 'text',
			$prefix . 'TAILLE'  => 'text',
			$prefix . 'URL'     => 'text',
			$prefix . 'NB'      => 'float',
			$prefix . 'DATE'    => 'date',
		);
	}

	/**
	 * Attentes actives d'une adresse, de la plus récente à la plus ancienne.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return array<int, array{id:int, pid:int, subscribed_at:int}>
	 */
	public function active_waits( string $email ): array {
		global $wpdb;

		$statuses = self::active_statuses();

		if ( '' === $email || empty( $statuses ) ) {
			return array();
		}

		$sql = "SELECT p.ID,
					   pid.meta_value AS pid,
					   UNIX_TIMESTAMP( p.post_date_gmt ) AS subscribed_at
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_title = %s
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				 ORDER BY p.ID DESC';

		$values = array_merge(
			array( Host::META_PID, Host::SUBSCRIBER_TYPE, $email ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$waits = array();

		foreach ( (array) $rows as $row ) {
			$waits[] = array(
				'id'            => (int) $row->ID,
				'pid'           => (int) $row->pid,
				'subscribed_at' => (int) $row->subscribed_at,
			);
		}

		return $waits;
	}

	/**
	 * Nom lisible d'un produit attendu.
	 *
	 * Le nom du parent pour une variation : « Robe Léa » plutôt que
	 * « Robe Léa - 38 », la taille étant déjà portée par son propre attribut.
	 *
	 * @param \WC_Product $product Produit ou variation.
	 *
	 * @return string
	 */
	private function product_name( \WC_Product $product ): string {
		if ( $product instanceof \WC_Product_Variation ) {
			$parent = wc_get_product( $product->get_parent_id() );

			if ( $parent ) {
				return $parent->get_name();
			}
		}

		return $product->get_name();
	}

	/**
	 * Taille — ou déclinaison — d'une variation.
	 *
	 * @param \WC_Product $product Produit ou variation.
	 *
	 * @return string
	 */
	private function variation_size( \WC_Product $product ): string {
		if ( ! $product instanceof \WC_Product_Variation ) {
			return '';
		}

		$taxonomy = (string) Settings::get( 'brevo_size_taxonomy', 'pa_taille' );

		/*
		 * `WC_Product_Variation::get_attribute()` renvoie le LIBELLÉ du terme.
		 * Lire `get_variation_attributes()` donnerait son identifiant technique
		 * (« 38-fr »), inutilisable dans un e-mail.
		 */
		$size = trim( (string) $product->get_attribute( $taxonomy ) );

		if ( '' !== $size ) {
			return $size;
		}

		/*
		 * Pas de repli sur « le premier attribut non vide » : sur un produit
		 * décliné en taille ET en couleur, l'ordre n'est pas garanti, et l'on
		 * annoncerait « Rouge » à quelqu'un qui attend du 42.
		 */
		return '';
	}

	/**
	 * Assemble des valeurs en une chaîne, sans doublon ni débordement.
	 *
	 * @param string[] $values Valeurs.
	 *
	 * @return string
	 */
	private function join( array $values ): string {
		$values = array_values( array_unique( array_filter( $values ) ) );

		if ( empty( $values ) ) {
			return '';
		}

		$extra  = count( $values ) - self::MAX_LISTED_PRODUCTS;
		$values = array_slice( $values, 0, self::MAX_LISTED_PRODUCTS );

		if ( $extra > 0 ) {
			$values[] = sprintf(
				/* translators: %d: nombre de produits non listés. */
				_n( '+%d autre', '+%d autres', $extra, 'extender-for-back-in-stock-notifier' ),
				$extra
			);
		}

		return implode( self::SEPARATOR, $values );
	}

	/**
	 * Normalise une adresse.
	 *
	 * @param string $email Adresse brute.
	 *
	 * @return string
	 */
	public static function normalize( string $email ): string {
		return OrderMatcher::normalize_email( $email );
	}
}
