<?php
/**
 * PHPStan-Bootstrap: Konstanten, die die Plugin-Hauptdatei zur Laufzeit
 * definiert. Wird nur von PHPStan geladen, nie von WordPress.
 *
 * @package GB_Block_Filter
 */

if ( ! defined( 'JG_GBBF_VERSION' ) ) {
	define( 'JG_GBBF_VERSION', '1.6.0' );
}
if ( ! defined( 'JG_GBBF_PLUGIN_FILE' ) ) {
	define( 'JG_GBBF_PLUGIN_FILE', __DIR__ . '/gb-block-filter.php' );
}
if ( ! defined( 'JG_GBBF_PLUGIN_DIR' ) ) {
	define( 'JG_GBBF_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'JG_GBBF_PLUGIN_URL' ) ) {
	define( 'JG_GBBF_PLUGIN_URL', 'https://example.local/wp-content/plugins/gb-block-filter/' );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
