<?php
/**
 * Verrou d'exclusion mutuelle entre processus.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Empêche deux exécutions concurrentes d'un même traitement de fond.
 *
 * Ni transient ni `wp_cache_add()` : le premier n'est pas atomique, le second
 * n'est pas durable. On s'appuie sur la contrainte d'unicité de `option_name`
 * avec un `INSERT IGNORE`, exactement comme `WP_Upgrader::create_lock()`.
 *
 * `add_option()` ne conviendrait PAS : elle fait un `SELECT` préalable puis un
 * `INSERT … ON DUPLICATE KEY UPDATE`, donc deux processus simultanés peuvent
 * tous deux croire avoir pris le verrou.
 */
final class Lock {

	/**
	 * Durée de vie par défaut, en secondes.
	 *
	 * Au-delà, le verrou est considéré comme orphelin : le processus qui le
	 * détenait a été tué sans pouvoir le libérer.
	 */
	public const DEFAULT_TTL = 600;

	/**
	 * Tente de prendre le verrou.
	 *
	 * @param string $name Nom court du verrou.
	 * @param int    $ttl  Durée de vie en secondes.
	 *
	 * @return bool Faux si un autre processus le détient toujours.
	 */
	public static function acquire( string $name, int $ttl = self::DEFAULT_TTL ): bool {
		$option = self::option_name( $name );

		// Deux tours au plus : une prise directe, puis une seconde après avoir
		// évincé un verrou périmé. Une boucle bornée plutôt qu'une récursion,
		// pour qu'un échec de `delete_option()` ne parte pas à l'infini.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			if ( self::insert( $option ) ) {
				return true;
			}

			$acquired_at = (int) self::read( $option );

			if ( $acquired_at > 0 && ( time() - $acquired_at ) < $ttl ) {
				return false;
			}

			self::release( $name );
		}

		return false;
	}

	/**
	 * Insère la ligne de verrou, sans écraser une éventuelle existante.
	 *
	 * @param string $option Nom complet de l'option.
	 *
	 * @return bool Vrai si CE processus vient de poser le verrou.
	 */
	private static function insert( string $option ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- l'atomicité EST la fonctionnalité : passer par l'API des options la perdrait.
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} ( option_name, option_value, autoload ) VALUES ( %s, %s, 'off' )",
				$option,
				(string) time()
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( 1 !== (int) $inserted ) {
			return false;
		}

		// L'écriture ayant contourné l'API des options, le cache d'objets porte
		// encore un « cette option n'existe pas » qu'il faut invalider.
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return true;
	}

	/**
	 * Libère le verrou.
	 *
	 * @param string $name Nom court du verrou.
	 */
	public static function release( string $name ): void {
		delete_option( self::option_name( $name ) );
	}

	/**
	 * Le verrou est-il actuellement détenu ?
	 *
	 * @param string $name Nom court du verrou.
	 * @param int    $ttl  Durée de vie en secondes.
	 *
	 * @return bool
	 */
	public static function is_held( string $name, int $ttl = self::DEFAULT_TTL ): bool {
		$acquired_at = (int) self::read( self::option_name( $name ) );

		return $acquired_at > 0 && ( time() - $acquired_at ) < $ttl;
	}

	/**
	 * Lit la valeur brute du verrou, sans passer par le cache d'options.
	 *
	 * Le `INSERT IGNORE` ci-dessus écrit directement en base : le cache d'objets
	 * peut encore porter un « cette option n'existe pas » posé par un autre
	 * processus. Une lecture directe est la seule fiable ici.
	 *
	 * @param string $option Nom complet de l'option.
	 *
	 * @return string
	 */
	private static function read( string $option ): string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- lecture volontairement non cachée, voir le bloc de documentation.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Nom d'option d'un verrou.
	 *
	 * @param string $name Nom court.
	 *
	 * @return string
	 */
	private static function option_name( string $name ): string {
		return Settings::option_name( 'lock_' . sanitize_key( $name ) );
	}
}
