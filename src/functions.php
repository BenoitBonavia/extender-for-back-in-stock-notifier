<?php
/**
 * Helpers globaux du plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'ebisn' ) ) {
	/**
	 * Accesseur global à l'instance du plugin.
	 *
	 * @return \EBISN\Plugin
	 */
	function ebisn(): \EBISN\Plugin {
		return \EBISN\Plugin::instance();
	}
}

if ( ! function_exists( 'ebisn_log' ) ) {
	/**
	 * Raccourci de journalisation.
	 *
	 * @param string               $message Message.
	 * @param string               $level   Niveau PSR-3.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	function ebisn_log( string $message, string $level = 'info', array $context = array() ): void {
		\EBISN\Support\Logger::log( $message, $level, $context );
	}
}
