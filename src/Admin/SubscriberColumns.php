<?php
/**
 * Colonnes « Alertes » et « Relances » de la liste des inscrits.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Tracking\DeliveryHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Montre, sur chaque ligne, combien d'alertes la personne a reçues et combien de
 * relances ont suivi la dernière.
 *
 * Une inscription peut être notifiée plusieurs fois, après chaque rupture : le
 * nombre d'alertes dit si la personne est fidèle à un produit capricieux, celui
 * des relances de l'alerte en cours dit si elle laisse traîner la dernière.
 */
final class SubscriberColumns {

	/**
	 * Clé de la colonne des alertes.
	 */
	public const COLUMN_ALERTS = 'ebisn_alerts';

	/**
	 * Clé de la colonne des relances.
	 */
	public const COLUMN_REMINDERS = 'ebisn_reminders';

	/**
	 * Accroche les colonnes et leur feuille de styles.
	 */
	public function register(): void {
		add_filter( Host::HOOK_COLUMNS, array( $this, 'add_columns' ), 20 );
		add_action( Host::HOOK_CUSTOM_COLUMN, array( $this, 'render_column' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Charge la feuille de styles sur le seul écran concerné.
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
	 * Insère les deux colonnes après « Mail Sent on ».
	 *
	 * Si l'hôte renomme cette colonne, elles se retrouvent en fin de tableau plutôt
	 * que d'être perdues.
	 *
	 * @param mixed $columns Colonnes de l'hôte.
	 *
	 * @return array<string, string>
	 */
	public function add_columns( $columns ): array {
		$columns = (array) $columns;
		$result  = array();
		$placed  = false;

		foreach ( $columns as $key => $label ) {
			$result[ $key ] = $label;

			if ( Host::COLUMN_MAIL_ON === $key ) {
				$result = $this->with_own_columns( $result );
				$placed = true;
			}
		}

		return $placed ? $result : $this->with_own_columns( $result );
	}

	/**
	 * Ajoute nos deux colonnes à un tableau.
	 *
	 * @param array<string, string> $columns Colonnes.
	 *
	 * @return array<string, string>
	 */
	private function with_own_columns( array $columns ): array {
		$columns[ self::COLUMN_ALERTS ]    = __( 'Alertes', 'extender-for-back-in-stock-notifier' );
		$columns[ self::COLUMN_REMINDERS ] = __( 'Relances', 'extender-for-back-in-stock-notifier' );

		return $columns;
	}

	/**
	 * Dessine une cellule.
	 *
	 * @param mixed $column  Clé de la colonne.
	 * @param mixed $post_id Inscription.
	 */
	public function render_column( $column, $post_id ): void {
		$post_id = (int) $post_id;

		if ( self::COLUMN_ALERTS === $column ) {
			$this->render_alerts( $post_id );
		} elseif ( self::COLUMN_REMINDERS === $column ) {
			$this->render_reminders( $post_id );
		}
	}

	/**
	 * Cellule des alertes : leur nombre, et la date de la dernière.
	 *
	 * @param int $post_id Inscription.
	 */
	private function render_alerts( int $post_id ): void {
		$alerts = DeliveryHistory::alerts( $post_id );

		if ( $alerts['total'] <= 0 ) {
			echo '<span class="ebisn-col__nil">—</span>';

			return;
		}

		printf(
			'<span class="ebisn-col" title="%1$s"><strong>%2$s%3$s</strong>',
			esc_attr( implode( "\n", DeliveryHistory::tooltip_lines( $post_id ) ) ),
			$alerts['estimated'] ? '≈ ' : '',
			esc_html( number_format_i18n( $alerts['total'] ) )
		);

		if ( $alerts['last'] > 0 ) {
			printf(
				'<br><span class="ebisn-col__sub">%s</span>',
				esc_html(
					sprintf(
						/* translators: %s: date de la dernière alerte. */
						__( 'dernière : %s', 'extender-for-back-in-stock-notifier' ),
						DeliveryHistory::date( $alerts['last'] )
					)
				)
			);
		}

		echo '</span>';
	}

	/**
	 * Cellule des relances : celles de l'alerte en cours, le total en dessous.
	 *
	 * @param int $post_id Inscription.
	 */
	private function render_reminders( int $post_id ): void {
		$reminders = DeliveryHistory::reminders( $post_id );

		if ( $reminders['total'] <= 0 ) {
			echo '<span class="ebisn-col__nil">—</span>';

			return;
		}

		printf(
			'<span class="ebisn-col" title="%1$s"><strong>%2$s</strong>',
			esc_attr( implode( "\n", DeliveryHistory::tooltip_lines( $post_id ) ) ),
			esc_html( number_format_i18n( $reminders['current'] ) )
		);

		$sub = array();

		if ( $reminders['total'] > $reminders['current'] ) {
			$sub[] = sprintf(
				/* translators: %s: nombre total de relances. */
				__( 'sur %s au total', 'extender-for-back-in-stock-notifier' ),
				number_format_i18n( $reminders['total'] )
			);
		}

		$sub[] = sprintf(
			/* translators: %s: date de la dernière relance. */
			__( 'dernière : %s', 'extender-for-back-in-stock-notifier' ),
			DeliveryHistory::date( $reminders['last'] )
		);

		printf( '<br><span class="ebisn-col__sub">%s</span></span>', esc_html( implode( ' · ', $sub ) ) );
	}
}
