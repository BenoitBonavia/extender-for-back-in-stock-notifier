<?php
/**
 * Encart de désabonnement sur la fiche produit.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Remplace le formulaire d'inscription par un bouton de désabonnement.
 *
 * Le remplacement se fait en substituant le GABARIT, et non en demandant à
 * l'extension hôte de ne pas afficher son formulaire. Ce détour n'en est pas
 * un : l'hôte rend son formulaire par dix chemins différents — produit simple,
 * déclinaison, produit groupé, shortcode, et six compatibilités avec des
 * extensions tierces — dont plusieurs ne consultent jamais son filtre
 * `cwginstock_display_subscribe_form`. Le shortcode notamment appelle
 * `display_subscribe_box()` sans son troisième paramètre, donc avec l'affichage
 * forcé.
 *
 * Tous, en revanche, instancient `CWG_Template`, et passent donc par
 * `cwginstock_locate_template`. C'est le seul point commun aux dix.
 *
 * L'encart hérite ainsi de toutes les conditions de l'hôte — stock, visibilité,
 * catégories, étiquettes, réassort — puisqu'il s'affiche exactement là où le
 * formulaire se serait affiché. Conséquence à connaître : sur un produit revenu
 * en stock, l'hôte ne rend rien, et le lien reçu par e-mail devient le seul
 * recours.
 */
final class ProductForm {

	/**
	 * Action AJAX de désabonnement.
	 */
	public const ACTION = 'ebisn_unsubscribe';

	/**
	 * Action du nonce.
	 */
	private const NONCE = 'ebisn_unsubscribe';

	/**
	 * Gabarit du formulaire d'inscription de l'extension hôte.
	 */
	private const HOST_TEMPLATE = 'default-form.php';

	/**
	 * Notre gabarit de remplacement.
	 */
	private const OWN_TEMPLATE = 'unsubscribe.php';

	/**
	 * Service de désabonnement.
	 *
	 * @var UnsubscribeService
	 */
	private $service;

	/**
	 * Reconnaissance du visiteur.
	 *
	 * @var SubscriberLocator
	 */
	private $locator;

	/**
	 * Inscriptions trouvées, indexées par produit attendu.
	 *
	 * Mémorisées entre le filtre d'affichage et le rendu : l'hôte appelle les
	 * deux coup sur coup, une seule recherche suffit.
	 *
	 * @var array<int, int[]>
	 */
	private $found = array();

	/**
	 * Décisions prises par le filtre d'affichage, par produit interrogé.
	 *
	 * Sert au diagnostic : comparer ce que le filtre a décidé à ce que la page
	 * affiche réellement est le seul moyen de distinguer « le filtre n'a pas
	 * trouvé » de « le filtre n'a pas été consulté ».
	 *
	 * @var array<int, bool>
	 */
	private $decisions = array();

	/**
	 * Constructeur.
	 *
	 * @param UnsubscribeService $service Service de désabonnement.
	 * @param SubscriberLocator  $locator Reconnaissance du visiteur.
	 */
	public function __construct( UnsubscribeService $service, SubscriberLocator $locator ) {
		$this->service = $service;
		$this->locator = $locator;
	}

	/**
	 * Accroche les hooks.
	 */
	public function register(): void {
		/*
		 * Substitution du gabarit, plutôt que le filtre d'affichage de l'hôte.
		 *
		 * `cwginstock_display_subscribe_form` paraissait le point de greffe
		 * naturel, mais il n'est PAS consulté par tous les chemins de rendu :
		 * le shortcode `[cwginstock_subscribe_form]` appelle
		 * `display_subscribe_box()` sans son troisième paramètre, donc avec
		 * `$display = true` en dur. Un thème plaçant le formulaire par ce
		 * shortcode ne voyait jamais notre encart.
		 *
		 * `cwginstock_locate_template` est en revanche traversé par les dix
		 * chemins de rendu, puisque tous instancient `CWG_Template`. Priorité 20
		 * pour passer après `force_template_from_plugin`, que l'hôte y branche.
		 */
		add_filter( 'cwginstock_locate_template', array( $this, 'swap_template' ), 20, 5 );

		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_ajax' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Rattache toute nouvelle inscription au navigateur qui l'a créée.
		add_action( Host::HOOK_AFTER_INSERT_SUBSCRIBER, array( $this, 'tag_visitor' ), 5, 1 );

		if ( Settings::get_bool( 'unsubscribe_hint', true ) ) {
			add_action( Host::HOOK_AFTER_SUBMIT_BUTTON, array( $this, 'render_hint' ), 99, 2 );
		}
	}

	/**
	 * Explique aux administrateurs pourquoi le bouton ne s'affiche pas.
	 *
	 * Le formulaire d'inscription reste visible quand personne n'a été reconnu,
	 * sans qu'aucune indication ne distingue « ce visiteur n'est pas inscrit »
	 * d'un dysfonctionnement. Cette note lève l'ambiguïté, et n'est visible que
	 * des personnes habilitées à gérer la boutique.
	 *
	 * @param int $product_id   Produit affiché.
	 * @param int $variation_id Déclinaison affichée, `0` si aucune.
	 */
	public function render_hint( $product_id, $variation_id = 0 ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$target = (int) $variation_id > 0 ? (int) $variation_id : (int) $product_id;

		if ( $target <= 0 ) {
			return;
		}

		printf(
			'<p class="ebisn-unsubscribe__hint"><strong>%1$s</strong> %2$s · %3$s</p>',
			esc_html__( 'Extender — visible par vous seul·e :', 'extender-for-back-in-stock-notifier' ),
			esc_html( $this->locator->explain( $target ) ),
			esc_html( $this->explain_decision( $target ) )
		);
	}

	/**
	 * Décrit ce que le filtre d'affichage a décidé pour ce produit.
	 *
	 * @param int $target Produit interrogé par la note.
	 *
	 * @return string
	 */
	private function explain_decision( int $target ): string {
		if ( ! array_key_exists( $target, $this->decisions ) ) {
			return __( 'gabarit non intercepté — ce formulaire n’est pas rendu par l’extension hôte', 'extender-for-back-in-stock-notifier' );
		}

		return $this->decisions[ $target ]
			? __( 'gabarit intercepté : aucune demande à désabonner ici', 'extender-for-back-in-stock-notifier' )
			: __( 'gabarit intercepté et remplacé', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * Substitue notre gabarit au formulaire d'inscription.
	 *
	 * @param string              $template      Gabarit résolu par l'hôte.
	 * @param string              $template_name Nom du gabarit demandé.
	 * @param string              $template_path Dossier de surcharge du thème.
	 * @param string              $default_path  Dossier de gabarits de l'hôte.
	 * @param array<string,mixed> $args          Variables destinées au gabarit.
	 *
	 * @return string
	 */
	public function swap_template( $template, $template_name, $template_path, $default_path, $args ): string {
		unset( $template_path, $default_path );

		if ( self::HOST_TEMPLATE !== $template_name || ! is_array( $args ) ) {
			return (string) $template;
		}

		$target = $this->target_from_args( $args );

		if ( $target <= 0 ) {
			return (string) $template;
		}

		$this->found[ $target ]     = $this->locator->find_for_current_visitor( $target );
		$this->decisions[ $target ] = empty( $this->found[ $target ] );

		if ( $this->decisions[ $target ] ) {
			return (string) $template;
		}

		return self::locate_own_template();
	}

	/**
	 * Chemin de notre gabarit, surcharge du thème comprise.
	 *
	 * @return string
	 */
	private static function locate_own_template(): string {
		$found = locate_template(
			array(
				'extender-for-back-in-stock-notifier/' . self::OWN_TEMPLATE,
				self::OWN_TEMPLATE,
			)
		);

		return '' !== $found ? $found : EBISN_PATH . 'templates/' . self::OWN_TEMPLATE;
	}

	/**
	 * Produit attendu, tel que l'hôte l'enregistre, d'après le contexte du gabarit.
	 *
	 * @param array<string, mixed> $args Variables du gabarit.
	 *
	 * @return int
	 */
	private function target_from_args( array $args ): int {
		$variation_id = isset( $args['variation_id'] ) ? (int) $args['variation_id'] : 0;

		if ( $variation_id > 0 ) {
			return $variation_id;
		}

		return isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
	}

	/**
	 * Affiche l'encart. Appelé par le gabarit.
	 *
	 * @param int $product_id   Produit affiché.
	 * @param int $variation_id Déclinaison affichée, `0` si aucune.
	 */
	public static function render_box( int $product_id, int $variation_id = 0 ): void {
		$target = $variation_id > 0 ? $variation_id : $product_id;

		$found = ( new SubscriberLocator() )->find_for_current_visitor( $target );

		if ( empty( $found ) ) {
			return;
		}

		$subscription_id = (int) $found[0];

		$confirmation = Settings::get_bool( 'unsubscribe_confirm', false )
			? __( 'Ne plus recevoir d’alerte pour ce produit ?', 'extender-for-back-in-stock-notifier' )
			: '';

		printf(
			'<div class="ebisn-unsubscribe" data-subscription="%1$s" data-nonce="%2$s" data-confirm="%3$s">'
				. '<p class="ebisn-unsubscribe__state">%4$s</p>'
				. '<button type="button" class="button ebisn-unsubscribe__button" data-intent="unsubscribe">%5$s</button>'
			. '</div>',
			esc_attr( (string) $subscription_id ),
			esc_attr( wp_create_nonce( self::NONCE . '_' . $subscription_id ) ),
			esc_attr( $confirmation ),
			esc_html( self::subscribed_message() ),
			esc_html( self::button_label() )
		);
	}

	/**
	 * Rattache une nouvelle inscription au navigateur courant.
	 *
	 * @param int $subscription_id Inscription créée.
	 */
	public function tag_visitor( $subscription_id ): void {
		$subscription_id = (int) $subscription_id;

		if ( $subscription_id <= 0 ) {
			return;
		}

		$token = VisitorCookie::ensure();

		if ( '' !== $token ) {
			update_post_meta( $subscription_id, VisitorCookie::META, $token );
		}
	}

	/**
	 * Traite la demande AJAX.
	 */
	public function handle_ajax(): void {
		$subscription_id = isset( $_POST['subscription'] ) ? absint( wp_unslash( $_POST['subscription'] ) ) : 0;
		$intent          = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : 'unsubscribe';

		check_ajax_referer( self::NONCE . '_' . $subscription_id, 'nonce' );

		/*
		 * Le jeton atteste de l'origine de la requête, jamais de l'identité de
		 * qui l'envoie : la possession de l'inscription est revérifiée ici,
		 * systématiquement, avant toute écriture.
		 */
		if ( ! $this->locator->owns( $subscription_id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Cette demande ne vous appartient pas.', 'extender-for-back-in-stock-notifier' ) ),
				403
			);
		}

		if ( 'resubscribe' === $intent ) {
			$done = $this->service->resubscribe( $subscription_id );

			wp_send_json_success(
				array(
					'state'   => 'subscribed',
					'message' => $done
						? self::subscribed_message()
						: __( 'Impossible de rétablir l’alerte.', 'extender-for-back-in-stock-notifier' ),
					'label'   => self::button_label(),
					'intent'  => 'unsubscribe',
				)
			);
		}

		$done = $this->service->unsubscribe( $subscription_id, 'button' );

		wp_send_json_success(
			array(
				'state'   => 'unsubscribed',
				'message' => $done
					? self::unsubscribed_message()
					: __( 'Le désabonnement n’a pas pu être enregistré.', 'extender-for-back-in-stock-notifier' ),
				'label'   => __( 'Annuler', 'extender-for-back-in-stock-notifier' ),
				'intent'  => 'resubscribe',
			)
		);
	}

	/**
	 * Charge les ressources sur les fiches produit.
	 */
	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		wp_enqueue_style(
			'ebisn-unsubscribe',
			EBISN_URL . 'assets/css/unsubscribe.css',
			array(),
			EBISN_VERSION
		);

		wp_enqueue_script(
			'ebisn-unsubscribe',
			EBISN_URL . 'assets/js/unsubscribe.js',
			array(),
			EBISN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_add_inline_script(
			'ebisn-unsubscribe',
			'window.ebisnUnsubscribe = ' . wp_json_encode(
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'action'  => self::ACTION,
					'error'   => __( 'Une erreur est survenue. Réessayez.', 'extender-for-back-in-stock-notifier' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Libellé du bouton de désabonnement.
	 *
	 * @return string
	 */
	public static function button_label(): string {
		$label = trim( (string) Settings::get( 'unsubscribe_button_label', '' ) );

		if ( '' === $label ) {
			$label = __( 'Ne plus être prévenu·e', 'extender-for-back-in-stock-notifier' );
		}

		/**
		 * Libellé du bouton de désabonnement.
		 *
		 * @param string $label Libellé configuré.
		 */
		return (string) apply_filters( 'ebisn_unsubscribe_button_label', $label );
	}

	/**
	 * Message affiché tant que la personne est inscrite.
	 *
	 * @return string
	 */
	public static function subscribed_message(): string {
		$message = trim( (string) Settings::get( 'unsubscribe_subscribed_message', '' ) );

		return '' !== $message
			? $message
			: __( 'Vous serez prévenu·e dès le retour en stock.', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * Message affiché après désabonnement.
	 *
	 * @return string
	 */
	public static function unsubscribed_message(): string {
		$message = trim( (string) Settings::get( 'unsubscribe_done_message', '' ) );

		return '' !== $message
			? $message
			: __( 'Vous ne serez plus prévenu·e pour ce produit.', 'extender-for-back-in-stock-notifier' );
	}
}
