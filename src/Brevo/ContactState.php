<?php
/**
 * Journal de synchronisation des contacts Brevo.
 *
 * @package ExtenderForBackInStockNotifier
 */

namespace EBISN\Brevo;

defined( 'ABSPATH' ) || exit;

/**
 * Table de suivi des adresses poussées vers Brevo.
 *
 * Deux raisons de ne pas stocker cet état en métadonnée d'inscription, comme le
 * faisaient les snippets :
 *
 * 1. **L'unité de synchronisation est l'adresse, pas l'inscription.** Brevo
 *    identifie un contact par son e-mail : une personne inscrite sur trois
 *    produits n'y existe qu'une fois. Suivre l'état par inscription menait à
 *    des mises à jour concurrentes du même contact, dont la dernière arrivée
 *    écrasait les autres.
 * 2. **L'extension hôte peut supprimer ses inscriptions.** Sa purge
 *    automatique efface définitivement les statuts « Mail Sent », « Purchased »
 *    et « Unsubscribed » — et donc toute métadonnée portée par eux. Un
 *    marqueur de synchronisation qui disparaît, c'est un contact repoussé
 *    indéfiniment.
 *
 * L'empreinte de charge utile complète le dispositif : tant qu'elle n'a pas
 * changé, il n'y a rien à envoyer. Un rattrapage relancé par mégarde ne
 * produit alors aucun appel réseau.
 */
final class ContactState {

	/**
	 * Version du schéma, pour les évolutions ultérieures.
	 */
	public const SCHEMA_VERSION = 1;

	/** Jamais synchronisé. */
	public const STATE_PENDING = 'pending';

	/** Synchronisé et à jour. */
	public const STATE_SYNCED = 'synced';

	/** Modifié depuis la dernière synchronisation. */
	public const STATE_DIRTY = 'dirty';

	/** En échec définitif. */
	public const STATE_FAILED = 'failed';

	/**
	 * Nom complet de la table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'ebisn_brevo_contacts';
	}

	/**
	 * Crée ou met à jour le schéma.
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		/*
		 * `email_hash` en clé primaire plutôt que l'adresse elle-même : une
		 * empreinte a une longueur fixe, donc indexable sans troncature, là où
		 * une adresse peut dépasser la limite d'index de MySQL.
		 */
		$sql = "CREATE TABLE {$table} (
			email_hash CHAR(64) NOT NULL,
			email VARCHAR(191) NOT NULL DEFAULT '',
			list_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			payload_hash CHAR(64) NOT NULL DEFAULT '',
			state VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			last_error TEXT NULL,
			synced_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (email_hash),
			KEY state (state),
			KEY updated_at (updated_at)
		) {$collate};";

		dbDelta( $sql );
	}

	/**
	 * Supprime la table.
	 */
	public static function uninstall(): void {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange -- suppression de notre propre table à la désinstallation.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Empreinte d'une adresse.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return string
	 */
	public static function hash( string $email ): string {
		return hash( 'sha256', strtolower( trim( $email ) ) );
	}

	/**
	 * Empreinte d'une charge utile.
	 *
	 * Les clés sont triées avant encodage : sans cela, deux charges utiles
	 * identiques mais construites dans un ordre différent produiraient des
	 * empreintes différentes, et l'on repousserait des données inchangées.
	 *
	 * @param array<string, mixed> $payload Charge utile.
	 *
	 * @return string
	 */
	public static function payload_hash( array $payload ): string {
		ksort( $payload );

		return hash( 'sha256', (string) wp_json_encode( $payload ) );
	}

	/**
	 * Lit l'état d'une adresse.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $email ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin, lecture ponctuelle par clé primaire.
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", self::hash( $email ) ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $row ) ? $row : null;
	}

	/**
	 * La charge utile diffère-t-elle de celle déjà synchronisée ?
	 *
	 * @param string $email        Adresse normalisée.
	 * @param string $payload_hash Empreinte de la charge utile à envoyer.
	 * @param int    $list_id      Liste de destination.
	 *
	 * @return bool
	 */
	public function needs_sync( string $email, string $payload_hash, int $list_id ): bool {
		$row = $this->get( $email );

		if ( null === $row ) {
			return true;
		}

		if ( self::STATE_SYNCED !== $row['state'] ) {
			return true;
		}

		if ( (int) $row['list_id'] !== $list_id ) {
			return true;
		}

		return (string) $row['payload_hash'] !== $payload_hash;
	}

	/**
	 * Enregistre une synchronisation réussie.
	 *
	 * @param string $email        Adresse normalisée.
	 * @param string $payload_hash Empreinte de la charge utile envoyée.
	 * @param int    $list_id      Liste de destination.
	 * @param string $synced_at    Date de synchronisation (`Y-m-d H:i:s` UTC) ; maintenant si omise.
	 */
	public function mark_synced( string $email, string $payload_hash, int $list_id, string $synced_at = '' ): void {
		$this->write(
			$email,
			array(
				'payload_hash' => $payload_hash,
				'list_id'      => $list_id,
				'state'        => self::STATE_SYNCED,
				'attempts'     => 0,
				'last_error'   => '',
				'synced_at'    => '' !== $synced_at ? $synced_at : gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}

	/**
	 * Marque une adresse comme à resynchroniser.
	 *
	 * @param string $email Adresse normalisée.
	 */
	public function mark_dirty( string $email ): void {
		$this->write( $email, array( 'state' => self::STATE_DIRTY ) );
	}

	/**
	 * Enregistre un échec.
	 *
	 * @param string $email     Adresse normalisée.
	 * @param string $error     Message d'erreur.
	 * @param bool   $permanent Vrai si l'échec est définitif.
	 */
	public function mark_failed( string $email, string $error, bool $permanent ): void {
		$row      = $this->get( $email );
		$attempts = null === $row ? 0 : (int) $row['attempts'];

		$this->write(
			$email,
			array(
				'state'      => $permanent ? self::STATE_FAILED : self::STATE_DIRTY,
				'attempts'   => $attempts + 1,
				'last_error' => $error,
			)
		);
	}

	/**
	 * Nombre de tentatives déjà effectuées pour une adresse.
	 *
	 * @param string $email Adresse normalisée.
	 *
	 * @return int
	 */
	public function attempts( string $email ): int {
		$row = $this->get( $email );

		return null === $row ? 0 : (int) $row['attempts'];
	}

	/**
	 * Nombre d'adresses dans un état donné.
	 *
	 * @param string $state État recherché.
	 *
	 * @return int
	 */
	public function count_by_state( string $state ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin, comptage pour le panneau d'état.
		$count = $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", $state )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) $count;
	}

	/**
	 * Adresses déjà connues du journal.
	 *
	 * @return array<string, true> Empreintes en clés.
	 */
	public function known_hashes(): array {
		global $wpdb;

		$table = self::table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table propre au plugin, lecture d'ensemble pour le rattrapage.
		$hashes = $wpdb->get_col( "SELECT email_hash FROM {$table} WHERE state = 'synced'" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_fill_keys( (array) $hashes, true );
	}

	/**
	 * Écrit une ligne, en la créant si nécessaire.
	 *
	 * @param string               $email   Adresse normalisée.
	 * @param array<string, mixed> $changes Colonnes à écrire.
	 */
	private function write( string $email, array $changes ): void {
		global $wpdb;

		$table = self::table();
		$hash  = self::hash( $email );

		$data = array_merge(
			array(
				'email_hash' => $hash,
				'email'      => substr( $email, 0, 191 ),
				'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			),
			$changes
		);

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table propre au plugin.
		if ( null === $this->get( $email ) ) {
			$wpdb->insert( $table, $data );

			return;
		}

		unset( $data['email_hash'] );
		$wpdb->update( $table, $data, array( 'email_hash' => $hash ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
