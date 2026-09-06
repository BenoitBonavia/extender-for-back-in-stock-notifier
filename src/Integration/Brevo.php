<?php
/**
 * Point d'isolement de l'extension Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Integration;

use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce que ce plugin sait des extensions Brevo.
 *
 * Jumelle de `BackInStockNotifier` : aucune autre classe ne doit écrire en dur
 * `sib_`, `SIB_Manager` ni `mailin`.
 *
 * Brevo est une dépendance OPTIONNELLE : elle n'a pas sa place dans l'en-tête
 * `Requires Plugins`, qui rendrait le plugin entier inactivable sans elle alors
 * qu'un seul module la concerne. La vérification se fait donc à l'exécution.
 *
 * Relevé sur « Brevo – Email, SMS, Web Push, Chat » 3.3.5 (slug `mailin`).
 */
final class Brevo {

	/**
	 * Slug wordpress.org de l'extension détenant la clé d'API.
	 */
	public const SLUG = 'mailin';

	/**
	 * Chemin de son fichier principal, relatif au dossier des extensions.
	 */
	public const BASENAME = 'mailin/sendinblue.php';

	/**
	 * Classe principale de l'extension Brevo.
	 */
	public const MAIN_CLASS = 'SIB_Manager';

	/**
	 * Constante de classe portant le nom de l'option de clé d'API.
	 */
	public const API_KEY_OPTION_CONSTANT = 'SIB_Manager::API_KEY_V3_OPTION_NAME';

	/**
	 * Nom d'option de repli, si la constante n'est pas disponible.
	 */
	public const API_KEY_OPTION = 'sib_api_key_v3';

	/**
	 * Racine de l'API Brevo.
	 */
	public const API_BASE = 'https://api.brevo.com/v3';

	/**
	 * Réglages de connexion du connecteur « Brevo for WooCommerce ».
	 */
	private const WC_CONNECTOR_OPTION = 'sendinblue_woocommerce_user_connection_settings';

	/**
	 * Drapeau activant le formulaire de réassort de ce connecteur.
	 */
	private const WC_CONNECTOR_BIS_FLAG = 'isBackInStockSyncEnabled';

	/**
	 * L'extension Brevo est-elle chargée ?
	 *
	 * Contrairement à l'extension hôte, `class_exists()` est ici fiable : Brevo
	 * inclut ses fichiers inconditionnellement, sans dépendre de WooCommerce.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return class_exists( self::MAIN_CLASS );
	}

	/**
	 * Clé d'API v3 utilisable.
	 *
	 * Trois sources, dans l'ordre : la clé de l'extension Brevo, désignée par sa
	 * propre constante ; la même option en dur ; enfin une clé saisie dans nos
	 * réglages. Cette dernière n'est pas un luxe — le connecteur « Brevo for
	 * WooCommerce » ne stocke plus AUCUNE clé v3 depuis sa version 4, si bien
	 * qu'un marchand n'ayant que celui-ci n'en a pas.
	 *
	 * @return string Chaîne vide si aucune clé n'est disponible.
	 */
	public static function api_key(): string {
		$option = defined( self::API_KEY_OPTION_CONSTANT )
			? (string) constant( self::API_KEY_OPTION_CONSTANT )
			: self::API_KEY_OPTION;

		$key = trim( (string) get_option( $option, '' ) );

		if ( '' === $key ) {
			$key = trim( (string) Settings::get( 'brevo_api_key', '' ) );
		}

		/**
		 * Clé d'API Brevo utilisée par ce plugin.
		 *
		 * @param string $key Clé résolue.
		 */
		return (string) apply_filters( 'ebisn_brevo_api_key', $key );
	}

	/**
	 * Une clé d'API est-elle disponible ?
	 *
	 * @return bool
	 */
	public static function has_api_key(): bool {
		return '' !== self::api_key();
	}

	/**
	 * La clé provient-elle de l'extension Brevo, ou de nos propres réglages ?
	 *
	 * @return bool
	 */
	public static function key_comes_from_plugin(): bool {
		$option = defined( self::API_KEY_OPTION_CONSTANT )
			? (string) constant( self::API_KEY_OPTION_CONSTANT )
			: self::API_KEY_OPTION;

		return '' !== trim( (string) get_option( $option, '' ) );
	}

	/**
	 * Liste Brevo vers laquelle synchroniser les inscrits.
	 *
	 * @return int `0` tant qu'aucune liste n'est choisie.
	 */
	public static function list_id(): int {
		return max( 0, (int) Settings::get( 'brevo_list_id', 0 ) );
	}

	/**
	 * Préfixe des attributs de contact.
	 *
	 * Configurable, et non figé : Brevo ne sait pas renommer un attribut sans
	 * détruire les données qu'il contient. Un site déjà synchronisé par un autre
	 * moyen doit pouvoir conserver ses noms d'attributs existants.
	 *
	 * @return string
	 */
	public static function attribute_prefix(): string {
		$prefix = strtoupper( trim( (string) Settings::get( 'brevo_attribute_prefix', 'MH_ATTENTE_' ) ) );

		// Contraintes Brevo : lettres, chiffres et tirets bas, débutant par une lettre.
		$prefix = (string) preg_replace( '/[^A-Z0-9_]/', '', $prefix );

		return '' === $prefix || ! preg_match( '/^[A-Z]/', $prefix ) ? 'MH_ATTENTE_' : $prefix;
	}

	/**
	 * Le connecteur « Brevo for WooCommerce » gère-t-il lui aussi le réassort ?
	 *
	 * Depuis sa version 4.0.44, ce connecteur affiche son propre formulaire
	 * « Notify me when available » et tient sa propre liste d'attente côté
	 * Brevo. Les deux systèmes enverraient alors des alertes concurrentes sur
	 * les mêmes produits. Le réglage vit côté Brevo : il peut être activé sans
	 * que rien ne change sur le site.
	 *
	 * @return bool
	 */
	public static function woocommerce_connector_handles_restock(): bool {
		$raw = get_option( self::WC_CONNECTOR_OPTION, '' );

		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}

		return is_array( $raw ) && ! empty( $raw[ self::WC_CONNECTOR_BIS_FLAG ] );
	}

	/**
	 * Nom lisible de l'extension Brevo.
	 *
	 * Volontairement non traduit : c'est un nom propre, il doit rester
	 * cherchable tel quel dans l'écran d'ajout d'extensions.
	 *
	 * @return string
	 */
	public static function name(): string {
		return 'Brevo';
	}
}
