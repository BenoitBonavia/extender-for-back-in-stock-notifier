<?php
/**
 * Module de désabonnement des alertes de réassort.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Conversion\NotificationSuppressor;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Unsubscribe\EmailLink;
use EBISN\Unsubscribe\ProductForm;
use EBISN\Unsubscribe\StatusRecorder;
use EBISN\Unsubscribe\SubscriberLocator;
use EBISN\Unsubscribe\UnsubscribeService;
use EBISN\Unsubscribe\VisitorCookie;

defined( 'ABSPATH' ) || exit;

/**
 * Permet à un client de sortir d'une alerte de retour en stock.
 *
 * L'extension hôte enregistre le statut « Unsubscribed » et sait le poser
 * depuis son administration, mais n'offre aucun moyen au client de s'en
 * servir : ni bouton, ni lien dans les e-mails. Ce module fournit ce chemin.
 *
 * L'enjeu dépasse le confort. Le réglage « Keep Subscription Entry to
 * Subscribed Status even Instock Email Sent » de l'hôte renvoie l'alerte à
 * CHAQUE réassort, indéfiniment, jusqu'à ce que l'inscription soit achetée ou
 * désabonnée. Sans ce module, la personne n'a aucune porte de sortie.
 */
final class Unsubscribe extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'unsubscribe';

	/**
	 * Classe de l'extension payante couvrant déjà cette fonctionnalité.
	 */
	private const HOST_ADDON = 'CWG_Instock_Notifier_Unsubscribe';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Permettre le désabonnement d’une alerte', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		if ( $this->addon_active() ) {
			return;
		}

		$service = new UnsubscribeService();

		/*
		 * Enregistré en premier, et sans condition d'affichage : le statut
		 * d'origine doit être capté même quand le désabonnement vient de
		 * l'administration de l'hôte ou de sa récupération de file.
		 */
		( new StatusRecorder() )->register();

		( new EmailLink( $service ) )->register();

		( new ProductForm( $service, new SubscriberLocator() ) )->register();

		/*
		 * Le blocage d'envoi appartient au module de conversion, qui le pose
		 * déjà pour les acheteurs. S'il est désactivé, on l'accroche ici :
		 * envoyer « votre produit est de retour » à quelqu'un qui vient de se
		 * désabonner est le pire résultat possible.
		 */
		if ( ! $this->conversion_module_handles_emails() ) {
			( new NotificationSuppressor() )->register();
		}
	}

	/**
	 * L'add-on payant de l'hôte est-il présent ?
	 *
	 * @return bool
	 */
	private function addon_active(): bool {
		return class_exists( self::HOST_ADDON );
	}

	/**
	 * Le module de conversion bloque-t-il déjà les envois ?
	 *
	 * @return bool
	 */
	private function conversion_module_handles_emails(): bool {
		return has_filter( Host::HOOK_STOP_EMAIL );
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

		if ( $this->addon_active() ) {
			$lines[] = '<strong style="color:#b26200">' . esc_html__(
				'Désabonnement : module en veille, l’extension « Unsubscribe » de ProPluginsLab est active et couvre déjà cette fonctionnalité.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';

			return $lines;
		}

		$lines[] = sprintf(
			/* translators: 1: nombre de désabonnements, 2: état de la mémorisation par cookie. */
			esc_html__( 'Désabonnements enregistrés : %1$s · mémorisation des visiteurs %2$s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( $this->count_unsubscribed() ) ) . '</strong>',
			VisitorCookie::is_enabled()
				? '<code>' . esc_html__( 'activée', 'extender-for-back-in-stock-notifier' ) . '</code>'
				: '<code>' . esc_html__( 'désactivée', 'extender-for-back-in-stock-notifier' ) . '</code>'
		);

		if ( $this->repeat_notifications_enabled() ) {
			$lines[] = sprintf(
				/* translators: %s: nom du réglage de l'extension hôte. */
				esc_html__( 'Le réglage %s de l’extension hôte est actif : sans désabonnement, une alerte est renvoyée à chaque réassort, indéfiniment.', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html__( 'Keep Subscription Entry to Subscribed Status', 'extender-for-back-in-stock-notifier' ) . '</code>'
			);
		}

		return $lines;
	}

	/**
	 * Nombre d'inscriptions désabonnées.
	 *
	 * @return int
	 */
	private function count_unsubscribed(): int {
		$counts = wp_count_posts( Host::SUBSCRIBER_TYPE );
		$status = Host::STATUS_UNSUBSCRIBED;

		return isset( $counts->$status ) ? (int) $counts->$status : 0;
	}

	/**
	 * L'hôte renvoie-t-il l'alerte à chaque réassort ?
	 *
	 * @return bool
	 */
	private function repeat_notifications_enabled(): bool {
		return Host::keeps_subscribed_after_notification();
	}
}
