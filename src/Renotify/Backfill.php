<?php
/**
 * Rattrapage des inscriptions notifiées restées bloquées.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Renotify;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\BatchJob;
use EBISN\Support\BatchRunner;
use EBISN\Support\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Remet en attente les inscriptions déjà notifiées dont le produit est de
 * nouveau indisponible.
 *
 * Ces personnes sont dans une impasse : prévenues une fois, elles ne le seront
 * plus jamais alors que le produit est reparti. Le rattrapage les réintègre au
 * cycle.
 */
final class Backfill implements BatchJob {

	/**
	 * Identifiant du travail.
	 */
	public const JOB_ID = 'renotify_backfill';

	/**
	 * Hook Action Scheduler d'une étape.
	 */
	public const HOOK_STEP = 'ebisn_renotify_backfill_step';

	/**
	 * Nombre d'inscriptions examinées par lot.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Service de remise en attente.
	 *
	 * @var RenotifyService
	 */
	private $service;

	/**
	 * Lectures d'inscriptions.
	 *
	 * @var SubscriptionQuery
	 */
	private $query;

	/**
	 * Produits dont le compteur d'inscrits reste à recalculer.
	 *
	 * @var array<int, true>
	 */
	private $touched = array();

	/**
	 * Constructeur.
	 *
	 * @param RenotifyService   $service Service de remise en attente.
	 * @param SubscriptionQuery $query   Lectures d'inscriptions.
	 */
	public function __construct( RenotifyService $service, SubscriptionQuery $query ) {
		$this->service = $service;
		$this->query   = $query;
	}

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
	 * Accroche les hooks du rattrapage.
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
	 * Amorce le rattrapage la première fois que le module tourne.
	 */
	public function maybe_bootstrap(): void {
		if ( ! JobState::bootstrap( self::JOB_ID, JobState::STATUS_PENDING ) ) {
			return;
		}

		( new BatchRunner( $this ) )->start();
	}

	/**
	 * Relance le rattrapage depuis le début.
	 */
	public function restart(): void {
		( new BatchRunner( $this ) )->start( true );
	}

	/**
	 * Relance le travail s'il s'est interrompu.
	 */
	public function revive_if_stalled(): void {
		( new BatchRunner( $this ) )->revive_if_stalled();
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
		$batch = $this->query->notified_batch( $cursor, $limit );

		if ( empty( $batch ) ) {
			$this->flush_counters();

			return array(
				'cursor'    => $cursor,
				'processed' => 0,
				'affected'  => 0,
				'done'      => true,
			);
		}

		$renotified = 0;
		$last_id    = $cursor;

		foreach ( $batch as $row ) {
			$last_id = $row['id'];

			if ( ! SubscriptionQuery::is_out_of_stock( $row['pid'] ) ) {
				continue;
			}

			if ( $this->service->renotify( $row['id'] ) ) {
				++$renotified;

				$parent = (int) get_post_meta( $row['id'], Host::META_PRODUCT_ID, true );

				if ( $parent > 0 ) {
					$this->touched[ $parent ] = true;
				}
			}
		}

		$done = count( $batch ) < $limit;

		/*
		 * Les compteurs de l'hôte sont recalculés une fois par produit et par
		 * lot : un même produit revient dans des dizaines d'inscriptions, et son
		 * décompte est une requête agrégée. Attendre la fin du parcours pour
		 * tout vider les perdrait — chaque lot s'exécute dans sa propre requête,
		 * donc sur une nouvelle instance.
		 */
		$this->flush_counters();

		return array(
			'cursor'    => $last_id,
			'processed' => count( $batch ),
			'affected'  => $renotified,
			'done'      => $done,
		);
	}

	/**
	 * Recalcule les compteurs d'inscrits des produits touchés.
	 */
	private function flush_counters(): void {
		foreach ( array_keys( $this->touched ) as $product_id ) {
			Host::refresh_subscriber_count( (int) $product_id );
		}

		$this->touched = array();
	}
}
