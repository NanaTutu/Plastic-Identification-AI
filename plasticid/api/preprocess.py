import io
import os

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError

MAX_IMAGE_EDGE = max(64, int(os.getenv("MAX_IMAGE_EDGE", "1280")))
MAX_IMAGE_PIXELS = max(1_000_000, int(os.getenv("MAX_IMAGE_PIXELS", "40000000")))
NORMALIZE_WB = os.getenv("NORMALIZE_WB", "1") == "1"
Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS


def open_image(img_bytes: bytes) -> Image.Image:
    try:
        with Image.open(io.BytesIO(img_bytes)) as source:
            if source.format not in {"JPEG", "PNG"}:
                raise ValueError(f"Unsupported image format: {source.format or 'unknown'}")
            if source.width * source.height > MAX_IMAGE_PIXELS:
                raise ValueError("Image dimensions exceed the configured limit")
            img = source.convert("RGB")
        return ImageOps.exif_transpose(img)
    except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError) as e:
        raise ValueError(f"Invalid image: {e}") from e


def _auto_white_balance(img: Image.Image) -> Image.Image:
    if not NORMALIZE_WB:
        return img
    try:
        arr = np.asarray(img, dtype=np.float32)
        if arr.ndim != 3 or arr.shape[2] != 3:
            return img
        means = arr.mean(axis=(0, 1))
        overall = means.mean()
        if overall < 1.0 or (means > 0).sum() != 3:
            return img
        gains = np.clip(overall / np.maximum(means, 1e-5), 0.5, 2.5)
        balanced = np.clip(arr * gains, 0, 255).astype(np.uint8)
        return Image.fromarray(balanced)
    except Exception:
        return img


def prepare_image(img_bytes: bytes) -> tuple[Image.Image, float, float]:
    img = open_image(img_bytes)
    orig_w, orig_h = img.size

    scale_x, scale_y = 1.0, 1.0
    edge = max(orig_w, orig_h)
    if edge > MAX_IMAGE_EDGE:
        factor = MAX_IMAGE_EDGE / edge
        new_w = int(orig_w * factor)
        new_h = int(orig_h * factor)
        if new_w > 0 and new_h > 0:
            img = img.resize((new_w, new_h), Image.LANCZOS)
            scale_x = orig_w / new_w
            scale_y = orig_h / new_h

    img = _auto_white_balance(img)

    return img, scale_x, scale_y


def rescale_bbox(bbox: list[float], scale_x: float, scale_y: float) -> list[float]:
    return [
        round(bbox[0] * scale_x, 1),
        round(bbox[1] * scale_y, 1),
        round(bbox[2] * scale_x, 1),
        round(bbox[3] * scale_y, 1),
    ]