"""Image preprocessing for National ID OCR."""

from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import List, Tuple

from PIL import Image, ImageEnhance, ImageFilter, ImageOps

Variant = Tuple[str, str, str]


def _fit_width(img: Image.Image) -> tuple[Image.Image, str]:
    w, h = img.size
    target_w = 1000 if w < 1000 else (1800 if w > 1800 else w)
    if target_w == w:
        return img, ""
    new_h = int(h * (target_w / w))
    resized = img.resize((target_w, new_h), Image.Resampling.LANCZOS)
    return resized, "upscaled" if w < 1000 else "downscaled"


def _write_jpeg(img: Image.Image) -> str | None:
    fd, out_path = tempfile.mkstemp(suffix=".jpg", prefix="ocr_fastapi_")
    os.close(fd)
    for quality in (88, 75, 60):
        img.save(out_path, "JPEG", quality=quality, optimize=True)
        if Path(out_path).stat().st_size <= 900 * 1024:
            return out_path
    Path(out_path).unlink(missing_ok=True)
    return None


def _save_color_variant(img: Image.Image, stage_label: str) -> Variant | None:
    """Color copy first. Grayscale contrast wipes text on a PhilID hologram."""
    fitted, stage_scale = _fit_width(img.convert("RGB"))
    out_path = _write_jpeg(fitted)
    if not out_path:
        return None
    stages = [s for s in (stage_scale, stage_label, "color") if s]
    return out_path, "image/jpeg", "+".join(stages)


def _save_variant(img: Image.Image, stage_label: str) -> Variant | None:
    img, stage_scale = _fit_width(img)
    gray = img.convert("L")
    contrast = ImageEnhance.Contrast(gray).enhance(1.4)
    sharp = contrast.filter(ImageFilter.SHARPEN)
    bright = ImageEnhance.Brightness(sharp).enhance(1.05)

    out_path = _write_jpeg(bright)
    if not out_path:
        return None
    stages = [s for s in (stage_scale, stage_label, "grayscale", "contrast", "sharpen") if s]
    return out_path, "image/jpeg", "+".join(stages)


def preprocess_variants(file_path: str, mime_type: str) -> List[Variant]:
    """
    Build OCR image variants: EXIF-corrected plus 90°/270° for portrait ID photos.
  Returns list of (processed_path, mime, stage_description).
    """
    if mime_type not in ("image/jpeg", "image/png"):
        return [(file_path, mime_type, "none")]

    variants: List[Variant] = []
    try:
        with Image.open(file_path) as raw:
            img = ImageOps.exif_transpose(raw.convert("RGB"))
            w, h = img.size
            angles = [0]
            if h > int(w * 1.05):
                angles.extend([90, 270])

            for angle in angles:
                working = img.rotate(-angle, expand=True) if angle else img
                label = "exif" if angle == 0 else f"rot{angle}"
                color = _save_color_variant(working, label)
                if color:
                    variants.append(color)
                variant = _save_variant(working, label)
                if variant:
                    variants.append(variant)
    except Exception:
        return [(file_path, mime_type, "none")]

    return variants or [(file_path, mime_type, "none")]


def preprocess_image(file_path: str, mime_type: str) -> Tuple[str, str, str]:
    """Primary variant (first from preprocess_variants)."""
    variants = preprocess_variants(file_path, mime_type)
    return variants[0]
