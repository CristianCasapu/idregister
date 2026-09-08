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


def read(engine, path):
    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        return {'width': 0, 'height': 0, 'lines': [], 'error': 'cannot decode ' + path}
    height, width = img.shape[:2]
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
    return {'width': width, 'height': height, 'lines': lines}


def main():
    if len(sys.argv) < 2:
        raise SystemExit('usage: ocr_frame.py <image> [<image> ...]')
    engine = load_engine()
    for path in sys.argv[1:]:
        print(json.dumps(read(engine, path), ensure_ascii=False), flush=True)


if __name__ == '__main__':
    main()
