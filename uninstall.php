<?php
/**
 * Uninstall-Skript.
 *
 * Wird ausgefuehrt, wenn das Plugin ueber das WordPress-Backend
 * geloescht wird (nicht beim einfachen Deaktivieren). Entfernt
 * saemtliche persistierten Daten - auch in Multisite-Installationen.
 *
 * Aufgeraeumt werden:
 *   - 'jg_gbbf_hidden_blocks'             (manuell ausgeblendete Bloecke)
 *   - 'jg_gbbf_hidden_embed_variations'   (ausgeblendete Embed-Varianten,
 *                                          seit v1.1.0)
 *   - 'jg_gbbf_migrated_v1_5'             (Sentinel der Legacy-Prefix-
 *                                          Migration)
 *   - Legacy-Schluessel aus < 1.5.0 (Pflicht zum Mitloeschen, falls
 *     ein User direkt nach dem Update deinstalliert, bevor die
 *     Migrations-Routine auf admin_init je gelaufen ist):
 *       'gbba_hidden_blocks'
 *       'gbba_hidden_embed_variations'
 *   - defensiv via LIKE: alle uebrigen jg_gbbf_*-Eintraege in
 *     wp_options (inkl. eventueller Transients).
 *
 * Multisite: per-Site-Aufraeumen plus netzwerkweite Sentinel-Option
 * `jg_gbbf_network_swept_v1_5` entfernen.
 *
 * @package GB_Block_Filter
 * @author  Jörn Gorres
 */

/*
 * Direkten Aufruf verhindern. WP setzt diese Konstante nur, wenn das
 * Plugin tatsaechlich deinstalliert wird.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Plugin-Daten einer einzelnen Site aufraeumen.
 *
 * Wird im Single-Site-Fall einmal und im Multisite-Fall einmal pro
 * Site innerhalb der switch_to_blog()-Schleife aufgerufen.
 *
 * @return void
 */
function jg_gbbf_uninstall_cleanup_current_site(): void {
	global $wpdb;

	// 1) Statisch benannte Optionen sauber via WP-API entfernen,
	//    damit ein evtl. persistenter Object-Cache mit-invalidiert
	//    wird (raw DELETE wuerde das nicht tun).
	//    Aktuelle (jg_gbbf_*) Keys.
	$jg_gbbf_options = array(
		'jg_gbbf_hidden_blocks',
		'jg_gbbf_hidden_embed_variations',
		'jg_gbbf_migrated_v1_5',
	);
	foreach ( $jg_gbbf_options as $jg_gbbf_option ) {
		delete_option( $jg_gbbf_option );
	}

	// Legacy-Keys (< 1.5.0) namentlich entfernen. Bewusst KEIN
	// LIKE 'gbba_%': das kurze Praefix ist nicht eindeutig genug und
	// koennte Optionen fremder Plugins treffen.
	$jg_gbbf_legacy_options = array(
		'gbba_hidden_blocks',
		'gbba_hidden_embed_variations',
	);
	foreach ( $jg_gbbf_legacy_options as $jg_gbbf_legacy_option ) {
		delete_option( $jg_gbbf_legacy_option );
	}

	// 2) Defensiv: saemtliche uebrigen jg_gbbf_*-Eintraege aus
	//    wp_options via LIKE entfernen. Deckt eventuelle kuenftige
	//    Optionen sowie Transients (`_transient_jg_gbbf_%` /
	//    `_transient_timeout_jg_gbbf_%`) ab. Das Praefix jg_gbbf_ ist
	//    plugin-eindeutig, ein Treffer in fremden Daten ist ausgeschlossen.
	//    $wpdb->esc_like() escaped die "_"-Wildcards im Plugin-Praefix;
	//    $wpdb->prepare() bindet die Patterns sauber als Strings.
	//
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
				OR option_name LIKE %s
				OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_jg_gbbf_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_jg_gbbf_' ) . '%',
			$wpdb->esc_like( 'jg_gbbf_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Persistenter Object Cache: geloeschte Optionen/Transients koennten
	// dort noch liegen. Nur die bekannten Keys gezielt verwerfen.
	foreach ( array_merge( $jg_gbbf_options, $jg_gbbf_legacy_options ) as $jg_gbbf_cache_key ) {
		wp_cache_delete( $jg_gbbf_cache_key, 'options' );
	}
}

if ( is_multisite() ) {
	// In Multisite die Aufraeumarbeiten pro Site wiederholen.
	// 'number' => 0 hebt das Standardlimit von 100 Sites auf.
	$jg_gbbf_sites = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);
	foreach ( $jg_gbbf_sites as $jg_gbbf_site_id ) {
		switch_to_blog( (int) $jg_gbbf_site_id );
		jg_gbbf_uninstall_cleanup_current_site();
		restore_current_blog();
	}

	// Netzwerkweiter Sweep-Sentinel der Legacy-Migration.
	delete_site_option( 'jg_gbbf_network_swept_v1_5' );
} else {
	jg_gbbf_uninstall_cleanup_current_site();
}
