"""Fuzzy matching HTTP endpoints (replaces fuzzy_match_cli.py proc_open)."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter

from app.schemas.fuzzy import FuzzyProfileRequest, FuzzyTextQueueRequest
from medical_fuzzy_matcher import match_profile, match_text_queue

router = APIRouter(prefix="/fuzzy", tags=["Fuzzy Matching"])
logger = logging.getLogger("medconnect.api")


@router.post("/match-profile", summary="Fuzzy match profile translation")
async def fuzzy_match_profile(body: FuzzyProfileRequest) -> dict:
    result = await asyncio.to_thread(match_profile, body.translation)
    logger.info("Fuzzy profile match completed")
    return result


@router.post("/match-text-queue", summary="Fuzzy match text analysis queue")
async def fuzzy_match_text_queue(body: FuzzyTextQueueRequest) -> dict:
    queue = [item.model_dump() if hasattr(item, "model_dump") else dict(item) for item in body.text_queue]
    result = await asyncio.to_thread(match_text_queue, queue)
    logger.info("Fuzzy text queue match completed (%d items)", len(queue))
    return result
