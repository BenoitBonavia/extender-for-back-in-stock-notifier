<?php
/**
 * Contrat commun à tous les modules du plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

defined( 'ABSPATH' ) || exit;

/**
 * Un module encapsule une extension autonome du plugin hôte
 * (l'équivalent structuré d'un snippet).
 */
interface ModuleInterface {

	/**
	 * Identifiant machine unique, en snake_case.
	 *
	 * @return string
	 */
	public function get_id(): string;

	/**
	 * Libellé lisible, affiché dans les réglages.
	 *
	 * @return string
	 */
	public function get_title(): string;

	/**
	 * Indique si le module doit être chargé.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool;

	/**
	 * État du module tant que le marchand n'a rien choisi.
	 *
	 * Exposé au contrat parce que l'écran de réglages doit afficher la MÊME
	 * valeur par défaut que celle sur laquelle `is_enabled()` se rabat.
	 *
	 * @return bool
	 */
	public function is_enabled_by_default(): bool;

	/**
	 * Accroche les hooks du module. Appelé une seule fois, si is_enabled().
	 */
	public function register(): void;
}
