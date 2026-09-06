<?php
/**
 * Bandeau d'indicateurs au-dessus de la liste des inscrits.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Conversion\Stats;
use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Affiche la valeur des listes d'attente au-dessus de la liste des inscrits.
 *
 * Le rendu passe par `views_edit-{post_type}` plutôt que par `admin_notices` :
 * ce dernier fait transiter le HTML par `wp_kses_post()`, qui retire
 * silencieusement les attributs `aria-*` de valeur et les éléments `svg`. Ce
 * hook place en outre le bandeau à sa vraie place — sous le titre de l'écran,
 * au-dessus des filtres de statut — et n'existe que sur cet écran, ce qui
 * dispense de tester où l'on se trouve.
 */
final class StatsBanner {

	/**
	 * Paramètre d'URL demandant un recalcul.
	 */
	private const REFRESH_ARG = 'ebisn_refresh_stats';

	/**
	 * Action du nonce de recalcul.
	 */
	private const REFRESH_NONCE = 'ebisn_refresh_stats';

	/**
	 * Accroche l'affichage et les ressources.
	 */
	public function register(): void {
		add_filter( 'views_' . Host::LIST_SCREEN_ID, array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Charge la feuille de styles sur le seul écran concerné.
	 *
	 * Le test porte sur l'identifiant d'écran, et non sur `$hook_suffix` : ce
	 * dernier vaut `edit.php` pour TOUS les types de contenu, si bien que la
	 * feuille serait chargée sur chaque liste d'articles du site.
	 */
	public function enqueue(): void {
		$screen = get_current_screen();

		if ( ! $screen || Host::LIST_SCREEN_ID !== $screen->id ) {
			return;
		}

		wp_enqueue_style(
			'ebisn-admin',
			EBISN_URL . 'assets/css/admin.css',
			array(),
			EBISN_VERSION
		);
	}

	/**
	 * Insère le bandeau avant les liens de filtrage par statut.
	 *
	 * @param array<string, string> $views Liens de filtrage.
	 *
	 * @return array<string, string>
	 */
	public function render( $views ): array {
		$views = (array) $views;

		// Le filtre peut être appliqué plusieurs fois sur un même écran ; le
		// bandeau, lui, ne doit s'afficher qu'une fois.
		static $rendered = false;

		if ( $rendered ) {
			return $views;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) || ! function_exists( 'wc_price' ) ) {
			return $views;
		}

		$rendered = true;

		$stats = Stats::get( $this->refresh_requested() );

		// Le bandeau est émis directement : `views_*` attend des éléments de
		// liste, y injecter une grille produirait un balisage invalide.
		echo $this->markup( $stats ); // phpcs:ignore WordPress.Security.EscapeOutputWithCast.OutputNotEscaped, WordPress.Security.EscapeOutput.OutputNotEscaped -- markup() échappe chaque valeur qu'il compose.

		return $views;
	}

	/**
	 * Un recalcul a-t-il été demandé ?
	 *
	 * @return bool
	 */
	private function refresh_requested(): bool {
		if ( ! isset( $_GET[ self::REFRESH_ARG ], $_GET['_wpnonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
			self::REFRESH_NONCE
		);
	}

	/**
	 * Compose le bandeau.
	 *
	 * @param array<string, mixed> $stats Indicateurs.
	 *
	 * @return string HTML entièrement échappé.
	 */
	private function markup( array $stats ): string {
		$pending   = $stats['pending'];
		$notified  = $stats['notified'];
		$recovered = $stats['recovered'];
		$rate      = (float) $stats['rate'];

		$cards = array(
			$this->card(
				__( 'En attente de réassort', 'extender-for-back-in-stock-notifier' ),
				wc_price( $pending['value'] ),
				$this->volume_hint( $pending ),
				'pending'
			),
			$this->card(
				__( 'Non récupéré', 'extender-for-back-in-stock-notifier' ),
				wc_price( $notified['value'] ),
				$this->volume_hint( $notified ),
				'lost'
			),
			$this->card(
				__( 'Récupéré — produit attendu', 'extender-for-back-in-stock-notifier' ),
				wc_price( $recovered['strict'] ),
				sprintf(
					/* translators: 1: nombre de lignes de commande, 2: nombre de conversions. */
					esc_html__( '%1$s ligne(s) de commande · %2$s conversion(s)', 'extender-for-back-in-stock-notifier' ),
					esc_html( number_format_i18n( $recovered['lines'] ) ),
					esc_html( number_format_i18n( $recovered['subs'] ) )
				),
				'won'
			),
			$this->card(
				__( 'Récupéré — commandes entières', 'extender-for-back-in-stock-notifier' ),
				wc_price( $recovered['broad'] ),
				sprintf(
					/* translators: 1: nombre de commandes, 2: montant du panier moyen. */
					esc_html__( '%1$s commande(s) · panier moyen %2$s', 'extender-for-back-in-stock-notifier' ),
					esc_html( number_format_i18n( $recovered['orders'] ) ),
					wp_kses_post(
						wc_price( $recovered['orders'] > 0 ? $recovered['broad'] / $recovered['orders'] : 0 )
					)
				),
				'total'
			),
		);

		return '<div class="ebisn-stats">'
			. '<h2 class="screen-reader-text">'
			. esc_html__( 'Valeur des listes d’attente', 'extender-for-back-in-stock-notifier' )
			. '</h2>'
			. '<ul class="ebisn-stats__grid">' . implode( '', $cards ) . '</ul>'
			. $this->rate_row( $rate, (int) $recovered['subs'], (int) $stats['opportunity'] )
			. $this->footnotes( $stats )
			. '</div>';
	}

	/**
	 * Compose une carte d'indicateur.
	 *
	 * @param string $label    Libellé.
	 * @param string $value    Valeur, déjà formatée (HTML de `wc_price`).
	 * @param string $hint     Précision sous la valeur, déjà échappée.
	 * @param string $modifier Suffixe de classe CSS.
	 *
	 * @return string
	 */
	private function card( string $label, string $value, string $hint, string $modifier ): string {
		return sprintf(
			'<li class="ebisn-stat ebisn-stat--%1$s">'
				. '<span class="ebisn-stat__label">%2$s</span>'
				. '<strong class="ebisn-stat__value">%3$s</strong>'
				. '<span class="ebisn-stat__hint">%4$s</span>'
			. '</li>',
			esc_attr( $modifier ),
			esc_html( $label ),
			wp_kses_post( $value ),
			$hint
		);
	}

	/**
	 * Précision de volumétrie sous une valeur catalogue.
	 *
	 * @param array{subs:int, products:int, units:int} $bucket Compteurs.
	 *
	 * @return string
	 */
	private function volume_hint( array $bucket ): string {
		return sprintf(
			/* translators: 1: nombre d'inscriptions, 2: nombre de produits, 3: nombre d'unités. */
			esc_html__( '%1$s inscription(s) · %2$s produit(s) · %3$s unité(s)', 'extender-for-back-in-stock-notifier' ),
			esc_html( number_format_i18n( $bucket['subs'] ) ),
			esc_html( number_format_i18n( $bucket['products'] ) ),
			esc_html( number_format_i18n( $bucket['units'] ) )
		);
	}

	/**
	 * Compose la ligne du taux de conversion.
	 *
	 * La jauge est purement décorative — `aria-hidden` — parce que la même
	 * information figure juste à côté en texte lisible. C'est le motif retenu
	 * par l'écran « Santé du site » de WordPress.
	 *
	 * @param float $rate        Taux, en pourcentage.
	 * @param int   $converted   Nombre de conversions.
	 * @param int   $opportunity Nombre d'inscrits ayant pu commander.
	 *
	 * @return string
	 */
	private function rate_row( float $rate, int $converted, int $opportunity ): string {
		$width = max( 0.0, min( 100.0, $rate ) );

		return sprintf(
			'<div class="ebisn-stats__rate">'
				. '<div class="ebisn-stats__rate-head">'
					. '<span class="ebisn-stat__label">%1$s</span>'
					. '<strong class="ebisn-stat__value">%2$s&nbsp;%%</strong>'
				. '</div>'
				. '<div class="ebisn-meter" aria-hidden="true"><span class="ebisn-meter__fill" style="width:%3$s%%"></span></div>'
				. '<span class="ebisn-stat__hint">%4$s</span>'
			. '</div>',
			esc_html__( 'Taux de conversion', 'extender-for-back-in-stock-notifier' ),
			esc_html( number_format_i18n( $rate, 1 ) ),
			// `number_format()` impose le point décimal : jusqu'à PHP 7.4, un
			// transtypage suit LC_NUMERIC et produirait une largeur invalide.
			esc_attr( number_format( $width, 2, '.', '' ) ),
			sprintf(
				/* translators: 1: nombre de commandes, 2: nombre d'inscrits notifiés. */
				esc_html__( '%1$s commande(s) sur %2$s inscrit(s) notifié(s)', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $converted ) ),
				esc_html( number_format_i18n( $opportunity ) )
			)
		);
	}

	/**
	 * Compose les réserves de lecture et le lien de recalcul.
	 *
	 * @param array<string, mixed> $stats Indicateurs.
	 *
	 * @return string
	 */
	private function footnotes( array $stats ): string {
		$notes    = array();
		$orphans  = (int) $stats['pending']['orphans'] + (int) $stats['notified']['orphans'];
		$no_price = (int) $stats['pending']['no_price'] + (int) $stats['notified']['no_price'];

		if ( $orphans > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: nombre d'inscriptions. */
				esc_html__( '%s inscription(s) sur un produit supprimé, exclues', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $orphans ) )
			);
		}

		if ( $no_price > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: nombre d'inscriptions. */
				esc_html__( '%s inscription(s) sur un produit sans prix, exclues', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $no_price ) )
			);
		}

		if ( (int) $stats['recovered']['unlinked'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: nombre de conversions. */
				esc_html__( '%s conversion(s) sans commande rattachée, hors chiffre d’affaires', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $stats['recovered']['unlinked'] ) )
			);
		}

		if ( (int) $stats['recovered']['missing'] > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: nombre de conversions. */
				esc_html__( '%s conversion(s) dont la commande a été supprimée, hors chiffre d’affaires', 'extender-for-back-in-stock-notifier' ),
				esc_html( number_format_i18n( $stats['recovered']['missing'] ) )
			);
		}

		$notes[] = esc_html__( 'taux calculé sur les seuls inscrits notifiés', 'extender-for-back-in-stock-notifier' );

		$refresh = sprintf(
			'<a href="%1$s" class="ebisn-stats__refresh">%2$s</a>',
			esc_url( wp_nonce_url( add_query_arg( self::REFRESH_ARG, '1' ), self::REFRESH_NONCE ) ),
			esc_html__( 'Recalculer', 'extender-for-back-in-stock-notifier' )
		);

		return '<p class="ebisn-stats__notes">' . implode( ' · ', $notes ) . ' · ' . $refresh . '</p>';
	}
}
