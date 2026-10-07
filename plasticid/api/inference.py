import logging
import os
import re
import threading
import time
from pathlib import Path

from fastapi import UploadFile, HTTPException
from PIL import Image
from ultralytics import YOLO

from api.preprocess import open_image, prepare_image, rescale_bbox

ALLOWED_TYPES = {"image/jpeg", "image/png", "image/jpg"}
MAX_FILE_SIZE = 10 * 1024 * 1024
IMGSZ = 640

try:
    import onnxruntime  # noqa: F401
    ONNX_AVAILABLE = True
except ImportError:
    ONNX_AVAILABLE = False

ONNX_INFERENCE = os.getenv("ONNX_INFERENCE", "0") == "1"

logger = logging.getLogger("plasticid-api")

APP_DIR = Path(__file__).resolve().parent.parent
PROJECT_DIR = Path(__file__).resolve().parents[2]
MODELS_DIR = APP_DIR / "models"


def _default_detector_path() -> str:
    candidates = (
        MODELS_DIR / "yolov8n.pt",
        APP_DIR / "yolov8n.pt",
        PROJECT_DIR / "yolov8n.pt",
    )
    for candidate in candidates:
        if candidate.is_file():
            return str(candidate)
    return str(candidates[-1])


MODEL_PATH = os.getenv("MODEL_PATH") or str(MODELS_DIR / "best_v8m.pt")
DETECTOR_PATH = os.getenv("DETECTOR_PATH") or _default_detector_path()
MODEL_NAME = Path(MODEL_PATH).name
DETECTOR_NAME = Path(DETECTOR_PATH).name
TORCH_MODEL_PATH = Path(MODEL_PATH)
TORCH_DETECTOR_PATH = Path(DETECTOR_PATH)

_PREDICT_LOCK = threading.Lock()

TARGET_OBJECTS = [
    "bottle",
    "cup",
    "bowl",
    "wine glass",
    "vase"
]

classifier_model: YOLO | None = None
detector_model: YOLO | None = None
INFERENCE_MODE = "torch"
MODEL_LOADED = False


def is_model_loaded() -> bool:
    return MODEL_LOADED


def _export_onnx(pt_path: Path) -> Path:
    onnx_path = pt_path.with_suffix(".onnx")
    if not onnx_path.exists():
        exporter = YOLO(str(pt_path))
        exporter.export(
            format="onnx",
            imgsz=IMGSZ,
            opset=12,
            simplify=False,
            dynamic=False,
        )
    return onnx_path


def _load_onnx_models() -> None:
    global classifier_model, detector_model, INFERENCE_MODE
    classifier_model = YOLO(str(_export_onnx(TORCH_MODEL_PATH)))
    detector_model = YOLO(str(_export_onnx(TORCH_DETECTOR_PATH)))
    INFERENCE_MODE = "onnx"


def _load_torch_models() -> None:
    global classifier_model, detector_model, INFERENCE_MODE
    classifier_model = YOLO(str(TORCH_MODEL_PATH))
    detector_model = YOLO(str(TORCH_DETECTOR_PATH))
    INFERENCE_MODE = "torch"


def _predict(yolo: YOLO, source, conf: float):
    with _PREDICT_LOCK:
        if INFERENCE_MODE == "onnx":
            return yolo.predict(source=source, conf=conf, verbose=False)
        return yolo.predict(source=source, conf=conf, device="cpu", verbose=False)


def _require_weights() -> None:
    missing = [
        str(path)
        for path in (TORCH_MODEL_PATH, TORCH_DETECTOR_PATH)
        if not path.is_file()
    ]
    if missing:
        raise FileNotFoundError(
            "Missing model weights: " + ", ".join(missing)
        )


def warmup() -> str:
    global INFERENCE_MODE, classifier_model, detector_model, MODEL_LOADED
    if classifier_model is not None and detector_model is not None:
        MODEL_LOADED = True
        return INFERENCE_MODE

    _require_weights()

    if ONNX_INFERENCE and ONNX_AVAILABLE:
        try:
            _load_onnx_models()
        except Exception as e:
            logger.warning(f"ONNX initialization failed, falling back to PyTorch: {e}")
            classifier_model = None
            detector_model = None
            INFERENCE_MODE = "torch"

    if classifier_model is None or detector_model is None:
        _load_torch_models()

    blank = Image.new("RGB", (64, 64))
    _predict(classifier_model, blank, 0.25)
    _predict(detector_model, blank, 0.25)
    MODEL_LOADED = True
    logger.info(f"Inference engine ready (backend={INFERENCE_MODE})")
    return INFERENCE_MODE


def validate_file(file: UploadFile):
    content_type = (file.content_type or "").split(";", 1)[0].strip().lower()
    if content_type not in ALLOWED_TYPES:
        raise HTTPException(
            status_code=400,
            detail=f"Invalid file type. Allowed: {', '.join(sorted(ALLOWED_TYPES))}"
        )


def validate_image_content(img_bytes: bytes) -> bool:
    try:
        open_image(img_bytes)
        return True
    except Exception:
        return False


def sanitize_job_id(job_id: str) -> str:
    sanitized = re.sub(r'[^a-zA-Z0-9_-]', '', job_id)
    if not sanitized:
        sanitized = f"job_{int(time.time())}"
    return sanitized


def _cap_edge(img: Image.Image, max_edge: int = IMGSZ):
    w, h = img.size
    edge = max(w, h)
    if edge <= max_edge:
        return img, 1.0, 1.0
    factor = max_edge / edge
    nw = max(1, int(w * factor))
    nh = max(1, int(h * factor))
    resized = img.resize((nw, nh), Image.LANCZOS)
    return resized, w / nw, h / nh


def _scale_boxes(raw_boxes, cap_fx, cap_fy, scale_x, scale_y):
    scaled = []
    for box in raw_boxes:
        x1, y1, x2, y2 = box
        scaled.append(
            rescale_bbox(
                [x1 * cap_fx, y1 * cap_fy, x2 * cap_fx, y2 * cap_fy],
                scale_x,
                scale_y,
            )
        )
    return scaled


def _classify(cropped: Image.Image, cap_fx: float, cap_fy: float, scale_x: float, scale_y: float):
    results = _predict(classifier_model, cropped, 0.25)
    detections = []
    for r in results:
        if not r.boxes:
            continue
        for b in r.boxes:
            cls = int(b.cls.item())
            raw = [float(v) for v in b.xyxy[0].tolist()]
            detections.append({
                "class_name": classifier_model.names[cls],
                "confidence": round(float(b.conf.item()), 4),
                "bbox": _scale_boxes([raw], cap_fx, cap_fy, scale_x, scale_y)[0],
            })
    return detections


def run_inference(img_bytes: bytes):
    img, scale_x, scale_y = prepare_image(img_bytes)

    best_conf = 0
    best_box = None
    detected_object = "unknown"

    detector_results = _predict(detector_model, img, 0.25)
    for r in detector_results:
        if not r.boxes:
            continue
        for b in r.boxes:
            class_name = detector_model.names[int(b.cls.item())]
            if class_name not in TARGET_OBJECTS:
                continue
            conf = float(b.conf.item())
            if conf > best_conf:
                best_conf = conf
                best_box = [float(v) for v in b.xyxy[0].tolist()]
                detected_object = class_name

    cropped = img
    cap_fx, cap_fy = 1.0, 1.0
    if best_box is not None:
        x1, y1, x2, y2 = map(int, best_box)
        cropped = img.crop((x1, y1, x2, y2))
        cropped, cap_fx, cap_fy = _cap_edge(cropped)

    detections = _classify(cropped, cap_fx, cap_fy, scale_x, scale_y)

    return detections, detected_object


def run_simple_inference(img_bytes: bytes):
    img, scale_x, scale_y = prepare_image(img_bytes)
    img, cap_fx, cap_fy = _cap_edge(img)

    detections = _classify(img, cap_fx, cap_fy, scale_x, scale_y)
    for d in detections:
        d["class"] = d.pop("class_name")
        d["bbox"] = [
            round(d["bbox"][0], 1),
            round(d["bbox"][1], 1),
            round(d["bbox"][2], 1),
            round(d["bbox"][3], 1),
        ]

    return detections