"""National ID OCR extraction pipeline."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

from ocr.ocr_space_client import friendly_error, ocr_response_failed, call_ocr_space
from ocr.philsys_parser import extract_all
from ocr.preprocessor import preprocess_image

OCR_DEBUG = os.environ.get("OCR_DEBUG", "0").lower() in ("1", "true", "yes")


def build_extract_response(
    parsed_text: str,
    preprocessing_used: str,
    *,
    cached: bool = False,
) -> dict[str, Any]:
    extraction = extract_all(parsed_text)
    low = extraction["low_confidence"]
    response: dict[str, Any] = {
        "success": True,
        "mode": "extract",
        "engine": "fastapi",
        "extracted": extraction["fields"],
        "overall_confidence": extraction["overall_confidence"],
        "low_confidence": low,
        "confidence_ok": not low,
        "preprocessing_used": preprocessing_used,
        "parsed_text": parsed_text if OCR_DEBUG else None,
        "cached": cached,
    }
    if low:
        response["message"] = (
            "We could not read your National ID with enough confidence. "
            "Please upload a clearer photo taken in good lighting."
        )
        response["extracted"] = {
            k: {"value": "", "confidence": v["confidence"], "source": v["source"]}
            for k, v in extraction["fields"].items()
        }
    else:
        response["message"] = (
            "National ID information extracted successfully. Please review the auto-filled fields."
        )
    return response


def extract_from_file(file_path: str, mime_type: str) -> dict[str, Any]:
    is_pdf = mime_type == "application/pdf"
    temp_path: str | None = None
    stage = "none"

    try:
        if not is_pdf:
            processed_path, processed_mime, stage = preprocess_image(file_path, mime_type)
            if processed_path != file_path:
                temp_path = processed_path
            ocr_path, ocr_mime = processed_path, processed_mime
        else:
            ocr_path, ocr_mime = file_path, mime_type

        parsed_text = ""
        ocr_note = ""
        for engine in (1, 2):
            ocr = call_ocr_space(ocr_path, ocr_mime, engine)
            if ocr is None:
                continue
            if ocr_response_failed(ocr):
                if engine == 2:
                    return {"success": False, "message": friendly_error(ocr or {})}
                continue
            results = ocr.get("ParsedResults") or []
            if results:
                parsed_text = str(results[0].get("ParsedText") or "").strip()
            if parsed_text:
                ocr_note = f"engine_{engine}"
                break

        if not parsed_text:
            return {
                "success": False,
                "message": (
                    "We couldn't accurately read your National ID. "
                    "Please upload a clearer photo taken in good lighting."
                ),
            }

        preprocessing_used = stage if stage != "none" else ocr_note
        if stage != "none" and ocr_note:
            preprocessing_used = f"{stage}+{ocr_note}"

        return build_extract_response(parsed_text, preprocessing_used)
    finally:
        if temp_path and Path(temp_path).exists():
            try:
                Path(temp_path).unlink()
            except OSError:
                pass
