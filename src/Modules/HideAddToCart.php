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
 * produit et demander à être prévenu de son retour.
 *
 * Deux mécanismes complémentaires, parce qu'aucun ne suffit seul :
 *
 * 1. Une règle de style sur `woocommerce-variation-add-to-cart-disabled`, la
 *    classe que WooCommerce pose dès qu'une déclinaison n'est pas achetable.
 *    Purement déclarative, elle ne dépend d'aucun script — donc fonctionne même
 *    sur les thèmes qui n'émettent pas l'événement `show_variation`.
 * 2. Un drapeau posé côté serveur d'après le balisage RÉELLEMENT produit par
 *    l'extension hôte, appliqué par un script. Il rattrape les thèmes qui
 *    redessinent le bloc sans reprendre la classe de WooCommerce, et atteint
 *    la quantité ou le bouton sortis de leur conteneur d'origine.
 *
 * Le second mécanisme reste le plus juste : l'hôte n'affiche pas son formulaire
 * dans tous les cas de rupture, et l'affiche parfois hors rupture. Ses
 * conditions sont au nombre d'une dizaine (catégories, étiquettes, prix,
 * réassort, visiteurs connectés ou non, produits exclus…) et les redupliquer
 * garantissait de diverger tôt ou tard.
 *
 * Désactivé par défaut : le module modifie une page publique, ce qu'aucune mise
 * à jour ne devrait faire sans qu'on l'ait demandé.
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
	 * Empreintes du balisage produit par l'extension hôte, ou par ce plugin.
	 *
	 * Trois cas : le formulaire d'inscription, le bouton d'ouverture de la
	 * fenêtre modale, et notre encart de désabonnement.
	 */
	private const MARKERS = array(
		'cwginstock-subscribe-form',
		'cwg_popup_submit',
		'ebisn-unsubscribe',
	);

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
		 * Priorité 1000 : après le 999 de l'extension hôte, qui injecte là son
		 * formulaire dans `availability_html`. C'est ce HTML que l'on inspecte.
		 */
		add_filter( 'woocommerce_available_variation', array( $this, 'flag_variation' ), 1000, 3 );

		// Priorité 1 : avant le rendu du résumé produit.
		add_action( 'woocommerce_before_single_product_summary', array( $this, 'maybe_remove_simple_form' ), 1 );
	}

	/**
	 * Charge les ressources sur les fiches produit.
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

		wp_enqueue_script(
			'ebisn-hide-add-to-cart',
			EBISN_URL . 'assets/js/hide-add-to-cart.js',
			array( 'jquery' ),
			EBISN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Signale au script les déclinaisons proposant une alerte.
	 *
	 * @param array<string, mixed> $data      Données de la déclinaison.
	 * @param mixed                $product   Produit parent.
	 * @param mixed                $variation Déclinaison.
	 *
	 * @return array<string, mixed>
	 */
	public function flag_variation( $data, $product, $variation ): array {
		unset( $product, $variation );

		$data = (array) $data;

		$html = isset( $data['availability_html'] ) ? (string) $data['availability_html'] : '';

		$data['ebisn_alert'] = self::contains_alert( $html );

		return $data;
	}

	/**
	 * Le balisage contient-il une alerte de retour en stock ?
	 *
	 * @param string $html Balisage à inspecter.
	 *
	 * @return bool
	 */
	private static function contains_alert( string $html ): bool {
		if ( '' === $html ) {
			return false;
		}

		foreach ( self::MARKERS as $marker ) {
			if ( false !== strpos( $html, $marker ) ) {
				return true;
			}
		}

		/**
		 * Balisage d'une déclinaison portant une alerte de retour en stock.
		 *
		 * Point d'extension pour un gabarit d'alerte entièrement réécrit, dont
		 * aucune des empreintes connues ne subsisterait.
		 *
		 * @param bool   $found Résultat de la détection.
		 * @param string $html  Balisage inspecté.
		 */
		return (bool) apply_filters( 'ebisn_variation_has_alert', false, $html );
	}

	/**
	 * Retire le formulaire d'ajout au panier d'un produit simple en réassort.
	 *
	 * Un produit simple en rupture n'affiche déjà rien : WooCommerce s'en
	 * charge. Reste le cas des commandes en attente de réapprovisionnement, où
	 * le produit demeure achetable — et où l'extension hôte propose malgré tout
	 * son alerte si le marchand l'a réglé ainsi.
	 */
	public function maybe_remove_simple_form(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || $product->is_type( 'variable' ) ) {
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
