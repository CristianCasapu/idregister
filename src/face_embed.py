#!/usr/bin/env python3
"""
Face descriptor for one picture, used to check that the selfie shows the person on the document.

usage: face_embed.py <image> [--min-size 0.04]

Prints one JSON object: {"ok", "vector": [...512 floats...], "score", "box", "faces", "pose"}
"faces" is how many faces were found; the biggest one is described. An empty vector means
no usable face was found. "pose" = [pitch, yaw, roll] in degrees when FACE_POSE=1.

The models are InsightFace's "buffalo_l" pack (SCRFD detector det_10g, ArcFace w600k_r50,
the 68-point 3D landmark net for the head pose) run straight on ONNX Runtime — the same
numbers InsightFace's FaceAnalysis gives, without the insightface package, which needs a
C compiler to install. The app installs the models by itself.

Environment: RECOGNIZE_INSIGHTFACE_ROOT (…/models/<pack>/*.onnx), RECOGNIZE_INSIGHTFACE_MODEL
(pack name, buffalo_l), RECOGNIZE_GPU (true = CUDA when available).
"""
import contextlib
import json
import math
import os
import sys
import warnings

warnings.filterwarnings('ignore')
os.environ.setdefault('OMP_NUM_THREADS', os.environ.get('RECOGNIZE_CORES', '0') or str(os.cpu_count() or 1))

import cv2  # noqa: E402
import numpy as np  # noqa: E402
import onnxruntime  # noqa: E402

MODEL = os.environ.get('RECOGNIZE_INSIGHTFACE_MODEL', 'buffalo_l')
ROOT = os.environ.get('RECOGNIZE_INSIGHTFACE_ROOT') or os.path.expanduser('~/.insightface')
DET_SIZE = 640
MAX_PX = 1600
DET_THRESH = 0.5
NMS_THRESH = 0.4

# the five ArcFace reference points on a 112x112 crop
ARCFACE_DST = np.array([[38.2946, 51.6963], [73.5318, 51.5014], [56.0252, 71.7366],
                        [41.5493, 92.3655], [70.7299, 92.2041]], dtype=np.float32)

# InsightFace's mean 3D shape of the 68 landmarks (meanshape_68.pkl), for the head pose
MEAN_SHAPE_68 = np.array([
    [-0.6267, -0.2927, -0.3140],
    [-0.5997, -0.1225, -0.2924],
    [-0.5710, 0.0512, -0.2549],
    [-0.5339, 0.2128, -0.1866],
    [-0.4797, 0.3506, -0.0477],
    [-0.3958, 0.4465, 0.0733],
    [-0.2988, 0.5107, 0.1798],
    [-0.1884, 0.5544, 0.3167],
    [0.0015, 0.5844, 0.3884],
    [0.1910, 0.5517, 0.3143],
    [0.3269, 0.4896, 0.1684],
    [0.4400, 0.4024, 0.0360],
    [0.5069, 0.3116, -0.0948],
    [0.5409, 0.2045, -0.2027],
    [0.5741, 0.0457, -0.2842],
    [0.5991, -0.1459, -0.2965],
    [0.6275, -0.3077, -0.3020],
    [-0.4747, -0.4376, 0.2365],
    [-0.4167, -0.4718, 0.3160],
    [-0.3475, -0.4841, 0.3661],
    [-0.2606, -0.4764, 0.3992],
    [-0.1671, -0.4578, 0.4166],
    [0.1232, -0.4587, 0.4251],
    [0.2064, -0.4804, 0.4158],
    [0.2867, -0.4901, 0.3920],
    [0.3624, -0.4769, 0.3528],
    [0.4256, -0.4501, 0.2953],
    [-0.0076, -0.3231, 0.4619],
    [-0.0079, -0.2557, 0.5105],
    [-0.0077, -0.1992, 0.5525],
    [-0.0073, -0.1426, 0.5987],
    [-0.1450, 0.0331, 0.4197],
    [-0.0843, 0.0313, 0.4732],
    [-0.0055, 0.0398, 0.5147],
    [0.0635, 0.0461, 0.4792],
    [0.1340, 0.0220, 0.4191],
    [-0.3868, -0.3134, 0.2596],
    [-0.3167, -0.3501, 0.3285],
    [-0.2341, -0.3549, 0.3335],
    [-0.1552, -0.3152, 0.3143],
    [-0.2309, -0.2843, 0.3256],
    [-0.3175, -0.2852, 0.3099],
    [0.1390, -0.3098, 0.3183],
    [0.2195, -0.3532, 0.3380],
    [0.3017, -0.3497, 0.3331],
    [0.3767, -0.3135, 0.2632],
    [0.2967, -0.2871, 0.3220],
    [0.2146, -0.2905, 0.3312],
    [-0.2014, 0.2374, 0.3795],
    [-0.1373, 0.1858, 0.4653],
    [-0.0765, 0.1512, 0.5036],
    [-0.0025, 0.1687, 0.5164],
    [0.0644, 0.1509, 0.5045],
    [0.1265, 0.1795, 0.4686],
    [0.2182, 0.2390, 0.3757],
    [0.1329, 0.2839, 0.4401],
    [0.0680, 0.2974, 0.4774],
    [-0.0005, 0.3000, 0.4871],
    [-0.0693, 0.2970, 0.4805],
    [-0.1425, 0.2743, 0.4381],
    [-0.1781, 0.2306, 0.3964],
    [-0.0740, 0.2147, 0.4653],
    [-0.0026, 0.2141, 0.4832],
    [0.0598, 0.2108, 0.4722],
    [0.1669, 0.2313, 0.3968],
    [0.0598, 0.2238, 0.4664],
    [-0.0014, 0.2258, 0.4752],
    [-0.0752, 0.2307, 0.4671]
], dtype=np.float32)


def providers():
    available = onnxruntime.get_available_providers()
    if os.environ.get('RECOGNIZE_GPU') == 'true' and 'CUDAExecutionProvider' in available:
        return ['CUDAExecutionProvider', 'CPUExecutionProvider']
    return ['CPUExecutionProvider']


def model_path(name):
    for base in (ROOT, os.path.join(ROOT, 'models')):
        p = os.path.join(base, MODEL, name)
        if os.path.isfile(p):
            return p
    raise FileNotFoundError('%s/%s not found under %s' % (MODEL, name, ROOT))


def session(name):
    opts = onnxruntime.SessionOptions()
    opts.log_severity_level = 3
    return onnxruntime.InferenceSession(model_path(name), opts, providers=providers())


# ---------------------------------------------------------------- similarity transform (Umeyama)

def similarity_transform(src, dst):
    """The 2x3 matrix of the similarity (scale, rotation, translation) that maps src onto dst
    in the least-squares sense — what skimage's SimilarityTransform.estimate computes."""
    src = np.asarray(src, dtype=np.float64)
    dst = np.asarray(dst, dtype=np.float64)
    n = src.shape[0]
    src_mean = src.mean(axis=0)
    dst_mean = dst.mean(axis=0)
    src_demean = src - src_mean
    dst_demean = dst - dst_mean
    A = dst_demean.T @ src_demean / n
    d = np.ones(2)
    if np.linalg.det(A) < 0:
        d[1] = -1
    U, S, Vt = np.linalg.svd(A)
    rank = np.linalg.matrix_rank(A)
    if rank == 0:
        return None
    if rank == 1:
        if np.linalg.det(U) * np.linalg.det(Vt) > 0:
            R = U @ Vt
        else:
            s = d[1]
            d[1] = -1
            R = U @ np.diag(d) @ Vt
            d[1] = s
    else:
        R = U @ np.diag(d) @ Vt
    var_src = src_demean.var(axis=0).sum()
    scale = 1.0 / var_src * (S @ d)
    t = dst_mean - scale * (R @ src_mean)
    M = np.zeros((2, 3))
    M[:, :2] = scale * R
    M[:, 2] = t
    return M


def norm_crop(img, kps, size=112):
    M = similarity_transform(kps, ARCFACE_DST)
    return cv2.warpAffine(img, M, (size, size), borderValue=0.0)


# ---------------------------------------------------------------- SCRFD detection (det_10g)

class Detector:
    def __init__(self):
        self.sess = session('det_10g.onnx')
        self.input_name = self.sess.get_inputs()[0].name
        self.output_names = [o.name for o in self.sess.get_outputs()]
        self.batched = len(self.sess.get_outputs()[0].shape) == 3
        self.strides = [8, 16, 32]
        self.num_anchors = 2
        self.fmc = 3

    def forward(self, img):
        h, w = img.shape[:2]
        blob = cv2.dnn.blobFromImage(img, 1.0 / 128.0, (w, h), (127.5, 127.5, 127.5), swapRB=True)
        outs = self.sess.run(self.output_names, {self.input_name: blob})
        scores_l, boxes_l, kps_l = [], [], []
        for idx, stride in enumerate(self.strides):
            scores = outs[idx][0] if self.batched else outs[idx]
            bbox = (outs[idx + self.fmc][0] if self.batched else outs[idx + self.fmc]) * stride
            kps = (outs[idx + self.fmc * 2][0] if self.batched else outs[idx + self.fmc * 2]) * stride
            fh, fw = h // stride, w // stride
            centers = np.stack(np.mgrid[:fh, :fw][::-1], axis=-1).astype(np.float32)
            centers = (centers * stride).reshape((-1, 2))
            centers = np.stack([centers] * self.num_anchors, axis=1).reshape((-1, 2))
            pos = np.where(scores >= DET_THRESH)[0]
            x1 = centers[:, 0] - bbox[:, 0]
            y1 = centers[:, 1] - bbox[:, 1]
            x2 = centers[:, 0] + bbox[:, 2]
            y2 = centers[:, 1] + bbox[:, 3]
            boxes = np.stack([x1, y1, x2, y2], axis=-1)
            pts = []
            for i in range(0, kps.shape[1], 2):
                pts.append(centers[:, i % 2] + kps[:, i])
                pts.append(centers[:, i % 2 + 1] + kps[:, i + 1])
            pts = np.stack(pts, axis=-1).reshape((kps.shape[0], -1, 2))
            scores_l.append(scores[pos])
            boxes_l.append(boxes[pos])
            kps_l.append(pts[pos])
        return scores_l, boxes_l, kps_l

    @staticmethod
    def nms(dets):
        x1, y1, x2, y2, scores = dets[:, 0], dets[:, 1], dets[:, 2], dets[:, 3], dets[:, 4]
        areas = (x2 - x1 + 1) * (y2 - y1 + 1)
        order = scores.argsort()[::-1]
        keep = []
        while order.size > 0:
            i = order[0]
            keep.append(i)
            xx1 = np.maximum(x1[i], x1[order[1:]])
            yy1 = np.maximum(y1[i], y1[order[1:]])
            xx2 = np.minimum(x2[i], x2[order[1:]])
            yy2 = np.minimum(y2[i], y2[order[1:]])
            w = np.maximum(0.0, xx2 - xx1 + 1)
            h = np.maximum(0.0, yy2 - yy1 + 1)
            inter = w * h
            ovr = inter / (areas[i] + areas[order[1:]] - inter)
            order = order[np.where(ovr <= NMS_THRESH)[0] + 1]
        return keep

    def detect(self, img):
        """(N, 5) boxes with score, (N, 5, 2) key points — like SCRFD.detect."""
        im_ratio = float(img.shape[0]) / img.shape[1]
        if im_ratio > 1.0:
            new_h, new_w = DET_SIZE, int(DET_SIZE / im_ratio)
        else:
            new_w, new_h = DET_SIZE, int(DET_SIZE * im_ratio)
        det_scale = float(new_h) / img.shape[0]
        det_img = np.zeros((DET_SIZE, DET_SIZE, 3), dtype=np.uint8)
        det_img[:new_h, :new_w, :] = cv2.resize(img, (new_w, new_h))
        scores_l, boxes_l, kps_l = self.forward(det_img)
        scores = np.vstack(scores_l)
        if scores.size == 0:
            return np.empty((0, 5), np.float32), np.empty((0, 5, 2), np.float32)
        order = scores.ravel().argsort()[::-1]
        boxes = np.vstack(boxes_l) / det_scale
        kps = np.vstack(kps_l) / det_scale
        pre = np.hstack((boxes, scores)).astype(np.float32, copy=False)[order]
        kps = kps[order]
        keep = self.nms(pre)
        return pre[keep], kps[keep]


# ---------------------------------------------------------------- ArcFace embedding (w600k_r50)

class Recognizer:
    def __init__(self):
        self.sess = session('w600k_r50.onnx')
        self.input_name = self.sess.get_inputs()[0].name
        self.output_name = self.sess.get_outputs()[0].name
        shape = self.sess.get_inputs()[0].shape
        self.size = int(shape[2])

    def embed(self, img, kps):
        crop = norm_crop(img, kps, self.size)
        blob = cv2.dnn.blobFromImage(crop, 1.0 / 127.5, (self.size, self.size), (127.5, 127.5, 127.5), swapRB=True)
        return self.sess.run([self.output_name], {self.input_name: blob})[0].flatten()


# ---------------------------------------------------------------- 3D landmarks → head pose (1k3d68)

class Pose:
    def __init__(self):
        self.sess = session('1k3d68.onnx')
        self.input_name = self.sess.get_inputs()[0].name
        self.output_name = self.sess.get_outputs()[0].name
        self.size = int(self.sess.get_inputs()[0].shape[2])

    def angles(self, img, bbox):
        w, h = bbox[2] - bbox[0], bbox[3] - bbox[1]
        cx, cy = (bbox[2] + bbox[0]) / 2, (bbox[3] + bbox[1]) / 2
        scale = self.size / (max(w, h) * 1.5)
        # scale about the origin, move the face centre to the middle of the crop (no rotation)
        M = np.array([[scale, 0, self.size / 2 - cx * scale], [0, scale, self.size / 2 - cy * scale]], dtype=np.float64)
        crop = cv2.warpAffine(img, M, (self.size, self.size), borderValue=0.0)
        # this net normalises its input itself (its graph starts with a batch-norm on the pixels)
        blob = cv2.dnn.blobFromImage(crop, 1.0, (self.size, self.size), (0.0, 0.0, 0.0), swapRB=True)
        pred = self.sess.run([self.output_name], {self.input_name: blob})[0][0]
        pred = pred.reshape((-1, 3))
        if pred.shape[0] > 68:
            pred = pred[-68:, :]
        pred[:, 0:2] += 1
        pred[:, 0:2] *= (self.size // 2)
        pred[:, 2] *= (self.size // 2)
        IM = cv2.invertAffineTransform(M)
        pts = np.zeros(pred.shape, dtype=np.float32)
        s = math.sqrt(IM[0][0] ** 2 + IM[0][1] ** 2)
        for i in range(pred.shape[0]):
            p = IM @ np.array([pred[i][0], pred[i][1], 1.0])
            pts[i][0:2] = p[0:2]
            pts[i][2] = pred[i][2] * s
        # affine camera P from the mean shape to the landmarks, then its rotation, then Euler angles
        X = np.hstack((MEAN_SHAPE_68, np.ones((68, 1), dtype=np.float32)))
        P = np.linalg.lstsq(X, pts, rcond=None)[0].T
        r1, r2 = P[0:1, :3], P[1:2, :3]
        r1 = r1 / np.linalg.norm(r1)
        r2 = r2 / np.linalg.norm(r2)
        R = np.concatenate((r1, r2, np.cross(r1, r2)), 0)
        sy = math.sqrt(R[0, 0] ** 2 + R[1, 0] ** 2)
        if sy >= 1e-6:
            x, y, z = math.atan2(R[2, 1], R[2, 2]), math.atan2(-R[2, 0], sy), math.atan2(R[1, 0], R[0, 0])
        else:
            x, y, z = math.atan2(-R[1, 2], R[1, 1]), math.atan2(-R[2, 0], sy), 0.0
        return [x * 180 / math.pi, y * 180 / math.pi, z * 180 / math.pi]


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
        boxes, kpss = Detector().detect(img)

    usable = []
    for i in range(boxes.shape[0]):
        x0, y0, x1, y1 = [float(v) for v in boxes[i, :4]]
        w, h = (x1 - x0) / width, (y1 - y0) / height
        if w >= min_size and h >= min_size:
            usable.append((w * h, i, (x0 / width, y0 / height, w, h)))

    if not usable:
        print(json.dumps({'ok': False, 'error': 'no face found', 'vector': [], 'faces': int(boxes.shape[0])}), flush=True)
        return

    usable.sort(key=lambda item: item[0], reverse=True)
    _, best, box = usable[0]
    with contextlib.redirect_stdout(sys.stderr):
        embedding = Recognizer().embed(img, kpss[best])
        norm = float(np.linalg.norm(embedding)) or 1.0
        pose = Pose().angles(img, boxes[best, :4]) if os.environ.get('FACE_POSE') == '1' else None
    print(json.dumps({
        'ok': True,
        'vector': [round(float(v) / norm, 6) for v in embedding],
        'score': float(boxes[best, 4]),
        'box': {'x': box[0], 'y': box[1], 'width': box[2], 'height': box[3]},
        'faces': int(boxes.shape[0]),
        'pose': [round(float(v), 1) for v in pose] if pose is not None else None,
    }), flush=True)


if __name__ == '__main__':
    main()
