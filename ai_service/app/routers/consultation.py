"""Consultation transcript analysis."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException

from app.schemas.analyze import TranscriptRequest
from analyzer import analyze_transcript
from transcriber import WHISPER_MODEL_NAME, dependency_status

router = APIRouter(tags=["Consultation"])
logger = logging.getLogger("medconnect.api")


@router.post("/analyze", summary="Analyze consultation transcript")
async def analyze(body: TranscriptRequest) -> dict:
    status = dependency_status() | {"whisper_model": WHISPER_MODEL_NAME}
    data = await asyncio.to_thread(analyze_transcript, body.transcript, status)
    logger.info("Transcript analyzed (%d chars)", len(body.transcript))
    return {"success": True, "data": data}
