<?php
/**
 * Export CSV de la matrice des demandes.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Matrix;

defined( 'ABSPATH' ) || exit;

/**
 * Écrit la matrice complète en CSV.
 *
 * « Complète » au sens propre : l'export ignore la pagination de l'écran et
 * reprend toutes les lignes correspondant à la recherche et au filtre en cours.
 * Exporter la seule page affichée serait une source d'erreur silencieuse.
 */
final class MatrixExporter {

	/**
	 * Paramètre d'URL déclenchant l'export.
	 */
	public const ACTION = 'ebisn_matrix_csv';

	/**
	 * Action du nonce.
	 */
	public const NONCE = 'ebisn_matrix_csv';

	/**
	 * Caractères qui, en tête de cellule, font qu'un tableur interprète la
	 * valeur comme une formule.
	 */
	private const FORMULA_TRIGGERS = array( '=', '+', '-', '@', "\t", "\r" );

	/**
	 * Intercepte la demande d'export.
	 */
	public function maybe_export(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- le jeton est vérifié juste après.
		if ( ! isset( $_GET[ self::ACTION ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'extender-for-back-in-stock-notifier' ) );
		}

		check_admin_referer( self::NONCE );

		$this->send( DemandMatrix::get() );
	}

	/**
	 * URL d'export, filtres courants inclus.
	 *
	 * @return string
	 */
	public static function url(): string {
		$args = array( self::ACTION => 1 );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture de contexte pour composer un lien.
		if ( isset( $_REQUEST['s'] ) && '' !== $_REQUEST['s'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
			$args['s'] = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		if ( isset( $_REQUEST['ebisn_scope'] ) && '' !== $_REQUEST['ebisn_scope'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
			$args['ebisn_scope'] = sanitize_key( wp_unslash( $_REQUEST['ebisn_scope'] ) );
		}

		return wp_nonce_url( add_query_arg( $args, admin_url( 'edit.php' ) ), self::NONCE );
	}

	/**
	 * Compose et envoie le fichier.
	 *
	 * @param array<string, mixed> $matrix Matrice complète.
	 */
	private function send( array $matrix ): void {
		$columns = (array) $matrix['columns'];

		if ( ! empty( $matrix['has_undefined'] ) ) {
			$columns[] = AttributeResolver::UNDEFINED;
		}

		$rows = $this->filter( (array) $matrix['rows'] );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header(
			'Content-Disposition: attachment; filename=' . sprintf(
				'demandes-par-taille-%s.csv',
				gmdate( 'Y-m-d' )
			)
		);

		$handle = fopen( 'php://output', 'w' );

		if ( false === $handle ) {
			exit;
		}

		/*
		 * Marque d'ordre des octets : sans elle, Excel lit l'UTF-8 de travers et
		 * « Sélène » devient « SÃ©lÃ¨ne ».
		 *
		 * L'écriture passe volontairement par les fonctions natives : la cible
		 * est `php://output`, un flux de réponse HTTP, et non un fichier —
		 * WP_Filesystem n'a rien à y faire.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $handle, "\xEF\xBB\xBF" );

		$header = array( __( 'Produit', 'extender-for-back-in-stock-notifier' ) );

		foreach ( $columns as $column ) {
			$header[] = (string) ( $matrix['labels'][ $column ] ?? $column );
		}

		$header[] = __( 'Total', 'extender-for-back-in-stock-notifier' );

		$this->put( $handle, $header );

		$sums  = array();
		$grand = 0;

		foreach ( $rows as $row ) {
			$line = array( (string) $row['name'] );

			foreach ( $columns as $column ) {
				$count           = (int) ( $row['cells'][ $column ]['count'] ?? 0 );
				$line[]          = $count;
				$sums[ $column ] = ( $sums[ $column ] ?? 0 ) + $count;
			}

			$line[] = (int) $row['total'];
			$grand += (int) $row['total'];

			$this->put( $handle, $line );
		}

		$totals = array( __( 'Total', 'extender-for-back-in-stock-notifier' ) );

		foreach ( $columns as $column ) {
			$totals[] = (int) ( $sums[ $column ] ?? 0 );
		}

		$totals[] = $grand;

		$this->put( $handle, $totals );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $handle );
		exit;
	}

	/**
	 * Applique la recherche et le filtre de portée.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filter( array $rows ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- le jeton a été vérifié par maybe_export().
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- idem.
		$scope = isset( $_GET['ebisn_scope'] ) ? sanitize_key( wp_unslash( $_GET['ebisn_scope'] ) ) : '';

		$needle = '' === $search ? '' : strtolower( remove_accents( trim( $search ) ) );

		return array_filter(
			$rows,
			static function ( array $row ) use ( $needle, $scope ): bool {
				$cells = (array) $row['cells'];

				if ( 'sized' === $scope && 1 === count( $cells ) && isset( $cells[ AttributeResolver::UNDEFINED ] ) ) {
					return false;
				}

				if ( '' === $needle ) {
					return true;
				}

				return false !== strpos( strtolower( remove_accents( (string) $row['name'] ) ), $needle );
			}
		);
	}

	/**
	 * Écrit une ligne, formules neutralisées.
	 *
	 * @param resource          $handle Flux de sortie.
	 * @param array<int, mixed> $line   Valeurs.
	 */
	private function put( $handle, array $line ): void {
		fputcsv( $handle, array_map( array( $this, 'defuse' ), $line ), ';' );
	}

	/**
	 * Neutralise une valeur susceptible d'être lue comme une formule.
	 *
	 * Un produit nommé « =1+1 » — ou, plus vicieux, une formule appelant une URL
	 * externe — est exécuté à l'ouverture du fichier par la plupart des
	 * tableurs. Préfixer d'une apostrophe force l'interprétation en texte.
	 *
	 * @param mixed $value Valeur.
	 *
	 * @return string
	 */
	private function defuse( $value ): string {
		$value = (string) $value;

		if ( '' === $value ) {
			return $value;
		}

		return in_array( $value[0], self::FORMULA_TRIGGERS, true ) ? "'" . $value : $value;
	}
}
