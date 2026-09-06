<?php
/**
 * Module masquant l'ajout au panier des produits indisponibles.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Retire le sélecteur de quantité et le bouton d'ajout au panier là où une
 * alerte de retour en stock est proposée.
 *
 * Afficher les deux n'a pas de sens : on ne peut pas à la fois commander un
 * produit et demander à être prévenu de son retour. Sur un produit décliné, le
 * bloc reste pourtant visible — souvent grisé — alors que la seule action
 * possible est de s'inscrire à l'alerte.
 *
 * Désactivé par défaut : le module modifie une page publique, ce qu'aucune
 * mise à jour ne devrait faire sans qu'on l'ait demandé.
 */
final class HideAddToCart extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'hide_add_to_cart';

	/**
	 * {@inheritDoc}
	 *
	 * @var bool
	 */
	protected $enabled_by_default = false;

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Masquer l’ajout au panier quand une alerte est proposée', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		/*
		 * Priorité 1 : le retrait doit précéder le rendu du résumé produit, où
		 * WooCommerce déclenche `woocommerce_{type}_add_to_cart`.
		 */
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'maybe_remove_simple_form' ), 1 );
	}

	/**
	 * Charge la feuille de styles sur les fiches produit.
	 *
	 * Le cas des déclinaisons se traite entièrement en CSS : WooCommerce marque
	 * déjà le bloc d'ajout au panier d'une classe dédiée dès que la déclinaison
	 * choisie n'est pas achetable.
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_style(
			'ebisn-hide-add-to-cart',
			EBISN_URL . 'assets/css/hide-add-to-cart.css',
			array(),
			EBISN_VERSION
		);
	}

	/**
	 * Retire le formulaire d'ajout au panier d'un produit simple en réassort.
	 *
	 * Un produit simple en rupture n'affiche déjà rien : WooCommerce s'en
	 * charge. Reste le cas des commandes en attente de réapprovisionnement, où
	 * le produit demeure achetable — et où l'extension hôte propose malgré tout
	 * son alerte si le marchand l'a réglé ainsi. C'est ce cas, et lui seul, qui
	 * demande une intervention côté serveur.
	 */
	public function maybe_remove_simple_form(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! $product->is_type( 'simple' ) ) {
			return;
		}

		if ( ! $product->is_on_backorder( 1 ) || ! $this->host_shows_alert_on_backorder() ) {
			return;
		}

		remove_action( 'woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );
	}

	/**
	 * L'extension hôte propose-t-elle son alerte sur les réassorts ?
	 *
	 * @return bool
	 */
	private function host_shows_alert_on_backorder(): bool {
		$settings = Host::settings();

		return isset( $settings['show_on_backorders'] )
			&& '1' === (string) $settings['show_on_backorders'];
	}
}
