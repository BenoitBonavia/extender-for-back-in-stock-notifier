<?php
/**
 * Planification des traitements de fond.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Unique point du plugin qui connaît Action Scheduler.
 *
 * Concentrer ici tous les `function_exists( 'as_*' )` évite de les disséminer,
 * et donne un seul endroit à adapter le jour où l'ordonnanceur change.
 *
 * Deux règles non négociables sont encapsulées :
 *
 * 1. Un argument SCALAIRE, jamais un tableau associatif. `wp-cron.php` exécute
 *    `do_action_ref_array( $hook, $v['args'] )` sans `array_values()` : en PHP 8,
 *    une clé de chaîne devient un argument nommé, et un nom qui ne correspond
 *    pas à un paramètre du callback lève une `Error` fatale — laquelle
 *    interrompt le run cron ENTIER, pas seulement l'événement fautif.
 * 2. Aucune planification avant `init`. Action Scheduler initialise son magasin
 *    sur `init` priorité 1 ; planifier plus tôt échoue silencieusement.
 */
final class Scheduler {

	/**
	 * Groupe affiché dans WooCommerce → État → Actions programmées.
	 */
	public const GROUP = 'ebisn';

	/**
	 * Action Scheduler est-il utilisable ?
	 *
	 * On teste les FONCTIONS, jamais `class_exists( 'ActionScheduler' )` :
	 * quand plusieurs extensions embarquent la bibliothèque, c'est la version la
	 * plus récente qui l'emporte, et rien ne garantit que ce soit la nôtre.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_has_scheduled_action' )
			&& function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Programme une exécution dès que possible.
	 *
	 * @param string     $hook   Nom du hook.
	 * @param int|string $arg    Argument scalaire unique, ou chaîne vide.
	 * @param bool       $unique Ne pas empiler si une action identique est déjà en file.
	 */
	public static function enqueue( string $hook, $arg = '', bool $unique = false ): void {
		self::defer(
			static function () use ( $hook, $arg, $unique ) {
				$args = self::args( $arg );

				if ( self::is_available() ) {
					as_enqueue_async_action( $hook, $args, self::GROUP, $unique );

					return;
				}

				self::fallback( time() + 30, $hook, $args, $unique );
			}
		);
	}

	/**
	 * Programme une exécution différée.
	 *
	 * @param int        $timestamp Date d'exécution (UNIX).
	 * @param string     $hook      Nom du hook.
	 * @param int|string $arg       Argument scalaire unique, ou chaîne vide.
	 * @param bool       $unique    Ne pas empiler si une action identique est déjà en file.
	 */
	public static function schedule( int $timestamp, string $hook, $arg = '', bool $unique = false ): void {
		self::defer(
			static function () use ( $timestamp, $hook, $arg, $unique ) {
				$args = self::args( $arg );

				if ( self::is_available() ) {
					as_schedule_single_action( $timestamp, $hook, $args, self::GROUP, $unique );

					return;
				}

				self::fallback( $timestamp, $hook, $args, $unique );
			}
		);
	}

	/**
	 * Une exécution est-elle déjà programmée ?
	 *
	 * @param string     $hook Nom du hook.
	 * @param int|string $arg  Argument scalaire unique, ou chaîne vide.
	 *
	 * @return bool
	 */
	public static function has_scheduled( string $hook, $arg = '' ): bool {
		$args = self::args( $arg );

		if ( self::is_available() ) {
			return (bool) as_has_scheduled_action( $hook, $args, self::GROUP );
		}

		return false !== wp_next_scheduled( $hook, $args );
	}

	/**
	 * Annule toutes les exécutions programmées d'un hook.
	 *
	 * @param string $hook Nom du hook.
	 */
	public static function cancel_all( string $hook ): void {
		if ( self::is_available() ) {
			as_unschedule_all_actions( $hook, null, self::GROUP );
		}

		wp_clear_scheduled_hook( $hook );
	}

	/**
	 * Normalise l'argument en tableau INDEXÉ.
	 *
	 * Indexé, donc positionnel : c'est ce qui met le repli WP-Cron à l'abri du
	 * piège des arguments nommés décrit en tête de classe.
	 *
	 * @param int|string $arg Argument scalaire, ou chaîne vide pour aucun.
	 *
	 * @return array<int, int|string>
	 */
	private static function args( $arg ): array {
		if ( '' === $arg || null === $arg ) {
			return array();
		}

		return array( is_int( $arg ) ? $arg : (string) $arg );
	}

	/**
	 * Repli WP-Cron.
	 *
	 * En principe inatteignable : le plugin exige WooCommerce, qui embarque
	 * Action Scheduler. Conservé pour ne pas perdre silencieusement un
	 * traitement sur une installation atypique.
	 *
	 * @param int                    $timestamp Date d'exécution (UNIX).
	 * @param string                 $hook      Nom du hook.
	 * @param array<int, int|string> $args      Arguments positionnels.
	 * @param bool                   $unique    Ne pas empiler si déjà programmé.
	 */
	private static function fallback( int $timestamp, string $hook, array $args, bool $unique ): void {
		if ( $unique && false !== wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		wp_schedule_single_event( $timestamp, $hook, $args );
	}

	/**
	 * Exécute maintenant, ou reporte à `init` si l'ordonnanceur n'est pas prêt.
	 *
	 * Le cas se produit réellement : `Plugin::boot()` tourne sur `plugins_loaded`,
	 * donc les migrations branchées sur `ebisn_upgrade` s'exécutent AVANT `init`.
	 *
	 * @param callable $callback Planification à exécuter.
	 */
	private static function defer( callable $callback ): void {
		if ( did_action( 'init' ) ) {
			$callback();

			return;
		}

		// Priorité 5 : après l'initialisation d'Action Scheduler (priorité 1).
		add_action( 'init', $callback, 5 );
	}
}
