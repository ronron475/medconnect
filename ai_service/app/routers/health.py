"""Health check endpoints."""

from __future__ import annotations

from fastapi import APIRouter

router = APIRouter(tags=["Health"])


@router.get("/health", summary="Service health status")
@router.get("/api/health", summary="Service health status (alias)")
def health() -> dict:
    from health_status import build_health_payload

    payload = build_health_payload()
    payload["engine"] = "fastapi"
    return payload


@router.get("/groq_health", summary="Groq API connectivity")
@router.get("/api/groq_health", summary="Groq API connectivity (alias)")
def groq_health() -> dict:
    from groq_client import groq_health_payload

    return groq_health_payload()
