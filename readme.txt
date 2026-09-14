=== Block-Filter für GenerateBlocks ===
Contributors: jgorres
Tags: gutenberg, blocks, generateblocks, block-editor, admin
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.5.2
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Hide individual Gutenberg blocks site-wide from the block inserter.

== Description ==

This plugin lets administrators globally hide individual Gutenberg blocks (Core, GenerateBlocks Free, GenerateBlocks Pro, theme blocks and other plugin blocks) from the block inserter — without writing any code.

= Features =

* Clear admin screen under "Settings → Hide blocks".
* Group blocks either by block category (as in the editor) or by namespace.
* GenerateBlocks Free and GenerateBlocks Pro are listed in separate groups.
* Embed variations (YouTube, Reddit, Vimeo, TikTok, Bluesky, …) can be hidden individually.
* Live search across all blocks and embed variations.
* "Toggle all" per group.
* Automatic detection of GenerateBlocks Pro blocks, marked with a Pro badge.
* Protection list: core blocks (paragraph, heading, list, image, group, columns) are always available as a safety net and cannot be hidden.
* Existing content using hidden blocks remains visible — only the insertion of new instances is blocked.
* No external dependencies, no tracking, no front-end database load.

= Security =

Defense in depth: capability check (`manage_options`), nonce validation, whitelist sanitization on save, consistent output escaping.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/` or install the ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin under Plugins.
3. Configure the plugin under Settings → Hide blocks.

== Frequently Asked Questions ==

= Will existing posts that use hidden blocks be destroyed? =

No. Content remains intact and renders normally on the front-end. Only inserting new instances from the block inserter is blocked.

= Why can't I hide "Paragraph" or "Image"? =

These blocks are hard-protected as a safety net — without them the editor would be practically unusable. If you really need to remove them, use the `allowed_block_types_all` filter in your theme or in a custom plugin.

== Changelog ==

= 1.5.2 =
* Fix: when another plugin sets `allowed_block_types_all` to `false` (no blocks allowed at all), that restriction is now passed through unchanged instead of being replaced by the full block registry minus the hidden blocks.
* Removed the `all_plugins` display-name override on `wp-admin/plugins.php`. The plugin is now listed under its real name "Block-Filter für GenerateBlocks" everywhere.
* Fix: the plugin's own editor script no longer runs on the plugin's settings page, so icons of hidden embed variations render correctly there.
* Uninstall: the catch-all `LIKE 'gbba_%'` cleanup was removed; the two known legacy option keys are still deleted by name. The generic four-letter prefix could have matched options of other plugins.
* Multisite: `get_sites()` calls now pass `number => 0`, so networks with more than 100 sites are fully migrated and cleaned up.
* Declared compatibility with WordPress 7.1 (`Tested up to`).

= 1.5.1 =
* Declared compatibility with WordPress 7.0 (`Tested up to`). No code changes.

= 1.5.0 =
* Refactor: prefix scheme corrected to `jg_gbbf_` / `JG_GBBF_` / `.jg-gbbf-*`. The previous code prefix was `gbba_` / `GBBA_` (from the original plugin slug `generateblocks-bloecke-ausblenden`), which no longer matched the current slug `gb-block-filter`. All globals — constants, classes, top-level functions, options, asset handles, CSS classes, body classes — are renamed accordingly.
* Class files in `includes/` renamed from `class-gbba-*.php` to `class-jg-gbbf-*.php`.
* Migration: one-shot routine on `admin_init` copies the two existing options `gbba_hidden_blocks` and `gbba_hidden_embed_variations` to `jg_gbbf_hidden_blocks` and `jg_gbbf_hidden_embed_variations` (only when the new key is still empty, to preserve any value written between the update and the first admin page load). Legacy keys are deleted afterwards. Multisite installations get an additional network sweep from the main site so subsites are migrated even if no admin visits them.
* Uninstall: `uninstall.php` now removes both prefix families (`gbba_*` and `jg_gbbf_*`) plus the network-wide sweep sentinel, so no leftover rows remain even if the plugin is deleted right after the update.

= 1.4.1 =
* Plugin Check compliance fix: shortened the 1.4.0 Upgrade Notice text to stay under the `upgrade_notice_limit` of 300 characters. No code or behavioural changes.

= 1.4.0 =
* **Rename:** plugin slug, folder name, main file and Text Domain changed to `gb-block-filter`; the displayed Plugin Name is now "Block-Filter für GenerateBlocks". Existing settings (hidden blocks, hidden embed variations) are preserved because the option names (`gbba_*` prefix) remain unchanged. WordPress may auto-deactivate the plugin during the slug change — reactivate it manually under Plugins.
* The plugin overview screen (`wp-admin/plugins.php`) showed the plugin as "GenerateBlocks – Block-Filter (Zusatz)" via a scoped `all_plugins` filter, so it sorted directly under "GenerateBlocks". (Removed again in 1.5.2.)
* `uninstall.php`: now also removes the second option `gbba_hidden_embed_variations` (added in v1.1.0 but previously not cleaned up); adds a defensive `LIKE 'gbba_%'` cleanup that covers any future plugin options and `_transient_gbba_*` / `_transient_timeout_gbba_*` entries. Multisite loop preserved.
* `readme.txt`: Installation, FAQ and the older Changelog entries (v1.0.0 – v1.3.6) translated to English. Non-standard header fields (`Author`, `Author URI`, `Plugin URI`) removed from the readme header block — they belong in the PHP plugin header only. `Contributors` slug switched to the WordPress.org user name `jgorres`. New `== Upgrade Notice ==` section added.
* `Plugin URI` removed from the main PHP file header (no dedicated plugin landing page).
* `languages/index.php`, `assets/index.php`, `includes/index.php` and the root `index.php` now use a proper `defined( 'ABSPATH' ) || exit;` guard instead of the bare "Silence is golden" stub.

= 1.3.10 =
* Behavior change for Core blocks that GenerateBlocks Pro replaces (currently `core/heading` and `core/image`): they are now **automatically hidden** from the block inserter as soon as GenerateBlocks Pro is active. The earlier 1.3.9 behavior (Pro active = user may opt in to hiding) was reversed because the Pro variants are meant to fully replace the Core blocks while Pro is loaded. The block-inserter filter (`GBBA_Block_Filter::filter_blocks`) now merges the user-configured hide list with the auto-hidden list returned by `GBBA_Plugin::get_pro_replaced_blocks()` and applies both in one pass. The settings page shows the affected entries as checked + disabled, with the new "Pro-Variante aktiv" badge and a tooltip explaining the auto-hide. As soon as GenerateBlocks Pro is deactivated, the blocks fall back to the regular "geschützt" badge and reappear in the inserter — no manual cleanup needed.
* Internal: `get_protected_blocks()` reverted to a thin wrapper around the `PROTECTED_BLOCKS` constant (always full list). The Pro-aware logic now lives exclusively in `get_pro_replaced_blocks()`, which is consumed both by the inserter filter and by the admin renderer.

= 1.3.9 =
* Feature: when GenerateBlocks Pro is active, the Core blocks `core/heading` and `core/image` are no longer in the protection list and can be hidden from the inserter. They are shown with a new "GB Pro Variante" badge (instead of "geschützt"), with a hover tooltip explaining that GenerateBlocks Pro provides an extended variant of the block. If GenerateBlocks Pro is not active, both blocks remain protected and keep the "geschützt" badge as before, so the editor stays safe to use on installations without the Pro plugin.
* Internal: replaced direct uses of `GBBA_Plugin::PROTECTED_BLOCKS` with the new helper `GBBA_Plugin::get_protected_blocks()`, which dynamically removes the Pro-replaced entries from the protection list. `GBBA_Plugin::is_generateblocks_pro_active()` exposes the detection (uses the `GENERATEBLOCKS_PRO_VERSION` constant defined by GB Pro itself, no extra plugin-list lookup).

= 1.3.8 =
* Critical fix: every block and embed variation is rendered twice in the settings page DOM — once in the category view and once in the namespace view. Both share `name="hidden[]"` (respectively `name="hidden_embed[]"`), and the HTML5 `hidden` attribute hides the inactive view visually but does **not** exclude its inputs from form submission. Toggling checkboxes in only one view therefore caused the other view to silently re-send the old state, which made the "Hide all" / "Show all" toggle look broken: after saving, all checkboxes appeared checked again, even though they had just been unchecked. The admin script now mirrors every checkbox change (manual click or "Toggle all" button) onto the twin checkbox with the same `name`/`value` in the opposite view, so both views stay in sync and only one state is submitted. Existing data is safe; users who experienced wrongly-hidden blocks should open the settings page once after the update, click "Toggle all" until the affected groups are emptied, save, and reload the editor.

= 1.3.7 =
* UX: Legacy / render-only blocks are no longer listed in the admin screen. Some plugins (for example GenerateBlocks 2.x for its `generateblocks/container`) keep an old block registered as a pure server-side renderer so that existing posts continue to render after a refactor. Such blocks never appear in the inserter and therefore cannot be hidden in any meaningful way; previously they were listed anyway, leading to duplicate entries (for example two "Container" rows side by side — one being the new Element block, the other the legacy Container renderer). Detection heuristic: empty `attributes` schema combined with no declared block category. Pro blocks are unaffected, because they are consistently registered via `block.json` and therefore always carry both attributes and a category.

= 1.3.6 =
* Follow-up fix to 1.3.5: `array_map( 'sanitize_text_field', wp_unslash( $_POST[...] ) )` is now placed directly in the same expression as the `$_POST` access — WPCS evaluates the `InputNotSanitized` sniff line-by-line, so a sanitization step on the following line was not picked up.

= 1.3.5 =
* Plugin Check compliance:
  * Removed the superfluous `load_plugin_textdomain()` call in `class-gbba-plugin.php` — WordPress loads translations automatically via the `Domain Path` header since 4.6.
  * `$_POST['hidden']` and `$_POST['hidden_embed']` are now additionally filtered through `array_map( 'sanitize_text_field', … )` before the whitelist validation (fixes the `InputNotSanitized` warning).
  * Marked the `do_action( 'enqueue_block_editor_assets' )` call with a clear `phpcs:ignore` plus explanation — the Core hook is fired deliberately so that other plugins/themes register their block assets.
  * `uninstall.php`: renamed the local multisite loop variables `$sites` / `$site` to `$gbba_sites` / `$gbba_site` (fixes the `NonPrefixedVariableFound` warning).
  * `readme.txt`: translated the Short Description and the `== Description ==` block to English; switched the `Contributors` entry to a valid WordPress.org format.

= 1.3.4 =
* UX: "Settings" link in the WordPress plugins list — direct jump from Plugins to the plugin configuration page.

= 1.3.3 =
* UX: tighter column grid — minimum column width 280 px (was 340 px). On 1920 px monitors this yields roughly 6 columns, on 1440 px monitors 4 columns.

= 1.3.2 =
* UX: in the category view, the block namespace (e.g. `core/paragraph`) is no longer shown as a slug tag. It still appears in the namespace view and on embed variations.

= 1.3.1 =
* Fix: block icons are now reliably rendered. On the settings page, WordPress core blocks are now explicitly registered client-side via `wp.blockLibrary.registerCoreBlocks()`; previously they were loosely loaded but not present in the block-type registry.
* Diagnostic hint in the browser console when block icons cannot be resolved.

= 1.3.0 =
* Block icons are now displayed between the checkbox and the block name on the settings page — also for embed variations. Rendering via `wp.blockEditor.BlockIcon`. The block-editor assets are loaded on the settings page for this purpose.

= 1.2.1 =
* Fix: blocks without a declared category (for example GenerateBlocks inner blocks such as `loop-item`, `query-page-numbers`, `query-no-results`) no longer end up under "Uncategorized" but in the group of their own namespace.

= 1.2.0 =
* UX: "Save" button at the end of every group — no more long scrolling needed.
* UX: Pro badge only once in the group heading; redundant per-block Pro badges and the intro badge removed.
* Statistics: the number of hidden embed variations is now counted and shown in the intro.

= 1.1.0 =
* GenerateBlocks Free and GenerateBlocks Pro are shown in separate groups (previously combined).
* New section: embed variations (YouTube, Reddit, Vimeo, TikTok, Bluesky, …) can now be removed individually from the editor. Technically via `wp.blocks.unregisterBlockVariation()`.
* Filter `gbba_embed_variations` for extending the variation list.
* The live search now also covers embed variations.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.5.2 =
Fix: respects a `false` value of `allowed_block_types_all` set by other plugins. The renamed entry on the plugins screen is gone, the plugin now appears under its real name. Declares WordPress 7.1 compatibility.

= 1.5.1 =
Maintenance release: declares WordPress 7.0 compatibility. No functional changes.

= 1.5.0 =
Internal refactor: prefix scheme corrected from `gbba_` (legacy, from old plugin slug `generateblocks-bloecke-ausblenden`) to `jg_gbbf_`. A one-shot migration copies your existing hidden-block settings to the new option keys, no data loss. No user-visible changes.

= 1.4.1 =
Plugin Check fix: shortened the 1.4.0 Upgrade Notice block to satisfy the `upgrade_notice_limit` sniff (max 300 characters). No functional changes.

= 1.4.0 =
Slug renamed to `gb-block-filter`. Existing settings preserved. The plugins overview shows the plugin as `GenerateBlocks – Block-Filter (Zusatz)`, sorting it next to GenerateBlocks. WordPress may auto-deactivate during the rename — reactivate manually under Plugins.

= 1.3.10 =
Auto-hides `core/heading` and `core/image` from the block inserter as soon as GenerateBlocks Pro is active, because the Pro variants fully replace those Core blocks while Pro is loaded.

= 1.3.0 =
Block icons are now displayed in the settings page next to each block name. No data migration required.

= 1.1.0 =
Adds an "Embed variations" section so individual embed providers (YouTube, Reddit, Vimeo, …) can be removed from the editor. No data migration required.

= 1.0.0 =
Initial release.
