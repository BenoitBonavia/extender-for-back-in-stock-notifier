<?php
/**
 * Observation des remises en rupture.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Renotify;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Remet en attente les inscrits déjà notifiés dès qu'un produit repart en
 * rupture.
 *
 * Rien n'est traité dans la requête qui déclenche le hook : une rupture survient
 * le plus souvent au moment où une commande décrémente le stock, donc dans le
 * tunnel de paiement ou dans un appel de la passerelle. Remettre là quatre cents
 * inscriptions en attente ferait dépasser le délai — et la passerelle rejouerait
 * son appel.
 */
final class StockWatcher {

	/**
	 * Hook Action Scheduler traitant un produit repassé en rupture.
	 */
	public const HOOK_PROCESS = 'ebisn_renotify_product';

	/**
	 * Nombre d'inscriptions traitées par passage.
	 *
	 * Au-delà, le traitement se replanifie : un produit à plusieurs milliers
	 * d'inscrits ne doit pas monopoliser un exécutant.
	 */
	private const BATCH = 200;

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
	 * Accroche les hooks.
	 */
	public function register(): void {
		add_action( Host::HOOK_BEFORE_TRIGGER_STATUS, array( $this, 'on_stock_status' ), 10, 2 );
		add_action( self::HOOK_PROCESS, array( $this, 'process_product' ), 10, 1 );
	}

	/**
	 * Réagit à un changement de statut de stock.
	 *
	 * @param int    $product_id   Produit ou déclinaison concerné.
	 * @param string $stock_status Nouveau statut de stock.
	 */
	public function on_stock_status( $product_id, $stock_status ): void {
		if ( Host::STOCK_OUT !== (string) $stock_status ) {
			return;
		}

		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		// `$unique` : une même rupture peut être signalée plusieurs fois dans la
		// même requête, notamment quand plusieurs lignes de commande touchent au
		// même produit.
		Scheduler::enqueue( self::HOOK_PROCESS, $product_id, true );
	}

	/**
	 * Remet en attente les inscrits d'un produit. Point d'entrée du hook différé.
	 *
	 * @param int $product_id Produit ou déclinaison.
	 */
	public function process_product( $product_id ): void {
		$product_id = (int) $product_id;

		if ( $product_id <= 0 ) {
			return;
		}

		/*
		 * Le produit a pu revenir en stock entre la mise en file et son
		 * traitement — un réapprovisionnement corrigé dans la foulée, par
		 * exemple. Remettre alors les inscriptions en attente déclencherait un
		 * envoi immédiat, pour un produit qui n'a jamais vraiment manqué.
		 */
		if ( ! SubscriptionQuery::is_out_of_stock( $product_id ) ) {
			return;
		}

		$ids        = $this->query->notified_for_product( $product_id, self::BATCH );
		$renotified = 0;

		foreach ( $ids as $subscription_id ) {
			if ( $this->service->renotify( $subscription_id ) ) {
				++$renotified;
			}
		}

		if ( $renotified > 0 ) {
			Host::refresh_subscriber_count( $this->parent_of( $product_id ) );

			Logger::info(
				sprintf(
					'Produit #%1$d repassé en rupture : %2$d inscription(s) remise(s) en attente.',
					$product_id,
					$renotified
				)
			);
		}

		/*
		 * Lot plein ET intégralement traité : d'autres inscriptions attendent
		 * probablement leur tour. Le tri étant croissant et sans curseur, une
		 * inscription qui n'a pas bougé reviendrait en tête du lot suivant : se
		 * replanifier après un échec tournerait en rond indéfiniment.
		 */
		if ( count( $ids ) >= self::BATCH && count( $ids ) === $renotified ) {
			Scheduler::schedule( time() + 30, self::HOOK_PROCESS, $product_id, true );
		}
	}

	/**
	 * Produit parent d'une déclinaison, ou le produit lui-même.
	 *
	 * Le compteur d'inscrits de l'hôte est porté par le parent.
	 *
	 * @param int $product_id Produit ou déclinaison.
	 *
	 * @return int
	 */
	private function parent_of( int $product_id ): int {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return $product_id;
		}

		$parent = (int) $product->get_parent_id();

		return $parent > 0 ? $parent : $product_id;
	}
}
