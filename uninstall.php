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
 * Supprime toutes les options préfixées ebisn_ du site courant.
 *
 * RIEN de ce qui appartient à « Back In Stock Notifier for WooCommerce » n'est
 * touché — ni les abonnés (type de contenu cwginstocknotifier), ni les arrivages
 * (cwginstock_arrival), ni ses options (cwginstocksettings et consorts). Ce
 * plugin n'est qu'une extension de l'hôte : celui-ci reste installé et ses
 * données ne lui appartiennent pas. Le seul cas où ce fichier écrit dans les
 * données de l'hôte serait une migration que NOUS aurions provoquée — il n'y en
 * a aucune aujourd'hui.
 */
function ebisn_uninstall_delete_options(): void {
	global $wpdb;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- désinstallation ponctuelle, pas de cache pertinent.
	$option_names = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'ebisn\\_%'"
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	foreach ( (array) $option_names as $option_name ) {
		delete_option( $option_name );
	}

	wp_clear_scheduled_hook( 'ebisn_daily_maintenance' );

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
