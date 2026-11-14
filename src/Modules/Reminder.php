<?php
/**
 * Module de relance après l'alerte de retour en stock.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Plugin;
use EBISN\Reminder\ReminderCatchup;
use EBISN\Reminder\ReminderQuery;
use EBISN\Reminder\ReminderService;
use EBISN\Reminder\ReminderSweep;
use EBISN\Support\JobState;
use EBISN\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Relance les personnes prévenues du retour en stock qui n'ont pas commandé.
 *
 * L'alerte de l'extension hôte ne part qu'une fois : sans réaction, plus rien
 * ne se passe. Ce module envoie, quelques jours plus tard, un e-mail court
 * rappelant que le produit est toujours disponible — au plus deux, la seconde
 * étant facultative.
 *
 * Désactivé par défaut : il provoque de nouveaux envois d'e-mails, ce qu'une
 * mise à jour ne doit pas déclencher sans qu'on l'ait demandé.
 */
final class Reminder extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'reminder';

	/**
	 * {@inheritDoc}
	 *
	 * @var bool
	 */
	protected $enabled_by_default = false;

	/**
	 * Balayage périodique.
	 *
	 * @var ReminderSweep|null
	 */
	private $sweep = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Relancer après l’alerte de retour en stock', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'L’alerte de retour en stock ne part qu’une fois : si la personne ne réagit pas, plus rien ne se passe. Ce module lui envoie, quelques jours plus tard, un e-mail rappelant que le produit est toujours disponible — une relance, et une seconde facultative. Elle n’est envoyée que si l’inscription est toujours à « Alerte envoyée », si le produit est encore en stock et si aucune commande de la personne ne le contient. Chaque e-mail porte un lien de désabonnement. Une inscription n’a droit qu’à une seule séquence de relances : si elle est notifiée de nouveau après une rupture, elle n’est pas relancée une seconde fois. Seules les alertes envoyées après l’activation sont relancées. Attention : ce module provoque de nouveaux envois d’e-mails.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * Raison pour laquelle le module doit rester en veille.
	 *
	 * @return string Chaîne vide si rien ne s'y oppose.
	 */
	public function dormant_reason(): string {
		if ( Host::keeps_subscribed_after_notification() ) {
			return __( 'Le réglage « Keep Subscription Entry to Subscribed Status » de l’extension hôte est actif : aucune inscription n’atteint « Alerte envoyée », il n’y a donc personne à relancer. Décochez-le pour utiliser ce module.', 'extender-for-back-in-stock-notifier' );
		}

		if ( null === Plugin::instance()->get_module( 'purchase_conversion' ) ) {
			return __( 'Le module « Marquer Purchased les inscrits qui ont commandé » est désactivé : sans lui, on ne sait pas qui a déjà acheté, et la relance partirait aussi vers des clients. Activez-le pour utiliser ce module.', 'extender-for-back-in-stock-notifier' );
		}

		if ( null === Plugin::instance()->get_module( 'unsubscribe' ) ) {
			return __( 'Le module « Désabonnement » est désactivé : chaque relance porte un lien de désabonnement qui ne fonctionnerait pas, et une personne qui se serait retirée recevrait la suivante. Activez-le pour utiliser ce module.', 'extender-for-back-in-stock-notifier' );
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		if ( '' !== $this->dormant_reason() ) {
			return;
		}

		$this->sweep = new ReminderSweep( new ReminderQuery(), new ReminderService() );
		$this->sweep->register();

		/*
		 * Le rattrapage s'enregistre mais ne démarre JAMAIS de lui-même : ni sur
		 * `ebisn_upgrade`, ni à l'activation du module. Seul un clic du marchand,
		 * une fois ses modèles d'e-mail relus, l'amorce.
		 */
		$catchup = new ReminderCatchup( new ReminderQuery(), new ReminderService() );
		$catchup->register();
		add_action( 'admin_init', array( $catchup, 'revive_if_stalled' ) );

		/*
		 * Sur `admin_init` et non `init` : vérifier qu'une action est programmée
		 * interroge Action Scheduler, ce qu'on ne veut pas à chaque page vue. Le
		 * passage se replanifie lui-même ; ceci ne sert qu'à l'amorcer, et à le
		 * faire renaître si le planificateur en a perdu la trace.
		 */
		add_action( 'admin_init', array( $this->sweep, 'ensure_scheduled' ) );
		add_action( 'ebisn_upgrade', array( $this->sweep, 'ensure_scheduled' ) );
	}

	/**
	 * Ajoute l'état du module au panneau Diagnostic.
	 *
	 * @param string[] $lines Lignes existantes.
	 *
	 * @return string[]
	 */
	public function add_diagnostics( $lines ): array {
		$lines = (array) $lines;

		$dormant = $this->dormant_reason();

		if ( '' !== $dormant ) {
			$lines[] = '<strong style="color:#b32d2e">' . esc_html__( 'Relances : module en veille.', 'extender-for-back-in-stock-notifier' ) . '</strong> ' . esc_html( $dormant );

			return $lines;
		}

		if ( Host::auto_delete_enabled() ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: nombre de jours. */
				esc_html__( 'Relances : la suppression automatique de l’extension hôte est active (%s jours). Elle efface les inscriptions « Alerte envoyée » selon leur date d’INSCRIPTION, donc souvent avant que la relance ne soit due. Allongez cette durée au-delà du délai de la dernière relance.', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( Host::auto_delete_days() ) )
			) . '</strong>';
		}

		$state = JobState::load( ReminderCatchup::JOB_ID );

		if ( JobState::STATUS_RUNNING === $state->status() ) {
			$lines[] = sprintf(
				/* translators: 1: inscriptions examinées, 2: relances envoyées. */
				esc_html__( 'Rattrapage des relances : en cours — %1$s inscription(s) examinée(s), %2$s relance(s) envoyée(s).', 'extender-for-back-in-stock-notifier' ),
				'<strong>' . esc_html( number_format_i18n( $state->processed() ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
			);
		}

		$counts = ( new ReminderQuery() )->counts_since( time() - 30 * DAY_IN_SECONDS );

		$lines[] = sprintf(
			/* translators: 1: relances envoyées, 2: relances sautées. */
			esc_html__( 'Relances, sur 30 jours : %1$s envoyée(s), %2$s sautée(s).', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( $counts['sent'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $counts['skipped'] ) ) . '</strong>'
		);

		$next = (int) wp_next_scheduled( ReminderSweep::HOOK );

		if ( $next <= 0 && function_exists( 'as_next_scheduled_action' ) ) {
			$scheduled = as_next_scheduled_action( ReminderSweep::HOOK, null, Scheduler::GROUP );
			$next      = is_int( $scheduled ) ? $scheduled : 0;
		}

		if ( $next > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: date et heure du prochain passage. */
				esc_html__( 'Prochain passage des relances : %s.', 'extender-for-back-in-stock-notifier' ),
				'<strong>' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $next ) ) . '</strong>'
			);
		}

		return $lines;
	}
}
