<?php
/**
 * PHPStan-Bootstrap: Konstanten, die die Plugin-Hauptdatei zur Laufzeit
 * definiert. Wird nur von PHPStan geladen, nie von WordPress.
 *
 * @package Block_Inserter_Filter
 */

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

if ( ! defined( 'JGOR_BIF_VERSION' ) ) {
	define( 'JGOR_BIF_VERSION', '2.1.2' );
}
if ( ! defined( 'JGOR_BIF_PLUGIN_FILE' ) ) {
	define( 'JGOR_BIF_PLUGIN_FILE', __DIR__ . '/block-inserter-filter.php' );
}
if ( ! defined( 'JGOR_BIF_PLUGIN_DIR' ) ) {
	define( 'JGOR_BIF_PLUGIN_DIR', __DIR__ . '/' );
}
if ( ! defined( 'JGOR_BIF_PLUGIN_URL' ) ) {
	define( 'JGOR_BIF_PLUGIN_URL', 'https://example.com/wp-content/plugins/block-inserter-filter/' );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
