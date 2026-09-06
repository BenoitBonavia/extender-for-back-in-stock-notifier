<?php
/**
 * Réponse d'un appel à l'API Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Traduit une réponse HTTP en verdict exploitable par les appelants.
 *
 * La distinction centrale est celle que les snippets remplacés ne faisaient
 * pas : un échec **réessayable** n'est pas un échec **définitif**. Un
 * dépassement de quota ou une coupure réseau signifient « recommence plus
 * tard » ; les traiter comme une erreur de charge utile condamnait
 * définitivement des contacts pour un incident de quelques minutes.
 */
final class Response {

	/**
	 * Code HTTP, `0` si la requête n'a pas abouti.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * Corps de la réponse, décodé si c'était du JSON.
	 *
	 * @var array<string, mixed>
	 */
	private $body;

	/**
	 * Message d'erreur lisible, chaîne vide en cas de succès.
	 *
	 * @var string
	 */
	private $error;

	/**
	 * Délai avant nouvelle tentative imposé par Brevo, en secondes.
	 *
	 * @var int
	 */
	private $retry_after;

	/**
	 * Constructeur.
	 *
	 * @param int                  $status      Code HTTP.
	 * @param array<string, mixed> $body        Corps décodé.
	 * @param string               $error       Message d'erreur.
	 * @param int                  $retry_after Délai imposé, en secondes.
	 */
	public function __construct( int $status, array $body = array(), string $error = '', int $retry_after = 0 ) {
		$this->status      = $status;
		$this->body        = $body;
		$this->error       = $error;
		$this->retry_after = $retry_after;
	}

	/**
	 * Code HTTP.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}

	/**
	 * Corps décodé.
	 *
	 * @return array<string, mixed>
	 */
	public function body(): array {
		return $this->body;
	}

	/**
	 * Message d'erreur lisible.
	 *
	 * @return string
	 */
	public function error(): string {
		return $this->error;
	}

	/**
	 * Délai avant nouvelle tentative imposé par Brevo.
	 *
	 * @return int Secondes, `0` si aucun délai n'est imposé.
	 */
	public function retry_after(): int {
		return $this->retry_after;
	}

	/**
	 * L'appel a-t-il abouti ?
	 *
	 * @return bool
	 */
	public function is_success(): bool {
		return $this->status >= 200 && $this->status < 300;
	}

	/**
	 * La clé d'API est-elle refusée ?
	 *
	 * Cas loin d'être théorique : l'extension Brevo SUPPRIME son option de clé
	 * dès qu'un appel renvoie 401. Une rotation de clé ou une reconnexion du
	 * compte doit mettre la synchronisation en pause, pas condamner les
	 * contacts qu'elle était en train de traiter.
	 *
	 * @return bool
	 */
	public function is_auth_failure(): bool {
		return in_array( $this->status, array( 401, 403 ), true );
	}

	/**
	 * Faut-il réessayer plus tard ?
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		// 0 = requête n'ayant pas abouti (réseau, DNS, TLS, délai dépassé).
		return 0 === $this->status || 429 === $this->status || $this->status >= 500;
	}
}
