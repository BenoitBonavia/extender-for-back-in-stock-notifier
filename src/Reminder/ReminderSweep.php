<?php
/**
 * Balayage périodique des relances dues.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Support\Lock;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Tâche récurrente : chaque heure, envoie les relances arrivées à échéance.
 *
 * Pas de `BatchRunner` : il sert un parcours fini, avec un état terminal et un
 * rattrapage à relancer. Ici la tâche ne se termine jamais — elle se replanifie.
 * Seuls le verrou, le planificateur et le journal sont réutilisés.
 */
final class ReminderSweep {

	/**
	 * Hook Action Scheduler d'un passage.
	 */
	public const HOOK = 'ebisn_reminder_sweep';

	/**
	 * Verrou d'exclusion entre deux passages.
	 */
	private const LOCK = 'reminder_sweep';

	/**
	 * Nombre de relances envoyées par passage, par défaut.
	 */
	public const DEFAULT_BATCH = 50;

	/**
	 * Budget de temps d'un passage, en secondes.
	 */
	private const TIME_BUDGET = 20.0;

	/**
	 * Taille d'un lot de lecture.
	 */
	private const READ_SIZE = 100;

	/**
	 * Heures d'envoi par défaut, heure du site : de 9 h (incluse) à 20 h (exclue).
	 */
	private const DEFAULT_WINDOW = array( 9, 20 );

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
	 * Garantit qu'un passage est programmé.
	 *
	 * Appelé depuis des requêtes ordinaires : un passage dont le planificateur a
	 * perdu la trace — action purgée, base restaurée — doit renaître seul.
	 */
	public function ensure_scheduled(): void {
		if ( Scheduler::has_scheduled( self::HOOK ) ) {
			return;
		}

		ReminderService::since();

		Scheduler::schedule( time() + 60, self::HOOK, '', true );
	}

	/**
	 * Annule les passages programmés.
	 */
	public function cancel(): void {
		Scheduler::cancel_all( self::HOOK );
	}

	/**
	 * Exécute un passage. Point d'entrée du hook Action Scheduler.
	 */
	public function run(): void {
		if ( ! Lock::acquire( self::LOCK ) ) {
			// Un passage tué en cours a pu laisser son verrou : se replanifier, sans
			// quoi la chaîne s'arrêterait jusqu'à la prochaine visite de l'administration.
			Scheduler::replace( time() + 10 * MINUTE_IN_SECONDS, self::HOOK );

			return;
		}

		// Avant tout : détecte une longue interruption du module (voir la méthode).
		ReminderService::touch();
		ReminderService::ensure_migrated();

		$next = time() + HOUR_IN_SECONDS;

		try {
			$next = $this->sweep();
		} catch ( \Throwable $error ) {
			Logger::error( sprintf( 'Passage des relances interrompu : %s', $error->getMessage() ) );
		} finally {
			Lock::release( self::LOCK );

			// Toujours se replanifier, même après une erreur : sans cela, une
			// exception unique éteindrait les relances jusqu'à la prochaine requête.
			Scheduler::replace( $next, self::HOOK );
		}
	}

	/**
	 * Un passage : lit les inscriptions dues, relance, rend l'heure du suivant.
	 *
	 * @return int Horodatage UNIX du prochain passage.
	 */
	private function sweep(): int {
		$now = time();

		$window_start = self::next_window_start( $now );

		if ( $window_start > $now ) {
			return $window_start;
		}

		$email = ReminderMailer::email();

		if ( null === $email || ! $email->is_enabled() ) {
			return $now + HOUR_IN_SECONDS;
		}

		$limit    = max( 1, (int) Settings::get( 'reminder_batch', self::DEFAULT_BATCH ) );
		$deadline = microtime( true ) + self::TIME_BUDGET;
		$since    = ReminderService::since();
		$latest   = $now - ReminderService::delay_seconds( 1 );
		$cursor   = 0;
		$sent     = 0;
		$deferred = false;

		while ( $sent < $limit && microtime( true ) < $deadline ) {
			$batch = $this->query->candidates( $cursor, self::READ_SIZE, $since, $latest, ReminderService::max_steps() );

			if ( empty( $batch ) ) {
				break;
			}

			foreach ( $batch as $row ) {
				$cursor = $row['id'];
				$step   = ReminderService::next_step( $row['mail_on'], $row['sent'], $now );

				if ( $step <= 0 ) {
					continue;
				}

				$result = $this->service->process( $row['id'], $step );

				if ( ReminderService::RESULT_SENT === $result ) {
					++$sent;
				} elseif ( ReminderService::RESULT_DEFERRED === $result ) {
					// Le limiteur d'envois de l'hôte est saturé : inutile d'insister.
					$deferred = true;
				}

				if ( $deferred || $sent >= $limit || microtime( true ) >= $deadline ) {
					break 2;
				}
			}

			if ( count( $batch ) < self::READ_SIZE ) {
				break;
			}
		}

		if ( $sent > 0 ) {
			Logger::info( sprintf( 'Passage des relances : %d relance(s) envoyée(s).', $sent ) );
		}

		// Il en reste vraisemblablement : reprendre vite plutôt qu'attendre une heure.
		if ( $deferred || $sent >= $limit ) {
			return $now + 5 * MINUTE_IN_SECONDS;
		}

		return $now + HOUR_IN_SECONDS;
	}

	/**
	 * Heure à laquelle l'envoi est (ou sera) permis.
	 *
	 * Une relance à trois heures du matin est lue comme du démarchage. En dehors
	 * de la plage, le passage se replanifie à son ouverture sans rien envoyer.
	 *
	 * @param int $now Instant courant (UNIX).
	 *
	 * @return int `$now` si l'envoi est permis maintenant.
	 */
	public static function next_window_start( int $now ): int {
		/**
		 * Plage horaire d'envoi des relances, en heures du site.
		 *
		 * @param int[] $window `array( début inclus, fin exclue )`, entre 0 et 24.
		 */
		$window = (array) apply_filters( 'ebisn_reminder_send_window', self::DEFAULT_WINDOW );
		$start  = isset( $window[0] ) ? max( 0, min( 23, (int) $window[0] ) ) : self::DEFAULT_WINDOW[0];
		$end    = isset( $window[1] ) ? max( 1, min( 24, (int) $window[1] ) ) : self::DEFAULT_WINDOW[1];

		// Plage incohérente : revenir à la plage par défaut plutôt que lever toute restriction.
		if ( $start >= $end ) {
			$start = self::DEFAULT_WINDOW[0];
			$end   = self::DEFAULT_WINDOW[1];
		}

		$hour = (int) wp_date( 'G', $now );

		if ( $hour >= $start && $hour < $end ) {
			return $now;
		}

		$tz    = wp_timezone();
		$local = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		$open  = $local->setTime( $start, 0, 0 );

		if ( $open->getTimestamp() <= $now ) {
			$open = $open->modify( '+1 day' );
		}

		return $open->getTimestamp();
	}
}
