<?php
/**
 * Module de désabonnement des alertes de réassort.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Modules;

use EBISN\Conversion\NotificationSuppressor;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Unsubscribe\EmailLink;
use EBISN\Unsubscribe\ProductForm;
use EBISN\Unsubscribe\StatusRecorder;
use EBISN\Unsubscribe\SubscriberLocator;
use EBISN\Unsubscribe\UnsubscribeService;
use EBISN\Unsubscribe\VisitorCookie;

defined( 'ABSPATH' ) || exit;

/**
 * Permet à un client de sortir d'une alerte de retour en stock.
 *
 * L'extension hôte enregistre le statut « Unsubscribed » et sait le poser
 * depuis son administration, mais n'offre aucun moyen au client de s'en
 * servir : ni bouton, ni lien dans les e-mails. Ce module fournit ce chemin.
 *
 * L'enjeu dépasse le confort. Le réglage « Keep Subscription Entry to
 * Subscribed Status even Instock Email Sent » de l'hôte renvoie l'alerte à
 * CHAQUE réassort, indéfiniment, jusqu'à ce que l'inscription soit achetée ou
 * désabonnée. Sans ce module, la personne n'a aucune porte de sortie.
 */
final class Unsubscribe extends AbstractModule {

	/**
	 * {@inheritDoc}
	 *
	 * @var string
	 */
	protected $id = 'unsubscribe';

	/**
	 * Classe de l'extension payante couvrant déjà cette fonctionnalité.
	 */
	private const HOST_ADDON = 'CWG_Instock_Notifier_Unsubscribe';

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return __(
			'L’extension hôte enregistre un statut « Unsubscribed » et sait le poser depuis son administration, mais n’offre au client aucun moyen de s’en servir. Ce module remplace le formulaire d’inscription par un bouton de désabonnement pour qui est déjà inscrit, et met un lien signé à disposition des gabarits d’e-mail — seul recours fiable pour une personne sans compte. Un désabonnement reste annulable. Les visiteurs non connectés sont reconnus par un cookie ne contenant qu’un identifiant aléatoire, jamais leur adresse.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_title(): string {
		return __( 'Permettre le désabonnement d’une alerte', 'extender-for-back-in-stock-notifier' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		add_filter( 'ebisn_diagnostics_lines', array( $this, 'add_diagnostics' ) );

		if ( $this->addon_active() ) {
			return;
		}

		$service = new UnsubscribeService();

		/*
		 * Enregistré en premier, et sans condition d'affichage : le statut
		 * d'origine doit être capté même quand le désabonnement vient de
		 * l'administration de l'hôte ou de sa récupération de file.
		 */
		( new StatusRecorder() )->register();

		( new EmailLink( $service ) )->register();

		( new ProductForm( $service, new SubscriberLocator() ) )->register();

		/*
		 * Le blocage d'envoi appartient au module de conversion, qui le pose
		 * déjà pour les acheteurs. S'il est désactivé, on l'accroche ici :
		 * envoyer « votre produit est de retour » à quelqu'un qui vient de se
		 * désabonner est le pire résultat possible.
		 */
		if ( ! $this->conversion_module_handles_emails() ) {
			( new NotificationSuppressor() )->register();
		}
	}

	/**
	 * L'add-on payant de l'hôte est-il présent ?
	 *
	 * @return bool
	 */
	private function addon_active(): bool {
		return class_exists( self::HOST_ADDON );
	}

	/**
	 * Le module de conversion bloque-t-il déjà les envois ?
	 *
	 * @return bool
	 */
	private function conversion_module_handles_emails(): bool {
		return has_filter( Host::HOOK_STOP_EMAIL );
	}

	/**
	 * Ajoute l'état du module au panneau Diagnostic.
	 *
	 * @param string[] $lines Lignes existantes.
	 *
	 * @return string[]
	 */
	public function add_diagnostics( $lines ): array {
		$lines = (array) $lines;

		if ( $this->addon_active() ) {
			$lines[] = '<strong style="color:#b26200">' . esc_html__(
				'Désabonnement : module en veille, l’extension « Unsubscribe » de ProPluginsLab est active et couvre déjà cette fonctionnalité.',
				'extender-for-back-in-stock-notifier'
			) . '</strong>';

			return $lines;
		}

		$lines[] = sprintf(
			/* translators: 1: nombre de désabonnements, 2: état de la mémorisation par cookie. */
			esc_html__( 'Désabonnements enregistrés : %1$s · mémorisation des visiteurs %2$s', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( $this->count_unsubscribed() ) ) . '</strong>',
			VisitorCookie::is_enabled()
				? '<code>' . esc_html__( 'activée', 'extender-for-back-in-stock-notifier' ) . '</code>'
				: '<code>' . esc_html__( 'désactivée', 'extender-for-back-in-stock-notifier' ) . '</code>'
		);

		$lines[] = $this->reachability_line();

		if ( $this->repeat_notifications_enabled() ) {
			$lines[] = sprintf(
				/* translators: %s: nom du réglage de l'extension hôte. */
				esc_html__( 'Le réglage %s de l’extension hôte est actif : sans désabonnement, une alerte est renvoyée à chaque réassort, indéfiniment.', 'extender-for-back-in-stock-notifier' ),
				'<code>' . esc_html__( 'Keep Subscription Entry to Subscribed Status', 'extender-for-back-in-stock-notifier' ) . '</code>'
			);
		}

		return $lines;
	}

	/**
	 * Décrit combien d'inscriptions peuvent afficher le bouton.
	 *
	 * Le bouton suppose de reconnaître le visiteur. Une inscription faite en
	 * invité avant l'installation de ce module ne porte ni compte ni jeton de
	 * navigateur : elle n'est joignable que par le lien envoyé en e-mail. Ce
	 * décompte évite d'avoir à le deviner.
	 *
	 * @return string
	 */
	private function reachability_line(): string {
		global $wpdb;

		$statuses     = \EBISN\Unsubscribe\SubscriberLocator::active_statuses();
		$placeholders = implode( ', ', array_fill( 0, max( 1, count( $statuses ) ), '%s' ) );

		$sql = "SELECT COUNT(*) AS total,
					   SUM( CASE WHEN COALESCE( uid.meta_value, '0' ) <> '0' THEN 1 ELSE 0 END ) AS with_account,
					   SUM( CASE WHEN visitor.meta_value IS NOT NULL THEN 1 ELSE 0 END ) AS with_browser
				  FROM {$wpdb->posts} p
				  LEFT JOIN {$wpdb->postmeta} uid
						 ON uid.post_id = p.ID
						AND uid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} visitor
						 ON visitor.post_id = p.ID
						AND visitor.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status IN ( {$placeholders} )";

		$values = array_merge(
			array( Host::META_USER_ID, \EBISN\Unsubscribe\VisitorCookie::META, Host::SUBSCRIBER_TYPE ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; affichage du panneau Diagnostic.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		if ( ! $row ) {
			return '';
		}

		$total     = (int) $row->total;
		$reachable = (int) $row->with_account + (int) $row->with_browser;

		if ( 0 === $total ) {
			return '';
		}

		$line = sprintf(
			/* translators: 1: inscriptions reconnaissables, 2: total, 3: nombre liées à un compte, 4: nombre liées à un navigateur. */
			esc_html__( 'Bouton de désabonnement affichable pour %1$s inscription(s) en cours sur %2$s : %3$s liée(s) à un compte client, %4$s à un navigateur.', 'extender-for-back-in-stock-notifier' ),
			'<strong>' . esc_html( number_format_i18n( $reachable ) ) . '</strong>',
			'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>',
			esc_html( number_format_i18n( (int) $row->with_account ) ),
			esc_html( number_format_i18n( (int) $row->with_browser ) )
		);

		if ( $reachable >= $total ) {
			return $line;
		}

		return $line . ' ' . esc_html__(
			'Les autres ont été créées en tant qu’invité avant l’activation de ce module : seul le lien inséré dans vos e-mails permet de les désabonner.',
			'extender-for-back-in-stock-notifier'
		);
	}

	/**
	 * Nombre d'inscriptions désabonnées.
	 *
	 * @return int
	 */
	private function count_unsubscribed(): int {
		$counts = wp_count_posts( Host::SUBSCRIBER_TYPE );
		$status = Host::STATUS_UNSUBSCRIBED;

		return isset( $counts->$status ) ? (int) $counts->$status : 0;
	}

	/**
	 * L'hôte renvoie-t-il l'alerte à chaque réassort ?
	 *
	 * @return bool
	 */
	private function repeat_notifications_enabled(): bool {
		return Host::keeps_subscribed_after_notification();
	}
}
