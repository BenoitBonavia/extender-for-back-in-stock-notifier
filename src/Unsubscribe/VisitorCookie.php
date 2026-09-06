<?php
/**
 * Jeton de navigateur permettant de retrouver les inscriptions d'un invité.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Associe un navigateur à ses inscriptions, sans rien savoir de la personne.
 *
 * Une inscription faite en tant qu'invité n'est rattachée à aucun compte : rien
 * ne permet, à la visite suivante, de savoir que ce visiteur est déjà inscrit.
 * Ce jeton comble ce vide.
 *
 * Il est **opaque et aléatoire** : ni l'adresse e-mail, ni son empreinte, ni
 * aucune donnée dérivée n'est déposée dans le navigateur. Un cookie intercepté
 * ne révèle donc rien de son propriétaire, et sa valeur ne sert qu'à retrouver
 * des inscriptions en base — jamais à prouver une identité, ce que le serveur
 * revérifie de toute façon avant toute écriture.
 *
 * C'est un CONFORT, pas une garantie : navigation privée, autre appareil ou
 * purge des cookies le font disparaître. Le lien signé envoyé par e-mail reste
 * le seul chemin fiable pour un invité.
 */
final class VisitorCookie {

	/**
	 * Nom du cookie.
	 */
	public const NAME = 'ebisn_visitor';

	/**
	 * Métadonnée portant le jeton sur une inscription.
	 */
	public const META = '_ebisn_visitor';

	/**
	 * Durée de vie par défaut, en jours.
	 */
	public const DEFAULT_LIFETIME_DAYS = 180;

	/**
	 * Jeton posé pendant cette requête, avant que le cookie ne soit relu.
	 *
	 * @var string
	 */
	private static $issued = '';

	/**
	 * La mémorisation par cookie est-elle active ?
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return Settings::get_bool( 'unsubscribe_remember_visitors', true );
	}

	/**
	 * Jeton du visiteur courant, sans en créer.
	 *
	 * @return string Chaîne vide si le visiteur n'en a pas.
	 */
	public static function current(): string {
		if ( '' !== self::$issued ) {
			return self::$issued;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- identifiant opaque, revérifié côté serveur avant toute écriture.
		$raw = isset( $_COOKIE[ self::NAME ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::NAME ] ) ) : '';

		return self::is_valid( $raw ) ? $raw : '';
	}

	/**
	 * Retourne le jeton du visiteur, en le créant si nécessaire.
	 *
	 * @return string Chaîne vide si la mémorisation est désactivée.
	 */
	public static function ensure(): string {
		if ( ! self::is_enabled() ) {
			return '';
		}

		$token = self::current();

		if ( '' !== $token ) {
			return $token;
		}

		$token = wp_generate_password( 32, false, false );

		/*
		 * Mémorisé pour la durée de la requête : le cookie ne sera lisible dans
		 * `$_COOKIE` qu'à la requête suivante, or l'inscription qui déclenche
		 * cet appel doit être marquée tout de suite.
		 */
		self::$issued = $token;

		self::send( $token );

		return $token;
	}

	/**
	 * Envoie le cookie au navigateur.
	 *
	 * @param string $token Jeton.
	 */
	private static function send( string $token ): void {
		if ( headers_sent() ) {
			// L'inscription passe par une requête AJAX : le cas ne devrait pas
			// se produire, mais un thème bavard peut avoir déjà écrit.
			return;
		}

		$days = max( 1, (int) Settings::get( 'unsubscribe_cookie_days', self::DEFAULT_LIFETIME_DAYS ) );

		/*
		 * Constantes lues par `constant()` sous garde : WordPress les définit
		 * dans `default-constants.php`, mais un site peut les redéfinir — et
		 * l'analyse statique n'en connaît pas la valeur.
		 */
		$path   = defined( 'COOKIEPATH' ) ? (string) constant( 'COOKIEPATH' ) : '';
		$domain = defined( 'COOKIE_DOMAIN' ) ? (string) constant( 'COOKIE_DOMAIN' ) : '';

		setcookie(
			self::NAME,
			$token,
			array(
				'expires'  => time() + ( $days * DAY_IN_SECONDS ),
				'path'     => '' !== $path ? $path : '/',
				'domain'   => $domain,
				'secure'   => is_ssl(),
				// Pas de HttpOnly : sans usage côté script, mais surtout aucune
				// donnée sensible à protéger — le jeton n'est qu'un pointeur.
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Le jeton a-t-il une forme acceptable ?
	 *
	 * @param string $token Jeton.
	 *
	 * @return bool
	 */
	private static function is_valid( string $token ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $token );
	}
}
