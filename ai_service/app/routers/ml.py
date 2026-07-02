"""Disease prediction ML endpoints."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException

from app.schemas.analyze import PredictDiseaseRequest
from analyzer import translate_hiligaynon
from disease_predictor import enrich_transcript_analysis, model_available

router = APIRouter(tags=["Machine Learning"])
logger = logging.getLogger("medconnect.api")


@router.post("/predict-disease", summary="Predict disease from symptoms")
async def predict_disease(body: PredictDiseaseRequest) -> dict:
    text = body.resolved_text()
    if not text and not body.symptoms:
        raise HTTPException(status_code=400, detail="Provide text and/or a symptoms list.")

    if not model_available():
        raise HTTPException(
            status_code=503,
            detail="Disease model not trained. Run ai_service/train_disease_classifier.py",
        )

    english = await asyncio.to_thread(translate_hiligaynon, text) if text else ""
    data = await asyncio.to_thread(
        enrich_transcript_analysis, english, body.symptoms, body.urgent_flags
    )
    logger.info("Disease prediction completed")
    return {"success": True, "data": data}
