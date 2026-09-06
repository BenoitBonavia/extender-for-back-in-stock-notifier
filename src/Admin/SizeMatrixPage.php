<?php
/**
 * Page « Demandes par taille ».
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Matrix\AttributeResolver;
use EBISN\Matrix\DemandMatrix;
use EBISN\Matrix\MatrixExporter;

defined( 'ABSPATH' ) || exit;

/**
 * Sous-page croisant les demandes de réassort par produit et par attribut.
 *
 * L'apparence est celle d'un écran de liste natif : le tableau est un
 * `WP_List_Table`, les réglages passent par les options d'écran, et aucun
 * composant n'est redessiné. Le seul CSS propre au plugin porte sur la carte de
 * chaleur et le tableau de synthèse, qui n'ont pas d'équivalent natif.
 */
final class SizeMatrixPage {

	/**
	 * Identifiant de la page.
	 */
	public const SLUG = 'ebisn-size-matrix';

	/**
	 * Identifiant de la page du snippet remplacé.
	 *
	 * Conservé pour rediriger les favoris et les liens déjà partagés.
	 */
	public const LEGACY_SLUG = 'mh-bisn-matrice';

	/**
	 * Identifiant d'écran, mémorisé pour les options d'écran.
	 *
	 * @var string
	 */
	private $hook = '';

	/**
	 * Accroche la page.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 20 );
		add_action( 'admin_init', array( $this, 'maybe_redirect_legacy' ) );
		add_action( 'admin_init', array( new MatrixExporter(), 'maybe_export' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_option' ), 10, 3 );
	}

	/**
	 * Déclare la sous-page.
	 */
	public function add_page(): void {
		$this->hook = (string) add_submenu_page(
			Host::MENU_PARENT,
			__( 'Demandes par taille', 'extender-for-back-in-stock-notifier' ),
			__( 'Demandes par taille', 'extender-for-back-in-stock-notifier' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);

		add_action( 'load-' . $this->hook, array( $this, 'add_screen_options' ) );
		add_action( 'admin_print_styles-' . $this->hook, array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Charge la feuille de styles sur cette seule page.
	 */
	public function enqueue_assets(): void {
		wp_enqueue_style(
			'ebisn-admin',
			EBISN_URL . 'assets/css/admin.css',
			array(),
			EBISN_VERSION
		);
	}

	/**
	 * Déclare les options d'écran.
	 *
	 * Le nombre de lignes par page passe par le mécanisme natif de WordPress,
	 * qui le mémorise par utilisateur — là où le snippet remplacé stockait sa
	 * préférence de filtrage dans le navigateur.
	 */
	public function add_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				'label'   => __( 'Produits par page', 'extender-for-back-in-stock-notifier' ),
				'default' => 50,
				'option'  => 'ebisn_matrix_per_page',
			)
		);
	}

	/**
	 * Persiste l'option d'écran.
	 *
	 * @param mixed  $status Valeur retournée par défaut (`false`).
	 * @param string $option Nom de l'option.
	 * @param mixed  $value  Valeur soumise.
	 *
	 * @return mixed
	 */
	public function save_screen_option( $status, $option, $value ) {
		return 'ebisn_matrix_per_page' === $option ? max( 1, (int) $value ) : $status;
	}

	/**
	 * Redirige l'ancienne adresse de la page du snippet.
	 */
	public function maybe_redirect_legacy(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::LEGACY_SLUG !== $page ) {
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/**
	 * URL de la page.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'edit.php?post_type=' . Host::SUBSCRIBER_TYPE . '&page=' . self::SLUG );
	}

	/**
	 * Affiche la page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'extender-for-back-in-stock-notifier' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- rafraîchissement d'un cache de lecture, sans effet de bord.
		$matrix = DemandMatrix::get( isset( $_GET['ebisn_refresh'] ) );

		$table = new SizeMatrixTable( $matrix );
		$table->prepare_items();

		echo '<div class="wrap">';

		echo '<h1 class="wp-heading-inline">'
			. esc_html__( 'Demandes par taille', 'extender-for-back-in-stock-notifier' )
			. '</h1>';

		printf(
			' <a href="%1$s" class="page-title-action">%2$s</a>',
			esc_url( MatrixExporter::url() ),
			esc_html__( 'Exporter en CSV', 'extender-for-back-in-stock-notifier' )
		);

		printf(
			' <a href="%1$s" class="page-title-action">%2$s</a>',
			esc_url( add_query_arg( 'ebisn_refresh', 1, self::url() ) ),
			esc_html__( 'Actualiser', 'extender-for-back-in-stock-notifier' )
		);

		echo '<hr class="wp-header-end">';

		echo '<p class="description">' . esc_html__(
			'Demandes de retour en stock croisées par produit et par déclinaison. Cliquez sur un chiffre pour voir les personnes concernées.',
			'extender-for-back-in-stock-notifier'
		) . '</p>';

		$this->render_queued_notice();
		$this->render_stats( $matrix );

		echo '<form method="get">';
		printf( '<input type="hidden" name="post_type" value="%s" />', esc_attr( Host::SUBSCRIBER_TYPE ) );
		printf( '<input type="hidden" name="page" value="%s" />', esc_attr( self::SLUG ) );

		$table->search_box(
			__( 'Rechercher un produit', 'extender-for-back-in-stock-notifier' ),
			'ebisn-matrix-search'
		);

		$table->display();

		echo '</form>';

		$this->render_footnote( $matrix );

		echo '</div>';
	}

	/**
	 * Avertit des demandes en file d'envoi, absentes du tableau.
	 */
	private function render_queued_notice(): void {
		$queued = DemandMatrix::queued_count();

		if ( $queued <= 0 ) {
			return;
		}

		echo '<div class="notice notice-warning inline"><p>';
		printf(
			/* translators: %s: nombre de demandes en file d'envoi. */
			esc_html__( '%s demande(s) sont en file d’envoi et n’apparaissent pas ici : le produit est réapprovisionné et l’alerte part sous peu. Si ce nombre ne redescend pas, la file d’envoi est bloquée.', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( $queued ) ) . '</strong>'
		);
		echo '</p></div>';
	}

	/**
	 * Affiche les indicateurs de tête.
	 *
	 * Réutilise les classes du bandeau de conversion : deux écrans du même
	 * plugin n'ont pas de raison d'inventer chacun leur présentation.
	 *
	 * @param array<string, mixed> $matrix Matrice.
	 */
	private function render_stats( array $matrix ): void {
		if ( empty( $matrix['rows'] ) ) {
			return;
		}

		$top_label = '';
		$top_count = 0;

		foreach ( (array) $matrix['totals'] as $value => $count ) {
			if ( AttributeResolver::UNDEFINED === $value || $count <= $top_count ) {
				continue;
			}

			$top_count = (int) $count;
			$top_label = (string) ( $matrix['labels'][ $value ] ?? $value );
		}

		echo '<div class="ebisn-stats"><ul class="ebisn-stats__grid">';

		$this->stat(
			__( 'Demandes comptées', 'extender-for-back-in-stock-notifier' ),
			number_format_i18n( (int) $matrix['grand'] )
		);
		$this->stat(
			__( 'Produits concernés', 'extender-for-back-in-stock-notifier' ),
			number_format_i18n( (int) $matrix['products'] )
		);

		if ( '' !== $top_label ) {
			$this->stat(
				__( 'Déclinaison la plus demandée', 'extender-for-back-in-stock-notifier' ),
				$top_label,
				sprintf(
					/* translators: %s: nombre de demandes. */
					__( '%s demande(s)', 'extender-for-back-in-stock-notifier' ),
					number_format_i18n( $top_count )
				)
			);
		}

		echo '</ul></div>';
	}

	/**
	 * Affiche un indicateur.
	 *
	 * @param string $label Libellé.
	 * @param string $value Valeur.
	 * @param string $hint  Précision, en texte brut.
	 */
	private function stat( string $label, string $value, string $hint = '' ): void {
		printf(
			'<li class="ebisn-stat"><span class="ebisn-stat__label">%1$s</span>'
				. '<strong class="ebisn-stat__value">%2$s</strong>'
				. '<span class="ebisn-stat__hint">%3$s</span></li>',
			esc_html( $label ),
			esc_html( $value ),
			esc_html( $hint )
		);
	}

	/*
	 * La répartition par déclinaison est rendue par SizeMatrixTable, dans le
	 * pied du tableau : chaque barre se place ainsi sous sa propre colonne.
	 */

	/**
	 * Affiche la note de bas de page.
	 *
	 * @param array<string, mixed> $matrix Matrice.
	 */
	private function render_footnote( array $matrix ): void {
		$statuses = array_map(
			static function ( string $status ): string {
				$object = get_post_status_object( $status );

				return $object && isset( $object->label ) ? (string) $object->label : $status;
			},
			DemandMatrix::statuses()
		);

		echo '<p class="description">';

		if ( '' !== (string) $matrix['attribute_label'] ) {
			printf(
				/* translators: %s: nom de l'attribut servant d'axe. */
				esc_html__( 'Déclinaison lue sur l’attribut « %s ».', 'extender-for-back-in-stock-notifier' ),
				esc_html( (string) $matrix['attribute_label'] )
			);
		} else {
			esc_html_e( 'Aucun attribut de déclinaison détecté.', 'extender-for-back-in-stock-notifier' );
		}

		echo ' ';

		printf(
			/* translators: 1: liste des statuts comptés, 2: URL des réglages. */
			esc_html__( 'Statuts comptés : %1$s. %2$s', 'extender-for-back-in-stock-notifier' ),
			esc_html( implode( ', ', $statuses ) ),
			'<a href="' . esc_url( Admin::get_settings_url( 'matrix' ) ) . '">'
				. esc_html__( 'Modifier ces réglages', 'extender-for-back-in-stock-notifier' ) . '</a>'
		);

		echo '</p>';
	}
}
