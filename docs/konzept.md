# Block Inserter Filter – Konzept und Aufbau

Version: 1.0 · Stand: 03.10.2026 · Plugin-Version: 2.1.5

## 1. Zweck

Das Plugin blendet einzelne Gutenberg-Blöcke und Embed-Varianten site-weit
aus dem Block-Inserter aus. Administratoren wählen die Blöcke auf einer
Einstellungsseite, optional mit Einbindung von GenerateBlocks. Es ist seit
2.1.4 im WordPress-Verzeichnis veröffentlicht
(`https://wordpress.org/plugins/block-inserter-filter/`).

## 2. Verzeichnisse

Das Projekt liegt unter `~/dev/jgorres-im-WP-Repository/block-inserter-filter/`
nach dem Schema der `README.md` in `~/dev/jgorres-im-WP-Repository/`.

```
block-inserter-filter/
├── plugin/                    ausgeliefertes Plugin, Ziel der Symlinks lokaler Sites
│   ├── block-inserter-filter.php   Header, Konstanten, Klassen einbinden
│   ├── readme.txt             für das WordPress-Verzeichnis
│   ├── uninstall.php
│   ├── includes/              class-jgor-bif-plugin, -block-filter, -admin-page
│   ├── assets/                admin.css, admin.js, editor.js
│   └── languages/             block-inserter-filter.pot (nur die Vorlage im Paket);
│                              de_DE und de_DE_formal als .po/.mo/.l10n.php nur im Repo
├── assets/                    WP.org-Assets: icon-128x128, icon-256x256 (APNG),
│                              banner-772x250, banner-1544x500
├── docs/                      diese Doku, icon-erzeugen.py (erzeugt assets/)
├── glotpress/                 .po-Stände von translate.wordpress.org (du und Sie,
│                              Plugin und Readme)
├── svn/                       SVN-Checkout trunk/, tags/, assets/ (nicht im Git)
├── dist/                      Release-ZIPs block-inserter-filter-<version>.zip (nicht im Git)
├── build.sh                   prüft die Versionen, schreibt dist/<slug>-<version>.zip
├── .distignore                Ausschlüsse aus plugin/ für ZIP und SVN-Export
├── LICENSE                    GPL-2.0-Text
├── composer.json, phpcs.xml.dist, phpstan.neon.dist, phpstan-bootstrap.php, stubs/
```

Git-Remote `origin`: `https://github.com/jgorres/block-inserter-filter-plugin`
(öffentlich, Branch `main`); diese Adresse steht als `Plugin URI` im Header.
Push von Hand nach jedem Commit.

Namensregeln: Slug und Text-Domain `block-inserter-filter`, Funktionen und
Optionen `jgor_bif_`, Klassen und Konstanten `JGOR_BIF_`, Handles `jgor-bif-`.

## 3. Werkzeuge

```bash
composer install       # einmalig
composer check         # PHPCS (WPCS) und PHPStan Level 6
wp i18n make-pot plugin plugin/languages/block-inserter-filter.pot \
  --domain=block-inserter-filter --slug=block-inserter-filter
./build.sh             # Release-ZIP nach dist/
```

Plugin Check auf dem entpackten ZIP:
`wp plugin check <entpackt> --path=/var/www/local-sites/plugintest.local`.

## 4. Release

1. Version in Header, `@version`, Konstante, `readme.txt` (Stable tag,
   Changelog, Upgrade Notice), `phpstan-bootstrap.php`; `.pot` neu erzeugen.
2. `composer check`, `./build.sh`, Plugin Check.
3. `plugin/` nach `svn/trunk/` spiegeln (ohne die Einträge aus `.distignore`),
   `svn cp trunk tags/<version>`, `svn ci`.
4. Übersetzungen über translate.wordpress.org, beide Anreden, Skill
   `glotpress-de`; Exporte nach `glotpress/`.

## 5. Entschiedene Fragen

| Frage | Entscheidung | Grund |
| --- | --- | --- |
| Übersetzungen im Paket | nein, nur `.pot` | Vorgabe des Plugin-Reviews vom 16.09.2026 |
| `Tested up to` | nur in `readme.txt` | die Einreichung lehnt die Zeile im Header ab |
| `Author URI` | entfällt | Vorgabe des Plugin-Reviews (2.1.4) |
| `Plugin URI` | GitHub-Repo | WordPress.org verlangt eine eigene Seite, keine WordPress.org-Adresse |

## Änderungen

| Version | Datum | Änderung |
| --- | --- | --- |
| 1.0 | 03.10.2026 | Erste Fassung beim Umbau auf das Ordnerschema (Plugin 2.1.5: `Plugin URI`, Dateien nach `plugin/`, Nebenordner `-assets`, `-glotpress`, `-SVN` eingezogen, `build.sh`, `LICENSE`) |
