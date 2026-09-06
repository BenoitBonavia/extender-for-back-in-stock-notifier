<?php
/**
 * Moteur d'exécution des traitements par lots.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Exécute un BatchJob par étapes auto-chaînées.
 *
 * Une seule étape est programmée à la fois : le chaînage est auto-limitant et
 * supprime la concurrence par construction, là où un lot d'actions parallèles
 * imposerait de synchroniser les écritures.
 *
 * Chaque étape s'arrête à la première des trois bornes atteintes — temps,
 * mémoire, fin du jeu de données — et persiste sa position AVANT de programmer
 * la suivante : une interruption brutale coûte au pire un lot rejoué.
 */
final class BatchRunner {

	/**
	 * Budget de temps par étape, en secondes.
	 *
	 * Confortablement sous les 30 secondes du lanceur d'Action Scheduler.
	 */
	private const TIME_BUDGET = 15.0;

	/**
	 * Part de la mémoire PHP au-delà de laquelle l'étape s'arrête.
	 */
	private const MEMORY_CEILING = 0.8;

	/**
	 * Délai entre deux étapes, en secondes.
	 */
	private const STEP_DELAY = 5;

	/**
	 * Travail à exécuter.
	 *
	 * @var BatchJob
	 */
	private $job;

	/**
	 * Constructeur.
	 *
	 * @param BatchJob $job Travail à exécuter.
	 */
	public function __construct( BatchJob $job ) {
		$this->job = $job;
	}

	/**
	 * Démarre — ou relance — le travail.
	 *
	 * @param bool $force Repartir de zéro même si le travail est déjà terminé.
	 */
	public function start( bool $force = false ): void {
		$state = JobState::load( $this->job->get_id() );

		if ( $force ) {
			$state->merge(
				array(
					'cursor'     => 0,
					'processed'  => 0,
					'affected'   => 0,
					'last_error' => '',
				)
			);
		}

		$state->merge(
			array(
				'status'     => JobState::STATUS_RUNNING,
				'started_at' => $state->get( 'started_at' ) ? $state->get( 'started_at' ) : time(),
			)
		)->save();

		$this->schedule_next( true );
	}

	/**
	 * Exécute une étape. Point d'entrée du hook Action Scheduler.
	 */
	public function run_step(): void {
		$id = $this->job->get_id();

		if ( ! Lock::acquire( $id ) ) {
			// Une autre étape est en vol : ne rien faire, elle programmera la suite.
			return;
		}

		try {
			$this->run_until_budget_exhausted();
		} catch ( \Throwable $error ) {
			JobState::load( $id )->merge(
				array(
					'status'     => JobState::STATUS_FAILED,
					'last_error' => $error->getMessage(),
				)
			)->save();

			Logger::error(
				sprintf( 'Traitement « %1$s » interrompu : %2$s', $id, $error->getMessage() )
			);
		} finally {
			Lock::release( $id );
		}
	}

	/**
	 * Boucle sur les lots jusqu'à épuisement du budget de l'étape.
	 */
	private function run_until_budget_exhausted(): void {
		$state = JobState::load( $this->job->get_id() );

		if ( ! $state->is_running() ) {
			return;
		}

		$deadline = microtime( true ) + self::TIME_BUDGET;

		do {
			$result = $this->job->process( $state->cursor(), $this->job->get_batch_size() );

			$state->merge(
				array(
					'cursor'    => (int) $result['cursor'],
					'processed' => $state->processed() + (int) $result['processed'],
					'affected'  => $state->affected() + (int) $result['affected'],
				)
			);

			if ( ! empty( $result['done'] ) ) {
				$state->merge( array( 'status' => JobState::STATUS_DONE ) )->save();

				Logger::info(
					sprintf(
						'Traitement « %1$s » terminé : %2$d élément(s) examiné(s), %3$d modifié(s).',
						$this->job->get_id(),
						$state->processed(),
						$state->affected()
					)
				);

				/**
				 * Un traitement par lots vient de s'achever.
				 *
				 * @param string   $job_id Identifiant du travail.
				 * @param JobState $state  État final.
				 */
				do_action( 'ebisn_batch_job_done', $this->job->get_id(), $state );

				return;
			}

			// Persister à chaque lot, et non en fin d'étape : le processus peut
			// être tué par le serveur sans que le `finally` ne s'exécute.
			$state->save();

		} while ( microtime( true ) < $deadline && ! $this->memory_exhausted() );

		$this->schedule_next( false );
	}

	/**
	 * La mémoire disponible est-elle en passe d'être épuisée ?
	 *
	 * @return bool
	 */
	private function memory_exhausted(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $limit <= 0 ) {
			// Pas de limite déclarée : rien à surveiller.
			return false;
		}

		return memory_get_usage( true ) > ( $limit * self::MEMORY_CEILING );
	}

	/**
	 * Programme l'étape suivante.
	 *
	 * @param bool $immediate Exécuter dès que possible plutôt qu'après un délai.
	 */
	private function schedule_next( bool $immediate ): void {
		if ( $immediate ) {
			Scheduler::enqueue( $this->job->get_hook(), '', true );

			return;
		}

		Scheduler::schedule( time() + self::STEP_DELAY, $this->job->get_hook(), '', true );
	}

	/**
	 * Relance un travail dont le processus a été tué en cours de route.
	 *
	 * À brancher sur un déclencheur régulier : sans cela, un travail « en cours »
	 * dont aucune étape n'est programmée resterait figé indéfiniment.
	 */
	public function revive_if_stalled(): void {
		$state = JobState::load( $this->job->get_id() );

		if ( ! $state->is_stalled() ) {
			return;
		}

		if ( Scheduler::has_scheduled( $this->job->get_hook() ) ) {
			return;
		}

		Logger::warning(
			sprintf( 'Traitement « %s » figé : relance automatique.', $this->job->get_id() )
		);

		Lock::release( $this->job->get_id() );
		$this->schedule_next( true );
	}
}
