<?php
/**
 * Journalisation via le logger WooCommerce.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Écrit dans WooCommerce → État → Journaux, source « extender-bisn ».
 */
final class Logger {

	/**
	 * Source des entrées de journal.
	 */
	public const SOURCE = 'extender-bisn';

	/**
	 * Instance du logger WooCommerce.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Écrit une entrée.
	 *
	 * @param string               $message Message.
	 * @param string               $level   Niveau PSR-3 (debug, info, notice, warning, error…).
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function log( string $message, string $level = 'info', array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		if ( null === self::$logger ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->log(
			$level,
			$message,
			array_merge( array( 'source' => self::SOURCE ), $context )
		);
	}

	/**
	 * Entrée de niveau debug, uniquement si WP_DEBUG est actif.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		self::log( $message, 'debug', $context );
	}

	/**
	 * Entrée de niveau info.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function info( string $message, array $context = array() ): void {
		self::log( $message, 'info', $context );
	}

	/**
	 * Entrée de niveau warning.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::log( $message, 'warning', $context );
	}

	/**
	 * Entrée de niveau error.
	 *
	 * @param string               $message Message.
	 * @param array<string, mixed> $context Contexte additionnel.
	 */
	public static function error( string $message, array $context = array() ): void {
		self::log( $message, 'error', $context );
	}
}
