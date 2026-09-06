<?php
/**
 * Signatures d'Action Scheduler, déclarées pour l'analyse statique uniquement.
 *
 * Ce fichier n'est JAMAIS chargé à l'exécution : il est seulement listé dans
 * `scanFiles` de phpstan.neon.dist, et exclu des archives par .gitattributes.
 *
 * Raison d'être : Action Scheduler est embarqué par WooCommerce, mais ses
 * fonctions ne figurent pas dans `php-stubs/woocommerce-stubs`. Sans ces
 * déclarations, PHPStan signale `function.notFound` sur chaque appel, alors que
 * ces fonctions sont bel et bien présentes en production — et systématiquement
 * gardées par `Scheduler::is_available()`.
 *
 * Signatures relevées sur Action Scheduler 3.9.x, la version embarquée par les
 * WooCommerce que ce plugin accepte. Les paramètres `$unique` et `$priority`
 * existent respectivement depuis les versions 3.6.0 et 3.8.0.
 *
 * @package ExtenderForBackInStockNotifier
 */

/**
 * Programme une action à exécuter dès que possible.
 *
 * @param string       $hook     Nom du hook.
 * @param array<mixed> $args     Arguments passés au callback.
 * @param string       $group    Groupe d'actions.
 * @param bool         $unique   N'enfiler que si aucune action identique n'est en attente.
 * @param int          $priority Priorité d'exécution.
 *
 * @return int Identifiant de l'action, `0` en cas d'échec.
 */
function as_enqueue_async_action( $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	return 0;
}

/**
 * Programme une action à une date donnée.
 *
 * @param int          $timestamp Date d'exécution (UNIX).
 * @param string       $hook      Nom du hook.
 * @param array<mixed> $args      Arguments passés au callback.
 * @param string       $group     Groupe d'actions.
 * @param bool         $unique    N'enfiler que si aucune action identique n'est en attente.
 * @param int          $priority  Priorité d'exécution.
 *
 * @return int Identifiant de l'action, `0` en cas d'échec.
 */
function as_schedule_single_action( $timestamp, $hook, $args = array(), $group = '', $unique = false, $priority = 10 ) {
	return 0;
}

/**
 * Une action correspondante est-elle programmée ou en cours ?
 *
 * @param string            $hook  Nom du hook.
 * @param array<mixed>|null $args  Arguments à faire correspondre, `null` pour n'importe lesquels.
 * @param string            $group Groupe d'actions.
 *
 * @return bool
 */
function as_has_scheduled_action( $hook, $args = null, $group = '' ) {
	return false;
}

/**
 * Date de la prochaine exécution programmée.
 *
 * @param string            $hook  Nom du hook.
 * @param array<mixed>|null $args  Arguments à faire correspondre, `null` pour n'importe lesquels.
 * @param string            $group Groupe d'actions.
 *
 * @return int|bool Date UNIX, `true` si une action est en cours, `false` sinon.
 */
function as_next_scheduled_action( $hook, $args = null, $group = '' ) {
	return false;
}

/**
 * Annule la prochaine occurrence programmée d'une action.
 *
 * @param string            $hook  Nom du hook.
 * @param array<mixed>|null $args  Arguments à faire correspondre.
 * @param string            $group Groupe d'actions.
 *
 * @return int|null Identifiant de l'action annulée.
 */
function as_unschedule_action( $hook, $args = array(), $group = '' ) {
	return null;
}

/**
 * Annule toutes les occurrences programmées d'une action.
 *
 * @param string            $hook  Nom du hook.
 * @param array<mixed>|null $args  Arguments à faire correspondre, `null` pour n'importe lesquels.
 * @param string            $group Groupe d'actions.
 *
 * @return void
 */
function as_unschedule_all_actions( $hook, $args = array(), $group = '' ) {
}
