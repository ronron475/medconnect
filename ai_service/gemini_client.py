"""Gemini API helpers — health checks for Railway /health (FAQ + cloud AI)."""

from __future__ import annotations

import json
import logging
import os
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

logger = logging.getLogger("medconnect.nlp.gemini")

DEFAULT_GEMINI_MODEL = "gemini-3.5-flash"
GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"

_startup_health: dict[str, Any] | None = None


def _env(*names: str) -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return ""


def gemini_api_key() -> str:
    return _env("AI_API_KEY", "GEMINI_API_KEY", "GOOGLE_API_KEY")


def gemini_model_name() -> str:
    model = _env("AI_MODEL")
    if model.startswith("gemini"):
        return model
    return DEFAULT_GEMINI_MODEL


def log_startup_config() -> None:
    """Log Gemini env configuration at service startup (never log the key)."""
    logger.info("GEMINI/AI_API_KEY loaded=%s", bool(gemini_api_key()))
    logger.info("AI_MODEL=%s", gemini_model_name())
    logger.info("env AI_ENABLED=%s", os.getenv("AI_ENABLED") or "(unset)")
    logger.info("env AI_PROVIDER=%s", os.getenv("AI_PROVIDER") or "(unset)")


def _extract_gemini_text(data: dict[str, Any]) -> str:
    texts: list[str] = []
    for cand in data.get("candidates") or []:
        content = cand.get("content") or {}
        for part in content.get("parts") or []:
            if not isinstance(part, dict):
                continue
            piece = str(part.get("text") or "").strip()
            if piece:
                texts.append(piece)
    return "\n".join(texts).strip()


def _post_generate(payload: dict[str, Any], model: str, key: str, timeout: int) -> dict[str, Any]:
    url = GEMINI_ENDPOINT.format(model=urllib.parse.quote(model, safe=".-"))
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": key,
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def _ping_gemini() -> tuple[str, str]:
    """Lightweight generateContent ping. Returns (text, model)."""
    key = gemini_api_key()
    if not key:
        raise RuntimeError("Gemini API key not configured — set AI_API_KEY on Railway")

    model = gemini_model_name()
    timeout = max(5, int(_env("AI_TIMEOUT") or "15"))
    generation: dict[str, Any] = {
        "temperature": 0,
        "maxOutputTokens": 64,
        "thinkingConfig": {"thinkingBudget": 0},
    }
    payload: dict[str, Any] = {
        "contents": [{"role": "user", "parts": [{"text": "Reply only with the two letters OK"}]}],
        "generationConfig": generation,
    }

    try:
        data = _post_generate(payload, model, key, timeout)
    except urllib.error.HTTPError as exc:
        body = ""
        try:
            body = exc.read().decode("utf-8", errors="replace")[:300]
        except Exception:
            pass
        # Older model APIs may reject thinkingConfig — retry without it.
        if exc.code == 400:
            generation.pop("thinkingConfig", None)
            try:
                data = _post_generate(payload, model, key, timeout)
            except Exception as retry_exc:
                raise RuntimeError(f"Gemini HTTP {exc.code}: {body or exc.reason}") from retry_exc
        elif exc.code in {429, 500, 502, 503}:
            import time

            time.sleep(0.8)
            try:
                data = _post_generate(payload, model, key, timeout)
            except Exception as retry_exc:
                raise RuntimeError(f"Gemini HTTP {exc.code}: {body or exc.reason}") from retry_exc
        else:
            raise RuntimeError(f"Gemini HTTP {exc.code}: {body or exc.reason}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"Gemini connection error: {exc.reason}") from exc

    text = _extract_gemini_text(data)
    if text:
        return text, model
    # HTTP succeeded with a candidate — count as connected (tiny prompts can be empty on some models).
    if data.get("candidates"):
        return "OK", model
    raise RuntimeError("Gemini returned empty response")


def test_gemini_health(force: bool = False) -> dict[str, Any]:
    """Run a lightweight Gemini ping and cache the result."""
    global _startup_health
    if _startup_health is not None and not force:
        return _startup_health

    key = gemini_api_key()
    model = gemini_model_name()
    if not key:
        _startup_health = {
            "gemini": False,
            "provider": None,
            "model": model,
            "status": "missing_key",
            "error": "AI_API_KEY / GEMINI_API_KEY not configured",
        }
        return _startup_health

    try:
        content, model_used = _ping_gemini()
        ok = bool(str(content).strip())
        _startup_health = {
            "gemini": ok,
            "provider": "gemini" if ok else None,
            "model": model_used,
            "status": "online" if ok else "unexpected_response",
            "response_preview": str(content)[:80],
            "error": None if ok else f"Unexpected response: {str(content)[:80]!r}",
        }
        logger.info("Gemini health check passed model=%s", model_used)
    except Exception as exc:
        logger.exception("Gemini health check failed: %s", exc)
        err = str(exc)
        status = "offline"
        if "429" in err or "quota" in err.lower() or "rate" in err.lower():
            status = "quota_exceeded"
        elif "401" in err or "403" in err or "API key" in err:
            status = "auth_failed"
        _startup_health = {
            # Key is present but Google rejected the call — still "configured", not connected.
            "gemini": False,
            "provider": None,
            "model": model,
            "status": status,
            "error": err,
        }

    return _startup_health


def gemini_health_payload() -> dict[str, Any]:
    """Payload for GET /api/gemini_health."""
    health = test_gemini_health(force=False)
    online = bool(health.get("gemini"))
    return {
        "success": True,
        "gemini": online,
        "provider": "gemini" if online else None,
        "model": health.get("model") or gemini_model_name(),
        "status": "online" if online else str(health.get("status") or "offline"),
        "error": health.get("error"),
        "configured": bool(gemini_api_key()),
    }
