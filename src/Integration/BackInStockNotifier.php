<?php
/**
 * Point d'isolement du plugin hôte.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce que ce plugin sait de « Back In Stock Notifier for WooCommerce ».
 *
 * Aucune autre classe ne doit écrire en dur `cwginstock`, `CWGINSTOCK_*` ni
 * `CWG_Instock_Notifier` : le jour où l'extension hôte renomme une option, un
 * type de contenu ou une constante, seul ce fichier bouge. C'est la contrepartie
 * d'étendre du code qu'on ne maîtrise pas.
 *
 * Relevé sur la version 7.4.2 (ProPluginsLab).
 */
final class BackInStockNotifier {

	/**
	 * Slug de l'extension, tel qu'il figure dans l'en-tête `Requires Plugins`
	 * du fichier principal de CE plugin, et tel que WordPress.org l'expose.
	 */
	public const SLUG = 'back-in-stock-notifier-for-woocommerce';

	/**
	 * Chemin du fichier principal, relatif au dossier des extensions.
	 *
	 * Le nom du fichier ne reprend PAS le slug : c'est `cwginstocknotifier.php`.
	 */
	public const BASENAME = 'back-in-stock-notifier-for-woocommerce/cwginstocknotifier.php';

	/**
	 * Nom de la constante portant le numéro de version de l'extension hôte.
	 *
	 * Passer par une constante de constante peut surprendre, mais c'est ce qui
	 * permet à `version()` de n'y accéder que par `constant()` : voir le
	 * commentaire de cette méthode.
	 */
	public const VERSION_CONSTANT = 'CWGINSTOCK_VERSION';

	/**
	 * Version minimale de l'extension hôte acceptée.
	 *
	 * Plancher provisoire et assumé : sans fonctionnalité, aucun hook consommé
	 * ne justifie encore un seuil précis. 7.0.0 ouvre la ligne majeure courante.
	 * À réviser dès qu'un module dépendra d'un hook daté.
	 */
	public const MIN_VERSION = '7.0';

	/**
	 * Dernière version de l'extension hôte contre laquelle ce plugin a été relu.
	 *
	 * Ce plugin s'accroche à du code qu'il ne maîtrise pas : un changement de
	 * version majeure de l'hôte peut déplacer un hook ou renommer une option
	 * sans que rien ne casse bruyamment. Cette valeur n'empêche rien — elle sert
	 * à afficher un avertissement dans le diagnostic, seul signal disponible.
	 */
	public const TESTED_VERSION = '7.4.2';

	/**
	 * Type de contenu des abonnés à une alerte de retour en stock.
	 */
	public const SUBSCRIBER_TYPE = 'cwginstocknotifier';

	/**
	 * Type de contenu des arrivages.
	 */
	public const ARRIVAL_TYPE = 'cwginstock_arrival';

	/**
	 * Option principale de l'extension hôte (tableau de réglages).
	 */
	public const SETTINGS_OPTION = 'cwginstocksettings';

	/**
	 * Dossier de surcharge des gabarits, à créer dans le thème.
	 *
	 * L'extension hôte cherche d'abord `{thème}/back-in-stock-notifier-for-woocommerce/{gabarit}`,
	 * puis `{thème}/{gabarit}`, avant de retomber sur les siens. Le tout passe
	 * par le filtre `cwginstock_locate_template`.
	 */
	public const THEME_TEMPLATE_DIR = 'back-in-stock-notifier-for-woocommerce/';

	/**
	 * L'extension hôte est-elle chargée ET démarrée ?
	 *
	 * On teste la CONSTANTE, jamais `class_exists( 'CWG_Instock_Notifier' )` :
	 * cette classe est déclarée dès l'inclusion du fichier principal, alors que
	 * le singleton n'est instancié — et les constantes définies — que si
	 * WooCommerce est actif. La classe existe donc aussi dans un cas où
	 * l'extension ne fait rien du tout.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( self::VERSION_CONSTANT );
	}

	/**
	 * Version de l'extension hôte.
	 *
	 * Lue par `constant()` sous garde `defined()` plutôt qu'en accès direct :
	 * aucun stub de l'extension hôte n'est fourni à PHPStan, et un accès direct
	 * à une constante inconnue ferait échouer l'analyse statique.
	 *
	 * @return string Chaîne vide si l'extension n'est pas démarrée.
	 */
	public static function version(): string {
		if ( ! self::is_active() ) {
			return '';
		}

		$version = constant( self::VERSION_CONSTANT );

		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * L'extension hôte est-elle présente dans une version exploitable ?
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		$version = self::version();

		return '' !== $version && version_compare( $version, self::MIN_VERSION, '>=' );
	}

	/**
	 * L'extension hôte a-t-elle changé de version MAJEURE depuis la relecture ?
	 *
	 * On ne compare que le premier segment : une version mineure ou correctrice
	 * n'est pas censée déplacer un hook, et déclencher l'avertissement à chaque
	 * correctif le rendrait invisible à force d'être affiché.
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
	 * L'extension hôte est-elle installée, active ou non ?
	 *
	 * Sert uniquement à orienter l'avertissement d'administration : « installez-la »
	 * et « activez-la » n'appellent pas le même écran.
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
	 * Réglages de l'extension hôte.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return (array) get_option( self::SETTINGS_OPTION, array() );
	}

	/**
	 * Nombre d'abonnés enregistrés, tous statuts confondus.
	 *
	 * @return int
	 */
	public static function subscriber_count(): int {
		if ( ! post_type_exists( self::SUBSCRIBER_TYPE ) ) {
			return 0;
		}

		$total = 0;

		foreach ( (array) wp_count_posts( self::SUBSCRIBER_TYPE ) as $count ) {
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * URL de la fiche d'installation de l'extension hôte.
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
	 * Nom lisible de l'extension hôte.
	 *
	 * Volontairement non traduit : c'est un nom propre, il doit rester
	 * cherchable tel quel dans l'écran d'ajout d'extensions.
	 *
	 * @return string
	 */
	public static function name(): string {
		return 'Back In Stock Notifier for WooCommerce';
	}
}
