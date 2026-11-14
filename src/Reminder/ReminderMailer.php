<?php
/**
 * Enregistrement de l'e-mail de relance auprès de WooCommerce.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Point d'accès à l'e-mail de relance.
 *
 * Cette classe existe pour ne jamais nommer `ReminderEmail` avant que
 * WooCommerce ait chargé `WC_Email` : l'autoloader de ce plugin chargerait le
 * fichier, et sa déclaration `extends \WC_Email` serait une erreur fatale.
 * Seul le filtre `woocommerce_email_classes` — appelé une fois `WC_Email`
 * disponible — instancie la classe.
 */
final class ReminderMailer {

	/**
	 * Clé de l'e-mail dans la liste de WooCommerce.
	 */
	public const KEY = 'EBISN_Reminder_Email';

	/**
	 * Identifiant de l'e-mail, qui nomme aussi son option de réglages.
	 */
	public const EMAIL_ID = 'ebisn_bis_reminder';

	/**
	 * Section de l'écran E-mails de WooCommerce.
	 *
	 * WooCommerce compare la section demandée à la clé de l'e-mail dans son
	 * registre, en minuscules — pas à son identifiant.
	 */
	public const SETTINGS_SECTION = 'ebisn_reminder_email';

	/**
	 * Accroche l'enregistrement de l'e-mail.
	 */
	public function register(): void {
		add_filter( 'woocommerce_email_classes', array( $this, 'add_email_class' ) );
		add_filter( 'woocommerce_email_from_name', array( $this, 'filter_from_name' ), 10, 2 );
		add_filter( 'woocommerce_email_from_address', array( $this, 'filter_from_address' ), 10, 2 );
		add_filter( 'woocommerce_email_headers', array( $this, 'filter_headers' ), 10, 2 );
	}

	/**
	 * Ajoute la classe à la liste des e-mails de WooCommerce.
	 *
	 * @param array<string, object> $emails E-mails enregistrés.
	 *
	 * @return array<string, object>
	 */
	public function add_email_class( $emails ): array {
		$emails = (array) $emails;

		if ( class_exists( '\WC_Email' ) ) {
			$emails[ self::KEY ] = new ReminderEmail();
		}

		return $emails;
	}

	/**
	 * L'e-mail de relance, tel que WooCommerce l'a instancié.
	 *
	 * @return ReminderEmail|null `null` si WooCommerce n'est pas prêt.
	 */
	public static function email(): ?ReminderEmail {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
			return null;
		}

		$emails = WC()->mailer()->get_emails();
		$email  = $emails[ self::KEY ] ?? null;

		return $email instanceof ReminderEmail ? $email : null;
	}

	/**
	 * Reprend le nom d'expéditeur configuré dans l'hôte.
	 *
	 * @param string $name  Nom de WooCommerce.
	 * @param object $email E-mail en cours d'envoi.
	 *
	 * @return string
	 */
	public function filter_from_name( $name, $email ): string {
		$name = (string) $name;

		if ( ! $this->is_ours( $email ) ) {
			return $name;
		}

		$configured = Host::mail_setting( Host::SETTING_MAIL_FROM_NAME );

		return '' !== $configured ? $configured : $name;
	}

	/**
	 * Reprend l'adresse d'expédition configurée dans l'hôte.
	 *
	 * @param string $address Adresse de WooCommerce.
	 * @param object $email   E-mail en cours d'envoi.
	 *
	 * @return string
	 */
	public function filter_from_address( $address, $email ): string {
		$address = (string) $address;

		if ( ! $this->is_ours( $email ) ) {
			return $address;
		}

		$configured = sanitize_email( Host::mail_setting( Host::SETTING_MAIL_FROM_EMAIL ) );

		return is_email( $configured ) ? $configured : $address;
	}

	/**
	 * Reprend l'adresse de réponse configurée dans l'hôte.
	 *
	 * @param string $headers  En-têtes.
	 * @param string $email_id Identifiant de l'e-mail.
	 *
	 * @return string
	 */
	public function filter_headers( $headers, $email_id ): string {
		$headers = (string) $headers;

		if ( self::EMAIL_ID !== (string) $email_id ) {
			return $headers;
		}

		$reply_to = sanitize_email( Host::mail_setting( Host::SETTING_MAIL_REPLY_TO ) );

		if ( ! is_email( $reply_to ) ) {
			return $headers;
		}

		$headers = (string) preg_replace( '/^Reply-To:.*\r?\n?/mi', '', $headers );

		return $headers . 'Reply-To: ' . $reply_to . "\r\n";
	}

	/**
	 * L'e-mail en cours d'envoi est-il la relance ?
	 *
	 * @param mixed $email Objet reçu par le filtre.
	 *
	 * @return bool
	 */
	private function is_ours( $email ): bool {
		return is_object( $email ) && isset( $email->id ) && self::EMAIL_ID === (string) $email->id;
	}
}
