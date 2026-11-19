"""Génère le logo et les icônes de l'application « Collecte EFTP » dans la charte de la plateforme.

Charte (backend/public/app/css, mobile/lib/src/ui/common.dart) :
    vert #128A3E, vert foncé #0D6B30, orange #D35400, blanc.

Symbole : une fiche de recensement (porte-document blanc, pince orange) validée par une coche verte,
avec un badge orange portant un engrenage blanc (formation technique et professionnelle), sur fond vert.

Usage (depuis mobile/) :  python tool/generate_branding.py
Produit :
    android/app/src/main/res/mipmap-*/ic_launcher.png              icône classique (Android < 8)
    android/app/src/main/res/mipmap-*/ic_launcher_foreground.png   avant-plan de l'icône adaptative (Android 8+)
    android/app/src/main/res/mipmap-*/ic_launcher_monochrome.png   icône thématique (Android 13+)
    android/app/src/main/res/mipmap-anydpi-v26/ic_launcher.xml     + values/ic_launcher_background.xml
    assets/branding/logo_mark.png        icône seule, coins arrondis (écran de connexion)
    assets/branding/logo_horizontal.png  icône + nom (documents, back-office, présentations)
    assets/branding/icon_1024.png        icône carrée haute définition (documentation, publication)
Dépendance : Pillow (pip install pillow). Police : Segoe UI (Windows), Arial ou DejaVu Sans à défaut.
"""
from __future__ import annotations

import math
import os
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

GREEN = (18, 138, 62)
GREEN_DARK = (13, 107, 48)
ORANGE = (211, 84, 0)
WHITE = (255, 255, 255)
LINE = (205, 222, 210)
CLEAR = (0, 0, 0, 0)

ROOT = Path(__file__).resolve().parents[1]
RES = ROOT / "android" / "app" / "src" / "main" / "res"
ASSETS = ROOT / "assets" / "branding"
SS = 4  # suréchantillonnage (anticrénelage)


def gear(draw: ImageDraw.ImageDraw, cx: float, cy: float, r: float, color, hole_color, teeth: int = 8) -> None:
    """Engrenage : couronne dentée + moyeu évidé."""
    r_in = r * 0.76
    step = 2 * math.pi / teeth
    pts = []
    for i in range(teeth):
        for frac, rad in ((0.00, r_in), (0.12, r), (0.38, r), (0.50, r_in)):
            a = step * (i + frac)
            pts.append((cx + rad * math.cos(a), cy + rad * math.sin(a)))
    draw.polygon(pts, fill=color)
    draw.ellipse([cx - r_in, cy - r_in, cx + r_in, cy + r_in], fill=color)
    h = r * 0.34
    draw.ellipse([cx - h, cy - h, cx + h, cy + h], fill=hole_color)


def check(draw: ImageDraw.ImageDraw, pts, width: float, color) -> None:
    draw.line(pts, fill=color, width=int(width), joint="curve")
    for x, y in (pts[0], pts[-1]):  # extrémités arrondies
        draw.ellipse([x - width / 2, y - width / 2, x + width / 2, y + width / 2], fill=color)


def symbol(size: int, *, mono: bool = False) -> Image.Image:
    """Symbole (à poser sur fond vert), transparent autour, occupant le carré [size×size]."""
    s = size * SS
    img = Image.new("RGBA", (s, s), CLEAR)
    d = ImageDraw.Draw(img)
    u = s / 100.0  # unité : 1 % du côté
    # Porte-document et pince
    d.rounded_rectangle([18 * u, 14 * u, 76 * u, 90 * u], radius=8 * u, fill=WHITE)
    d.rounded_rectangle([34 * u, 7 * u, 60 * u, 21 * u], radius=4.5 * u, fill=WHITE if mono else ORANGE)
    d.ellipse([44 * u, 9.5 * u, 50 * u, 15.5 * u], fill=CLEAR if mono else WHITE)
    # Lignes de la fiche et coche (découpées dans le blanc pour l'icône monochrome)
    for y in (31, 40):
        d.rounded_rectangle([27 * u, y * u, 67 * u, (y + 4) * u], radius=2 * u, fill=CLEAR if mono else LINE)
    check(d, [(29 * u, 62 * u), (40 * u, 73 * u), (62 * u, 50 * u)], 8 * u, CLEAR if mono else GREEN)
    # Badge engrenage
    bx, by, br = 76 * u, 78 * u, 17 * u
    if mono:
        d.ellipse([bx - br - 3 * u, by - br - 3 * u, bx + br + 3 * u, by + br + 3 * u], fill=CLEAR)
        gear(d, bx, by, br * 0.92, WHITE, CLEAR)
    else:
        d.ellipse([bx - br - 2.5 * u, by - br - 2.5 * u, bx + br + 2.5 * u, by + br + 2.5 * u], fill=WHITE)
        d.ellipse([bx - br, by - br, bx + br, by + br], fill=ORANGE)
        gear(d, bx, by, br * 0.66, WHITE, ORANGE)
    return img.resize((size, size), Image.LANCZOS)


def background(size: int, radius_pct: float) -> Image.Image:
    """Fond vert en dégradé diagonal (vert → vert foncé), coins arrondis."""
    n = 128
    small = Image.new("RGB", (n, n))
    px = small.load()
    for y in range(n):
        for x in range(n):
            t = (x + y) / (2 * (n - 1))
            px[x, y] = tuple(int(GREEN[i] + (GREEN_DARK[i] - GREEN[i]) * t) for i in range(3))
    s = size * SS
    grad = small.resize((s, s), Image.BICUBIC).convert("RGBA")
    mask = Image.new("L", (s, s), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, s - 1, s - 1], radius=int(s * radius_pct), fill=255)
    out = Image.new("RGBA", (s, s), CLEAR)
    out.paste(grad, (0, 0), mask)
    return out.resize((size, size), Image.LANCZOS)


def full_icon(size: int, radius_pct: float = 0.22) -> Image.Image:
    bg = background(size, radius_pct)
    inner = int(size * 0.74)
    off = (size - inner) // 2
    bg.alpha_composite(symbol(inner), (off, off))
    return bg


def font(size: int, bold: bool) -> ImageFont.FreeTypeFont:
    names = ["segoeuib.ttf", "arialbd.ttf", "DejaVuSans-Bold.ttf"] if bold else ["segoeui.ttf", "arial.ttf", "DejaVuSans.ttf"]
    for name in names:
        for base in (os.environ.get("WINDIR", "C:/Windows") + "/Fonts", "/usr/share/fonts/truetype/dejavu"):
            p = Path(base) / name
            if p.exists():
                return ImageFont.truetype(str(p), size)
    return ImageFont.load_default()


def horizontal_logo(height: int = 256) -> Image.Image:
    sub = "Recensement de la formation professionnelle non formelle"
    f1, f2 = font(int(height * 0.36), True), font(int(height * 0.13), False)
    meas = ImageDraw.Draw(Image.new("RGB", (1, 1)))
    x = int(height * 1.18)
    w = int(x + max(meas.textlength("Collecte EFTP", font=f1), meas.textlength(sub, font=f2)) + height * 0.1)
    img = Image.new("RGBA", (w, height), CLEAR)
    img.alpha_composite(full_icon(height), (0, 0))
    d = ImageDraw.Draw(img)
    d.text((x, int(height * 0.10)), "Collecte ", font=f1, fill=GREEN_DARK)
    d.text((x + meas.textlength("Collecte ", font=f1), int(height * 0.10)), "EFTP", font=f1, fill=ORANGE)
    d.text((x, int(height * 0.64)), sub, font=f2, fill=(60, 72, 64))
    return img


def main() -> None:
    for name, k in {"mdpi": 1.0, "hdpi": 1.5, "xhdpi": 2.0, "xxhdpi": 3.0, "xxxhdpi": 4.0}.items():
        folder = RES / f"mipmap-{name}"
        folder.mkdir(parents=True, exist_ok=True)
        full_icon(round(48 * k)).save(folder / "ic_launcher.png")
        # Icône adaptative : calque de 108 dp ; le symbole tient dans la zone sûre centrale (66 dp de diamètre).
        layer = round(108 * k)
        for mono, file in ((False, "ic_launcher_foreground.png"), (True, "ic_launcher_monochrome.png")):
            img = Image.new("RGBA", (layer, layer), CLEAR)
            mark = symbol(round(62 * k), mono=mono)
            img.alpha_composite(mark, ((layer - mark.width) // 2, (layer - mark.height) // 2))
            img.save(folder / file)

    any_dpi = RES / "mipmap-anydpi-v26"
    any_dpi.mkdir(exist_ok=True)
    (any_dpi / "ic_launcher.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<!-- Icône adaptative (Android 8+), générée par mobile/tool/generate_branding.py -->\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@color/ic_launcher_background"/>\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>\n'
        '    <monochrome android:drawable="@mipmap/ic_launcher_monochrome"/>\n'
        '</adaptive-icon>\n', encoding="utf-8")
    (RES / "values" / "ic_launcher_background.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n<resources>\n'
        '    <!-- Fond de l\'icône adaptative : vert foncé de la charte (#0D6B30). -->\n'
        '    <color name="ic_launcher_background">#0D6B30</color>\n</resources>\n', encoding="utf-8")

    ASSETS.mkdir(parents=True, exist_ok=True)
    full_icon(512).save(ASSETS / "logo_mark.png")
    full_icon(1024).save(ASSETS / "icon_1024.png")
    horizontal_logo(256).save(ASSETS / "logo_horizontal.png")
    print("Logo et icônes générés dans", ASSETS, "et", RES)


if __name__ == "__main__":
    main()
