<?php
/**
 * E-mail de relance : « votre produit est toujours disponible ».
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Settings;
use EBISN\Unsubscribe\EmailLink;

defined( 'ABSPATH' ) || exit;

/**
 * E-mail WooCommerce envoyé à une personne déjà prévenue qui n'a pas commandé.
 *
 * Il apparaît dans WooCommerce → Réglages → E-mails, où le marchand modifie
 * sujet, titre et texte de chaque relance et voit l'aperçu. Deux jeux de champs :
 * le premier pour la relance n° 1, les champs suffixés `_2` pour la n° 2.
 *
 * Ne JAMAIS référencer cette classe avant que `WC_Email` existe : voir
 * `ReminderMailer`.
 */
final class ReminderEmail extends \WC_Email {

	/**
	 * Inscription destinataire, `0` pour l'aperçu de WooCommerce.
	 *
	 * @var int
	 */
	private $subscription_id = 0;

	/**
	 * Rang de la relance en cours de composition.
	 *
	 * @var int
	 */
	private $step = 1;

	/**
	 * Est-ce la dernière relance configurée ?
	 *
	 * @var bool
	 */
	private $is_last = false;

	/**
	 * Substitutions par défaut, pour repartir d'une page blanche entre deux envois.
	 *
	 * @var array<string, string>
	 */
	private $base_placeholders = array();

	/**
	 * Lien de désabonnement de l'envoi en cours.
	 *
	 * @var string
	 */
	private $unsubscribe_url = '';

	/**
	 * Préfixe de l'objet : « [TEST] » pour un e-mail de contrôle, vide sinon.
	 *
	 * @var string
	 */
	private $subject_prefix = '';

	/**
	 * Issue de l'envoi en cours, d'après les événements de `wp_mail()` :
	 * `failed`, `succeeded`, `blocked`, ou chaîne vide si aucun n'a eu lieu.
	 *
	 * @var string
	 */
	private $outcome = '';

	/**
	 * Constructeur.
	 */
	public function __construct() {
		$this->id             = ReminderMailer::EMAIL_ID;
		$this->customer_email = true;
		$this->title          = __( 'Retour en stock — relance', 'extender-for-back-in-stock-notifier' );
		$this->description    = __( 'Envoyé aux personnes déjà prévenues du retour en stock qui n’ont pas commandé, tant que le produit est disponible. Deux relances au plus, chacune avec son propre texte.', 'extender-for-back-in-stock-notifier' );
		$this->template_html  = 'emails/reminder.php';
		$this->template_plain = 'emails/plain/reminder.php';

		$this->placeholders = array(
			'{product_name}'         => '',
			'{only_product_name}'    => '',
			'{product_link}'         => '',
			'{cart_link}'            => '',
			'{product_price}'        => '',
			'{product_image}'        => '',
			'{product_table}'        => '',
			'{stock_left}'           => '',
			'{subscriber_name}'      => '',
			'{subscriber_firstname}' => '',
			'{unsubscribe_url}'      => '',
			'{shopname}'             => $this->get_blogname(),
			'{coupon_box}'           => '',
			'{coupon_code}'          => '',
			'{coupon_amount}'        => '',
			'{coupon_expiry}'        => '',
		);

		parent::__construct();

		$this->template_base     = EBISN_PATH . 'templates/';
		$this->base_placeholders = $this->placeholders;

		add_filter( 'woocommerce_email_preview_placeholders', array( $this, 'preview_placeholders' ), 10, 2 );
	}

	/**
	 * Liste lisible des balises, pour l'aide des champs.
	 *
	 * @return string
	 */
	private function placeholder_help(): string {
		return sprintf(
			/* translators: %s: liste de balises. */
			__( 'Balises disponibles : %s', 'extender-for-back-in-stock-notifier' ),
			'<code>{product_name}</code>, <code>{only_product_name}</code>, <code>{product_link}</code>, <code>{cart_link}</code>, <code>{product_price}</code>, <code>{product_image}</code>, <code>{product_table}</code>, <code>{stock_left}</code>, <code>{subscriber_firstname}</code>, <code>{subscriber_name}</code>, <code>{shopname}</code>, <code>{coupon_box}</code>, <code>{coupon_code}</code>, <code>{coupon_amount}</code>, <code>{coupon_expiry}</code>, <code>{unsubscribe_url}</code>'
		);
	}

	/**
	 * Valeurs par défaut d'un champ, selon le rang de la relance.
	 *
	 * @param string $field `subject`, `heading` ou `additional_content`.
	 * @param int    $step  Rang.
	 *
	 * @return string
	 */
	private function default_for( string $field, int $step ): string {
		$defaults = array(
			1 => array(
				'subject'            => __( 'Toujours disponible : {only_product_name}', 'extender-for-back-in-stock-notifier' ),
				'heading'            => __( '{only_product_name} est toujours disponible', 'extender-for-back-in-stock-notifier' ),
				'additional_content' => __( 'Bonjour,<br/><br/>Nous vous avons prévenu(e) il y a quelques jours : {product_name} est de retour en stock, et il est toujours disponible.<br/><br/><a href="{cart_link}">Ajouter au panier</a><br/><br/>Merci de votre confiance, {shopname}.', 'extender-for-back-in-stock-notifier' ),
			),
			2 => array(
				'subject'            => __( 'Dernière chance pour {only_product_name}', 'extender-for-back-in-stock-notifier' ),
				'heading'            => __( 'Dernière chance pour {only_product_name}', 'extender-for-back-in-stock-notifier' ),
				'additional_content' => __( 'Bonjour,<br/><br/>{product_name} est toujours disponible, mais nous n’avons pas la certitude de pouvoir le réapprovisionner.<br/><br/>{coupon_box}<a href="{cart_link}">Ajouter au panier</a><br/><br/>C’est notre dernier message à ce sujet. À bientôt, {shopname}.', 'extender-for-back-in-stock-notifier' ),
			),
		);

		return $defaults[ $step ][ $field ] ?? '';
	}

	/**
	 * Nom du champ de réglage pour un rang donné.
	 *
	 * @param string $field Champ de base.
	 * @param int    $step  Rang.
	 *
	 * @return string
	 */
	private function field_key( string $field, int $step ): string {
		return $step >= 2 ? $field . '_2' : $field;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_subject() {
		return $this->default_for( 'subject', $this->step );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_heading() {
		return $this->default_for( 'heading', $this->step );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_default_additional_content() {
		return $this->default_for( 'additional_content', $this->step );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_subject() {
		$raw = (string) $this->get_option( $this->field_key( 'subject', $this->step ), $this->get_default_subject() );

		/** Documenté dans WC_Email::get_subject(). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre de WooCommerce, rejoué à l'identique.
		return $this->subject_prefix . apply_filters( 'woocommerce_email_subject_' . $this->id, $this->format_string( $raw ), $this->object, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_heading() {
		$raw = (string) $this->get_option( $this->field_key( 'heading', $this->step ), $this->get_default_heading() );

		/** Documenté dans WC_Email::get_heading(). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre de WooCommerce, rejoué à l'identique.
		return apply_filters( 'woocommerce_email_heading_' . $this->id, $this->format_string( $raw ), $this->object, $this );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_additional_content() {
		$raw = (string) $this->get_option( $this->field_key( 'additional_content', $this->step ), $this->get_default_additional_content() );

		/** Documenté dans WC_Email::get_additional_content(). */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtre de WooCommerce, rejoué à l'identique.
		return apply_filters( 'woocommerce_email_additional_content_' . $this->id, $this->format_string( $raw ), $this->object, $this );
	}

	/**
	 * Envoie une relance.
	 *
	 * @param int  $subscription_id Inscription destinataire.
	 * @param int  $step            Rang de la relance.
	 * @param bool $is_last         Est-ce la dernière relance configurée.
	 *
	 * @return bool|null Vrai si envoyé, faux en cas d'échec, `null` si rien n'a été tenté.
	 */
	public function trigger( $subscription_id = 0, $step = 1, $is_last = false ) {
		$this->setup_locale();

		$this->subscription_id = absint( $subscription_id );
		$this->step            = max( 1, (int) $step );
		$this->is_last         = (bool) $is_last;

		if ( $this->subscription_id <= 0 || ! $this->is_enabled() ) {
			$this->restore_locale();

			return null;
		}

		$this->recipient = (string) get_post_meta( $this->subscription_id, Host::META_EMAIL, true );

		if ( ! is_email( $this->recipient ) ) {
			$this->restore_locale();

			return false;
		}

		$this->populate_placeholders();

		$result = $this->dispatch();

		$this->restore_locale();

		return $result;
	}

	/**
	 * Envoie un e-mail de contrôle, pour juger des modèles avant un envoi réel.
	 *
	 * Sans AUCUN effet de bord : ni compteur, ni méta, ni coupon créé. Le produit et
	 * le prénom viennent d'une inscription réelle, afin que le rendu soit celui
	 * que verra un destinataire ; mais le lien de désabonnement et le coupon sont
	 * factices. Un lien réel désabonnerait la personne dont on emprunte
	 * l'inscription au premier clic de contrôle, et un coupon réel serait créé
	 * pour rien.
	 *
	 * @param int    $step Rang de la relance à prévisualiser.
	 * @param string $to   Destinataire.
	 *
	 * @return bool
	 */
	public function send_test( int $step, string $to ): bool {
		if ( ! is_email( $to ) || ! $this->is_enabled() || null === Host::api() ) {
			return false;
		}

		$this->setup_locale();

		$this->step            = max( 1, min( 2, $step ) );
		$this->is_last         = ReminderService::max_steps() <= $this->step;
		$this->subscription_id = ( new ReminderQuery() )->any_notified();
		$this->recipient       = $to;
		$this->subject_prefix  = __( '[TEST] ', 'extender-for-back-in-stock-notifier' );

		$this->populate_placeholders( true );

		$result = $this->dispatch();

		// L'instance est partagée pour toute la requête : ne rien laisser du test.
		$this->subscription_id = 0;
		$this->unsubscribe_url = '';
		$this->subject_prefix  = '';

		$this->restore_locale();

		return $result;
	}

	/**
	 * Envoie le message composé, et rend l'issue réelle de l'envoi.
	 *
	 * @return bool
	 */
	private function dispatch(): bool {
		$this->outcome = '';

		add_action( 'wp_mail_failed', array( $this, 'catch_mail_failure' ) );
		add_action( 'wp_mail_succeeded', array( $this, 'catch_mail_success' ) );
		add_filter( 'pre_wp_mail', array( $this, 'catch_mail_block' ), PHP_INT_MAX );

		$result = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		remove_action( 'wp_mail_failed', array( $this, 'catch_mail_failure' ) );
		remove_action( 'wp_mail_succeeded', array( $this, 'catch_mail_success' ) );
		remove_filter( 'pre_wp_mail', array( $this, 'catch_mail_block' ), PHP_INT_MAX );

		if ( ! $result ) {
			$result = $this->resolve_empty_result();
		}

		return (bool) $result;
	}

	/**
	 * Tranche quand `send()` ne renvoie pas un succès net.
	 *
	 * Certaines extensions SMTP ou de file d'attente remplacent `wp_mail()` et ne
	 * renvoient rien : les événements observés pendant l'envoi font alors foi.
	 *
	 * @return bool
	 */
	private function resolve_empty_result(): bool {
		$outcome = $this->outcome;

		if ( 'succeeded' === $outcome ) {
			return true;
		}

		if ( 'failed' === $outcome || 'blocked' === $outcome ) {
			return false;
		}

		/** Aucun événement : une extension de file d'attente a sans doute pris le relais, tenu pour envoyé. */
		return (bool) apply_filters( 'ebisn_reminder_assume_sent_on_empty_mail_result', true, $this->subscription_id, $this );
	}

	/**
	 * `wp_mail()` a signalé un échec.
	 *
	 * @param mixed $error Erreur.
	 */
	public function catch_mail_failure( $error ): void {
		unset( $error );

		$this->outcome = 'failed';
	}

	/**
	 * `wp_mail()` a confirmé l'envoi.
	 *
	 * @param mixed $mail_data Arguments de `wp_mail()`.
	 */
	public function catch_mail_success( $mail_data ): void {
		unset( $mail_data );

		$this->outcome = 'succeeded';
	}

	/**
	 * Une extension a court-circuité `wp_mail()`.
	 *
	 * @param mixed $short_circuit Valeur du filtre.
	 *
	 * @return mixed
	 */
	public function catch_mail_block( $short_circuit ) {
		if ( null !== $short_circuit ) {
			$this->outcome = 'blocked';
		}

		return $short_circuit;
	}

	/**
	 * Nettoie une valeur saisie par un visiteur avant de la glisser dans un e-mail.
	 *
	 * Sans balises, et surtout sans crochets : l'hôte a eu une faille où un nom
	 * contenant un shortcode était exécuté à la composition du message.
	 *
	 * @param string $value Valeur brute.
	 *
	 * @return string
	 */
	private function clean( string $value ): string {
		return trim( str_replace( array( '[', ']', '<', '>' ), '', sanitize_text_field( $value ) ) );
	}

	/**
	 * Calcule les substitutions de l'inscription en cours.
	 *
	 * @param bool $test E-mail de contrôle : lien de désabonnement et coupon factices.
	 */
	private function populate_placeholders( bool $test = false ): void {
		$this->placeholders = $this->base_placeholders;

		$id  = $this->subscription_id;
		$api = Host::api();

		// Aucune inscription à emprunter : valeurs d'exemple, comme l'aperçu de WooCommerce.
		if ( $test && $id <= 0 ) {
			$this->placeholders    = array_merge( $this->placeholders, $this->preview_placeholders( array(), '' ) );
			$this->unsubscribe_url = home_url( '/' );

			return;
		}

		$product_id = Host::product_id_for( $id );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( null !== $api ) {
			$this->placeholders['{product_name}']         = wp_strip_all_tags( (string) $api->display_product_name( $id ) );
			$this->placeholders['{only_product_name}']    = wp_strip_all_tags( (string) $api->display_only_product_name( $id ) );
			$this->placeholders['{product_link}']         = Host::utm( (string) $api->display_product_link( $id ), 'reminder_product_link' );
			$this->placeholders['{cart_link}']            = Host::utm( (string) $api->get_cart_link( $id ), 'reminder_cart_link' );
			$this->placeholders['{product_price}']        = html_entity_decode( wp_strip_all_tags( (string) $api->get_product_price( $id ) ), ENT_QUOTES, 'UTF-8' );
			$this->placeholders['{product_image}']        = (string) $api->get_product_image( $id );
			$this->placeholders['{subscriber_name}']      = $this->clean( (string) $api->get_subscriber_name( $id ) );
			$this->placeholders['{subscriber_firstname}'] = $this->clean( (string) $api->get_subscriber_firstname( $id ) );
		}

		$this->placeholders['{product_table}']   = Host::product_table_html( $id );
		$this->placeholders['{shopname}']        = $this->get_blogname();
		$this->unsubscribe_url                   = EmailLink::url( $id );
		$this->placeholders['{unsubscribe_url}'] = $this->unsubscribe_url;

		if ( $product instanceof \WC_Product && $product->managing_stock() && null !== $product->get_stock_quantity() ) {
			$this->placeholders['{stock_left}'] = (string) max( 0, (int) $product->get_stock_quantity() );
		}

		if ( $test ) {
			$this->apply_test_overrides();
		} else {
			$this->add_coupon_placeholders();
		}

		/**
		 * Modifie les substitutions d'une relance.
		 *
		 * @param array<string, string> $placeholders Substitutions.
		 * @param int                   $id           Inscription.
		 * @param ReminderEmail         $email        E-mail en cours d'envoi.
		 */
		$this->placeholders = (array) apply_filters( 'ebisn_reminder_placeholders', $this->placeholders, $id, $this );
	}

	/**
	 * Remplace ce qui aurait un effet réel par des valeurs d'exemple.
	 */
	private function apply_test_overrides(): void {
		$this->unsubscribe_url                   = home_url( '/' );
		$this->placeholders['{unsubscribe_url}'] = $this->unsubscribe_url;

		if ( ! $this->is_last || ! Settings::get_bool( 'reminder_coupon_last', false ) ) {
			return;
		}

		$this->placeholders['{coupon_code}']   = 'EXEMPLE-10';
		$this->placeholders['{coupon_amount}'] = '10%';
		$this->placeholders['{coupon_box}']    = Host::coupon_box_html( 'EXEMPLE-10', '10%', 0, $this->placeholders['{cart_link}'] );
	}

	/**
	 * Ajoute le coupon, si — et seulement si — il est demandé.
	 *
	 * Trois conditions : l'option est cochée, c'est la dernière relance, et le
	 * texte en utilise vraiment une balise. Comme l'hôte, on ne crée pas de
	 * coupon qui ne figurerait dans aucun message.
	 */
	private function add_coupon_placeholders(): void {
		if ( ! $this->is_last || ! Settings::get_bool( 'reminder_coupon_last', false ) || ! Host::coupon_enabled() ) {
			return;
		}

		// Objet, titre et message : une balise de coupon n'importe où doit en créer un.
		$content = (string) $this->get_option( $this->field_key( 'additional_content', $this->step ), $this->get_default_additional_content() )
			. (string) $this->get_option( $this->field_key( 'subject', $this->step ), $this->get_default_subject() )
			. (string) $this->get_option( $this->field_key( 'heading', $this->step ), $this->get_default_heading() );

		if ( false === strpos( $content, '{coupon_' ) ) {
			return;
		}

		$coupon = Host::coupon_for( $this->subscription_id );

		if ( '' === $coupon['code'] ) {
			return;
		}

		$this->placeholders['{coupon_code}']   = $coupon['code'];
		$this->placeholders['{coupon_amount}'] = $coupon['amount'];

		if ( $coupon['expires'] > 0 ) {
			$this->placeholders['{coupon_expiry}'] = wp_date( (string) get_option( 'date_format' ), $coupon['expires'] );
		}

		$this->placeholders['{coupon_box}'] = Host::coupon_box_html(
			$coupon['code'],
			$coupon['amount'],
			$coupon['expires'],
			$this->placeholders['{cart_link}']
		);
	}

	/**
	 * Valeurs d'exemple pour l'aperçu de WooCommerce.
	 *
	 * @param mixed  $placeholders Substitutions existantes.
	 * @param string $email_type   Classe de l'e-mail prévisualisé.
	 *
	 * @return array<string, string>
	 */
	public function preview_placeholders( $placeholders, $email_type = '' ): array {
		$placeholders = (array) $placeholders;

		if ( $email_type && ! in_array( $email_type, array( self::class, ReminderMailer::EMAIL_ID ), true ) ) {
			return $placeholders;
		}

		$name = __( 'Produit exemple', 'extender-for-back-in-stock-notifier' );

		return array_merge(
			$placeholders,
			array(
				'{product_name}'         => $name,
				'{only_product_name}'    => $name,
				'{product_link}'         => home_url( '/' ),
				'{cart_link}'            => home_url( '/' ),
				'{product_price}'        => function_exists( 'wc_price' ) ? html_entity_decode( wp_strip_all_tags( wc_price( 29 ) ), ENT_QUOTES, 'UTF-8' ) : '29',
				'{stock_left}'           => '3',
				'{subscriber_name}'      => __( 'Camille Dupont', 'extender-for-back-in-stock-notifier' ),
				'{subscriber_firstname}' => __( 'Camille', 'extender-for-back-in-stock-notifier' ),
				'{unsubscribe_url}'      => home_url( '/' ),
				'{shopname}'             => $this->get_blogname(),
				'{coupon_code}'          => 'EXEMPLE-10',
				'{coupon_amount}'        => '10%',
			)
		);
	}

	/**
	 * Arguments communs aux deux gabarits.
	 *
	 * @param bool $plain_text Gabarit texte.
	 *
	 * @return array<string, mixed>
	 */
	private function template_args( bool $plain_text ): array {
		return array(
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'subscription_id'    => $this->subscription_id,
			'unsubscribe_url'    => '' !== $this->unsubscribe_url || $this->subscription_id > 0 ? $this->unsubscribe_url : home_url( '/' ),
			'blogname'           => $this->get_blogname(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	/**
	 * {@inheritDoc}
	 */
	public function init_form_fields(): void {
		$help = $this->placeholder_help();

		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Activer/Désactiver', 'extender-for-back-in-stock-notifier' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activer cet e-mail', 'extender-for-back-in-stock-notifier' ),
				'default' => 'yes',
			),
			'subject'              => array(
				'title'       => __( 'Relance 1 — objet', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $help,
				'placeholder' => $this->default_for( 'subject', 1 ),
				'default'     => '',
			),
			'heading'              => array(
				'title'       => __( 'Relance 1 — titre', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $help,
				'placeholder' => $this->default_for( 'heading', 1 ),
				'default'     => '',
			),
			'additional_content'   => array(
				'title'       => __( 'Relance 1 — message', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height:120px;',
				'description' => $help,
				'desc_tip'    => true,
				'default'     => $this->default_for( 'additional_content', 1 ),
			),
			'subject_2'            => array(
				'title'       => __( 'Relance 2 — objet', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $help,
				'placeholder' => $this->default_for( 'subject', 2 ),
				'default'     => '',
			),
			'heading_2'            => array(
				'title'       => __( 'Relance 2 — titre', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'text',
				'desc_tip'    => true,
				'description' => $help,
				'placeholder' => $this->default_for( 'heading', 2 ),
				'default'     => '',
			),
			'additional_content_2' => array(
				'title'       => __( 'Relance 2 — message', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'textarea',
				'css'         => 'width:400px; height:120px;',
				'description' => $help,
				'desc_tip'    => true,
				'default'     => $this->default_for( 'additional_content', 2 ),
			),
			'email_type'           => array(
				'title'       => __( 'Type d’e-mail', 'extender-for-back-in-stock-notifier' ),
				'type'        => 'select',
				'description' => __( 'Format de l’e-mail à envoyer.', 'extender-for-back-in-stock-notifier' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => $this->get_email_type_options(),
				'desc_tip'    => true,
			),
		);
	}
}
