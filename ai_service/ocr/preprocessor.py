"""Image preprocessing for National ID OCR."""

from __future__ import annotations

import os
import tempfile
from pathlib import Path
from typing import List, Tuple

from PIL import Image, ImageEnhance, ImageFilter, ImageOps

Variant = Tuple[str, str, str]


def _save_variant(img: Image.Image, stage_label: str) -> Variant | None:
    w, h = img.size
    target_w = 1000 if w < 1000 else (1800 if w > 1800 else w)
    stage_scale = ""
    if target_w != w:
        new_h = int(h * (target_w / w))
        img = img.resize((target_w, new_h), Image.Resampling.LANCZOS)
        stage_scale = "upscaled" if w < 1000 else "downscaled"

    gray = img.convert("L")
    contrast = ImageEnhance.Contrast(gray).enhance(1.4)
    sharp = contrast.filter(ImageFilter.SHARPEN)
    bright = ImageEnhance.Brightness(sharp).enhance(1.05)

    fd, out_path = tempfile.mkstemp(suffix=".jpg", prefix="ocr_fastapi_")
    os.close(fd)
    stages = [s for s in (stage_scale, stage_label, "grayscale", "contrast", "sharpen") if s]
    for quality in (88, 75, 60):
        bright.save(out_path, "JPEG", quality=quality, optimize=True)
        if Path(out_path).stat().st_size <= 900 * 1024:
            break
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
