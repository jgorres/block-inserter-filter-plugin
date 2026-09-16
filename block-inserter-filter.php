<?php
/**
 * Plugin Name:       Block Inserter Filter
 * Description:       Hides individual Gutenberg blocks and embed variations from the block inserter, site-wide. Configurable per block by administrators, with optional GenerateBlocks integration.
 * Version:           2.1.3
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.1
 * Author:            Jörn Gorres
 * Author URI:        https://joern.gorres.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       block-inserter-filter
 * Domain Path:       /languages
 *
 * @package Block_Inserter_Filter
 * @author  Jörn Gorres
 * @version 2.1.3
 */

/*
 * Direkten Datei-Aufruf verhindern. Wird die Datei ausserhalb von
 * WordPress aufgerufen, ist die Konstante ABSPATH nicht definiert
 * und das Skript bricht ab. Pflicht-Schutz fuer alle Plugin-Dateien.
 */
defined( 'ABSPATH' ) || exit;

/*
 * Plugin-Konstanten – erlauben in den Klassen einen einheitlichen
 * Zugriff auf Version, Pfad und URL, ohne staendig __FILE__ und
 * plugin_dir_*() neu auswerten zu muessen.
 */
define( 'JGOR_BIF_VERSION', '2.1.3' );
define( 'JGOR_BIF_PLUGIN_FILE', __FILE__ );
define( 'JGOR_BIF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JGOR_BIF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Klassen einbinden.
 *
 * Reihenfolge: zuerst die Hauptklasse (haelt Konstanten und
 * Hilfsfunktionen), danach Filter und Admin-UI, die auf der
 * Hauptklasse aufbauen.
 */
require_once JGOR_BIF_PLUGIN_DIR . 'includes/class-jgor-bif-plugin.php';
require_once JGOR_BIF_PLUGIN_DIR . 'includes/class-jgor-bif-block-filter.php';
require_once JGOR_BIF_PLUGIN_DIR . 'includes/class-jgor-bif-admin-page.php';

/**
 * Plugin starten, sobald alle aktiven Plugins geladen sind. Das
 * stellt sicher, dass GenerateBlocks/GenerateBlocks Pro bereits
 * registriert sind, wenn wir auf den Block-Type-Registry zugreifen.
 *
 * Eigene Funktion ohne Rueckgabewert, weil ein Action-Callback nichts
 * zurueckgeben soll (JGOR_BIF_Plugin::instance() liefert die Instanz).
 *
 * @return void
 */
function jgor_bif_init(): void {
	JGOR_BIF_Plugin::instance();
}
add_action( 'plugins_loaded', 'jgor_bif_init' );
