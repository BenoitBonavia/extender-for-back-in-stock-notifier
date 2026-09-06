<?php
/**
 * Vérification des prérequis d'exécution.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN;

use EBISN\Integration\BackInStockNotifier;

defined( 'ABSPATH' ) || exit;

/**
 * Vérifie que WooCommerce et l'extension hôte sont présents et assez récents.
 *
 * L'en-tête `Requires Plugins` du fichier principal couvre déjà le cas courant :
 * depuis WordPress 6.5, l'activation est refusée tant que les deux extensions ne
 * sont pas là. Ce contrôle reste le filet pour les trois cas qu'il ne couvre pas :
 * une extension hôte installée dans un dossier renommé (le slug ne résout plus),
 * une version trop ancienne (l'en-tête ignore les numéros de version), et une
 * extension présente mais non démarrée.
 */
final class Requirements {

	/**
	 * Code de la dernière raison d'échec.
	 *
	 * Les chaînes ne sont traduites qu'à l'affichage : les prérequis sont
	 * évalués sur `plugins_loaded`, soit avant que les traductions ne soient
	 * disponibles (WordPress 6.7 signale les chargements trop précoces).
	 *
	 * @var string
	 */
	private static $failure_code = '';

	/**
	 * Indique si l'environnement permet de charger le plugin.
	 *
	 * L'ordre des contrôles compte : WooCommerce d'abord. L'extension hôte ne
	 * définit ses constantes que si WooCommerce est actif — sans cette garde,
	 * une boutique sans WooCommerce se verrait reprocher l'absence de
	 * l'extension hôte, qui est pourtant bien là.
	 *
	 * @return bool
	 */
	public static function are_met(): bool {
		if ( ! class_exists( 'WooCommerce' ) ) {
			self::$failure_code = 'missing_wc';

			return false;
		}

		if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, EBISN_MIN_WC_VERSION, '<' ) ) {
			self::$failure_code = 'outdated_wc';

			return false;
		}

		if ( ! BackInStockNotifier::is_active() ) {
			self::$failure_code = 'missing_bisn';

			return false;
		}

		if ( ! BackInStockNotifier::is_supported() ) {
			self::$failure_code = 'outdated_bisn';

			return false;
		}

		self::$failure_code = '';

		return true;
	}

	/**
	 * Affiche l'avertissement d'administration en cas de prérequis manquant.
	 */
	public static function render_notice(): void {
		if ( '' === self::$failure_code || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = self::failure_message();

		if ( '' === $message ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> — %s</p></div>',
			esc_html__( 'Extender for Back In Stock Notifier', 'extender-for-back-in-stock-notifier' ),
			wp_kses( $message, array( 'a' => array( 'href' => array() ) ) )
		);
	}

	/**
	 * Compose le message correspondant à la raison d'échec mémorisée.
	 *
	 * @return string HTML restreint aux liens.
	 */
	private static function failure_message(): string {
		switch ( self::$failure_code ) {
			case 'outdated_wc':
				return esc_html(
					sprintf(
						/* translators: %s: numéro de version minimale de WooCommerce. */
						__( 'WooCommerce %s ou supérieur est requis.', 'extender-for-back-in-stock-notifier' ),
						EBISN_MIN_WC_VERSION
					)
				);

			case 'missing_wc':
				return esc_html__( 'WooCommerce doit être installé et activé.', 'extender-for-back-in-stock-notifier' );

			case 'missing_bisn':
				/*
				 * « Installer » et « activer » n'appellent pas le même écran, et
				 * l'extension est le plus souvent déjà installée : ce plugin ne
				 * s'active pas sans elle. Envoyer systématiquement vers l'écran
				 * d'ajout ferait chercher une extension déjà présente.
				 */
				return sprintf(
					/* translators: 1: nom de l'extension requise, 2: URL de l'écran adéquat, 3: libellé du lien. */
					esc_html__( 'L’extension %1$s est requise. %2$s', 'extender-for-back-in-stock-notifier' ),
					'<strong>' . esc_html( BackInStockNotifier::name() ) . '</strong>',
					BackInStockNotifier::is_installed()
						? '<a href="' . esc_url( BackInStockNotifier::plugins_url() ) . '">'
							. esc_html__( 'L’activer', 'extender-for-back-in-stock-notifier' ) . '</a>'
						: '<a href="' . esc_url( BackInStockNotifier::install_url() ) . '">'
							. esc_html__( 'L’installer', 'extender-for-back-in-stock-notifier' ) . '</a>'
				);

			case 'outdated_bisn':
				return esc_html(
					sprintf(
						/* translators: 1: nom de l'extension requise, 2: version minimale, 3: version installée. */
						__( '%1$s %2$s ou supérieur est requis ; version installée : %3$s.', 'extender-for-back-in-stock-notifier' ),
						BackInStockNotifier::name(),
						BackInStockNotifier::MIN_VERSION,
						BackInStockNotifier::version()
					)
				);

			default:
				return '';
		}
	}
}
