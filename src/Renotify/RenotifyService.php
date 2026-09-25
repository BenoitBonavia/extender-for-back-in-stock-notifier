<?php
/**
 * Remise en attente d'une inscription déjà notifiée.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Renotify;

use EBISN\Integration\BackInStockNotifier as Host;
use EBISN\Support\Logger;
use EBISN\Support\Settings;
use EBISN\Unsubscribe\UnsubscribeService;

defined( 'ABSPATH' ) || exit;

/**
 * Fait repasser en « En attente » une inscription qui avait été notifiée, ou la
 * désabonne une fois son quota d'alertes épuisé.
 *
 * L'écriture du retour en attente est déléguée à `CWG_Instock_API::subscriber_subscribed()`,
 * comme le désabonnement l'est à sa méthode jumelle : l'hôte pose lui-même ce
 * statut par cette voie, et l'emprunter laisse passer `transition_post_status`,
 * donc ses webhooks.
 *
 * La métadonnée `cwginstock_mail_on` n'est JAMAIS effacée au passage — elle est
 * même écrite si elle manquait. C'est elle qui garde la trace qu'une alerte a
 * déjà été envoyée, et c'est elle qui fait entrer une inscription dans le
 * dénominateur du taux de conversion.
 *
 * Une inscription dont le quota de remises en attente est épuisé n'est PAS
 * laissée en « Alerte envoyée » : elle a eu ses chances, et un état mort ne
 * ferait que fausser le taux dans un sens comme dans l'autre. Elle est
 * désabonnée, par `UnsubscribeService` — même chemin qu'un désabonnement
 * volontaire, donc réversible depuis l'administration.
 */
final class RenotifyService {

	/**
	 * Nombre de remises en attente déjà appliquées à une inscription.
	 */
	public const META_CYCLES = '_ebisn_notify_cycles';

	/**
	 * Horodatage de la dernière remise en attente.
	 */
	public const META_LAST = '_ebisn_renotified_at';

	/**
	 * Nombre maximal de remises en attente par défaut, avant désabonnement automatique.
	 */
	public const DEFAULT_MAX_CYCLES = 3;

	/**
	 * `process()` a remis l'inscription en attente.
	 */
	public const RESULT_RENOTIFIED = 'renotified';

	/**
	 * `process()` a désabonné l'inscription, son quota étant épuisé.
	 */
	public const RESULT_UNSUBSCRIBED = 'unsubscribed';

	/**
	 * `process()` n'a rien fait : statut hors périmètre, ou écriture refusée.
	 */
	public const RESULT_SKIPPED = 'skipped';

	/**
	 * Service de désabonnement, pour l'inscription dont le quota est épuisé.
	 *
	 * @var UnsubscribeService
	 */
	private $unsubscribe;

	/**
	 * Constructeur.
	 *
	 * @param UnsubscribeService|null $unsubscribe Service de désabonnement, instancié
	 *                                              par défaut pour ne pas obliger chaque
	 *                                              appelant à le fournir.
	 */
	public function __construct( ?UnsubscribeService $unsubscribe = null ) {
		$this->unsubscribe = $unsubscribe ?? new UnsubscribeService();
	}

	/**
	 * Statuts depuis lesquels une remise en attente a du sens.
	 *
	 * Uniquement « Alerte envoyée » : une inscription convertie ou désabonnée a
	 * quitté le cycle, et un échec d'envoi relève du marchand.
	 *
	 * @return string[]
	 */
	public static function source_statuses(): array {
		/**
		 * Statuts pouvant être remis en attente lors d'une rupture.
		 *
		 * @param string[] $statuses Statuts.
		 */
		return (array) apply_filters(
			'ebisn_renotify_source_statuses',
			array( Host::STATUS_MAILSENT )
		);
	}

	/**
	 * Nombre maximal de remises en attente par inscription.
	 *
	 * @return int `0` pour aucune limite.
	 */
	public static function max_cycles(): int {
		return max( 0, (int) Settings::get( 'renotify_max_cycles', self::DEFAULT_MAX_CYCLES ) );
	}

	/**
	 * Traite une inscription déjà notifiée dont le produit repasse en rupture.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return string L'une des constantes `RESULT_*`.
	 */
	public function process( int $subscription_id ): string {
		$current = (string) get_post_status( $subscription_id );

		if ( ! in_array( $current, self::source_statuses(), true ) ) {
			return self::RESULT_SKIPPED;
		}

		$cycles = (int) get_post_meta( $subscription_id, self::META_CYCLES, true );
		$max    = self::max_cycles();

		if ( $max > 0 && $cycles >= $max ) {
			return $this->exhaust( $subscription_id, $cycles, $max );
		}

		$this->preserve_notified_trace( $subscription_id );

		if ( ! $this->write_status( $subscription_id ) ) {
			return self::RESULT_SKIPPED;
		}

		update_post_meta( $subscription_id, self::META_CYCLES, $cycles + 1 );
		update_post_meta( $subscription_id, self::META_LAST, time() );

		/**
		 * Une inscription vient d'être remise en attente.
		 *
		 * @param int $subscription_id Inscription.
		 * @param int $cycles          Nombre de remises en attente, celle-ci comprise.
		 */
		do_action( 'ebisn_subscription_renotified', $subscription_id, $cycles + 1 );

		return self::RESULT_RENOTIFIED;
	}

	/**
	 * Désabonne une inscription dont le quota de remises en attente est épuisé.
	 *
	 * @param int $subscription_id Inscription.
	 * @param int $cycles          Remises en attente déjà appliquées.
	 * @param int $max             Quota atteint.
	 *
	 * @return string
	 */
	private function exhaust( int $subscription_id, int $cycles, int $max ): string {
		if ( ! $this->unsubscribe->unsubscribe( $subscription_id, 'renotify' ) ) {
			return self::RESULT_SKIPPED;
		}

		Logger::info(
			sprintf(
				'Inscription #%1$d : plafond de %2$d alerte(s) atteint (%3$d remise(s) en attente) — désabonnement automatique.',
				$subscription_id,
				$max,
				$cycles
			)
		);

		/**
		 * Une inscription vient d'être désabonnée pour avoir épuisé son quota
		 * de remises en attente.
		 *
		 * @param int $subscription_id Inscription.
		 * @param int $cycles          Remises en attente déjà appliquées.
		 */
		do_action( 'ebisn_subscription_renotify_exhausted', $subscription_id, $cycles );

		return self::RESULT_UNSUBSCRIBED;
	}

	/**
	 * Garantit qu'une trace de l'alerte déjà envoyée subsistera après la bascule.
	 *
	 * L'extension hôte n'a pas toujours horodaté ses envois : elle-même retombe
	 * sur `post_modified_gmt` dans sa colonne « Instock Mail On » quand la
	 * métadonnée manque, ce qui atteste que de telles inscriptions existent. Or
	 * ce repli disparaît avec le statut « Alerte envoyée », et la remise en
	 * attente le fait disparaître.
	 *
	 * Sans cette précaution, une inscription notifiée avant que l'hôte n'écrive
	 * cette métadonnée sortirait définitivement du taux de conversion à sa
	 * première renotification — et son achat éventuel ne compterait plus.
	 *
	 * L'horodatage est lu AVANT le changement de statut : `wp_update_post()`
	 * réécrit `post_modified_gmt` à la date du jour.
	 *
	 * @param int $subscription_id Inscription.
	 */
	private function preserve_notified_trace( int $subscription_id ): void {
		$stamp = get_post_meta( $subscription_id, Host::META_MAIL_ON, true );

		if ( is_scalar( $stamp ) && (int) $stamp > 0 ) {
			return;
		}

		$post     = get_post( $subscription_id );
		$modified = $post instanceof \WP_Post
			? (int) strtotime( $post->post_modified_gmt . ' UTC' )
			: 0;

		update_post_meta(
			$subscription_id,
			Host::META_MAIL_ON,
			$modified > 0 ? $modified : time()
		);
	}

	/**
	 * Écrit le statut, par l'API de l'hôte quand elle est disponible.
	 *
	 * @param int $subscription_id Inscription.
	 *
	 * @return bool
	 */
	private function write_status( int $subscription_id ): bool {
		if ( class_exists( '\CWG_Instock_API' ) ) {
			$api    = new \CWG_Instock_API();
			$result = $api->subscriber_subscribed( $subscription_id );

			return ! is_wp_error( $result ) && (int) $result > 0;
		}

		$result = wp_update_post(
			array(
				'ID'          => $subscription_id,
				'post_type'   => Host::SUBSCRIBER_TYPE,
				'post_status' => Host::STATUS_SUBSCRIBED,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			Logger::error(
				sprintf(
					'Inscription #%1$d : remise en attente refusée — %2$s',
					$subscription_id,
					$result->get_error_message()
				)
			);

			return false;
		}

		return (int) $result > 0;
	}
}
