#!/usr/bin/env python3
"""
Erzeugt die WordPress.org-Assets für Block Inserter Filter:
animierte Icons (APNG) und statische Banner, alle im selben Stil
(Raster aus Gutenberg-Block-Icons, Dunkelgrau auf Weiß).

Autor: Jörn Gorres
Datum: 14.09.2026

Ablauf:
  1. Block-Icons aus wp-includes/js/dist/block-library.js einer lokalen
     WordPress-Installation extrahieren (Icons, die per `icon: xxx_default`
     bei registerBlockType verwendet werden, plus FEATURED-Sonderfälle).
  2. Jedes Icon als SVG schreiben und mit Inkscape in PNG rendern.
  3. Icons:   8x8-Raster (256 px: Zellen 32 px, 128 px: Zellen 16 px); je Frame ein Icon mehr
     (Aufbau läuft einmal durch). Danach wischt eine Diagonale von oben
     rechts nach unten links über das Bild; der gewischte Bereich wird
     weichgezeichnet. Dann wischt die Diagonale zurück, dabei verschwinden
     die unscharfen Icons und nur die wichtigsten Blöcke (FEATURED) bleiben
     scharf stehen. Standbild am Ende 5 s, dann Schleife. Ausgabe als APNG.
  4. Banner:  statisches PNG 1544x500 (und daraus 772x250): Icon-Raster in
     Hellgrau als Muster, FEATURED-Icons dunkel und scharf verteilt, links
     Textblock mit Plugin-Name und Untertitel.

Aufruf:
  python3 icon-erzeugen.py [/var/www/local-sites/<domain>]
"""

import pathlib
import random
import re
import subprocess
import sys
import tempfile

from PIL import Image, ImageDraw, ImageFilter, ImageFont

# ── Parameter ──────────────────────────────────────────────────────────
SITE = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "/var/www/local-sites/gb-blockfilter.local")
BUNDLE = SITE / "wp-includes/js/dist/block-library.js"
OUT_DIR = pathlib.Path(__file__).resolve().parent.parent / "assets"  # WP.org-Assets im Repo

BG = (255, 255, 255, 255)
COLOR = "#1d2327"   # WordPress-Dunkelgrau
FONT_BOLD = "/usr/share/fonts/truetype/noto/NotoSans-Bold.ttf"
FONT_REGULAR = "/usr/share/fonts/truetype/noto/NotoSans-Regular.ttf"

# Animation (beide Icon-Größen)
FRAME_MS = 50       # Dauer je Aufbau-Frame
HOLD_FULL_MS = 600  # Standbild mit vollem Raster vor dem Wischen
WIPE_FRAMES = 24    # Anzahl Frames je Wischbewegung
WIPE_MS = 40        # Dauer je Wisch-Frame
WIPE_EDGE = 32      # weiche Kante der Wischgrenze in px (bei 256 px)
BLUR_RADIUS = 4     # Gauß-Radius für den gewischten Bereich
HOLD_BLUR_MS = 600  # Standbild voll weichgezeichnet vor dem Rückwischen
HOLD_END_MS = 5000  # Standbild am Ende (nur FEATURED-Icons), danach Schleife
LOOP = 0            # 0 = Endlosschleife, 1 = einmal abspielen und auf dem Endbild stehen bleiben
SHARP_SEED = 7      # fester Zufalls-Seed für die Positionen der FEATURED-Icons

# Icons, die nach dem Rückwischen scharf stehen bleiben (Variablennamen
# aus block-library.js). Wert = erlaubter Bereich für die Position:
#   None             → irgendwo im Raster
#   (r0, r1, c0, c1) → nur in diesem Zeilen-/Spaltenbereich (inklusive)
# Absatz, Überschrift, Liste, Tabelle, Bild, Accordion, Video,
# Individuelles HTML, Einbettung frei; Gruppe, Spalten, Zitat, Code im
# oberen rechten Quadranten, damit der nicht leer wirkt.
FEATURED_256 = {
    "paragraph_default": None, "heading_default": None, "list_default": None,
    "table_default": None, "image_default": None, "accordion_default": None,
    "video_default": None, "html_default": None, "embedContentIcon": None,
    "group_default": (0, 3, 4, 7), "columns_default": (0, 3, 4, 7),
    "quote_default": (0, 3, 4, 7), "code_default": (0, 3, 4, 7),
}
# 128 px: gleiches 8x8-Raster und gleiche Belegung wie 256 px, halb so groß.
FEATURED_128 = FEATURED_256

# Banner
BANNER_W, BANNER_H = 1544, 500
BANNER_CELL = 64          # Rasterzelle im Banner
BANNER_ICON = 36          # Icon-Größe im Banner
PATTERN_ALPHA = 0.16      # Deckkraft der Muster-Icons (Hellgrau-Wirkung)
BANNER_TEXT = "Block Inserter Filter"
BANNER_SUB = "Hide blocks from the inserter, site-wide."
BANNER_TEXT_X = 96        # linker Rand des Textblocks
BANNER_TITLE_SIZE = 80
BANNER_SUB_SIZE = 34
BANNER_TEXT_COLOR = "#ff9c00"  # Schriftfarbe Titel und Untertitel (Orange)
BANNER_BORDER = 2              # dünner schwarzer Rand in px (bei 1544 px Breite)
BANNER_BORDER_COLOR = "#000000"
BANNER_FEATURED = list(FEATURED_256)  # dieselben 13 Icons, dunkel im Muster
BANNER_SEED = 11

# Icons, die nicht als Block-Symbol taugen (leer, zu generisch), werden übersprungen.
SKIP = {"block_default", "icon", "icon_default"}


# ── Icons aus dem Bundle ───────────────────────────────────────────────

def extract_icons(js: str):
    """Liefert eine geordnete Liste (name, (viewBox, path_markup)) der Block-Icons."""
    used = list(dict.fromkeys(list(FEATURED_256) + list(FEATURED_128)))
    for m in re.finditer(r"icon:\s*([a-z0-9_]+_default)\b", js):
        name = m.group(1)
        if name not in used and name not in SKIP:
            used.append(name)

    defs = {}
    for name in used:
        m = re.search(r"var " + re.escape(name) + r" = /\* @__PURE__ \*/ \(0, import_jsx_runtime\d+\.jsxs?\)\(import_\w+\.SVG, \{(.*?)\}\);\n", js, re.S)
        if not m:
            continue
        body = m.group(1)
        vb = re.search(r'viewBox:\s*"([^"]+)"', body)
        paths = re.findall(r'import_\w+\.Path,\s*\{([^}]*)\}', body)
        if not vb or not paths:
            continue
        elems = []
        for p in paths:
            d = re.search(r'd:\s*"([^"]+)"', p)
            if not d:
                continue
            attrs = 'd="%s"' % d.group(1)
            fr = re.search(r'fillRule:\s*"([^"]+)"', p)
            cr = re.search(r'clipRule:\s*"([^"]+)"', p)
            if fr:
                attrs += ' fill-rule="%s"' % fr.group(1)
            if cr:
                attrs += ' clip-rule="%s"' % cr.group(1)
            elems.append("<path %s/>" % attrs)
        if not elems:
            continue
        defs[name] = (vb.group(1), "".join(elems))
    return [(n, defs[n]) for n in used if n in defs]


def render_tiles(icons, size: int, workdir: pathlib.Path):
    """Rendert alle Icons in der gewünschten Pixelgröße, liefert name → RGBA-Bild."""
    tiles = {}
    for name, (vb, elems) in icons:
        svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="%s" width="%d" height="%d" fill="%s">%s</svg>' % (
            vb, size, size, COLOR, elems)
        svg_file = workdir / ("%s-%d.svg" % (name, size))
        png_file = workdir / ("%s-%d.png" % (name, size))
        svg_file.write_text(svg)
        subprocess.run(
            ["inkscape", str(svg_file), "--export-type=png", "--export-filename=" + str(png_file),
             "--export-width=%d" % size, "--export-height=%d" % size, "--export-background-opacity=0"],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
        tiles[name] = Image.open(png_file).convert("RGBA")
    return tiles


# ── Rasterbelegung ─────────────────────────────────────────────────────

def place(grid: int, featured: dict, pool: list, seed: int):
    """Belegt ein grid x grid-Raster: FEATURED an Zufallspositionen (Seed),
    Bereichsvorgaben beachtet, Rest zyklisch aus dem Pool."""
    need = grid * grid
    rng = random.Random(seed)
    names = [None] * need
    free_names = [n for n, z in featured.items() if z is None]
    for cell, name in zip(sorted(rng.sample(range(need), len(free_names))), free_names):
        names[cell] = name
    for name, zone in featured.items():
        if zone is None:
            continue
        r0, r1, c0, c1 = zone
        allowed = [r * grid + c for r in range(r0, r1 + 1) for c in range(c0, c1 + 1)]
        free = [i for i in allowed if names[i] is None]
        names[rng.choice(free)] = name
    filler = [n for n in pool if n not in featured]
    k = 0
    for i in range(need):
        if names[i] is None:
            names[i] = filler[k % len(filler)]
            k += 1
    sharp = sorted(i for i, n in enumerate(names) if n in featured)
    return names, sharp


# ── Animation ──────────────────────────────────────────────────────────

def diag_mask(size: int, c: float, edge: float, forward: bool):
    """Maske der Wischgrenze x - y = c mit weicher Kante."""
    mask = Image.new("L", (size, size), 0)
    px = mask.load()
    for y in range(size):
        for x in range(size):
            d = (x - y) - c if forward else c - (x - y)
            if d >= edge:
                px[x, y] = 255
            elif d > 0:
                px[x, y] = int(255 * d / edge)
    return mask


def animate(grid: int, cell: int, icon: int, names, sharp_cells, tiles, out: pathlib.Path):
    """Baut die komplette Frame-Folge und speichert sie als APNG."""
    size = grid * cell
    scale = size / 256
    edge = WIPE_EDGE * scale
    blur = BLUR_RADIUS * scale
    pad = (cell - icon) // 2

    frames, durations = [], []
    canvas = Image.new("RGBA", (size, size), BG)
    for k, name in enumerate(names):
        row, col = divmod(k, grid)
        canvas.alpha_composite(tiles[name], (col * cell + pad, row * cell + pad))
        frames.append(canvas.copy())
        durations.append(FRAME_MS)
    durations[-1] = HOLD_FULL_MS

    sharp = canvas.copy()
    blurred = sharp.filter(ImageFilter.GaussianBlur(blur))

    # Hinweg: scharf -> unscharf, von oben rechts nach unten links.
    for i in range(1, WIPE_FRAMES + 1):
        c = size - (2 * size + edge) * i / WIPE_FRAMES
        frames.append(Image.composite(blurred, sharp, diag_mask(size, c, edge, True)))
        durations.append(WIPE_MS)
    durations[-1] = HOLD_BLUR_MS

    # Zielbild: leerer Hintergrund, nur FEATURED-Zellen scharf.
    target = Image.new("RGBA", (size, size), BG)
    for k in sharp_cells:
        row, col = divmod(k, grid)
        box = (col * cell, row * cell, (col + 1) * cell, (row + 1) * cell)
        target.paste(sharp.crop(box), box)

    # Rückweg: unscharf -> Zielbild, von unten links nach oben rechts.
    for i in range(1, WIPE_FRAMES + 1):
        c = -size + (2 * size + edge) * i / WIPE_FRAMES
        frames.append(Image.composite(target, blurred, diag_mask(size, c, edge, False)))
        durations.append(WIPE_MS)
    durations[-1] = HOLD_END_MS

    # Alles ist grau auf weiß: 8-Bit-Graustufen statt RGBA hält die Datei klein.
    seq = [f.convert("L") for f in frames]
    seq[0].save(out, format="PNG", save_all=True, append_images=seq[1:],
                duration=durations, loop=LOOP, disposal=0, blend=0, optimize=True)
    print("%s: %d Frames, %.1f KB" % (out.name, len(seq), out.stat().st_size / 1024))


# ── Banner ─────────────────────────────────────────────────────────────

def banner(pool: list, tiles: dict, out_large: pathlib.Path, out_small: pathlib.Path):
    """Statisches Banner: Icon-Muster in Hellgrau, FEATURED dunkel, Text links."""
    cols = BANNER_W // BANNER_CELL
    rows = BANNER_H // BANNER_CELL
    off_x = (BANNER_W - cols * BANNER_CELL) // 2
    off_y = (BANNER_H - rows * BANNER_CELL) // 2
    pad = (BANNER_CELL - BANNER_ICON) // 2

    img = Image.new("RGBA", (BANNER_W, BANNER_H), BG)

    # Textblock links vermessen, um seine Fläche freizuhalten.
    title_font = ImageFont.truetype(FONT_BOLD, BANNER_TITLE_SIZE)
    sub_font = ImageFont.truetype(FONT_REGULAR, BANNER_SUB_SIZE)
    draw = ImageDraw.Draw(img)
    tb = draw.textbbox((0, 0), BANNER_TEXT, font=title_font)
    sb = draw.textbbox((0, 0), BANNER_SUB, font=sub_font)
    gap = 14
    block_h = (tb[3] - tb[1]) + gap + (sb[3] - sb[1])
    block_w = max(tb[2] - tb[0], sb[2] - sb[0])
    text_y = (BANNER_H - block_h) // 2
    field = (BANNER_TEXT_X - 40, text_y - 36, BANNER_TEXT_X + block_w + 40, text_y + block_h + 36)

    def cell_box(r, c):
        x = off_x + c * BANNER_CELL
        y = off_y + r * BANNER_CELL
        return (x, y, x + BANNER_CELL, y + BANNER_CELL)

    def overlaps(a, b):
        return not (a[2] <= b[0] or a[0] >= b[2] or a[3] <= b[1] or a[1] >= b[3])

    # Zellen unter dem Textfeld bleiben frei.
    cells = [(r, c) for r in range(rows) for c in range(cols)]
    free_cells = [rc for rc in cells if not overlaps(cell_box(*rc), field)]

    # FEATURED-Icons dunkel auf zufällige freie Zellen (Seed), Rest Muster.
    rng = random.Random(BANNER_SEED)
    featured_cells = dict(zip(rng.sample(free_cells, len(BANNER_FEATURED)), BANNER_FEATURED))
    filler = [n for n in pool if n not in BANNER_FEATURED]

    light_cache = {}
    k = 0
    for rc in free_cells:
        x, y, _, _ = cell_box(*rc)
        if rc in featured_cells:
            img.alpha_composite(tiles[featured_cells[rc]], (x + pad, y + pad))
            continue
        name = filler[k % len(filler)]
        k += 1
        if name not in light_cache:
            t = tiles[name].copy()
            t.putalpha(t.getchannel("A").point(lambda v: int(v * PATTERN_ALPHA)))
            light_cache[name] = t
        img.alpha_composite(light_cache[name], (x + pad, y + pad))

    # Text zeichnen.
    draw = ImageDraw.Draw(img)
    draw.text((BANNER_TEXT_X - tb[0], text_y - tb[1]), BANNER_TEXT, font=title_font, fill=BANNER_TEXT_COLOR)
    draw.text((BANNER_TEXT_X - sb[0], text_y + (tb[3] - tb[1]) + gap - sb[1]), BANNER_SUB, font=sub_font, fill=BANNER_TEXT_COLOR)

    # Dünner Rand innen entlang der Kante, damit die Bannergröße exakt bleibt.
    draw.rectangle((0, 0, BANNER_W - 1, BANNER_H - 1), outline=BANNER_BORDER_COLOR, width=BANNER_BORDER)

    large = img.convert("RGB")
    large.save(out_large, format="PNG", optimize=True)
    # Kleines Banner: skalieren und den Rand in 1 px nachziehen, damit er
    # beim Verkleinern nicht zu Grau verschwimmt.
    small = large.resize((BANNER_W // 2, BANNER_H // 2), Image.LANCZOS)
    ImageDraw.Draw(small).rectangle((0, 0, BANNER_W // 2 - 1, BANNER_H // 2 - 1), outline=BANNER_BORDER_COLOR, width=max(1, BANNER_BORDER // 2))
    small.save(out_small, format="PNG", optimize=True)
    for f in (out_large, out_small):
        print("%s: %.1f KB" % (f.name, f.stat().st_size / 1024))


# ── Hauptprogramm ──────────────────────────────────────────────────────

def main():
    if not BUNDLE.is_file():
        sys.exit("Bundle nicht gefunden: %s" % BUNDLE)
    icons = extract_icons(BUNDLE.read_text())
    by_name = dict(icons)
    for featured in (FEATURED_256, FEATURED_128, BANNER_FEATURED):
        missing = [n for n in featured if n not in by_name]
        if missing:
            sys.exit("FEATURED-Icons nicht im Bundle gefunden: %s" % ", ".join(missing))
    pool = [n for n, _ in icons]

    with tempfile.TemporaryDirectory() as tmp:
        work = pathlib.Path(tmp)
        tiles24 = render_tiles(icons, 24, work)
        tiles12 = render_tiles(icons, 12, work)
        tiles36 = render_tiles(icons, BANNER_ICON, work)

        names, sharp = place(8, FEATURED_256, pool, SHARP_SEED)
        print("Scharf am Ende:", ", ".join(names[i].replace("_default", "") for i in sharp))
        animate(8, 32, 24, names, sharp, tiles24, OUT_DIR / "icon-256x256.png")
        animate(8, 16, 12, names, sharp, tiles12, OUT_DIR / "icon-128x128.png")

        banner(pool, tiles36, OUT_DIR / "banner-1544x500.png", OUT_DIR / "banner-772x250.png")


if __name__ == "__main__":
    main()
