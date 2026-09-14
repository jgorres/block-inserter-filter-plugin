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
 * @package GB_Block_Filter
 * @author  Jörn Gorres
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin-UI: Settings-Seite unter "Settings" mit der Block- und
 * Embed-Varianten-Auswahl, Formularverarbeitung und Asset-Einbindung.
 */
final class JG_GBBF_Admin_Page {

	/**
	 * Slug der Settings-Seite (Query-Parameter ?page=...).
	 *
	 * Seit 1.6.0 englisch; vorher 'jg-gbbf-bloecke-ausblenden'. Alte
	 * Lesezeichen auf die Settings-Seite laufen deshalb ins Leere.
	 */
	public const MENU_SLUG = 'jg-gbbf-hide-blocks';

	/**
	 * Nonce-Action-Name.
	 */
	public const NONCE_ACTION = 'jg_gbbf_save_blocks';

	/**
	 * `action`-Wert fuer admin-post.php.
	 */
	public const FORM_ACTION = 'jg_gbbf_save';

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
			__( 'Hide Blocks', 'gb-block-filter' ),
			__( 'Hide Blocks', 'gb-block-filter' ),
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
			'jg-gbbf-admin',
			JG_GBBF_PLUGIN_URL . 'assets/admin.css',
			array(),
			JG_GBBF_VERSION
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
		 * Block-Assets registrieren. Ein eigener jg_gbbf_*-Hook waere
		 * funktional unsinnig, weil sich keine externen Plugins
		 * daran anhaengen wuerden. Core selbst feuert den Hook ebenfalls
		 * ausserhalb des Post-Editors (Widgets-Screen, Site-Editor).
		 * Das plugineigene editor.js erkennt diese Seite ueber
		 * get_current_screen() und laedt hier nicht (siehe
		 * JG_GBBF_Block_Filter::enqueue_editor_assets()).
		 */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core-Hook bewusst: andere Plugins/Themes sollen ihre Block-Assets registrieren.
		do_action( 'enqueue_block_editor_assets' );

		wp_enqueue_script(
			'jg-gbbf-admin',
			JG_GBBF_PLUGIN_URL . 'assets/admin.js',
			array( 'wp-blocks', 'wp-block-library', 'wp-block-editor', 'wp-element', 'wp-dom-ready' ),
			JG_GBBF_VERSION,
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
				esc_html__( 'You do not have permission to do this.', 'gb-block-filter' ),
				esc_html__( 'You do not have permission to do this.', 'gb-block-filter' ),
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
			if ( ! JG_GBBF_Plugin::is_valid_block_name( $name ) ) {
				continue;
			}
			if ( in_array( $name, JG_GBBF_Plugin::get_protected_blocks(), true ) ) {
				continue;
			}
			if ( ! in_array( $name, $valid_keys, true ) ) {
				continue;
			}
			$sanitized_blocks[] = $name;
		}
		$sanitized_blocks = array_values( array_unique( $sanitized_blocks ) );

		update_option( JG_GBBF_Plugin::OPTION_NAME, $sanitized_blocks );

		// ----- 2) Embed-Varianten -----
		// Sanitization analog zum Block-Array oben; Validierung gegen die
		// Whitelist bekannter Embed-Varianten erfolgt anschliessend.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wurde oben via check_admin_referer geprueft.
		$submitted_vars = isset( $_POST['hidden_embed'] ) && is_array( $_POST['hidden_embed'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['hidden_embed'] ) )
			: array();

		$known_variations     = array_keys( JG_GBBF_Plugin::get_embed_variations() );
		$sanitized_variations = array();
		foreach ( $submitted_vars as $slug ) {
			if ( ! is_string( $slug ) ) {
				continue;
			}
			if ( ! JG_GBBF_Plugin::is_valid_variation_name( $slug ) ) {
				continue;
			}
			if ( ! in_array( $slug, $known_variations, true ) ) {
				continue;
			}
			$sanitized_variations[] = $slug;
		}
		$sanitized_variations = array_values( array_unique( $sanitized_variations ) );

		update_option( JG_GBBF_Plugin::OPTION_EMBED_VARIATIONS, $sanitized_variations );

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
				esc_html__( 'You do not have permission to do this.', 'gb-block-filter' ),
				esc_html__( 'You do not have permission to do this.', 'gb-block-filter' ),
				array( 'response' => 403 )
			);
		}

		$registered = WP_Block_Type_Registry::get_instance()->get_all_registered();
		$hidden     = JG_GBBF_Plugin::get_hidden_blocks();
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
				'protected'    => in_array( $name_str, JG_GBBF_Plugin::get_protected_blocks(), true ),
				'pro_replaced' => in_array( $name_str, JG_GBBF_Plugin::get_pro_replaced_blocks(), true ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur Anzeige des Erfolgshinweises, keine Datenmutation.
		$updated          = isset( $_GET['updated'] );
		$total_count      = count( $registered );
		$hidden_count     = count( $hidden );
		$hidden_var_count = count( JG_GBBF_Plugin::get_hidden_embed_variations() );

		?>
		<div class="wrap jg-gbbf-admin">
			<h1><?php esc_html_e( 'Hide Blocks', 'gb-block-filter' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'gb-block-filter' ); ?></p>
				</div>
			<?php endif; ?>

			<p class="jg-gbbf-intro">
				<?php
				printf(
					/* translators: 1: number of registered blocks, 2: number of hidden blocks, 3: number of hidden embed variations. */
					esc_html__( 'Registered blocks: %1$d. Currently hidden: %2$d blocks and %3$d embed variations.', 'gb-block-filter' ),
					(int) $total_count,
					(int) $hidden_count,
					(int) $hidden_var_count
				);
				?>
			</p>

			<p class="description">
				<?php esc_html_e( 'Checked blocks are removed from the block inserter for all users. Existing content using hidden blocks remains visible – only inserting new instances is blocked.', 'gb-block-filter' ); ?>
				<br>
				<?php esc_html_e( 'Certain core blocks (Paragraph, Heading, List, Image, Group, Columns) always stay available as a safety net and cannot be hidden here.', 'gb-block-filter' ); ?>
			</p>

			<div class="jg-gbbf-toolbar">
				<label class="jg-gbbf-search-label" for="jg-gbbf-search">
					<span class="screen-reader-text"><?php esc_html_e( 'Search blocks', 'gb-block-filter' ); ?></span>
					<input
						type="search"
						id="jg-gbbf-search"
						class="jg-gbbf-search"
						placeholder="<?php esc_attr_e( 'Search blocks…', 'gb-block-filter' ); ?>"
						autocomplete="off"
					>
				</label>

				<fieldset class="jg-gbbf-view-switch">
					<legend class="screen-reader-text"><?php esc_html_e( 'Choose grouping', 'gb-block-filter' ); ?></legend>
					<label>
						<input type="radio" name="jg_gbbf_view" value="category" checked>
						<?php esc_html_e( 'By category', 'gb-block-filter' ); ?>
					</label>
					<label>
						<input type="radio" name="jg_gbbf_view" value="namespace">
						<?php esc_html_e( 'By namespace', 'gb-block-filter' ); ?>
					</label>
				</fieldset>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="jg-gbbf-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::FORM_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>

				<div class="jg-gbbf-view jg-gbbf-view--category" data-view="category">
					<?php foreach ( $cat_slugs as $cat_slug ) : ?>
						<?php
						$blocks_for_cat = $by_category[ $cat_slug ];
						$cat_label      = $cat_map[ $cat_slug ] ?? $cat_slug;
						$this->render_group( 'cat-' . $cat_slug, $cat_label, $cat_slug, $blocks_for_cat, $hidden_map );
						?>
					<?php endforeach; ?>
				</div>

				<div class="jg-gbbf-view jg-gbbf-view--namespace" data-view="namespace" hidden>
					<?php foreach ( $namespaces as $ns ) : ?>
						<?php
						// Label = roher Namespace; das Pro-Badge in der
						// Gruppen-Legende kennzeichnet den Pro-Block-Pool.
						$this->render_group( 'ns-' . $ns, $ns, $ns, $by_namespace[ $ns ], $hidden_map );
						?>
					<?php endforeach; ?>
				</div>

				<?php $this->render_embed_variations_section(); ?>

				<p class="jg-gbbf-no-results" hidden>
					<em><?php esc_html_e( 'No blocks found.', 'gb-block-filter' ); ?></em>
				</p>

				<?php submit_button( __( 'Save Settings', 'gb-block-filter' ) ); ?>
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

		$list_id  = 'jg-gbbf-list-' . sanitize_html_class( $group_slug );
		$is_pro_g = ( 'generateblocks-pro' === $group_raw );
		?>
		<fieldset class="jg-gbbf-group" data-group="<?php echo esc_attr( $group_slug ); ?>">
			<legend class="jg-gbbf-group__legend">
				<span class="jg-gbbf-group__title"><?php echo esc_html( $group_label ); ?></span>
				<span class="jg-gbbf-group__count"><?php echo (int) count( $blocks ); ?></span>
				<?php if ( $is_pro_g ) : ?>
					<span class="jg-gbbf-badge jg-gbbf-badge--pro">Pro</span>
				<?php endif; ?>
				<button
					type="button"
					class="button button-small jg-gbbf-toggle-all"
					data-target="<?php echo esc_attr( $list_id ); ?>"
				><?php esc_html_e( 'Toggle all', 'gb-block-filter' ); ?></button>
			</legend>
			<ul class="jg-gbbf-list" id="<?php echo esc_attr( $list_id ); ?>">
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
					 *                     JG_GBBF_Block_Filter::filter_blocks).
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
					$item_class     = 'jg-gbbf-item'
						. ( $disabled ? ' jg-gbbf-item--protected' : '' )
						. ( $pro_replaced ? ' jg-gbbf-item--pro-replaced' : '' );
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
							<span class="jg-gbbf-item__icon" data-block-name="<?php echo esc_attr( $entry['name'] ); ?>" aria-hidden="true"></span>
							<span class="jg-gbbf-item__title"><?php echo esc_html( $entry['title'] ); ?></span>
							<code class="jg-gbbf-item__name"><?php echo esc_html( $entry['name'] ); ?></code>
							<?php if ( $pro_replaced ) : ?>
								<span
									class="jg-gbbf-badge jg-gbbf-badge--pro-replacement"
									title="<?php esc_attr_e( 'GenerateBlocks Pro is active. This core block is hidden from the inserter automatically because GenerateBlocks Pro provides an extended variant. If GenerateBlocks Pro is deactivated, the core block reappears automatically.', 'gb-block-filter' ); ?>"
								><?php esc_html_e( 'Pro variant active', 'gb-block-filter' ); ?></span>
							<?php elseif ( $disabled ) : ?>
								<span class="jg-gbbf-badge jg-gbbf-badge--protected"><?php esc_html_e( 'protected', 'gb-block-filter' ); ?></span>
							<?php endif; ?>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="jg-gbbf-group__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'gb-block-filter' ); ?></button>
			</div>
		</fieldset>
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
		$variations = JG_GBBF_Plugin::get_embed_variations();
		if ( empty( $variations ) ) {
			return;
		}

		$hidden_variations = JG_GBBF_Plugin::get_hidden_embed_variations();
		$hidden_map        = array_fill_keys( $hidden_variations, true );

		// Alphabetisch nach Anzeigename sortieren.
		asort( $variations, SORT_NATURAL | SORT_FLAG_CASE );

		$list_id = 'jg-gbbf-list-embed-variations';
		?>
		<fieldset class="jg-gbbf-group jg-gbbf-group--variations" data-group="embed-variations">
			<legend class="jg-gbbf-group__legend">
				<span class="jg-gbbf-group__title"><?php esc_html_e( 'Embed variations', 'gb-block-filter' ); ?></span>
				<span class="jg-gbbf-group__count"><?php echo (int) count( $variations ); ?></span>
				<button
					type="button"
					class="button button-small jg-gbbf-toggle-all"
					data-target="<?php echo esc_attr( $list_id ); ?>"
				><?php esc_html_e( 'Toggle all', 'gb-block-filter' ); ?></button>
			</legend>
			<p class="description jg-gbbf-group__hint">
				<?php esc_html_e( 'Variations of the "Embed" block (YouTube, Reddit, Vimeo, …). Checked variations are removed in the editor; the base "Embed" block remains available.', 'gb-block-filter' ); ?>
			</p>
			<ul class="jg-gbbf-list" id="<?php echo esc_attr( $list_id ); ?>">
				<?php foreach ( $variations as $slug => $label ) : ?>
					<?php $is_hidden = isset( $hidden_map[ $slug ] ); ?>
					<li class="jg-gbbf-item jg-gbbf-item--variation"
						data-name="<?php echo esc_attr( $slug ); ?>"
						data-title="<?php echo esc_attr( $label ); ?>">
						<label>
							<input
								type="checkbox"
								name="hidden_embed[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $is_hidden ); ?>
							>
							<span class="jg-gbbf-item__icon" data-variation-name="<?php echo esc_attr( $slug ); ?>" aria-hidden="true"></span>
							<span class="jg-gbbf-item__title"><?php echo esc_html( $label ); ?></span>
							<code class="jg-gbbf-item__name"><?php echo esc_html( $slug ); ?></code>
						</label>
					</li>
				<?php endforeach; ?>
			</ul>
			<div class="jg-gbbf-group__actions">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'gb-block-filter' ); ?></button>
			</div>
		</fieldset>
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
			$map['uncategorized'] = __( 'Uncategorized', 'gb-block-filter' );
		}

		return $map;
	}
}
