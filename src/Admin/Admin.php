<?php
/**
 * Intégration à l'administration WordPress.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre l'écran de réglages, les ressources et les liens d'action.
 */
final class Admin {

	/**
	 * Identifiant interne du jeu de réglages.
	 *
	 * Sert d'identifiant à `SettingsFields`, dont héritent les filtres
	 * d'extensibilité de WooCommerce (`woocommerce_get_settings_ebisn`).
	 */
	public const SETTINGS_ID = 'ebisn';

	/**
	 * Accroche les hooks d'administration.
	 */
	public function register(): void {
		add_filter( 'plugin_action_links_' . EBISN_BASENAME, array( $this, 'add_action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		( new SettingsPage() )->register();
	}

	/**
	 * Ajoute un lien « Réglages » sur la ligne du plugin.
	 *
	 * @param array<int|string, string> $links Liens existants, indexés par identifiant.
	 *
	 * @return array<int|string, string>
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::get_settings_url() ),
			esc_html__( 'Réglages', 'extender-for-back-in-stock-notifier' )
		);

		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * URL de la page de réglages du plugin.
	 *
	 * @param string $section Section à ouvrir, vide pour la première.
	 *
	 * @return string
	 */
	public static function get_settings_url( string $section = '' ): string {
		return SettingsPage::url( $section );
	}

	/**
	 * Charge le script d'administration commun.
	 *
	 * Les feuilles de styles sont enfilées par les écrans qui en ont besoin :
	 * chacun connaît le sien, et rien ne justifie de les charger ailleurs.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, SettingsPage::SLUG ) ) {
			return;
		}

		// $args en tableau (WordPress 6.3+) plutôt que le booléen $in_footer.
		wp_enqueue_script(
			'ebisn-admin',
			EBISN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			EBISN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		/*
		 * wp_add_inline_script() plutôt que wp_localize_script() : cette dernière
		 * est prévue pour les chaînes traduisibles et force la conversion des
		 * valeurs en chaînes. Ici on veut du JSON typé.
		 */
		wp_add_inline_script(
			'ebisn-admin',
			'window.ebisnAdmin = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'ebisn_admin' ),
				)
			) . ';',
			'before'
		);
	}
}
