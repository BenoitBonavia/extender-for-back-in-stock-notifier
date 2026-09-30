<?php
/**
 * Module de croisement avec le réassort de Real Stock Manager.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Integration\RealStockManager;

defined( 'ABSPATH' ) || exit;

/**
 * Croise les demandes de retour en stock avec le réassort suivi par Real
 * Stock Manager for WooCommerce, à deux endroits : une case sur la page
 * « Demandes par taille » masquant ce que le stock physique et le commandé
 * fournisseur couvrent déjà, et une carte sur le bandeau de la liste des
 * inscrits valorisant ce que le commandé fournisseur peut honorer tout de
 * suite.
 *
 * Ne câble rien par lui-même : sa seule présence parmi les modules actifs est
 * ce que {@see \EBISN\Matrix\SupplyCoverage::is_available()} consulte pour
 * décider d'afficher l'un ou l'autre. Actif par défaut, à la différence du
 * module de synchronisation Brevo : lecture seule, sans effet tant que le
 * plugin frère Real Stock Manager for WooCommerce n'est pas détecté.
 */
final class StockCoverage extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'stock_coverage';

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'Croise les demandes de retour en stock avec le réassort suivi par Real Stock Manager for WooCommerce, commandes clients en attente servies en premier, stock physique consommé avant le commandé fournisseur. Ajoute à l’écran « Demandes par taille » une case qui déduit de chaque déclinaison ce qui est déjà couvert, et sur la liste des inscrits une carte valorisant ce que le commandé fournisseur peut honorer tout de suite. Sans ce plugin installé et actif, ce module ne change rien aux deux écrans. Lecture seule : aucune donnée n’est modifiée.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Croiser les demandes avec le réassort en cours', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );
	}

	/**
	 * Ajoute l'état de la dépendance au panneau Diagnostic.
	 *
	 * @param string[] $lines Lignes existantes.
	 *
	 * @return string[]
	 */
	public function add_diagnostics( $lines ): array {
		$lines = (array) $lines;

		if ( ! RealStockManager::is_active() ) {
			$lines[] = sprintf(
				/* translators: %s: nom du plugin frère. */
				esc_html__( '%s : non détecté. La case de croisement n’apparaît pas sur l’écran « Demandes par taille ».', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html( RealStockManager::name() ) . '</code>'
			);

			return $lines;
		}

		$lines[] = sprintf(
			/* translators: 1: nom du plugin frère, 2: numéro de version détecté. */
			esc_html__( '%1$s : détecté, version %2$s.', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( RealStockManager::name() ) . '</strong>',
			'<code>' . esc_html( RealStockManager::version() ) . '</code>'
		);

		if ( RealStockManager::is_untested() ) {
			$lines[] = '<strong style="color:#b26200">' . sprintf(
				/* translators: %s: numéro de version testé. */
				esc_html__( 'Cette version majeure n’a pas encore été relue (dernière version testée : %s).', 'extender-for-back-in-stock-notifier' ),
				esc_html( RealStockManager::TESTED_VERSION )
			) . '</strong>';
		}

		return $lines;
	}
}
