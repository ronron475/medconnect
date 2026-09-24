"""Care Tips Cohere Rerank proxy for Hostinger PHP → Railway."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, field_validator

router = APIRouter(tags=["Care Tips"])
logger = logging.getLogger("medconnect.api")


class CareTipsRerankRequest(BaseModel):
    query: str = Field(..., min_length=1, max_length=1000)
    documents: list[str] = Field(..., min_length=1, max_length=80)
    top_n: int = Field(default=3, ge=1, le=10)
    model: str = ""
    timeout: int = Field(default=12, ge=5, le=30)

    @field_validator("query", "model")
    @classmethod
    def strip_text(cls, v: str) -> str:
        return (v or "").strip()

    @field_validator("documents")
    @classmethod
    def clean_docs(cls, docs: list[str]) -> list[str]:
        cleaned = [str(d).strip() for d in docs if str(d).strip()]
        if not cleaned:
            raise ValueError("documents must contain at least one non-empty string")
        return cleaned[:80]


@router.post("/care-tips/rerank", summary="Rerank existing Care Tip CSV candidates (Cohere)")
async def care_tips_rerank(body: CareTipsRerankRequest) -> dict:
    """Rank existing documents only. Never generates or rewrites Care Tip text."""
    from cohere_rerank import cohere_rerank_enabled, rerank_documents

    if not cohere_rerank_enabled():
        raise HTTPException(
            status_code=503,
            detail="Cohere rerank is not configured on Railway (set COHERE_API_KEY).",
        )

    try:
        results = await asyncio.to_thread(
            rerank_documents,
            body.query,
            body.documents,
            body.top_n,
            body.model or None,
            body.timeout,
        )
    except Exception as exc:
        logger.warning("Care tips Cohere rerank failed: %s", exc)
        raise HTTPException(status_code=503, detail="Cohere rerank temporarily unavailable.") from exc

    return {
        "success": True,
        "data": {
            "results": results,
            "count": len(results),
        },
    }
