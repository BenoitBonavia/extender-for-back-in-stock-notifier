<?php
/**
 * Nettoyage à la suppression du plugin.
 *
 * @package ExtenderForBackInStockNotifier
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Sécurité : on ne supprime rien si la constante ci-dessous est définie dans
 * wp-config.php. Pratique pour conserver les réglages entre deux réinstalls.
 */
if ( defined( 'EBISN_KEEP_DATA_ON_UNINSTALL' ) && EBISN_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Supprime les données de CE plugin sur le site courant.
 *
 * Les données de « Back In Stock Notifier for WooCommerce » ne sont pas
 * touchées : ni les abonnés (type de contenu cwginstocknotifier), ni les
 * arrivages (cwginstock_arrival), ni ses options.
 *
 * Une nuance importante s'y ajoute depuis l'introduction du module de
 * conversion : ce plugin ÉCRIT dans les données de l'hôte — il fait passer des
 * inscriptions au statut « Purchased » et y attache ses propres métadonnées.
 * Ces métadonnées nous appartiennent et sont supprimées. Les statuts, eux, ne
 * sont pas rétablis : ce sont des données métier du marchand, et une conversion
 * reste vraie même sans ce plugin. `ebisn_restore_statuses_on_uninstall`
 * permet de demander explicitement le contraire.
 */
function ebisn_uninstall_delete_options(): void {
	global $wpdb;

	/*
	 * Les tâches de fond sont TOUJOURS déprogrammées, quel que soit le réglage :
	 * laisser des actions planifiées sur des hooks dont plus aucun callback
	 * n'existe les ferait échouer en boucle dans Action Scheduler.
	 */
	ebisn_uninstall_clear_schedules();

	// Sans opt-in explicite, on ne supprime aucune donnée.
	if ( 'yes' !== get_option( 'ebisn_delete_data_on_uninstall', 'no' ) ) {
		return;
	}

	if ( 'yes' === get_option( 'ebisn_restore_statuses_on_uninstall', 'no' ) ) {
		ebisn_uninstall_restore_statuses();
	}

	ebisn_uninstall_delete_meta();
	ebisn_uninstall_drop_tables();

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$option_names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ebisn\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	/*
	 * Résidus de la bibliothèque de mise à jour. Elle nettoie normalement elle-même
	 * sur l'action `uninstall_{plugin}`, mais la présence de ce fichier uninstall.php
	 * court-circuite cette action : le ménage doit donc être fait ici.
	 */
	$puc_slug = 'extender-for-back-in-stock-notifier';

	delete_option( 'external_updates-' . $puc_slug );
	delete_site_option( 'external_updates-' . $puc_slug );
	wp_clear_scheduled_hook( 'puc_cron_check_updates-' . $puc_slug );
}

/**
 * Rétablit le statut d'origine des inscriptions que ce plugin a converties.
 *
 * En une seule requête jointe : la désinstallation s'exécute dans une requête
 * unique, sans ordonnanceur pour prendre le relais. Les conversions héritées
 * des snippets ne portent pas de statut d'origine et restent donc en l'état —
 * il n'y a rien à quoi les rétablir.
 */
function ebisn_uninstall_restore_statuses(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->posts} p
			   INNER JOIN {$wpdb->postmeta} pm
					   ON pm.post_id = p.ID
					  AND pm.meta_key = %s
				  SET p.post_status = pm.meta_value
				WHERE p.post_type = %s
				  AND p.post_status = %s
				  AND pm.meta_value <> ''",
			'_ebisn_previous_status',
			'cwginstocknotifier',
			'cwg_converted'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Supprime les métadonnées posées par ce plugin sur les inscriptions.
 */
function ebisn_uninstall_delete_meta(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$wpdb->query(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_ebisn\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
}

/**
 * Supprime les tables propres au plugin.
 */
function ebisn_uninstall_drop_tables(): void {
	global $wpdb;

	$table = $wpdb->prefix . 'ebisn_brevo_contacts';

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- suppression de notre propre table.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

/**
 * Déprogramme les traitements de fond.
 *
 * Action Scheduler d'abord — c'est lui qui porte les actions réelles — puis
 * WP-Cron, au cas où le repli aurait été emprunté.
 */
function ebisn_uninstall_clear_schedules(): void {
	$hooks = array(
		'ebisn_daily_maintenance',
		'ebisn_convert_order',
		'ebisn_purchase_backfill_step',
		'ebisn_legacy_conversion_meta_step',
		'ebisn_brevo_sync_contact',
		'ebisn_brevo_backfill_step',
		'ebisn_brevo_backfill_poll',
	);

	foreach ( $hooks as $hook ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( $hook, null, 'ebisn' );
		}

		wp_clear_scheduled_hook( $hook );
	}
}

if ( is_multisite() ) {
	// 'number' => 0 : sans quoi get_sites() s'arrête aux 100 premiers sites et
	// le reste du réseau garderait ses données en base, silencieusement.
	$ebisn_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $ebisn_site_ids as $ebisn_site_id ) {
		switch_to_blog( (int) $ebisn_site_id );
		ebisn_uninstall_delete_options();
		restore_current_blog();
	}
} else {
	ebisn_uninstall_delete_options();
}
