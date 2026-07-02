"""Root and service info endpoints."""

from __future__ import annotations

from fastapi import APIRouter

router = APIRouter(tags=["Root"])


@router.get("/", summary="Service information")
def root() -> dict:
    return {
        "success": True,
        "service": "medconnect-ai",
        "engine": "fastapi",
        "message": "AI service is running. Use /health to check status.",
        "endpoints": {
            "GET": ["/health", "/api/health", "/groq_health", "/api/groq_health", "/docs", "/redoc"],
            "POST": [
                "/analyze",
                "/predict-disease",
                "/transcribe",
                "/analyze-medical-profile",
                "/analyze-medical-text",
                "/recognize-symptoms",
                "/fuzzy/match-profile",
                "/fuzzy/match-text-queue",
                "/ocr/extract",
            ],
        },
    }
