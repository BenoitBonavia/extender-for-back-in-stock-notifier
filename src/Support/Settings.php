<?php
/**
 * Accès centralisé aux réglages du plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Les réglages sont stockés en options WordPress préfixées `ebisn_`, ce qui les
 * rend directement compatibles avec l'API de réglages de WooCommerce.
 */
final class Settings {

	/**
	 * Préfixe appliqué à toutes les options du plugin.
	 */
	public const PREFIX = 'ebisn_';

	/**
	 * Construit le nom d'option complet à partir d'une clé courte.
	 *
	 * @param string $key Clé sans préfixe.
	 *
	 * @return string
	 */
	public static function option_name( string $key ): string {
		return 0 === strncmp( $key, self::PREFIX, strlen( self::PREFIX ) )
			? $key
			: self::PREFIX . $key;
	}

	/**
	 * Retourne la valeur brute d'un réglage.
	 *
	 * Le paramètre s'appelle `$fallback` et non `$default` : ce dernier est un
	 * mot réservé de PHP, et les standards de codage le refusent en nom de
	 * paramètre.
	 *
	 * @param string $key      Clé sans préfixe.
	 * @param mixed  $fallback Valeur de repli.
	 *
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$value = get_option( self::option_name( $key ), $fallback );

		/**
		 * Filtre la valeur d'un réglage du plugin.
		 *
		 * @param mixed  $value    Valeur lue en base.
		 * @param string $key      Clé sans préfixe.
		 * @param mixed  $fallback Valeur de repli.
		 */
		return apply_filters( 'ebisn_setting', $value, $key, $fallback );
	}

	/**
	 * Retourne un réglage booléen (WooCommerce stocke 'yes' / 'no').
	 *
	 * @param string $key      Clé sans préfixe.
	 * @param bool   $fallback Valeur de repli.
	 *
	 * @return bool
	 */
	public static function get_bool( string $key, bool $fallback = false ): bool {
		$value = self::get( $key, $fallback ? 'yes' : 'no' );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( strtolower( (string) $value ), array( 'yes', '1', 'true', 'on' ), true );
	}

	/**
	 * Enregistre un réglage.
	 *
	 * @param string $key   Clé sans préfixe.
	 * @param mixed  $value Valeur à stocker.
	 *
	 * @return bool
	 */
	public static function update( string $key, $value ): bool {
		return update_option( self::option_name( $key ), $value, false );
	}

	/**
	 * Supprime un réglage.
	 *
	 * @param string $key Clé sans préfixe.
	 *
	 * @return bool
	 */
	public static function delete( string $key ): bool {
		return delete_option( self::option_name( $key ) );
	}
}
