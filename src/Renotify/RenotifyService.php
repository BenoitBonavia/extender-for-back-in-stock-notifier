<?php
/**
 * Remise en attente d'une inscription déjà notifiée.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Renotify;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fait repasser en « En attente » une inscription qui avait été notifiée.
 *
 * L'écriture est déléguée à `CWG_Instock_API::subscriber_subscribed()`, comme le
 * désabonnement l'est à sa méthode jumelle : l'hôte pose lui-même ce statut par
 * cette voie, et l'emprunter laisse passer `transition_post_status`, donc ses
 * webhooks.
 *
 * La métadonnée `cwginstock_mail_on` n'est JAMAIS effacée au passage. C'est elle
 * qui garde la trace qu'une alerte a déjà été envoyée, et c'est sur elle que
 * repose le taux de conversion : l'effacer ferait sortir la personne du
 * dénominateur à chaque cycle, et le taux remonterait tout seul.
 */
final class RenotifyService {

	/**
	 * Nombre de remises en attente déjà appliquées à une inscription.
	 */
	public const META_CYCLES = '_ebisn_notify_cycles';

	/**
	 * Horodatage de la dernière remise en attente.
	 */
	public const META_LAST = '_ebisn_renotified_at';

	/**
	 * Statuts depuis lesquels une remise en attente a du sens.
	 *
	 * Uniquement « Alerte envoyée » : une inscription convertie ou désabonnée a
	 * quitté le cycle, et un échec d'envoi relève du marchand.
	 *
	 * @return string[]
	 */
	public static function source_statuses(): array {
		/**
		 * Statuts pouvant être remis en attente lors d'une rupture.
		 *
		 * @param string[] $statuses Statuts.
		 */
		return (array) apply_filters(
			'ebisn_renotify_source_statuses',
			array( Host::STATUS_MAILSENT )
		);
	}

	/**
	 * Nombre maximal de remises en attente par inscription.
	 *
	 * @return int `0` pour aucune limite.
	 */
	public static function max_cycles(): int {
		return max( 0, (int) Settings::get( 'renotify_max_cycles', 0 ) );
	}

	/**
	 * Remet une inscription en attente.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return bool Vrai si CET appel a effectué la remise en attente.
	 */
	public function renotify( int $subscription_id ): bool {
		$current = (string) get_post_status( $subscription_id );

		if ( ! in_array( $current, self::source_statuses(), true ) ) {
			return false;
		}

		$cycles = (int) get_post_meta( $subscription_id, self::META_CYCLES, true );
		$max    = self::max_cycles();

		if ( $max > 0 && $cycles >= $max ) {
			return false;
		}

		if ( ! $this->write_status( $subscription_id ) ) {
			return false;
		}

		update_post_meta( $subscription_id, self::META_CYCLES, $cycles + 1 );
		update_post_meta( $subscription_id, self::META_LAST, time() );

		/**
		 * Une inscription vient d'être remise en attente.
		 *
		 * @param int $subscription_id Inscription.
		 * @param int $cycles          Nombre de remises en attente, celle-ci comprise.
		 */
		do_action( 'ebisn_subscription_renotified', $subscription_id, $cycles + 1 );

		return true;
	}

	/**
	 * Écrit le statut, par l'API de l'hôte quand elle est disponible.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return bool
	 */
	private function write_status( int $subscription_id ): bool {
		if ( class_exists( '\CWG_Instock_API' ) ) {
			$api    = new \CWG_Instock_API();
			$result = $api->subscriber_subscribed( $subscription_id );

			return ! is_wp_error( $result ) && (int) $result > 0;
		}

		$result = wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_type'   => Host::SUBSCRIBER_TYPE,
				'post_status' => Host::STATUS_SUBSCRIBED,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				sprintf(
					'Inscription #%1$d : remise en attente refusée — %2$s',
					$subscription_id,
					$result->get_error_message()
				)
			);

			return false;
		}

		return (int) $result > 0;
	}
}
