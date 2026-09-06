<?php
/**
 * Déclaration des attributs de contact chez Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Support\Logger;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Crée chez Brevo les attributs que ce plugin renseigne.
 *
 * Étape indispensable, et facile à oublier : **un attribut inconnu du compte
 * est ignoré silencieusement**. À l'import comme à la création de contact,
 * Brevo accepte la requête, répond un succès, et jette les valeurs. Sans cette
 * déclaration préalable, la synchronisation paraît fonctionner alors qu'elle
 * ne transmet rien.
 *
 * L'opération n'est tentée qu'une fois par jeu d'attributs : un attribut déjà
 * présent fait répondre 400 à Brevo, ce qui est ici un succès déguisé.
 */
final class AttributeInstaller {

	/**
	 * Réglage mémorisant l'empreinte des attributs déjà déclarés.
	 */
	private const DONE_OPTION = 'brevo_attributes_installed';

	/**
	 * Contraintes de nommage Brevo : lettres, chiffres et tirets bas,
	 * commençant par une lettre, cinquante caractères au plus.
	 */
	private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,49}$/';

	/**
	 * Déclare les attributs manquants, si ce n'est déjà fait.
	 *
	 * @param bool $force Recommencer même si le jeu courant a déjà été déclaré.
	 *
	 * @return bool Vrai si tous les attributs sont désormais disponibles.
	 */
	public function ensure( bool $force = false ): bool {
		$types       = PayloadBuilder::attribute_types();
		$fingerprint = md5( (string) wp_json_encode( $types ) );

		if ( ! $force && Settings::get( self::DONE_OPTION, '' ) === $fingerprint ) {
			return true;
		}

		$contacts = new Contacts( new Client() );
		$existing = $this->existing_names( $contacts );

		if ( null === $existing ) {
			// Compte injoignable : ne rien mémoriser, on retentera plus tard.
			return false;
		}

		$ok = true;

		foreach ( $types as $name => $type ) {
			if ( ! preg_match( self::NAME_PATTERN, $name ) ) {
				Logger::error(
					sprintf(
						'Attribut Brevo « %s » ignoré : Brevo n’accepte que des lettres, chiffres et tirets bas, commençant par une lettre.',
						$name
					)
				);

				$ok = false;
				continue;
			}

			if ( isset( $existing[ strtoupper( $name ) ] ) ) {
				continue;
			}

			$response = $contacts->create_attribute( $name, $type );

			if ( $response->is_success() ) {
				Logger::info( sprintf( 'Attribut Brevo « %s » créé.', $name ) );

				continue;
			}

			/*
			 * 400 signifie le plus souvent « existe déjà » — Brevo ne distingue
			 * pas ce cas d'une charge utile invalide. La liste des attributs
			 * ayant déjà été consultée, on peut le considérer comme bénin.
			 */
			if ( 400 === $response->status() ) {
				continue;
			}

			Logger::error(
				sprintf(
					'Création de l’attribut Brevo « %1$s » refusée (HTTP %2$d) : %3$s',
					$name,
					$response->status(),
					$response->error()
				)
			);

			$ok = false;
		}

		if ( $ok ) {
			Settings::update( self::DONE_OPTION, $fingerprint );
			$this->flush_plugin_cache();
		}

		return $ok;
	}

	/**
	 * Oublie l'état de déclaration, pour forcer un nouveau passage.
	 */
	public static function reset(): void {
		Settings::delete( self::DONE_OPTION );
	}

	/**
	 * Attributs déjà déclarés sur le compte.
	 *
	 * @param Contacts $contacts Couche métier Brevo.
	 *
	 * @return array<string, true>|null Noms en majuscules, `null` si le compte est injoignable.
	 */
	private function existing_names( Contacts $contacts ): ?array {
		$response = $contacts->attributes();

		if ( ! $response->is_success() ) {
			return null;
		}

		$body  = $response->body();
		$names = array();

		foreach ( (array) ( $body['attributes'] ?? array() ) as $attribute ) {
			if ( isset( $attribute['name'] ) && is_string( $attribute['name'] ) ) {
				$names[ strtoupper( $attribute['name'] ) ] = true;
			}
		}

		return $names;
	}

	/**
	 * Invalide le cache d'attributs de l'extension Brevo.
	 *
	 * Sans cela, son écran de réglages continue d'ignorer nos attributs
	 * pendant un quart d'heure.
	 */
	private function flush_plugin_cache(): void {
		if ( is_callable( array( 'SIB_API_Manager', 'remove_transients' ) ) ) {
			call_user_func( array( 'SIB_API_Manager', 'remove_transients' ) );
		}
	}
}
