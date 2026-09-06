<?php
/**
 * Détection des achats au fil de l'eau.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;
use EBISN\Support\Scheduler;

defined( 'ABSPATH' ) || exit;

/**
 * Convertit les inscriptions dès qu'une commande vaut achat, et revient en
 * arrière si cette commande est annulée, remboursée ou supprimée.
 *
 * Aucun travail n'est fait dans la requête qui déclenche le hook : le passage
 * d'une commande en « en cours » survient dans le tunnel de paiement ou dans un
 * appel de la passerelle. Une recherche d'inscriptions y serait au mieux une
 * latence, au pire un dépassement de délai — auquel cas la passerelle rejoue
 * son appel, et le traitement recommence.
 */
final class RealtimeListener {

	/**
	 * Hook Action Scheduler traitant une commande.
	 */
	public const HOOK_CONVERT = 'ebisn_convert_order';

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
	 * Constructeur.
	 *
	 * @param ConversionService      $conversions   Service de conversion.
	 * @param SubscriptionRepository $subscriptions Dépôt de lecture.
	 */
	public function __construct( ConversionService $conversions, SubscriptionRepository $subscriptions ) {
		$this->conversions   = $conversions;
		$this->subscriptions = $subscriptions;
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		/*
		 * Un seul hook générique plutôt qu'un par statut : les snippets
		 * s'accrochaient à `processing` ET `completed`, traitant donc deux fois
		 * la même commande sur un trajet normal, tout en ignorant les statuts
		 * personnalisés qu'ajoutent les extensions de préparation de commande.
		 */
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 3 );

		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 20 );
		add_action( 'woocommerce_trash_order', array( $this, 'on_order_removed' ), 20 );
		add_action( 'woocommerce_delete_order', array( $this, 'on_order_removed' ), 20 );

		add_action( self::HOOK_CONVERT, array( $this, 'process_order' ), 10, 1 );
	}

	/**
	 * Réagit au changement de statut d'une commande.
	 *
	 * @param int    $order_id   Commande.
	 * @param string $old_status Statut précédent, sans préfixe.
	 * @param string $new_status Nouveau statut, sans préfixe.
	 */
	public function on_status_changed( $order_id, $old_status, $new_status ): void {
		$order_id  = (int) $order_id;
		$eligible  = OrderMatcher::order_statuses();
		$was_valid = in_array( (string) $old_status, $eligible, true );
		$is_valid  = in_array( (string) $new_status, $eligible, true );

		if ( $is_valid && ! $was_valid ) {
			Scheduler::enqueue( self::HOOK_CONVERT, $order_id, true );

			return;
		}

		// La commande sort des statuts valant achat : l'achat n'a pas eu lieu.
		if ( $was_valid && ! $is_valid ) {
			$this->revert_order( $order_id, sprintf( 'commande passée en « %s »', (string) $new_status ) );
		}
	}

	/**
	 * Annule les conversions d'une commande supprimée ou mise à la corbeille.
	 *
	 * @param int $order_id Commande.
	 */
	public function on_order_removed( $order_id ): void {
		$this->revert_order( (int) $order_id, 'commande supprimée' );
	}

	/**
	 * Annule les conversions dont le produit a été remboursé.
	 *
	 * Un remboursement est souvent PARTIEL : rembourser un article sur cinq ne
	 * doit pas rétablir les quatre autres attentes. On ne rétablit donc une
	 * inscription que si le produit qu'elle attendait a été remboursé
	 * intégralement.
	 *
	 * @param int $order_id Commande.
	 */
	public function on_order_refunded( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $this->subscriptions->find_converted_for_order( $order->get_id() ) as $subscription_id ) {
			$awaited = (int) get_post_meta( $subscription_id, Host::META_PID, true );

			if ( $awaited > 0 && ! $this->is_fully_refunded( $order, $awaited ) ) {
				continue;
			}

			$this->conversions->revert( $subscription_id, $order->get_id(), 'produit remboursé' );
		}
	}

	/**
	 * Le produit attendu a-t-il été remboursé en totalité ?
	 *
	 * @param \WC_Order $order   Commande.
	 * @param int       $awaited Produit ou variation attendu par l'inscription.
	 *
	 * @return bool Faux si le produit n'apparaît pas dans la commande.
	 */
	private function is_fully_refunded( \WC_Order $order, int $awaited ): bool {
		$found = false;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			if ( (int) $item->get_variation_id() !== $awaited && (int) $item->get_product_id() !== $awaited ) {
				continue;
			}

			$found = true;

			// `get_qty_refunded_for_item()` renvoie une quantité négative.
			$refunded = abs( (int) $order->get_qty_refunded_for_item( $item_id ) );

			if ( $refunded < (int) $item->get_quantity() ) {
				return false;
			}
		}

		return $found;
	}

	/**
	 * Convertit les inscriptions satisfaites par une commande.
	 *
	 * Point d'entrée du hook Action Scheduler : c'est ici, hors du chemin de
	 * paiement, que le travail réel a lieu.
	 *
	 * @param int $order_id Commande.
	 */
	public function process_order( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		// Écarte notamment les WC_Order_Refund, qui n'ont pas d'adresse de facturation.
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$created = $order->get_date_created();

		if ( ! $created instanceof \WC_DateTime ) {
			return;
		}

		$identity   = OrderMatcher::identity( $order );
		$ordered_at = $created->getTimestamp();

		$candidates = $this->subscriptions->find_convertible_for_purchase(
			OrderMatcher::product_ids( $order ),
			$identity['email'],
			$identity['user_id'],
			$ordered_at
		);

		$converted = 0;

		foreach ( $candidates as $subscription_id ) {
			$subscribed_at = (int) get_post_time( 'U', true, $subscription_id );

			if ( ! OrderMatcher::is_attributable( $subscribed_at, $ordered_at ) ) {
				continue;
			}

			if ( $this->conversions->convert( $subscription_id, $order->get_id(), MetaKeys::SOURCE_REALTIME, $ordered_at ) ) {
				++$converted;
			}
		}

		if ( $converted > 0 ) {
			Logger::info(
				sprintf( 'Commande #%1$d : %2$d inscription(s) converties.', $order->get_id(), $converted )
			);
		}
	}

	/**
	 * Rétablit les inscriptions converties par une commande.
	 *
	 * @param int    $order_id Commande.
	 * @param string $reason   Motif, à des fins de journalisation.
	 */
	private function revert_order( int $order_id, string $reason ): void {
		if ( $order_id <= 0 ) {
			return;
		}

		foreach ( $this->subscriptions->find_converted_for_order( $order_id ) as $subscription_id ) {
			$this->conversions->revert( $subscription_id, $order_id, $reason );
		}
	}
}
