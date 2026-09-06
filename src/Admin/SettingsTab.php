<?php
/**
 * Onglet de réglages WooCommerce.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Admin;

use EBISN\Brevo\Client;
use EBISN\Brevo\Contacts;
use EBISN\Brevo\Lists;
use EBISN\Integration\BackInStockNotifier;
use EBISN\Integration\Brevo;
use EBISN\Matrix\DemandMatrix;
use EBISN\Modules\ModuleInterface;
use EBISN\Plugin;
use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Réglages → Extender BIS.
 *
 * Les identifiants de champs servent directement de noms d'options : ils
 * doivent donc rester préfixés par `ebisn_` (cf. Settings::PREFIX).
 */
final class SettingsTab extends \WC_Settings_Page {

	/**
	 * Paramètre d'URL demandant un rafraîchissement des données Brevo.
	 */
	private const REFRESH_ARG = 'ebisn_refresh_brevo';

	/**
	 * Déclare l'onglet auprès de WooCommerce.
	 */
	public function __construct() {
		$this->id    = Admin::SETTINGS_TAB;
		$this->label = __( 'Extender BIS', 'extender-for-back-in-stock-notifier' );

		parent::__construct();
	}

	/**
	 * Sous-sections de l'onglet.
	 *
	 * @return array<string, string>
	 */
	protected function get_own_sections(): array {
		return array(
			''           => __( 'Général', 'extender-for-back-in-stock-notifier' ),
			'modules'    => __( 'Modules', 'extender-for-back-in-stock-notifier' ),
			'conversion' => __( 'Conversion', 'extender-for-back-in-stock-notifier' ),
			'matrix'     => __( 'Demandes par taille', 'extender-for-back-in-stock-notifier' ),
			'brevo'      => __( 'Brevo', 'extender-for-back-in-stock-notifier' ),
		);
	}

	/**
	 * Champs de la section « Demandes par taille ».
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_matrix_section(): array {
		return array(
			array(
				'title' => __( 'Demandes par taille', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Écran croisant les demandes de réassort par produit et par déclinaison.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'matrix_options',
			),
			array(
				'title'    => __( 'Attribut de déclinaison', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Attribut porté en colonnes. La détection automatique privilégie un attribut dont le nom évoque une taille, puis le plus souvent renseigné.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'matrix_attribute',
				'type'     => 'select',
				'options'  => $this->attribute_options(),
				'default'  => '',
				'class'    => 'wc-enhanced-select',
			),
			array(
				'title'    => __( 'Statuts comptés', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Par défaut, seules les demandes réellement en attente sont comptées. Ajouter « En file » ou « Alerte envoyée » donne la demande totale plutôt que le reste à servir.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'matrix_statuses',
				'type'     => 'multiselect',
				'options'  => $this->status_options(),
				'default'  => DemandMatrix::DEFAULT_STATUSES,
				'class'    => 'wc-enhanced-select',
			),
			array(
				'title'    => __( 'Durée du cache', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'minutes', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Le cache est de toute façon vidé dès qu’une inscription ou une conversion le périme. 0 le désactive.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'matrix_cache_minutes',
				'type'     => 'number',
				'default'  => DemandMatrix::DEFAULT_TTL_MINUTES,
				'css'      => 'width:100px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'matrix_options',
			),
		);
	}

	/**
	 * Attributs de produit proposés comme axe de la matrice.
	 *
	 * @return array<string, string>
	 */
	private function attribute_options(): array {
		$options = array( '' => __( 'Détection automatique', 'extender-for-back-in-stock-notifier' ) );

		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return $options;
		}

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			if ( empty( $attribute->attribute_name ) ) {
				continue;
			}

			$name = 'pa_' . $attribute->attribute_name;

			$options[ $name ] = ! empty( $attribute->attribute_label )
				? (string) $attribute->attribute_label
				: $name;
		}

		return $options;
	}

	/**
	 * Statuts d'inscription proposés au comptage.
	 *
	 * @return array<string, string>
	 */
	private function status_options(): array {
		$options = array();

		foreach ( BackInStockNotifier::statuses() as $status ) {
			$object = get_post_status_object( $status );

			$options[ $status ] = $object && isset( $object->label ) ? (string) $object->label : $status;
		}

		return $options;
	}

	/**
	 * Champs de la section « Conversion ».
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_conversion_section(): array {
		return array(
			array(
				'title' => __( 'Conversion « Purchased »', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Comportement de la détection d’achat.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'conversion_options',
			),
			array(
				'title'    => __( 'Statuts de commande', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Statuts, séparés par des virgules, à partir desquels une commande vaut achat.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'conversion_order_statuses',
				'type'     => 'text',
				'default'  => 'processing,completed',
				'css'      => 'width:320px;',
			),
			array(
				'title'    => __( 'Fenêtre d’attribution', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'jours', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Au-delà de ce délai entre l’inscription et la commande, l’achat n’est plus attribué à l’attente. 0 désactive la limite.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'conversion_window_days',
				'type'     => 'number',
				'default'  => 0,
				'css'      => 'width:100px;',
			),
			array(
				'title'    => __( 'Ne pas notifier un acheteur', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Supprimer l’alerte de retour en stock pour qui a déjà acheté le produit.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Une inscription déjà mise en file d’envoi reste notifiée par l’extension hôte même après conversion : sans cette option, le client reçoit « votre produit est de retour » alors qu’il vient de le commander.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'conversion_suppress_notification',
				'type'     => 'checkbox',
				'default'  => 'yes',
			),
			array(
				'title'   => __( 'Déduire les remboursements', 'extender-for-back-in-stock-notifier' ),
				'desc'    => __( 'Soustraire les remboursements des montants récupérés affichés.', 'extender-for-back-in-stock-notifier' ),
				'id'      => Settings::PREFIX . 'stats_deduct_refunds',
				'type'    => 'checkbox',
				'default' => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'conversion_options',
			),
		);
	}

	/**
	 * Champs de la section « Brevo ».
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_brevo_section(): array {
		return array(
			array(
				'title' => __( 'Synchronisation Brevo', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => $this->brevo_intro(),
				'id'    => Settings::PREFIX . 'brevo_options',
			),
			$this->brevo_list_field(),
			array(
				'title'    => __( 'Clé d’API', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'À renseigner uniquement si l’extension Brevo n’est pas connectée : sa clé est utilisée en priorité.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'brevo_api_key',
				'type'     => 'password',
				'default'  => '',
				'css'      => 'width:420px;',
			),
			array(
				'title'    => __( 'Préfixe des attributs', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Préfixe des attributs de contact créés chez Brevo. Ne le modifiez pas après le premier envoi : Brevo ne sait pas renommer un attribut sans détruire les données qu’il contient.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'brevo_attribute_prefix',
				'type'     => 'text',
				'default'  => 'MH_ATTENTE_',
				'css'      => 'width:220px;',
			),
			array(
				'title'    => __( 'Attribut de taille', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Taxonomie d’attribut WooCommerce dont la valeur est envoyée comme taille.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'brevo_size_taxonomy',
				'type'     => 'text',
				'default'  => 'pa_taille',
				'css'      => 'width:220px;',
			),
			array(
				'title'    => __( 'Exiger un consentement', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Ajouter une case de consentement marketing au formulaire de réassort.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Sans consentement, le contact est créé chez Brevo mais n’est ajouté à aucune liste. Demander à être prévenu d’un réassort n’est pas accepter de recevoir des campagnes.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'brevo_require_consent',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Libellé du consentement', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Texte affiché à côté de la case. Il est conservé avec la preuve de consentement.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'brevo_consent_label',
				'type'     => 'text',
				'default'  => '',
				'css'      => 'width:420px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'brevo_options',
			),
		);
	}

	/**
	 * Champ de sélection de la liste Brevo.
	 *
	 * Une liste déroulante quand le compte répond, un champ numérique sinon :
	 * il doit rester possible de désigner une liste alors même que l'API est
	 * momentanément injoignable ou que la clé n'a pas les droits de lecture.
	 *
	 * @return array<string, mixed>
	 */
	private function brevo_list_field(): array {
		$field = array(
			'title'    => __( 'Liste de destination', 'extender-for-back-in-stock-notifier' ),
			'desc_tip' => __( 'Liste Brevo recevant les inscrits. Tant qu’aucune liste n’est choisie, le module reste inactif.', 'extender-for-back-in-stock-notifier' ),
			'id'       => Settings::PREFIX . 'brevo_list_id',
			'default'  => 0,
		);

		$lists = Brevo::has_api_key() ? Lists::all( $this->refresh_requested() ) : array();

		if ( empty( $lists ) ) {
			return array_merge(
				$field,
				array(
					'type' => 'number',
					'css'  => 'width:120px;',
					'desc' => Brevo::has_api_key()
						? esc_html__( 'Listes non récupérables pour le moment : saisissez l’identifiant numérique.', 'extender-for-back-in-stock-notifier' )
						: '',
				)
			);
		}

		$options = array( 0 => __( '— Aucune —', 'extender-for-back-in-stock-notifier' ) );

		foreach ( $lists as $id => $name ) {
			/* translators: 1: nom de la liste Brevo, 2: identifiant numérique. */
			$options[ $id ] = sprintf( __( '%1$s (#%2$d)', 'extender-for-back-in-stock-notifier' ), $name, $id );
		}

		return array_merge(
			$field,
			array(
				'type'    => 'select',
				'options' => $options,
				'class'   => 'wc-enhanced-select',
				'desc'    => $this->brevo_refresh_link(),
			)
		);
	}

	/**
	 * Lien de rafraîchissement du cache des listes.
	 *
	 * @return string
	 */
	private function brevo_refresh_link(): string {
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( wp_nonce_url( add_query_arg( self::REFRESH_ARG, '1' ), self::REFRESH_ARG ) ),
			esc_html__( 'Rafraîchir la liste depuis Brevo', 'extender-for-back-in-stock-notifier' )
		);
	}

	/**
	 * Un rafraîchissement des listes a-t-il été demandé ?
	 *
	 * @return bool
	 */
	private function refresh_requested(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- le jeton est vérifié juste après.
		if ( ! isset( $_GET[ self::REFRESH_ARG ], $_GET['_wpnonce'] ) ) {
			return false;
		}

		return (bool) wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ),
			self::REFRESH_ARG
		);
	}

	/**
	 * Texte d'introduction de la section Brevo, adapté à ce qui est détecté.
	 *
	 * @return string
	 */
	private function brevo_intro(): string {
		if ( ! Brevo::is_active() && ! Brevo::has_api_key() ) {
			return esc_html__(
				'L’extension Brevo n’est pas détectée. Installez-la, ou renseignez directement une clé d’API ci-dessous.',
				'extender-for-back-in-stock-notifier'
			);
		}

		if ( ! Brevo::has_api_key() ) {
			return esc_html__(
				'L’extension Brevo est présente mais aucune clé d’API n’a été trouvée : le compte est-il connecté ?',
				'extender-for-back-in-stock-notifier'
			);
		}

		return esc_html__(
			'Les inscrits sont poussés vers la liste choisie. Brevo identifiant un contact par son adresse, un envoi répété ne crée jamais de doublon.',
			'extender-for-back-in-stock-notifier'
		) . '<br>' . $this->brevo_connection_status();
	}

	/**
	 * État de la connexion au compte Brevo.
	 *
	 * Le résultat est mis en cache : cet appel relève lui aussi du quota des
	 * « autres » points d'entrée de l'API, cent par heure.
	 *
	 * @return string
	 */
	private function brevo_connection_status(): string {
		$cache_key = 'ebisn_brevo_account';
		$account   = get_transient( $cache_key );

		if ( false === $account || $this->refresh_requested() ) {
			$response = ( new Contacts( new Client() ) )->account();
			$body     = $response->body();

			$account = $response->is_success()
				? (string) ( $body['companyName'] ?? ( $body['email'] ?? __( 'compte connecté', 'extender-for-back-in-stock-notifier' ) ) )
				: '';

			set_transient( $cache_key, $account, HOUR_IN_SECONDS );
		}

		if ( '' === $account ) {
			return '<strong style="color:#b32d2e">' . esc_html__(
				'Connexion à Brevo impossible : la clé d’API est-elle valide ?',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';
		}

		return sprintf(
			/* translators: %s: nom du compte Brevo. */
			esc_html__( 'Connecté à Brevo — %s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( $account ) . '</strong>'
		);
	}

	/**
	 * Champs de la section par défaut.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_default_section(): array {
		return array(
			array(
				'title' => __( 'Réglages généraux', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Comportement global de l’extension.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'general_options',
			),
			array(
				'title'    => __( 'Journalisation', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Consigner les opérations de l’extension dans les journaux WooCommerce.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Visible dans WooCommerce → État → Journaux, source « extender-bisn ».', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'enable_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Effacer les données à la désinstallation', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Supprimer les réglages de cette extension si elle est supprimée.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Ne concerne que les données de CETTE extension : ses réglages, ses métadonnées de conversion et sa table de synchronisation. Les abonnés, arrivages et réglages de Back In Stock Notifier ne sont jamais supprimés.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'delete_data_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'title'    => __( 'Rétablir les statuts à la désinstallation', 'extender-for-back-in-stock-notifier' ),
				'desc'     => __( 'Remettre les inscriptions converties dans leur statut d’origine.', 'extender-for-back-in-stock-notifier' ),
				'desc_tip' => __( 'Par défaut, les inscriptions marquées « Purchased » le restent : la conversion demeure vraie même sans cette extension. Cochez pour tout remettre en l’état. Les conversions héritées de vos anciens snippets ne peuvent pas être rétablies, leur statut d’origine n’ayant jamais été enregistré.', 'extender-for-back-in-stock-notifier' ),
				'id'       => Settings::PREFIX . 'restore_statuses_on_uninstall',
				'type'     => 'checkbox',
				'default'  => 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'general_options',
			),
			array(
				'title' => __( 'Diagnostic', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => $this->diagnostics_html(),
				'id'    => Settings::PREFIX . 'diagnostics',
			),
			array(
				'type' => 'sectionend',
				'id'   => Settings::PREFIX . 'diagnostics',
			),
		);
	}

	/**
	 * État de la dépendance au plugin hôte.
	 *
	 * Sur une extension qui n'a encore aucune fonctionnalité, c'est ce bloc qui
	 * permet de constater d'un coup d'œil que la chaîne est branchée : version
	 * réellement lue chez l'hôte, et volumétrie de ses données.
	 *
	 * @return string
	 */
	private function diagnostics_html(): string {
		$lines = array();

		$lines[] = sprintf(
			/* translators: 1: nom de l'extension hôte, 2: version installée, 3: version minimale exigée. */
			esc_html__( '%1$s — version installée : %2$s · version minimale exigée : %3$s', 'extender-for-back-in-stock-notifier' ),
			esc_html( BackInStockNotifier::name() ),
			'<strong>' . esc_html( BackInStockNotifier::version() ) . '</strong>',
			'<code>' . esc_html( BackInStockNotifier::MIN_VERSION ) . '</code>'
		);

		if ( BackInStockNotifier::is_untested() ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: dernière version relue de l'extension hôte. */
				esc_html__( 'L’extension hôte a changé de version majeure depuis la dernière relecture de ce plugin (%s). Les hooks et réglages sur lesquels il s’appuie ont pu bouger : vérifiez le comportement avant de vous y fier.', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html( BackInStockNotifier::TESTED_VERSION ) . '</code>'
			) . '</strong>';
		}

		$lines[] = sprintf(
			/* translators: 1: nombre d'abonnés, 2: slug du type de contenu. */
			esc_html__( 'Abonnés enregistrés chez l’hôte : %1$s (type de contenu %2$s)', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( BackInStockNotifier::subscriber_count() ) ) . '</strong>',
			'<code>' . esc_html( BackInStockNotifier::SUBSCRIBER_TYPE ) . '</code>'
		);

		if ( BackInStockNotifier::auto_delete_enabled() ) {
			$lines[] = '<strong style="color:#b32d2e">' . sprintf(
				/* translators: %s: nombre de jours de rétention configuré chez l'extension hôte. */
				esc_html__( 'La suppression automatique de l’extension hôte est active (%s jours) : elle efface définitivement les inscriptions « Mail Sent », « Unsubscribed » et « Purchased ». Les conversions et les statistiques de chiffre d’affaires récupéré disparaîtront au fil de l’eau.', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html( number_format_i18n( BackInStockNotifier::auto_delete_days() ) ) . '</code>'
			) . '</strong>';
		}

		$lines[] = sprintf(
			/* translators: 1: version du plugin, 2: source des journaux WooCommerce. */
			esc_html__( 'Extender for Back In Stock Notifier %1$s — journaux sous la source %2$s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( EBISN_VERSION ) . '</strong>',
			'<code>' . esc_html( \EBISN\Support\Logger::SOURCE ) . '</code>'
		);

		/**
		 * Lignes affichées dans le panneau Diagnostic.
		 *
		 * Chaque module y contribue son état sans que cette classe ait à le
		 * connaître. Le HTML est déjà échappé par l'appelant : les filtres
		 * doivent en faire autant.
		 *
		 * @param string[] $lines Lignes de diagnostic, HTML échappé.
		 */
		$lines = (array) apply_filters( 'ebisn_diagnostics_lines', $lines );

		return implode( '<br>', array_filter( array_map( 'strval', $lines ) ) );
	}

	/**
	 * Champs de la section « Modules » : une case à cocher par module déclaré.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	protected function get_settings_for_modules_section(): array {
		$settings = array(
			array(
				'title' => __( 'Modules', 'extender-for-back-in-stock-notifier' ),
				'type'  => 'title',
				'desc'  => __( 'Activez individuellement les règles ajoutées à Back In Stock Notifier.', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'module_options',
			),
		);

		/*
		 * Le défaut affiché est celui du module, et non un 'yes' figé : c'est la
		 * valeur exacte sur laquelle AbstractModule::is_enabled() se rabat. Les
		 * déclarer séparément afficherait un module coché alors qu'il ne tourne pas.
		 */
		foreach ( $this->get_declared_modules() as $module ) {
			$settings[] = array(
				'title'   => $module->get_title(),
				'desc'    => __( 'Activer', 'extender-for-back-in-stock-notifier' ),
				'id'      => Settings::PREFIX . 'module_' . $module->get_id() . '_enabled',
				'type'    => 'checkbox',
				'default' => $module->is_enabled_by_default() ? 'yes' : 'no',
			);
		}

		if ( 1 === count( $settings ) ) {
			$settings[] = array(
				'title' => '',
				'type'  => 'info',
				'text'  => __( 'Aucun module déclaré pour le moment. Ajoutez vos classes dans src/Modules/ puis référencez-les dans Plugin::get_module_classes().', 'extender-for-back-in-stock-notifier' ),
				'id'    => Settings::PREFIX . 'module_empty_notice',
			);
		}

		$settings[] = array(
			'type' => 'sectionend',
			'id'   => Settings::PREFIX . 'module_options',
		);

		return $settings;
	}

	/**
	 * Instancie tous les modules déclarés, actifs ou non, pour l'affichage.
	 *
	 * @return ModuleInterface[]
	 */
	private function get_declared_modules(): array {
		$modules = array();

		foreach ( Plugin::get_module_classes() as $class_name ) {
			if ( ! is_string( $class_name ) || ! class_exists( $class_name ) ) {
				continue;
			}

			$module = new $class_name();

			if ( $module instanceof ModuleInterface ) {
				$modules[] = $module;
			}
		}

		return $modules;
	}
}
