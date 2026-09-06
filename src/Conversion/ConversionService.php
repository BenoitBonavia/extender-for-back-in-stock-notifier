<?php
/**
 * Écriture des conversions d'inscription.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Fait passer une inscription en « Purchased », et sait revenir en arrière.
 *
 * Unique point du plugin autorisé à modifier le statut d'une inscription.
 *
 * Deux partis pris expliquent la forme de cette classe :
 *
 * 1. **Mise à jour conditionnelle plutôt que marqueur d'idempotence.** Le
 *    `UPDATE … WHERE post_status = <statut lu>` ne peut réussir qu'une fois :
 *    deux traitements concurrents ne peuvent pas convertir la même inscription,
 *    et rejouer un lot entier est sans effet. Il devient donc inutile d'écrire
 *    quoi que ce soit sur la commande — ce que faisaient les snippets, au prix
 *    d'une sauvegarde de commande, et donc d'un webhook `order.updated`, sur
 *    chaque commande examinée.
 * 2. **SQL direct plutôt que `wp_update_post()`.** Cette dernière déclencherait
 *    `save_post` sur un type de contenu qui ne nous appartient pas, avec le
 *    risque qu'un gestionnaire de l'hôte — appelé hors de son écran d'édition,
 *    donc sans `$_POST` — écrase les métadonnées de l'inscription. Ce n'est pas
 *    une édition éditoriale, c'est une transition d'état métier.
 */
final class ConversionService {

	/**
	 * Statuts depuis lesquels une inscription peut être convertie.
	 *
	 * Les désinscrits et les échecs d'envoi en sont volontairement absents.
	 *
	 * @return string[]
	 */
	public static function convertible_statuses(): array {
		/**
		 * Statuts d'inscription convertibles en « Purchased ».
		 *
		 * Restreindre à `cwg_mailsent` ne compte que les achats consécutifs à
		 * l'alerte, au prix de laisser « en attente » des inscriptions dont le
		 * titulaire a déjà acheté.
		 *
		 * @param string[] $statuses Statuts convertibles.
		 */
		return (array) apply_filters(
			'ebisn_convertible_statuses',
			array( Host::STATUS_MAILSENT, Host::STATUS_SUBSCRIBED, Host::STATUS_QUEUED )
		);
	}

	/**
	 * Convertit une inscription.
	 *
	 * @param int    $subscription_id Inscription à convertir.
	 * @param int    $order_id        Commande à l'origine de la conversion.
	 * @param string $source          Origine : `realtime`, `backfill` ou `snippet`.
	 * @param int    $converted_at    Horodatage UNIX UTC ; l'instant courant si `0`.
	 *
	 * @return bool Vrai si CET appel a effectué la conversion.
	 */
	public function convert( int $subscription_id, int $order_id, string $source, int $converted_at = 0 ): bool {
		$previous = (string) get_post_status( $subscription_id );

		if ( ! in_array( $previous, self::convertible_statuses(), true ) ) {
			return false;
		}

		/*
		 * Le statut lu sert de condition : si un autre processus l'a modifié
		 * entre-temps, aucune ligne n'est touchée et on renonce. Aucune fenêtre
		 * de concurrence ne subsiste, sans avoir à poser de verrou.
		 */
		if ( ! $this->swap_status( $subscription_id, $previous, Host::STATUS_CONVERTED ) ) {
			return false;
		}

		$converted_at = $converted_at > 0 ? $converted_at : time();

		update_post_meta( $subscription_id, MetaKeys::PREVIOUS_STATUS, $previous );
		update_post_meta( $subscription_id, MetaKeys::ORDER_ID, $order_id );
		update_post_meta( $subscription_id, MetaKeys::CONVERTED_AT, $converted_at );
		update_post_meta( $subscription_id, MetaKeys::SOURCE, $source );

		$this->record_lag( $subscription_id, $previous, $converted_at );
		$this->refresh_host_counter( $subscription_id, $previous );

		Logger::info(
			sprintf(
				'Inscription #%1$d convertie en « Purchased » depuis « %2$s » (commande #%3$d, origine %4$s).',
				$subscription_id,
				$previous,
				$order_id,
				$source
			)
		);

		/**
		 * Une inscription vient d'être convertie.
		 *
		 * @param int    $subscription_id Inscription convertie.
		 * @param int    $order_id        Commande à l'origine de la conversion.
		 * @param string $previous        Statut précédent.
		 * @param string $source          Origine de la conversion.
		 */
		do_action( 'ebisn_subscription_converted', $subscription_id, $order_id, $previous, $source );

		return true;
	}

	/**
	 * Annule une conversion et restaure le statut d'origine.
	 *
	 * @param int    $subscription_id Inscription à rétablir.
	 * @param int    $order_id        Restreindre à cette commande ; `0` pour ne pas restreindre.
	 * @param string $reason          Motif, à des fins de journalisation.
	 *
	 * @return bool Vrai si CET appel a effectué le retour en arrière.
	 */
	public function revert( int $subscription_id, int $order_id = 0, string $reason = '' ): bool {
		$previous = (string) get_post_meta( $subscription_id, MetaKeys::PREVIOUS_STATUS, true );

		if ( '' === $previous ) {
			/*
			 * Conversion héritée des snippets : ceux-ci n'enregistraient pas le
			 * statut d'origine. Deviner ferait plus de dégâts que de s'abstenir.
			 */
			return false;
		}

		if ( $order_id > 0 && (int) get_post_meta( $subscription_id, MetaKeys::ORDER_ID, true ) !== $order_id ) {
			return false;
		}

		if ( ! $this->swap_status( $subscription_id, Host::STATUS_CONVERTED, $previous ) ) {
			return false;
		}

		foreach ( MetaKeys::subscription_keys() as $key ) {
			delete_post_meta( $subscription_id, $key );
		}

		$this->refresh_host_counter( $subscription_id, $previous );

		Logger::info(
			sprintf(
				'Conversion de l’inscription #%1$d annulée, statut rétabli à « %2$s »%3$s.',
				$subscription_id,
				$previous,
				'' === $reason ? '' : ' — ' . $reason
			)
		);

		/**
		 * Une conversion vient d'être annulée.
		 *
		 * @param int    $subscription_id Inscription rétablie.
		 * @param string $restored        Statut restauré.
		 * @param string $reason          Motif.
		 */
		do_action( 'ebisn_subscription_reverted', $subscription_id, $previous, $reason );

		return true;
	}

	/**
	 * Change le statut d'une inscription, à condition qu'elle porte bien celui attendu.
	 *
	 * @param int    $subscription_id Inscription visée.
	 * @param string $expected        Statut attendu avant l'écriture.
	 * @param string $new_status      Statut à poser.
	 *
	 * @return bool Vrai si une ligne — et une seule — a été modifiée.
	 */
	private function swap_status( int $subscription_id, string $expected, string $new_status ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- écriture conditionnelle atomique : c'est précisément ce que l'API des posts ne sait pas faire.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts}
				    SET post_status = %s
				  WHERE ID = %d
				    AND post_type = %s
				    AND post_status = %s",
				$new_status,
				$subscription_id,
				Host::SUBSCRIBER_TYPE,
				$expected
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 1 !== (int) $updated ) {
			return false;
		}

		// Invalide aussi les compteurs par statut consommés par le tableau de bord.
		clean_post_cache( $subscription_id );

		return true;
	}

	/**
	 * Enregistre le délai écoulé entre l'alerte et la commande.
	 *
	 * N'a de sens que pour une inscription réellement notifiée : c'est ce délai,
	 * et non le taux brut, qui dit à quelle vitesse une alerte se transforme en
	 * commande.
	 *
	 * @param int    $subscription_id Inscription convertie.
	 * @param string $previous        Statut précédent.
	 * @param int    $converted_at    Horodatage de la conversion.
	 */
	private function record_lag( int $subscription_id, string $previous, int $converted_at ): void {
		if ( Host::STATUS_MAILSENT !== $previous ) {
			return;
		}

		$mailed_at = (int) get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

		if ( $mailed_at <= 0 || $converted_at <= $mailed_at ) {
			return;
		}

		update_post_meta( $subscription_id, MetaKeys::LAG, $converted_at - $mailed_at );
	}

	/**
	 * Remet à jour le compteur d'inscrits en attente de l'extension hôte.
	 *
	 * L'hôte ne compte que les `cwg_subscribed` et ignore évidemment les
	 * transitions provoquées par ce plugin : sans ce recalcul, le nombre
	 * d'inscrits affiché sur la fiche produit dérive à chaque conversion.
	 *
	 * @param int    $subscription_id Inscription concernée.
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
