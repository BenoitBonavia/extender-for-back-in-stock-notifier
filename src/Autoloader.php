<?php
/**
 * Autoloader PSR-4 minimaliste (pas de dépendance à Composer en production).
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN;

defined( 'ABSPATH' ) || exit;

/**
 * Charge les classes du namespace EBISN\ depuis le dossier src/.
 */
final class Autoloader {

	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Enregistre l'autoloader auprès de SPL.
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Résout un nom de classe pleinement qualifié vers un fichier de src/.
	 *
	 * @param string $class_name Nom de classe pleinement qualifié.
	 */
	public static function load( string $class_name ): void {
		if ( 0 !== strncmp( self::PREFIX, $class_name, strlen( self::PREFIX ) ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::PREFIX ) );
		$file     = EBISN_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
