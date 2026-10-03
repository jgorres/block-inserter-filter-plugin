<?php
/**
 * Uninstall-Skript.
 *
 * Wird ausgefuehrt, wenn das Plugin ueber das WordPress-Backend
 * geloescht wird (nicht beim einfachen Deaktivieren). Entfernt
 * saemtliche persistierten Daten - auch in Multisite-Installationen.
 *
 * Aufgeraeumt werden:
 *   - 'jgor_bif_hidden_blocks'             (manuell ausgeblendete Bloecke)
 *   - 'jgor_bif_hidden_embed_variations'   (ausgeblendete Embed-Varianten,
 *                                          seit v1.1.0)
 *   - 'jgor_bif_migrated_v2'               (Sentinel der Legacy-Prefix-
 *                                          Migration)
 *   - Legacy-Schluessel aus 1.5.x (jg_gbbf_*) und < 1.5.0 (gbba_*),
 *     Pflicht zum Mitloeschen, falls ein User direkt nach dem Update
 *     deinstalliert, bevor die Migrations-Routine auf admin_init je
 *     gelaufen ist.
 *   - defensiv via LIKE: alle uebrigen jgor_bif_*- und jg_gbbf_*-
 *     Eintraege in wp_options (inkl. eventueller Transients).
 *
 * Multisite: per-Site-Aufraeumen plus netzwerkweite Sentinel-Optionen
 * `jgor_bif_network_swept_v2` und `jg_gbbf_network_swept_v1_5` entfernen.
 *
 * @package Block_Inserter_Filter
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
function jgor_bif_uninstall_cleanup_current_site(): void {
	global $wpdb;

	// 1) Statisch benannte Optionen sauber via WP-API entfernen,
	// damit ein evtl. persistenter Object-Cache mit-invalidiert
	// wird (raw DELETE wuerde das nicht tun).
	// Aktuelle (jgor_bif_*) Keys.
	$jgor_bif_options = array(
		'jgor_bif_hidden_blocks',
		'jgor_bif_hidden_embed_variations',
		'jgor_bif_migrated_v2',
	);
	foreach ( $jgor_bif_options as $jgor_bif_option ) {
		delete_option( $jgor_bif_option );
	}

	// Legacy-Keys (1.5.x: jg_gbbf_*, < 1.5.0: gbba_*) namentlich
	// entfernen. Bewusst KEIN LIKE 'gbba_%': das kurze Praefix ist nicht
	// eindeutig genug und koennte Optionen fremder Plugins treffen.
	$jgor_bif_legacy_options = array(
		'jg_gbbf_hidden_blocks',
		'jg_gbbf_hidden_embed_variations',
		'jg_gbbf_migrated_v1_5',
		'gbba_hidden_blocks',
		'gbba_hidden_embed_variations',
	);
	foreach ( $jgor_bif_legacy_options as $jgor_bif_legacy_option ) {
		delete_option( $jgor_bif_legacy_option );
	}

	// 2) Defensiv: saemtliche uebrigen jgor_bif_*- und jg_gbbf_*-
	// Eintraege aus wp_options via LIKE entfernen. Deckt eventuelle
	// kuenftige Optionen sowie Transients (`_transient_<prefix>%` /
	// `_transient_timeout_<prefix>%`) ab. Beide Praefixe sind
	// plugin-eindeutig, ein Treffer in fremden Daten ist ausgeschlossen.
	// $wpdb->esc_like() escaped die "_"-Wildcards im Plugin-Praefix;
	// $wpdb->prepare() bindet die Patterns sauber als Strings.
	//
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
				OR option_name LIKE %s
				OR option_name LIKE %s
				OR option_name LIKE %s
				OR option_name LIKE %s
				OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_jgor_bif_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_jgor_bif_' ) . '%',
			$wpdb->esc_like( 'jgor_bif_' ) . '%',
			$wpdb->esc_like( '_transient_jg_gbbf_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_jg_gbbf_' ) . '%',
			$wpdb->esc_like( 'jg_gbbf_' ) . '%'
		)
	);
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// Persistenter Object Cache: geloeschte Optionen/Transients koennten
	// dort noch liegen. Nur die bekannten Keys gezielt verwerfen.
	foreach ( array_merge( $jgor_bif_options, $jgor_bif_legacy_options ) as $jgor_bif_cache_key ) {
		wp_cache_delete( $jgor_bif_cache_key, 'options' );
	}
}

if ( is_multisite() ) {
	// In Multisite die Aufraeumarbeiten pro Site wiederholen.
	// 'number' => 0 hebt das Standardlimit von 100 Sites auf.
	$jgor_bif_sites = get_sites(
		array(
			'fields'     => 'ids',
			'number'     => 0,
			'network_id' => get_current_network_id(),
		)
	);
	foreach ( $jgor_bif_sites as $jgor_bif_site_id ) {
		switch_to_blog( (int) $jgor_bif_site_id );
		jgor_bif_uninstall_cleanup_current_site();
		restore_current_blog();
	}

	// Netzwerkweite Sweep-Sentinels der Legacy-Migrationen.
	delete_site_option( 'jgor_bif_network_swept_v2' );
	delete_site_option( 'jg_gbbf_network_swept_v1_5' );
} else {
	jgor_bif_uninstall_cleanup_current_site();
}
