<?php
/**
 * Module de conversion des inscriptions en « Purchased ».
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Conversion\Backfill;
use EBISN\Conversion\ConversionService;
use EBISN\Conversion\NotificationSuppressor;
use EBISN\Conversion\OrderLookup;
use EBISN\Conversion\RealtimeListener;
use EBISN\Conversion\SubscriptionRepository;
use EBISN\Migration\LegacyConversionMeta;
use EBISN\Support\JobState;
use EBISN\Support\Settings;
use EBISN\Support\SnippetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Marque « Purchased » les inscrits qui ont commandé le produit attendu.
 *
 * L'extension hôte déclare bien ce statut, mais aucun code de sa version
 * gratuite ne le pose : il est réservé à l'une de ses extensions payantes. Ce
 * module fournit le déclencheur manquant, au fil de l'eau et sur l'historique.
 *
 * Cette classe ne fait que du câblage : toute la logique vit dans `EBISN\Conversion`.
 */
final class PurchaseConversion extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'purchase_conversion';

	/**
	 * Fonctions des snippets WPCode que ce module remplace.
	 *
	 * Seules celles qui écrivent : leur présence signifie que le snippet
	 * convertirait les mêmes inscriptions en parallèle.
	 */
	private const SNIPPET_FUNCTIONS = array(
		'mh_bisn_mark_purchased',
		'mh_bisn_backfill_page',
	);

	/**
	 * Rattrapage sur l'historique.
	 *
	 * @var Backfill|null
	 */
	private $backfill = null;

	/**
	 * Migration des métadonnées de snippet.
	 *
	 * @var LegacyConversionMeta|null
	 */
	private $migration = null;

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Marquer « Purchased » les inscrits qui ont commandé', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		/*
		 * Tant que le snippet remplacé est chargé, ce module reste inerte : les
		 * deux convertiraient les mêmes inscriptions, et le rattrapage
		 * s'exécuterait pendant que le snippet écrit ses propres métadonnées.
		 */
		if ( $this->snippet_guard()->is_blocked() ) {
			return;
		}

		$conversions   = new ConversionService();
		$subscriptions = new SubscriptionRepository();

		( new RealtimeListener( $conversions, $subscriptions ) )->register();

		if ( Settings::get_bool( 'conversion_suppress_notification', true ) ) {
			( new NotificationSuppressor() )->register();
		}

		$this->backfill = new Backfill( $conversions, $subscriptions, new OrderLookup() );
		$this->backfill->register();

		$this->migration = new LegacyConversionMeta();
		$this->migration->register();

		/*
		 * L'amorçage passe par `ebisn_upgrade`, et non par le hook d'activation :
		 * celui-ci ne se déclenche pas lors d'une mise à jour, et pas davantage
		 * site par site en multisite.
		 */
		add_action( 'ebisn_upgrade', array( $this, 'on_upgrade' ) );

		// Chien de garde : reprend un traitement dont le processus a été tué.
		add_action( 'admin_init', array( $this, 'revive_jobs' ) );
	}

	/**
	 * Amorce la migration puis le rattrapage.
	 *
	 * Dans cet ordre : le rattrapage doit voir les conversions déjà faites par
	 * les snippets sous leur nouvelle forme, sinon il les reprendrait à son
	 * compte. Les deux travaux étant chacun protégés par leur propre verrou,
	 * ils peuvent progresser en parallèle sans se gêner.
	 */
	public function on_upgrade(): void {
		if ( null !== $this->migration ) {
			$this->migration->maybe_bootstrap();
		}

		if ( null !== $this->backfill ) {
			$this->backfill->maybe_bootstrap();
		}
	}

	/**
	 * Amorce ce qui doit l'être, et relance les traitements restés en plan.
	 *
	 * L'amorçage est répété ici, et pas seulement sur `ebisn_upgrade` : un
	 * module désactivé au moment de la mise à jour, puis réactivé, n'aurait
	 * sinon jamais reçu cette action — la version installée ne changeant plus.
	 * `JobState::bootstrap()` s'appuyant sur `add_option()`, l'appel est sans
	 * effet une fois l'état créé.
	 */
	public function revive_jobs(): void {
		if ( null !== $this->migration ) {
			$this->migration->maybe_bootstrap();
			$this->migration->revive_if_stalled();
		}

		if ( null !== $this->backfill ) {
			$this->backfill->maybe_bootstrap();
			$this->backfill->revive_if_stalled();
		}
	}

	/**
	 * Garde de détection du snippet remplacé.
	 *
	 * @return SnippetGuard
	 */
	private function snippet_guard(): SnippetGuard {
		return new SnippetGuard( self::SNIPPET_FUNCTIONS );
	}

	/**
	 * Ajoute l'état du rattrapage au panneau Diagnostic.
	 *
	 * @param string[] $lines Lignes existantes.
	 *
	 * @return string[]
	 */
	public function add_diagnostics( $lines ): array {
		$lines = (array) $lines;

		$blocked = $this->snippet_guard()->diagnostics_line( $this->get_title() );

		if ( '' !== $blocked ) {
			$lines[] = $blocked;

			return $lines;
		}

		$inherited = LegacyConversionMeta::inherited_count();

		if ( $inherited > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: nombre de conversions reprises des snippets. */
				esc_html__( '%s conversion(s) reprise(s) des snippets : leur statut d’origine est inconnu, elles ne peuvent pas être annulées automatiquement.', 'extender-for-back-in-stock-notifier' ),
				'<strong>' . esc_html( number_format_i18n( $inherited ) ) . '</strong>'
			);
		}

		$state = JobState::load( Backfill::JOB_ID );

		switch ( $state->status() ) {
			case JobState::STATUS_DONE:
				$lines[] = sprintf(
					/* translators: 1: nombre d'inscriptions examinées, 2: nombre de conversions. */
					esc_html__( 'Rattrapage des achats passés : terminé — %1$s inscription(s) examinée(s), %2$s convertie(s).', 'extender-for-back-in-stock-notifier' ),
					'<strong>' . esc_html( number_format_i18n( $state->processed() ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
				);
				break;

			case JobState::STATUS_RUNNING:
				$lines[] = sprintf(
					/* translators: 1: nombre d'inscriptions examinées, 2: nombre de conversions. */
					esc_html__( 'Rattrapage des achats passés : en cours — %1$s inscription(s) examinée(s), %2$s convertie(s).', 'extender-for-back-in-stock-notifier' ),
					'<strong>' . esc_html( number_format_i18n( $state->processed() ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
				);
				break;

			case JobState::STATUS_FAILED:
				$lines[] = '<strong style="color:#b32d2e">' . sprintf(
					/* translators: %s: message d'erreur. */
					esc_html__( 'Rattrapage des achats passés : interrompu — %s', 'extender-for-back-in-stock-notifier' ),
					'<code>' . esc_html( (string) $state->get( 'last_error', '' ) ) . '</code>'
				) . '</strong>';
				break;
		}

		return $lines;
	}
}
