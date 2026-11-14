<?php
/**
 * Module d'historique des envois.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Admin\SubscriberColumns;
use EBISN\Tracking\AlertTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Compte les alertes envoyées à chaque inscription et affiche, dans la liste des
 * inscrits, combien d'alertes et de relances elle a reçues.
 *
 * Actif par défaut : il n'envoie rien, et n'écrit qu'une métadonnée à chaque
 * alerte. Les relances, elles, sont consignées par le module qui les envoie,
 * que celui-ci soit actif ou non — seul leur affichage en dépend.
 */
final class SendHistory extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'send_history';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Historique des envois par inscription', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'Ajoute à la liste des inscrits deux colonnes : le nombre d’alertes de retour en stock déjà envoyées à la personne — il peut y en avoir plusieurs, après chaque rupture — et le nombre de relances qui ont suivi la dernière alerte. Une info-bulle détaille alerte par alerte. Les alertes envoyées avant l’activation de ce module ne sont pas datées : leur nombre est estimé d’après le module de renotification, et signalé par « ≈ ». N’envoie aucun e-mail ; n’écrit qu’une métadonnée à chaque alerte.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		( new AlertTracker() )->register();

		if ( is_admin() ) {
			( new SubscriberColumns() )->register();
		}
	}
}
