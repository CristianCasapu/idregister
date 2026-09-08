#!/usr/bin/env python3
"""
Face descriptor for one picture, used to check that the selfie shows the person on the document.

usage: face_embed.py <image> [--min-size 0.04]

Prints one JSON object: {"ok", "vector": [...512 floats...], "score", "box", "faces"}
"faces" is how many faces were found; the biggest one is described. An empty vector means
no usable face was found.

Environment: RECOGNIZE_GPU, RECOGNIZE_INSIGHTFACE_ROOT, RECOGNIZE_INSIGHTFACE_MODEL.
"""
import contextlib
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')
os.environ.setdefault('OMP_NUM_THREADS', os.environ.get('RECOGNIZE_CORES', '0') or str(os.cpu_count() or 1))

import cv2  # noqa: E402
import numpy as np  # noqa: E402
import onnxruntime  # noqa: E402
from insightface.app import FaceAnalysis  # noqa: E402

MODEL = os.environ.get('RECOGNIZE_INSIGHTFACE_MODEL', 'buffalo_l')
ROOT = os.environ.get('RECOGNIZE_INSIGHTFACE_ROOT') or os.path.expanduser('~/.insightface')
DET_SIZE = 640
MAX_PX = 1600


def providers():
    available = onnxruntime.get_available_providers()
    if os.environ.get('RECOGNIZE_GPU') == 'true' and 'CUDAExecutionProvider' in available:
        return ['CUDAExecutionProvider', 'CPUExecutionProvider']
    return ['CPUExecutionProvider']


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    path = sys.argv[1]
    min_size = 0.04
    if '--min-size' in sys.argv:
        min_size = float(sys.argv[sys.argv.index('--min-size') + 1])

    img = cv2.imread(path, cv2.IMREAD_COLOR)
    if img is None:
        print(json.dumps({'ok': False, 'error': 'cannot decode image', 'vector': [], 'faces': 0}), flush=True)
        return
    height, width = img.shape[:2]
    if max(height, width) > MAX_PX:
        scale = MAX_PX / max(height, width)
        img = cv2.resize(img, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
        height, width = img.shape[:2]

    with contextlib.redirect_stdout(sys.stderr):
        # the 3D landmarks give the head pose (the head turn of the selfie); an optional module
        modules = ['detection', 'recognition'] + (['landmark_3d_68'] if os.environ.get('FACE_POSE') == '1' else [])
        app = FaceAnalysis(name=MODEL, root=ROOT, providers=providers(), allowed_modules=modules)
        app.prepare(ctx_id=0, det_size=(DET_SIZE, DET_SIZE))
        faces = app.get(img)

    usable = []
    for face in faces:
        x0, y0, x1, y1 = [float(v) for v in face.bbox]
        w, h = (x1 - x0) / width, (y1 - y0) / height
        if w >= min_size and h >= min_size:
            usable.append((w * h, face, (x0 / width, y0 / height, w, h)))

    if not usable:
        print(json.dumps({'ok': False, 'error': 'no face found', 'vector': [], 'faces': len(faces)}), flush=True)
        return

    usable.sort(key=lambda item: item[0], reverse=True)
    _, best, box = usable[0]
    print(json.dumps({
        'ok': True,
        'vector': [round(float(v), 6) for v in best.normed_embedding],
        'score': float(best.det_score),
        'box': {'x': box[0], 'y': box[1], 'width': box[2], 'height': box[3]},
        'faces': len(faces),
        # head pose in degrees [pitch, yaw, roll] from the 3D landmarks (the head turn of the selfie)
        'pose': [round(float(v), 1) for v in best.pose] if getattr(best, 'pose', None) is not None else None,
    }), flush=True)


if __name__ == '__main__':
    main()
