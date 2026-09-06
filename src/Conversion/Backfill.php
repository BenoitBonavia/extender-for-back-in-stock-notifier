<?php
/**
 * Rattrapage des achats déjà passés.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Support\BatchJob;
use EBISN\Support\BatchRunner;
use EBISN\Support\JobState;

defined( 'ABSPATH' ) || exit;

/**
 * Rejoue la détection d'achat sur tout l'historique, en tâche de fond.
 *
 * Le parcours va des inscriptions vers les commandes, et non l'inverse. Les
 * snippets balayaient l'historique des commandes en interrogeant les
 * inscriptions pour chacune — donc en examinant l'écrasante majorité de
 * commandes qui ne concernent aucune attente, et en sauvegardant chacune
 * d'elles pour y poser un marqueur. Ici, on n'examine que les inscriptions
 * réellement convertibles, et aucune commande n'est modifiée.
 */
final class Backfill implements BatchJob {

	/**
	 * Identifiant du travail.
	 */
	public const JOB_ID = 'purchase_backfill';

	/**
	 * Hook Action Scheduler d'une étape.
	 */
	public const HOOK_STEP = 'ebisn_purchase_backfill_step';

	/**
	 * Nombre d'inscriptions examinées par lot.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Service de conversion.
	 *
	 * @var ConversionService
	 */
	private $conversions;

	/**
	 * Dépôt de lecture des inscriptions.
	 *
	 * @var SubscriptionRepository
	 */
	private $subscriptions;

	/**
	 * Recherche de commandes.
	 *
	 * @var OrderLookup
	 */
	private $orders;

	/**
	 * Constructeur.
	 *
	 * @param ConversionService      $conversions   Service de conversion.
	 * @param SubscriptionRepository $subscriptions Dépôt de lecture.
	 * @param OrderLookup            $orders        Recherche de commandes.
	 */
	public function __construct( ConversionService $conversions, SubscriptionRepository $subscriptions, OrderLookup $orders ) {
		$this->conversions   = $conversions;
		$this->subscriptions = $subscriptions;
		$this->orders        = $orders;
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
	 * Amorce le rattrapage au premier démarrage du plugin.
	 *
	 * `JobState::bootstrap()` s'appuie sur `add_option()`, dont l'échec signifie
	 * « déjà amorcé ». C'est atomique, et cela fonctionne site par site en
	 * multisite — contrairement au hook d'activation, qui ne se déclenche ni
	 * lors d'une mise à jour, ni pour les sites créés ensuite.
	 */
	public function maybe_bootstrap(): void {
		if ( ! JobState::bootstrap( self::JOB_ID, JobState::STATUS_PENDING ) ) {
			return;
		}

		( new BatchRunner( $this ) )->start();
	}

	/**
	 * Relance le travail s'il s'est arrêté sans être terminé.
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
		$batch = $this->subscriptions->get_convertible_batch( $cursor, $limit );

		if ( empty( $batch ) ) {
			return array(
				'cursor'    => $cursor,
				'processed' => 0,
				'affected'  => 0,
				'done'      => true,
			);
		}

		$orders    = $this->load_orders_for_batch( $batch );
		$converted = 0;
		$last_id   = $cursor;

		foreach ( $batch as $subscription ) {
			$last_id = $subscription['id'];

			$order = $this->find_earliest_matching_order( $orders, $subscription );

			if ( null === $order ) {
				continue;
			}

			$done = $this->conversions->convert(
				$subscription['id'],
				$order['id'],
				MetaKeys::SOURCE_BACKFILL,
				$order['created_at']
			);

			if ( $done ) {
				++$converted;
			}
		}

		return array(
			'cursor'    => $last_id,
			'processed' => count( $batch ),
			'affected'  => $converted,
			// Un lot incomplet signale la fin du jeu de données.
			'done'      => count( $batch ) < $limit,
		);
	}

	/**
	 * Charge en une fois les commandes de tous les acheteurs du lot.
	 *
	 * @param array<int, array{id:int, email:string, user_id:int, pid:int, subscribed_at:int}> $batch Lot d'inscriptions.
	 *
	 * @return array<int, array{id:int, email:string, user_id:int, created_at:int, products:int[]}>
	 */
	private function load_orders_for_batch( array $batch ): array {
		$emails   = array();
		$user_ids = array();

		foreach ( $batch as $subscription ) {
			if ( '' !== $subscription['email'] ) {
				$emails[] = $subscription['email'];
			}

			if ( $subscription['user_id'] > 0 ) {
				$user_ids[] = $subscription['user_id'];
			}
		}

		return $this->orders->find_orders_for( $emails, $user_ids );
	}

	/**
	 * Première commande satisfaisant une attente.
	 *
	 * La plus ANCIENNE l'emporte : c'est elle qui a mis fin à l'attente. Les
	 * snippets parcouraient l'historique du plus récent au plus ancien, et
	 * attribuaient donc l'achat à la mauvaise commande quand il y en avait
	 * plusieurs.
	 *
	 * @param array<int, array{id:int, email:string, user_id:int, created_at:int, products:int[]}> $orders       Commandes du lot.
	 * @param array{id:int, email:string, user_id:int, pid:int, subscribed_at:int}                 $subscription Inscription examinée.
	 *
	 * @return array{id:int, email:string, user_id:int, created_at:int, products:int[]}|null
	 */
	private function find_earliest_matching_order( array $orders, array $subscription ) {
		$match = null;

		foreach ( $orders as $order ) {
			if ( ! $this->belongs_to( $order, $subscription ) ) {
				continue;
			}

			if ( ! in_array( $subscription['pid'], $order['products'], true ) ) {
				continue;
			}

			if ( ! OrderMatcher::is_attributable( $subscription['subscribed_at'], $order['created_at'] ) ) {
				continue;
			}

			if ( null === $match || $order['created_at'] < $match['created_at'] ) {
				$match = $order;
			}
		}

		return $match;
	}

	/**
	 * La commande a-t-elle été passée par le titulaire de l'inscription ?
	 *
	 * @param array{id:int, email:string, user_id:int, created_at:int, products:int[]} $order        Commande.
	 * @param array{id:int, email:string, user_id:int, pid:int, subscribed_at:int}     $subscription Inscription.
	 *
	 * @return bool
	 */
	private function belongs_to( array $order, array $subscription ): bool {
		if ( '' !== $subscription['email'] && $subscription['email'] === $order['email'] ) {
			return true;
		}

		// Jamais sur `0` : ce serait faire correspondre tous les visiteurs non connectés.
		return $subscription['user_id'] > 0 && $subscription['user_id'] === $order['user_id'];
	}
}
