<?php
/**
 * Historique des alertes et des relances envoyées à une inscription.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Tracking;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Reminder\ReminderService;
use EBISN\Renotify\RenotifyService;

defined( 'ABSPATH' ) || exit;

/**
 * Qui a reçu quoi, et quand — par inscription.
 *
 * L'extension hôte ne garde que la date de la DERNIÈRE alerte (`cwginstock_mail_on`,
 * écrasée à chaque envoi). Or une inscription peut être notifiée plusieurs fois,
 * après chaque rupture, et relancée entre-temps : sans historique, on ne peut ni
 * compter les alertes ni dire combien de relances a coûté celle-ci.
 *
 * Trois métadonnées : le journal des alertes suivies (une date chacune), un
 * compteur d'alertes ANTÉRIEURES au suivi — sans date, reconstituées à partir du
 * module de renotification —, et le journal des relances.
 */
final class DeliveryHistory {

	/**
	 * Journal des alertes : liste d'horodatages UNIX UTC.
	 */
	public const META_ALERT_LOG = '_ebisn_alert_log';

	/**
	 * Alertes antérieures au suivi, sans date. Posée une seule fois, à la première alerte suivie.
	 */
	public const META_ALERTS_LEGACY = '_ebisn_alerts_legacy';

	/**
	 * Journal des relances : liste de `{ t: horodatage, s: rang, c: rattrapage }`.
	 */
	public const META_REMINDER_LOG = '_ebisn_reminder_log';

	/**
	 * Consigne l'envoi d'une alerte.
	 *
	 * À la PREMIÈRE alerte suivie d'une inscription, amorce le compteur des alertes
	 * déjà parties : l'hôte n'a pas encore réécrit `cwginstock_mail_on`, qui porte
	 * donc encore la date de l'alerte précédente, et chaque remise en attente du
	 * module de renotification suit une alerte.
	 *
	 * @param int $subscription_id Inscription.
	 */
	public static function record_alert( int $subscription_id ): void {
		if ( $subscription_id <= 0 ) {
			return;
		}

		$log = self::read_list( $subscription_id, self::META_ALERT_LOG );

		if ( empty( $log ) && ! metadata_exists( 'post', $subscription_id, self::META_ALERTS_LEGACY ) ) {
			$cycles  = (int) get_post_meta( $subscription_id, RenotifyService::META_CYCLES, true );
			$mail_on = (int) get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

			update_post_meta( $subscription_id, self::META_ALERTS_LEGACY, max( $cycles, $mail_on > 0 ? 1 : 0 ) );
		}

		$log[] = time();

		update_post_meta( $subscription_id, self::META_ALERT_LOG, $log );
	}

	/**
	 * Consigne l'envoi d'une relance.
	 *
	 * @param int  $subscription_id Inscription.
	 * @param int  $step            Rang de la relance.
	 * @param bool $catchup         Relance envoyée par le rattrapage.
	 */
	public static function record_reminder( int $subscription_id, int $step, bool $catchup ): void {
		if ( $subscription_id <= 0 ) {
			return;
		}

		$log   = self::read_list( $subscription_id, self::META_REMINDER_LOG );
		$log[] = array(
			't' => time(),
			's' => $step,
			'c' => $catchup ? 1 : 0,
		);

		update_post_meta( $subscription_id, self::META_REMINDER_LOG, $log );
	}

	/**
	 * Alertes envoyées à une inscription.
	 *
	 * Une inscription jamais suivie — alertée avant l'activation du suivi — reçoit
	 * une ESTIMATION, signalée comme telle : la renotification compte ses remises en
	 * attente, ce qui permet de remonter au nombre d'alertes sans en connaître les dates.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return array{dates:int[], total:int, last:int, estimated:bool} `last` : date de la dernière alerte, `0` si inconnue.
	 */
	public static function alerts( int $subscription_id ): array {
		$log   = self::read_list( $subscription_id, self::META_ALERT_LOG );
		$dates = array();

		foreach ( $log as $stamp ) {
			if ( is_numeric( $stamp ) && (int) $stamp > 0 ) {
				$dates[] = (int) $stamp;
			}
		}

		sort( $dates );

		if ( ! empty( $dates ) || metadata_exists( 'post', $subscription_id, self::META_ALERTS_LEGACY ) ) {
			$legacy = max( 0, (int) get_post_meta( $subscription_id, self::META_ALERTS_LEGACY, true ) );

			return array(
				'dates'     => $dates,
				'total'     => $legacy + count( $dates ),
				'last'      => ! empty( $dates ) ? (int) end( $dates ) : self::mail_on( $subscription_id ),
				'estimated' => $legacy > 0,
			);
		}

		$status  = (string) get_post_status( $subscription_id );
		$cycles  = (int) get_post_meta( $subscription_id, RenotifyService::META_CYCLES, true );
		$mail_on = self::mail_on( $subscription_id );
		$alerted = Host::STATUS_MAILSENT === $status
			|| ( $mail_on > 0 && in_array( $status, array( Host::STATUS_CONVERTED, Host::STATUS_UNSUBSCRIBED ), true ) );

		$total = $cycles + ( $alerted ? 1 : 0 );

		if ( $mail_on > 0 ) {
			$total = max( $total, 1 );
		}

		return array(
			'dates'     => array(),
			'total'     => $total,
			'last'      => $mail_on,
			'estimated' => $total > 0,
		);
	}

	/**
	 * Relances envoyées à une inscription.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return array{log:array<int, array{t:int, s:int, c:int}>, total:int, current:int, last:int} `current` : relances postérieures à la dernière alerte.
	 */
	public static function reminders( int $subscription_id ): array {
		$log = array();

		foreach ( self::read_list( $subscription_id, self::META_REMINDER_LOG ) as $entry ) {
			if ( is_array( $entry ) && isset( $entry['t'] ) && (int) $entry['t'] > 0 ) {
				$log[] = array(
					't' => (int) $entry['t'],
					's' => isset( $entry['s'] ) ? (int) $entry['s'] : 1,
					'c' => ! empty( $entry['c'] ) ? 1 : 0,
				);
			}
		}

		usort(
			$log,
			static function ( array $a, array $b ): int {
				return $a['t'] <=> $b['t'];
			}
		);

		$last_alert = self::alerts( $subscription_id )['last'];
		$current    = 0;

		foreach ( $log as $entry ) {
			// Dernière alerte inconnue : on ne sait rien départager, tout est « en cours ».
			if ( $entry['t'] >= $last_alert ) {
				++$current;
			}
		}

		return array(
			'log'     => $log,
			'total'   => count( $log ),
			'current' => $current,
			'last'    => ! empty( $log ) ? (int) end( $log )['t'] : 0,
		);
	}

	/**
	 * Détail lisible, alerte par alerte, pour l'info-bulle d'une cellule.
	 *
	 * Chaque relance est rattachée à la dernière alerte qui la précède. Aucune
	 * donnée personnelle : des dates et des rangs.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return string[]
	 */
	public static function tooltip_lines( int $subscription_id ): array {
		$alerts    = self::alerts( $subscription_id );
		$reminders = self::reminders( $subscription_id );
		$lines     = array();

		$groups = array();

		foreach ( $alerts['dates'] as $index => $date ) {
			$groups[ $index ] = array();
		}

		$orphans = array();

		foreach ( $reminders['log'] as $entry ) {
			$target = null;

			foreach ( $alerts['dates'] as $index => $date ) {
				if ( $date <= $entry['t'] ) {
					$target = $index;
				}
			}

			if ( null === $target ) {
				$orphans[] = $entry;
			} else {
				$groups[ $target ][] = $entry;
			}
		}

		if ( empty( $alerts['dates'] ) ) {
			$lines[] = sprintf(
				/* translators: %d: nombre d'alertes estimé. */
				_n( '%d alerte, estimée : elle précède le suivi des envois.', '%d alertes, estimées : elles précèdent le suivi des envois.', $alerts['total'], 'extender-for-back-in-stock-notifier' ),
				$alerts['total']
			);
		} elseif ( $alerts['total'] > count( $alerts['dates'] ) ) {
			$lines[] = sprintf(
				/* translators: %d: nombre d'alertes antérieures au suivi. */
				_n( '%d alerte antérieure au suivi, sans date.', '%d alertes antérieures au suivi, sans date.', $alerts['total'] - count( $alerts['dates'] ), 'extender-for-back-in-stock-notifier' ),
				$alerts['total'] - count( $alerts['dates'] )
			);
		}

		foreach ( $alerts['dates'] as $index => $date ) {
			$lines[] = self::alert_line( $date, $groups[ $index ] );
		}

		if ( ! empty( $orphans ) ) {
			$lines[] = __( 'Sur une alerte antérieure non datée', 'extender-for-back-in-stock-notifier' ) . ' : ' . self::reminder_list( $orphans );
		}

		// Une seule séquence de relances par inscription : on dit pourquoi l'alerte en cours n'en a pas.
		$first   = (int) get_post_meta( $subscription_id, ReminderService::META_FIRST_CYCLE, true );
		$mail_on = (int) get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

		if ( $first > 0 && $mail_on > 0 && $first !== $mail_on ) {
			$lines[] = __( 'Séquence de relances déjà utilisée sur une alerte précédente : aucune relance sur celle-ci.', 'extender-for-back-in-stock-notifier' );
		}

		return $lines;
	}

	/**
	 * Ligne de l'info-bulle pour une alerte datée.
	 *
	 * @param int                                    $date      Date de l'alerte.
	 * @param array<int, array{t:int, s:int, c:int}> $reminders Relances reçues depuis.
	 *
	 * @return string
	 */
	private static function alert_line( int $date, array $reminders ): string {
		$label = sprintf(
			/* translators: %s: date de l'alerte. */
			__( 'Alerte du %s', 'extender-for-back-in-stock-notifier' ),
			self::date( $date )
		);

		if ( empty( $reminders ) ) {
			return $label . ' : ' . __( 'aucune relance', 'extender-for-back-in-stock-notifier' );
		}

		return $label . ' : ' . self::reminder_list( $reminders );
	}

	/**
	 * Énumère des relances : « relance 1 le 6 nov., relance 2 le 10 nov. ».
	 *
	 * @param array<int, array{t:int, s:int, c:int}> $reminders Relances.
	 *
	 * @return string
	 */
	private static function reminder_list( array $reminders ): string {
		$parts = array();

		foreach ( $reminders as $entry ) {
			$parts[] = sprintf(
				/* translators: 1: rang de la relance, 2: mention du rattrapage, 3: date. */
				__( 'relance %1$d%2$s le %3$s', 'extender-for-back-in-stock-notifier' ),
				$entry['s'],
				$entry['c'] ? ' ' . __( '(rattrapage)', 'extender-for-back-in-stock-notifier' ) : '',
				self::date( $entry['t'] )
			);
		}

		return implode( ', ', $parts );
	}

	/**
	 * Date courte, dans le fuseau du site.
	 *
	 * @param int $timestamp Horodatage UNIX UTC.
	 *
	 * @return string
	 */
	public static function date( int $timestamp ): string {
		return wp_date( 'j M Y', $timestamp );
	}

	/**
	 * Date de la dernière alerte d'après l'hôte, avec son propre repli.
	 *
	 * L'hôte lit `post_modified_gmt` quand `cwginstock_mail_on` manque sur une
	 * inscription « Mail Sent » : on fait de même, pour afficher la même date que
	 * sa colonne « Mail Sent on ».
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return int `0` si inconnue.
	 */
	private static function mail_on( int $subscription_id ): int {
		$stamp = (int) get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

		if ( $stamp > 0 ) {
			return $stamp;
		}

		if ( Host::STATUS_MAILSENT !== (string) get_post_status( $subscription_id ) ) {
			return 0;
		}

		$post = get_post( $subscription_id );

		return $post instanceof \WP_Post ? max( 0, (int) strtotime( $post->post_modified_gmt . ' UTC' ) ) : 0;
	}

	/**
	 * Lit une métadonnée qui doit être une liste.
	 *
	 * @param int    $subscription_id Inscription.
	 * @param string $key             Clé.
	 *
	 * @return array<int, mixed>
	 */
	private static function read_list( int $subscription_id, string $key ): array {
		$value = get_post_meta( $subscription_id, $key, true );

		return is_array( $value ) ? array_values( $value ) : array();
	}
}
