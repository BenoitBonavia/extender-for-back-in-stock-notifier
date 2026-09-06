<?php
/**
 * Plugin Name:          Extender for Back In Stock Notifier
 * Plugin URI:           https://github.com/benoitbonavia/extender-for-back-in-stock-notifier
 * Description:          Étend « Back In Stock Notifier for WooCommerce » : règles et automatismes supplémentaires regroupés dans une extension unique plutôt que dans des snippets épars.
 * Version:              0.4.2
 * Requires at least:    6.8
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce, back-in-stock-notifier-for-woocommerce
 * Author:               Benoit Bonavia
 * Author URI:           https://github.com/benoitbonavia
 * License:              GPL-3.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:          extender-for-back-in-stock-notifier
 * Domain Path:          /languages
 * Update URI:           false
 * WC requires at least: 9.9
 * WC tested up to:      11.0
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN;

defined( 'ABSPATH' ) || exit;

define( 'EBISN_VERSION', '0.4.2' );
define( 'EBISN_FILE', __FILE__ );
define( 'EBISN_PATH', plugin_dir_path( __FILE__ ) );
define( 'EBISN_URL', plugin_dir_url( __FILE__ ) );
define( 'EBISN_BASENAME', plugin_basename( __FILE__ ) );

/** Version minimale de WooCommerce requise. */
define( 'EBISN_MIN_WC_VERSION', '9.9' );

require_once EBISN_PATH . 'src/Autoloader.php';
Autoloader::register();

require_once EBISN_PATH . 'src/functions.php';

register_activation_hook( __FILE__, array( Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Installer::class, 'deactivate' ) );

/**
 * Déclare la compatibilité avec les fonctionnalités récentes de WooCommerce.
 *
 * Doit être appelé sur `before_woocommerce_init`. WooCommerce n'affiche ces
 * informations que pour les extensions déclarant « WC tested up to » dans leur
 * en-tête : garder cette valeur à jour fait partie du contrat.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', EBISN_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', EBISN_FILE, true );
	}
);

/*
 * Point d'entrée : instancie le plugin une fois toutes les extensions chargées.
 *
 * La priorité 20 laisse passer les extensions branchées par défaut sur
 * `plugins_loaded`. Le plugin hôte, lui, s'instancie dès l'inclusion de son
 * fichier — donc bien avant ce hook, quel que soit l'ordre alphabétique des
 * dossiers : ses constantes sont déjà posées quand Requirements les interroge.
 *
 * Enveloppé dans une fonction anonyme plutôt que branché directement sur
 * `Plugin::instance` : une action ne doit rien renvoyer, et `instance()` renvoie
 * le conteneur.
 */
add_action(
	'plugins_loaded',
	static function () {
		Plugin::instance();
	},
	20
);
