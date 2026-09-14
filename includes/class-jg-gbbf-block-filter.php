<?php
/**
 * Block-Inserter-Filter.
 *
 * Haengt sich an den WP-Filter `allowed_block_types_all` und nimmt zwei
 * Block-Gruppen aus der erlaubten Liste heraus:
 *
 *   1. vom Administrator manuell ausgeblendete Bloecke
 *      (Option 'jg_gbbf_hidden_blocks'),
 *   2. Core-Bloecke, die durch eine GenerateBlocks-Pro-Variante
 *      ersetzt werden, sobald GB Pro aktiv ist
 *      (JG_GBBF_Plugin::get_pro_replaced_blocks()).
 *
 * @package GB_Block_Filter
 * @author  Jörn Gorres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Block-Filter: reduziert ueber `allowed_block_types_all` die im
 * Inserter erlaubten Bloecke und entfernt per Editor-Skript die
 * ausgeblendeten Embed-Varianten.
 */
final class JG_GBBF_Block_Filter {

	/**
	 * Konstruktor – haengt den Filter mit spaeter Prioritaet ein, damit
	 * Filter anderer Plugins zuerst arbeiten und unsere Reduktion
	 * darauf operiert. Zusaetzlich wird das Editor-JS registriert,
	 * das nicht erlaubte Embed-Varianten ausblendet.
	 */
	public function __construct() {
		add_filter( 'allowed_block_types_all', array( $this, 'filter_blocks' ), 99, 2 );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
	}

	/**
	 * Filtert die Liste der erlaubten Bloecke.
	 *
	 * Verhalten:
	 *   - Sind keine Bloecke ausgeblendet, wird der Eingangswert
	 *     unveraendert zurueckgegeben.
	 *   - Liefert ein vorheriger Filter `false` (= gar keine Bloecke
	 *     erlaubt, z. B. durch ein Rollen-Restriktions-Plugin), wird
	 *     dieser Wert unveraendert durchgereicht. Wir heben fremde
	 *     Sperren nicht auf, denn eine Teilmenge von "nichts" bleibt
	 *     "nichts".
	 *   - Liefert ein vorheriger Filter `true` (= alle Bloecke
	 *     erlaubt), bauen wir die volle Liste aus dem Block-Type-
	 *     Registry auf, bevor wir ausblenden.
	 *   - Andernfalls reduzieren wir das vorhandene Array um die
	 *     ausgeblendeten Eintraege.
	 *
	 * @param bool|string[]           $allowed Aktuell erlaubte Bloecke.
	 * @param WP_Block_Editor_Context $context Editor-Kontext (ungenutzt).
	 * @return bool|string[]
	 */
	public function filter_blocks( $allowed, $context ) {
		unset( $context ); // Bewusst ungenutzt – wir filtern global.

		$hidden      = JG_GBBF_Plugin::get_hidden_blocks();
		$auto_hidden = JG_GBBF_Plugin::get_pro_replaced_blocks();

		// Effektive Hide-Liste:
		// - $hidden        : explizit vom Redakteur per UI ausgeblendet.
		// - $auto_hidden   : automatisch durch GB-Pro-Aktivierung
		// ausgeblendet (Core-Bloecke, fuer die GB Pro
		// eine erweiterte Variante mitbringt).
		// Wir fassen beide zusammen, deduplizieren defensiv und filtern
		// anschliessend einmalig.
		$effective_hidden = array_values( array_unique( array_merge( $hidden, $auto_hidden ) ) );

		if ( empty( $effective_hidden ) ) {
			return $allowed;
		}

		// Fremde Komplettsperre (false) unangetastet lassen. Nur bei
		// `true` (oder einem Nicht-Array als Defensivfall) wird die
		// vollstaendige Liste aus der Registry aufgebaut.
		if ( false === $allowed ) {
			return $allowed;
		}

		if ( ! is_array( $allowed ) ) {
			$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
			$allowed    = array_keys( $registered );
		}

		return array_values( array_diff( $allowed, $effective_hidden ) );
	}

	/**
	 * Bindet im Block-Editor ein kleines Skript ein, das die
	 * konfigurierten Embed-Varianten (YouTube, Reddit, …) per
	 * `wp.blocks.unregisterBlockVariation()` aus dem Inserter
	 * entfernt.
	 *
	 * Wird nur eingebunden, wenn ueberhaupt Varianten ausgeblendet
	 * wurden – sonst null Overhead im Editor.
	 */
	public function enqueue_editor_assets(): void {
		// Die eigene Settings-Seite loest `enqueue_block_editor_assets`
		// selbst aus, um Block-Icons aufzuloesen. Dort duerfen die
		// ausgeblendeten Embed-Varianten NICHT deregistriert werden,
		// sonst fehlen genau deren Icons in der Auswahlliste.
		if ( is_admin() && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( $screen instanceof WP_Screen
				&& 'settings_page_' . JG_GBBF_Admin_Page::MENU_SLUG === $screen->id ) {
				return;
			}
		}

		$hidden = JG_GBBF_Plugin::get_hidden_embed_variations();
		if ( empty( $hidden ) ) {
			return;
		}

		wp_enqueue_script(
			'jg-gbbf-editor',
			JG_GBBF_PLUGIN_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-dom-ready' ),
			JG_GBBF_VERSION,
			true
		);

		wp_localize_script(
			'jg-gbbf-editor',
			'jgGbbfEditorData',
			array(
				'hiddenEmbedVariations' => array_values( $hidden ),
			)
		);
	}
}
