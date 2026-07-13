"""National ID OCR extraction pipeline."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

from ocr.ocr_space_client import friendly_error, ocr_response_failed, call_ocr_space
from ocr.philsys_parser import extract_all
from ocr.preprocessor import preprocess_variants

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


def _score_extraction(extraction: dict[str, Any]) -> float:
    fields = extraction.get("fields") or {}
    required = ("first_name", "last_name", "date_of_birth", "national_id")
    filled = sum(1 for key in required if (fields.get(key) or {}).get("value"))
    return float(extraction.get("overall_confidence") or 0.0) + (filled * 0.12)


def extract_from_file(file_path: str, mime_type: str) -> dict[str, Any]:
    is_pdf = mime_type == "application/pdf"
    temp_paths: list[str] = []

    try:
        if is_pdf:
            variants = [(file_path, mime_type, "pdf")]
        else:
            variants = preprocess_variants(file_path, mime_type)
            for path, _, stage in variants:
                if stage != "none" and path != file_path:
                    temp_paths.append(path)

        best_text = ""
        best_stage = "none"
        best_score = -1.0
        best_extraction: dict[str, Any] | None = None
        had_response = False

        for ocr_path, ocr_mime, stage in variants:
            for engine in (2, 1):
                ocr = call_ocr_space(ocr_path, ocr_mime, engine)
                if ocr is None:
                    continue
                had_response = True
                if ocr_response_failed(ocr):
                    continue
                results = ocr.get("ParsedResults") or []
                parsed_text = ""
                if results:
                    parsed_text = str(results[0].get("ParsedText") or "").strip()
                if not parsed_text:
                    continue

                extraction = extract_all(parsed_text)
                score = _score_extraction(extraction)
                stage_note = f"{stage}+engine_{engine}" if stage != "none" else f"engine_{engine}"
                if score > best_score:
                    best_score = score
                    best_text = parsed_text
                    best_stage = stage_note
                    best_extraction = extraction
                if score >= 0.95 and not extraction.get("low_confidence"):
                    break
            if best_score >= 0.95 and best_extraction and not best_extraction.get("low_confidence"):
                break

        if not best_text or best_extraction is None:
            if not had_response:
                return {
                    "success": False,
                    "message": "Could not reach the OCR service. Please check your connection and try again.",
                }
            return {
                "success": False,
                "message": (
                    "We couldn't accurately read your National ID. "
                    "Please upload a clearer photo taken in good lighting."
                ),
            }

        return build_extract_response(best_text, best_stage)
    finally:
        for path in temp_paths:
            try:
                Path(path).unlink(missing_ok=True)
            except OSError:
                pass
