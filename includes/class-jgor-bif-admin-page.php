<?php
/**
 * Admin-UI: Settings-Seite zum Aus-/Einblenden von Bloecken.
 *
 * Listet alle registrierten Bloecke gruppiert nach Kategorie (wie im
 * Block-Editor) oder alternativ nach Namespace. Pro Block eine
 * Checkbox; markiert = ausgeblendet.
 *
 * Sicherheitsmechanismen:
 *   - `manage_options`-Capability bei Anzeige UND Speicherung.
 *   - Nonce-Pruefung via `check_admin_referer()`.
 *   - Whitelist-Sanitization beim Speichern: nur tatsaechlich
 *     registrierte Bloecke werden uebernommen, geschuetzte Bloecke
 *     werden hart aussortiert.
 *   - Output durchgaengig escaped.
 *
 * @package Block_Inserter_Filter
 * @author  Jörn Gorres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-UI: Settings-Seite unter "Settings" mit der Block- und
 * Embed-Varianten-Auswahl, Formularverarbeitung und Asset-Einbindung.
 */
final class JGOR_BIF_Admin_Page {

	/**
	 * Slug der Settings-Seite (Query-Parameter ?page=...).
	 *
	 * Seit 1.6.0 englisch; vorher 'jgor-bif-bloecke-ausblenden'. Alte
	 * Lesezeichen auf die Settings-Seite laufen deshalb ins Leere.
	 */
	public const MENU_SLUG = 'jgor-bif-hide-blocks';

	/**
	 * Nonce-Action-Name.
	 */
	public const NONCE_ACTION = 'jgor_bif_save_blocks';

	/**
	 * `action`-Wert fuer admin-post.php.
	 */
	public const FORM_ACTION = 'jgor_bif_save';

	/**
	 * Konstruktor – haengt die Hooks ein.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_' . self::FORM_ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Registriert den Menueintrag unter "Settings" (Einstellungen).
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Hide Blocks', 'block-inserter-filter' ),
			__( 'Hide Blocks', 'block-inserter-filter' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Bindet CSS und JS – nur auf der eigenen Settings-Seite.
	 *
	 * Damit die Block-Icons clientseitig aufgeloest werden koennen
	 * (sie sind ueberwiegend als React-Komponenten registriert und
	 * nicht serverseitig zugaenglich), werden hier die regulaeren
	 * Block-Editor-Skripte eingebunden und der Hook
	 * `enqueue_block_editor_assets` ausgeloest. So registrieren auch
	 * Drittanbieter-Plugins wie GenerateBlocks ihre Bloecke und damit
	 * deren Icons.
	 *
	 * @param string $hook_suffix Aktueller Admin-Hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'settings_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		// Eigene Styles.
		wp_enqueue_style(
			'jgor-bif-admin',
			JGOR_BIF_PLUGIN_URL . 'assets/admin.css',
			array(),
			JGOR_BIF_VERSION
		);

		// Block-Editor-Assets fuer die Icon-Aufloesung.
		wp_enqueue_script( 'wp-blocks' );
		wp_enqueue_script( 'wp-block-library' );
		wp_enqueue_script( 'wp-block-editor' );
		wp_enqueue_script( 'wp-element' );
		wp_enqueue_style( 'wp-block-library' );

		/*
		 * Plugin-/Theme-eigene Bloecke mitregistrieren, damit auch
		 * deren Icons verfuegbar sind.
		 *
		 * Bewusst der Core-Hook 'enqueue_block_editor_assets' – die
		 * Settings-Seite ist zwar keine echte Editor-Seite, simuliert
		 * aber den Editor-Kontext, damit andere Plugins/Themes ihre
		 * Block-Assets registrieren. Ein eigener jgor_bif_*-Hook waere
		 * funktional unsinnig, weil sich keine externen Plugins
		 * daran anhaengen wuerden. Core selbst feuert den Hook ebenfalls
		 * ausserhalb des Post-Editors (Widgets-Screen, Site-Editor).
		 * Das plugineigene editor.js erkennt diese Seite ueber
		 * get_current_screen() und laedt hier nicht (siehe
		 * JGOR_BIF_Block_Filter::enqueue_editor_assets()).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-Hook bewusst: andere Plugins/Themes sollen ihre Block-Assets registrieren.
		do_action( 'enqueue_block_editor_assets' );

		wp_enqueue_script(
			'jgor-bif-admin',
			JGOR_BIF_PLUGIN_URL . 'assets/admin.js',
			array( 'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-element', 'wp-dom-ready' ),
			JGOR_BIF_VERSION,
			true
		);
	}

	/**
	 * Verarbeitet das gesendete Formular.
	 *
	 * Validiert Nonce + Capability, sanitisiert die Auswahl gegen die
	 * Liste der tatsaechlich registrierten Bloecke und speichert das
	 * Ergebnis in der Plugin-Option.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do this.', 'block-inserter-filter' ),
				esc_html__( 'You do not have permission to do this.', 'block-inserter-filter' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE_ACTION );

		// ----- 1) Bloecke -----
		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$valid_keys = array_keys( $registered );

		/*
		 * Sanitization auf derselben Zeile wie der $_POST-Zugriff, damit
		 * der WPCS-Sniff "ValidatedSanitizedInput.InputNotSanitized" sie
		 * sicher erkennt: array_map() mit einem bekannten Sanitizer-
		 * Callback gilt fuer WPCS als gueltige Sanitization-Wrapper.
		 * Die eigentliche Whitelist-Validierung gegen die registrierten
		 * Bloecke erfolgt anschliessend in der Schleife.
		 */
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wurde oben via check_admin_referer geprueft.
		$submitted = isset( $_POST['hidden'] ) && is_array( $_POST['hidden'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['hidden'] ) )
			: array();

		$sanitized_blocks = array();
		foreach ( $submitted as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( ! JGOR_BIF_Plugin::is_valid_block_name( $name ) ) {
				continue;
			}
			if ( in_array( $name, JGOR_BIF_Plugin::get_protected_blocks(), true ) ) {
				continue;
			}
			if ( ! in_array( $name, $valid_keys, true ) ) {
				continue;
			}
			$sanitized_blocks[] = $name;
		}
		$sanitized_blocks = array_values( array_unique( $sanitized_blocks ) );

		update_option( JGOR_BIF_Plugin::OPTION_NAME, $sanitized_blocks );

		// ----- 2) Embed-Varianten -----
		// Sanitization analog zum Block-Array oben; Validierung gegen die
		// Whitelist bekannter Embed-Varianten erfolgt anschliessend.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wurde oben via check_admin_referer geprueft.
		$submitted_vars = isset( $_POST['hidden_embed'] ) && is_array( $_POST['hidden_embed'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['hidden_embed'] ) )
			: array();

		$known_variations     = array_keys( JGOR_BIF_Plugin::get_embed_variations() );
		$sanitized_variations = array();
		foreach ( $submitted_vars as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			if ( ! JGOR_BIF_Plugin::is_valid_variation_name( $slug ) ) {
				continue;
			}
			if ( ! in_array( $slug, $known_variations, true ) ) {
				continue;
			}
			$sanitized_variations[] = $slug;
		}
		$sanitized_variations = array_values( array_unique( $sanitized_variations ) );

		update_option( JGOR_BIF_Plugin::OPTION_EMBED_VARIATIONS, $sanitized_variations );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::MENU_SLUG,
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Rendert die Settings-Seite.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to do this.', 'block-inserter-filter' ),
				esc_html__( 'You do not have permission to do this.', 'block-inserter-filter' ),
				array( 'response' => 403 )
			);
		}

		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$hidden     = JGOR_BIF_Plugin::get_hidden_blocks();
		$hidden_map = array_fill_keys( $hidden, true );

		// Block-Kategorien (Slug => Anzeigename) holen.
		$cat_map = $this->get_category_titles();

		// GenerateBlocks Pro als eigene Kategorie ausweisen, damit
		// Free- und Pro-Bloecke nicht in einem Topf landen.
		if ( ! isset( $cat_map['generateblocks-pro'] ) ) {
			$cat_map['generateblocks-pro'] = 'GenerateBlocks Pro';
		}
		if ( ! isset( $cat_map['generateblocks'] ) ) {
			$cat_map['generateblocks'] = 'GenerateBlocks';
		}

		// Bloecke nach Kategorie und Namespace gruppieren.
		$by_category  = array();
		$by_namespace = array();

		foreach ( $registered as $name => $block ) {
			$name_str  = (string) $name;
			$title     = ! empty( $block->title ) ? (string) $block->title : $name_str;
			$namespace = strstr( $name_str, '/', true );
			if ( false === $namespace || '' === $namespace ) {
				$namespace = 'uncategorized';
			}

			$is_pro            = str_starts_with( $name_str, 'generateblocks-pro/' );
			$declared_category = ! empty( $block->category ) ? (string) $block->category : '';

			/*
			 * Legacy- / Render-only-Blocks ueberspringen.
			 *
			 * Hintergrund: Einige Plugins registrieren Blocks weiterhin
			 * per register_block_type() ohne block.json, damit bereits
			 * gespeicherte Beitraege sie auch nach einem Refactoring
			 * weiter rendern koennen. Beispiel: GenerateBlocks 2.x
			 * registriert den alten 'generateblocks/container' nur noch
			 * mit Titel + render_callback (siehe class-render-blocks.php
			 * in GenerateBlocks). Solche Bloecke
			 *   - erscheinen nicht im Inserter (kein clientseitiges
			 *     registerBlockType()),
			 *   - haben kein attributes-Schema und keine Kategorie,
			 *   - lassen sich also nicht sinnvoll aus dem Inserter
			 *     ausblenden.
			 *
			 * Wuerden wir sie trotzdem listen, erschienen sie neben dem
			 * Nachfolger-Block mit demselben Titel (z. B. "Container"
			 * doppelt) und stifteten Verwirrung. Deshalb hier filtern:
			 * leeres attributes UND leere Kategorie => Legacy/Server-only.
			 *
			 * Pro-Bloecke sind von dieser Heuristik nicht betroffen,
			 * weil sie durchgehend per block.json registriert sind und
			 * damit sowohl Attribute als auch eine Kategorie tragen.
			 */
			if ( empty( $block->attributes ) && '' === $declared_category ) {
				continue;
			}

			/*
			 * Kategorie bestimmen:
			 *   1. Pro-Bloecke landen in der virtuellen Kategorie
			 *      'generateblocks-pro' – egal, was das Pro-Plugin
			 *      selbst als Kategorie setzt.
			 *   2. Bloecke ohne deklarierte Kategorie (oder mit
			 *      'uncategorized') fallen auf ihren Namespace zurueck.
			 *      Das fischt z. B. GenerateBlocks-Innenblocks
			 *      ('generateblocks/loop-item', 'query-page-numbers',
			 *      'query-no-results' …) aus dem 'Uncategorized'-
			 *      Topf und sortiert sie zur passenden Hauptgruppe.
			 *   3. Sonst: deklarierte Kategorie verwenden.
			 */
			if ( $is_pro ) {
				$category = 'generateblocks-pro';
			} elseif ( '' === $declared_category || 'uncategorized' === $declared_category ) {
				$category = $namespace;
			} else {
				$category = $declared_category;
			}

			$entry = array(
				'name'         => $name_str,
				'title'        => $title,
				'protected'    => in_array( $name_str, JGOR_BIF_Plugin::get_protected_blocks(), true ),
				'pro_replaced' => in_array( $name_str, JGOR_BIF_Plugin::get_pro_replaced_blocks(), true ),
				'is_pro'       => $is_pro,
			);

			$by_category[ $category ][]   = $entry;
			$by_namespace[ $namespace ][] = $entry;
		}

		// Innerhalb der Gruppen alphabetisch nach Titel sortieren.
		$sort_by_title = static function ( array $a, array $b ): int {
			return strcasecmp( $a['title'], $b['title'] );
		};
		foreach ( $by_category as &$blocks_in_cat ) {
			usort( $blocks_in_cat, $sort_by_title );
		}
		unset( $blocks_in_cat );
		foreach ( $by_namespace as &$blocks_in_ns ) {
			usort( $blocks_in_ns, $sort_by_title );
		}
		unset( $blocks_in_ns );

		// Kategorie-Reihenfolge: alphabetisch nach Anzeigenamen.
		$cat_slugs = array_keys( $by_category );
		usort(
			$cat_slugs,
			static function ( $a, $b ) use ( $cat_map ): int {
				$ta = $cat_map[ $a ] ?? $a;
				$tb = $cat_map[ $b ] ?? $b;
				return strcasecmp( $ta, $tb );
			}
		);

		// Namespace-Reihenfolge: alphabetisch.
		$namespaces = array_keys( $by_namespace );
		sort( $namespaces );

		// Der Erfolgshinweis nach dem Speichern kommt von WordPress selbst:
		// wp-admin/options-head.php zeigt bei ?updated=1 auf Unterseiten von
		// "Einstellungen" die Meldung "Settings saved." an. Eine eigene
		// Meldung wuerde doppelt erscheinen.
		$total_count      = count( $registered );
		$hidden_count     = count( $hidden );
		$hidden_var_count = count( JGOR_BIF_Plugin::get_hidden_embed_variations() );

		?>
		<div class="wrap jgor-bif-admin">
			<h1><?php esc_html_e( 'Hide Blocks', 'block-inserter-filter' ); ?></h1>

			<p class="jgor-bif-intro">
				<?php
				printf(
					/* translators: 1: number of registered blocks, 2: number of hidden blocks, 3: number of hidden embed variations. */
					esc_html__( 'Registered blocks: %1$d. Currently hidden: %2$d blocks and %3$d embed variations.', 'block-inserter-filter' ),
					(int) $total_count,
					(int) $hidden_count,
					(int) $hidden_var_count
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Checked blocks are removed from the block inserter for all users. Existing content using hidden blocks remains visible – only inserting new instances is blocked.', 'block-inserter-filter' ); ?>
				<?php esc_html_e( 'Certain core blocks (Paragraph, Heading, List, Image, Group, Columns) always stay available as a safety net and cannot be hidden here.', 'block-inserter-filter' ); ?>
			</p>

			<div class="jgor-bif-toolbar">
				<label class="jgor-bif-search-label" for="jgor-bif-search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search blocks', 'block-inserter-filter' ); ?></span>
					<input
						type="search"
						id="jgor-bif-search"
						class="jgor-bif-search"
						placeholder="<?php esc_attr_e( 'Search blocks…', 'block-inserter-filter' ); ?>"
						autocomplete="off"
					>
				</label>

				<fieldset class="jgor-bif-view-switch">
					<legend class="screen-reader-text"><?php esc_html_e( 'Choose grouping', 'block-inserter-filter' ); ?></legend>
					<label>
						<input type="radio" name="jgor_bif_view" value="category" checked>
						<?php esc_html_e( 'By category', 'block-inserter-filter' ); ?>
					</label>
					<label>
						<input type="radio" name="jgor_bif_view" value="namespace">
						<?php esc_html_e( 'By namespace', 'block-inserter-filter' ); ?>
					</label>
				</fieldset>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jgor-bif-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<div class="jgor-bif-view jgor-bif-view--category" data-view="category">
					<?php foreach ( $cat_slugs as $cat_slug ) : ?>
						<?php
						$blocks_for_cat = $by_category[ $cat_slug ];
						$cat_label      = $cat_map[ $cat_slug ] ?? $cat_slug;
						$this->render_group( 'cat-' . $cat_slug, $cat_label, $cat_slug, $blocks_for_cat, $hidden_map );
						?>
					<?php endforeach; ?>
				</div>

				<div class="jgor-bif-view jgor-bif-view--namespace" data-view="namespace" hidden>
					<?php foreach ( $namespaces as $ns ) : ?>
						<?php
						// Label = roher Namespace; das Pro-Badge in der
						// Gruppen-Legende kennzeichnet den Pro-Block-Pool.
						$this->render_group( 'ns-' . $ns, $ns, $ns, $by_namespace[ $ns ], $hidden_map );
						?>
					<?php endforeach; ?>
				</div>

				<?php $this->render_embed_variations_section(); ?>

				<p class="jgor-bif-no-results" hidden>
					<em><?php esc_html_e( 'No blocks found.', 'block-inserter-filter' ); ?></em>
				</p>

				<?php submit_button( __( 'Save Settings', 'block-inserter-filter' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Rendert eine einzelne Block-Gruppe (Kategorie oder Namespace).
	 *
	 * @param string                           $group_slug Eindeutiger Slug fuer DOM-IDs.
	 * @param string                           $group_label Anzeigetitel der Gruppe.
	 * @param string                           $group_raw   Roher Slug ohne Prefix.
	 * @param array<int, array<string, mixed>> $blocks      Block-Eintraege der Gruppe.
	 * @param array<string, bool>              $hidden_map  Map ausgeblendeter Bloecke (name => true).
	 */
	private function render_group( string $group_slug, string $group_label, string $group_raw, array $blocks, array $hidden_map ): void {
		if ( empty( $blocks ) ) {
			return;
		}

		$list_id  = 'jgor-bif-list-' . sanitize_html_class( $group_slug );
		$panel_id = 'jgor-bif-panel-' . sanitize_html_class( $group_slug );
		$is_pro_g = ( 'generateblocks-pro' === $group_raw );

		// Anzahl der in dieser Gruppe ausgeblendeten Bloecke (manuell
		// oder automatisch durch GenerateBlocks Pro) fuer die Kopfzeile.
		$hidden_in_group = 0;
		foreach ( $blocks as $entry ) {
			if ( isset( $hidden_map[ $entry['name'] ] ) || ! empty( $entry['pro_replaced'] ) ) {
				++$hidden_in_group;
			}
		}
		// Vorlage mit %d; das JS ersetzt den Platzhalter beim Live-Update,
		// deshalb steht sie zusaetzlich unuebersetzt-frei als data-label.
		/* translators: %d: number of hidden blocks in this group. */
		$hidden_template = __( '%d hidden', 'block-inserter-filter' );
		?>
		<div class="jgor-bif-group" role="group" data-group="<?php echo esc_attr( $group_slug ); ?>">
			<div class="jgor-bif-group__header">
				<?php
				/*
				 * Accordion-Kopf: ein echter Button mit aria-expanded /
				 * aria-controls, damit Tastatur und Screenreader den
				 * Zustand kennen. Startzustand geschlossen, das Panel
				 * traegt `hidden`. "Toggle all" steht bewusst NEBEN dem
				 * Kopf-Button, Buttons duerfen nicht verschachtelt werden.
				 */
				?>
				<button
					type="button"
					class="jgor-bif-group__toggle"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $panel_id ); ?>"
				>
					<span class="jgor-bif-group__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					<span class="jgor-bif-group__title"><?php echo esc_html( $group_label ); ?></span>
					<span class="jgor-bif-group__count"><?php echo (int) count( $blocks ); ?></span>
					<span class="jgor-bif-group__hidden" data-label="<?php echo esc_attr( $hidden_template ); ?>"><?php echo esc_html( sprintf( $hidden_template, (int) $hidden_in_group ) ); ?></span>
					<?php if ( $is_pro_g ) : ?>
						<span class="jgor-bif-badge jgor-bif-badge--pro">Pro</span>
					<?php endif; ?>
				</button>
				<button
					type="button"
					class="button button-small jgor-bif-toggle-all"
					data-target="<?php echo esc_attr( $list_id ); ?>"
				><?php esc_html_e( 'Toggle all', 'block-inserter-filter' ); ?></button>
			</div>
			<div class="jgor-bif-group__panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<ul class="jgor-bif-list" id="<?php echo esc_attr( $list_id ); ?>">
				<?php foreach ( $blocks as $entry ) : ?>
					<?php
					/*
					 * Drei Zustaende pro Eintrag:
					 *
					 *   - protected     : Core-Block aus dem Sicherheits-
					 *                     netz (Absatz, Liste, Gruppe …).
					 *                     Checkbox disabled + unchecked.
					 *                     Badge "geschuetzt".
					 *
					 *   - pro_replaced  : Core-Block, fuer den GenerateBlocks
					 *                     Pro eine erweiterte Variante
					 *                     mitbringt UND Pro ist aktiv.
					 *                     Der Block wird automatisch aus
					 *                     dem Inserter genommen (via
					 *                     JGOR_BIF_Block_Filter::filter_blocks).
					 *                     Visuell wird die Checkbox
					 *                     angehakt + gesperrt dargestellt –
					 *                     das macht klar, dass der Block
					 *                     gerade ausgeblendet ist, ohne
					 *                     dass die User-Option dafuer
					 *                     beschrieben werden muesste.
					 *                     Badge "Pro-Variante aktiv".
					 *
					 *   - normal        : Frei waehlbar (Checkbox aktiv).
					 */
					$pro_replaced   = ! empty( $entry['pro_replaced'] );
					$is_user_hidden = isset( $hidden_map[ $entry['name'] ] );
					$is_hidden      = $is_user_hidden || $pro_replaced;
					$disabled       = (bool) $entry['protected'] || $pro_replaced;
					$item_class     = 'jgor-bif-item'
						. ( $disabled ? ' jgor-bif-item--protected' : '' )
						. ( $pro_replaced ? ' jgor-bif-item--pro-replaced' : '' );
					?>
					<li class="<?php echo esc_attr( $item_class ); ?>"
						data-name="<?php echo esc_attr( $entry['name'] ); ?>"
						data-title="<?php echo esc_attr( $entry['title'] ); ?>">
						<label>
							<input
								type="checkbox"
								name="hidden[]"
								value="<?php echo esc_attr( $entry['name'] ); ?>"
								<?php checked( $is_hidden ); ?>
								<?php disabled( $disabled ); ?>
							>
							<span class="jgor-bif-item__icon" data-block-name="<?php echo esc_attr( $entry['name'] ); ?>" aria-hidden="true"></span>
							<span class="jgor-bif-item__title"><?php echo esc_html( $entry['title'] ); ?></span>
							<code class="jgor-bif-item__name"><?php echo esc_html( $entry['name'] ); ?></code>
							<?php if ( $pro_replaced ) : ?>
								<span
									class="jgor-bif-badge jgor-bif-badge--pro-replacement"
									title="<?php esc_attr_e( 'GenerateBlocks Pro is active. This core block is hidden from the inserter automatically because GenerateBlocks Pro provides an extended variant. If GenerateBlocks Pro is deactivated, the core block reappears automatically.', 'block-inserter-filter' ); ?>"
								><?php esc_html_e( 'Pro variant active', 'block-inserter-filter' ); ?></span>
							<?php elseif ( $disabled ) : ?>
								<span class="jgor-bif-badge jgor-bif-badge--protected"><?php esc_html_e( 'protected', 'block-inserter-filter' ); ?></span>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="jgor-bif-group__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'block-inserter-filter' ); ?></button>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Rendert die Sektion fuer Embed-Varianten.
	 *
	 * Embed-Varianten (YouTube, Reddit, Vimeo, …) sind technisch
	 * keine eigenen Block-Typen, sondern werden via
	 * `wp.blocks.registerBlockVariation` an den Block `core/embed`
	 * gehaengt. Wir verwalten sie deshalb in einer separaten
	 * Sektion mit eigenem POST-Feldnamen `hidden_embed[]`.
	 *
	 * Die Sektion ist immer sichtbar (unabhaengig von Kategorie-/
	 * Namespace-View).
	 */
	private function render_embed_variations_section(): void {
		$variations = JGOR_BIF_Plugin::get_embed_variations();
		if ( empty( $variations ) ) {
			return;
		}

		$hidden_variations = JGOR_BIF_Plugin::get_hidden_embed_variations();
		$hidden_map        = array_fill_keys( $hidden_variations, true );

		// Alphabetisch nach Anzeigename sortieren.
		asort( $variations, SORT_NATURAL | SORT_FLAG_CASE );

		$list_id         = 'jgor-bif-list-embed-variations';
		$panel_id        = 'jgor-bif-panel-embed-variations';
		$hidden_in_group = count( array_intersect_key( $hidden_map, $variations ) );
		/* translators: %d: number of hidden embed variations in this group. */
		$hidden_template = __( '%d hidden', 'block-inserter-filter' );
		?>
		<div class="jgor-bif-group jgor-bif-group--variations" role="group" data-group="embed-variations">
			<div class="jgor-bif-group__header">
				<button
					type="button"
					class="jgor-bif-group__toggle"
					aria-expanded="false"
					aria-controls="<?php echo esc_attr( $panel_id ); ?>"
				>
					<span class="jgor-bif-group__chevron dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span>
					<span class="jgor-bif-group__title"><?php esc_html_e( 'Embed variations', 'block-inserter-filter' ); ?></span>
					<span class="jgor-bif-group__count"><?php echo (int) count( $variations ); ?></span>
					<span class="jgor-bif-group__hidden" data-label="<?php echo esc_attr( $hidden_template ); ?>"><?php echo esc_html( sprintf( $hidden_template, (int) $hidden_in_group ) ); ?></span>
				</button>
				<button
					type="button"
					class="button button-small jgor-bif-toggle-all"
					data-target="<?php echo esc_attr( $list_id ); ?>"
				><?php esc_html_e( 'Toggle all', 'block-inserter-filter' ); ?></button>
			</div>
			<div class="jgor-bif-group__panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
			<p class="description jgor-bif-group__hint">
				<?php esc_html_e( 'Variations of the "Embed" block (YouTube, Reddit, Vimeo, …). Checked variations are removed in the editor; the base "Embed" block remains available.', 'block-inserter-filter' ); ?>
			</p>
			<ul class="jgor-bif-list" id="<?php echo esc_attr( $list_id ); ?>">
				<?php foreach ( $variations as $slug => $label ) : ?>
					<?php $is_hidden = isset( $hidden_map[ $slug ] ); ?>
					<li class="jgor-bif-item jgor-bif-item--variation"
						data-name="<?php echo esc_attr( $slug ); ?>"
						data-title="<?php echo esc_attr( $label ); ?>">
						<label>
							<input
								type="checkbox"
								name="hidden_embed[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $is_hidden ); ?>
							>
							<span class="jgor-bif-item__icon" data-variation-name="<?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span>
							<span class="jgor-bif-item__title"><?php echo esc_html( $label ); ?></span>
							<code class="jgor-bif-item__name"><?php echo esc_html( $slug ); ?></code>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="jgor-bif-group__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'block-inserter-filter' ); ?></button>
			</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Liefert die WordPress-Block-Kategorien als Map (Slug => Titel).
	 *
	 * Greift auf `get_block_categories()` mit einem Standard-Editor-
	 * Kontext zurueck und sammelt alle bekannten Kategorien (inkl. der
	 * von Plugins/Themes per `block_categories_all` ergaenzten).
	 *
	 * @return array<string, string>
	 */
	private function get_category_titles(): array {
		$map = array();

		if ( class_exists( 'WP_Block_Editor_Context' ) && function_exists( 'get_block_categories' ) ) {
			$context    = new WP_Block_Editor_Context( array( 'name' => 'core/edit-post' ) );
			$categories = get_block_categories( $context );
			if ( is_array( $categories ) ) {
				foreach ( $categories as $cat ) {
					if ( isset( $cat['slug'], $cat['title'] ) ) {
						$map[ (string) $cat['slug'] ] = (string) $cat['title'];
					}
				}
			}
		}

		// Fallback fuer den Edge-Case 'uncategorized'.
		if ( ! isset( $map['uncategorized'] ) ) {
			$map['uncategorized'] = __( 'Uncategorized', 'block-inserter-filter' );
		}

		return $map;
	}
}
