<?php
/**
 * Suppression de l'alerte pour un inscrit ayant déjà acheté.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Empêche l'envoi de « votre produit est de retour en stock » à quelqu'un qui
 * vient de l'acheter.
 *
 * Le cas est loin d'être théorique : une inscription mise en file d'envoi
 * (`cwg_queued`) puis convertie garde sa place dans la file de l'extension
 * hôte. Changer son statut ne désarme rien — l'hôte a déjà sélectionné les
 * destinataires. Sans ce garde-fou, le client reçoit l'alerte après avoir
 * commandé, ce que les snippets remplacés ne traitaient pas.
 */
final class NotificationSuppressor {

	/**
	 * Accroche le filtre de l'extension hôte.
	 */
	public function register(): void {
		add_filter( Host::HOOK_STOP_EMAIL, array( $this, 'maybe_stop' ), 20, 2 );
	}

	/**
	 * Bloque l'alerte si l'inscription ne doit plus en recevoir.
	 *
	 * Deux cas, pour la même raison : l'extension hôte a déjà sélectionné ses
	 * destinataires quand l'inscription bascule, et changer son statut ne la
	 * retire pas de la file.
	 *
	 * @param bool $stop            Décision des filtres précédents.
	 * @param int  $subscription_id Inscription destinataire.
	 *
	 * @return bool
	 */
	public function maybe_stop( $stop, $subscription_id ): bool {
		if ( $stop ) {
			return true;
		}

		$subscription_id = (int) $subscription_id;

		if ( $subscription_id <= 0 ) {
			return (bool) $stop;
		}

		$status = (string) get_post_status( $subscription_id );

		$reasons = array(
			Host::STATUS_CONVERTED    => __( 'le produit a déjà été acheté', 'extender-for-back-in-stock-notifier' ),
			Host::STATUS_UNSUBSCRIBED => __( 'la personne s’est désabonnée', 'extender-for-back-in-stock-notifier' ),
		);

		if ( ! isset( $reasons[ $status ] ) ) {
			return (bool) $stop;
		}

		Logger::info(
			sprintf(
				'Alerte de retour en stock supprimée pour l’inscription #%1$d : %2$s.',
				$subscription_id,
				$reasons[ $status ]
			)
		);

		return true;
	}
}
