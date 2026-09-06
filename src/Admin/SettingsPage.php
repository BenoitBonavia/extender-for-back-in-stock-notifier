<?php
/**
 * Écran de réglages de l'extension.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Page de réglages, sous le menu de l'extension hôte.
 *
 * Les réglages étaient à l'origine un onglet de WooCommerce — l'endroit où
 * WooCommerce attend ceux d'une extension. En pratique, ils s'y perdaient : les
 * écrans de ce plugin vivent sous « Instock Notifier », et rien ne conduisait
 * de l'un à l'autre. Ils sont donc désormais là où l'on travaille.
 *
 * Le rendu et l'enregistrement restent confiés à `WC_Admin_Settings` : c'est ce
 * qui donne aux champs l'apparence, les infobulles et le comportement d'un
 * écran de réglages WooCommerce, sans les réécrire.
 */
final class SettingsPage {

	/**
	 * Identifiant de la page.
	 */
	public const SLUG = 'ebisn-settings';

	/**
	 * Définition des sections et des champs.
	 *
	 * @var SettingsFields|null
	 */
	private $fields = null;

	/**
	 * Accroche la page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 25 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy' ) );
	}

	/**
	 * Redirige l'ancienne adresse, du temps où les réglages étaient un onglet
	 * de WooCommerce.
	 *
	 * Sans cela, un favori mènerait aux réglages généraux de WooCommerce, sans
	 * la moindre indication de ce qui s'est passé.
	 */
	public function maybe_redirect_legacy(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';

		if ( 'wc-settings' !== $page || Admin::SETTINGS_ID !== $tab ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		wp_safe_redirect( self::url( $section ) );
		exit;
	}

	/**
	 * Déclare la sous-page.
	 */
	public function add_page(): void {
		$hook = add_submenu_page(
			Host::MENU_PARENT,
			__( 'Réglages Extender', 'extender-for-back-in-stock-notifier' ),
			__( 'Réglages Extender', 'extender-for-back-in-stock-notifier' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			// L'enregistrement doit précéder tout affichage, sans quoi la page
			// se dessinerait avec les valeurs d'avant la soumission.
			add_action( 'load-' . $hook, array( $this, 'maybe_save' ) );
			add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue_assets' ) );
		}
	}

	/**
	 * URL de la page, éventuellement d'une section précise.
	 *
	 * @param string $section Identifiant de section, vide pour la première.
	 *
	 * @return string
	 */
	public static function url( string $section = '' ): string {
		$args = array(
			'post_type' => Host::SUBSCRIBER_TYPE,
			'page'      => self::SLUG,
		);

		if ( '' !== $section ) {
			$args['section'] = $section;
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}

	/**
	 * Charge les styles d'administration.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'ebisn-admin',
			EBISN_URL . 'assets/css/admin.css',
			array(),
			EBISN_VERSION
		);

		// Les champs `wc-enhanced-select` de WooCommerce ont besoin de Select2 ;
		// sans cela, les listes à choix multiples restent des `<select>` bruts.
		if ( function_exists( 'WC' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/**
	 * Enregistre les réglages soumis.
	 */
	public function maybe_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- le jeton est vérifié juste après.
		if ( ! isset( $_POST['ebisn_save_settings'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'extender-for-back-in-stock-notifier' ) );
		}

		check_admin_referer( 'ebisn_save_settings' );

		\WC_Admin_Settings::save_fields( $this->fields()->get_settings_for_section( $this->current_section() ) );

		\WC_Admin_Settings::add_message( __( 'Réglages enregistrés.', 'extender-for-back-in-stock-notifier' ) );
	}

	/**
	 * Affiche la page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'extender-for-back-in-stock-notifier' ) );
		}

		$sections = $this->fields()->get_sections();
		$current  = $this->current_section();

		echo '<div class="wrap woocommerce">';

		echo '<h1>' . esc_html__( 'Réglages Extender', 'extender-for-back-in-stock-notifier' ) . '</h1>';

		\WC_Admin_Settings::show_messages();

		$this->render_tabs( $sections, $current );

		echo '<form method="post" action="">';

		\WC_Admin_Settings::output_fields( $this->fields()->get_settings_for_section( $current ) );

		wp_nonce_field( 'ebisn_save_settings' );

		printf(
			'<p class="submit"><button type="submit" name="ebisn_save_settings" value="1" class="button-primary">%s</button></p>',
			esc_html__( 'Enregistrer les modifications', 'extender-for-back-in-stock-notifier' )
		);

		echo '</form>';

		echo '</div>';
	}

	/**
	 * Affiche la navigation entre sections.
	 *
	 * @param array<string, string> $sections Sections déclarées.
	 * @param string                $current  Section affichée.
	 */
	private function render_tabs( array $sections, string $current ): void {
		if ( count( $sections ) < 2 ) {
			return;
		}

		echo '<nav class="nav-tab-wrapper woo-nav-tab-wrapper">';

		foreach ( $sections as $id => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( self::url( (string) $id ) ),
				$current === (string) $id ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}

		echo '</nav>';
	}

	/**
	 * Section demandée.
	 *
	 * @return string
	 */
	private function current_section(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte.
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		return array_key_exists( $section, $this->fields()->get_sections() ) ? $section : '';
	}

	/**
	 * Définition des sections et des champs.
	 *
	 * @return SettingsFields
	 */
	private function fields(): SettingsFields {
		if ( null === $this->fields ) {
			$this->fields = new SettingsFields();
		}

		return $this->fields;
	}
}
