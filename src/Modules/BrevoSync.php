<?php
/**
 * Module de synchronisation des inscrits vers Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Brevo\AttributeInstaller;
use EBISN\Brevo\Backfill;
use EBISN\Brevo\Consent;
use EBISN\Brevo\ContactState;
use EBISN\Brevo\PayloadBuilder;
use EBISN\Brevo\SyncService;
use EBISN\Admin\BrevoBackfillPage;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Integration\Brevo;
use EBISN\Support\JobState;
use EBISN\Support\Scheduler;
use EBISN\Support\Settings;
use EBISN\Support\SnippetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Pousse dans une liste Brevo les adresses inscrites à une alerte de réassort.
 *
 * Désactivé par défaut : il dépend d'une extension tierce et d'une liste que
 * seul le marchand peut désigner. Un module qui s'activerait seul enverrait des
 * données à un compte externe sans que personne ne l'ait demandé.
 */
final class BrevoSync extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'brevo_sync';

	/**
	 * {@inheritDoc}
	 *
	 * @var bool
	 */
	protected $enabled_by_default = false;

	/**
	 * Hook différé de déclaration des attributs chez Brevo.
	 */
	public const HOOK_INSTALL_ATTRIBUTES = 'ebisn_brevo_install_attributes';

	/**
	 * Fonctions des snippets WPCode que ce module remplace.
	 */
	private const SNIPPET_FUNCTIONS = array(
		'mh_brevo_push_waitlist_contact',
		'mh_brevo_bf_process_batch',
	);

	/**
	 * Service de synchronisation.
	 *
	 * @var SyncService|null
	 */
	private $sync = null;

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
		return __( 'Synchroniser les inscrits vers Brevo', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		if ( $this->snippet_guard()->is_blocked() ) {
			return;
		}

		$state = new ContactState();

		$this->sync = new SyncService( $state, new PayloadBuilder() );
		$this->sync->register();

		$this->backfill = new Backfill( $state, new PayloadBuilder() );
		$this->backfill->register();

		if ( Settings::get_bool( 'brevo_require_consent', false ) ) {
			( new Consent() )->register();
		}

		if ( is_admin() ) {
			( new BrevoBackfillPage() )->register();
		}

		// Priorité 20 : après Consent, qui persiste la preuve en priorité 5.
		add_action( Host::HOOK_AFTER_INSERT_SUBSCRIBER, array( $this, 'on_subscriber_created' ), 20, 1 );

		add_action( self::HOOK_INSTALL_ATTRIBUTES, array( $this, 'install_attributes' ) );
		add_action( 'ebisn_upgrade', array( $this, 'on_upgrade' ) );
		add_action( 'admin_init', array( $this, 'revive_jobs' ) );
	}

	/**
	 * Met l'adresse d'un nouvel inscrit en file de synchronisation.
	 *
	 * Le second argument du hook est délibérément ignoré : l'extension hôte
	 * l'émet depuis trois endroits, avec des formes différentes — la clé de
	 * l'adresse y est `user_email` en AJAX mais `email` en REST. La métadonnée,
	 * elle, est écrite avant le hook sur les trois chemins.
	 *
	 * @param int $subscription_id Inscription créée.
	 */
	public function on_subscriber_created( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;

		if ( $subscription_id <= 0 || null === $this->sync ) {
			return;
		}

		$email = PayloadBuilder::normalize(
			(string) get_post_meta( $subscription_id, Host::META_EMAIL, true )
		);

		if ( '' === $email ) {
			// Repli : l'hôte recopie aussi l'adresse dans le titre du contenu.
			$email = PayloadBuilder::normalize( (string) get_the_title( $subscription_id ) );
		}

		if ( '' === $email ) {
			return;
		}

		/**
		 * Faut-il synchroniser cette inscription vers Brevo ?
		 *
		 * @param bool   $should          Décision par défaut.
		 * @param int    $subscription_id Inscription concernée.
		 * @param string $email           Adresse normalisée.
		 */
		if ( ! apply_filters( 'ebisn_brevo_should_sync', true, $subscription_id, $email ) ) {
			return;
		}

		$this->sync->enqueue( $email );
	}

	/**
	 * Prépare la table et le rattrapage au premier démarrage.
	 */
	public function on_upgrade(): void {
		if ( null !== $this->backfill ) {
			$this->backfill->maybe_bootstrap();
		}

		/*
		 * La déclaration des attributs passe par le réseau : elle est différée
		 * plutôt qu'exécutée ici. `ebisn_upgrade` se déclenche sur une requête
		 * ordinaire — souvent un affichage de page —, où deux appels d'API
		 * bloquants n'ont rien à faire.
		 */
		if ( Brevo::has_api_key() ) {
			Scheduler::enqueue( self::HOOK_INSTALL_ATTRIBUTES, '', true );
		}
	}

	/**
	 * Déclare les attributs chez Brevo. Point d'entrée du hook différé.
	 */
	public function install_attributes(): void {
		( new AttributeInstaller() )->ensure();
	}

	/**
	 * Amorce ce qui doit l'être, et relance un rattrapage resté en plan.
	 *
	 * L'amorçage est répété ici, et pas seulement sur `ebisn_upgrade` : ce
	 * module étant désactivé par défaut, il n'écoute pas encore cette action au
	 * moment où elle se déclenche. Sans ce rattrapage, l'activer plus tard
	 * n'initialiserait jamais son état, la version installée n'ayant pas changé.
	 *
	 * `JobState::bootstrap()` reposant sur `add_option()`, l'appel est sans
	 * effet une fois l'état créé.
	 */
	public function revive_jobs(): void {
		if ( null === $this->backfill ) {
			return;
		}

		$this->backfill->maybe_bootstrap();
		$this->backfill->revive_if_stalled();
	}

	/**
	 * Ajoute l'état de la synchronisation au panneau Diagnostic.
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

		if ( ! Brevo::has_api_key() ) {
			$lines[] = '<strong style="color:#b32d2e">' . esc_html__(
				'Brevo : aucune clé d’API trouvée. Connectez l’extension Brevo, ou saisissez une clé dans les réglages.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';

			return $lines;
		}

		if ( Brevo::list_id() <= 0 ) {
			$lines[] = '<strong style="color:#b26200">' . esc_html__(
				'Brevo : aucune liste sélectionnée. Le module reste inactif tant qu’une liste n’est pas choisie.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';

			return $lines;
		}

		$lines[] = sprintf(
			/* translators: 1: nombre de contacts, 2: identifiant de liste Brevo, 3: origine de la clé d'API. */
			esc_html__( 'Brevo : %1$s contact(s) synchronisé(s) vers la liste %2$s · clé d’API %3$s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( ( new ContactState() )->count_by_state( ContactState::STATE_SYNCED ) ) ) . '</strong>',
			'<code>#' . esc_html( (string) Brevo::list_id() ) . '</code>',
			Brevo::key_comes_from_plugin()
				? esc_html__( 'fournie par l’extension Brevo', 'extender-for-back-in-stock-notifier' )
				: esc_html__( 'saisie dans les réglages', 'extender-for-back-in-stock-notifier' )
		);

		$failed = ( new ContactState() )->count_by_state( ContactState::STATE_FAILED );

		if ( $failed > 0 ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: nombre de contacts en échec. */
				esc_html__( 'Brevo : %s contact(s) en échec définitif.', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $failed ) )
			) . '</strong>';
		}

		$lines[] = $this->backfill_line();

		if ( Brevo::woocommerce_connector_handles_restock() ) {
			$lines[] = '<strong style="color:#b26200">' . esc_html__(
				'Le connecteur « Brevo for WooCommerce » gère lui aussi le retour en stock : il affiche son propre formulaire et tient sa propre liste d’attente. Désactivez cette option côté Brevo pour éviter deux alertes concurrentes.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';
		}

		return array_values( array_filter( $lines ) );
	}

	/**
	 * Ligne de diagnostic décrivant l'état du rattrapage.
	 *
	 * @return string
	 */
	private function backfill_line(): string {
		$state = JobState::load( Backfill::JOB_ID );

		switch ( $state->status() ) {
			case JobState::STATUS_WAITING:
				return sprintf(
					/* translators: %s: URL de la page de rattrapage. */
					esc_html__( 'Rattrapage Brevo : en attente de votre validation. %s', 'extender-for-back-in-stock-notifier' ),
					'<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Host::SUBSCRIBER_TYPE . '&page=ebisn-brevo-backfill' ) ) . '">'
						. esc_html__( 'Ouvrir la page de rattrapage', 'extender-for-back-in-stock-notifier' ) . '</a>'
				);

			case JobState::STATUS_RUNNING:
				return sprintf(
					/* translators: 1: nombre d'inscriptions examinées, 2: nombre de contacts envoyés. */
					esc_html__( 'Rattrapage Brevo : en cours — %1$s inscription(s) examinée(s), %2$s contact(s) envoyé(s).', 'extender-for-back-in-stock-notifier' ),
					'<strong>' . esc_html( number_format_i18n( $state->processed() ) ) . '</strong>',
					'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
				);

			case JobState::STATUS_DONE:
				return sprintf(
					/* translators: %s: nombre de contacts envoyés. */
					esc_html__( 'Rattrapage Brevo : terminé — %s contact(s) envoyé(s).', 'extender-for-back-in-stock-notifier' ),
					'<strong>' . esc_html( number_format_i18n( $state->affected() ) ) . '</strong>'
				);

			default:
				return '';
		}
	}

	/**
	 * Garde de détection des snippets remplacés.
	 *
	 * @return SnippetGuard
	 */
	private function snippet_guard(): SnippetGuard {
		return new SnippetGuard( self::SNIPPET_FUNCTIONS );
	}
}
