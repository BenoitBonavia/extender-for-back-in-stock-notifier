<?php
/**
 * Reprise des métadonnées de conversion posées par les snippets.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Migration;

use EBISN\Conversion\MetaKeys;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\BatchJob;
use EBISN\Support\BatchRunner;
use EBISN\Support\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Transpose les métadonnées `mh_bisn_*` des snippets vers le préfixe du plugin.
 *
 * Par copie puis suppression, lot par lot, et non par un `UPDATE … SET meta_key`
 * global : le travail est ainsi reprenable, et une interruption laisse une base
 * cohérente plutôt qu'à moitié renommée.
 *
 * Une limite est assumée : les snippets n'enregistraient pas le statut
 * d'origine des inscriptions qu'ils convertissaient. Ces conversions héritées
 * sont donc marquées comme telles et resteront **non réversibles** — le
 * reconstituer reviendrait à inventer une donnée.
 */
final class LegacyConversionMeta implements BatchJob {

	/**
	 * Identifiant du travail.
	 */
	public const JOB_ID = 'legacy_conversion_meta';

	/**
	 * Hook Action Scheduler d'une étape.
	 */
	public const HOOK_STEP = 'ebisn_legacy_conversion_meta_step';

	/**
	 * Nombre d'inscriptions traitées par lot.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return self::JOB_ID;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_hook(): string {
		return self::HOOK_STEP;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_batch_size(): int {
		return self::BATCH_SIZE;
	}

	/**
	 * Accroche les hooks de la migration.
	 */
	public function register(): void {
		add_action( self::HOOK_STEP, array( $this, 'run_step' ) );
	}

	/**
	 * Exécute une étape. Point d'entrée du hook Action Scheduler.
	 */
	public function run_step(): void {
		( new BatchRunner( $this ) )->run_step();
	}

	/**
	 * Amorce la migration si des données de snippet subsistent.
	 */
	public function maybe_bootstrap(): void {
		if ( ! JobState::bootstrap( self::JOB_ID, JobState::STATUS_PENDING ) ) {
			return;
		}

		if ( 0 === $this->count_remaining() ) {
			// Rien à reprendre : le travail est terminé avant d'avoir commencé.
			JobState::load( self::JOB_ID )
				->merge( array( 'status' => JobState::STATUS_DONE ) )
				->save();

			return;
		}

		( new BatchRunner( $this ) )->start();
	}

	/**
	 * Relance la migration si son processus a été interrompu.
	 */
	public function revive_if_stalled(): void {
		( new BatchRunner( $this ) )->revive_if_stalled();
	}

	/**
	 * Nombre d'inscriptions portant encore une métadonnée de snippet.
	 *
	 * @return int
	 */
	public function count_remaining(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- comptage ponctuel à l'amorçage de la migration.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				MetaKeys::LEGACY_ORDER_ID
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * Nombre de conversions héritées des snippets, donc non réversibles.
	 *
	 * @return int
	 */
	public static function inherited_count(): int {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- comptage affiché dans le panneau Diagnostic.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				MetaKeys::SOURCE,
				MetaKeys::SOURCE_SNIPPET
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $cursor Dernière inscription traitée.
	 * @param int $limit  Taille du lot.
	 *
	 * @return array{cursor:int, processed:int, affected:int, done:bool}
	 */
	public function process( int $cursor, int $limit ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- parcours par curseur d'un travail de migration.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value AS order_id
				   FROM {$wpdb->postmeta} pm
				  INNER JOIN {$wpdb->posts} p
						  ON p.ID = pm.post_id
						 AND p.post_type = %s
				  WHERE pm.meta_key = %s
					AND pm.post_id > %d
				  ORDER BY pm.post_id ASC
				  LIMIT %d",
				Host::SUBSCRIBER_TYPE,
				MetaKeys::LEGACY_ORDER_ID,
				$cursor,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			return array(
				'cursor'    => $cursor,
				'processed' => 0,
				'affected'  => 0,
				'done'      => true,
			);
		}

		$migrated = 0;
		$last_id  = $cursor;

		foreach ( $rows as $row ) {
			$last_id = (int) $row->post_id;

			if ( $this->migrate_one( $last_id, (int) $row->order_id ) ) {
				++$migrated;
			}
		}

		return array(
			'cursor'    => $last_id,
			'processed' => count( $rows ),
			'affected'  => $migrated,
			'done'      => count( $rows ) < $limit,
		);
	}

	/**
	 * Transpose les métadonnées d'une inscription.
	 *
	 * @param int $subscription_id Inscription.
	 * @param int $order_id        Commande enregistrée par le snippet.
	 *
	 * @return bool
	 */
	private function migrate_one( int $subscription_id, int $order_id ): bool {
		/*
		 * Ne jamais écraser une donnée du plugin : si le module de conversion a
		 * déjà traité cette inscription, sa version est la bonne — elle porte le
		 * statut d'origine, que le snippet ne connaissait pas.
		 */
		$existing = get_post_meta( $subscription_id, MetaKeys::ORDER_ID, true );

		if ( '' === (string) $existing ) {
			update_post_meta( $subscription_id, MetaKeys::ORDER_ID, $order_id );
			update_post_meta( $subscription_id, MetaKeys::SOURCE, MetaKeys::SOURCE_SNIPPET );

			$converted_at = (int) get_post_meta( $subscription_id, MetaKeys::LEGACY_CONVERTED_AT, true );

			if ( $converted_at > 0 ) {
				update_post_meta( $subscription_id, MetaKeys::CONVERTED_AT, $converted_at );
			}

			/*
			 * `_ebisn_previous_status` reste volontairement ABSENTE : les snippets
			 * ne l'enregistraient pas, et `ConversionService::revert()` traite
			 * justement son absence comme « conversion non réversible ».
			 */
		}

		foreach ( MetaKeys::legacy_subscription_keys() as $key ) {
			delete_post_meta( $subscription_id, $key );
		}

		return true;
	}
}
