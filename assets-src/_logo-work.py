#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""
Studio-Komp — generator assetow nowego logo z assets-src/logo-source.jpg.

Podejscie:
1. Zmierzenie kolorow (tlo, zolty, jasny tekst) na pliku zrodlowym.
2. Rekonstrukcja maty alfa z odleglosci koloru od tla (luma + klasyfikacja
   zolty/jasny), bez halo: kolor w kazdym pikselu to kolor palety,
   a ksztalt/krycie przenosi alfa.
3. Upscale 3x premultiplied + Lanczos, feather 1px na alfie.
4. Tlo #2D343A dokladnie (kompozycja), lekkie wyostrzenie UnsharpMask.
5. Eksport logo 3x/2x/1x, transparent 3x, znak kwadratowy 512,
   ikony 192/512/180, favicon.ico (16/32/48), favicon.svg (base64 PNG),
   palette.json.

Uzycie:
  python assets-src/_logo-work.py
"""
import base64
import io
import json
import sys
from collections import Counter
from pathlib import Path

import numpy as np
from PIL import Image, ImageFilter

sys.stdout.reconfigure(encoding="utf-8")

ROOT = Path(r"C:\Users\Mati\Desktop\Piotrek")
SRC = ROOT / "assets-src" / "logo-source.jpg"
PUB = ROOT / "public"
BRAND = PUB / "brand"

# --- Paleta marki (tokeny z site/docs) ---
BG = np.array([0x2D, 0x34, 0x3A], np.float32)      # graphite
YELLOW = np.array([0xFF, 0xD4, 0x00], np.float32)  # brand yellow
LIGHT = np.array([0xF5, 0xF6, 0xF5], np.float32)   # light text
SCALE = 3

LANC = Image.Resampling.LANCZOS


def log(*a):
    print(*a)


def hexs(rgb):
    rgb = tuple(int(round(v)) for v in rgb)
    return "#%02X%02X%02X" % rgb


def save_rgb(arr, path):
    path.parent.mkdir(parents=True, exist_ok=True)
    Image.fromarray(np.clip(np.round(arr), 0, 255).astype(np.uint8), "RGB").save(path, "PNG", optimize=True)


def save_rgba(rgb, alpha, path):
    path.parent.mkdir(parents=True, exist_ok=True)
    a = np.clip(np.round(alpha * 255), 0, 255).astype(np.uint8)
    rgb8 = np.clip(np.round(rgb), 0, 255).astype(np.uint8)
    Image.fromarray(np.dstack([rgb8, a]), "RGBA").save(path, "PNG", optimize=True)


def blur_f32(a, sigma):
    """Separurowany blur gaussowski float (Pillow nie obsluguje filtra na 'F')."""
    radius = max(1, int(round(3.0 * sigma)))
    x = np.arange(-radius, radius + 1, dtype=np.float32)
    k = np.exp(-(x * x) / (2.0 * sigma * sigma))
    k /= k.sum()
    h, w = a.shape
    p = np.pad(a, ((0, 0), (radius, radius)), mode="edge")
    tmp = np.zeros_like(a)
    for i, wgt in enumerate(k):
        tmp += wgt * p[:, i:i + w]
    p2 = np.pad(tmp, ((radius, radius), (0, 0)), mode="edge")
    out = np.zeros_like(a)
    for i, wgt in enumerate(k):
        out += wgt * p2[i:i + h, :]
    return out


def premultiplied_resize(rgb, alpha, size, feather=0.0):
    """Poprawny resize RGBA: premultiply -> resize -> (feather) -> unpremultiply.

    Kolor jest po resize przyciagany do najblizszego koloru palety, wiec
    Lanczos nie zostawia jasnych/ciemnych obwodek (ringing) ani pikseli
    spoza palety. Alfa pozostaje wygladzona.
    """
    pm = rgb * alpha[..., None]
    chans = []
    for c in range(3):
        f = Image.fromarray(pm[..., c].astype(np.float32))
        chans.append(np.asarray(f.resize(size, LANC)).astype(np.float32))
    af = Image.fromarray((alpha * 255.0).astype(np.float32))
    a = np.clip(np.asarray(af.resize(size, LANC)).astype(np.float32) / 255.0, 0.0, 1.0)
    if feather > 0:
        chans = [blur_f32(ch, feather) for ch in chans]
        a = np.clip(blur_f32(a, feather), 0.0, 1.0)
    safe = a > 1e-4
    denom = np.where(safe, a, 1.0)
    out = np.zeros((size[1], size[0], 3), np.float32)
    for c in range(3):
        out[..., c] = np.where(safe, chans[c] / denom, 0.0)
    return snap_palette(out, a), a


def snap_palette(rgb, alpha):
    """Przyciaga kolor do palety marki (zolty/jasny); alfa trzyma ksztalt."""
    dy = ((rgb - YELLOW[None, None, :]) ** 2).sum(-1)
    dl = ((rgb - LIGHT[None, None, :]) ** 2).sum(-1)
    pal = np.where((dy < dl)[..., None], YELLOW[None, None, :], LIGHT[None, None, :])
    safe = alpha > 1e-4
    return np.where(safe[..., None], pal, 0.0).astype(np.float32)


def composite(rgb, alpha, bg):
    comp = bg[None, None, :] * (1.0 - alpha[..., None]) + rgb * alpha[..., None]
    return comp


def main():
    im = Image.open(SRC).convert("RGB")
    W, H = im.size
    arr = np.asarray(im).astype(np.float32)
    r, g, b = arr[..., 0], arr[..., 1], arr[..., 2]
    lum = 0.299 * r + 0.587 * g + 0.114 * b
    log(f"Zrodlo: {SRC} {W}x{H}")

    # ---------------------------------------------------------------- pomiary
    flat = arr.reshape(-1, 3)
    counters = Counter(map(tuple, flat.astype(np.uint8)))
    bg_hex = "#%02X%02X%02X" % counters.most_common(1)[0][0]

    yel_mask = (((r + g) / 2.0 - b) > 40) & (r > 100) & (g > 90)
    light_mask = (r > 195) & (g > 195) & (b > 195)

    def erode(m, n=2):
        m = m.copy()
        for _ in range(n):
            p = np.pad(m, 1, constant_values=False)
            m = (p[1:-1, 1:-1] & p[:-2, 1:-1] & p[2:, 1:-1] & p[1:-1, :-2] & p[1:-1, 2:])
        return m

    # "core" koloru = srednia z najjasniejszych 10% pikseli danej klasy
    # (rdzen kreski, bez ciemnych pikseli antyaliasu i bez rozjasnionego
    #  ringingu JPEG). Uzywany tez do normalizacji alfy.
    def core_color(mask):
        vals = arr[mask]
        ls = lum[mask]
        thr = np.percentile(ls, 90)
        return vals[ls >= thr].mean(axis=0), vals

    y_core, y_vals = core_color(yel_mask)
    l_core, l_vals = core_color(light_mask)
    er_y = erode(yel_mask, 1)
    er_l = erode(light_mask, 1)
    y_mean = y_vals.mean(axis=0)
    l_mean = l_vals.mean(axis=0)
    log("Pomiary kolorow (zrodlo):")
    log("  tlo dominanty        ", bg_hex)
    log("  zolty core (p90 lum) ", hexs(y_core))
    log("  zolty mediana rdzenia", hexs(np.median(arr[er_y], axis=0)), " pikseli:", int(er_y.sum()))
    log("  zolty srednia klasy  ", hexs(y_mean), " pikseli:", int(yel_mask.sum()))
    log("  jasny core (p90 lum) ", hexs(l_core))
    log("  jasny mediana rdzenia", hexs(np.median(arr[er_l], axis=0)), " pikseli:", int(er_l.sum()))
    log("  jasny srednia klasy  ", hexs(l_mean), " pikseli:", int(light_mask.sum()))

    # ----------------------------------------------------------- mata alfa
    lum_bg = float(0.299 * BG[0] + 0.587 * BG[1] + 0.114 * BG[2])
    lum_y = float(0.299 * y_core[0] + 0.587 * y_core[1] + 0.114 * y_core[2])
    lum_l = float(0.299 * l_core[0] + 0.587 * l_core[1] + 0.114 * l_core[2])

    a_y = np.clip((lum - lum_bg) / (lum_y - lum_bg), 0.0, 1.0)
    a_l = np.clip((lum - lum_bg) / (lum_l - lum_bg), 0.0, 1.0)
    pred_y = BG[None, None, :] + a_y[..., None] * (y_core[None, None, :] - BG)
    pred_l = BG[None, None, :] + a_l[..., None] * (l_core[None, None, :] - BG)
    dist_y = ((arr - pred_y) ** 2).sum(-1)
    dist_l = ((arr - pred_l) ** 2).sum(-1)
    is_yellow = dist_y < dist_l

    raw = np.where(is_yellow, a_y, a_l)
    # odciecie szumu JPEG (ringing) + zachowanie cienkich antyaliasow
    alpha = np.clip((raw - 0.10) / 0.80, 0.0, 1.0)
    strong = alpha > 0.20
    med = alpha > 0.045
    grow = strong.copy()
    for _ in range(2):
        p = np.pad(grow, 1, constant_values=False)
        grow = p[1:-1, 1:-1] | p[:-2, 1:-1] | p[2:, 1:-1] | p[1:-1, :-2] | p[1:-1, 2:]
    alpha = np.where(grow & med, alpha, 0.0)

    # paleta per piksel
    pal = np.where(is_yellow[..., None], YELLOW[None, None, :], LIGHT[None, None, :])
    alpha = np.clip(alpha, 0.0, 1.0)

    # ------------------------------------------------------------- upscale 3x
    size3 = (W * SCALE, H * SCALE)
    rgb3, a3 = premultiplied_resize(pal, alpha, size3, feather=0.9)

    # bbox logo (3x)
    ys, xs = np.nonzero(a3 > 0.03)
    log(f"BBox logo 3x: x {xs.min()}..{xs.max()} y {ys.min()}..{ys.max()}")

    # --------------------------------------------------- logo na tle grafitu
    comp3 = composite(rgb3, a3, BG)
    comp3_img = Image.fromarray(np.clip(np.round(comp3), 0, 255).astype(np.uint8), "RGB")
    sharp3_img = comp3_img.filter(ImageFilter.UnsharpMask(radius=1.0, percent=45, threshold=3))
    # tlo dokladnie #2D343A tam, gdzie alfa == 0
    sharp3 = np.asarray(sharp3_img).astype(np.float32)
    sharp3[a3 <= 0.0] = BG
    log("Kolor tla po kompozycji:", hexs(sharp3[H * SCALE // 2, 2, :]), hexs(sharp3[2, 2, :]))

    logo3_path = BRAND / "studio-komp-logo.png"
    save_rgb(sharp3, logo3_path)
    log("zapis", logo3_path)

    # 2x / 1x z czystej (niewyostrzonej) kompozycji 3x
    comp3_clean = Image.fromarray(np.clip(np.round(comp3), 0, 255).astype(np.uint8), "RGB")
    img2 = comp3_clean.resize((W * 2, H * 2), LANC)
    img1 = comp3_clean.resize((W, H), LANC)
    logo2_path = BRAND / "studio-komp-logo@2x.png"
    logo1_path = BRAND / "studio-komp-logo@1x.png"
    img2.save(logo2_path, "PNG", optimize=True)
    img1.save(logo1_path, "PNG", optimize=True)
    log("zapis", logo2_path, img2.size)
    log("zapis", logo1_path, img1.size)

    # ------------------------------------------------------ wersja transparent
    tr_path = BRAND / "studio-komp-logo-transparent.png"
    save_rgba(rgb3, a3, tr_path)
    log("zapis", tr_path, f"{W * SCALE}x{H * SCALE}")

    # ---------------------------------------------------- znak kwadratowy 512
    # w zrodle wordmark zaczyna sie od x=195; znak konczy sie na x=184
    x_lim = int(186 * SCALE)
    col_profile = [int((a3[:, x] > 0.03).sum()) for x in range(x_lim - 20, x_lim + 40)]
    log("profil kolumn przy cieciu (x, piksele):", col_profile)
    mark_mask = a3[:, :x_lim] > 0.03
    mys, mxs = np.nonzero(mark_mask)
    mx0, mx1, my0, my1 = mxs.min(), mxs.max(), mys.min(), mys.max()
    log(f"BBox znaku 3x: x {mx0}..{mx1} y {my0}..{my1} ({mx1 - mx0 + 1}x{my1 - my0 + 1})")
    mark_rgb = rgb3[my0:my1 + 1, mx0:mx1 + 1]
    mark_a = a3[my0:my1 + 1, mx0:mx1 + 1]
    mw, mh = mx1 - mx0 + 1, my1 - my0 + 1

    canvas = 512
    margin = 0.08
    content_w = int(round(canvas * (1 - 2 * margin)))  # 430
    content_h = int(round(content_w * mh / mw))
    log(f"Znak w kwadracie: {content_w}x{content_h} (margines 8%)")
    mrgb, ma = premultiplied_resize(mark_rgb, mark_a, (content_w, content_h))

    bg_rgba = np.zeros((canvas, canvas, 4), np.float32)
    bg_rgba[..., 0:3] = BG
    bg_rgba[..., 3] = 255.0
    offs = ((canvas - content_h) // 2, (canvas - content_w) // 2)
    bg_arr = bg_rgba.copy()
    oy, ox = offs
    dst = bg_arr[oy:oy + content_h, ox:ox + content_w]
    dst_rgb = dst[..., :3] * (1.0 - ma[..., None]) + mrgb * ma[..., None]
    dst[..., :3] = dst_rgb
    mark_square_path = BRAND / "studio-komp-mark-square.png"
    save_rgb(bg_arr[..., :3], mark_square_path)
    log("zapis", mark_square_path, "512x512")

    mark_square = Image.open(mark_square_path).convert("RGB")

    # ------------------------------------------------------------------ ikony
    icon512 = PUB / "icon-512.png"
    icon512.write_bytes(mark_square_path.read_bytes())
    log("zapis", icon512, "512x512 (kopia znaku)")

    for size, path in ((192, PUB / "icon-192.png"), (180, PUB / "apple-touch-icon.png")):
        im_s = mark_square.resize((size, size), LANC)
        im_s.save(path, "PNG", optimize=True)
        log("zapis", path, f"{size}x{size}")

    # ------------------------------------------------------------- favicon.ico
    ico_path = PUB / "favicon.ico"
    mark_square.save(ico_path, format="ICO", sizes=[(16, 16), (32, 32), (48, 48)])
    ico = Image.open(ico_path)
    log("zapis", ico_path, "sizes:", sorted(ico.info.get("sizes", [])))

    # ------------------------------------------------------------- favicon.svg
    png256 = io.BytesIO()
    mark_square.resize((256, 256), LANC).save(png256, "PNG", optimize=True)
    b64 = base64.b64encode(png256.getvalue()).decode("ascii")
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" '
        'viewBox="0 0 256 256" role="img" aria-label="Studio-Komp">\n'
        f'  <image width="256" height="256" href="data:image/png;base64,{b64}"/>\n'
        "</svg>\n"
    )
    svg_path = PUB / "favicon.svg"
    svg_path.write_text(svg, encoding="utf-8")
    log("zapis", svg_path, f"{svg_path.stat().st_size} B")

    # ------------------------------------------------------------ palette.json
    palette = {
        "graphite": "#2D343A",
        "yellow": "#FFD400",
        "light": "#F5F6F5",
        "source": "logo-source.jpg",
        "measured": {
            "background": bg_hex,
            "yellow": hexs(y_mean),
            "yellowCore": hexs(y_core),
            "light": hexs(l_mean),
            "lightCore": hexs(l_core),
            "sourceSize": f"{W}x{H}",
            "note": (
                "Wartosci 'measured' pochodza z logo-source.jpg (JPEG 4:2:0, "
                "desaturacja cienkich linii). Assety renderowane tokenami marki "
                "(yellow #FFD400, light #F5F6F5)."
            ),
        },
    }
    pal_path = BRAND / "palette.json"
    pal_path.write_text(json.dumps(palette, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    log("zapis", pal_path)

    # ------------------------------------------------------------ podsumowanie
    log("\n=== PLIKI ===")
    files = [
        BRAND / "studio-komp-logo.png",
        BRAND / "studio-komp-logo@2x.png",
        BRAND / "studio-komp-logo@1x.png",
        BRAND / "studio-komp-logo-transparent.png",
        BRAND / "studio-komp-mark-square.png",
        BRAND / "palette.json",
        PUB / "icon-512.png",
        PUB / "icon-192.png",
        PUB / "apple-touch-icon.png",
        PUB / "favicon.ico",
        PUB / "favicon.svg",
    ]
    for p in files:
        if p.suffix in (".json", ".svg"):
            log(f"{p.relative_to(ROOT)}  {p.stat().st_size} B")
        else:
            with Image.open(p) as ii:
                log(f"{p.relative_to(ROOT)}  {ii.size[0]}x{ii.size[1]}  {p.stat().st_size} B  {ii.mode}")


if __name__ == "__main__":
    main()
