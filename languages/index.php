<?php
/**
 * Stub gegen Direktaufruf des `languages/`-Verzeichnisses.
 *
 * Verhindert, dass Server, die Directory-Listing aktiviert haben, den
 * Inhalt des Ordners offenlegen. Das Verzeichnis existiert, damit der
 * "Domain Path"-Header des Plugins auf einen real vorhandenen Ordner
 * zeigt; hier liegen die .pot-Vorlage und die Uebersetzungen (.po/.mo/
 * .l10n.php). Core laedt sie seit WordPress 6.7 automatisch ueber den
 * Domain Path; sie dienen zugleich als Import-Vorlagen fuer
 * translate.wordpress.org.
 *
 * @package Block_Inserter_Filter
 */

defined( 'ABSPATH' ) || exit;
