<?php
/**
 * Activation / désactivation du plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN;

use EBISN\Integration\BackInStockNotifier;

defined( 'ABSPATH' ) || exit;

/**
 * Gère les routines d'installation et de nettoyage à chaud.
 */
final class Installer {

	/**
	 * Option stockant la version installée (utile pour les futures migrations).
	 */
	public const VERSION_OPTION = 'ebisn_version';

	/**
	 * Routine d'activation.
	 *
	 * Redondante avec l'en-tête `Requires Plugins` dans le cas courant, mais
	 * celui-ci résout les dépendances par le NOM DU DOSSIER : une extension hôte
	 * installée sous un autre nom passerait entre les mailles.
	 */
	public static function activate(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::abort(
				__(
					'Extender for Back In Stock Notifier nécessite WooCommerce. Activez WooCommerce puis réessayez.',
					'extender-for-back-in-stock-notifier'
				)
			);
		}

		if ( ! BackInStockNotifier::is_active() ) {
			self::abort(
				sprintf(
					/* translators: %s: nom de l'extension requise. */
					__(
						'Extender for Back In Stock Notifier nécessite l’extension %s. Activez-la puis réessayez.',
						'extender-for-back-in-stock-notifier'
					),
					BackInStockNotifier::name()
				)
			);
		}

		self::maybe_upgrade();

		do_action( 'ebisn_activated' );
	}

	/**
	 * Interrompt l'activation en désactivant le plugin.
	 *
	 * @param string $message Explication affichée au marchand.
	 */
	private static function abort( string $message ): void {
		deactivate_plugins( EBISN_BASENAME );

		wp_die(
			esc_html( $message ),
			esc_html__( 'Prérequis manquant', 'extender-for-back-in-stock-notifier' ),
			array( 'back_link' => true )
		);
	}

	/**
	 * Routine de désactivation.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'ebisn_daily_maintenance' );

		do_action( 'ebisn_deactivated' );
	}

	/**
	 * Exécute les migrations entre versions puis met à jour le marqueur.
	 *
	 * Volontairement publique et idempotente : WordPress n'exécute pas le hook
	 * d'activation lors d'une mise à jour de plugin, elle doit donc pouvoir être
	 * appelée depuis une requête ordinaire (cf. Plugin::boot).
	 */
	public static function maybe_upgrade(): void {
		$installed = (string) get_option( self::VERSION_OPTION, '' );

		if ( EBISN_VERSION === $installed ) {
			return;
		}

		/**
		 * Point d'accroche pour les migrations de données.
		 *
		 * @param string $installed Version précédemment installée ('' si première install).
		 */
		do_action( 'ebisn_upgrade', $installed );

		update_option( self::VERSION_OPTION, EBISN_VERSION, false );
	}
}
