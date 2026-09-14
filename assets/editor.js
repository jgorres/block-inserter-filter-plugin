/**
 * Block-Editor-Skript: Entfernt nicht erlaubte Embed-Varianten.
 *
 * Wird im Block-Editor geladen und ruft fuer jede in der Plugin-
 * Konfiguration markierte Embed-Variante (z. B. 'youtube', 'reddit')
 * `wp.blocks.unregisterBlockVariation('core/embed', name)` auf.
 *
 * Die Liste kommt vom Server via `wp_localize_script` als
 * `window.gbbaEditorData.hiddenEmbedVariations`.
 *
 * @package GBBA
 * @author  Jörn Gorres
 * @version 1.1.0
 */
( function () {
    'use strict';

    var data   = window.gbbaEditorData || {};
    var hidden = data.hiddenEmbedVariations;

    if ( ! hidden || ! hidden.length ) {
        return;
    }

    if ( ! window.wp || ! wp.domReady || ! wp.blocks || 'function' !== typeof wp.blocks.unregisterBlockVariation ) {
        return;
    }

    wp.domReady( function () {
        hidden.forEach( function ( name ) {
            try {
                wp.blocks.unregisterBlockVariation( 'core/embed', name );
            } catch ( e ) {
                // Variante existiert in dieser WP-Version nicht – ignorieren.
            }
        } );
    } );

} )();
