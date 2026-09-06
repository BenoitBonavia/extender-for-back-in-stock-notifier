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
	 * Niveaux consignés même journalisation désactivée.
	 *
	 * Un incident ne doit pas dépendre d'un réglage que personne n'a pensé à
	 * cocher AVANT qu'il ne survienne : quand ces niveaux se déclenchent, il est
	 * déjà trop tard pour aller activer la journalisation.
	 */
	private const ALWAYS_LOGGED = array( 'error', 'critical', 'alert', 'emergency' );

	/**
	 * Instance du logger WooCommerce.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * État du réglage de journalisation, résolu une fois par requête.
	 *
	 * @var bool|null
	 */
	private static $enabled = null;

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

		if ( ! self::is_level_enabled( $level ) ) {
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
	 * Ce niveau doit-il être écrit dans l'état actuel des réglages ?
	 *
	 * Le réglage est mémorisé pour la durée de la requête : sans cela, une
	 * boucle de traitement par lots relirait l'option à chaque entrée écrite.
	 * La contrepartie est qu'un changement de réglage ne prend effet qu'à la
	 * requête suivante, ce qui est sans conséquence.
	 *
	 * @param string $level Niveau PSR-3.
	 *
	 * @return bool
	 */
	private static function is_level_enabled( string $level ): bool {
		if ( in_array( $level, self::ALWAYS_LOGGED, true ) ) {
			return true;
		}

		if ( null === self::$enabled ) {
			self::$enabled = Settings::get_bool( 'enable_logging', false );
		}

		return self::$enabled;
	}

	/**
	 * Oublie l'état mémorisé du réglage.
	 *
	 * Utile après l'enregistrement des réglages, et indispensable aux tests.
	 */
	public static function reset(): void {
		self::$enabled = null;
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
