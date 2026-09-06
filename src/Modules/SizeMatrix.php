<?php
/**
 * Module « Demandes par taille ».
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Admin\SizeMatrixPage;
use EBISN\Matrix\DemandMatrix;
use EBISN\Support\SnippetGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Ajoute un écran croisant les demandes de réassort par produit et par
 * déclinaison.
 *
 * Répond à une question que l'extension hôte ne sait pas poser : « de quelles
 * tailles ai-je besoin, et en quelle quantité ? ». Sa liste d'inscrits est
 * linéaire ; il faut la lire ligne à ligne pour reconstituer la demande.
 */
final class SizeMatrix extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'size_matrix';

	/**
	 * Fonctions du snippet WPCode que ce module remplace.
	 */
	private const SNIPPET_FUNCTIONS = array(
		'mh_bisn_mx_render_page',
		'mh_bisn_mx_collect',
	);

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Afficher les demandes par taille', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		/*
		 * Le snippet remplacé enregistre sa propre sous-page : les deux
		 * coexisteraient dans le menu, avec des chiffres susceptibles de
		 * diverger dès que les réglages du module changent.
		 */
		if ( $this->snippet_guard()->is_blocked() ) {
			return;
		}

		DemandMatrix::register_invalidation();

		if ( is_admin() ) {
			( new SizeMatrixPage() )->register();
		}

		add_action( 'ebisn_upgrade', array( $this, 'on_upgrade' ) );
	}

	/**
	 * Reprend la configuration du snippet et nettoie ses résidus.
	 */
	public function on_upgrade(): void {
		$this->import_snippet_constants();
		$this->purge_snippet_cache();
	}

	/**
	 * Reprend les constantes du snippet comme valeurs initiales des réglages.
	 *
	 * Elles ne sont lisibles que tant que le snippet est chargé : on les
	 * capture au passage, plutôt que de laisser un marchand ayant ajusté sa
	 * configuration la retrouver réinitialisée.
	 */
	private function import_snippet_constants(): void {
		$mapping = array(
			'MH_BISN_MX_SIZE_ATTR' => 'matrix_attribute',
			'MH_BISN_MX_CACHE_TTL' => 'matrix_cache_minutes',
		);

		foreach ( $mapping as $constant => $setting ) {
			if ( ! defined( $constant ) ) {
				continue;
			}

			$value = constant( $constant );

			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			// Le snippet exprime sa durée de cache en secondes.
			if ( 'matrix_cache_minutes' === $setting ) {
				$value = max( 0, (int) round( ( (int) $value ) / MINUTE_IN_SECONDS ) );
			}

			\EBISN\Support\Settings::update( $setting, $value );
		}

		if ( defined( 'MH_BISN_MX_STATUS' ) && is_string( constant( 'MH_BISN_MX_STATUS' ) ) ) {
			\EBISN\Support\Settings::update(
				'matrix_statuses',
				array_map( 'trim', explode( ',', (string) constant( 'MH_BISN_MX_STATUS' ) ) )
			);
		}
	}

	/**
	 * Supprime le cache laissé par le snippet.
	 */
	private function purge_snippet_cache(): void {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- nettoyage ponctuel de transients à la migration.
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_mh_bisn_mx_' ) . '%'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		foreach ( (array) $names as $name ) {
			delete_transient( substr( (string) $name, strlen( '_transient_' ) ) );
		}
	}

	/**
	 * Ajoute l'état du module au panneau Diagnostic.
	 *
	 * @param string[] $lines Lignes existantes.
	 *
	 * @return string[]
	 */
	public function add_diagnostics( $lines ): array {
		$lines = (array) $lines;

		$blocked = $this->snippet_guard()->diagnostics_line( $this->get_title() );

		if ( '' !== $blocked ) {
			$lines[] = $blocked;

			return $lines;
		}

		$matrix = DemandMatrix::get();

		$lines[] = sprintf(
			/* translators: 1: nombre de demandes, 2: nombre de produits, 3: nom de l'attribut. */
			esc_html__( 'Demandes par taille : %1$s demande(s) sur %2$s produit(s), déclinaison lue sur %3$s.', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( (int) $matrix['grand'] ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( (int) $matrix['products'] ) ) . '</strong>',
			'' !== (string) $matrix['attribute_label']
				? '<code>' . esc_html( (string) $matrix['attribute_label'] ) . '</code>'
				: '<em>' . esc_html__( 'aucun attribut détecté', 'extender-for-back-in-stock-notifier' ) . '</em>'
		);

		return $lines;
	}

	/**
	 * Garde de détection du snippet remplacé.
	 *
	 * @return SnippetGuard
	 */
	private function snippet_guard(): SnippetGuard {
		return new SnippetGuard( self::SNIPPET_FUNCTIONS );
	}
}
