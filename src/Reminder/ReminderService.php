<?php
/**
 * Relance d'une inscription déjà notifiée.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Reminder;

use EBISN\Conversion\OrderLookup;
use EBISN\Conversion\OrderMatcher;
use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Database;
use EBISN\Support\Logger;
use EBISN\Support\Settings;
use EBISN\Tracking\DeliveryHistory;

defined( 'ABSPATH' ) || exit;

/**
 * Décide si une inscription doit être relancée et, si oui, envoie l'e-mail.
 *
 * Le principe directeur est qu'une relance perdue vaut mieux qu'une relance en
 * double : la personne a déjà été prévenue, un second e-mail identique est un
 * irritant, un e-mail manquant n'est rien. L'écriture « cette relance est
 * traitée » précède donc l'envoi, et un échec n'est jamais retenté.
 *
 * Chaque relance occupe un rang (1 ou 2). `_ebisn_reminder_sent` retient le
 * dernier rang traité — envoyé OU sauté — pour le cycle d'alerte identifié par
 * `_ebisn_reminder_cycle`, qui reprend `cwginstock_mail_on`. Une inscription
 * remise en attente puis notifiée de nouveau change de `mail_on` : son compteur
 * est alors invalide, donc remis à zéro, sans rien avoir à nettoyer.
 */
final class ReminderService {

	/**
	 * Dernier rang de relance traité sur le cycle courant.
	 */
	public const META_SENT = '_ebisn_reminder_sent';

	/**
	 * Cycle d'alerte (`cwginstock_mail_on`) auquel `META_SENT` se rapporte.
	 */
	public const META_CYCLE = '_ebisn_reminder_cycle';

	/**
	 * Cycle d'alerte (`cwginstock_mail_on`) sur lequel la séquence de relances a démarré.
	 *
	 * Contrairement à `META_CYCLE`, il ne se renouvelle JAMAIS : une inscription
	 * n'a droit qu'à une séquence de relances, et ne la rejoue pas après une
	 * nouvelle alerte. Écrit à l'envoi de la première relance — pas à une relance sautée.
	 */
	public const META_FIRST_CYCLE = '_ebisn_reminded_cycle';

	/**
	 * Dernier échec d'envoi.
	 */
	public const META_ERROR = '_ebisn_reminder_error';

	/**
	 * Date d'envoi de la relance de rattrapage, absente des relances automatiques.
	 */
	public const META_CATCHUP = '_ebisn_reminder_catchup_at';

	/**
	 * Délai par défaut de la première relance, en jours.
	 */
	public const DEFAULT_DELAY_1 = 3;

	/**
	 * Délai par défaut de la seconde relance, en jours, depuis l'alerte.
	 */
	public const DEFAULT_DELAY_2 = 7;

	/**
	 * Âge au-delà duquel une relance n'a plus de sens, en jours après son échéance.
	 *
	 * Protège d'un envoi massif de relances périmées après une longue panne du
	 * planificateur, ou après une désactivation puis une réactivation du module.
	 */
	public const STALE_AFTER_DAYS = 14;

	/**
	 * Relance envoyée.
	 */
	public const RESULT_SENT = 'sent';

	/**
	 * Relance sautée, et notée comme telle.
	 */
	public const RESULT_SKIPPED = 'skipped';

	/**
	 * Rien n'a été fait, et l'on ne peut pas aller plus loin pour l'instant :
	 * limiteur d'envois saturé, ou e-mail désactivé. À reprendre au passage suivant.
	 */
	public const RESULT_DEFERRED = 'deferred';

	/**
	 * L'inscription n'est plus concernée — statut changé, rang déjà traité — :
	 * rien n'a été fait, et l'appelant peut passer à la suivante.
	 */
	public const RESULT_IGNORED = 'ignored';

	/**
	 * L'envoi a échoué ; il n'est pas retenté.
	 */
	public const RESULT_FAILED = 'failed';

	/**
	 * Simulation : rien n'a été envoyé ni écrit.
	 */
	public const RESULT_DRY_RUN = 'dry_run';

	/**
	 * Recherche de commandes.
	 *
	 * @var OrderLookup
	 */
	private $orders;

	/**
	 * Constructeur.
	 *
	 * @param OrderLookup|null $orders Recherche de commandes.
	 */
	public function __construct( ?OrderLookup $orders = null ) {
		$this->orders = $orders ?? new OrderLookup();
	}

	/**
	 * Nombre de relances configurées.
	 *
	 * @return int `1` ou `2`.
	 */
	public static function max_steps(): int {
		return Settings::get_bool( 'reminder_second_enabled', false ) ? 2 : 1;
	}

	/**
	 * Délai d'une relance depuis l'alerte, en secondes.
	 *
	 * La seconde est forcée strictement après la première : deux relances le même
	 * jour n'auraient aucun sens, et un réglage incohérent ne doit pas les
	 * provoquer.
	 *
	 * @param int $step Rang de la relance.
	 *
	 * @return int
	 */
	public static function delay_seconds( int $step ): int {
		$first = max( 1, (int) Settings::get( 'reminder_delay_1_days', self::DEFAULT_DELAY_1 ) );

		if ( $step <= 1 ) {
			return $first * DAY_IN_SECONDS;
		}

		$second = max( $first + 1, (int) Settings::get( 'reminder_delay_2_days', self::DEFAULT_DELAY_2 ) );

		return $second * DAY_IN_SECONDS;
	}

	/**
	 * Plancher de stock en dessous duquel on ne relance plus.
	 *
	 * @return int
	 */
	public static function stock_floor(): int {
		return max( 0, (int) Settings::get( 'reminder_stock_floor', 0 ) );
	}

	/**
	 * Date d'activation du module : les alertes antérieures ne sont jamais relancées.
	 *
	 * Posée au premier appel. Il n'y a volontairement aucun rattrapage : activer
	 * le module ne doit pas envoyer, d'un coup, une relance à chaque personne
	 * notifiée ces derniers mois.
	 *
	 * @return int Horodatage UNIX UTC.
	 */
	public static function since(): int {
		$since = (int) Settings::get( 'reminder_since', 0 );

		if ( $since > 0 ) {
			return $since;
		}

		$since = time();

		Settings::update( 'reminder_since', $since );

		return $since;
	}

	/**
	 * Interruption au-delà de laquelle l'activation est considérée comme neuve, en jours.
	 */
	public const GAP_RESET_DAYS = 2;

	/**
	 * Enregistre qu'un passage a eu lieu, et repart d'une activation neuve après une longue interruption.
	 *
	 * Le module peut être coupé puis réactivé des semaines plus tard — ou le
	 * planificateur rester à l'arrêt. Sans cela, la date d'activation d'origine
	 * rendrait éligibles les alertes envoyées pendant la coupure, que le marchand
	 * n'a jamais choisi de relancer. Elles relèvent alors du rattrapage, à la demande.
	 */
	public static function touch(): void {
		$now  = time();
		$last = (int) Settings::get( 'reminder_last_run', 0 );

		if ( $last > 0 && $now - $last > self::GAP_RESET_DAYS * DAY_IN_SECONDS ) {
			Settings::update( 'reminder_since', $now );

			Logger::warning( 'Relances : module interrompu plus de deux jours, la date d’activation repart d’aujourd’hui.' );
		}

		Settings::update( 'reminder_last_run', $now );
	}

	/**
	 * Le rang donné est-il dû, à cet instant, pour une alerte envoyée à `$mail_on` ?
	 *
	 * @param int $mail_on Alerte (UNIX UTC).
	 * @param int $sent    Rangs déjà traités sur ce cycle.
	 * @param int $now     Instant courant.
	 *
	 * @return int Rang à traiter, `0` si aucun.
	 */
	public static function next_step( int $mail_on, int $sent, int $now ): int {
		$step = $sent + 1;

		if ( $step > self::max_steps() ) {
			return 0;
		}

		return $mail_on + self::delay_seconds( $step ) <= $now ? $step : 0;
	}

	/**
	 * Traite une relance.
	 *
	 * Le rattrapage (`$catchup`) relance, à la demande, une inscription qui n'a
	 * JAMAIS été relancée sur ce cycle d'alerte, quel que soit son âge — donc sans
	 * la règle de péremption. Elle ne recevra pas de seconde relance ensuite : le
	 * rang final est consigné d'emblée, sans quoi une alerte vieille de huit jours
	 * recevrait la première puis la seconde à quelques heures d'écart.
	 *
	 * @param int  $subscription_id Inscription.
	 * @param int  $step            Rang de la relance, `1` ou `2`.
	 * @param bool $catchup         Rattrapage à la demande plutôt que passage automatique.
	 *
	 * @return string L'une des constantes `RESULT_*`.
	 */
	public function process( int $subscription_id, int $step, bool $catchup = false ): string {
		if ( Host::STATUS_MAILSENT !== (string) get_post_status( $subscription_id ) ) {
			return self::RESULT_IGNORED;
		}

		$mail_on = (int) get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

		if ( $mail_on <= 0 ) {
			return self::RESULT_IGNORED;
		}

		// Le rang demandé doit être celui qui suit le dernier traité sur CE cycle.
		$sent = (int) get_post_meta( $subscription_id, self::META_CYCLE, true ) === $mail_on
			? (int) get_post_meta( $subscription_id, self::META_SENT, true )
			: 0;

		if ( $catchup ? ( 1 !== $step || 0 !== $sent ) : ( $sent + 1 !== $step || $step > self::max_steps() ) ) {
			return self::RESULT_IGNORED;
		}

		/*
		 * Une seule séquence par inscription, pas par alerte. Si des relances sont
		 * déjà parties sur une alerte PRÉCÉDENTE, celle-ci n'en reçoit aucune : le
		 * rang 2 d'une séquence entamée sur la MÊME alerte reste, lui, permis.
		 * Le rattrapage, lui, ne prend que les inscriptions jamais relancées.
		 */
		$first = (int) get_post_meta( $subscription_id, self::META_FIRST_CYCLE, true );

		if ( $catchup ? $first > 0 : ( $first > 0 && $first !== $mail_on ) ) {
			return self::RESULT_IGNORED;
		}

		// Rang consigné : le dernier configuré pour un rattrapage, qui n'a pas de suite.
		$recorded = $catchup ? self::max_steps() : $step;

		$reason = $this->blocking_reason( $subscription_id, $step, $mail_on, $catchup );

		/**
		 * Simule l'envoi : journalise ce qui partirait, sans rien envoyer ni écrire.
		 *
		 * Vaut aussi pour une relance qui serait sautée : une simulation ne doit pas
		 * consommer le rang, sans quoi la vraie relance ne partirait jamais.
		 *
		 * @param bool $dry_run         Vrai pour simuler. Faux par défaut.
		 * @param int  $subscription_id Inscription.
		 * @param int  $step            Rang de la relance.
		 */
		$dry_run = (bool) apply_filters( 'ebisn_reminder_dry_run', false, $subscription_id, $step );

		if ( $dry_run ) {
			Logger::info(
				sprintf(
					'Relance %1$d simulée pour l’inscription #%2$d%3$s.',
					$step,
					$subscription_id,
					'' !== $reason ? ' (serait sautée : ' . $reason . ')' : ''
				)
			);

			return self::RESULT_DRY_RUN;
		}

		if ( '' !== $reason ) {
			$this->mark_skipped( $subscription_id, $step, $mail_on, $reason, $recorded );

			return self::RESULT_SKIPPED;
		}

		// Sans les utilitaires de l'hôte, le message partirait vide et le rang serait perdu.
		if ( null === Host::api() ) {
			Logger::warning( 'Relances suspendues : les utilitaires de l’extension hôte sont introuvables.' );

			return self::RESULT_DEFERRED;
		}

		$email = ReminderMailer::email();

		if ( null === $email || ! $email->is_enabled() ) {
			return self::RESULT_DEFERRED;
		}

		if ( ! Host::try_acquire_send_slot() ) {
			return self::RESULT_DEFERRED;
		}

		// Avant l'envoi : voir la description de la classe.
		update_post_meta( $subscription_id, self::META_CYCLE, $mail_on );
		update_post_meta( $subscription_id, self::META_SENT, $recorded );

		// Marque l'alerte qui porte la séquence — jamais écrit pour une relance sautée.
		if ( 0 === $first ) {
			update_post_meta( $subscription_id, self::META_FIRST_CYCLE, $mail_on );
		}

		$delivered = $email->trigger( $subscription_id, $step, self::max_steps() === $step );

		if ( true !== $delivered ) {
			update_post_meta( $subscription_id, self::META_ERROR, sprintf( 'relance %1$d, %2$s', $step, gmdate( 'Y-m-d H:i:s' ) . ' UTC' ) );

			Logger::error( sprintf( 'Relance %1$d : échec d’envoi pour l’inscription #%2$d (non retentée).', $step, $subscription_id ) );

			return self::RESULT_FAILED;
		}

		delete_post_meta( $subscription_id, self::META_ERROR );
		update_post_meta( $subscription_id, self::meta_sent_at( $step ), time() );

		if ( $catchup ) {
			update_post_meta( $subscription_id, self::META_CATCHUP, time() );
		}

		// Toujours consignée, que le module d'affichage soit actif ou non.
		DeliveryHistory::record_reminder( $subscription_id, $step, $catchup );

		Logger::info(
			sprintf(
				'Relance %1$d envoyée pour l’inscription #%2$d%3$s.',
				$step,
				$subscription_id,
				$catchup ? ' (rattrapage)' : ''
			)
		);

		/**
		 * Une relance vient d'être envoyée.
		 *
		 * @param int $subscription_id Inscription.
		 * @param int $step            Rang de la relance.
		 */
		do_action( 'ebisn_reminder_sent', $subscription_id, $step );

		return self::RESULT_SENT;
	}

	/**
	 * Reprend l'existant, une seule fois : voir `migrate_first_cycle()`.
	 */
	public static function ensure_migrated(): void {
		if ( Settings::get_bool( 'reminder_first_cycle_migrated', false ) ) {
			return;
		}

		if ( self::migrate_first_cycle() ) {
			Settings::update( 'reminder_first_cycle_migrated', 'yes' );
		}
	}

	/**
	 * Pose le marqueur de séquence sur les inscriptions déjà relancées avant la règle.
	 *
	 * Les versions précédentes comptaient les relances PAR alerte, sans marqueur de
	 * vie entière. Une inscription qui a reçu une relance — `_ebisn_reminder_{n}_at`
	 * — garde donc, comme alerte porteuse, celle que `_ebisn_reminder_cycle`
	 * désigne. Sans cette reprise, elle serait relancée une seconde fois à sa
	 * prochaine alerte.
	 *
	 * Limite connue : si une relance a depuis été SAUTÉE sur une alerte ultérieure,
	 * `_ebisn_reminder_cycle` désigne cette alerte-là. Cas improbable, les versions
	 * concernées étant récentes.
	 *
	 * @return bool Faux si la requête a échoué : le drapeau n'est alors pas posé, la reprise sera retentée.
	 */
	private static function migrate_first_cycle(): bool {
		global $wpdb;

		$sql = "INSERT INTO {$wpdb->postmeta} ( post_id, meta_key, meta_value )
				SELECT c.post_id, %s, c.meta_value
				  FROM {$wpdb->postmeta} c
				 WHERE c.meta_key = %s
				   AND EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} s
						 WHERE s.post_id = c.post_id
						   AND s.meta_key IN ( %s, %s )
				   )
				   AND NOT EXISTS (
						SELECT 1 FROM {$wpdb->postmeta} f
						 WHERE f.post_id = c.post_id
						   AND f.meta_key = %s
				   )";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- requête assemblée avec des marqueurs, puis passée à prepare() ; reprise ponctuelle.
		$wpdb->query(
			$wpdb->prepare(
				$sql,
				self::META_FIRST_CYCLE,
				self::META_CYCLE,
				self::meta_sent_at( 1 ),
				self::meta_sent_at( 2 ),
				self::META_FIRST_CYCLE
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared

		return '' === Database::last_error();
	}

	/**
	 * Clé de métadonnée de l'envoi d'une relance.
	 *
	 * @param int $step Rang.
	 *
	 * @return string
	 */
	public static function meta_sent_at( int $step ): string {
		return '_ebisn_reminder_' . $step . '_at';
	}

	/**
	 * Motif pour lequel la relance ne doit PAS partir.
	 *
	 * Réévalué à l'instant de l'envoi, et non à la sélection : le stock et les
	 * commandes évoluent entre les deux, et la sélection peut dater d'un passage
	 * précédent.
	 *
	 * @param int  $subscription_id Inscription.
	 * @param int  $step            Rang.
	 * @param int  $mail_on         Alerte (UNIX UTC).
	 * @param bool $catchup         Rattrapage : la péremption ne s'applique pas, l'âge est borné par le marchand.
	 *
	 * @return string Code du motif, chaîne vide si rien ne s'y oppose.
	 */
	private function blocking_reason( int $subscription_id, int $step, int $mail_on, bool $catchup = false ): string {
		if ( ! $catchup && time() > $mail_on + self::delay_seconds( $step ) + self::STALE_AFTER_DAYS * DAY_IN_SECONDS ) {
			return 'stale';
		}

		$product_id = Host::product_id_for( $subscription_id );
		$product    = $product_id > 0 && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;

		if ( ! $product instanceof \WC_Product ) {
			return 'product_missing';
		}

		if ( ! $product->is_in_stock() ) {
			return 'out_of_stock';
		}

		$floor = self::stock_floor();

		if ( $floor > 0 && $product->managing_stock() ) {
			$quantity = $product->get_stock_quantity();

			if ( null !== $quantity && (int) $quantity <= $floor ) {
				return 'below_stock_floor';
			}
		}

		if ( $this->has_ordered( $subscription_id, $product_id ) ) {
			return 'order_exists';
		}

		/**
		 * Motif supplémentaire de ne pas relancer.
		 *
		 * @param string $reason          Chaîne vide : aucun motif.
		 * @param int    $subscription_id Inscription.
		 * @param int    $step            Rang de la relance.
		 */
		return (string) apply_filters( 'ebisn_reminder_block_reason', '', $subscription_id, $step );
	}

	/**
	 * La personne a-t-elle déjà commandé ce produit depuis son inscription ?
	 *
	 * Plus large que le statut « Purchased » : voir `OrderLookup::has_live_order_for()`.
	 *
	 * @param int $subscription_id Inscription.
	 * @param int $product_id      Produit ou variation attendu.
	 *
	 * @return bool
	 */
	private function has_ordered( int $subscription_id, int $product_id ): bool {
		$email   = OrderMatcher::normalize_email( (string) get_post_meta( $subscription_id, Host::META_EMAIL, true ) );
		$user_id = (int) get_post_meta( $subscription_id, Host::META_USER_ID, true );

		$products = array(
			$product_id,
			(int) get_post_meta( $subscription_id, Host::META_PRODUCT_ID, true ),
		);

		$post  = get_post( $subscription_id );
		$since = $post instanceof \WP_Post ? (int) strtotime( $post->post_date_gmt . ' UTC' ) : 0;

		return $this->orders->has_live_order_for(
			'' !== $email ? array( $email ) : array(),
			$user_id > 0 ? array( $user_id ) : array(),
			$products,
			max( 0, $since )
		);
	}

	/**
	 * Consigne qu'une relance n'est pas partie, et pourquoi.
	 *
	 * Le rang est consommé : une relance sautée n'est pas retentée, ce qui
	 * évite qu'une inscription dont le produit est en rupture revienne à chaque
	 * passage. La seconde relance reste évaluée indépendamment.
	 *
	 * @param int    $subscription_id Inscription.
	 * @param int    $step            Rang.
	 * @param int    $mail_on         Alerte (UNIX UTC).
	 * @param string $reason          Code du motif.
	 * @param int    $recorded        Rang à consigner : celui de la relance, ou le dernier pour un rattrapage.
	 */
	private function mark_skipped( int $subscription_id, int $step, int $mail_on, string $reason, int $recorded ): void {
		update_post_meta( $subscription_id, self::META_CYCLE, $mail_on );
		update_post_meta( $subscription_id, self::META_SENT, $recorded );
		update_post_meta( $subscription_id, '_ebisn_reminder_' . $step . '_skipped', $reason );
		update_post_meta( $subscription_id, '_ebisn_reminder_' . $step . '_skipped_at', time() );

		Logger::info(
			sprintf( 'Relance %1$d sautée pour l’inscription #%2$d : %3$s.', $step, $subscription_id, $reason )
		);

		/**
		 * Une relance vient d'être sautée.
		 *
		 * @param int    $subscription_id Inscription.
		 * @param int    $step            Rang de la relance.
		 * @param string $reason          Code du motif.
		 */
		do_action( 'ebisn_reminder_skipped', $subscription_id, $step, $reason );
	}
}
