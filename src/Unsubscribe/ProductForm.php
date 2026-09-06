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
 * Le remplacement s'appuie sur un mécanisme prévu par l'extension hôte : quand
 * `cwginstock_display_subscribe_form` renvoie `false`, celle-ci ne rend pas son
 * formulaire et déclenche `cwginstock_custom_form` à la place — après avoir
 * évalué stock, visibilité, catégories, étiquettes et réassort. Aucune de ces
 * règles n'est donc à redupliquer, et le procédé vaut aussi bien pour un
 * produit simple que pour une déclinaison.
 *
 * Conséquence à connaître : l'encart n'apparaît que là où l'hôte afficherait
 * son formulaire, donc sur un produit indisponible. Un produit revenu en stock
 * n'affiche plus rien, et le lien reçu par e-mail devient le seul recours.
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
		add_filter( Host::HOOK_DISPLAY_FORM, array( $this, 'hide_subscribe_form' ), 20, 3 );
		add_action( Host::HOOK_CUSTOM_FORM, array( $this, 'render' ), 10, 2 );

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
			'<p class="ebisn-unsubscribe__hint"><strong>%1$s</strong> %2$s</p>',
			esc_html__( 'Extender — visible par vous seul·e :', 'extender-for-back-in-stock-notifier' ),
			esc_html( $this->locator->explain( $target ) )
		);
	}

	/**
	 * Masque le formulaire d'inscription si le visiteur est déjà inscrit.
	 *
	 * @param bool                               $display   Décision des filtres précédents.
	 * @param \WC_Product                        $product   Produit affiché.
	 * @param \WC_Product_Variation|array<mixed> $variation Déclinaison affichée.
	 *
	 * @return bool
	 */
	public function hide_subscribe_form( $display, $product, $variation ): bool {
		if ( ! $display ) {
			return false;
		}

		$target = $this->target_id( $product, $variation );

		if ( $target <= 0 ) {
			return true;
		}

		$this->found[ $target ] = $this->locator->find_for_current_visitor( $target );

		return empty( $this->found[ $target ] );
	}

	/**
	 * Affiche l'encart, à la place du formulaire.
	 *
	 * @param \WC_Product                        $product   Produit affiché.
	 * @param \WC_Product_Variation|array<mixed> $variation Déclinaison affichée.
	 */
	public function render( $product, $variation ): void {
		$target = $this->target_id( $product, $variation );

		if ( $target <= 0 || empty( $this->found[ $target ] ) ) {
			return;
		}

		$subscription_id = (int) $this->found[ $target ][0];

		$confirmation = $this->needs_confirmation()
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

	/**
	 * Faut-il demander confirmation avant de désabonner ?
	 *
	 * @return bool
	 */
	private function needs_confirmation(): bool {
		return Settings::get_bool( 'unsubscribe_confirm', false );
	}

	/**
	 * Identifiant du produit attendu, tel que l'hôte l'enregistre.
	 *
	 * La déclinaison quand il y en a une, le produit sinon — c'est la
	 * convention de `cwginstock_pid`.
	 *
	 * @param mixed $product   Produit affiché.
	 * @param mixed $variation Déclinaison affichée.
	 *
	 * @return int
	 */
	private function target_id( $product, $variation ): int {
		if ( $variation instanceof \WC_Product_Variation ) {
			return $variation->get_id();
		}

		return $product instanceof \WC_Product ? $product->get_id() : 0;
	}
}
