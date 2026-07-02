"""Image preprocessing for National ID OCR."""

from __future__ import annotations

import tempfile
from pathlib import Path
from typing import Tuple

from PIL import Image, ImageEnhance, ImageFilter


def preprocess_image(file_path: str, mime_type: str) -> Tuple[str, str, str]:
    """
    Returns (processed_path, mime, stage_description).
    Falls back to original file when preprocessing is unavailable.
    """
    if mime_type not in ("image/jpeg", "image/png"):
        return file_path, mime_type, "none"

    try:
        with Image.open(file_path) as img:
            img = img.convert("RGB")
            w, h = img.size
            target_w = 1000 if w < 1000 else (1800 if w > 1800 else w)
            if target_w != w:
                new_h = int(h * (target_w / w))
                img = img.resize((target_w, new_h), Image.Resampling.LANCZOS)
                stage_scale = "upscaled" if w < 1000 else "downscaled"
            else:
                stage_scale = ""

            gray = img.convert("L")
            contrast = ImageEnhance.Contrast(gray).enhance(1.35)
            sharp = contrast.filter(ImageFilter.SHARPEN)
            bright = ImageEnhance.Brightness(sharp).enhance(1.05)

            fd, out_path = tempfile.mkstemp(suffix=".jpg", prefix="ocr_fastapi_")
            import os
            os.close(fd)
            stages = [s for s in (stage_scale, "grayscale", "contrast", "sharpen") if s]
            for quality in (85, 70, 55):
                bright.save(out_path, "JPEG", quality=quality, optimize=True)
                if Path(out_path).stat().st_size <= 900 * 1024:
                    break
            return out_path, "image/jpeg", "+".join(stages) if stages else "processed"
    except Exception:
        return file_path, mime_type, "none"
