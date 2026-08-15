"""FAQ chatbot assist endpoint for Hostinger PHP."""

from __future__ import annotations

import asyncio
import logging

from fastapi import APIRouter, HTTPException
from pydantic import BaseModel, Field, field_validator

router = APIRouter(tags=["FAQ Chatbot"])
logger = logging.getLogger("medconnect.api")


class FaqChatHistoryTurn(BaseModel):
    role: str = ""
    text: str = ""


class FaqChatAssistRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=800)
    lang: str = "en"
    intent: str = ""
    emotion: str = ""
    topic: str = ""
    history: list[FaqChatHistoryTurn] = Field(default_factory=list)

    @field_validator("text", "lang", "intent", "emotion", "topic")
    @classmethod
    def strip_text(cls, v: str) -> str:
        return (v or "").strip()


@router.post("/faq-chatbot/assist", summary="FAQ chatbot conversational fallback")
async def faq_chatbot_assist(body: FaqChatAssistRequest) -> dict:
    from faq_chatbot import generate_reply

    lang = body.lang.lower() if body.lang.lower() in {"en", "fil", "hil"} else "en"
    history = [{"role": turn.role, "text": turn.text} for turn in body.history[:12]]
    try:
        html = await asyncio.to_thread(
            generate_reply,
            body.text,
            lang,
            intent=body.intent,
            emotion=body.emotion,
            topic=body.topic,
            history=history,
        )
    except Exception as exc:
        logger.warning("FAQ chatbot assist failed: %s", exc)
        raise HTTPException(status_code=503, detail="FAQ assistant is temporarily unavailable.") from exc

    if not html:
        raise HTTPException(status_code=503, detail="FAQ assistant returned an empty reply.")

    logger.info("FAQ chatbot assist ok (%d chars, lang=%s)", len(body.text), lang)
    return {"success": True, "data": {"html": html}}
