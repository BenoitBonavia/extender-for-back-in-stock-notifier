<?php
/**
 * Règles d'appariement entre une commande et une inscription.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

use EBISN\Support\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Décide si une commande vaut achat d'un produit attendu.
 *
 * Volontairement sans accès à la base : ces règles sont celles qu'on veut
 * pouvoir relire, discuter et corriger sans dérouler une requête.
 */
final class OrderMatcher {

	/**
	 * Statuts de commande valant achat.
	 *
	 * @return string[] Statuts sans le préfixe `wc-`.
	 */
	public static function order_statuses(): array {
		$configured = Settings::get( 'conversion_order_statuses', '' );
		$statuses   = is_string( $configured ) && '' !== $configured
			? array_map( 'trim', explode( ',', $configured ) )
			: array( 'processing', 'completed' );

		/**
		 * Statuts de commande déclenchant une conversion.
		 *
		 * @param string[] $statuses Statuts sans le préfixe `wc-`.
		 */
		$statuses = (array) apply_filters( 'ebisn_conversion_order_statuses', $statuses );

		return array_values(
			array_unique(
				array_filter(
					array_map( array( self::class, 'unprefix_status' ), $statuses )
				)
			)
		);
	}

	/**
	 * Retire le préfixe `wc-` d'un statut de commande.
	 *
	 * Surtout pas `ltrim( $status, 'wc-' )` : cette fonction retire des
	 * CARACTÈRES, pas un préfixe, et transformerait « completed » en
	 * « ompleted ».
	 *
	 * @param mixed $status Statut, avec ou sans préfixe.
	 *
	 * @return string
	 */
	private static function unprefix_status( $status ): string {
		$status = sanitize_key( (string) $status );

		return 0 === strncmp( $status, 'wc-', 3 ) ? substr( $status, 3 ) : $status;
	}

	/**
	 * Fenêtre d'attribution, en jours.
	 *
	 * Au-delà, une commande n'est plus considérée comme causée par l'attente :
	 * attribuer un achat à une inscription vieille de trois ans ne décrit plus
	 * rien. `0` désactive la borne — c'est le défaut, pour rester strictement
	 * aligné sur le comportement des snippets remplacés.
	 *
	 * @return int
	 */
	public static function attribution_window_days(): int {
		$days = (int) Settings::get( 'conversion_window_days', 0 );

		/**
		 * Nombre de jours au-delà duquel une commande n'est plus attribuée.
		 *
		 * @param int $days Fenêtre en jours, `0` pour aucune limite.
		 */
		return max( 0, (int) apply_filters( 'ebisn_conversion_window_days', $days ) );
	}

	/**
	 * Normalise une adresse pour la comparaison.
	 *
	 * La mise en minuscules n'est pas cosmétique : la collation MySQL par défaut
	 * masque la casse dans une requête, mais dès qu'on compare deux adresses en
	 * PHP — ce que fait le rattrapage — `Jean@x.fr` et `jean@x.fr` redeviennent
	 * deux valeurs distinctes.
	 *
	 * @param string $email Adresse brute.
	 *
	 * @return string Chaîne vide si l'adresse est inexploitable.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( sanitize_email( $email ) ) );

		return is_email( $email ) ? $email : '';
	}

	/**
	 * Identifiants de produits et de variations présents dans une commande.
	 *
	 * Les deux sont collectés : une inscription vise la variation quand il y en
	 * a une (`cwginstock_pid`), mais le produit parent quand l'inscription porte
	 * sur « n'importe quelle variation ».
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return int[]
	 */
	public static function product_ids( \WC_Order $order ): array {
		$ids = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$ids[] = (int) $item->get_variation_id();
			$ids[] = (int) $item->get_product_id();
		}

		/**
		 * Produits d'une commande susceptibles de satisfaire une attente.
		 *
		 * Point d'extension pour les types de produits que WooCommerce ne
		 * représente pas ligne à ligne : un produit groupé, par exemple, n'appa-
		 * raît jamais dans la commande, seuls ses enfants y figurent.
		 *
		 * @param int[]     $ids   Identifiants collectés.
		 * @param \WC_Order $order Commande.
		 */
		$ids = (array) apply_filters( 'ebisn_order_product_ids', $ids, $order );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * Adresse et compte client associés à une commande.
	 *
	 * @param \WC_Order $order Commande.
	 *
	 * @return array{email:string, user_id:int} `user_id` vaut `0` pour une commande invitée ;
	 *                                          l'appelant NE DOIT PAS chercher les inscriptions
	 *                                          à `user_id = 0`, elles correspondraient à tous
	 *                                          les inscrits non connectés du site.
	 */
	public static function identity( \WC_Order $order ): array {
		return array(
			'email'   => self::normalize_email( (string) $order->get_billing_email() ),
			'user_id' => max( 0, (int) $order->get_customer_id() ),
		);
	}

	/**
	 * L'achat peut-il être attribué à cette attente ?
	 *
	 * Deux conditions, appliquées aussi bien au fil de l'eau qu'au rattrapage —
	 * les snippets ne les vérifiaient qu'au rattrapage, si bien qu'une commande
	 * pouvait convertir une inscription créée APRÈS elle.
	 *
	 * @param int $subscribed_at Inscription, horodatage UNIX UTC.
	 * @param int $ordered_at    Création de la commande, horodatage UNIX UTC.
	 *
	 * @return bool
	 */
	public static function is_attributable( int $subscribed_at, int $ordered_at ): bool {
		if ( $subscribed_at <= 0 || $ordered_at <= 0 ) {
			return false;
		}

		if ( $subscribed_at > $ordered_at ) {
			return false;
		}

		$window = self::attribution_window_days();

		if ( 0 === $window ) {
			return true;
		}

		return ( $ordered_at - $subscribed_at ) <= ( $window * DAY_IN_SECONDS );
	}
}
