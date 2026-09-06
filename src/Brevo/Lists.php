<?php
/**
 * Listes de contacts Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Récupère et met en cache les listes du compte Brevo.
 *
 * Le cache n'est pas une optimisation de confort : l'endpoint des listes relève
 * du quota des « autres » points d'entrée de l'API, limité à cent appels par
 * heure tous usages confondus. Le rafraîchir à chaque affichage de l'écran de
 * réglages suffirait à l'épuiser.
 */
final class Lists {

	/**
	 * Clé du cache.
	 */
	private const TRANSIENT = 'ebisn_brevo_lists';

	/**
	 * Durée de vie du cache.
	 */
	private const TTL = HOUR_IN_SECONDS;

	/**
	 * Nombre de listes par page, plafond de l'API.
	 */
	private const PAGE_SIZE = 50;

	/**
	 * Listes du compte, identifiant => nom.
	 *
	 * @param bool $refresh Forcer un appel à l'API.
	 *
	 * @return array<int, string> Vide si le compte est injoignable.
	 */
	public static function all( bool $refresh = false ): array {
		if ( ! $refresh ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$contacts = new Contacts( new Client() );
		$lists    = array();
		$offset   = 0;
		$loaded   = 0;

		do {
			$response = $contacts->lists( self::PAGE_SIZE, $offset );

			if ( ! $response->is_success() ) {
				// Rien n'est mis en cache : un incident passager ne doit pas
				// vider la liste déroulante pour l'heure qui vient.
				return array();
			}

			$body  = $response->body();
			$page  = (array) ( $body['lists'] ?? array() );
			$total = (int) ( $body['count'] ?? 0 );

			foreach ( $page as $list ) {
				if ( isset( $list['id'], $list['name'] ) ) {
					$lists[ (int) $list['id'] ] = (string) $list['name'];
					++$loaded;
				}
			}

			$offset += self::PAGE_SIZE;

			// Le plafond sur l'offset borne la boucle même si le compte renvoyé
			// par l'API était incohérent.
		} while ( ! empty( $page ) && $loaded < $total && $offset < 1000 );

		set_transient( self::TRANSIENT, $lists, self::TTL );

		return $lists;
	}

	/**
	 * Vide le cache.
	 */
	public static function flush(): void {
		delete_transient( self::TRANSIENT );
	}
}
