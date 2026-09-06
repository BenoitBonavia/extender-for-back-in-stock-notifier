<?php
/**
 * Rattrapage en masse des inscrits vers Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Integration\Brevo;
use EBISN\Support\BatchJob;
use EBISN\Support\BatchRunner;
use EBISN\Support\JobState;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Pousse les inscrits existants vers la liste Brevo, par imports successifs.
 *
 * Trois différences de fond avec le snippet remplacé :
 *
 * 1. **Le navigateur n'est plus l'ordonnanceur.** Le snippet rechargeait sa
 *    page toutes les huit secondes pour faire avancer le travail : fermer
 *    l'onglet le figeait, l'ouvrir à deux lançait deux exécutions concurrentes.
 * 2. **Un `202` n'est pas un succès.** L'import est asynchrone : ce code
 *    signifie seulement que Brevo a accepté la demande. Les adresses ne sont
 *    marquées synchronisées qu'une fois l'import réellement terminé.
 * 3. **Les adresses sont dédoublonnées et comparées au journal**, si bien
 *    qu'un rattrapage relancé n'envoie que ce qui a changé.
 */
final class Backfill implements BatchJob {

	/**
	 * Identifiant du travail.
	 */
	public const JOB_ID = 'brevo_backfill';

	/**
	 * Hook Action Scheduler d'une étape.
	 */
	public const HOOK_STEP = 'ebisn_brevo_backfill_step';

	/**
	 * Hook de suivi d'un import.
	 */
	public const HOOK_POLL = 'ebisn_brevo_backfill_poll';

	/**
	 * Nombre d'inscriptions lues par lot.
	 *
	 * La contrainte de Brevo porte sur le POIDS de la charge utile — dix
	 * mégaoctets au maximum — et non sur un nombre de contacts. Cette valeur
	 * borne surtout la mémoire PHP ; le découpage réel se fait à l'octet.
	 */
	private const BATCH_SIZE = 500;

	/**
	 * Poids maximal d'un import, en octets.
	 *
	 * Deux mégaoctets, très en dessous du plafond documenté : la marge couvre
	 * les attributs longs et l'encodage des caractères accentués.
	 */
	private const MAX_PAYLOAD_BYTES = 2097152;

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
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::JOB_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_hook(): string {
		return self::HOOK_STEP;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_batch_size(): int {
		return self::BATCH_SIZE;
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( self::HOOK_STEP, array( $this, 'run_step' ) );
		add_action( self::HOOK_POLL, array( $this, 'poll_process' ), 10, 1 );
	}

	/**
	 * Exécute une étape. Point d'entrée du hook Action Scheduler.
	 */
	public function run_step(): void {
		( new BatchRunner( $this ) )->run_step();
	}

	/**
	 * Relance le travail s'il s'est interrompu.
	 */
	public function revive_if_stalled(): void {
		( new BatchRunner( $this ) )->revive_if_stalled();
	}

	/**
	 * Démarre le rattrapage.
	 *
	 * @param bool $force Repartir de zéro.
	 */
	public function start( bool $force = false ): void {
		( new BatchRunner( $this ) )->start( $force );
	}

	/**
	 * Prépare l'état du rattrapage au premier démarrage du plugin.
	 *
	 * Le travail n'est PAS lancé automatiquement. Un premier envoi de masse vers
	 * un compte Brevo est irréversible : il peut déclencher des scénarios
	 * d'automatisation et expédier des messages à des milliers de personnes.
	 * Le plugin prépare donc tout, compte ce qu'il y a à faire, et attend un
	 * clic.
	 *
	 * Seule exception : si le snippet remplacé a déjà mené ce rattrapage à son
	 * terme, il n'y a rien à proposer.
	 */
	public function maybe_bootstrap(): void {
		$status = $this->snippet_already_ran() ? JobState::STATUS_DONE : JobState::STATUS_WAITING;

		if ( ! JobState::bootstrap( self::JOB_ID, $status ) ) {
			return;
		}

		if ( JobState::STATUS_DONE === $status ) {
			Logger::info( 'Rattrapage Brevo considéré comme déjà effectué : suivi du snippet trouvé en base.' );
		}
	}

	/**
	 * Le snippet remplacé a-t-il déjà mené le rattrapage ?
	 *
	 * On interroge son option de suivi, et non des métadonnées d'inscription :
	 * l'extension hôte peut supprimer ses inscriptions, donc leurs marqueurs,
	 * alors qu'une option demeure.
	 *
	 * @return bool
	 */
	private function snippet_already_ran(): bool {
		$job = get_option( 'mh_brevo_backfill_job', array() );

		return is_array( $job ) && ! empty( $job['done'] ) && (int) $job['done'] > 0;
	}

	/**
	 * Nombre d'adresses restant à envoyer.
	 *
	 * Estimation volontairement approximative : elle compte les adresses
	 * distinctes en attente qui n'ont jamais été synchronisées, sans construire
	 * leurs attributs. Le chiffre exact demanderait de composer la charge utile
	 * de chaque contact — beaucoup trop cher pour une simple notice, et le
	 * travail lui-même refera ce tri.
	 *
	 * @return int
	 */
	public function count_pending(): int {
		global $wpdb;

		$statuses = PayloadBuilder::active_statuses();

		if ( empty( $statuses ) ) {
			return 0;
		}

		$table = ContactState::table();

		$sql = "SELECT COUNT( DISTINCT LOWER( p.post_title ) )
				  FROM {$wpdb->posts} p
				  LEFT JOIN {$table} c
						 ON c.email_hash = SHA2( LOWER( p.post_title ), 256 )
						AND c.state = 'synced'
				 WHERE p.post_type = %s
				   AND p.post_title LIKE %s
				   AND c.email_hash IS NULL
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';

		$values = array_merge(
			array( Host::SUBSCRIBER_TYPE, '%@%' ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * Simule le premier lot, sans aucun appel réseau.
	 *
	 * Emprunte exactement le même chemin que l'exécution — même lecture, même
	 * dédoublonnage, même construction d'attributs — et s'arrête juste avant
	 * l'envoi. Une simulation qui suivrait un chemin distinct de l'exécution ne
	 * garantirait rien de ce qu'elle annonce.
	 *
	 * @param int $limit Nombre de contacts à présenter.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function preview( int $limit = 15 ): array {
		$rows     = $this->read_subscribers( 0, max( $limit * 4, self::BATCH_SIZE ) );
		$contacts = $this->to_contacts( $rows );

		return array_slice( array_values( $contacts ), 0, $limit );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $cursor Dernière inscription lue.
	 * @param int $limit  Taille du lot.
	 *
	 * @return array{cursor:int, processed:int, affected:int, done:bool}
	 */
	public function process( int $cursor, int $limit ): array {
		$rows = $this->read_subscribers( $cursor, $limit );

		if ( empty( $rows ) ) {
			return array(
				'cursor'    => $cursor,
				'processed' => 0,
				'affected'  => 0,
				'done'      => true,
			);
		}

		$last_id  = (int) end( $rows )['id'];
		$contacts = $this->to_contacts( $rows );

		if ( empty( $contacts ) ) {
			return array(
				'cursor'    => $last_id,
				'processed' => count( $rows ),
				'affected'  => 0,
				'done'      => count( $rows ) < $limit,
			);
		}

		$sent = 0;

		foreach ( $this->chunk_by_weight( $contacts ) as $chunk ) {
			$sent += $this->send_chunk( $chunk );
		}

		return array(
			'cursor'    => $last_id,
			'processed' => count( $rows ),
			'affected'  => $sent,
			'done'      => count( $rows ) < $limit,
		);
	}

	/**
	 * Lit un lot d'inscriptions, adresse comprise.
	 *
	 * @param int $cursor Dernière inscription lue.
	 * @param int $limit  Taille du lot.
	 *
	 * @return array<int, array{id:int, email:string}>
	 */
	private function read_subscribers( int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = PayloadBuilder::active_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$sql = "SELECT p.ID, p.post_title AS email
				  FROM {$wpdb->posts} p
				 WHERE p.post_type = %s
				   AND p.ID > %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				 ORDER BY p.ID ASC
				 LIMIT %d';

		$values = array_merge(
			array( Host::SUBSCRIBER_TYPE, $cursor ),
			$statuses,
			array( $limit )
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$out = array();

		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'    => (int) $row->ID,
				'email' => (string) $row->email,
			);
		}

		return $out;
	}

	/**
	 * Transforme un lot d'inscriptions en contacts à envoyer.
	 *
	 * Dédoublonne par adresse et écarte ce qui est déjà à jour chez Brevo.
	 *
	 * @param array<int, array{id:int, email:string}> $rows Inscriptions.
	 *
	 * @return array<string, array<string, mixed>> Contacts indexés par adresse.
	 */
	private function to_contacts( array $rows ): array {
		$list_id  = Brevo::list_id();
		$contacts = array();

		foreach ( $rows as $row ) {
			$email = PayloadBuilder::normalize( $row['email'] );

			if ( '' === $email || isset( $contacts[ $email ] ) ) {
				continue;
			}

			$attributes = $this->builder->build( $email );

			if ( empty( $attributes ) ) {
				continue;
			}

			$hash = ContactState::payload_hash(
				array_merge( $attributes, array( 'list' => $list_id ) )
			);

			if ( ! $this->state->needs_sync( $email, $hash, $list_id ) ) {
				continue;
			}

			$contacts[ $email ] = array(
				'email'        => $email,
				'attributes'   => $attributes,
				'payload_hash' => $hash,
			);
		}

		return $contacts;
	}

	/**
	 * Découpe les contacts en envois respectant le poids maximal.
	 *
	 * Le découpage se fait à l'octet, et non à un nombre fixe de contacts : la
	 * limite de Brevo porte sur la taille de la charge utile, et un contact
	 * attendant cinq produits pèse bien plus qu'un autre.
	 *
	 * @param array<string, array<string, mixed>> $contacts Contacts.
	 *
	 * @return array<int, array<string, array<string, mixed>>>
	 */
	private function chunk_by_weight( array $contacts ): array {
		$chunks  = array();
		$current = array();
		$weight  = 0;

		foreach ( $contacts as $email => $contact ) {
			$size = strlen( (string) wp_json_encode( $contact ) );

			if ( ! empty( $current ) && ( $weight + $size ) > self::MAX_PAYLOAD_BYTES ) {
				$chunks[] = $current;
				$current  = array();
				$weight   = 0;
			}

			$current[ $email ] = $contact;
			$weight           += $size;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = $current;
		}

		return $chunks;
	}

	/**
	 * Envoie un groupe de contacts.
	 *
	 * @param array<string, array<string, mixed>> $chunk Contacts.
	 *
	 * @return int Nombre de contacts acceptés par Brevo.
	 */
	private function send_chunk( array $chunk ): int {
		$list_id = Brevo::list_id();
		$payload = array();

		foreach ( $chunk as $contact ) {
			$payload[] = array(
				'email'      => $contact['email'],
				'attributes' => $contact['attributes'],
			);
		}

		$response = ( new Contacts( new Client() ) )->import( $payload, $list_id );

		if ( ! $response->is_success() ) {
			foreach ( $chunk as $email => $contact ) {
				unset( $contact );
				$this->state->mark_failed( (string) $email, $response->error(), ! $response->is_retryable() );
			}

			Logger::error(
				sprintf(
					'Import Brevo refusé (HTTP %1$d) : %2$s',
					$response->status(),
					$response->error()
				)
			);

			return 0;
		}

		$body       = $response->body();
		$process_id = isset( $body['processId'] ) ? (int) $body['processId'] : 0;

		$this->remember_pending_import( $process_id, $chunk );

		if ( $process_id > 0 ) {
			// Premier contrôle à trente secondes, puis espacement progressif.
			Scheduler::schedule( time() + 30, self::HOOK_POLL, $process_id, true );
		}

		return count( $chunk );
	}

	/**
	 * Mémorise les adresses d'un import en attente de confirmation.
	 *
	 * @param int                                 $process_id Identifiant d'import Brevo.
	 * @param array<string, array<string, mixed>> $chunk      Contacts envoyés.
	 */
	private function remember_pending_import( int $process_id, array $chunk ): void {
		$state   = JobState::load( self::JOB_ID );
		$pending = (array) $state->get( 'pending_imports', array() );

		$entries = array();

		foreach ( $chunk as $email => $contact ) {
			$entries[ (string) $email ] = (string) $contact['payload_hash'];
		}

		if ( $process_id <= 0 ) {
			/*
			 * Import accepté sans identifiant : impossible d'en suivre l'issue.
			 * On marque tout de même l'envoi, faute de quoi le contact serait
			 * repoussé indéfiniment à chaque exécution.
			 */
			$this->confirm( $entries );

			return;
		}

		$pending[ $process_id ] = $entries;

		$state->merge( array( 'pending_imports' => $pending ) )->save();
	}

	/**
	 * Vérifie l'issue d'un import. Point d'entrée du hook de suivi.
	 *
	 * @param int $process_id Identifiant d'import Brevo.
	 */
	public function poll_process( $process_id ): void {
		$process_id = (int) $process_id;
		$state      = JobState::load( self::JOB_ID );
		$pending    = (array) $state->get( 'pending_imports', array() );

		if ( $process_id <= 0 || ! isset( $pending[ $process_id ] ) ) {
			return;
		}

		$response = ( new Contacts( new Client() ) )->process_status( $process_id );

		if ( ! $response->is_success() ) {
			$this->reschedule_poll( $process_id, $state );

			return;
		}

		$body   = $response->body();
		$status = isset( $body['status'] ) ? (string) $body['status'] : '';

		if ( in_array( $status, array( 'queued', 'processing' ), true ) ) {
			$this->reschedule_poll( $process_id, $state );

			return;
		}

		if ( 'completed' === $status ) {
			$this->confirm( (array) $pending[ $process_id ] );
		} else {
			foreach ( array_keys( (array) $pending[ $process_id ] ) as $email ) {
				$this->state->mark_failed(
					(string) $email,
					sprintf( 'import Brevo %1$d — %2$s', $process_id, $status ),
					true
				);
			}

			Logger::error( sprintf( 'Import Brevo %1$d terminé en « %2$s ».', $process_id, $status ) );
		}

		unset( $pending[ $process_id ] );
		$state->merge( array( 'pending_imports' => $pending ) )->save();
	}

	/**
	 * Reprogramme un contrôle d'import, à intervalle croissant.
	 *
	 * L'endpoint de suivi relève du quota des « autres » points d'entrée, limité
	 * à cent appels par heure tous usages confondus : un sondage régulier
	 * épuiserait ce quota et se ferait refuser.
	 *
	 * @param int      $process_id Identifiant d'import.
	 * @param JobState $state      État du travail.
	 */
	private function reschedule_poll( int $process_id, JobState $state ): void {
		$polls = (array) $state->get( 'poll_counts', array() );
		$count = isset( $polls[ $process_id ] ) ? (int) $polls[ $process_id ] + 1 : 1;

		// Huit contrôles au plus, espacés de 30 s, 1 min, 2 min, 4 min, etc.
		if ( $count > 8 ) {
			Logger::warning(
				sprintf( 'Import Brevo %d toujours en cours après huit contrôles : suivi abandonné.', $process_id )
			);

			$pending = (array) $state->get( 'pending_imports', array() );

			if ( isset( $pending[ $process_id ] ) ) {
				// Envoyé et accepté : on ne le repousse pas indéfiniment.
				$this->confirm( (array) $pending[ $process_id ] );
				unset( $pending[ $process_id ] );
			}

			unset( $polls[ $process_id ] );

			$state->merge(
				array(
					'pending_imports' => $pending,
					'poll_counts'     => $polls,
				)
			)->save();

			return;
		}

		$polls[ $process_id ] = $count;
		$state->merge( array( 'poll_counts' => $polls ) )->save();

		$delay = min( 30 * ( 2 ** ( $count - 1 ) ), 10 * MINUTE_IN_SECONDS );

		Scheduler::schedule( time() + $delay, self::HOOK_POLL, $process_id, true );
	}

	/**
	 * Marque un ensemble d'adresses comme synchronisées.
	 *
	 * @param array<string, string> $entries Adresse => empreinte de charge utile.
	 */
	private function confirm( array $entries ): void {
		$list_id = Brevo::list_id();

		foreach ( $entries as $email => $hash ) {
			$this->state->mark_synced( (string) $email, (string) $hash, $list_id );
		}
	}
}
