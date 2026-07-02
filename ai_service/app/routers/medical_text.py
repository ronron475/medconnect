"""Medical text NLP analysis."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter

from app.schemas.analyze import MedicalTextRequest
from medical_text_analysis import analyze_medical_text
from transcriber import WHISPER_MODEL_NAME, dependency_status

router = APIRouter(tags=["Medical Text"])
logger = logging.getLogger("medconnect.api")


@router.post("/analyze-medical-text", summary="Analyze Hiligaynon/English medical text")
async def analyze_medical_text_endpoint(body: MedicalTextRequest) -> dict:
    status = dependency_status() | {"whisper_model": WHISPER_MODEL_NAME}
    data = await asyncio.to_thread(analyze_medical_text, body.text, status)
    logger.info("Medical text analyzed (%d chars)", len(body.text))
    return {"success": True, "data": data}
