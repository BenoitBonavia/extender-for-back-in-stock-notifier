<?php
/**
 * Consentement marketing à l'inscription.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Ajoute une case de consentement au formulaire de réassort, et en conserve la
 * preuve.
 *
 * Demander à être prévenu d'un retour en stock n'est pas consentir à recevoir
 * des campagnes : la finalité n'est pas la même. Brevo l'exige explicitement —
 * consentement actif, case non pré-cochée, preuve conservable — et sanctionne
 * le manquement par la suspension du compte.
 *
 * La case « I Agree » de l'extension hôte ne peut pas y suppléer : elle porte
 * sur les conditions générales, et surtout l'hôte ne l'enregistre NULLE PART.
 * Il n'en reste donc aucune trace exploitable comme preuve.
 */
final class Consent {

	/**
	 * Nom du champ de formulaire.
	 */
	private const FIELD = 'ebisn_marketing_consent';

	/**
	 * Métadonnée portant la preuve.
	 */
	public const META = '_ebisn_marketing_consent';

	/**
	 * Accroche l'affichage et l'enregistrement.
	 */
	public function register(): void {
		add_action( Host::HOOK_AFTER_EMAIL_FIELD, array( $this, 'render_field' ) );
		add_action( Host::HOOK_AFTER_INSERT_SUBSCRIBER, array( $this, 'store_consent' ), 5, 1 );
	}

	/**
	 * Affiche la case, systématiquement décochée.
	 */
	public function render_field(): void {
		printf(
			'<p class="ebisn-consent"><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
			esc_attr( self::FIELD ),
			esc_html( self::label() )
		);
	}

	/**
	 * Libellé de la case.
	 *
	 * Sa version est conservée avec la preuve : savoir qu'une personne a coché
	 * une case ne vaut que si l'on sait ce qu'elle disait.
	 *
	 * @return string
	 */
	public static function label(): string {
		$default = __( 'Je souhaite aussi recevoir les actualités et les offres par e-mail.', 'extender-for-back-in-stock-notifier' );
		$label   = (string) Settings::get( 'brevo_consent_label', '' );

		return '' !== trim( $label ) ? $label : $default;
	}

	/**
	 * Enregistre la preuve de consentement sur l'inscription.
	 *
	 * @param int $subscription_id Inscription créée.
	 */
	public function store_consent( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;

		if ( $subscription_id <= 0 ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- l'extension hôte a déjà validé son propre jeton avant d'émettre ce hook.
		if ( empty( $_POST[ self::FIELD ] ) ) {
			return;
		}

		update_post_meta(
			$subscription_id,
			self::META,
			array(
				'given_at' => gmdate( 'Y-m-d H:i:s' ),
				'label'    => self::label(),
				'source'   => esc_url_raw( (string) wp_get_referer() ),
				'version'  => EBISN_VERSION,
			)
		);
	}

	/**
	 * Une adresse a-t-elle consenti au marketing ?
	 *
	 * Le consentement est porté par l'inscription, mais s'apprécie par adresse :
	 * une personne ayant consenti une fois n'a pas à le refaire à chaque produit.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return bool
	 */
	public static function has_consent( string $email ): bool {
		global $wpdb;

		if ( '' === $email ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture ponctuelle, en contexte de tâche de fond.
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pm
						  ON pm.post_id = p.ID
						 AND pm.meta_key = %s
				  WHERE p.post_type = %s
					AND p.post_title = %s",
				self::META,
				Host::SUBSCRIBER_TYPE,
				$email
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $found > 0;
	}
}
