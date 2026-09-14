<?php
/**
 * Hauptklasse des Plugins.
 *
 * Singleton, das beim Laden den Filter und die Admin-UI initialisiert
 * und gemeinsame Hilfsfunktionen (Optionen lesen, geschuetzte Bloecke,
 * Pro-Erkennung) bereitstellt.
 *
 * @package Block_Inserter_Filter
 * @author  Jörn Gorres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hauptklasse: Singleton, initialisiert Block-Filter und Admin-UI und
 * stellt die gemeinsamen Hilfsfunktionen (Optionen, Schutzliste,
 * Pro-Erkennung, Legacy-Migration) bereit.
 */
final class JGOR_BIF_Plugin {

	/**
	 * Singleton-Instanz.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Name der WP-Option, in der die ausgeblendeten Bloecke gespeichert
	 * werden (Array von Block-Namen, z. B. 'core/verse').
	 */
	public const OPTION_NAME = 'jgor_bif_hidden_blocks';

	/**
	 * Name der WP-Option fuer ausgeblendete Embed-Varianten
	 * (Array von Variation-Slugs, z. B. 'twitter', 'youtube').
	 */
	public const OPTION_EMBED_VARIATIONS = 'jgor_bif_hidden_embed_variations';

	/**
	 * Bekannte Embed-Varianten von WordPress Core.
	 *
	 * Variations werden in WordPress JS-seitig registriert und sind
	 * darum nicht im PHP-Block-Type-Registry sichtbar. Wir pflegen
	 * deshalb eine statische Liste, die ueber den Filter
	 * `jgor_bif_embed_variations` erweitert/ueberschrieben werden kann.
	 *
	 * Wenn eine hier gelistete Variante im aktuellen WP-Build nicht
	 * existiert (z. B. 'screencast'), bleibt das harmlos: das
	 * Editor-JS ruft `unregisterBlockVariation` auf, was bei
	 * unbekannten Namen still scheitert.
	 */
	public const EMBED_VARIATIONS = array(
		'twitter'       => 'Twitter / X',
		'youtube'       => 'YouTube',
		'facebook'      => 'Facebook',
		'instagram'     => 'Instagram',
		'wordpress'     => 'WordPress',
		'soundcloud'    => 'SoundCloud',
		'spotify'       => 'Spotify',
		'flickr'        => 'Flickr',
		'vimeo'         => 'Vimeo',
		'animoto'       => 'Animoto',
		'cloudup'       => 'Cloudup',
		'crowdsignal'   => 'Crowdsignal',
		'dailymotion'   => 'Dailymotion',
		'imgur'         => 'Imgur',
		'issuu'         => 'Issuu',
		'kickstarter'   => 'Kickstarter',
		'meetup-com'    => 'Meetup',
		'mixcloud'      => 'Mixcloud',
		'pocket-casts'  => 'Pocket Casts',
		'reddit'        => 'Reddit',
		'reverbnation'  => 'ReverbNation',
		'screencast'    => 'Screencast',
		'scribd'        => 'Scribd',
		'slideshare'    => 'SlideShare',
		'smugmug'       => 'SmugMug',
		'speaker'       => 'Speaker',
		'speaker-deck'  => 'Speaker Deck',
		'ted'           => 'TED',
		'tiktok'        => 'TikTok',
		'tumblr'        => 'Tumblr',
		'videopress'    => 'VideoPress',
		'wordpress-tv'  => 'WordPress.tv',
		'amazon-kindle' => 'Amazon Kindle',
		'pinterest'     => 'Pinterest',
		'bluesky'       => 'Bluesky',
		'wolfram-cloud' => 'Wolfram Cloud',
	);

	/**
	 * Block-Slugs, die NIEMALS ausgeblendet werden duerfen.
	 *
	 * Sicherheitsnetz, damit der Editor nutzbar bleibt – ohne Absatz,
	 * Ueberschrift, Liste, Bild, Gruppe und Spalten waere er praktisch
	 * unbedienbar.
	 *
	 * Achtung: NICHT direkt verwenden! Stattdessen
	 * JGOR_BIF_Plugin::get_protected_blocks() aufrufen, da der Schutz
	 * fuer einzelne Bloecke ('core/heading', 'core/image') aufgehoben
	 * wird, sobald GenerateBlocks Pro aktiv ist und damit eine
	 * erweiterte Variante zur Verfuegung stellt.
	 */
	public const PROTECTED_BLOCKS = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/image',
		'core/group',
		'core/columns',
		'core/column',
	);

	/**
	 * Core-Bloecke, fuer die GenerateBlocks Pro eine erweiterte
	 * Variante anbietet. Wenn GB Pro aktiv ist, werden diese Bloecke
	 * NICHT mehr geschuetzt – Redakteure koennen sie aus dem Inserter
	 * nehmen, sobald sie ausschliesslich die Pro-Variante verwenden
	 * wollen. In der Settings-Seite tragen diese Eintraege dann
	 * statt des "geschuetzt"-Badges einen "Pro-Variante"-Hinweis.
	 *
	 * Aktuell:
	 *   - core/heading -> GB Pro "Flexible Ueberschrift"
	 *   - core/image   -> GB Pro "Bild" (Container/Media)
	 */
	public const PRO_REPLACED_BLOCKS = array(
		'core/heading',
		'core/image',
	);

	/**
	 * Liefert die Singleton-Instanz.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Konstruktor – privat, damit das Singleton-Pattern eingehalten
	 * wird. Initialisiert die Sub-Module.
	 *
	 * Hinweis zur Internationalisierung: Quellsprache der Strings ist
	 * Englisch. Uebersetzungen kommen als Sprachpakete von
	 * translate.wordpress.org (Core laedt sie seit 4.6 automatisch,
	 * load_plugin_textdomain() ist nicht noetig). Die .po-Dateien unter
	 * languages/ sind die Vorlagen fuer den Import in GlotPress.
	 */
	private function __construct() {
		/*
		 * Legacy-Prefix-Migration mit Prio 1, damit der nachfolgende
		 * Admin-Code (Settings-Lesen, Block-Filter) bereits die
		 * jgor_bif_-Schluessel sieht. Laeuft idempotent einmal pro Site.
		 * Schema-Wechsel: gbba_* (< 1.5.0, Slug "generateblocks-bloecke-
		 * ausblenden") -> jg_gbbf_* (1.5.x, Slug "gb-block-filter")
		 * -> jgor_bif_* (ab 2.0.0, Slug "block-inserter-filter").
		 */
		add_action( 'admin_init', array( __CLASS__, 'migrate_legacy' ), 1 );

		// Sub-Module initialisieren.
		new JGOR_BIF_Block_Filter();
		new JGOR_BIF_Admin_Page();

		// "Einstellungen"-Link in der WP-Plugin-Liste anzeigen.
		add_filter(
			'plugin_action_links_' . plugin_basename( JGOR_BIF_PLUGIN_FILE ),
			array( $this, 'add_settings_link' )
		);
	}

	/**
	 * Stellt der Plugin-Zeile in der WP-Plugin-Liste einen
	 * "Einstellungen"-Link voran, der direkt auf die Settings-Seite
	 * fuehrt. Vereinfacht den Weg vom Plugin-Bildschirm zur
	 * Konfiguration.
	 *
	 * @param string[] $links Bestehende Action-Links (Aktivieren / Deaktivieren / …).
	 * @return string[]
	 */
	public function add_settings_link( array $links ): array {
		$url           = admin_url( 'options-general.php?page=' . JGOR_BIF_Admin_Page::MENU_SLUG );
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Settings', 'block-inserter-filter' )
		);

		// Vor die bestehenden Links setzen, damit "Settings" als
		// erste Aktion sichtbar ist.
		array_unshift( $links, $settings_link );

		return $links;
	}

	/**
	 * Liefert die Liste der ausgeblendeten Block-Slugs.
	 *
	 * Bereinigt das Ergebnis defensiv:
	 *   - nur Strings im Block-Namensformat 'namespace/name'
	 *   - keine geschuetzten Bloecke
	 *   - keine Duplikate, keine Leerstrings
	 *
	 * @return string[]
	 */
	public static function get_hidden_blocks(): array {
		$stored = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$cleaned = array();
		foreach ( $stored as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( ! self::is_valid_block_name( $name ) ) {
				continue;
			}
			if ( in_array( $name, self::get_protected_blocks(), true ) ) {
				continue;
			}
			$cleaned[] = $name;
		}

		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * Liefert die Liste der geschuetzten Core-Bloecke.
	 *
	 * Sicherheitsnetz: diese Bloecke sind im Plugin-UI fix gesperrt und
	 * koennen NICHT manuell ausgeblendet werden. Die Liste ist
	 * unabhaengig vom Aktiv-Status von GenerateBlocks Pro – sie sichert
	 * die grundlegende Editierbarkeit der Seite.
	 *
	 * Wrapper-Methode statt direktem Konstantenzugriff, damit zukuenftige
	 * Logik (z. B. Filter-Hooks) zentral hier andocken kann.
	 *
	 * @return string[]
	 */
	public static function get_protected_blocks(): array {
		return self::PROTECTED_BLOCKS;
	}

	/**
	 * Liefert die Core-Bloecke, die durch eine GenerateBlocks-Pro-
	 * Variante ersetzt werden UND deshalb automatisch aus dem Inserter
	 * verschwinden sollen, sobald GB Pro tatsaechlich aktiv ist.
	 *
	 * Doppelte Bedeutung:
	 *
	 *   1. In der Settings-UI tragen diese Bloecke einen
	 *      "Pro-Variante aktiv"-Badge anstelle des "geschuetzt"-Badges.
	 *      Die Checkbox ist sichtbar angehakt und gesperrt – sie zeigt
	 *      damit, dass der Block bereits aus dem Inserter genommen
	 *      wurde, OHNE dass das Hide-Setting des Redakteurs angefasst
	 *      werden muss.
	 *
	 *   2. Der Block-Inserter-Filter (JGOR_BIF_Block_Filter::filter_blocks)
	 *      nimmt diese Bloecke zusaetzlich zur user-konfigurierten Hide-
	 *      Liste aus dem Inserter. Damit greift das Auto-Hide auch dann,
	 *      wenn der User die Settings-Seite nie aufgerufen hat.
	 *
	 * Ist GenerateBlocks Pro nicht aktiv, liefert die Methode ein leeres
	 * Array – der Fallback ist dann der unveraenderte "geschuetzt"-Badge
	 * (Block bleibt im Inserter, weil Core-Block ohne Pro-Ersatz).
	 *
	 * @return string[]
	 */
	public static function get_pro_replaced_blocks(): array {
		if ( ! self::is_generateblocks_pro_active() ) {
			return array();
		}
		return self::PRO_REPLACED_BLOCKS;
	}

	/**
	 * Prueft, ob ein String dem WordPress-Block-Namensschema entspricht.
	 *
	 * Block-Namen sind klein geschrieben und folgen dem Muster
	 * 'namespace/name', wobei beide Teile aus a-z, 0-9 und Bindestrichen
	 * bestehen duerfen.
	 *
	 * @param string $name Zu pruefender Wert.
	 * @return bool
	 */
	public static function is_valid_block_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name );
	}

	/**
	 * Prueft, ob ein String dem WordPress-Variation-Namensschema
	 * entspricht (kleinbuchstaben, ziffern, bindestrich; kein '/').
	 *
	 * @param string $name Zu pruefender Wert.
	 * @return bool
	 */
	public static function is_valid_variation_name( string $name ): bool {
		return (bool) preg_match( '/^[a-z0-9-]+$/', $name );
	}

	/**
	 * Liefert die Liste aller bekannten Embed-Varianten als
	 * Slug => Anzeigename. Erweiterbar via Filter.
	 *
	 * @return array<string, string>
	 */
	public static function get_embed_variations(): array {
		$variations = self::EMBED_VARIATIONS;

		/**
		 * Filter: Erlaubt es Themes/Plugins, weitere Embed-Varianten
		 * zur Liste hinzuzufuegen oder vorhandene umzubenennen.
		 *
		 * @param array<string, string> $variations Slug => Anzeigename.
		 */
		$filtered = apply_filters( 'jgor_bif_embed_variations', $variations );

		if ( ! is_array( $filtered ) ) {
			return $variations;
		}

		// Defensive Bereinigung: nur Eintraege mit gueltigem Variation-Slug + nichtleerem Titel.
		$cleaned = array();
		foreach ( $filtered as $slug => $label ) {
			if ( ! is_string( $slug ) || ! is_string( $label ) ) {
				continue;
			}
			if ( ! self::is_valid_variation_name( $slug ) ) {
				continue;
			}
			$label = trim( $label );
			if ( '' === $label ) {
				continue;
			}
			$cleaned[ $slug ] = $label;
		}

		return $cleaned;
	}

	/**
	 * Liefert die Liste der ausgeblendeten Embed-Varianten.
	 *
	 * @return string[] Array von Variation-Slugs (z. B. ['twitter','reddit']).
	 */
	public static function get_hidden_embed_variations(): array {
		$stored = get_option( self::OPTION_EMBED_VARIATIONS, array() );

		if ( ! is_array( $stored ) ) {
			return array();
		}

		$known = array_keys( self::get_embed_variations() );

		$cleaned = array();
		foreach ( $stored as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			if ( ! self::is_valid_variation_name( $slug ) ) {
				continue;
			}
			// Whitelist: nur tatsaechlich definierte Varianten.
			if ( ! in_array( $slug, $known, true ) ) {
				continue;
			}
			$cleaned[] = $slug;
		}

		return array_values( array_unique( $cleaned ) );
	}

	/**
	 * Erkennt, ob GenerateBlocks Pro aktiv ist.
	 *
	 * Wir pruefen sowohl die Versionskonstante als auch die Hauptklasse,
	 * um gegen Aenderungen in zukuenftigen Pro-Versionen robust zu sein.
	 *
	 * @return bool
	 */
	public static function is_generateblocks_pro_active(): bool {
		return defined( 'GENERATEBLOCKS_PRO_VERSION' ) || class_exists( 'GenerateBlocks_Pro' );
	}

	/**
	 * Einmalige Legacy-Prefix-Migration (gbba_* / jg_gbbf_* -> jgor_bif_*).
	 *
	 * Wird beim ersten admin_init nach dem Update auf 2.0.0 ausgefuehrt.
	 * Sentinel-Option `jgor_bif_migrated_v2` verhindert Mehrfachlauf.
	 *
	 * Schritte:
	 *   1. Sentinel pruefen - bereits gelaufen? Abbruch.
	 *   2. Je Einstellung den neuesten vorhandenen Legacy-Wert
	 *      uebernehmen (zuerst jg_gbbf_*, sonst gbba_*), aber nur,
	 *      wenn der neue Key noch leer ist (Strict-Vergleich gegen false).
	 *   3. Alle Legacy-Optionen samt altem Sentinel loeschen.
	 *   4. Sentinel setzen.
	 *
	 * Multisite-Sweep: vom Hauptsite-Admin aus iterieren wir ueber
	 * alle Subsites und fuehren die Site-lokale Migration je einmal
	 * aus. Netzwerk-Sentinel `jgor_bif_network_swept_v2` schuetzt
	 * vor doppeltem Sweep; der alte Netzwerk-Sentinel wird entfernt.
	 *
	 * @return void
	 */
	public static function migrate_legacy(): void {

		// Per-Site Migration.
		self::do_legacy_migration_current_site();

		// Multisite-Netzwerk-Sweep - genau einmal pro Netzwerk vom
		// Hauptsite-Admin aus (admin_init feuert pro Subsite).
		if ( is_multisite() && is_main_site() ) {
			$network_sentinel = 'jgor_bif_network_swept_v2';
			if ( ! get_site_option( $network_sentinel ) ) {
				// 'number' => 0 hebt das Standardlimit von 100 Sites auf.
				$site_ids = get_sites(
					array(
						'fields'     => 'ids',
						'number'     => 0,
						'network_id' => get_current_network_id(),
					)
				);
				foreach ( $site_ids as $blog_id ) {
					switch_to_blog( (int) $blog_id );
					self::do_legacy_migration_current_site();
					restore_current_blog();
				}
				update_site_option( $network_sentinel, JGOR_BIF_VERSION );
				delete_site_option( 'jg_gbbf_network_swept_v1_5' );
			}
		}
	}

	/**
	 * Site-lokale Migration durchfuehren (idempotent ueber Sentinel).
	 *
	 * @return void
	 */
	private static function do_legacy_migration_current_site(): void {
		$sentinel = 'jgor_bif_migrated_v2';
		if ( get_option( $sentinel ) ) {
			return;
		}

		// Neuer Key => Legacy-Keys in absteigender Aktualitaet.
		$key_map = array(
			'jgor_bif_hidden_blocks'           => array( 'jg_gbbf_hidden_blocks', 'gbba_hidden_blocks' ),
			'jgor_bif_hidden_embed_variations' => array( 'jg_gbbf_hidden_embed_variations', 'gbba_hidden_embed_variations' ),
		);

		foreach ( $key_map as $new_key => $legacy_keys ) {
			$new_existing = get_option( $new_key, false );
			foreach ( $legacy_keys as $legacy_key ) {
				$legacy_value = get_option( $legacy_key, false );
				if ( false !== $legacy_value ) {
					if ( false === $new_existing ) {
						update_option( $new_key, $legacy_value );
						$new_existing = $legacy_value;
					}
					delete_option( $legacy_key );
				}
			}
		}

		// Sentinel der Migration 1.5 ist ueberfluessig geworden.
		delete_option( 'jg_gbbf_migrated_v1_5' );

		update_option( $sentinel, JGOR_BIF_VERSION );
	}
}
