<?php
/**
 * Base commune aux modules.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Implémente le comportement par défaut d'un module : activation pilotée par
 * une option `ebisn_module_{id}_enabled`, surchargeable par filtre.
 */
abstract class AbstractModule implements ModuleInterface {

	/**
	 * Identifiant machine unique.
	 *
	 * @var string
	 */
	protected $id = '';

	/**
	 * Libellé lisible.
	 *
	 * @var string
	 */
	protected $title = '';

	/**
	 * Le module est-il actif par défaut ?
	 *
	 * @var bool
	 */
	protected $enabled_by_default = true;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return $this->title;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Vide par défaut : un module qui n'explique rien n'affiche rien, plutôt
	 * qu'un texte générique qui n'apprendrait rien à personne.
	 */
	public function get_description(): string {
		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_enabled_by_default(): bool {
		return $this->enabled_by_default;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_enabled(): bool {
		$enabled = Settings::get_bool(
			'module_' . $this->get_id() . '_enabled',
			$this->enabled_by_default
		);

		/**
		 * Force l'activation ou la désactivation d'un module.
		 *
		 * @param bool            $enabled État calculé depuis les réglages.
		 * @param ModuleInterface $module  Instance du module.
		 */
		return (bool) apply_filters( 'ebisn_module_is_enabled', $enabled, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	abstract public function register(): void;
}
