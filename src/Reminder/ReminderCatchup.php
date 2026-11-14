<?php
/**
 * Rattrapage des relances, lancé à la demande du marchand.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Support\JobState;
use EBISN\Support\Lock;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Relance, UNE fois et seulement sur clic, les personnes prévenues il y a plus
 * que le délai de la première relance et jamais relancées depuis.
 *
 * Le balayage automatique (`ReminderSweep`) ne touche que les alertes postérieures
 * à l'activation du module. Ce travail comble l'historique, mais sur décision du
 * marchand : il peut ainsi modifier et tester ses modèles d'e-mail avant que le
 * moindre message ne parte. Rien ne le démarre de lui-même — ni l'activation du
 * module, ni une mise à jour.
 *
 * Il s'étale en passages, comme le balayage : plafond d'envois par passage, plage
 * horaire d'envoi, limiteur de l'hôte. Une personne sans achat ni désabonnement
 * qui a déjà été relancée ne revient jamais : son compteur la fait sortir de la
 * sélection, ce qui rend le travail idempotent.
 */
final class ReminderCatchup {

	/**
	 * Identifiant de l'état du travail.
	 */
	public const JOB_ID = 'reminder_catchup';

	/**
	 * Hook Action Scheduler d'un passage.
	 */
	public const HOOK = 'ebisn_reminder_catchup_step';

	/**
	 * Ancienneté maximale par défaut d'une alerte rattrapée, en jours.
	 */
	public const DEFAULT_MAX_AGE_DAYS = 30;

	/**
	 * Verrou d'exclusion entre deux passages.
	 */
	private const LOCK = 'reminder_catchup';

	/**
	 * Délai entre deux passages tant qu'il reste du travail, en secondes.
	 */
	private const STEP_DELAY = 300;

	/**
	 * Budget de temps d'un passage, en secondes.
	 */
	private const TIME_BUDGET = 20.0;

	/**
	 * Taille d'un lot de lecture.
	 */
	private const READ_SIZE = 100;

	/**
	 * Lectures d'inscriptions.
	 *
	 * @var ReminderQuery
	 */
	private $query;

	/**
	 * Service de relance.
	 *
	 * @var ReminderService
	 */
	private $service;

	/**
	 * Constructeur.
	 *
	 * @param ReminderQuery   $query   Lectures d'inscriptions.
	 * @param ReminderService $service Service de relance.
	 */
	public function __construct( ReminderQuery $query, ReminderService $service ) {
		$this->query   = $query;
		$this->service = $service;
	}

	/**
	 * Accroche le hook du passage.
	 */
	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	/**
	 * Ancienneté maximale réglée par le marchand, en jours.
	 *
	 * @return int
	 */
	public static function max_age_days(): int {
		return max( 1, (int) Settings::get( 'reminder_catchup_max_age_days', self::DEFAULT_MAX_AGE_DAYS ) );
	}

	/**
	 * État courant du rattrapage.
	 *
	 * @return JobState
	 */
	public function state(): JobState {
		return JobState::load( self::JOB_ID );
	}

	/**
	 * Fenêtre d'alertes concernée, en horodatages UNIX UTC.
	 *
	 * Bornée par la date d'activation du module : les alertes postérieures sont du
	 * ressort du balayage automatique. Les rattraper ici consommerait leur rang
	 * final, donc leur priverait d'une éventuelle seconde relance.
	 *
	 * @param int $max_age_days Ancienneté maximale, en jours.
	 *
	 * @return array{since:int, latest:int} `since` : plus ancienne ; `latest` : plus récente admise.
	 */
	public static function window( int $max_age_days ): array {
		$now = time();

		return array(
			'since'  => $now - max( 1, $max_age_days ) * DAY_IN_SECONDS,
			'latest' => min( $now - ReminderService::delay_seconds( 1 ), ReminderService::since() - 1 ),
		);
	}

	/**
	 * Majorant du nombre de personnes qu'un lancement relancerait.
	 *
	 * @param int $max_age_days Ancienneté maximale, en jours.
	 *
	 * @return int
	 */
	public function eligible_count( int $max_age_days ): int {
		$window = self::window( $max_age_days );

		return $this->query->count_catchup( $window['since'], $window['latest'] );
	}

	/**
	 * Lance le rattrapage.
	 *
	 * @param int $max_age_days Ancienneté maximale d'une alerte, en jours.
	 *
	 * @return bool Faux si un rattrapage est déjà en cours : le lancer deux fois n'a aucun effet.
	 */
	public function start( int $max_age_days ): bool {
		$state = $this->state();

		if ( $state->is_running() ) {
			return false;
		}

		$state->merge(
			array(
				'status'       => JobState::STATUS_RUNNING,
				'cursor'       => 0,
				'processed'    => 0,
				'affected'     => 0,
				'skipped'      => 0,
				'started_at'   => time(),
				'finished_at'  => 0,
				'stopped'      => false,
				'max_age_days' => max( 1, $max_age_days ),
				'last_error'   => '',
			)
		)->save();

		Logger::info( sprintf( 'Rattrapage des relances lancé (alertes de moins de %d jour(s)).', max( 1, $max_age_days ) ) );

		/**
		 * Le rattrapage des relances vient d'être lancé.
		 *
		 * @param int $max_age_days Ancienneté maximale d'une alerte, en jours.
		 */
		do_action( 'ebisn_reminder_catchup_started', max( 1, $max_age_days ) );

		Scheduler::enqueue( self::HOOK, '', true );

		return true;
	}

	/**
	 * Arrête le rattrapage en cours.
	 *
	 * Les personnes déjà relancées le restent ; les autres pourront l'être à un
	 * prochain lancement.
	 */
	public function stop(): void {
		self::abort( 'arrêté à la demande' );
	}

	/**
	 * Interrompt un rattrapage en cours, définitivement.
	 *
	 * Statique : la désactivation du plugin l'appelle sans avoir de module à
	 * instancier. Sans cela, un rattrapage laissé « en cours » serait repris à la
	 * réactivation par le chien de garde, donc sans nouveau clic du marchand.
	 *
	 * @param string $note Raison, pour le journal.
	 */
	public static function abort( string $note ): void {
		$state = JobState::load( self::JOB_ID );

		if ( ! $state->is_running() ) {
			return;
		}

		Scheduler::cancel_all( self::HOOK );

		$state->merge(
			array(
				'status'      => JobState::STATUS_DONE,
				'stopped'     => true,
				'finished_at' => time(),
			)
		)->save();

		Logger::info( sprintf( 'Rattrapage des relances interrompu : %s.', $note ) );
	}

	/**
	 * Relance un rattrapage dont le processus a été tué.
	 *
	 * À brancher sur une requête d'administration : sans cela, un travail « en
	 * cours » dont plus aucun passage n'est programmé resterait figé.
	 */
	public function revive_if_stalled(): void {
		$state = $this->state();

		if ( ! $state->is_stalled() || Scheduler::has_scheduled( self::HOOK ) ) {
			return;
		}

		/*
		 * Un passage sauvegarde son état au moins une fois par plage d'envoi, soit
		 * moins de quinze heures. Une journée entière de silence signifie que le
		 * module a été coupé, ou le plugin désactivé, pendant l'exécution : relancer
		 * des envois sans qu'on l'ait redemandé serait pire que de les laisser là.
		 */
		if ( ( time() - (int) $state->get( 'updated_at', 0 ) ) > DAY_IN_SECONDS ) {
			self::abort( 'abandonné après plus d’un jour d’inactivité' );

			return;
		}

		Logger::warning( 'Rattrapage des relances figé : relance automatique.' );

		Lock::release( self::LOCK );
		Scheduler::enqueue( self::HOOK, '', true );
	}

	/**
	 * Exécute un passage. Point d'entrée du hook Action Scheduler.
	 */
	public function run(): void {
		if ( ! $this->state()->is_running() ) {
			return;
		}

		if ( ! Lock::acquire( self::LOCK ) ) {
			// Verrou orphelin d'un passage tué : se replanifier plutôt que s'éteindre.
			Scheduler::replace( time() + 10 * MINUTE_IN_SECONDS, self::HOOK );

			return;
		}

		$next = time() + self::STEP_DELAY;

		try {
			$next = $this->pass();
		} catch ( \Throwable $error ) {
			$this->state()->merge(
				array(
					'status'     => JobState::STATUS_FAILED,
					'last_error' => $error->getMessage(),
				)
			)->save();

			Logger::error( sprintf( 'Rattrapage des relances interrompu : %s', $error->getMessage() ) );

			$next = 0;
		} finally {
			Lock::release( self::LOCK );
		}

		// Depuis le passage en cours (voir Scheduler::replace()), et seulement s'il n'a pas été arrêté entre-temps.
		if ( $next > 0 ) {
			$latest = $this->state();

			if ( $latest->is_running() ) {
				Scheduler::replace( $next, self::HOOK );
			}
		}
	}

	/**
	 * Un passage : relance un lot, avance le curseur.
	 *
	 * @return int Horodatage UNIX du passage suivant, `0` si le travail est terminé.
	 */
	private function pass(): int {
		$now   = time();
		$state = $this->state();

		// Hors plage d'envoi : ne rien faire, mais rester en vie jusqu'à l'ouverture.
		$opens = ReminderSweep::next_window_start( $now );

		if ( $opens > $now ) {
			$state->save();

			return $opens;
		}

		$email = ReminderMailer::email();

		if ( null === $email || ! $email->is_enabled() ) {
			// E-mail désactivé dans WooCommerce : on attend, on ne perd pas le travail.
			$state->save();

			return $now + HOUR_IN_SECONDS;
		}

		$age      = (int) $state->get( 'max_age_days', self::DEFAULT_MAX_AGE_DAYS );
		$window   = self::window( $age );
		$limit    = max( 1, (int) Settings::get( 'reminder_batch', ReminderSweep::DEFAULT_BATCH ) );
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$cursor   = $state->cursor();
		$sent     = 0;
		$skipped  = 0;
		$examined = 0;
		$stopped  = false;
		$finished = false;
		$aborted  = false;

		while ( $sent < $limit && microtime( true ) < $deadline ) {
			try {
				$batch = $this->query->candidates( $cursor, self::READ_SIZE, $window['since'], $window['latest'], 1 );
			} catch ( \RuntimeException $error ) {
				// Erreur de lecture : ce n'est PAS la fin de la liste. Terminer ici
				// marquerait le rattrapage comme fini alors qu'il reste du monde.
				Logger::warning( sprintf( 'Rattrapage des relances : lecture impossible (%s), nouvel essai plus tard.', $error->getMessage() ) );

				$stopped = true;
				break;
			}

			if ( empty( $batch ) ) {
				$finished = true;
				break;
			}

			foreach ( $batch as $row ) {
				// « Arrêter » peut survenir pendant le passage : ne plus rien envoyer ensuite.
				if ( ! $this->state()->is_running() ) {
					$aborted = true;
					break 2;
				}

				$result = $this->service->process( $row['id'], 1, true );

				if ( ReminderService::RESULT_DEFERRED === $result ) {
					// Limiteur d'envois saturé : on s'arrête SANS avancer le curseur,
					// cette inscription n'a pas été traitée.
					$stopped = true;
					break 2;
				}

				$cursor = $row['id'];
				++$examined;

				if ( ReminderService::RESULT_SENT === $result ) {
					++$sent;
				} elseif ( ReminderService::RESULT_SKIPPED === $result ) {
					++$skipped;
				}

				if ( $sent >= $limit || microtime( true ) >= $deadline ) {
					break 2;
				}
			}

			if ( count( $batch ) < self::READ_SIZE ) {
				$finished = true;
				break;
			}
		}

		/*
		 * Relire l'état avant d'écrire : `JobState::save()` réécrit tout le tableau,
		 * donc la copie chargée au début du passage écraserait un arrêt demandé
		 * entre-temps — et les envois reprendraient après « Arrêter ».
		 */
		$state = $this->state();

		if ( $aborted || ! $state->is_running() ) {
			return 0;
		}

		$state->merge(
			array(
				'cursor'    => $cursor,
				'processed' => $state->processed() + $examined,
				'affected'  => $state->affected() + $sent,
				'skipped'   => (int) $state->get( 'skipped', 0 ) + $skipped,
			)
		);

		if ( $finished && ! $stopped ) {
			$state->merge(
				array(
					'status'      => JobState::STATUS_DONE,
					'finished_at' => time(),
				)
			)->save();

			Logger::info(
				sprintf(
					'Rattrapage des relances terminé : %1$d relance(s) envoyée(s), %2$d sautée(s).',
					$state->affected(),
					(int) $state->get( 'skipped', 0 )
				)
			);

			/**
			 * Le rattrapage des relances est terminé.
			 *
			 * @param JobState $state État final.
			 */
			do_action( 'ebisn_reminder_catchup_finished', $state );

			return 0;
		}

		$state->save();

		return $now + self::STEP_DELAY;
	}
}
