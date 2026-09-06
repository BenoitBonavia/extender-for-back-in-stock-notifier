<?php
/**
 * Reconnaissance du visiteur et de ses inscriptions.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Retrouve les inscriptions du visiteur courant pour un produit donné.
 *
 * Deux chemins seulement, et jamais l'adresse e-mail saisie à l'écran : laisser
 * quelqu'un désigner une adresse permettrait de désabonner autrui en devinant
 * son adresse.
 *
 * 1. Le compte WordPress, quand la personne est connectée — certitude totale.
 * 2. Le jeton de navigateur, pour un invité revenant sur le même appareil.
 */
final class SubscriberLocator {

	/**
	 * Statuts depuis lesquels un désabonnement a du sens.
	 *
	 * Une inscription déjà convertie ou désinscrite n'a rien à désabonner ; un
	 * échec d'envoi non plus, il relève du marchand.
	 *
	 * @return string[]
	 */
	public static function active_statuses(): array {
		/**
		 * Statuts d'inscription pouvant être désabonnés.
		 *
		 * @param string[] $statuses Statuts.
		 */
		return (array) apply_filters(
			'ebisn_unsubscribable_statuses',
			array( Host::STATUS_SUBSCRIBED, Host::STATUS_QUEUED, Host::STATUS_MAILSENT )
		);
	}

	/**
	 * Le visiteur courant a-t-il la moindre inscription en cours ?
	 *
	 * Court-circuit indispensable : sur un produit variable, l'extension hôte
	 * évalue l'affichage du formulaire pour CHAQUE déclinaison. Sans ce test,
	 * un produit à vingt tailles déclencherait vingt requêtes — pour un
	 * résultat vide dans l'immense majorité des cas.
	 *
	 * @var bool|null
	 */
	private $has_any = null;

	/**
	 * Inscriptions du visiteur courant sur un produit ou une déclinaison.
	 *
	 * @param int $subscribed_id Produit ou variation, tel que stocké par l'hôte.
	 *
	 * @return int[] Identifiants d'inscriptions, la plus récente d'abord.
	 */
	public function find_for_current_visitor( int $subscribed_id ): array {
		if ( $subscribed_id <= 0 ) {
			return array();
		}

		$identity = $this->identity_clauses();

		if ( empty( $identity['where'] ) ) {
			return array();
		}

		if ( ! $this->has_any_subscription() ) {
			return array();
		}

		global $wpdb;

		$statuses = self::active_statuses();

		if ( empty( $statuses ) ) {
			return array();
		}

		$sql = "SELECT p.ID
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} uid
						 ON uid.post_id = p.ID
						AND uid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} visitor
						 ON visitor.post_id = p.ID
						AND visitor.meta_key = %s
				 WHERE p.post_type = %s
				   AND pid.meta_value = %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				   AND ( ' . implode( ' OR ', $identity['where'] ) . ' )
				 ORDER BY p.ID DESC';

		$values = array_merge(
			array( Host::META_PID, Host::META_USER_ID, VisitorCookie::META, Host::SUBSCRIBER_TYPE, $subscribed_id ),
			$statuses,
			$identity['params']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; seuls les noms de tables sont interpolés.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Le visiteur courant possède-t-il cette inscription ?
	 *
	 * Vérification faite systématiquement AVANT d'écrire quoi que ce soit : un
	 * jeton de sécurité atteste de l'origine d'une requête, jamais de l'identité
	 * de qui l'envoie.
	 *
	 * @param int $subscription_id Inscription visée.
	 *
	 * @return bool
	 */
	public function owns( int $subscription_id ): bool {
		if ( $subscription_id <= 0 || Host::SUBSCRIBER_TYPE !== get_post_type( $subscription_id ) ) {
			return false;
		}

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			if ( (int) get_post_meta( $subscription_id, Host::META_USER_ID, true ) === $user_id ) {
				return true;
			}

			// Inscription faite en invité, avec l'adresse du compte aujourd'hui
			// connecté. Cette adresse est vérifiée par WordPress.
			$email = $this->current_user_email();

			if ( '' !== $email && strtolower( (string) get_the_title( $subscription_id ) ) === strtolower( $email ) ) {
				return true;
			}
		}

		$token = VisitorCookie::current();

		if ( '' !== $token && (string) get_post_meta( $subscription_id, VisitorCookie::META, true ) === $token ) {
			return true;
		}

		return false;
	}

	/**
	 * Décrit, en clair, pourquoi le visiteur est reconnu — ou ne l'est pas.
	 *
	 * Destiné au diagnostic : sans cela, un formulaire d'inscription qui reste
	 * affiché ne dit pas si le visiteur n'a simplement aucune demande en cours,
	 * ou si quelque chose l'empêche d'être reconnu.
	 *
	 * @param int $subscribed_id Produit ou variation affiché.
	 *
	 * @return string
	 */
	public function explain( int $subscribed_id ): string {
		$parts = array();

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$email = $this->current_user_email();

			$parts[] = sprintf(
				/* translators: 1: identifiant du compte, 2: adresse du compte. */
				__( 'connecté (compte #%1$d, %2$s)', 'extender-for-back-in-stock-notifier' ),
				$user_id,
				'' !== $email ? $email : __( 'sans adresse', 'extender-for-back-in-stock-notifier' )
			);
		} else {
			$parts[] = __( 'non connecté', 'extender-for-back-in-stock-notifier' );
		}

		$parts[] = '' !== VisitorCookie::current()
			? __( 'cookie présent', 'extender-for-back-in-stock-notifier' )
			: __( 'aucun cookie', 'extender-for-back-in-stock-notifier' );

		$found = $this->find_for_current_visitor( $subscribed_id );
		$total = $this->count_all_for( $subscribed_id );

		$parts[] = sprintf(
			/* translators: 1: identifiant produit, 2: demandes reconnues, 3: demandes en base. */
			__( 'produit #%1$d : %2$d demande(s) reconnue(s) sur %3$d en cours', 'extender-for-back-in-stock-notifier' ),
			$subscribed_id,
			count( $found ),
			$total
		);

		if ( empty( $found ) && $total > 0 ) {
			$parts[] = __( 'ces demandes appartiennent à quelqu’un d’autre, ou ont été créées en invité sans cookie — seul le lien e-mail les atteint', 'extender-for-back-in-stock-notifier' );
		}

		if ( 0 === $total ) {
			$parts[] = __( 'aucune demande en cours sur ce produit', 'extender-for-back-in-stock-notifier' );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * Nombre de demandes en cours sur un produit, tous visiteurs confondus.
	 *
	 * @param int $subscribed_id Produit ou variation.
	 *
	 * @return int
	 */
	private function count_all_for( int $subscribed_id ): int {
		global $wpdb;

		$statuses = self::active_statuses();

		if ( empty( $statuses ) ) {
			return 0;
		}

		$sql = "SELECT COUNT(*)
				  FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pid
						 ON pid.post_id = p.ID
						AND pid.meta_key = %s
				 WHERE p.post_type = %s
				   AND pid.meta_value = %d
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';

		$values = array_merge(
			array( Host::META_PID, Host::SUBSCRIBER_TYPE, $subscribed_id ),
			$statuses
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; affichage de diagnostic réservé aux gestionnaires.
		$count = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return (int) $count;
	}

	/**
	 * Le visiteur a-t-il au moins une inscription, tous produits confondus ?
	 *
	 * Résultat mémorisé pour la durée de la requête : l'appel se répète autant
	 * de fois qu'il y a de déclinaisons sur la page.
	 *
	 * @return bool
	 */
	private function has_any_subscription(): bool {
		if ( null !== $this->has_any ) {
			return $this->has_any;
		}

		global $wpdb;

		$identity = $this->identity_clauses();
		$statuses = self::active_statuses();

		if ( empty( $identity['where'] ) || empty( $statuses ) ) {
			$this->has_any = false;

			return false;
		}

		$sql = "SELECT p.ID
				  FROM {$wpdb->posts} p
				  LEFT JOIN {$wpdb->postmeta} uid
						 ON uid.post_id = p.ID
						AND uid.meta_key = %s
				  LEFT JOIN {$wpdb->postmeta} visitor
						 ON visitor.post_id = p.ID
						AND visitor.meta_key = %s
				 WHERE p.post_type = %s
				   AND p.post_status IN ( " . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )
				   AND ( ' . implode( ' OR ', $identity['where'] ) . ' )
				 LIMIT 1';

		$values = array_merge(
			array( Host::META_USER_ID, VisitorCookie::META, Host::SUBSCRIBER_TYPE ),
			$statuses,
			$identity['params']
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; résultat mémorisé pour la requête.
		$found = $wpdb->get_var( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		$this->has_any = null !== $found;

		return $this->has_any;
	}

	/**
	 * Clauses SQL identifiant le visiteur courant.
	 *
	 * @return array{where: string[], params: array<int, int|string>}
	 */
	private function identity_clauses(): array {
		$where  = array();
		$params = array();

		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$where[]  = 'uid.meta_value = %d';
			$params[] = $user_id;

			/*
			 * L'adresse du compte, en plus de son identifiant : quelqu'un ayant
			 * demandé l'alerte en tant qu'invité, puis créé un compte ou s'étant
			 * connecté ensuite, ne serait sinon jamais reconnu — son inscription
			 * porte `cwginstock_user_id = 0`.
			 *
			 * S'appuyer sur l'adresse du compte connecté est sûr : elle est
			 * vérifiée par WordPress, contrairement à une adresse qu'on
			 * laisserait saisir à l'écran.
			 */
			$email = $this->current_user_email();

			if ( '' !== $email ) {
				// L'extension hôte recopie l'adresse dans le titre du contenu.
				$where[]  = 'p.post_title = %s';
				$params[] = $email;
			}
		}

		$token = VisitorCookie::current();

		if ( '' !== $token ) {
			$where[]  = 'visitor.meta_value = %s';
			$params[] = $token;
		}

		return array(
			'where'  => $where,
			'params' => $params,
		);
	}

	/**
	 * Adresse du compte connecté.
	 *
	 * @return string Chaîne vide si personne n'est connecté.
	 */
	private function current_user_email(): string {
		$user = wp_get_current_user();

		if ( ! $user instanceof \WP_User || ! $user->exists() ) {
			return '';
		}

		$email = sanitize_email( (string) $user->user_email );

		return is_email( $email ) ? $email : '';
	}
}
