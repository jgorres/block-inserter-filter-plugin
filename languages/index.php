<?php
/**
 * Stub gegen Direktaufruf des `languages/`-Verzeichnisses.
 *
 * Verhindert, dass Server, die Directory-Listing aktiviert haben, den
 * Inhalt des Ordners offenlegen. Das Verzeichnis existiert, damit der
 * "Domain Path"-Header des Plugins auf einen real vorhandenen Ordner
 * zeigt; hier liegen die .pot-Vorlage und die Uebersetzungen (.po/.mo)
 * als Import-Vorlagen fuer translate.wordpress.org.
 *
 * @package GB_Block_Filter
 */

defined( 'ABSPATH' ) || exit;
