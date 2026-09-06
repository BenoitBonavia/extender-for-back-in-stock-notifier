<?php
/**
 * Module d'affichage des indicateurs de conversion.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Admin\StatsBanner;
use EBISN\Conversion\Stats;

defined( 'ABSPATH' ) || exit;

/**
 * Affiche la valeur des listes d'attente au-dessus de la liste des inscrits.
 *
 * Séparé du module de conversion : on peut vouloir convertir les inscriptions
 * sans encombrer l'écran, ou consulter les chiffres d'un site dont les
 * conversions viennent d'ailleurs.
 */
final class ConversionStats extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'conversion_stats';

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Afficher la valeur des listes d’attente', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		/*
		 * L'invalidation est branchée même hors administration : les conversions
		 * surviennent dans un contexte de tâche de fond, où rien ne s'affiche.
		 */
		Stats::register_invalidation();

		if ( is_admin() ) {
			( new StatsBanner() )->register();
		}
	}
}
