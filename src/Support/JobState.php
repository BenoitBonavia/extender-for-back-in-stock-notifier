<?php
/**
 * État persistant d'un traitement par lots.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Enveloppe typée de l'option `ebisn_job_{id}`.
 *
 * Seule classe à connaître la forme de cette option : le reste du plugin ne
 * manipule que des accesseurs.
 */
final class JobState {

	/** Le travail n'a pas encore été amorcé. */
	public const STATUS_PENDING = 'pending';

	/** En attente d'une configuration ou d'une confirmation du marchand. */
	public const STATUS_WAITING = 'waiting';

	/** En cours d'exécution. */
	public const STATUS_RUNNING = 'running';

	/** Interrompu volontairement : reprend là où il s'était arrêté. */
	public const STATUS_PAUSED = 'paused';

	/** Terminé. */
	public const STATUS_DONE = 'done';

	/** Abandonné après une erreur non récupérable. */
	public const STATUS_FAILED = 'failed';

	/**
	 * Identifiant du travail.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Données de l'état.
	 *
	 * @var array<string, mixed>
	 */
	private $data;

	/**
	 * Constructeur.
	 *
	 * @param string               $id   Identifiant du travail.
	 * @param array<string, mixed> $data Données de l'état.
	 */
	private function __construct( string $id, array $data ) {
		$this->id   = $id;
		$this->data = $data;
	}

	/**
	 * Charge l'état d'un travail, ou en crée un vierge.
	 *
	 * @param string $id Identifiant du travail.
	 *
	 * @return JobState
	 */
	public static function load( string $id ): JobState {
		$stored = Settings::get( self::key( $id ), array() );

		return new self( $id, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Crée l'état d'un travail s'il n'existe pas encore.
	 *
	 * Repose sur `add_option()`, dont l'échec signifie « déjà amorcé ». C'est ce
	 * qui rend l'amorçage sûr sans `register_activation_hook` — lequel ne se
	 * déclenche ni lors d'une mise à jour, ni site par site en multisite.
	 *
	 * @param string               $id     Identifiant du travail.
	 * @param string               $status Statut initial.
	 * @param array<string, mixed> $extra  Données initiales.
	 *
	 * @return bool Vrai si l'état vient d'être créé.
	 */
	public static function bootstrap( string $id, string $status, array $extra = array() ): bool {
		$data = array_merge(
			array(
				'status'     => $status,
				'cursor'     => 0,
				'processed'  => 0,
				'affected'   => 0,
				'started_at' => 0,
				'updated_at' => time(),
				'last_error' => '',
			),
			$extra
		);

		return add_option( Settings::option_name( self::key( $id ) ), $data, '', false );
	}

	/**
	 * Nom d'option (sans préfixe) d'un travail.
	 *
	 * @param string $id Identifiant du travail.
	 *
	 * @return string
	 */
	public static function key( string $id ): string {
		return 'job_' . sanitize_key( $id );
	}

	/**
	 * Supprime l'état d'un travail.
	 *
	 * @param string $id Identifiant du travail.
	 */
	public static function forget( string $id ): void {
		Settings::delete( self::key( $id ) );
	}

	/**
	 * Lit une valeur de l'état.
	 *
	 * @param string $field    Nom du champ.
	 * @param mixed  $fallback Valeur de repli.
	 *
	 * @return mixed
	 */
	public function get( string $field, $fallback = null ) {
		return array_key_exists( $field, $this->data ) ? $this->data[ $field ] : $fallback;
	}

	/**
	 * Statut courant.
	 *
	 * @return string
	 */
	public function status(): string {
		return (string) $this->get( 'status', self::STATUS_PENDING );
	}

	/**
	 * Position atteinte dans le jeu de données.
	 *
	 * @return int
	 */
	public function cursor(): int {
		return (int) $this->get( 'cursor', 0 );
	}

	/**
	 * Nombre d'éléments examinés.
	 *
	 * @return int
	 */
	public function processed(): int {
		return (int) $this->get( 'processed', 0 );
	}

	/**
	 * Nombre d'éléments réellement modifiés.
	 *
	 * @return int
	 */
	public function affected(): int {
		return (int) $this->get( 'affected', 0 );
	}

	/**
	 * Le travail est-il terminé ?
	 *
	 * @return bool
	 */
	public function is_done(): bool {
		return self::STATUS_DONE === $this->status();
	}

	/**
	 * Le travail est-il en cours ?
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		return self::STATUS_RUNNING === $this->status();
	}

	/**
	 * Le travail progresse-t-il encore ?
	 *
	 * Sert au chien de garde : un travail « en cours » dont l'état n'a pas bougé
	 * depuis longtemps est un travail dont le processus a été tué.
	 *
	 * @param int $threshold Ancienneté maximale tolérée, en secondes.
	 *
	 * @return bool
	 */
	public function is_stalled( int $threshold = 900 ): bool {
		if ( ! $this->is_running() ) {
			return false;
		}

		return ( time() - (int) $this->get( 'updated_at', 0 ) ) > $threshold;
	}

	/**
	 * Modifie l'état en mémoire.
	 *
	 * @param array<string, mixed> $changes Champs à écrire.
	 *
	 * @return JobState
	 */
	public function merge( array $changes ): JobState {
		$this->data = array_merge( $this->data, $changes );

		return $this;
	}

	/**
	 * Persiste l'état, en rafraîchissant systématiquement l'horodatage.
	 *
	 * @return JobState
	 */
	public function save(): JobState {
		$this->data['updated_at'] = time();

		Settings::update( self::key( $this->id ), $this->data );

		return $this;
	}

	/**
	 * Retourne l'état complet.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
