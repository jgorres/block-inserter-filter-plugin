<?php
/**
 * Stub gegen Direktaufruf des `languages/`-Verzeichnisses.
 *
 * Verhindert, dass Server, die Directory-Listing aktiviert haben, den
 * Inhalt des Ordners offenlegen. Das Verzeichnis existiert, damit der
 * "Domain Path"-Header des Plugins auf einen real vorhandenen Ordner
 * zeigt; .mo-/.po-Dateien koennen hier kuenftig abgelegt werden.
 *
 * @package GBBA
 */

defined( 'ABSPATH' ) || exit;
