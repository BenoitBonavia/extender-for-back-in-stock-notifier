<?php
/**
 * Lien de désabonnement inséré dans les e-mails.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Fournit et traite le lien de désabonnement des e-mails.
 *
 * C'est le seul chemin fiable pour une personne inscrite en tant qu'invité :
 * elle n'a pas de compte, et son cookie peut avoir disparu. Le lien fonctionne
 * depuis n'importe quel appareil et sans session.
 *
 * La signature est dérivée des sels de WordPress par `wp_hash()`. Rien n'est
 * stocké : le jeton est recalculable à volonté, il ne peut pas être forgé sans
 * connaître les sels du site, et il n'y a aucune table à purger.
 */
final class EmailLink {

	/**
	 * Paramètre d'URL portant l'identifiant d'inscription.
	 */
	public const ARG_ID = 'ebisn_unsub';

	/**
	 * Paramètre d'URL portant la signature.
	 */
	public const ARG_TOKEN = 'ebisn_key';

	/**
	 * Jeton hérité, reconnu par les gabarits écrits pour l'add-on payant.
	 */
	private const LEGACY_TOKEN = '{cwginstock_unsubscribe}';

	/**
	 * Service de désabonnement.
	 *
	 * @var UnsubscribeService
	 */
	private $service;

	/**
	 * Constructeur.
	 *
	 * @param UnsubscribeService $service Service de désabonnement.
	 */
	public function __construct( UnsubscribeService $service ) {
		$this->service = $service;
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_handle' ) );

		if ( ! Settings::get_bool( 'unsubscribe_email_link', true ) ) {
			return;
		}

		/*
		 * Jeton hérité : l'hôte ne consulte ce filtre que si quelqu'un y est
		 * branché. S'y brancher active donc la résolution de
		 * `{cwginstock_unsubscribe}`, et rend compatibles les gabarits déjà
		 * écrits pour l'add-on payant.
		 */
		add_filter( 'cwginstock_replace_shortcode', array( $this, 'resolve_legacy_token' ), 10, 2 );

		// Jeton moderne, exposé aux gabarits WC_Email de l'hôte.
		add_filter( Host::HOOK_EMAIL_PLACEHOLDERS, array( $this, 'add_placeholder' ), 10, 2 );
	}

	/**
	 * URL de désabonnement d'une inscription.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return string
	 */
	public static function url( int $subscription_id ): string {
		$product_id = (int) get_post_meta( $subscription_id, Host::META_PRODUCT_ID, true );
		$base       = $product_id > 0 ? get_permalink( $product_id ) : home_url( '/' );

		if ( ! is_string( $base ) || '' === $base ) {
			$base = home_url( '/' );
		}

		return add_query_arg(
			array(
				self::ARG_ID    => $subscription_id,
				self::ARG_TOKEN => self::token( $subscription_id ),
			),
			$base
		);
	}

	/**
	 * Signature d'une inscription.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return string
	 */
	public static function token( int $subscription_id ): string {
		return wp_hash( 'ebisn_unsubscribe_' . $subscription_id );
	}

	/**
	 * Résout le jeton hérité des gabarits.
	 *
	 * @param string $content       Jeton ou contenu transmis par l'hôte.
	 * @param int    $subscriber_id Inscription concernée.
	 *
	 * @return string
	 */
	public function resolve_legacy_token( $content, $subscriber_id ): string {
		$content = (string) $content;

		if ( self::LEGACY_TOKEN !== $content ) {
			return $content;
		}

		return self::url( (int) $subscriber_id );
	}

	/**
	 * Ajoute le lien aux placeholders des e-mails modernes.
	 *
	 * @param array<string, string> $placeholders  Placeholders existants.
	 * @param int                   $subscriber_id Inscription concernée.
	 *
	 * @return array<string, string>
	 */
	public function add_placeholder( $placeholders, $subscriber_id ): array {
		$placeholders                       = (array) $placeholders;
		$placeholders['{unsubscribe_url}']  = self::url( (int) $subscriber_id );
		$placeholders[ self::LEGACY_TOKEN ] = self::url( (int) $subscriber_id );

		return $placeholders;
	}

	/**
	 * Traite un clic sur le lien de désabonnement.
	 */
	public function maybe_handle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- la signature du lien tient lieu de jeton ; un nonce ne survivrait pas à un e-mail.
		if ( ! isset( $_GET[ self::ARG_ID ], $_GET[ self::ARG_TOKEN ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$subscription_id = absint( wp_unslash( $_GET[ self::ARG_ID ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$token = sanitize_text_field( wp_unslash( $_GET[ self::ARG_TOKEN ] ) );

		$valid = $subscription_id > 0
			&& Host::SUBSCRIBER_TYPE === get_post_type( $subscription_id )
			// Comparaison à temps constant : une comparaison ordinaire laisse
			// deviner la signature caractère par caractère.
			&& hash_equals( self::token( $subscription_id ), $token );

		if ( ! $valid ) {
			$this->notice(
				__( 'Ce lien de désabonnement n’est plus valide.', 'extender-for-back-in-stock-notifier' ),
				'error'
			);

			$this->redirect();
		}

		$status = (string) get_post_status( $subscription_id );

		if ( Host::STATUS_UNSUBSCRIBED === $status ) {
			$this->notice(
				__( 'Vous étiez déjà désabonné·e de cette alerte.', 'extender-for-back-in-stock-notifier' ),
				'notice'
			);

			$this->redirect();
		}

		if ( $this->service->unsubscribe( $subscription_id, 'email' ) ) {
			$this->notice(
				__( 'C’est fait : vous ne recevrez plus d’alerte pour ce produit.', 'extender-for-back-in-stock-notifier' ),
				'success'
			);
		} else {
			$this->notice(
				__( 'Le désabonnement n’a pas pu être enregistré. Réessayez plus tard.', 'extender-for-back-in-stock-notifier' ),
				'error'
			);
		}

		$this->redirect();
	}

	/**
	 * Ajoute un message pour la page suivante.
	 *
	 * @param string $message Message.
	 * @param string $type    Type WooCommerce : `success`, `notice` ou `error`.
	 */
	private function notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	/**
	 * Redirige vers la page courante, débarrassée des paramètres du lien.
	 *
	 * Sans cette redirection, un rechargement rejouerait la demande et le
	 * message resterait affiché indéfiniment.
	 */
	private function redirect(): void {
		wp_safe_redirect( remove_query_arg( array( self::ARG_ID, self::ARG_TOKEN ) ) );
		exit;
	}
}
