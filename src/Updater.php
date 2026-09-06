<?php
/**
 * Mises à jour du plugin depuis GitHub.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN;

use EBISN\Support\Logger;
use EBISN\Support\Settings;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

defined( 'ABSPATH' ) || exit;

/**
 * Branche la bibliothèque « Plugin Update Checker » sur le dépôt GitHub du plugin,
 * de sorte que les mises à jour apparaissent dans l'écran Extensions de WordPress.
 */
final class Updater {

	/**
	 * Dépôt GitHub hébergeant les releases.
	 */
	public const REPOSITORY_URL = 'https://github.com/BenoitBonavia/extender-for-back-in-stock-notifier/';

	/**
	 * Branche par défaut du dépôt.
	 *
	 * Indispensable : Plugin Update Checker suppose « master ». Les stratégies
	 * « dernière release » puis « tag le plus élevé » ne sont activées que si la
	 * branche configurée vaut exactement 'master' ou 'main'
	 * (GitHubApi::getUpdateDetectionStrategies()).
	 */
	public const BRANCH = 'main';

	/**
	 * Constante facultative, à définir dans wp-config.php, contenant un jeton GitHub.
	 *
	 * Inutile tant que le dépôt est public : elle ne sert alors qu'à relever la
	 * limite de l'API GitHub (60 requêtes/heure par IP sans jeton, 5 000 avec).
	 * Elle devient obligatoire si le dépôt passe en privé.
	 */
	public const TOKEN_CONSTANT = 'EBISN_GITHUB_TOKEN';

	/**
	 * Filtre appliqué au nom des fichiers attachés à une release.
	 *
	 * Sans expression régulière, la bibliothèque retiendrait le PREMIER fichier
	 * attaché, quel qu'il soit (checksums, signature…).
	 */
	public const ASSET_NAME_REGEX = '/\.zip($|[?&#])/i';

	/**
	 * Instance unique du vérificateur, ou false si l'initialisation a échoué.
	 *
	 * @var mixed
	 */
	private static $checker = null;

	/**
	 * Initialise le vérificateur de mises à jour.
	 *
	 * Volontairement appelé avant le contrôle des prérequis : même si WooCommerce
	 * ou l'extension hôte manquent, le site doit pouvoir recevoir un correctif.
	 *
	 * @return mixed L'objet vérificateur, ou null en cas d'échec.
	 */
	public static function register() {
		// Un slug ne peut être enregistré qu'une fois : la bibliothèque déclenche
		// sinon une E_USER_ERROR « Plugin slug is already in use ».
		if ( null !== self::$checker ) {
			return self::$checker ? self::$checker : null;
		}

		self::$checker = false;

		$library = EBISN_PATH . 'lib/plugin-update-checker/plugin-update-checker.php';

		if ( ! is_readable( $library ) ) {
			Logger::warning( 'Bibliothèque de mise à jour introuvable : ' . $library );

			return null;
		}

		/*
		 * Ce fichier n'est pas une classe mais un amorçage à effets de bord : il
		 * installe l'autoloader interne de la bibliothèque et enregistre ses
		 * classes dans la fabrique. Aucun autoloader PSR-4 ne peut le déclencher.
		 */
		require_once $library;

		if ( ! class_exists( PucFactory::class ) ) {
			Logger::warning( 'Bibliothèque de mise à jour chargée mais fabrique absente.' );

			return null;
		}

		try {
			$checker = PucFactory::buildUpdateChecker(
				self::REPOSITORY_URL,
				EBISN_FILE,
				self::get_slug()
			);
		} catch ( \Exception $exception ) {
			Logger::error( 'Initialisation des mises à jour impossible : ' . $exception->getMessage() );

			return null;
		}

		$checker->setBranch( self::BRANCH );

		$token = self::get_token();

		if ( '' !== $token ) {
			// À poser avant toute vérification : sur dépôt privé, l'URL de
			// téléchargement retenue dépend de la présence d'une authentification.
			$checker->setAuthentication( $token );
		}

		$api = $checker->getVcsApi();

		/*
		 * On interroge l'objet par method_exists() plutôt que par instanceof :
		 * la fabrique de version majeure peut renvoyer une copie de la
		 * bibliothèque embarquée par une AUTRE extension du site (garde
		 * class_exists dans Puc/v5/PucFactory.php). Se lier à un namespace de
		 * version mineure casserait à la première mise à jour de la lib.
		 */
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			$api->enableReleaseAssets( self::ASSET_NAME_REGEX );
		}

		add_action( 'puc_api_error', array( __CLASS__, 'log_api_error' ), 10, 4 );

		self::$checker = $checker;

		return $checker;
	}

	/**
	 * Slug du plugin, qui doit correspondre au nom du dossier d'installation.
	 *
	 * La bibliothèque s'en sert pour renommer le dossier extrait de l'archive :
	 * une divergence installerait la mise à jour à côté de la version en place.
	 *
	 * @return string
	 */
	public static function get_slug(): string {
		$directory = dirname( EBISN_BASENAME );

		if ( '' === $directory || '.' === $directory ) {
			return 'extender-for-back-in-stock-notifier';
		}

		return $directory;
	}

	/**
	 * Jeton GitHub éventuellement défini dans wp-config.php.
	 *
	 * @return string Chaîne vide si aucun jeton n'est configuré.
	 */
	private static function get_token(): string {
		if ( ! defined( self::TOKEN_CONSTANT ) ) {
			return '';
		}

		$token = constant( self::TOKEN_CONSTANT );

		return is_string( $token ) ? trim( $token ) : '';
	}

	/**
	 * Consigne les erreurs remontées par la bibliothèque de mise à jour.
	 *
	 * Sans cela, un jeton expiré ou un dépôt injoignable se traduit simplement
	 * par « l'extension est à jour », sans aucun signal.
	 *
	 * @param \WP_Error $error    Erreur rencontrée.
	 * @param mixed     $response Réponse HTTP brute, si disponible.
	 * @param string    $url      URL interrogée.
	 * @param string    $slug     Slug concerné.
	 */
	public static function log_api_error( $error, $response = null, $url = null, $slug = null ): void {
		if ( self::get_slug() !== $slug ) {
			return;
		}

		if ( ! Settings::get_bool( 'enable_logging' ) ) {
			return;
		}

		$message = is_wp_error( $error ) ? $error->get_error_message() : 'Erreur inconnue';

		Logger::error(
			sprintf( 'Vérification des mises à jour : %s (%s)', $message, (string) $url )
		);
	}
}
