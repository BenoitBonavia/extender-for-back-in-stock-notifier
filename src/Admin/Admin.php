<?php
/**
 * Intégration à l'administration WordPress.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Enregistre l'onglet de réglages, les ressources et les liens d'action.
 */
final class Admin {

	/**
	 * Identifiant de l'onglet de réglages WooCommerce.
	 */
	public const SETTINGS_TAB = 'ebisn';

	/**
	 * Accroche les hooks d'administration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_settings_pages', array( $this, 'add_settings_page' ) );
		add_filter( 'plugin_action_links_' . EBISN_BASENAME, array( $this, 'add_action_links' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Ajoute l'onglet de réglages à WooCommerce.
	 *
	 * @param array<int, \WC_Settings_Page> $pages Pages de réglages existantes.
	 *
	 * @return array<int, \WC_Settings_Page>
	 */
	public function add_settings_page( array $pages ): array {
		$pages[] = new SettingsTab();

		return $pages;
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
	 * @return string
	 */
	public static function get_settings_url(): string {
		return admin_url( 'admin.php?page=wc-settings&tab=' . self::SETTINGS_TAB );
	}

	/**
	 * Charge CSS et JS uniquement sur les écrans du plugin.
	 *
	 * @param string $hook_suffix Écran courant.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! $this->is_plugin_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			'ebisn-admin',
			EBISN_URL . 'assets/css/admin.css',
			array(),
			EBISN_VERSION
		);

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

	/**
	 * Détermine si l'écran courant appartient au plugin.
	 *
	 * @param string $hook_suffix Écran courant.
	 *
	 * @return bool
	 */
	private function is_plugin_screen( string $hook_suffix ): bool {
		if ( 'woocommerce_page_wc-settings' !== $hook_suffix ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		return self::SETTINGS_TAB === $tab;
	}
}
