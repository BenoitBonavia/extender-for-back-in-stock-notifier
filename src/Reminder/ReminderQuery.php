<?php
/**
 * Lectures d'inscriptions pour les relances.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Retrouve les inscriptions notifiées susceptibles d'être relancées.
 *
 * La requête ne tranche pas : elle écarte seulement ce qui ne peut PAS être dû —
 * mauvais statut, alerte trop ancienne ou trop récente, relances épuisées — et
 * laisse `ReminderService` décider, avec les contrôles qui exigent d'instancier
 * un produit ou de chercher des commandes.
 */
final class ReminderQuery {

	/**
	 * Lot d'inscriptions notifiées, pour le parcours d'un passage.
	 *
	 * Le compteur de relances n'est tenu pour valide que s'il porte sur le cycle
	 * d'alerte courant : une inscription remise en attente puis notifiée de
	 * nouveau a un autre `cwginstock_mail_on`, donc repart de zéro.
	 *
	 * @param int  $cursor    Dernière inscription examinée.
	 * @param int  $limit     Taille du lot.
	 * @param int  $since     Alertes envoyées avant cette date (UNIX) : ignorées.
	 * @param int  $latest    Alertes envoyées après cette date (UNIX) : trop récentes pour la première relance.
	 * @param int  $max_steps Nombre de relances configurées.
	 * @param bool $never_reminded Ne garder que les inscriptions jamais relancées, sur aucune alerte (rattrapage).
	 *
	 * @return array<int, array{id:int, mail_on:int, sent:int}> `sent` : relances déjà traitées sur ce cycle.
	 *
	 * @throws \RuntimeException Si la lecture échoue : une liste vide serait prise pour la fin du parcours.
	 */
	public function candidates( int $cursor, int $limit, int $since, int $latest, int $max_steps, bool $never_reminded = false ): array {
		global $wpdb;

		list( $sql, $values ) = $this->inner_query( $cursor, $since, $latest, $max_steps, $never_reminded );

		$sql     .= ' ORDER BY p.ID ASC LIMIT %d';
		$values[] = max( 1, $limit );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		// Une lecture en échec rendrait une liste vide, que l'appelant lirait comme « fini ».
		if ( '' !== Database::last_error() ) {
			throw new \RuntimeException( esc_html( Database::last_error() ) );
		}

		$batch = array();

		foreach ( (array) $rows as $row ) {
			$mail_on = (int) $row->mail_on;
			$sent    = (int) $row->cycle === $mail_on ? (int) $row->sent : 0;

			$batch[] = array(
				'id'      => (int) $row->id,
				'mail_on' => $mail_on,
				'sent'    => $sent,
			);
		}

		return $batch;
	}

	/**
	 * Nombre d'inscriptions que le rattrapage pourrait relancer.
	 *
	 * C'est un MAJORANT : le stock du produit et les commandes de la personne ne
	 * se contrôlent qu'à l'envoi, car ils exigent d'instancier un produit et de
	 * chercher des commandes pour chaque inscription.
	 *
	 * @param int $since  Alertes envoyées avant cette date (UNIX) : ignorées.
	 * @param int $latest Alertes envoyées après cette date (UNIX) : trop récentes.
	 *
	 * @return int `0` en cas d'erreur de lecture : l'écran de réglages ne doit pas tomber.
	 */
	public function count_catchup( int $since, int $latest ): int {
		global $wpdb;

		// Rang final à 1 : ne ressortent que les inscriptions jamais relancées sur ce cycle.
		list( $inner, $values ) = $this->inner_query( 0, $since, $latest, 1, true );

		$sql = 'SELECT COUNT(*) FROM ( ' . $inner . ' ) due';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; décompte ponctuel pour l'écran de réglages.
		$count = (int) $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		$count = '' === Database::last_error() ? $count : 0;
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return $count;
	}

	/**
	 * Une inscription notifiée récente, pour composer un e-mail de test réaliste.
	 *
	 * @return int `0` si aucune inscription n'est à « Alerte envoyée ».
	 */
	public function any_notified(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture ponctuelle pour un e-mail de test.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX( ID ) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				Host::SUBSCRIBER_TYPE,
				Host::STATUS_MAILSENT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return $id;
	}

	/**
	 * Sélection commune aux lectures : inscriptions notifiées et leur compteur de relances.
	 *
	 * @param int  $cursor    Dernière inscription examinée.
	 * @param int  $since     Alertes envoyées avant cette date (UNIX) : ignorées.
	 * @param int  $latest    Alertes envoyées après cette date (UNIX) : trop récentes.
	 * @param int  $max_steps Rang final : les inscriptions l'ayant atteint sont écartées.
	 * @param bool $never_reminded Écarter aussi celles relancées sur une alerte précédente, pas seulement sur celle-ci.
	 *
	 * @return array{0:string, 1:array<int, int|string>} Requête sans tri ni limite, et ses valeurs.
	 */
	private function inner_query( int $cursor, int $since, int $latest, int $max_steps, bool $never_reminded ): array {
		global $wpdb;

		$sql = "SELECT p.ID AS id,
						MAX( CAST( mo.meta_value AS UNSIGNED ) ) AS mail_on,
						MAX( CAST( rs.meta_value AS UNSIGNED ) ) AS sent,
						MAX( CAST( rc.meta_value AS UNSIGNED ) ) AS cycle,
						MAX( CAST( rf.meta_value AS UNSIGNED ) ) AS reminded
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} mo
						 ON mo.post_id = p.ID
						AND mo.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} rs
						 ON rs.post_id = p.ID
						AND rs.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} rc
						 ON rc.post_id = p.ID
						AND rc.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} rf
						 ON rf.post_id = p.ID
						AND rf.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status = %s
				   AND p.ID > %d
				   AND CAST( mo.meta_value AS UNSIGNED ) >= %d
				   AND CAST( mo.meta_value AS UNSIGNED ) <= %d
				 GROUP BY p.ID
				HAVING ( cycle IS NULL OR cycle <> mail_on OR sent < %d )
				   AND ( reminded IS NULL" . ( $never_reminded ? '' : ' OR reminded = mail_on' ) . ' )';

		$values = array(
			Host::META_MAIL_ON,
			ReminderService::META_SENT,
			ReminderService::META_CYCLE,
			ReminderService::META_FIRST_CYCLE,
			Host::SUBSCRIBER_TYPE,
			Host::STATUS_MAILSENT,
			$cursor,
			max( 1, $since ),
			$latest,
			max( 1, $max_steps ),
		);

		return array( $sql, $values );
	}

	/**
	 * Décompte des relances envoyées et sautées depuis une date.
	 *
	 * Sert à l'encart d'état des réglages : un marchand qui active ce module doit
	 * pouvoir constater qu'il fait quelque chose.
	 *
	 * @param int $since Horodatage UNIX UTC.
	 *
	 * @return array{sent:int, skipped:int}
	 */
	public function counts_since( int $since ): array {
		global $wpdb;

		$sent_like    = '\\_ebisn\\_reminder\\_%\\_at';
		$skipped_like = '\\_ebisn\\_reminder\\_%\\_skipped\\_at';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- décompte ponctuel pour l'écran de réglages.
		$sent = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( * ) FROM {$wpdb->postmeta}
				  WHERE meta_key LIKE %s
				    AND meta_key NOT LIKE %s
				    AND meta_key <> %s
				    AND CAST( meta_value AS UNSIGNED ) >= %d",
				$sent_like,
				$skipped_like,
				// L'horodatage du rattrapage double celui de la relance : ne le compter qu'une fois.
				ReminderService::META_CATCHUP,
				$since
			)
		);

		$skipped = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT( * ) FROM {$wpdb->postmeta}
				  WHERE meta_key LIKE %s
				    AND CAST( meta_value AS UNSIGNED ) >= %d",
				$skipped_like,
				$since
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'sent'    => $sent,
			'skipped' => $skipped,
		);
	}
}
