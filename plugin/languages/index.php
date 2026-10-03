<?php
/**
 * Stub gegen Direktaufruf des `languages/`-Verzeichnisses.
 *
 * Verhindert, dass Server, die Directory-Listing aktiviert haben, den
 * Inhalt des Ordners offenlegen. Das Verzeichnis existiert, damit der
 * "Domain Path"-Header des Plugins auf einen real vorhandenen Ordner
 * zeigt. Im Release-Paket liegt hier nur die .pot-Vorlage; die
 * Uebersetzungen (.po/.mo/.l10n.php) bleiben im Entwicklungs-Repo
 * (.distignore) und dienen als Import-Vorlagen fuer translate.wordpress.org.
 * Die Sprachpakete von dort installiert WordPress automatisch.
 *
 * @package Block_Inserter_Filter
 */

defined( 'ABSPATH' ) || exit;
