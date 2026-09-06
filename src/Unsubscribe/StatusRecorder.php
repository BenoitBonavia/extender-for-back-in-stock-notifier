<?php
/**
 * Mémorisation du statut précédant un désabonnement.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Unsubscribe;

use EBISN\Integration\BackInStockNotifier as Host;

defined( 'ABSPATH' ) || exit;

/**
 * Retient d'où vient une inscription passée en « Unsubscribed ».
 *
 * Ce travail est fait sur `transition_post_status`, et non dans
 * `UnsubscribeService`, parce que ce plugin n'est pas le seul à poser ce
 * statut. L'extension hôte le fait aussi :
 *
 * - depuis son action groupée « Change status to Unsubscribed » ;
 * - depuis sa récupération de file, quand `queue_recovery_final_status` est
 *   réglé sur « Unsubscribed » — auquel cas personne n'a rien demandé.
 *
 * En écoutant la transition plutôt que nos propres appels, le statut d'origine
 * est connu dans tous les cas, et le bouton « Annuler » sait rétablir même un
 * désabonnement que nous n'avons pas provoqué.
 */
final class StatusRecorder {

	/**
	 * Statut occupé avant le désabonnement.
	 */
	public const META_PREVIOUS = '_ebisn_prev_status';

	/**
	 * Origine du désabonnement : `button`, `email` ou `admin`.
	 */
	public const META_SOURCE = '_ebisn_unsub_source';

	/**
	 * Accroche l'écoute des transitions.
	 */
	public function register(): void {
		add_action( 'transition_post_status', array( $this, 'remember' ), 10, 3 );
	}

	/**
	 * Note le statut quitté au moment d'un passage en « Unsubscribed ».
	 *
	 * @param string   $new_status Nouveau statut.
	 * @param string   $old_status Ancien statut.
	 * @param \WP_Post $post       Contenu concerné.
	 */
	public function remember( $new_status, $old_status, $post ): void {
		if ( ! $post instanceof \WP_Post || Host::SUBSCRIBER_TYPE !== $post->post_type ) {
			return;
		}

		if ( Host::STATUS_UNSUBSCRIBED !== $new_status || $new_status === $old_status ) {
			return;
		}

		// Un statut d'origine incohérent — « auto-draft », « new » — n'aurait
		// aucun sens à rétablir.
		if ( ! in_array( (string) $old_status, SubscriberLocator::active_statuses(), true ) ) {
			return;
		}

		update_post_meta( $post->ID, self::META_PREVIOUS, (string) $old_status );

		/*
		 * Origine par défaut : le désabonnement ne vient pas de nous, donc d'une
		 * action du marchand ou d'un automatisme de l'hôte. Nos propres chemins
		 * écrasent ensuite cette valeur.
		 */
		if ( '' === (string) get_post_meta( $post->ID, self::META_SOURCE, true ) ) {
			update_post_meta( $post->ID, self::META_SOURCE, 'admin' );
		}
	}
}
