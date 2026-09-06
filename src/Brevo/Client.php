<?php
/**
 * Transport HTTP vers l'API Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Integration\Brevo;
use EBISN\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Appelle l'API Brevo. Ne connaît rien du métier.
 *
 * Le client livré par l'extension Brevo n'est volontairement pas réutilisé :
 * il fixe un délai d'expiration de 10 000 secondes — de toute évidence des
 * millisecondes mal converties — et retire du corps de requête toutes les
 * valeurs vides de premier niveau, ce qui rend certains appels impossibles à
 * formuler. Nous n'empruntons à cette extension que sa clé d'API.
 */
final class Client {

	/**
	 * Délai d'expiration par défaut, en secondes.
	 */
	private const TIMEOUT = 15;

	/**
	 * Clé d'API.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructeur.
	 *
	 * @param string $api_key Clé d'API ; celle de l'extension Brevo si omise.
	 */
	public function __construct( string $api_key = '' ) {
		$this->api_key = '' !== $api_key ? $api_key : Brevo::api_key();
	}

	/**
	 * Une clé d'API est-elle disponible ?
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Exécute une requête GET.
	 *
	 * @param string               $endpoint Chemin, relatif à la racine de l'API.
	 * @param array<string, mixed> $query    Paramètres d'URL.
	 *
	 * @return Response
	 */
	public function get( string $endpoint, array $query = array() ): Response {
		$url = Brevo::API_BASE . $endpoint;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		return $this->request( 'GET', $url, null, self::TIMEOUT );
	}

	/**
	 * Exécute une requête POST.
	 *
	 * @param string               $endpoint Chemin, relatif à la racine de l'API.
	 * @param array<string, mixed> $payload  Corps de la requête.
	 * @param int                  $timeout  Délai d'expiration, en secondes.
	 *
	 * @return Response
	 */
	public function post( string $endpoint, array $payload, int $timeout = self::TIMEOUT ): Response {
		return $this->request( 'POST', Brevo::API_BASE . $endpoint, $payload, $timeout );
	}

	/**
	 * Exécute une requête.
	 *
	 * @param string                    $method  Verbe HTTP.
	 * @param string                    $url     URL complète.
	 * @param array<string, mixed>|null $payload Corps de la requête.
	 * @param int                       $timeout Délai d'expiration, en secondes.
	 *
	 * @return Response
	 */
	private function request( string $method, string $url, ?array $payload, int $timeout ): Response {
		if ( ! $this->is_configured() ) {
			return new Response( 0, array(), __( 'Aucune clé d’API Brevo disponible.', 'extender-for-back-in-stock-notifier' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'accept'       => 'application/json',
				'content-type' => 'application/json',
				'api-key'      => $this->api_key,
			),
		);

		if ( null !== $payload ) {
			// `wp_json_encode` explicite : passer un tableau à `body` produirait
			// du `form-urlencoded`, que l'API refuse.
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			/*
			 * Statut 0 : la requête n'a pas abouti. Le cas est explicitement
			 * réessayable — et il faut garder à l'esprit qu'un délai dépassé peut
			 * survenir APRÈS que Brevo a traité la demande. C'est ce qui rend
			 * l'idempotence côté WordPress indispensable.
			 */
			return new Response( 0, array(), $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = trim( (string) wp_remote_retrieve_body( $response ) );
		$body   = json_decode( $raw, true );
		$body   = is_array( $body ) ? $body : array();

		$this->log_rate_limit( $response, $url );

		if ( $status >= 200 && $status < 300 ) {
			return new Response( $status, $body );
		}

		$message = isset( $body['message'] ) && is_string( $body['message'] )
			? $body['message']
			: substr( $raw, 0, 300 );

		return new Response( $status, $body, $message, $this->retry_delay( $response, $status ) );
	}

	/**
	 * Délai avant nouvelle tentative imposé par Brevo.
	 *
	 * Brevo ne renvoie pas d'en-tête `Retry-After` : sa documentation s'appuie
	 * sur `x-sib-ratelimit-reset`, exprimé en secondes.
	 *
	 * @param array<string, mixed>|\WP_Error $response Réponse HTTP.
	 * @param int                            $status   Code HTTP.
	 *
	 * @return int
	 */
	private function retry_delay( $response, int $status ): int {
		if ( 429 !== $status ) {
			return 0;
		}

		$reset = (int) wp_remote_retrieve_header( $response, 'x-sib-ratelimit-reset' );

		return $reset > 0 ? $reset : MINUTE_IN_SECONDS;
	}

	/**
	 * Signale une approche du plafond d'appels.
	 *
	 * Les en-têtes de quota sont présents sur TOUTES les réponses, pas seulement
	 * sur les 429 : les lire en régime nominal permet de voir venir la limite
	 * plutôt que de la subir.
	 *
	 * @param array<string, mixed>|\WP_Error $response Réponse HTTP.
	 * @param string                         $url      URL appelée.
	 */
	private function log_rate_limit( $response, string $url ): void {
		$remaining = wp_remote_retrieve_header( $response, 'x-sib-ratelimit-remaining' );

		if ( '' === $remaining || ! is_numeric( $remaining ) ) {
			return;
		}

		if ( (int) $remaining > 20 ) {
			return;
		}

		Logger::warning(
			sprintf(
				'Quota d’appels Brevo bientôt atteint : %1$d requête(s) restante(s) sur %2$s.',
				(int) $remaining,
				esc_url_raw( $url )
			)
		);
	}
}
