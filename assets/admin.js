/**
 * Admin-JS fuer "Block Filter for GenerateBlocks".
 *
 * Aufgaben:
 *   - Live-Suche ueber alle Block-Eintraege.
 *   - Umschaltung zwischen Kategorie- und Namespace-Ansicht.
 *   - "Alle umschalten"-Button pro Gruppe.
 *
 * Vanilla JS, keine Abhaengigkeiten. IIFE-gekapselt, strict mode.
 *
 * @package GB_Block_Filter
 * @author  Jörn Gorres
 */
( function () {
    'use strict';

    /**
     * Stellt sicher, dass die WordPress-Core-Bloecke clientseitig
     * registriert sind. Auf einer Settings-Seite wird sonst nur das
     * Block-Library-Skript geladen, OHNE dass `registerCoreBlocks`
     * aufgerufen wird – das passiert normalerweise erst in
     * `wp-edit-post` / `wp-edit-site`.
     */
    function ensureCoreBlocksRegistered() {
        if ( ! window.wp || ! wp.blocks || ! wp.blockLibrary ) {
            return;
        }
        if ( typeof wp.blockLibrary.registerCoreBlocks !== 'function' ) {
            return;
        }

        try {
            // Falls 'core/paragraph' bereits registriert ist, nichts tun.
            if ( wp.blocks.getBlockType && wp.blocks.getBlockType( 'core/paragraph' ) ) {
                return;
            }
            wp.blockLibrary.registerCoreBlocks();
        } catch ( e ) {
            // Fehler bewusst ignorieren – Icons sind Dekoration, das
            // Plugin funktioniert auch ohne sie.
        }
    }

    /**
     * Rendert die Block-Icons in die vorbereiteten Span-Container.
     *
     * Pro `.jg-gbbf-item__icon[data-block-name]` wird der Block-Typ aus
     * `wp.blocks.getBlockType` geholt und sein Icon ueber die
     * `BlockIcon`-Komponente von `wp.blockEditor` gerendert. Fuer
     * Embed-Varianten werden analog `wp.blocks.getBlockVariations`
     * abgefragt.
     */
    function renderBlockIcons() {
        if ( ! window.wp || ! wp.blocks || ! wp.blockEditor || ! wp.element ) {
            return;
        }
        var BlockIcon = wp.blockEditor.BlockIcon;
        if ( ! BlockIcon ) {
            return;
        }

        // Sicherstellen, dass Core-Bloecke registriert sind.
        ensureCoreBlocksRegistered();

        var createElement = wp.element.createElement;

        // Render-Wrapper – React 18 (createRoot) bevorzugt, sonst Legacy `render`.
        function renderInto( reactElement, container ) {
            try {
                if ( typeof wp.element.createRoot === 'function' ) {
                    wp.element.createRoot( container ).render( reactElement );
                } else if ( typeof wp.element.render === 'function' ) {
                    wp.element.render( reactElement, container );
                }
            } catch ( e ) {
                // Im Zweifel still scheitern – Icon ist Dekoration, kein Pflichtfeature.
            }
        }

        // ----- Block-Icons -----
        var missing = 0;
        var blockContainers = document.querySelectorAll( '.jg-gbbf-item__icon[data-block-name]' );
        Array.prototype.forEach.call( blockContainers, function ( container ) {
            var name = container.getAttribute( 'data-block-name' );
            if ( ! name ) {
                return;
            }
            var blockType = wp.blocks.getBlockType( name );
            if ( ! blockType || ! blockType.icon ) {
                missing++;
                return;
            }
            renderInto( createElement( BlockIcon, { icon: blockType.icon, showColors: false } ), container );
        } );

        // Fehlende Icons (Block clientseitig nicht registriert) werden
        // still uebergangen; der Zaehler bleibt fuer die Lesbarkeit.
        void missing;

        // ----- Embed-Varianten-Icons -----
        var variations = [];
        if ( typeof wp.blocks.getBlockVariations === 'function' ) {
            variations = wp.blocks.getBlockVariations( 'core/embed' ) || [];
        }
        if ( variations.length ) {
            var variationContainers = document.querySelectorAll( '.jg-gbbf-item__icon[data-variation-name]' );
            Array.prototype.forEach.call( variationContainers, function ( container ) {
                var slug = container.getAttribute( 'data-variation-name' );
                if ( ! slug ) {
                    return;
                }
                var variation = variations.find ? variations.find( function ( v ) { return v.name === slug; } ) : null;
                if ( ! variation || ! variation.icon ) {
                    return;
                }
                renderInto( createElement( BlockIcon, { icon: variation.icon, showColors: false } ), container );
            } );
        }
    }

    // Icon-Rendering ueber wp.domReady, weil wp-blocks/-block-editor
    // synchron als Abhaengigkeit geladen werden und dann verfuegbar sind.
    if ( window.wp && typeof wp.domReady === 'function' ) {
        wp.domReady( renderBlockIcons );
    } else {
        document.addEventListener( 'DOMContentLoaded', renderBlockIcons );
    }

    document.addEventListener( 'DOMContentLoaded', function () {

        var search        = document.getElementById( 'jg-gbbf-search' );
        var noResults     = document.querySelector( '.jg-gbbf-no-results' );
        var viewRadios    = document.querySelectorAll( 'input[name="jg_gbbf_view"]' );
        var viewCategory  = document.querySelector( '.jg-gbbf-view--category' );
        var viewNamespace = document.querySelector( '.jg-gbbf-view--namespace' );

        /**
         * Aktive Ansicht (Kategorie / Namespace) umschalten.
         */
        function switchView( value ) {
            if ( ! viewCategory || ! viewNamespace ) {
                return;
            }
            if ( 'namespace' === value ) {
                viewCategory.hidden  = true;
                viewNamespace.hidden = false;
            } else {
                viewCategory.hidden  = false;
                viewNamespace.hidden = true;
            }
            applyFilter();
        }

        Array.prototype.forEach.call( viewRadios, function ( radio ) {
            radio.addEventListener( 'change', function () {
                if ( radio.checked ) {
                    switchView( radio.value );
                }
            } );
        } );

        /**
         * Live-Suche – filtert Items in der aktiven Ansicht UND
         * in der immer sichtbaren Embed-Varianten-Sektion.
         */
        function applyFilter() {
            var query        = ( search ? search.value : '' ).toLowerCase().trim();
            var activeView   = document.querySelector( '.jg-gbbf-view:not([hidden])' );
            var variationGrp = document.querySelector( '.jg-gbbf-group--variations' );

            // Items aus der aktiven Block-Ansicht ...
            var items = [];
            if ( activeView ) {
                items = items.concat( Array.prototype.slice.call( activeView.querySelectorAll( '.jg-gbbf-item' ) ) );
            }
            // ... plus die immer sichtbare Embed-Varianten-Liste.
            if ( variationGrp ) {
                items = items.concat( Array.prototype.slice.call( variationGrp.querySelectorAll( '.jg-gbbf-item' ) ) );
            }

            var visibleCount = 0;
            items.forEach( function ( item ) {
                var title = ( item.getAttribute( 'data-title' ) || '' ).toLowerCase();
                var name  = ( item.getAttribute( 'data-name' )  || '' ).toLowerCase();
                var match = '' === query || title.indexOf( query ) !== -1 || name.indexOf( query ) !== -1;

                item.hidden = ! match;
                if ( match ) {
                    visibleCount++;
                }
            } );

            // Leere Gruppen ausblenden – aufgeraeumte Optik bei aktiver Suche.
            var groups = [];
            if ( activeView ) {
                groups = groups.concat( Array.prototype.slice.call( activeView.querySelectorAll( '.jg-gbbf-group' ) ) );
            }
            if ( variationGrp ) {
                groups.push( variationGrp );
            }
            groups.forEach( function ( group ) {
                var visibleItems = group.querySelectorAll( '.jg-gbbf-item:not([hidden])' );
                group.hidden = 0 === visibleItems.length;
            } );

            if ( noResults ) {
                noResults.hidden = visibleCount > 0;
            }
        }

        if ( search ) {
            search.addEventListener( 'input', applyFilter );
        }

        /**
         * Dasselbe Block-/Variations-Item existiert im DOM zweifach:
         * einmal in der Kategorie- und einmal in der Namespace-Ansicht.
         * Beim Submit liefert der Browser ALLE input[type=checkbox]-
         * Werte – auch die der gerade per HTML5-Attribut `hidden`
         * ausgeblendeten Ansicht. Wenn die beiden Ansichten nicht
         * synchron gehalten werden, ueberschreibt der Stand der
         * unsichtbaren Ansicht beim Speichern die Eingaben in der
         * sichtbaren Ansicht (typisches Symptom: "alle Haken sind
         * nach dem Speichern wieder da").
         *
         * Loesung: bei jeder Aenderung einer Checkbox spiegeln wir
         * den neuen Zustand auf alle Checkboxes mit demselben
         * `name`/`value`-Paar im Dokument. Damit haben Kategorie-
         * und Namespace-Ansicht stets identischen State.
         *
         * Geschuetzte Checkboxes (`disabled`) werden ignoriert.
         *
         * @param {string}  name    z. B. "hidden[]" oder "hidden_embed[]".
         * @param {string}  value   Block- oder Variations-Slug.
         * @param {boolean} checked Neuer Soll-Zustand.
         */
        function syncCheckboxByNameValue( name, value, checked ) {
            if ( ! name || ! value ) {
                return;
            }
            // CSS.escape() ist seit IE11+ verfuegbar; Fallback verhindert
            // den seltenen Bruch bei sehr alten Browsern.
            var safeValue = ( typeof window.CSS !== 'undefined' && typeof window.CSS.escape === 'function' )
                ? window.CSS.escape( value )
                : value.replace( /(["\\])/g, '\\$1' );

            var selector = 'input[type="checkbox"][name="' + name + '"][value="' + safeValue + '"]:not([disabled])';
            var twins    = document.querySelectorAll( selector );
            Array.prototype.forEach.call( twins, function ( twin ) {
                // Programmatisches Setzen loest in JS kein 'change'-
                // Event aus, daher entsteht keine Endlosschleife.
                if ( twin.checked !== checked ) {
                    twin.checked = checked;
                }
            } );
        }

        // Bei jeder User-Interaktion an einer Checkbox: spiegelbildliche
        // Checkboxes der anderen Ansicht mitziehen.
        var syncedCheckboxes = document.querySelectorAll(
            'input[type="checkbox"][name="hidden[]"], input[type="checkbox"][name="hidden_embed[]"]'
        );
        Array.prototype.forEach.call( syncedCheckboxes, function ( box ) {
            box.addEventListener( 'change', function () {
                syncCheckboxByNameValue( box.name, box.value, box.checked );
            } );
        } );

        /**
         * "Alle umschalten" pro Gruppe.
         *
         * Logik: Wenn mindestens eine nicht-geschuetzte Checkbox in der
         * Gruppe noch nicht angehakt ist, werden alle angehakt. Sind
         * bereits alle angehakt, werden alle abgehakt.
         *
         * Wichtig: nach dem Toggle werden die Zwillinge in der jeweils
         * anderen Ansicht ueber syncCheckboxByNameValue() synchronisiert
         * – sonst wuerde die unsichtbare Ansicht beim Speichern den
         * alten Stand mitliefern.
         */
        var toggleButtons = document.querySelectorAll( '.jg-gbbf-toggle-all' );
        Array.prototype.forEach.call( toggleButtons, function ( btn ) {
            btn.addEventListener( 'click', function () {
                var targetId = btn.getAttribute( 'data-target' );
                if ( ! targetId ) {
                    return;
                }
                var list = document.getElementById( targetId );
                if ( ! list ) {
                    return;
                }

                var boxes = list.querySelectorAll( 'input[type="checkbox"]:not([disabled])' );
                if ( 0 === boxes.length ) {
                    return;
                }

                var anyUnchecked = false;
                Array.prototype.forEach.call( boxes, function ( box ) {
                    if ( ! box.checked ) {
                        anyUnchecked = true;
                    }
                } );

                Array.prototype.forEach.call( boxes, function ( box ) {
                    box.checked = anyUnchecked;
                    syncCheckboxByNameValue( box.name, box.value, anyUnchecked );
                } );
            } );
        } );

    } );

} )();
