#!/usr/bin/env python3
"""
Where the face is in a small selfie frame — the guidance behind the automatic selfie
("come closer", "centre your face", "hold still"). OpenCV's frontal-face cascade, which ships
with OpenCV: fast, no model to download, good enough to place a face in an oval.

usage: face_guide.py <image>
prints {"width", "height", "faces": [{"x", "y", "w", "h"}]} — boxes relative to the image (0..1),
largest first. Nothing is written anywhere.
"""
import json
import os
import sys

os.environ.setdefault('OMP_NUM_THREADS', '1')

import cv2  # noqa: E402


def main():
    if len(sys.argv) < 2:
        raise SystemExit('usage: face_guide.py <image>')
    img = cv2.imread(sys.argv[1], cv2.IMREAD_COLOR)
    if img is None:
        print(json.dumps({'width': 0, 'height': 0, 'faces': [], 'error': 'cannot decode'}))
        return
    h, w = img.shape[:2]
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    gray = cv2.equalizeHist(gray)
    cascade = cv2.CascadeClassifier(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml')
    min_side = max(24, int(min(w, h) * 0.18))
    faces = cascade.detectMultiScale(gray, scaleFactor=1.1, minNeighbors=4, minSize=(min_side, min_side))
    out = []
    for (x, y, fw, fh) in sorted(list(faces), key=lambda f: -f[2] * f[3]):
        out.append({'x': round(x / w, 4), 'y': round(y / h, 4), 'w': round(fw / w, 4), 'h': round(fh / h, 4)})
    print(json.dumps({'width': w, 'height': h, 'faces': out}))


if __name__ == '__main__':
    main()
