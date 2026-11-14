<?php
/**
 * Comptage des alertes réellement envoyées.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Tracking;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Consigne chaque alerte de retour en stock envoyée par l'extension hôte.
 */
final class AlertTracker {

	/**
	 * Accroche l'écoute des envois.
	 */
	public function register(): void {
		add_action( Host::HOOK_MAIL_SENT_SUCCESS, array( $this, 'on_alert_sent' ), 10, 1 );
	}

	/**
	 * Une alerte vient de partir.
	 *
	 * @param mixed $subscription_id Inscription destinataire.
	 */
	public function on_alert_sent( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;

		if ( $subscription_id <= 0 || Host::SUBSCRIBER_TYPE !== get_post_type( $subscription_id ) ) {
			return;
		}

		DeliveryHistory::record_alert( $subscription_id );
	}
}
