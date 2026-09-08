#!/usr/bin/env python3
"""
Text of a picture of an identity document, with positions — the reading engine behind
"Sign up with ID" (RapidOCR: neural text detection + recognition on ONNX Runtime, the same
kind of engine as ML Kit in NecMat; it reads a photographed card far better than Tesseract).

usage: ocr_frame.py <image> [<image> ...]
prints one JSON object per image:
  {"width", "height", "lines": [{"text", "x", "y", "w", "h", "conf"}, ...]}
Lines are in reading order (rows top to bottom, left to right inside a row), boxes are
relative to the image (0..1). Nothing is written anywhere.
"""
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')
os.environ.setdefault('OMP_NUM_THREADS', '2')

import cv2  # noqa: E402
import numpy as np  # noqa: E402


def load_engine():
    from rapidocr_onnxruntime import RapidOCR
    return RapidOCR()


def order_rows(items):
    """Group boxes into rows (close tops) and sort each row left to right, like NecMat's orderLines."""
    items = sorted(items, key=lambda it: it['top'])
    rows, row, row_top = [], [], 0
    for it in items:
        if not row:
            row, row_top = [it], it['top']
            continue
        tol = max(4.0, np.mean([r['h'] for r in row]) * 0.5)
        if it['top'] - row_top <= tol:
            row.append(it)
        else:
            rows.append(sorted(row, key=lambda r: r['left']))
            row, row_top = [it], it['top']
    if row:
        rows.append(sorted(row, key=lambda r: r['left']))
    return [it for r in rows for it in r]


def liveness(img):
    """Is this a physical document, not a copy or a screen? Cheap signals on the card area
    (the frame is the guide plus a margin): colour (a black-and-white copy has none), a periodic
    pixel grid in the spectrum (a screen photographed shows moiré), the blue cast of a display,
    and where the glare sits (on a laminated card it moves when the hand tilts). Only numbers
    come out; the frame is dropped like every other one."""
    h, w = img.shape[:2]
    x0, x1 = int(w * 0.08 / 1.16), int(w * (1 - 0.08 / 1.16))
    y0, y1 = int(h * 0.15 / 1.30), int(h * (1 - 0.15 / 1.30))
    card = img[y0:y1, x0:x1]
    if card.size == 0 or card.shape[0] < 16 or card.shape[1] < 16:
        return None
    hsv = cv2.cvtColor(card, cv2.COLOR_BGR2HSV)
    sat = hsv[:, :, 1].astype(np.float32) / 255.0
    val = hsv[:, :, 2]
    lit = val > 40
    if lit.sum() < 100:
        return None
    sat_lit = sat[lit]
    out = {
        'sat': round(float(sat_lit.mean()), 4),
        'satP90': round(float(np.percentile(sat_lit, 90)), 4),
        'colour': round(float((sat_lit > 0.18).mean()), 4),
    }
    # blue cast: displays are cold (white point ~6500 K), a card under room light is not
    bgr = card.reshape(-1, 3).astype(np.float32)[lit.reshape(-1)]
    out['blue'] = round(float((bgr[:, 0].mean() - bgr[:, 2].mean()) / max(1.0, bgr.mean())), 4)
    # moiré: sharp peaks in the spectrum away from the centre, ignoring the JPEG block grid
    gray = cv2.cvtColor(card, cv2.COLOR_BGR2GRAY).astype(np.float32)
    n = 512
    g = cv2.resize(gray, (n, max(64, int(round(gray.shape[0] * n / gray.shape[1])))), interpolation=cv2.INTER_AREA)
    g = g - g.mean()
    win = np.outer(np.hanning(g.shape[0]), np.hanning(g.shape[1])).astype(np.float32)
    spec = np.log1p(np.abs(np.fft.fftshift(np.fft.fft2(g * win))))
    fh, fw = spec.shape
    cy, cx = fh // 2, fw // 2
    yy, xx = np.mgrid[:fh, :fw]
    dy, dx = yy - cy, xx - cx
    radius = np.sqrt((dy / cy) ** 2 + (dx / cx) ** 2)
    band = (radius > 0.10) & (radius < 0.97)
    # the 8x8 JPEG blocks leave peaks at multiples of 1/8 cycle per pixel on both axes
    jx = np.abs(((dx + fw / 16) % (fw / 8)) - fw / 16) < 3
    jy = np.abs(((dy + fh / 16) % (fh / 8)) - fh / 16) < 3
    jpeg_grid = (jx & (np.abs(dy) < 3)) | (jy & (np.abs(dx) < 3)) | (jx & jy)
    axes = (np.abs(dx) < 2) | (np.abs(dy) < 2)
    usable = band & ~jpeg_grid & ~axes
    background = cv2.GaussianBlur(spec, (0, 0), 5)
    contrast = spec - background
    peaks = contrast[usable]
    out['moire'] = round(float(peaks.max()) if peaks.size else 0.0, 3)
    out['peaks'] = int((peaks > 2.2).sum()) if peaks.size else 0
    # glare: a blown-out blob, and where it is (relative to the card)
    bright = val >= 250
    frac = float(bright.mean())
    out['glare'] = round(frac, 4)
    if 0.0015 < frac < 0.25:
        ys, xs = np.nonzero(bright)
        out['glareAt'] = [round(float(xs.mean()) / card.shape[1], 3), round(float(ys.mean()) / card.shape[0], 3)]
    else:
        out['glareAt'] = None
    # detail: a card has crisp micro-print, a photo of a screen or a copy is softer
    out['sharp'] = round(float(cv2.Laplacian(g, cv2.CV_32F).var()), 1)
    return out


def read(engine, path):
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        return {'width': 0, 'height': 0, 'lines': [], 'error': 'cannot decode ' + path}
    height, width = img.shape[:2]
    try:
        live = liveness(img)
    except Exception as e:  # noqa: BLE001 — the reading must not fail because of a check
        live = {'error': str(e)}
    result, _ = engine(img)
    items = []
    for box, text, conf in (result or []):
        xs = [p[0] for p in box]
        ys = [p[1] for p in box]
        left, top, right, bottom = min(xs), min(ys), max(xs), max(ys)
        text = str(text).strip()
        if not text:
            continue
        items.append({'text': text, 'left': left, 'top': top, 'w': right - left, 'h': bottom - top, 'conf': float(conf)})
    lines = []
    for it in order_rows(items):
        lines.append({
            'text': it['text'],
            'x': round(it['left'] / width, 4), 'y': round(it['top'] / height, 4),
            'w': round(it['w'] / width, 4), 'h': round(it['h'] / height, 4),
            'conf': round(it['conf'], 3),
        })
    return {'width': width, 'height': height, 'lines': lines, 'live': live}


def main():
    if len(sys.argv) < 2:
        raise SystemExit('usage: ocr_frame.py <image> [<image> ...]')
    engine = load_engine()
    for path in sys.argv[1:]:
        print(json.dumps(read(engine, path), ensure_ascii=False), flush=True)


if __name__ == '__main__':
    main()
