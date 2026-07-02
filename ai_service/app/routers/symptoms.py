"""Symptom recognition endpoints."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException

from app.schemas.analyze import RecognizeSymptomsRequest

router = APIRouter(tags=["Symptoms"])
logger = logging.getLogger("medconnect.api")


@router.post("/recognize-symptoms", summary="Recognize Hiligaynon symptoms")
async def recognize_symptoms_endpoint(body: RecognizeSymptomsRequest) -> dict:
    text = body.resolved_text()
    if not text:
        raise HTTPException(status_code=400, detail="Text is required.")

    from hiligaynon_symptom_matcher import recognize_symptoms

    result = await asyncio.to_thread(recognize_symptoms, text, body.fuzzy_threshold)
    count = result.get("detection_count", 0)
    logger.info("Recognized %s symptom(s)", count)
    return {
        "success": True,
        "status": "success",
        "message": f"{count} symptom(s) detected.",
        "data": result,
    }
