"""OCR.Space API client."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

import httpx

OCR_SPACE_ENDPOINT = os.environ.get(
    "OCR_SPACE_ENDPOINT", "https://api.ocr.space/parse/image"
)


def _api_key() -> str:
    """Load OCR.Space key from process environment only (never from PHP source)."""
    return os.environ.get("OCR_SPACE_API_KEY", "").strip()


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


def _ssl_verify() -> bool | str:
    """Use the project CA bundle. Windows Python often lacks the system store."""
    ca = Path(__file__).resolve().parents[2] / "config" / "ssl" / "cacert.pem"
    if ca.is_file():
        return str(ca)
    return True


def _filetype(mime: str) -> str:
    if mime == "image/png":
        return "PNG"
    if mime == "application/pdf":
        return "PDF"
    return "JPG"


def call_ocr_space(
    file_path: str,
    mime: str,
    engine: int = 1,
    *,
    detect_orientation: bool = False,
) -> dict[str, Any] | None:
    """Multipart upload. Base64 inflates the file past the free-tier 1 MB limit."""
    api_key = _api_key()
    if not api_key:
        return {"IsErroredOnProcessing": True, "ErrorMessage": ["OCR_SPACE_API_KEY not configured"]}

    filename = "national-id" + (".pdf" if mime == "application/pdf" else ".jpg")
    try:
        with open(file_path, "rb") as fh:
            raw = fh.read()
        with httpx.Client(timeout=60.0, verify=_ssl_verify()) as client:
            resp = client.post(
                OCR_SPACE_ENDPOINT,
                data={
                    "apikey": api_key,
                    "language": "eng",
                    "OCREngine": str(engine),
                    "scale": "true",
                    "isOverlayRequired": "true",
                    "detectOrientation": "true" if detect_orientation else "false",
                    "isTable": "false",
                    "filetype": _filetype(mime),
                },
                files={"file": (filename, raw, mime or "image/jpeg")},
            )
            resp.raise_for_status()
            payload = resp.json()
            results = payload.get("ParsedResults") or []
            if results and not str(results[0].get("ParsedText") or "").strip():
                lines = []
                for line in ((results[0].get("TextOverlay") or {}).get("Lines") or []):
                    words = [str(w.get("WordText") or "") for w in (line.get("Words") or [])]
                    joined = " ".join(w for w in words if w).strip()
                    if joined:
                        lines.append(joined)
                if lines:
                    results[0]["ParsedText"] = "\n".join(lines)
            return payload
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
