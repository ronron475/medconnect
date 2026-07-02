"""OCR.Space API client."""

from __future__ import annotations

import base64
import os
from typing import Any

import httpx

OCR_SPACE_ENDPOINT = os.environ.get(
    "OCR_SPACE_ENDPOINT", "https://api.ocr.space/parse/image"
)


def _api_key() -> str:
    key = os.environ.get("OCR_SPACE_API_KEY", "").strip()
    if key:
        return key
    # Optional: read from PHP config when running locally on XAMPP
    config_path = os.path.join(
        os.path.dirname(__file__), os.pardir, os.pardir, "config", "ocr_config.php"
    )
    try:
        text = open(config_path, encoding="utf-8", errors="ignore").read()
        import re
        m = re.search(r"OCR_SPACE_API_KEY['\"]\s*,\s*['\"]([^'\"]+)", text)
        if m:
            return m.group(1).strip()
    except OSError:
        pass
    return ""


def ocr_response_failed(ocr: dict[str, Any]) -> bool:
    if ocr.get("IsErroredOnProcessing"):
        return True
    exit_code = ocr.get("OCRExitCode", 0)
    if isinstance(exit_code, int) and exit_code >= 4:
        return True
    msgs = ocr.get("ErrorMessage") or []
    if isinstance(msgs, str):
        msgs = [msgs]
    for msg in msgs:
        ml = str(msg).lower()
        if any(x in ml for x in ("e500", "binary", "resource", "exhaustion", "timeout")):
            return True
    return False


def friendly_error(ocr: dict[str, Any]) -> str:
    msgs = ocr.get("ErrorMessage") or []
    if isinstance(msgs, str):
        msgs = [msgs]
    raw = " ".join(str(m) for m in msgs).lower()
    if any(x in raw for x in ("e500", "binary", "resource", "exhaustion")):
        return (
            "The OCR service could not process the image right now due to a resource issue. "
            "Please try again with a smaller or clearer JPG file."
        )
    if "timeout" in raw:
        return "The OCR service timed out. Please try again with a smaller file."
    return "The OCR service could not read the uploaded ID. Please try again with a clearer photo."


def call_ocr_space(file_path: str, mime: str, engine: int = 1) -> dict[str, Any] | None:
    api_key = _api_key()
    if not api_key:
        return {"IsErroredOnProcessing": True, "ErrorMessage": ["OCR_SPACE_API_KEY not configured"]}

    with open(file_path, "rb") as fh:
        raw = fh.read()
    b64 = base64.b64encode(raw).decode("ascii")
    data_url = f"data:{mime};base64,{b64}"

    try:
        with httpx.Client(timeout=60.0) as client:
            resp = client.post(
                OCR_SPACE_ENDPOINT,
                data={
                    "apikey": api_key,
                    "language": "eng",
                    "OCREngine": str(engine),
                    "scale": "true",
                    "isOverlayRequired": "false",
                    "detectOrientation": "true",
                    "isTable": "false",
                    "base64Image": data_url,
                },
                headers={"Content-Type": "application/x-www-form-urlencoded"},
            )
            resp.raise_for_status()
            return resp.json()
    except Exception:
        return None


def extract_text(file_path: str, mime: str) -> tuple[str, str]:
    """Run OCR and return (parsed_text, preprocessing_note)."""
    for engine in (1, 2):
        ocr = call_ocr_space(file_path, mime, engine)
        if ocr is None or ocr_response_failed(ocr):
            continue
        text = ""
        results = ocr.get("ParsedResults") or []
        if results:
            text = str(results[0].get("ParsedText") or "").strip()
        if text:
            return text, f"engine_{engine}"
    return "", "failed"
