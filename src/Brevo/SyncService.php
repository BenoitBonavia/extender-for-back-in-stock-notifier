<?php
/**
 * Synchronisation d'une adresse vers Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Integration\Brevo;
use EBISN\Support\JobState;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pousse une adresse vers Brevo, avec reprise sur incident.
 *
 * Trois corrections par rapport aux snippets remplacés :
 *
 * - un dépassement de quota (429) ou une coupure réseau ne condamnent plus le
 *   contact : ce sont des échecs réessayables, pas des erreurs de charge utile ;
 * - les tentatives s'espacent exponentiellement, avec une part d'aléa — sans
 *   elle, tous les travaux mis en échec par un même incident repartiraient
 *   ensemble et le provoqueraient à nouveau ;
 * - rien n'est envoyé si la charge utile n'a pas changé depuis la dernière
 *   synchronisation réussie.
 */
final class SyncService {

	/**
	 * Hook Action Scheduler de synchronisation d'une adresse.
	 */
	public const HOOK_SYNC = 'ebisn_brevo_sync_contact';

	/**
	 * Nombre maximal de tentatives.
	 */
	private const MAX_ATTEMPTS = 6;

	/**
	 * Délai de base du report exponentiel, en secondes.
	 */
	private const BASE_DELAY = MINUTE_IN_SECONDS;

	/**
	 * Journal de synchronisation.
	 *
	 * @var ContactState
	 */
	private $state;

	/**
	 * Constructeur d'attributs.
	 *
	 * @var PayloadBuilder
	 */
	private $builder;

	/**
	 * Constructeur.
	 *
	 * @param ContactState|null   $state   Journal de synchronisation.
	 * @param PayloadBuilder|null $builder Constructeur d'attributs.
	 */
	public function __construct( ?ContactState $state = null, ?PayloadBuilder $builder = null ) {
		$this->state   = $state ?? new ContactState();
		$this->builder = $builder ?? new PayloadBuilder();
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( self::HOOK_SYNC, array( $this, 'sync' ), 10, 1 );
	}

	/**
	 * Met une adresse en file de synchronisation.
	 *
	 * L'argument passé est l'ADRESSE, jamais l'identifiant d'inscription :
	 * Brevo raisonne par contact, et deux inscriptions d'une même personne
	 * doivent produire un seul envoi.
	 *
	 * @param string $email Adresse normalisée.
	 */
	public function enqueue( string $email ): void {
		if ( '' === $email ) {
			return;
		}

		/*
		 * Tant qu'un rattrapage tourne, il est le seul à écrire : ses imports
		 * sont traités de façon asynchrone par Brevo et pourraient atterrir
		 * APRÈS un envoi au fil de l'eau, écrasant des valeurs fraîches par des
		 * anciennes. L'adresse est donc marquée, et le rattrapage la reprendra.
		 */
		if ( $this->backfill_is_running() ) {
			$this->state->mark_dirty( $email );

			return;
		}

		// `$unique` : une seule synchronisation en vol par adresse.
		Scheduler::enqueue( self::HOOK_SYNC, $email, true );
	}

	/**
	 * Synchronise une adresse. Point d'entrée du hook Action Scheduler.
	 *
	 * @param string $email Adresse normalisée.
	 */
	public function sync( $email ): void {
		$email = PayloadBuilder::normalize( (string) $email );

		if ( '' === $email ) {
			return;
		}

		$list_id = Brevo::list_id();

		if ( ! Brevo::has_api_key() || $list_id <= 0 ) {
			// Module non configuré : on garde la trace, sans consommer d'essai.
			$this->state->mark_dirty( $email );

			return;
		}

		$attributes = $this->builder->build( $email );

		if ( empty( $attributes ) ) {
			// L'adresse n'attend plus rien : plus rien à décrire chez Brevo.
			return;
		}

		$payload_hash = ContactState::payload_hash(
			array_merge( $attributes, array( 'list' => $list_id ) )
		);

		if ( ! $this->state->needs_sync( $email, $payload_hash, $list_id ) ) {
			return;
		}

		$target_list = $this->list_for( $email, $list_id );
		$response    = ( new Contacts( new Client() ) )->upsert( $email, $attributes, $target_list );

		if ( $response->is_success() ) {
			$this->state->mark_synced( $email, $payload_hash, $target_list );

			return;
		}

		$this->handle_failure( $email, $response );
	}

	/**
	 * Liste de destination pour une adresse.
	 *
	 * Sans consentement marketing explicite, le contact est créé — donc
	 * segmentable et utilisable en transactionnel — mais n'est ajouté à aucune
	 * liste. Demander à être prévenu d'un réassort n'est pas accepter de
	 * recevoir des campagnes, et Brevo sanctionne la confusion des deux.
	 *
	 * @param string $email   Adresse normalisée.
	 * @param int    $list_id Liste configurée.
	 *
	 * @return int
	 */
	private function list_for( string $email, int $list_id ): int {
		if ( ! Settings::get_bool( 'brevo_require_consent', false ) ) {
			return $list_id;
		}

		return Consent::has_consent( $email ) ? $list_id : 0;
	}

	/**
	 * Traite un échec d'appel.
	 *
	 * @param string   $email    Adresse normalisée.
	 * @param Response $response Réponse de l'API.
	 */
	private function handle_failure( string $email, Response $response ): void {
		if ( $response->is_auth_failure() ) {
			/*
			 * La clé est refusée. L'extension Brevo SUPPRIME d'ailleurs son option
			 * de clé dans ce cas. Rien ne serait réparé en réessayant, et surtout
			 * rien ne doit être marqué en échec définitif : le problème est de
			 * configuration, pas de contact.
			 */
			$this->state->mark_dirty( $email );

			Logger::error(
				sprintf(
					'Brevo refuse la clé d’API (HTTP %1$d) : synchronisation suspendue. %2$s',
					$response->status(),
					$response->error()
				)
			);

			return;
		}

		if ( ! $response->is_retryable() ) {
			$this->state->mark_failed( $email, $response->error(), true );

			Logger::error(
				sprintf(
					'Brevo refuse le contact (HTTP %1$d) : %2$s',
					$response->status(),
					$response->error()
				)
			);

			return;
		}

		$attempts = $this->state->attempts( $email ) + 1;
		$this->state->mark_failed( $email, $response->error(), $attempts >= self::MAX_ATTEMPTS );

		if ( $attempts >= self::MAX_ATTEMPTS ) {
			Logger::error(
				sprintf( 'Brevo : abandon après %1$d tentatives — %2$s', $attempts, $response->error() )
			);

			return;
		}

		Scheduler::schedule( time() + $this->retry_delay( $attempts, $response ), self::HOOK_SYNC, $email, true );
	}

	/**
	 * Délai avant la prochaine tentative.
	 *
	 * Report exponentiel, additionné d'une part d'aléa : sans elle, cent
	 * travaux mis en échec par le même incident repartiraient à la même
	 * seconde et le reproduiraient.
	 *
	 * @param int      $attempts Nombre de tentatives déjà effectuées.
	 * @param Response $response Réponse de l'API.
	 *
	 * @return int Secondes.
	 */
	private function retry_delay( int $attempts, Response $response ): int {
		// Brevo indique lui-même quand réessayer sur un dépassement de quota.
		if ( $response->retry_after() > 0 ) {
			return $response->retry_after() + wp_rand( 1, 30 );
		}

		$delay = self::BASE_DELAY * ( 2 ** max( 0, $attempts - 1 ) );

		return min( (int) $delay, 6 * HOUR_IN_SECONDS ) + wp_rand( 1, 60 );
	}

	/**
	 * Un rattrapage est-il en cours ?
	 *
	 * @return bool
	 */
	private function backfill_is_running(): bool {
		return JobState::load( Backfill::JOB_ID )->is_running();
	}
}
