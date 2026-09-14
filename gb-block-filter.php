<?php
/**
 * Plugin Name:       Block-Filter für GenerateBlocks
 * Description:       Blendet einzelne Gutenberg-Blöcke (Core, GenerateBlocks Free, GenerateBlocks Pro, Theme- und Plugin-Blöcke) global aus dem Block-Inserter aus. Konfigurierbar pro Block durch Administratoren.
 * Version:           1.5.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Tested up to:      7.0
 * Author:            Jörn Gorres
 * Author URI:        https://joern.gorres.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gb-block-filter
 * Domain Path:       /languages
 *
 * @package GBBA
 * @author  Jörn Gorres
 * @version 1.5.1
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
define( 'JG_GBBF_VERSION', '1.5.1' );
define( 'JG_GBBF_PLUGIN_FILE', __FILE__ );
define( 'JG_GBBF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JG_GBBF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Klassen einbinden.
 *
 * Reihenfolge: zuerst die Hauptklasse (haelt Konstanten und
 * Hilfsfunktionen), danach Filter und Admin-UI, die auf der
 * Hauptklasse aufbauen.
 */
require_once JG_GBBF_PLUGIN_DIR . 'includes/class-jg-gbbf-plugin.php';
require_once JG_GBBF_PLUGIN_DIR . 'includes/class-jg-gbbf-block-filter.php';
require_once JG_GBBF_PLUGIN_DIR . 'includes/class-jg-gbbf-admin-page.php';

/*
 * Plugin starten, sobald alle aktiven Plugins geladen sind. Das
 * stellt sicher, dass GenerateBlocks/GenerateBlocks Pro bereits
 * registriert sind, wenn wir auf den Block-Type-Registry zugreifen.
 */
add_action( 'plugins_loaded', array( 'JG_GBBF_Plugin', 'instance' ) );
