"""National ID OCR endpoints."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, File, HTTPException, UploadFile

from app.core.config import get_settings
from ocr.service import extract_from_file

router = APIRouter(prefix="/ocr", tags=["OCR"])
logger = logging.getLogger("medconnect.api")


@router.get("/health", summary="OCR module health")
def ocr_health() -> dict:
    return {
        "status": "ok",
        "service": "medconnect-ocr",
        "engine": "fastapi",
    }


@router.post("/extract", summary="Extract PhilSys National ID fields")
async def ocr_extract(national_id_image: UploadFile = File(...)) -> dict:
    settings = get_settings()
    if not national_id_image.filename:
        raise HTTPException(status_code=400, detail="No file uploaded.")

    content_type = (national_id_image.content_type or "").lower()
    allowed = {"image/jpeg", "image/png", "application/pdf"}
    if content_type not in allowed:
        raise HTTPException(status_code=400, detail="Invalid file type. Allowed: JPG, PNG, PDF.")

    import os
    import tempfile
    from pathlib import Path

    suffix = Path(national_id_image.filename).suffix or ".jpg"
    fd, tmp_path = tempfile.mkstemp(suffix=suffix, prefix="ocr_upload_")
    os.close(fd)
    size = 0

    try:
        with open(tmp_path, "wb") as out:
            while True:
                chunk = await national_id_image.read(256 * 1024)
                if not chunk:
                    break
                size += len(chunk)
                if size > settings.ocr_max_file_size:
                    raise HTTPException(status_code=400, detail="File is too large. Maximum allowed size is 5 MB.")
                out.write(chunk)

        result = await asyncio.to_thread(extract_from_file, tmp_path, content_type)
        if not result.get("success"):
            raise HTTPException(
                status_code=422,
                detail=result.get("message", "OCR extraction failed."),
            )
        logger.info("OCR extract completed for %s", national_id_image.filename)
        return result
    finally:
        try:
            Path(tmp_path).unlink(missing_ok=True)
        except OSError:
            pass
