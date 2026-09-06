<?php
/**
 * Détection des snippets remplacés par ce plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Met un module en veille tant que le snippet qu'il remplace est encore actif.
 *
 * Ce plugin reprend des snippets qui tournent en production. Pendant la fenêtre
 * de bascule, les deux peuvent être chargés en même temps : le snippet et le
 * module convertiraient alors les mêmes inscriptions, et pousseraient deux fois
 * les mêmes contacts.
 *
 * Ne sont retenues comme sentinelles que les fonctions qui ÉCRIVENT quelque
 * chose. Une fonction d'affichage ou de lecture encore chargée ne justifierait
 * pas de désactiver un module.
 */
final class SnippetGuard {

	/**
	 * Fonctions dont la présence indique un snippet actif.
	 *
	 * @var string[]
	 */
	private $functions;

	/**
	 * Constructeur.
	 *
	 * @param string[] $functions Noms de fonctions sentinelles.
	 */
	public function __construct( array $functions ) {
		$this->functions = $functions;
	}

	/**
	 * Un snippet concurrent est-il chargé ?
	 *
	 * @return bool
	 */
	public function is_blocked(): bool {
		return array() !== $this->detected();
	}

	/**
	 * Fonctions de snippet effectivement présentes.
	 *
	 * @return string[]
	 */
	public function detected(): array {
		return array_values( array_filter( $this->functions, 'function_exists' ) );
	}

	/**
	 * Ligne de diagnostic décrivant la mise en veille.
	 *
	 * @param string $module_title Libellé du module concerné.
	 *
	 * @return string HTML échappé, chaîne vide si rien n'est détecté.
	 */
	public function diagnostics_line( string $module_title ): string {
		$detected = $this->detected();

		if ( empty( $detected ) ) {
			return '';
		}

		// Chaque nom est échappé séparément : échapper la chaîne assemblée
		// neutraliserait aussi les balises des séparateurs.
		$names = array_map(
			static function ( string $name ): string {
				return '<code>' . esc_html( $name ) . '()</code>';
			},
			$detected
		);

		return '<strong style="color:#b32d2e">' . sprintf(
			/* translators: 1: nom du module, 2: liste de noms de fonctions PHP. */
			esc_html__( 'Module « %1$s » en veille : le snippet qu’il remplace est toujours actif (%2$s). Désactivez-le pour éviter que les deux ne traitent les mêmes données.', 'extender-for-back-in-stock-notifier' ),
			esc_html( $module_title ),
			implode( ', ', $names )
		) . '</strong>';
	}
}
