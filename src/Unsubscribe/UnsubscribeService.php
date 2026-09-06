<?php
/**
 * Désabonnement d'une inscription et retour en arrière.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fait passer une inscription en « Unsubscribed », et sait revenir en arrière.
 *
 * L'écriture est **déléguée à l'extension hôte**, qui expose déjà
 * `CWG_Instock_API::subscriber_unsubscribed()` et s'en sert pour son action
 * groupée d'administration. C'est un choix inverse de celui retenu pour la
 * conversion « Purchased », où l'écriture directe s'imposait faute d'API : ici,
 * emprunter la sienne garantit un comportement identique au sien, et laisse
 * passer `transition_post_status` — donc ses webhooks partent normalement.
 *
 * Le statut d'origine, lui, n'est pas mémorisé ici mais dans
 * `StatusRecorder` : il doit l'être quel que soit l'auteur du changement.
 */
final class UnsubscribeService {

	/**
	 * Désabonne une inscription.
	 *
	 * @param int    $subscription_id Inscription.
	 * @param string $source          Origine : `button`, `email` ou `admin`.
	 *
	 * @return bool Vrai si CET appel a effectué le désabonnement.
	 */
	public function unsubscribe( int $subscription_id, string $source = 'button' ): bool {
		$current = (string) get_post_status( $subscription_id );

		if ( ! in_array( $current, SubscriberLocator::active_statuses(), true ) ) {
			return false;
		}

		if ( ! $this->write_status( $subscription_id, Host::STATUS_UNSUBSCRIBED ) ) {
			return false;
		}

		update_post_meta( $subscription_id, StatusRecorder::META_SOURCE, $source );

		$this->refresh_host_counter( $subscription_id, $current );

		Logger::info(
			sprintf(
				'Inscription #%1$d désabonnée depuis « %2$s » (origine %3$s).',
				$subscription_id,
				$current,
				$source
			)
		);

		/**
		 * Une inscription vient d'être désabonnée.
		 *
		 * @param int    $subscription_id Inscription.
		 * @param string $previous        Statut précédent.
		 * @param string $source          Origine de la demande.
		 */
		do_action( 'ebisn_subscription_unsubscribed', $subscription_id, $current, $source );

		return true;
	}

	/**
	 * Rétablit une inscription désabonnée dans son statut d'origine.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return bool Vrai si CET appel a effectué le rétablissement.
	 */
	public function resubscribe( int $subscription_id ): bool {
		if ( Host::STATUS_UNSUBSCRIBED !== get_post_status( $subscription_id ) ) {
			return false;
		}

		$previous = (string) get_post_meta( $subscription_id, StatusRecorder::META_PREVIOUS, true );

		/*
		 * Sans statut d'origine connu — un désabonnement antérieur à ce module,
		 * ou posé par un add-on —, on rétablit « en attente » : c'est l'état le
		 * plus proche de l'intention, et le seul qui remette la personne dans la
		 * file des futures alertes.
		 */
		if ( ! in_array( $previous, SubscriberLocator::active_statuses(), true ) ) {
			$previous = Host::STATUS_SUBSCRIBED;
		}

		if ( ! $this->write_status( $subscription_id, $previous ) ) {
			return false;
		}

		delete_post_meta( $subscription_id, StatusRecorder::META_PREVIOUS );
		delete_post_meta( $subscription_id, StatusRecorder::META_SOURCE );

		$this->refresh_host_counter( $subscription_id, $previous );

		Logger::info(
			sprintf( 'Inscription #%1$d rétablie au statut « %2$s ».', $subscription_id, $previous )
		);

		/**
		 * Une inscription désabonnée vient d'être rétablie.
		 *
		 * @param int    $subscription_id Inscription.
		 * @param string $restored        Statut restauré.
		 */
		do_action( 'ebisn_subscription_resubscribed', $subscription_id, $previous );

		return true;
	}

	/**
	 * Écrit le statut, par l'API de l'hôte quand elle est disponible.
	 *
	 * @param int    $subscription_id Inscription.
	 * @param string $status          Statut à poser.
	 *
	 * @return bool
	 */
	private function write_status( int $subscription_id, string $status ): bool {
		if ( Host::STATUS_UNSUBSCRIBED === $status && $this->host_api_available() ) {
			$api    = new \CWG_Instock_API();
			$result = $api->subscriber_unsubscribed( $subscription_id );

			return ! is_wp_error( $result ) && (int) $result > 0;
		}

		/*
		 * Repli : rétablissement d'un statut quelconque, ou API de l'hôte
		 * absente d'une version future. `wp_update_post()` plutôt qu'une
		 * écriture directe, pour rester aligné sur ce que fait l'hôte et
		 * déclencher les mêmes transitions.
		 */
		$result = wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_type'   => Host::SUBSCRIBER_TYPE,
				'post_status' => $status,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				sprintf(
					'Inscription #%1$d : changement de statut refusé — %2$s',
					$subscription_id,
					$result->get_error_message()
				)
			);

			return false;
		}

		return (int) $result > 0;
	}

	/**
	 * L'API de désabonnement de l'hôte est-elle utilisable ?
	 *
	 * `class_exists()` suffit, et n'est pas une formalité : l'extension hôte
	 * n'inclut AUCUN de ses fichiers tant que WooCommerce est inactif. La
	 * classe peut donc parfaitement manquer alors que l'extension est installée.
	 *
	 * @return bool
	 */
	private function host_api_available(): bool {
		return class_exists( '\CWG_Instock_API' );
	}

	/**
	 * Remet à jour le compteur d'inscrits en attente de l'hôte.
	 *
	 * `subscriber_unsubscribed()` ne le fait pas : sans ce recalcul, le nombre
	 * d'inscrits affiché sur la fiche produit surestime durablement la demande.
	 *
	 * @param int    $subscription_id Inscription.
	 * @param string $status          Statut entrant ou sortant.
	 */
	private function refresh_host_counter( int $subscription_id, string $status ): void {
		if ( Host::STATUS_SUBSCRIBED !== $status ) {
			return;
		}

		Host::refresh_subscriber_count(
			(int) get_post_meta( $subscription_id, Host::META_PRODUCT_ID, true )
		);
	}
}
