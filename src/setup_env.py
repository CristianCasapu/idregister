#!/usr/bin/env python3
"""
Installs everything "Sign up with ID" needs into the virtual environment this script runs in —
called by the app from the administration page (or "occ idregister:install-ocr"); nothing to
do by hand.

    <venv>/bin/python setup_env.py --models <dir>

Packages: RapidOCR (neural text recognition; the ONNX Runtime build for Python < 3.13, the
newer "rapidocr" package after that), ONNX Runtime, OpenCV headless 4.x (no libGL on the server needed; the 5.x wheels
ship without the Haar cascades the selfie guide uses), numpy. Only pre-built wheels are used, so no compiler is needed.
Models: InsightFace's "buffalo_l" pack (face detection, recognition, 3D landmarks) from its
GitHub release, unpacked into <dir>/buffalo_l/. Prints what it does, one line at a time.
"""
import hashlib
import importlib.metadata as md
import json
import os
import re
import subprocess
import sys
import tempfile
import urllib.request
import zipfile

PY = sys.executable
PACK = 'buffalo_l'
PACK_URL = 'https://github.com/deepinsight/insightface/releases/download/v0.7/buffalo_l.zip'
PACK_FILES = ('det_10g.onnx', 'w600k_r50.onnx', '1k3d68.onnx')


def say(msg):
    print(msg, flush=True)


def pip(*args, check=True):
    cmd = [PY, '-m', 'pip', '--disable-pip-version-check', '--no-input', *args]
    say('$ ' + ' '.join(a for a in cmd[3:]))
    r = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
    for line in r.stdout.splitlines():
        if line.strip() and not line.startswith('  '):
            say(line.rstrip())
    if check and r.returncode != 0:
        raise SystemExit('pip failed (%d)' % r.returncode)
    return r.returncode


def deps_of(dist, drop=(), add=()):
    out = list(add)
    for req in md.requires(dist) or []:
        req = req.split(';')[0].strip()
        name = re.split(r'[<>=!~\[ ]', req, 1)[0].strip().lower().replace('_', '-')
        if name in drop or not name:
            continue
        out.append(req)
    return out


def fetch_models(root):
    pack = os.path.join(root, PACK)
    if all(os.path.isfile(os.path.join(pack, f)) for f in PACK_FILES):
        say('face models already there: ' + pack)
        return
    os.makedirs(root, exist_ok=True)
    say('== Downloading the face models (%s, about 290 MB)' % PACK)
    fd, tmp = tempfile.mkstemp(suffix='.zip', dir=root)
    os.close(fd)
    try:
        req = urllib.request.Request(PACK_URL, headers={'User-Agent': 'idregister-setup'})
        with urllib.request.urlopen(req, timeout=120) as resp, open(tmp, 'wb') as out:
            total = int(resp.headers.get('Content-Length') or 0)
            done, mark = 0, 0
            while True:
                chunk = resp.read(1 << 20)
                if not chunk:
                    break
                out.write(chunk)
                done += len(chunk)
                if total and done * 10 // total > mark:
                    mark = done * 10 // total
                    say('  %d%%' % (mark * 10))
        say('  %d MB, sha256 %s' % (done >> 20, hashlib.sha256(open(tmp, 'rb').read()).hexdigest()[:16]))
        with zipfile.ZipFile(tmp) as z:
            names = [n for n in z.namelist() if n.endswith('.onnx')]
            os.makedirs(pack, exist_ok=True)
            for n in names:
                target = os.path.join(pack, os.path.basename(n))
                with z.open(n) as src, open(target, 'wb') as dst:
                    dst.write(src.read())
                say('  ' + os.path.basename(n))
    finally:
        try:
            os.unlink(tmp)
        except OSError:
            pass
    missing = [f for f in PACK_FILES if not os.path.isfile(os.path.join(pack, f))]
    if missing:
        raise SystemExit('the model pack lacks ' + ', '.join(missing))


def main():
    models = None
    if '--models' in sys.argv:
        models = sys.argv[sys.argv.index('--models') + 1]
    say('Python %d.%d.%d at %s' % (*sys.version_info[:3], PY))
    pip('install', '--upgrade', 'pip', 'wheel', check=False)

    legacy = sys.version_info < (3, 13)
    ocr = 'rapidocr_onnxruntime>=1.3,<2' if legacy else 'rapidocr>=2'
    ocr_dist = 'rapidocr_onnxruntime' if legacy else 'rapidocr'
    say('OCR package: ' + ocr)
    pip('install', '--only-binary=:all:', '--no-deps', '--upgrade', ocr)
    deps = deps_of(ocr_dist, drop=('opencv-python', 'opencv-contrib-python'),
                   add=('opencv-python-headless>=4.5,<5', 'onnxruntime>=1.7', 'numpy'))
    pip('install', '--only-binary=:all:', '--upgrade', *deps)

    say('== Checking the reader')
    import numpy as np
    import cv2
    import onnxruntime  # noqa: F401
    if legacy:
        from rapidocr_onnxruntime import RapidOCR
    else:
        from rapidocr import RapidOCR
    engine = RapidOCR()
    img = np.full((64, 220, 3), 255, np.uint8)
    cv2.putText(img, 'DOCUMENT', (8, 44), cv2.FONT_HERSHEY_SIMPLEX, 1.2, (0, 0, 0), 2)
    out = engine(img)
    found = out[0] if isinstance(out, tuple) else getattr(out, 'txts', None)
    say('warm-up read: %s' % (json.dumps([t[1] if isinstance(t, (list, tuple)) else t for t in (found or [])]),))
    # the face cascades of OpenCV, used by the selfie guide (the 5.x wheels do not ship them)
    if not os.path.isfile(cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'):
        raise SystemExit('this OpenCV build (%s) has no Haar cascades; the selfie guide needs them' % cv2.__version__)

    if models:
        fetch_models(models)
        say('== Checking the face models')
        sess = onnxruntime.InferenceSession(os.path.join(models, PACK, 'w600k_r50.onnx'), providers=['CPUExecutionProvider'])
        say('  recognition input %s' % (sess.get_inputs()[0].shape,))
    versions = {d: md.version(d) for d in (ocr_dist, 'onnxruntime', 'opencv-python-headless', 'numpy')}
    say('installed: ' + json.dumps(versions))
    say('== Done')


if __name__ == '__main__':
    main()
