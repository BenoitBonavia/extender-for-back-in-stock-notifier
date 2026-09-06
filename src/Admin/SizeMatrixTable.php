<?php
/**
 * Tableau des demandes par produit et par attribut.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Matrix\AttributeResolver;
use EBISN\Matrix\DemandMatrix;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Liste native WordPress à colonnes dynamiques.
 *
 * S'appuyer sur `WP_List_Table` apporte sans écrire une ligne le balisage
 * `wp-list-table`, la pagination, les en-têtes triables, la boîte de recherche
 * et les options d'écran — c'est-à-dire l'apparence et les comportements que
 * l'on attend d'un écran d'administration, y compris sous un jeu de couleurs
 * personnalisé.
 *
 * Les colonnes étant des valeurs d'attribut arbitraires, elles ne peuvent pas
 * servir d'identifiants : « 38,5 » ou « Bleu nuit » finiraient dans des classes
 * CSS et des paramètres d'URL. Chaque valeur reçoit donc une clé technique
 * `v0`, `v1`… et la correspondance est conservée à part.
 */
final class SizeMatrixTable extends \WP_List_Table {

	/**
	 * Clé de la colonne des totaux de ligne.
	 */
	private const TOTAL_COLUMN = 'ebisn_total';

	/**
	 * Clé de la colonne des produits.
	 */
	private const PRODUCT_COLUMN = 'ebisn_product';

	/**
	 * Matrice complète.
	 *
	 * @var array<string, mixed>
	 */
	private $matrix;

	/**
	 * Correspondance clé technique => valeur d'attribut.
	 *
	 * @var array<string, string>
	 */
	private $column_values = array();

	/**
	 * Valeur la plus demandée d'une cellule, pour la carte de chaleur.
	 *
	 * @var int
	 */
	private $peak = 0;

	/**
	 * Totaux par colonne, sur les lignes filtrées et toutes pages confondues.
	 *
	 * @var array<string, int>
	 */
	private $column_sums = array();

	/**
	 * Total général des lignes filtrées.
	 *
	 * @var int
	 */
	private $filtered_total = 0;

	/**
	 * Constructeur.
	 *
	 * @param array<string, mixed> $matrix Matrice complète.
	 */
	public function __construct( array $matrix ) {
		$this->matrix = $matrix;

		parent::__construct(
			array(
				'singular' => 'ebisn_matrix_row',
				'plural'   => 'ebisn_matrix_rows',
				'ajax'     => false,
			)
		);

		$index = 0;

		foreach ( $this->columns_in_order() as $value ) {
			$this->column_values[ 'v' . $index ] = $value;
			++$index;
		}
	}

	/**
	 * Valeurs d'attribut affichées, la colonne « N/D » en dernier.
	 *
	 * @return string[]
	 */
	private function columns_in_order(): array {
		$values = (array) $this->matrix['columns'];

		if ( ! empty( $this->matrix['has_undefined'] ) ) {
			$values[] = AttributeResolver::UNDEFINED;
		}

		return $values;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		$columns = array(
			self::PRODUCT_COLUMN => __( 'Produit', 'extender-for-back-in-stock-notifier' ),
		);

		foreach ( $this->column_values as $key => $value ) {
			$columns[ $key ] = $this->matrix['labels'][ $value ] ?? $value;
		}

		$columns[ self::TOTAL_COLUMN ] = __( 'Total', 'extender-for-back-in-stock-notifier' );

		return $columns;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string, array<int, mixed>>
	 */
	protected function get_sortable_columns(): array {
		$sortable = array(
			self::PRODUCT_COLUMN => array( self::PRODUCT_COLUMN, false ),
			// `true` : le tableau arrive déjà trié par total décroissant.
			self::TOTAL_COLUMN   => array( self::TOTAL_COLUMN, true, null, null, 'desc' ),
		);

		foreach ( array_keys( $this->column_values ) as $key ) {
			$sortable[ $key ] = array( $key, false );
		}

		return $sortable;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name(): string {
		return self::PRODUCT_COLUMN;
	}

	/**
	 * Filtre, trie et découpe les lignes.
	 */
	public function prepare_items(): void {
		$rows = $this->filter_rows( (array) $this->matrix['rows'] );

		$this->peak = $this->compute_peak( $rows );

		// Les totaux du pied portent sur les lignes RETENUES, toutes pages
		// confondues : ils suivent donc la recherche et le filtre, mais ne
		// rétrécissent pas quand on tourne la page.
		$this->compute_sums( $rows );

		$rows = $this->sort_rows( $rows );

		$per_page = $this->get_items_per_page( 'ebisn_matrix_per_page', 50 );
		$current  = $this->get_pagenum();
		$total    = count( $rows );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->items = array_slice( $rows, ( $current - 1 ) * $per_page, $per_page, true );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Applique la recherche et le filtre de déclinaison.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_rows( array $rows ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de lecture, sans effet de bord.
		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de lecture, sans effet de bord.
		$scope = isset( $_REQUEST['ebisn_scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['ebisn_scope'] ) ) : '';

		$needle = '' === $search ? '' : self::normalize( $search );

		return array_filter(
			$rows,
			static function ( array $row ) use ( $needle, $scope ): bool {
				if ( 'sized' === $scope && self::row_has_no_attribute( $row ) ) {
					return false;
				}

				if ( '' === $needle ) {
					return true;
				}

				return false !== strpos( self::normalize( (string) $row['name'] ), $needle );
			}
		);
	}

	/**
	 * La ligne est-elle dépourvue de toute valeur d'attribut ?
	 *
	 * @param array<string, mixed> $row Ligne.
	 *
	 * @return bool
	 */
	private static function row_has_no_attribute( array $row ): bool {
		$cells = (array) $row['cells'];

		return 1 === count( $cells ) && isset( $cells[ AttributeResolver::UNDEFINED ] );
	}

	/**
	 * Normalise une chaîne pour la recherche.
	 *
	 * Sans accents et en minuscules : « selene » doit trouver « Sélène ».
	 *
	 * @param string $value Chaîne.
	 *
	 * @return string
	 */
	private static function normalize( string $value ): string {
		return strtolower( remove_accents( trim( $value ) ) );
	}

	/**
	 * Valeur maximale d'une cellule, base de la carte de chaleur.
	 *
	 * Calculée sur les lignes filtrées, et non sur la page affichée : l'échelle
	 * resterait sinon différente d'une page à l'autre, rendant les couleurs
	 * incomparables.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes filtrées.
	 *
	 * @return int
	 */
	private function compute_peak( array $rows ): int {
		$peak = 0;

		foreach ( $rows as $row ) {
			foreach ( (array) $row['cells'] as $cell ) {
				$peak = max( $peak, (int) $cell['count'] );
			}
		}

		return $peak;
	}

	/**
	 * Additionne les demandes par colonne sur les lignes retenues.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes filtrées.
	 */
	private function compute_sums( array $rows ): void {
		$this->column_sums    = array();
		$this->filtered_total = 0;

		foreach ( $rows as $row ) {
			$this->filtered_total += (int) $row['total'];

			foreach ( (array) $row['cells'] as $value => $cell ) {
				$this->column_sums[ $value ] = ( $this->column_sums[ $value ] ?? 0 ) + (int) $cell['count'];
			}
		}
	}

	/**
	 * Trie les lignes selon la colonne demandée.
	 *
	 * @param array<int, array<string, mixed>> $rows Lignes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function sort_rows( array $rows ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tri de lecture, sans effet de bord.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : self::TOTAL_COLUMN;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tri de lecture, sans effet de bord.
		$order = isset( $_REQUEST['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_REQUEST['order'] ) ) ) ? 'asc' : 'desc';

		$sign = 'asc' === $order ? 1 : -1;

		if ( self::PRODUCT_COLUMN === $orderby ) {
			uasort(
				$rows,
				static function ( array $a, array $b ) use ( $sign ): int {
					return $sign * strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				}
			);

			return $rows;
		}

		$value = $this->column_values[ $orderby ] ?? null;

		uasort(
			$rows,
			static function ( array $a, array $b ) use ( $sign, $value ): int {
				$x = null === $value ? (int) $a['total'] : (int) ( $a['cells'][ $value ]['count'] ?? 0 );
				$y = null === $value ? (int) $b['total'] : (int) ( $b['cells'][ $value ]['count'] ?? 0 );

				if ( $x === $y ) {
					// Départage stable : à égalité, l'ordre alphabétique évite
					// que les lignes ne dansent d'un affichage à l'autre.
					return strnatcasecmp( (string) $a['name'], (string) $b['name'] );
				}

				return $sign * ( $x <=> $y );
			}
		);

		return $rows;
	}

	/**
	 * Affiche le tableau, pied de répartition compris.
	 *
	 * Reprend la structure de `WP_List_Table::display()` en ne changeant qu'une
	 * chose : le pied, qui répète les en-têtes par défaut, porte ici la
	 * répartition des demandes. Chaque barre se trouve ainsi SOUS sa colonne,
	 * ce qui évite d'avoir à faire correspondre des libellés répétés ailleurs.
	 */
	public function display(): void {
		$this->display_tablenav( 'top' );

		$this->screen->render_screen_reader_content( 'heading_list' );

		printf(
			'<table class="wp-list-table %s">',
			esc_attr( implode( ' ', $this->get_table_classes() ) )
		);

		echo '<thead><tr>';
		$this->print_column_headers();
		echo '</tr></thead>';

		printf( '<tbody id="the-list" data-wp-lists="list:%s">', esc_attr( $this->_args['singular'] ) );
		$this->display_rows_or_placeholder();
		echo '</tbody>';

		$this->display_totals_row();

		echo '</table>';

		$this->display_tablenav( 'bottom' );
	}

	/**
	 * Affiche le pied de répartition.
	 */
	private function display_totals_row(): void {
		if ( empty( $this->items ) ) {
			return;
		}

		$peak = $this->column_sums ? max( $this->column_sums ) : 0;

		echo '<tfoot><tr class="ebisn-matrix__totals">';

		printf(
			'<th scope="row" class="column-%1$s">%2$s<span class="ebisn-matrix__scope">%3$s</span></th>',
			esc_attr( self::PRODUCT_COLUMN ),
			esc_html__( 'Répartition', 'extender-for-back-in-stock-notifier' ),
			esc_html__( 'toutes pages', 'extender-for-back-in-stock-notifier' )
		);

		foreach ( $this->column_values as $key => $value ) {
			$count  = (int) ( $this->column_sums[ $value ] ?? 0 );
			$height = $peak > 0 ? ( $count / $peak ) * 100 : 0;

			printf(
				'<td class="column-%1$s">'
					. '<span class="ebisn-matrix__bar" aria-hidden="true"><span style="height:%2$s%%"></span></span>'
					. '<span class="ebisn-matrix__sum">%3$s</span>'
				. '</td>',
				esc_attr( $key ),
				// Point décimal imposé : un transtypage suivrait la locale.
				esc_attr( number_format( $height, 2, '.', '' ) ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		printf(
			'<td class="column-%1$s"><span class="ebisn-matrix__sum">%2$s</span></td>',
			esc_attr( self::TOTAL_COLUMN ),
			esc_html( number_format_i18n( $this->filtered_total ) )
		);

		echo '</tr></tfoot>';
	}

	/**
	 * {@inheritDoc}
	 */
	public function no_items(): void {
		esc_html_e(
			'Aucune demande en attente : tout est réapprovisionné, ou les demandes existantes ont déjà été notifiées ou converties en achat.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * Filtre de portée, à gauche de la pagination.
	 *
	 * @param string $which Position du bloc (`top` ou `bottom`).
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtre de lecture, sans effet de bord.
		$scope = isset( $_REQUEST['ebisn_scope'] ) ? sanitize_key( wp_unslash( $_REQUEST['ebisn_scope'] ) ) : '';

		echo '<div class="alignleft actions">';

		echo '<label class="screen-reader-text" for="ebisn_scope">'
			. esc_html__( 'Filtrer les produits', 'extender-for-back-in-stock-notifier' )
			. '</label>';

		echo '<select name="ebisn_scope" id="ebisn_scope">';
		printf(
			'<option value="">%s</option>',
			esc_html__( 'Tous les produits', 'extender-for-back-in-stock-notifier' )
		);
		printf(
			'<option value="sized" %1$s>%2$s</option>',
			selected( $scope, 'sized', false ),
			esc_html__( 'Produits déclinés uniquement', 'extender-for-back-in-stock-notifier' )
		);
		echo '</select>';

		submit_button(
			__( 'Filtrer', 'extender-for-back-in-stock-notifier' ),
			'',
			'filter_action',
			false,
			array( 'id' => 'ebisn-matrix-filter' )
		);

		echo '</div>';
	}

	/**
	 * Colonne « Produit ».
	 *
	 * @param array<string, mixed> $item Ligne.
	 *
	 * @return string
	 */
	public function column_ebisn_product( array $item ): string {
		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>',
			esc_url( self::subscribers_url( array( (int) $item['parent_id'] ) ) ),
			esc_html( (string) $item['name'] )
		);
	}

	/**
	 * Colonne « Total ».
	 *
	 * @param array<string, mixed> $item Ligne.
	 *
	 * @return string
	 */
	public function column_ebisn_total( array $item ): string {
		return '<strong>' . esc_html( number_format_i18n( (int) $item['total'] ) ) . '</strong>';
	}

	/**
	 * Cellules de valeurs d'attribut.
	 *
	 * @param array<string, mixed> $item        Ligne.
	 * @param string               $column_name Clé technique de la colonne.
	 *
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		$value = $this->column_values[ $column_name ] ?? null;

		if ( null === $value ) {
			return '';
		}

		$cell  = $item['cells'][ $value ] ?? null;
		$count = $cell ? (int) $cell['count'] : 0;

		if ( 0 === $count ) {
			// Le point est décoratif ; la valeur réelle reste annoncée aux
			// lecteurs d'écran, pour qui « · » ne veut rien dire.
			return '<span class="ebisn-matrix__nil" aria-hidden="true">·</span>'
				. '<span class="screen-reader-text">0</span>';
		}

		/*
		 * Le lien porte les variations exactes de cette valeur, et non le
		 * produit entier : cliquer « 38 » ne doit pas lister les demandes de
		 * toutes les tailles. À défaut de variation connue — produit simple ou
		 * variation supprimée — on retombe sur le parent.
		 */
		$targets = ! empty( $cell['pids'] ) ? array_map( 'intval', (array) $cell['pids'] ) : array( (int) $item['parent_id'] );

		return sprintf(
			'<a class="ebisn-matrix__cell" href="%1$s" title="%2$s" style="--ebisn-heat:%3$s">%4$s</a>',
			esc_url( self::subscribers_url( $targets ) ),
			esc_attr__( 'Voir les demandes', 'extender-for-back-in-stock-notifier' ),
			// Point décimal imposé : un transtypage suivrait la locale.
			esc_attr( number_format( $this->intensity( $count ), 3, '.', '' ) ),
			esc_html( number_format_i18n( $count ) )
		);
	}

	/**
	 * Intensité d'une cellule, de 0 à 1.
	 *
	 * @param int $count Nombre de demandes.
	 *
	 * @return float
	 */
	private function intensity( int $count ): float {
		if ( $count <= 0 || $this->peak <= 0 ) {
			return 0.0;
		}

		return $count / $this->peak;
	}

	/**
	 * URL de la liste des inscrits, filtrée sur des produits.
	 *
	 * `cwg_filter_by_products` est le paramètre de l'extension hôte : il accepte
	 * un tableau et fait correspondre `cwginstock_pid` OU
	 * `cwginstock_product_id`.
	 *
	 * @param int[] $product_ids Produits ou variations.
	 *
	 * @return string
	 */
	public static function subscribers_url( array $product_ids ): string {
		$product_ids = array_values( array_unique( array_filter( array_map( 'intval', $product_ids ) ) ) );

		$args = array(
			'post_type'              => Host::SUBSCRIBER_TYPE,
			'cwg_filter_by_products' => $product_ids,
		);

		$statuses = DemandMatrix::statuses();

		// Le paramètre `post_status` n'accepte qu'une valeur : sur une sélection
		// multiple, mieux vaut ne pas filtrer du tout que d'en imposer une seule.
		if ( 1 === count( $statuses ) ) {
			$args['post_status'] = $statuses[0];
		}

		return add_query_arg( $args, admin_url( 'edit.php' ) );
	}
}
