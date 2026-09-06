<?php
/**
 * Métadonnées posées par le module de conversion.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Conversion;

defined( 'ABSPATH' ) || exit;

/**
 * Toutes les clés de métadonnées écrites — ou relues — par ce plugin.
 *
 * Un seul fichier bouge le jour où une clé change. Les clés « héritées » sont
 * celles des snippets WPCode que ce plugin remplace : elles ne doivent JAMAIS
 * être modifiées, des données de production les portent déjà.
 */
final class MetaKeys {

	/**
	 * Préfixe des métadonnées du plugin.
	 *
	 * Le tiret bas initial rend la métadonnée « protégée » au sens de WordPress :
	 * invisible dans la boîte « Champs personnalisés » et non modifiable par
	 * l'API REST sans `register_meta()`. Les clés `mh_bisn_*` des snippets, elles,
	 * étaient exposées.
	 */
	public const PREFIX = '_ebisn_';

	/**
	 * Commande ayant provoqué la conversion.
	 */
	public const ORDER_ID = '_ebisn_order_id';

	/**
	 * Horodatage UNIX UTC de la conversion.
	 */
	public const CONVERTED_AT = '_ebisn_converted_at';

	/**
	 * Statut de l'inscription avant conversion.
	 *
	 * C'est la clé de voûte du module. Sans elle : impossible d'annuler une
	 * conversion après un remboursement, et impossible de recalculer un taux de
	 * conversion — puisque `cwg_converted` écrase l'information disant si la
	 * personne avait seulement été notifiée.
	 */
	public const PREVIOUS_STATUS = '_ebisn_previous_status';

	/**
	 * Origine de la conversion : `realtime`, `backfill` ou `snippet`.
	 */
	public const SOURCE = '_ebisn_source';

	/**
	 * Délai entre l'envoi de l'alerte et la commande, en secondes.
	 *
	 * Absente quand l'inscription n'avait jamais été notifiée.
	 */
	public const LAG = '_ebisn_lag';

	/** Conversion détectée au fil de l'eau, à la commande. */
	public const SOURCE_REALTIME = 'realtime';

	/** Conversion détectée par le rattrapage sur l'historique. */
	public const SOURCE_BACKFILL = 'backfill';

	/** Conversion héritée des snippets WPCode, statut d'origine inconnu. */
	public const SOURCE_SNIPPET = 'snippet';

	/*
	 * ---------------------------------------------------------------------
	 * Clés héritées des snippets WPCode
	 *
	 * Intangibles : le site de production les porte déjà. Elles sont relues
	 * une fois par la migration, puis supprimées.
	 * ---------------------------------------------------------------------
	 */

	/** Ancien équivalent de ORDER_ID. */
	public const LEGACY_ORDER_ID = 'mh_bisn_order_id';

	/** Ancien équivalent de CONVERTED_AT. */
	public const LEGACY_CONVERTED_AT = 'mh_bisn_converted_on';

	/**
	 * Marqueur d'idempotence posé par le snippet sur la COMMANDE.
	 *
	 * Ce plugin ne l'écrit ni ne le lit : l'idempotence vient désormais d'une
	 * mise à jour conditionnelle du statut de l'inscription. La clé est
	 * seulement connue ici pour pouvoir être nettoyée sur demande. Attention :
	 * sous HPOS elle vit dans `wc_orders_meta`, pas dans `postmeta`.
	 */
	public const LEGACY_ORDER_FLAG = '_mh_bisn_converted';

	/**
	 * Métadonnées d'inscription écrites par ce module.
	 *
	 * @return string[]
	 */
	public static function subscription_keys(): array {
		return array(
			self::ORDER_ID,
			self::CONVERTED_AT,
			self::PREVIOUS_STATUS,
			self::SOURCE,
			self::LAG,
		);
	}

	/**
	 * Métadonnées d'inscription héritées des snippets.
	 *
	 * @return string[]
	 */
	public static function legacy_subscription_keys(): array {
		return array(
			self::LEGACY_ORDER_ID,
			self::LEGACY_CONVERTED_AT,
		);
	}
}
