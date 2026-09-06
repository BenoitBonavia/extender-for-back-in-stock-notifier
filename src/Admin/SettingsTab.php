<?php
/**
 * Onglet de réglages WooCommerce.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Integration\BackInStockNotifier;
use EBISN\Modules\ModuleInterface;
use EBISN\Plugin;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Réglages → Extender BIS.
 *
 * Les identifiants de champs servent directement de noms d'options : ils
 * doivent donc rester préfixés par `ebisn_` (cf. Settings::PREFIX).
 */
final class SettingsTab extends \WC_Settings_Page {

	/**
	 * Déclare l'onglet auprès de WooCommerce.
	 */
	public function __construct() {
		$this->id    = Admin::SETTINGS_TAB;
		$this->label = __( 'Extender BIS', 'extender-for-back-in-stock-notifier' );

		parent::__construct();
	}

	/**
	 * Sous-sections de l'onglet.
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections(): array {
		return array(
			''        => __( 'Général', 'extender-for-back-in-stock-notifier' ),
			'modules' => __( 'Modules', 'extender-for-back-in-stock-notifier' ),
		);
	}

	/**
	 * Champs de la section par défaut.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'Réglages généraux', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Comportement global de l’extension.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'general_options',
			),
			array(
				'title'    => __( 'Journalisation', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Consigner les opérations de l’extension dans les journaux WooCommerce.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Visible dans WooCommerce → État → Journaux, source « extender-bisn ».', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'enable_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Effacer les données à la désinstallation', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Supprimer les réglages de cette extension si elle est supprimée.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Ne concerne que les réglages de CETTE extension. Les abonnés, arrivages et réglages de Back In Stock Notifier ne sont jamais touchés : ils appartiennent à l’extension hôte, qui reste installée.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'delete_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'general_options',
			),
			array(
				'title' => __( 'Diagnostic', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => $this->diagnostics_html(),
				'id'    => Settings::PREFIX . 'diagnostics',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'diagnostics',
			),
		);
	}

	/**
	 * État de la dépendance au plugin hôte.
	 *
	 * Sur une extension qui n'a encore aucune fonctionnalité, c'est ce bloc qui
	 * permet de constater d'un coup d'œil que la chaîne est branchée : version
	 * réellement lue chez l'hôte, et volumétrie de ses données.
	 *
	 * @return string
	 */
	private function diagnostics_html(): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: nom de l'extension hôte, 2: version installée, 3: version minimale exigée. */
			esc_html__( '%1$s — version installée : %2$s · version minimale exigée : %3$s', 'extender-for-back-in-stock-notifier' ),
			esc_html( BackInStockNotifier::name() ),
			'<strong>' . esc_html( BackInStockNotifier::version() ) . '</strong>',
			'<code>' . esc_html( BackInStockNotifier::MIN_VERSION ) . '</code>'
		);

		if ( BackInStockNotifier::is_untested() ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: dernière version relue de l'extension hôte. */
				esc_html__( 'L’extension hôte a changé de version majeure depuis la dernière relecture de ce plugin (%s). Les hooks et réglages sur lesquels il s’appuie ont pu bouger : vérifiez le comportement avant de vous y fier.', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html( BackInStockNotifier::TESTED_VERSION ) . '</code>'
			) . '</strong>';
		}

		$lines[] = sprintf(
			/* translators: 1: nombre d'abonnés, 2: slug du type de contenu. */
			esc_html__( 'Abonnés enregistrés chez l’hôte : %1$s (type de contenu %2$s)', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( BackInStockNotifier::subscriber_count() ) ) . '</strong>',
			'<code>' . esc_html( BackInStockNotifier::SUBSCRIBER_TYPE ) . '</code>'
		);

		$lines[] = sprintf(
			/* translators: 1: version du plugin, 2: source des journaux WooCommerce. */
			esc_html__( 'Extender for Back In Stock Notifier %1$s — journaux sous la source %2$s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( EBISN_VERSION ) . '</strong>',
			'<code>' . esc_html( \EBISN\Support\Logger::SOURCE ) . '</code>'
		);

		return implode( '<br>', $lines );
	}

	/**
	 * Champs de la section « Modules » : une case à cocher par module déclaré.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_modules_section(): array {
		$settings = array(
			array(
				'title' => __( 'Modules', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Activez individuellement les règles ajoutées à Back In Stock Notifier.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'module_options',
			),
		);

		foreach ( $this->get_declared_modules() as $module ) {
			$settings[] = array(
				'title'   => $module->get_title(),
				'desc'    => __( 'Activer', 'extender-for-back-in-stock-notifier' ),
				'id'      => Settings::PREFIX . 'module_' . $module->get_id() . '_enabled',
				'type'    => 'checkbox',
				'default' => 'yes',
			);
		}

		if ( 1 === count( $settings ) ) {
			$settings[] = array(
				'title' => '',
				'type'  => 'info',
				'text'  => __( 'Aucun module déclaré pour le moment. Ajoutez vos classes dans src/Modules/ puis référencez-les dans Plugin::get_module_classes().', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'module_empty_notice',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => Settings::PREFIX . 'module_options',
		);

		return $settings;
	}

	/**
	 * Instancie tous les modules déclarés, actifs ou non, pour l'affichage.
	 *
	 * @return ModuleInterface[]
	 */
	private function get_declared_modules(): array {
		$modules = array();

		foreach ( Plugin::get_module_classes() as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( $module instanceof ModuleInterface ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}
}
