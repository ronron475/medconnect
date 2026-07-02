"""Medical profile NLP validation."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException

from app.schemas.analyze import MedicalProfileRequest
from analyzer import analyze_medical_profile
from transcriber import WHISPER_MODEL_NAME, dependency_status

router = APIRouter(tags=["Profile"])
logger = logging.getLogger("medconnect.api")


@router.post("/analyze-medical-profile", summary="Validate medical profile fields")
async def analyze_medical_profile_endpoint(body: MedicalProfileRequest) -> dict:
    if not body.allergies and not body.current_medications:
        raise HTTPException(
            status_code=400,
            detail="Enter allergies and/or current medications.",
        )
    status = dependency_status() | {"whisper_model": WHISPER_MODEL_NAME}
    data = await asyncio.to_thread(
        analyze_medical_profile,
        body.allergies,
        body.current_medications,
        status,
    )
    logger.info("Medical profile analyzed")
    return {"success": True, "data": data}
