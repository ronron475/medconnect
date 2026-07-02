"""Transcription endpoints."""

from __future__ import annotations

import asyncio
import logging
import os
import tempfile
import uuid

from fastapi import APIRouter, File, HTTPException, UploadFile

router = APIRouter(tags=["Transcription"])
logger = logging.getLogger("medconnect.api")


@router.post("/transcribe", summary="Transcribe audio or video")
async def transcribe(
    audio: UploadFile | None = File(None),
    video: UploadFile | None = File(None),
) -> dict:
    uploaded = audio if audio and audio.filename else video
    if uploaded is None or not uploaded.filename:
        raise HTTPException(status_code=400, detail="Audio or video file is required.")

    suffix = os.path.splitext(uploaded.filename or "")[1] or ".webm"
    temp_path = os.path.join(tempfile.gettempdir(), f"medconnect_{uuid.uuid4().hex}{suffix}")

    try:
        content = await uploaded.read()
        with open(temp_path, "wb") as handle:
            handle.write(content)

        from transcriber import transcribe_file

        data = await asyncio.to_thread(transcribe_file, temp_path)
        logger.info("Transcription completed for %s", uploaded.filename)
        return {"success": True, "data": data}
    except Exception as exc:
        logger.exception("Transcription failed")
        raise HTTPException(status_code=500, detail=f"Transcription failed: {exc}") from exc
    finally:
        try:
            os.remove(temp_path)
        except OSError:
            pass
