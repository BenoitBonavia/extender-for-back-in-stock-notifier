<?php
/**
 * Opérations Brevo sur les contacts.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Traduit les besoins du plugin en appels d'API Brevo.
 *
 * Une règle traverse toute la classe : **`emailBlacklisted` n'est jamais
 * transmis**. Transmettre `false` réabonnerait un contact qui s'est désinscrit,
 * ce que Brevo qualifie explicitement d'illégal et sanctionne d'une suspension
 * de compte. Un champ absent ne modifie rien ; c'est exactement ce que l'on
 * veut. L'extension Brevo officielle, elle, envoie `false` — ce n'est pas une
 * référence à suivre.
 */
final class Contacts {

	/**
	 * Client HTTP.
	 *
	 * @var Client
	 */
	private $client;

	/**
	 * Constructeur.
	 *
	 * @param Client $client Client HTTP.
	 */
	public function __construct( Client $client ) {
		$this->client = $client;
	}

	/**
	 * Crée ou met à jour un contact.
	 *
	 * Brevo répond 201 pour une création et 204 pour une mise à jour : les deux
	 * sont des succès. L'unicité étant garantie par l'adresse, un appel répété
	 * ne crée jamais de doublon.
	 *
	 * @param string               $email      Adresse.
	 * @param array<string, mixed> $attributes Attributs de contact.
	 * @param int                  $list_id    Liste à rejoindre ; `0` pour aucune.
	 *
	 * @return Response
	 */
	public function upsert( string $email, array $attributes, int $list_id = 0 ): Response {
		$payload = array(
			'email'         => $email,
			'updateEnabled' => true,
		);

		if ( ! empty( $attributes ) ) {
			$payload['attributes'] = $attributes;
		}

		if ( $list_id > 0 ) {
			$payload['listIds'] = array( $list_id );
		}

		/**
		 * Charge utile envoyée à Brevo pour un contact.
		 *
		 * @param array<string, mixed> $payload Charge utile.
		 * @param string               $email   Adresse concernée.
		 */
		$payload = (array) apply_filters( 'ebisn_brevo_contact_payload', $payload, $email );

		return $this->client->post( '/contacts', $payload );
	}

	/**
	 * Importe un lot de contacts.
	 *
	 * L'import est ASYNCHRONE : un 202 ne signifie pas que les contacts sont
	 * enregistrés, seulement que Brevo a accepté la demande. Il faut suivre le
	 * `processId` renvoyé pour savoir ce qu'il en est advenu.
	 *
	 * @param array<int, array<string, mixed>> $contacts Contacts, format `jsonBody`.
	 * @param int                              $list_id  Liste de destination.
	 *
	 * @return Response
	 */
	public function import( array $contacts, int $list_id ): Response {
		/*
		 * `emptyContactsAttributes` à `false` impérativement : à `true`, les
		 * champs vides de l'import EFFACERAIENT les attributs déjà renseignés
		 * côté Brevo, dont ceux que le connecteur WooCommerce alimente à chaque
		 * commande.
		 */
		$payload = array(
			'jsonBody'                => array_values( $contacts ),
			'updateExistingContacts'  => true,
			'emptyContactsAttributes' => false,
		);

		if ( $list_id > 0 ) {
			$payload['listIds'] = array( $list_id );
		}

		// 30 secondes : un import porte plusieurs milliers de contacts.
		return $this->client->post( '/contacts/import', $payload, 30 );
	}

	/**
	 * État d'un import asynchrone.
	 *
	 * À consommer avec parcimonie : cet endpoint ne relève pas du quota des
	 * contacts mais de celui, bien plus étroit, de « tous les autres » — cent
	 * appels par heure, tous usages confondus.
	 *
	 * @param int $process_id Identifiant renvoyé par l'import.
	 *
	 * @return Response
	 */
	public function process_status( int $process_id ): Response {
		return $this->client->get( '/processes/' . $process_id );
	}

	/**
	 * Listes de contacts du compte.
	 *
	 * @param int $limit  Nombre de listes par page (50 maximum).
	 * @param int $offset Décalage.
	 *
	 * @return Response
	 */
	public function lists( int $limit = 50, int $offset = 0 ): Response {
		return $this->client->get(
			'/contacts/lists',
			array(
				'limit'  => $limit,
				'offset' => $offset,
			)
		);
	}

	/**
	 * Informations du compte, utilisées pour tester la connexion.
	 *
	 * @return Response
	 */
	public function account(): Response {
		return $this->client->get( '/account' );
	}

	/**
	 * Attributs de contact déclarés sur le compte.
	 *
	 * @return Response
	 */
	public function attributes(): Response {
		return $this->client->get( '/contacts/attributes' );
	}

	/**
	 * Déclare un attribut de contact.
	 *
	 * Étape indispensable : un attribut inconnu du compte est **ignoré
	 * silencieusement** à l'import. Sans cette déclaration, les données partent
	 * et disparaissent sans qu'aucune erreur ne le signale.
	 *
	 * @param string $name Nom de l'attribut, en majuscules.
	 * @param string $type Type Brevo : `text`, `date`, `float` ou `boolean`.
	 *
	 * @return Response
	 */
	public function create_attribute( string $name, string $type = 'text' ): Response {
		return $this->client->post(
			'/contacts/attributes/normal/' . rawurlencode( $name ),
			array( 'type' => $type )
		);
	}
}
