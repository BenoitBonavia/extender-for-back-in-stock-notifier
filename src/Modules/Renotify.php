<?php
/**
 * Module de renotification à chaque rupture.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Renotify\Backfill;
use EBISN\Renotify\RenotifyService;
use EBISN\Renotify\StockWatcher;
use EBISN\Renotify\SubscriptionQuery;
use EBISN\Support\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Reprévient à chaque nouvelle rupture, jusqu'à l'achat ou le désabonnement.
 *
 * Une alerte de retour en stock ne sert aujourd'hui qu'une fois : la personne
 * est prévenue, et si le produit repart en quelques heures sans qu'elle ait
 * commandé, elle ne le sera plus jamais. Ce module la remet en attente dès que
 * le produit redevient indisponible.
 *
 * Désactivé par défaut : il provoque de nouveaux envois d'e-mails, ce qu'une
 * mise à jour ne doit pas déclencher sans qu'on l'ait demandé.
 */
final class Renotify extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'renotify';

	/**
	 * {@inheritDoc}
	 *
	 * @var bool
	 */
	protected $enabled_by_default = false;

	/**
	 * Rattrapage.
	 *
	 * @var Backfill|null
	 */
	private $backfill = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Reprévenir à chaque nouvelle rupture', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'Une alerte de retour en stock ne sert normalement qu’une fois : si le produit repart avant que la personne n’ait commandé, elle ne sera plus jamais prévenue. Ce module la remet automatiquement en attente dès que le produit redevient indisponible, et recommence à chaque cycle jusqu’à ce qu’elle achète ou se désabonne. Un rattrapage est lancé une fois à l’activation, sur les inscriptions déjà bloquées dans cet état. Attention : ce module provoque de nouveaux envois d’e-mails.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		/*
		 * L'option « Keep Subscription Entry to Subscribed Status » de l'hôte
		 * couvre déjà le besoin, à sa manière : elle empêche purement et
		 * simplement le passage en « Alerte envoyée ». Les deux mécanismes se
		 * marcheraient dessus, et le sien fausse le taux de conversion.
		 */
		if ( Host::keeps_subscribed_after_notification() ) {
			return;
		}

		$service = new RenotifyService();
		$query   = new SubscriptionQuery();

		( new StockWatcher( $service, $query ) )->register();

		$this->backfill = new Backfill( $service, $query );
		$this->backfill->register();

		add_action( 'ebisn_upgrade', array( $this, 'on_upgrade' ) );
		add_action( 'admin_init', array( $this, 'boot_jobs' ) );
	}

	/**
	 * Amorce le rattrapage à la première mise à jour suivant l'activation.
	 */
	public function on_upgrade(): void {
		if ( null !== $this->backfill ) {
			$this->backfill->maybe_bootstrap();
		}
	}

	/**
	 * Amorce et relance les traitements de fond.
	 *
	 * Répété sur `admin_init` : ce module étant désactivé par défaut, il
	 * n'écoute pas `ebisn_upgrade` au moment où cette action se déclenche. Sans
	 * cela, l'activer plus tard n'amorcerait jamais son rattrapage, la version
	 * installée n'ayant pas changé.
	 */
	public function boot_jobs(): void {
		if ( null === $this->backfill ) {
			return;
		}

		$this->backfill->maybe_bootstrap();
		$this->backfill->revive_if_stalled();
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

		if ( Host::keeps_subscribed_after_notification() ) {
			$lines[] = '<strong style="color:#b32d2e">' . esc_html__(
				'Renotification : module en veille. Le réglage « Keep Subscription Entry to Subscribed Status » de l’extension hôte est actif et fait déjà repartir les alertes — mais en empêchant toute inscription d’atteindre le statut « Alerte envoyée », ce qui rend le taux de conversion inexploitable. Décochez-le pour utiliser ce module.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';

			return $lines;
		}

		$state = JobState::load( Backfill::JOB_ID );

		if ( JobState::STATUS_RUNNING === $state->status() ) {
			$lines[] = sprintf(
				/* translators: 1: inscriptions examinées, 2: inscriptions remises en attente. */
				esc_html__( 'Rattrapage des renotifications : en cours — %1$s inscription(s) examinée(s), %2$s remise(s) en attente.', 'extender-for-back-in-stock-notifier' ),
				'<strong>' . esc_html( number_format_i18n( $state->processed() ) ) . '</strong>',
				'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
			);

			return $lines;
		}

		if ( JobState::STATUS_DONE === $state->status() ) {
			$lines[] = sprintf(
				/* translators: %s: inscriptions remises en attente. */
				esc_html__( 'Rattrapage des renotifications : terminé — %s inscription(s) remise(s) en attente.', 'extender-for-back-in-stock-notifier' ),
				'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
			);
		}

		return $lines;
	}
}
