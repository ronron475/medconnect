"""Gemini generateContent proxy — Hostinger PHP demos use the Railway AI_API_KEY."""

from __future__ import annotations

import asyncio
import logging
from typing import Any

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field

router = APIRouter(tags=["Gemini"])
logger = logging.getLogger("medconnect.api")


class GeminiGenerateRequest(BaseModel):
    """Gemini generateContent body as built by PHP demos."""

    payload: dict[str, Any] = Field(default_factory=dict)
    model: str = ""
    timeout: int = Field(default=15, ge=5, le=30)


async def _gemini_generate(body: GeminiGenerateRequest) -> dict:
    from gemini_client import generate_content

    payload = body.payload if isinstance(body.payload, dict) else {}
    if not payload.get("contents"):
        raise HTTPException(status_code=400, detail="payload.contents is required")

    try:
        pack = await asyncio.to_thread(
            generate_content,
            payload,
            model=body.model or None,
            timeout=body.timeout,
        )
    except RuntimeError as exc:
        msg = str(exc)
        logger.warning("gemini-generate failed: %s", msg)
        code = 503
        if "429" in msg or "quota" in msg.lower():
            code = 429
        elif "401" in msg or "403" in msg or "API key" in msg:
            code = 502
        raise HTTPException(status_code=code, detail=msg[:240]) from exc
    except Exception as exc:
        logger.warning("gemini-generate error: %s", exc)
        raise HTTPException(status_code=503, detail="Gemini proxy temporarily unavailable.") from exc

    text = str(pack.get("text") or "").strip()
    if text == "":
        raise HTTPException(status_code=502, detail="empty Gemini interpretation")

    return {
        "success": True,
        "data": {
            "text": text,
            "model": pack.get("model"),
            "response": pack.get("response"),
        },
    }


@router.post(
    "/gemini/generate",
    summary="Proxy Gemini generateContent (Railway key)",
)
async def gemini_generate(body: GeminiGenerateRequest) -> dict:
    return await _gemini_generate(body)


@router.post(
    "/nlp-step3-demo/gemini-generate",
    summary="Proxy Gemini generateContent for nlp_step3_demo (alias)",
)
async def nlp_step3_demo_gemini_generate(body: GeminiGenerateRequest) -> dict:
    return await _gemini_generate(body)
