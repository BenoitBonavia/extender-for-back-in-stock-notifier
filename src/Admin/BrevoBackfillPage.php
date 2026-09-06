<?php
/**
 * Page de lancement et de suivi du rattrapage Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Brevo\AttributeInstaller;
use EBISN\Brevo\Backfill;
use EBISN\Brevo\ContactState;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Integration\Brevo;
use EBISN\Support\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Sous-page « Rattrapage Brevo », sous le menu de l'extension hôte.
 *
 * La page ne fait QUE lancer et afficher : le travail lui-même est exécuté par
 * Action Scheduler. Le snippet remplacé, lui, se rechargeait toutes les huit
 * secondes pour faire avancer ses lots — fermer l'onglet figeait le travail, et
 * deux administrateurs sur la page lançaient deux exécutions concurrentes.
 *
 * Le lancement demande un clic, jamais automatique : un premier envoi de masse
 * vers un compte Brevo est irréversible et peut déclencher des scénarios
 * d'automatisation.
 */
final class BrevoBackfillPage {

	/**
	 * Identifiant de la page.
	 */
	public const SLUG = 'ebisn-brevo-backfill';

	/**
	 * Action du nonce.
	 */
	private const NONCE = 'ebisn_brevo_backfill';

	/**
	 * Contacts d'une simulation, à afficher sous le formulaire.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private $preview = array();

	/**
	 * Accroche la page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
	}

	/**
	 * Déclare la sous-page.
	 */
	public function add_page(): void {
		add_submenu_page(
			Host::MENU_PARENT,
			__( 'Rattrapage Brevo', 'extender-for-back-in-stock-notifier' ),
			__( 'Rattrapage Brevo', 'extender-for-back-in-stock-notifier' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Affiche la page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'extender-for-back-in-stock-notifier' ) );
		}

		$notice = $this->handle_actions();
		$state  = JobState::load( Backfill::JOB_ID );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Rattrapage Brevo', 'extender-for-back-in-stock-notifier' ) . '</h1>';

		echo '<p>' . esc_html__(
			'Envoie vers votre liste Brevo les adresses déjà inscrites à une alerte de retour en stock. Brevo identifiant un contact par son adresse, un envoi répété ne crée jamais de doublon : il met simplement le contact à jour.',
			'extender-for-back-in-stock-notifier'
		) . '</p>';

		if ( '' !== $notice ) {
			echo wp_kses_post( $notice );
		}

		if ( ! $this->render_requirements() ) {
			echo '</div>';

			return;
		}

		$this->render_status( $state );
		$this->render_form( $state );
		$this->render_preview();

		echo '</div>';
	}

	/**
	 * Affiche l'aperçu d'une simulation.
	 */
	private function render_preview(): void {
		if ( array() === $this->preview ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Aperçu — premiers contacts', 'extender-for-back-in-stock-notifier' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:1000px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Adresse', 'extender-for-back-in-stock-notifier' ) . '</th>';
		echo '<th>' . esc_html__( 'Attributs envoyés', 'extender-for-back-in-stock-notifier' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->preview as $contact ) {
			$parts = array();

			foreach ( (array) $contact['attributes'] as $key => $value ) {
				$parts[] = $key . ' = ' . ( is_scalar( $value ) ? (string) $value : '' );
			}

			printf(
				'<tr><td>%1$s</td><td><code>%2$s</code></td></tr>',
				esc_html( (string) $contact['email'] ),
				esc_html( implode( ' · ', $parts ) )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__(
			'Un attribut absent de cette liste ne sera pas envoyé : sa valeur actuelle chez Brevo restera donc intacte.',
			'extender-for-back-in-stock-notifier'
		) . '</p>';
	}

	/**
	 * Traite le formulaire.
	 *
	 * @return string Notice HTML, chaîne vide si aucune action n'a été demandée.
	 */
	private function handle_actions(): string {
		if ( ! isset( $_POST['ebisn_brevo_action'] ) ) {
			return '';
		}

		if ( ! check_admin_referer( self::NONCE ) ) {
			return '';
		}

		$action   = sanitize_key( wp_unslash( $_POST['ebisn_brevo_action'] ) );
		$backfill = new Backfill();

		if ( 'preview' === $action ) {
			$this->preview = $backfill->preview();

			if ( array() === $this->preview ) {
				return '<div class="notice notice-warning"><p>' . esc_html__(
					'Rien à envoyer : toutes les adresses en attente sont déjà à jour chez Brevo.',
					'extender-for-back-in-stock-notifier'
				) . '</p></div>';
			}

			return '<div class="notice notice-info"><p>' . esc_html__(
				'Simulation — aucun appel à Brevo n’a été effectué.',
				'extender-for-back-in-stock-notifier'
			) . '</p></div>';
		}

		if ( 'run' === $action ) {
			// Les attributs doivent exister AVANT tout envoi : Brevo ignore
			// silencieusement les valeurs d'un attribut qu'il ne connaît pas.
			if ( ! ( new AttributeInstaller() )->ensure() ) {
				return '<div class="notice notice-error"><p>' . esc_html__(
					'Impossible de déclarer les attributs de contact chez Brevo. Vérifiez la clé d’API, puis réessayez : sans ces attributs, les données seraient envoyées puis ignorées.',
					'extender-for-back-in-stock-notifier'
				) . '</p></div>';
			}

			$backfill->start( true );

			return '<div class="notice notice-success"><p>' . esc_html__(
				'Rattrapage lancé. Il se poursuit en arrière-plan, même si vous quittez cette page.',
				'extender-for-back-in-stock-notifier'
			) . '</p></div>';
		}

		if ( 'dismiss' === $action ) {
			JobState::load( Backfill::JOB_ID )
				->merge( array( 'status' => JobState::STATUS_DONE ) )
				->save();

			return '<div class="notice notice-info"><p>' . esc_html__(
				'Rattrapage marqué comme effectué. Aucune donnée n’a été envoyée.',
				'extender-for-back-in-stock-notifier'
			) . '</p></div>';
		}

		return '';
	}

	/**
	 * Affiche les prérequis manquants.
	 *
	 * @return bool Vrai si tout est en place.
	 */
	private function render_requirements(): bool {
		$ready = true;

		if ( ! Brevo::has_api_key() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__(
				'Aucune clé d’API Brevo n’est disponible. Connectez l’extension Brevo, ou saisissez une clé dans les réglages de l’extension.',
				'extender-for-back-in-stock-notifier'
			) . '</p></div>';

			$ready = false;
		}

		if ( Brevo::list_id() <= 0 ) {
			echo '<div class="notice notice-error"><p>' . esc_html__(
				'Aucune liste Brevo n’est sélectionnée. Choisissez-en une dans les réglages avant de lancer le rattrapage.',
				'extender-for-back-in-stock-notifier'
			) . '</p></div>';

			$ready = false;
		}

		return $ready;
	}

	/**
	 * Affiche l'état courant.
	 *
	 * @param JobState $state État du travail.
	 */
	private function render_status( JobState $state ): void {
		$contacts = new ContactState();

		echo '<table class="widefat striped" style="max-width:640px;margin-bottom:20px;"><tbody>';

		$this->row(
			__( 'Adresses restant à envoyer', 'extender-for-back-in-stock-notifier' ),
			number_format_i18n( ( new Backfill() )->count_pending() )
		);
		$this->row(
			__( 'Contacts déjà synchronisés', 'extender-for-back-in-stock-notifier' ),
			number_format_i18n( $contacts->count_by_state( ContactState::STATE_SYNCED ) )
		);
		$this->row(
			__( 'Contacts en échec', 'extender-for-back-in-stock-notifier' ),
			number_format_i18n( $contacts->count_by_state( ContactState::STATE_FAILED ) )
		);
		$this->row(
			__( 'Liste de destination', 'extender-for-back-in-stock-notifier' ),
			'#' . Brevo::list_id()
		);
		$this->row(
			__( 'État du rattrapage', 'extender-for-back-in-stock-notifier' ),
			$this->status_label( $state )
		);

		if ( $state->processed() > 0 ) {
			$this->row(
				__( 'Progression', 'extender-for-back-in-stock-notifier' ),
				sprintf(
					/* translators: 1: nombre d'inscriptions examinées, 2: nombre de contacts envoyés. */
					__( '%1$s inscription(s) examinée(s), %2$s contact(s) envoyé(s)', 'extender-for-back-in-stock-notifier' ),
					number_format_i18n( $state->processed() ),
					number_format_i18n( $state->affected() )
				)
			);
		}

		$error = (string) $state->get( 'last_error', '' );

		if ( '' !== $error ) {
			$this->row( __( 'Dernière erreur', 'extender-for-back-in-stock-notifier' ), $error );
		}

		echo '</tbody></table>';
	}

	/**
	 * Affiche une ligne du tableau d'état.
	 *
	 * @param string $label Libellé.
	 * @param string $value Valeur.
	 */
	private function row( string $label, string $value ): void {
		printf(
			'<tr><th scope="row" style="width:240px;">%1$s</th><td>%2$s</td></tr>',
			esc_html( $label ),
			esc_html( $value )
		);
	}

	/**
	 * Libellé lisible d'un état.
	 *
	 * @param JobState $state État du travail.
	 *
	 * @return string
	 */
	private function status_label( JobState $state ): string {
		switch ( $state->status() ) {
			case JobState::STATUS_RUNNING:
				return __( 'En cours', 'extender-for-back-in-stock-notifier' );

			case JobState::STATUS_DONE:
				return __( 'Terminé', 'extender-for-back-in-stock-notifier' );

			case JobState::STATUS_FAILED:
				return __( 'Interrompu', 'extender-for-back-in-stock-notifier' );

			case JobState::STATUS_WAITING:
				return __( 'En attente de validation', 'extender-for-back-in-stock-notifier' );

			default:
				return __( 'Jamais lancé', 'extender-for-back-in-stock-notifier' );
		}
	}

	/**
	 * Affiche les commandes.
	 *
	 * @param JobState $state État du travail.
	 */
	private function render_form( JobState $state ): void {
		if ( $state->is_running() ) {
			echo '<p>' . esc_html__(
				'Le rattrapage est en cours. Suivez son avancement dans WooCommerce → État → Actions programmées, ou rechargez cette page.',
				'extender-for-back-in-stock-notifier'
			) . '</p>';

			return;
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE );

		printf(
			'<button type="submit" name="ebisn_brevo_action" value="preview" class="button">%s</button> ',
			esc_html__( 'Simuler', 'extender-for-back-in-stock-notifier' )
		);

		printf(
			'<button type="submit" name="ebisn_brevo_action" value="run" class="button button-primary" onclick="return confirm(%1$s);">%2$s</button> ',
			esc_attr( wp_json_encode( __( 'Envoyer ces contacts vers Brevo ? L’opération n’est pas annulable.', 'extender-for-back-in-stock-notifier' ) ) ),
			esc_html__( 'Lancer le rattrapage', 'extender-for-back-in-stock-notifier' )
		);

		if ( JobState::STATUS_WAITING === $state->status() ) {
			printf(
				'<button type="submit" name="ebisn_brevo_action" value="dismiss" class="button">%s</button>',
				esc_html__( 'Ne rien envoyer, marquer comme fait', 'extender-for-back-in-stock-notifier' )
			);
		}

		echo '</form>';
	}
}
