<?php
/**
 * Point d'isolement du plugin hôte.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Tout ce que ce plugin sait de « Back In Stock Notifier for WooCommerce ».
 *
 * Aucune autre classe ne doit écrire en dur `cwginstock`, `CWGINSTOCK_*` ni
 * `CWG_Instock_Notifier` : le jour où l'extension hôte renomme une option, un
 * type de contenu ou une constante, seul ce fichier bouge. C'est la contrepartie
 * d'étendre du code qu'on ne maîtrise pas.
 *
 * Relevé sur la version 7.5.3 (ProPluginsLab).
 */
final class BackInStockNotifier {

	/**
	 * Slug de l'extension, tel qu'il figure dans l'en-tête `Requires Plugins`
	 * du fichier principal de CE plugin, et tel que WordPress.org l'expose.
	 */
	public const SLUG = 'back-in-stock-notifier-for-woocommerce';

	/**
	 * Chemin du fichier principal, relatif au dossier des extensions.
	 *
	 * Le nom du fichier ne reprend PAS le slug : c'est `cwginstocknotifier.php`.
	 */
	public const BASENAME = 'back-in-stock-notifier-for-woocommerce/cwginstocknotifier.php';

	/**
	 * Nom de la constante portant le numéro de version de l'extension hôte.
	 *
	 * Passer par une constante de constante peut surprendre, mais c'est ce qui
	 * permet à `version()` de n'y accéder que par `constant()` : voir le
	 * commentaire de cette méthode.
	 */
	public const VERSION_CONSTANT = 'CWGINSTOCK_VERSION';

	/**
	 * Version minimale de l'extension hôte acceptée.
	 *
	 * Plancher provisoire et assumé : sans fonctionnalité, aucun hook consommé
	 * ne justifie encore un seuil précis. 7.0.0 ouvre la ligne majeure courante.
	 * À réviser dès qu'un module dépendra d'un hook daté.
	 */
	public const MIN_VERSION = '7.0';

	/**
	 * Dernière version de l'extension hôte contre laquelle ce plugin a été relu.
	 *
	 * Ce plugin s'accroche à du code qu'il ne maîtrise pas : un changement de
	 * version majeure de l'hôte peut déplacer un hook ou renommer une option
	 * sans que rien ne casse bruyamment. Cette valeur n'empêche rien — elle sert
	 * à afficher un avertissement dans le diagnostic, seul signal disponible.
	 */
	public const TESTED_VERSION = '7.5.3';

	/**
	 * Type de contenu des abonnés à une alerte de retour en stock.
	 */
	public const SUBSCRIBER_TYPE = 'cwginstocknotifier';

	/**
	 * Type de contenu des arrivages.
	 */
	public const ARRIVAL_TYPE = 'cwginstock_arrival';

	/**
	 * Identifiant d'écran de la liste des inscrits.
	 */
	public const LIST_SCREEN_ID = 'edit-cwginstocknotifier';

	/**
	 * Slug du menu de l'extension hôte, à passer en parent d'une sous-page.
	 */
	public const MENU_PARENT = 'edit.php?post_type=cwginstocknotifier';

	/*
	 * ---------------------------------------------------------------------
	 * Statuts d'inscription
	 *
	 * Les six statuts réellement enregistrés par la version gratuite. Le
	 * septième que l'on croise dans le code de l'hôte, `cwg_doubleoptin`, est
	 * référencé mais JAMAIS enregistré : il appartient à un module payant.
	 * Ne pas le supposer présent.
	 * ---------------------------------------------------------------------
	 */

	/** Inscrit, en attente de réapprovisionnement. */
	public const STATUS_SUBSCRIBED = 'cwg_subscribed';

	/** Mis en file d'envoi. Posé en SQL direct : aucune transition n'est émise. */
	public const STATUS_QUEUED = 'cwg_queued';

	/** Alerte de retour en stock envoyée. */
	public const STATUS_MAILSENT = 'cwg_mailsent';

	/** Échec d'envoi de l'alerte. */
	public const STATUS_MAILNOTSENT = 'cwg_mailnotsent';

	/**
	 * « Purchased ».
	 *
	 * Enregistré par l'hôte, mais aucun code de la version gratuite ne le pose :
	 * il est réservé à un module payant. C'est ce vide que comble le module de
	 * conversion de ce plugin.
	 */
	public const STATUS_CONVERTED = 'cwg_converted';

	/** Désinscrit. Jamais reconverti par ce plugin. */
	public const STATUS_UNSUBSCRIBED = 'cwg_unsubscribed';

	/*
	 * ---------------------------------------------------------------------
	 * Métadonnées d'inscription
	 *
	 * Écrites par CWG_Instock_API::insert_data() sur les trois chemins de
	 * création (AJAX, REST create, REST update). Les trois clés de produit
	 * coexistent et n'ont pas le même sens : voir chaque constante.
	 * ---------------------------------------------------------------------
	 */

	/** ID du produit PARENT, toujours — jamais celui d'une variation. */
	public const META_PRODUCT_ID = 'cwginstock_product_id';

	/** ID de la variation attendue, `0` pour un produit simple. */
	public const META_VARIATION_ID = 'cwginstock_variation_id';

	/**
	 * Produit effectivement attendu : `variation_id` s'il y en a une, sinon
	 * `product_id`. C'est la clé sur laquelle l'hôte résout `wc_get_product()`.
	 */
	public const META_PID = 'cwginstock_pid';

	/**
	 * Variation ayant déclenché la notification d'un inscrit « parent ».
	 *
	 * Posée par l'hôte quand `variable_any_variation_backinstock` est actif, et
	 * prioritaire sur META_PID dans tout son code d'e-mail. Jamais nettoyée.
	 */
	public const META_BYPASS_PID = 'cwginstock_bypass_pid';

	/** Adresse de l'inscrit. Également recopiée dans `post_title`. */
	public const META_EMAIL = 'cwginstock_subscriber_email';

	/** Compte WordPress de l'inscrit, `0` pour un visiteur non connecté. */
	public const META_USER_ID = 'cwginstock_user_id';

	/** Nom saisi. Absente si le champ est vide ou désactivé. */
	public const META_NAME = 'cwginstock_subscriber_name';

	/** Quantité demandée. Absente si le champ quantité est désactivé. */
	public const META_QUANTITY = 'cwginstock_custom_quantity';

	/** Horodatage UNIX UTC de l'envoi de l'alerte. */
	public const META_MAIL_ON = 'cwginstock_mail_on';

	/**
	 * Nombre d'inscrits en attente, stocké sur le PRODUIT.
	 *
	 * L'hôte n'y compte que les `cwg_subscribed`. Tout code qui fait sortir une
	 * inscription de ce statut doit le recalculer, sous peine de le laisser
	 * dériver silencieusement.
	 */
	public const PRODUCT_META_SUBSCRIBER_COUNT = 'cwg_total_subscribers';

	/*
	 * ---------------------------------------------------------------------
	 * Hooks consommés
	 * ---------------------------------------------------------------------
	 */

	/**
	 * `( int $subscriber_id, array $post_data )` — inscription enregistrée.
	 *
	 * Émis depuis trois endroits dont la forme de `$post_data` DIFFÈRE : la
	 * clé de l'adresse est `user_email` en AJAX et `email` en REST. Il se
	 * déclenche aussi sur une ré-inscription et sur une mise à jour REST.
	 * Ne jamais lire le second argument : tout relire depuis l'ID.
	 */
	public const HOOK_AFTER_INSERT_SUBSCRIBER = 'cwginstock_after_insert_subscriber';

	/**
	 * `( bool $stop, int $subscriber_id, WC_Product $product )` — filtre.
	 *
	 * Renvoyer `true` empêche l'envoi de l'alerte de retour en stock.
	 */
	public const HOOK_STOP_EMAIL = 'cwginstock_stop_email';

	/** `( array $keys )` — clés de formulaire persistées en méta, sans préfixe. */
	public const HOOK_CUSTOM_META_KEYS = 'cwginstocknotifier_insert_custom_meta_data';

	/** `( int $product_id, int $variation_id )` — sous le champ e-mail du formulaire. */
	public const HOOK_AFTER_EMAIL_FIELD = 'cwg_instock_after_email_field';

	/** `( int $product_id, int $variation_id )` — sous le bouton d'inscription. */
	public const HOOK_AFTER_SUBMIT_BUTTON = 'cwginstock_after_submit_button';

	/**
	 * `( bool $display, WC_Product $product, WC_Product_Variation|array $variation )`.
	 *
	 * Renvoyer `false` empêche le rendu du formulaire d'inscription ET fait
	 * déclencher `HOOK_CUSTOM_FORM` à sa place, une fois que l'hôte a évalué
	 * toutes ses conditions de visibilité.
	 */
	public const HOOK_DISPLAY_FORM = 'cwginstock_display_subscribe_form';

	/**
	 * `( WC_Product $product, WC_Product_Variation|array $variation )`.
	 *
	 * Rendu de remplacement du formulaire, à l'endroit exact où l'hôte aurait
	 * affiché le sien.
	 */
	public const HOOK_CUSTOM_FORM = 'cwginstock_custom_form';

	/**
	 * `( array $placeholders, int $subscriber_id, WC_Email $email )` — filtre.
	 *
	 * Substitutions des gabarits d'e-mail modernes de l'hôte.
	 */
	public const HOOK_EMAIL_PLACEHOLDERS = 'cwginstock_email_placeholders';

	/**
	 * `( int $id, string $stock_status, WC_Product|string $product )`.
	 *
	 * Déclenchée à CHAQUE changement de statut de stock, ruptures comprises :
	 * l'hôte ne filtre sur « instock » qu'après l'avoir émise. C'est donc le
	 * seul point où observer une remise en rupture. Elle est également émise
	 * par sa couche de compatibilité avec les stocks tiers, ce qu'un branchement
	 * direct sur les hooks de WooCommerce ne couvrirait pas.
	 */
	public const HOOK_BEFORE_TRIGGER_STATUS = 'cwginstock_before_trigger_status';

	/** Statut de stock d'un produit indisponible, tel que WooCommerce le nomme. */
	public const STOCK_OUT = 'outofstock';

	/*
	 * ---------------------------------------------------------------------
	 * Réglages de l'extension hôte
	 * ---------------------------------------------------------------------
	 */

	/**
	 * Option principale de l'extension hôte (tableau de réglages).
	 */
	public const SETTINGS_OPTION = 'cwginstocksettings';

	/** Clé d'activation de la suppression automatique des inscrits. */
	public const SETTING_AUTO_DELETE = 'enable_auto_delete';

	/** Nombre de jours de rétention avant suppression automatique. */
	public const SETTING_AUTO_DELETE_DAYS = 'delete_subscribers_for_x_days';

	/** Rétention appliquée par l'hôte quand la valeur n'est pas renseignée. */
	public const AUTO_DELETE_DEFAULT_DAYS = 7;

	/**
	 * Clé maintenant les inscrits en attente même après l'envoi de l'alerte.
	 *
	 * Le libellé de l'écran parle de « post status subscribed », mais la valeur
	 * est bien stockée sous ce nom-là.
	 */
	public const SETTING_KEEP_SUBSCRIBED = 'keep_status_subscribed';

	/**
	 * Dossier de surcharge des gabarits, à créer dans le thème.
	 *
	 * L'extension hôte cherche d'abord `{thème}/back-in-stock-notifier-for-woocommerce/{gabarit}`,
	 * puis `{thème}/{gabarit}`, avant de retomber sur les siens. Le tout passe
	 * par le filtre `cwginstock_locate_template`.
	 */
	public const THEME_TEMPLATE_DIR = 'back-in-stock-notifier-for-woocommerce/';

	/**
	 * L'extension hôte est-elle chargée ET démarrée ?
	 *
	 * On teste la CONSTANTE, jamais `class_exists( 'CWG_Instock_Notifier' )` :
	 * cette classe est déclarée dès l'inclusion du fichier principal, alors que
	 * le singleton n'est instancié — et les constantes définies — que si
	 * WooCommerce est actif. La classe existe donc aussi dans un cas où
	 * l'extension ne fait rien du tout.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return defined( self::VERSION_CONSTANT );
	}

	/**
	 * Version de l'extension hôte.
	 *
	 * Lue par `constant()` sous garde `defined()` plutôt qu'en accès direct :
	 * aucun stub de l'extension hôte n'est fourni à PHPStan, et un accès direct
	 * à une constante inconnue ferait échouer l'analyse statique.
	 *
	 * @return string Chaîne vide si l'extension n'est pas démarrée.
	 */
	public static function version(): string {
		if ( ! self::is_active() ) {
			return '';
		}

		$version = constant( self::VERSION_CONSTANT );

		return is_scalar( $version ) ? (string) $version : '';
	}

	/**
	 * L'extension hôte est-elle présente dans une version exploitable ?
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		$version = self::version();

		return '' !== $version && version_compare( $version, self::MIN_VERSION, '>=' );
	}

	/**
	 * L'extension hôte a-t-elle changé de version MAJEURE depuis la relecture ?
	 *
	 * On ne compare que le premier segment : une version mineure ou correctrice
	 * n'est pas censée déplacer un hook, et déclencher l'avertissement à chaque
	 * correctif le rendrait invisible à force d'être affiché.
	 *
	 * @return bool
	 */
	public static function is_untested(): bool {
		$version = self::version();

		if ( '' === $version ) {
			return false;
		}

		$installed = (int) explode( '.', $version )[0];
		$tested    = (int) explode( '.', self::TESTED_VERSION )[0];

		return $installed > $tested;
	}

	/**
	 * L'extension hôte est-elle installée, active ou non ?
	 *
	 * Sert uniquement à orienter l'avertissement d'administration : « installez-la »
	 * et « activez-la » n'appellent pas le même écran.
	 *
	 * @return bool
	 */
	public static function is_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return array_key_exists( self::BASENAME, get_plugins() );
	}

	/**
	 * Réglages de l'extension hôte.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		return (array) get_option( self::SETTINGS_OPTION, array() );
	}

	/**
	 * Statuts d'inscription enregistrés par la version gratuite de l'hôte.
	 *
	 * @return string[]
	 */
	public static function statuses(): array {
		return array(
			self::STATUS_SUBSCRIBED,
			self::STATUS_QUEUED,
			self::STATUS_MAILSENT,
			self::STATUS_MAILNOTSENT,
			self::STATUS_CONVERTED,
			self::STATUS_UNSUBSCRIBED,
		);
	}

	/**
	 * L'extension hôte supprime-t-elle automatiquement ses inscrits ?
	 *
	 * Réglage désactivé par défaut, mais lourd de conséquences quand il est
	 * actif : l'hôte efface DÉFINITIVEMENT (`wp_delete_post( $id, true )`) les
	 * inscriptions « Mail Sent », « Unsubscribed » et « Purchased » passé un
	 * délai. Tout ce que ce plugin attache à une inscription — conversion,
	 * statut d'origine, marqueur de synchronisation — part avec elle.
	 *
	 * @return bool
	 */
	public static function auto_delete_enabled(): bool {
		$settings = self::settings();

		return isset( $settings[ self::SETTING_AUTO_DELETE ] )
			&& '1' === (string) $settings[ self::SETTING_AUTO_DELETE ];
	}

	/**
	 * Rétention appliquée par la suppression automatique de l'hôte, en jours.
	 *
	 * @return int
	 */
	public static function auto_delete_days(): int {
		$settings = self::settings();
		$days     = isset( $settings[ self::SETTING_AUTO_DELETE_DAYS ] )
			? (int) $settings[ self::SETTING_AUTO_DELETE_DAYS ]
			: 0;

		return $days > 0 ? $days : self::AUTO_DELETE_DEFAULT_DAYS;
	}

	/**
	 * L'hôte renvoie-t-il l'alerte à chaque réassort ?
	 *
	 * Quand ce réglage est actif, une inscription reste « en attente » même
	 * après l'envoi de l'alerte : l'hôte prévient lui-même qu'il notifiera
	 * « repeatedly […] until the subscribed product is purchased or
	 * unsubscribed ». Sans possibilité de se désabonner, la personne ne peut
	 * donc plus jamais faire cesser ces envois.
	 *
	 * @return bool
	 */
	public static function keeps_subscribed_after_notification(): bool {
		$settings = self::settings();

		return isset( $settings[ self::SETTING_KEEP_SUBSCRIBED ] )
			&& '1' === (string) $settings[ self::SETTING_KEEP_SUBSCRIBED ];
	}

	/**
	 * Recalcule le nombre d'inscrits en attente stocké sur un produit.
	 *
	 * L'hôte maintient `cwg_total_subscribers` à l'inscription et à l'envoi,
	 * mais il ne connaît évidemment pas les transitions que CE plugin provoque.
	 * Sans ce recalcul, le compteur affiché par l'hôte surestime durablement le
	 * nombre de personnes réellement en attente.
	 *
	 * @param int $product_id Produit parent.
	 *
	 * @return void
	 */
	public static function refresh_subscriber_count( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- compteur de l'hôte, recalculé ponctuellement après une transition.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				   FROM {$wpdb->posts} p
				  INNER JOIN {$wpdb->postmeta} pm
				          ON pm.post_id = p.ID
				         AND pm.meta_key = %s
				  WHERE p.post_type = %s
				    AND p.post_status = %s
				    AND pm.meta_value = %s",
				self::META_PRODUCT_ID,
				self::SUBSCRIBER_TYPE,
				self::STATUS_SUBSCRIBED,
				(string) $product_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		update_post_meta( $product_id, self::PRODUCT_META_SUBSCRIBER_COUNT, $count );
	}

	/**
	 * Nombre d'abonnés enregistrés, tous statuts confondus.
	 *
	 * @return int
	 */
	public static function subscriber_count(): int {
		if ( ! post_type_exists( self::SUBSCRIBER_TYPE ) ) {
			return 0;
		}

		$total = 0;

		foreach ( (array) wp_count_posts( self::SUBSCRIBER_TYPE ) as $count ) {
			$total += (int) $count;
		}

		return $total;
	}

	/**
	 * URL de la fiche d'installation de l'extension hôte.
	 *
	 * @return string
	 */
	public static function install_url(): string {
		return self_admin_url( 'plugin-install.php?tab=search&type=term&s=' . rawurlencode( self::SLUG ) );
	}

	/**
	 * URL de la liste des extensions installées.
	 *
	 * @return string
	 */
	public static function plugins_url(): string {
		return self_admin_url( 'plugins.php' );
	}

	/*
	 * ---------------------------------------------------------------------
	 * Services de l'hôte utilisés pour composer un e-mail
	 *
	 * Relevés sur la 7.5.3. Chacun est gardé : l'hôte peut renommer ou retirer
	 * un de ces points d'entrée, et une relance ne doit alors pas devenir une
	 * erreur fatale mais simplement perdre l'élément concerné.
	 * ---------------------------------------------------------------------
	 */

	/** Version qui introduit le limiteur d'envois. */
	public const THROTTLE_SINCE = '7.3.0';

	/** Version qui introduit le coupon automatique. */
	public const COUPON_SINCE = '7.4.0';

	/**
	 * L'hôte est-il au moins dans cette version ?
	 *
	 * Plus fiable que `method_exists()` pour savoir si un service existe : la
	 * date d'arrivée d'un service est un fait documenté dans son changelog.
	 *
	 * @param string $minimum Version minimale.
	 *
	 * @return bool
	 */
	private static function at_least( string $minimum ): bool {
		$version = self::version();

		return '' !== $version && version_compare( $version, $minimum, '>=' );
	}

	/** Date d'expiration (UNIX) du coupon automatique d'une inscription. */
	public const META_COUPON_EXPIRES = 'cwginstock_coupon_expires';

	/**
	 * Produit ou déclinaison réellement attendu par une inscription.
	 *
	 * Même résolution que l'hôte dans tout son code d'e-mail : la variation qui
	 * a déclenché la notification (`bypass_pid`) prime sur le produit attendu.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return int `0` si l'inscription n'en désigne aucun.
	 */
	public static function product_id_for( int $subscription_id ): int {
		$bypass = (int) get_post_meta( $subscription_id, self::META_BYPASS_PID, true );

		if ( $bypass > 0 ) {
			return $bypass;
		}

		return (int) get_post_meta( $subscription_id, self::META_PID, true );
	}

	/** Clés de l'expéditeur configuré dans l'hôte pour ses propres e-mails. */
	public const SETTING_MAIL_FROM_NAME  = 'mail_from_name';
	public const SETTING_MAIL_FROM_EMAIL = 'mail_from_email';
	public const SETTING_MAIL_REPLY_TO   = 'mail_reply_to';

	/**
	 * Valeur d'un réglage d'expéditeur de l'hôte.
	 *
	 * Une relance doit partir de la même adresse que l'alerte : changer
	 * d'expéditeur d'un e-mail à l'autre est le meilleur moyen de la voir
	 * classée comme indésirable.
	 *
	 * @param string $key Une des constantes `SETTING_MAIL_*`.
	 *
	 * @return string Chaîne vide si le marchand n'a rien saisi.
	 */
	public static function mail_setting( string $key ): string {
		$settings = self::settings();

		return isset( $settings[ $key ] ) && is_scalar( $settings[ $key ] ) ? trim( (string) $settings[ $key ] ) : '';
	}

	/**
	 * Utilitaires de l'hôte autour d'une inscription.
	 *
	 * @return \CWG_Instock_API|null `null` si l'hôte ne la fournit pas.
	 */
	public static function api(): ?\CWG_Instock_API {
		return class_exists( '\CWG_Instock_API' ) ? new \CWG_Instock_API() : null;
	}

	/**
	 * Réserve un créneau auprès du limiteur d'envois de l'hôte.
	 *
	 * Sans attente : `wait_for_slot()` dormirait jusqu'à dix secondes, ce qui
	 * n'a pas sa place dans un passage qui en traite des dizaines. L'appelant
	 * reporte simplement l'inscription au passage suivant.
	 *
	 * @return bool Vrai si l'envoi est autorisé — aussi quand le limiteur est absent ou inactif.
	 */
	public static function try_acquire_send_slot(): bool {
		if ( ! self::at_least( self::THROTTLE_SINCE ) || ! class_exists( '\CWG_Instock_Mail_Throttle' ) ) {
			return true;
		}

		return (bool) \CWG_Instock_Mail_Throttle::try_acquire();
	}

	/**
	 * Ajoute les balises UTM configurées dans l'hôte à un lien.
	 *
	 * @param string $url     Lien.
	 * @param string $context Emplacement du lien, pour l'étiquette `utm_content`.
	 *
	 * @return string
	 */
	public static function utm( string $url, string $context ): string {
		if ( ! function_exists( 'cwg_instock_add_utm_params' ) ) {
			return $url;
		}

		$tagged = cwg_instock_add_utm_params( $url, $context );

		return is_string( $tagged ) ? $tagged : $url;
	}

	/**
	 * Tableau « article de commande » du produit attendu.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return string HTML, vide si l'hôte ne le fournit pas.
	 */
	public static function product_table_html( int $subscription_id ): string {
		if ( ! function_exists( 'cwg_instock_get_product_table_html' ) ) {
			return '';
		}

		return (string) cwg_instock_get_product_table_html( $subscription_id, false );
	}

	/**
	 * Le coupon automatique de l'hôte est-il actif ?
	 *
	 * @return bool
	 */
	public static function coupon_enabled(): bool {
		return self::at_least( self::COUPON_SINCE )
			&& class_exists( '\CWG_Instock_Auto_Coupon' )
			&& (bool) \CWG_Instock_Auto_Coupon::is_enabled();
	}

	/**
	 * Coupon d'une inscription : celui de l'alerte s'il est encore valable, sinon un nouveau.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return array{code:string, amount:string, expires:int} `code` vide si aucun coupon.
	 */
	public static function coupon_for( int $subscription_id ): array {
		$none = array(
			'code'    => '',
			'amount'  => '',
			'expires' => 0,
		);

		if ( ! self::coupon_enabled() ) {
			return $none;
		}

		$code = (string) \CWG_Instock_Auto_Coupon::get_coupon_for_subscriber( $subscription_id );

		if ( '' === $code ) {
			return $none;
		}

		return array(
			'code'    => $code,
			'amount'  => (string) \CWG_Instock_Auto_Coupon::format_amount(),
			'expires' => (int) get_post_meta( $subscription_id, self::META_COUPON_EXPIRES, true ),
		);
	}

	/**
	 * Bloc de coupon prêt à l'emploi de l'hôte.
	 *
	 * @param string $code    Code.
	 * @param string $amount  Remise, formatée.
	 * @param int    $expires Expiration (UNIX), `0` si le coupon n'expire pas.
	 * @param string $link    Lien du bouton.
	 *
	 * @return string HTML, vide si l'hôte ne le fournit pas.
	 */
	public static function coupon_box_html( string $code, string $amount, int $expires, string $link ): string {
		if ( '' === $code || ! function_exists( 'cwg_instock_get_coupon_box_html' ) ) {
			return '';
		}

		return (string) cwg_instock_get_coupon_box_html(
			$code,
			$amount,
			$expires > 0 ? wp_date( (string) get_option( 'date_format' ), $expires ) : '',
			$link
		);
	}

	/**
	 * Nom lisible de l'extension hôte.
	 *
	 * Volontairement non traduit : c'est un nom propre, il doit rester
	 * cherchable tel quel dans l'écran d'ajout d'extensions.
	 *
	 * @return string
	 */
	public static function name(): string {
		return 'Back In Stock Notifier for WooCommerce';
	}
}
