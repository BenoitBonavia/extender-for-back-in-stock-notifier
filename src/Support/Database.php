<?php
/**
 * Lecture de l'état de la dernière requête.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Petit point d'accès à `$wpdb->last_error`.
 *
 * `$wpdb->get_results()` rend un tableau vide aussi bien quand rien ne
 * correspond que quand la requête a échoué. Lire l'erreur est le seul moyen de
 * les distinguer — et la différence compte : « aucune commande trouvée » est
 * une autorisation d'envoyer, « requête en échec » n'en est pas une.
 */
final class Database {

	/**
	 * Erreur de la dernière requête.
	 *
	 * @return string Chaîne vide si elle a réussi.
	 */
	public static function last_error(): string {
		$db = $GLOBALS['wpdb'] ?? null;

		return is_object( $db ) && isset( $db->last_error ) ? (string) $db->last_error : '';
	}
}
