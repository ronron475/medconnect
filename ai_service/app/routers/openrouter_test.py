"""TEMPORARY OpenRouter connectivity check. Remove this file and its two lines in app/main.py when done.

Self-protected: requires header X-MedConnect-Service-Token equal to OPENROUTER_TEST_TOKEN
(or MEDCONNECT_AI_SERVICE_TOKEN when that is unset). With neither set the route answers 404.
Sends one tiny chat request straight to OpenRouter. Does not touch the Gemini → OpenRouter
quota fallback or any triage logic. The key and token are never logged or returned.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import logging
import os
import time
import urllib.error
import urllib.request

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse

from gemini_client import OPENROUTER_DEMO_ENDPOINT, OPENROUTER_DEMO_MODEL

router = APIRouter(tags=["Diagnostics"])
logger = logging.getLogger("medconnect.openrouter_test")

_TIMEOUT_SECONDS = 25


def _scrub(text: str, key: str) -> str:
    text = text.replace(key, "[redacted]") if key else text
    return text[:200]


def _authorized(request: Request) -> bool | None:
    """None = route disabled (no token configured); False = bad/missing token."""
    expected = (
        (os.environ.get("OPENROUTER_TEST_TOKEN") or "").strip()
        or (os.environ.get("MEDCONNECT_AI_SERVICE_TOKEN") or "").strip()
    )
    if not expected:
        return None
    provided = request.headers.get("x-medconnect-service-token", "").strip()
    if not provided:
        return False
    return hmac.compare_digest(
        hashlib.sha256(provided.encode("utf-8")).digest(),
        hashlib.sha256(expected.encode("utf-8")).digest(),
    )


@router.post("/openrouter-test", summary="TEMPORARY: one minimal OpenRouter request", include_in_schema=False)
def openrouter_test(request: Request):
    allowed = _authorized(request)
    if allowed is None:
        return JSONResponse(status_code=404, content={"detail": "Not Found"})
    if not allowed:
        return JSONResponse(status_code=403, content={"success": False, "message": "Forbidden"})

    key = (os.environ.get("OPENROUTER_API_KEY") or "").strip()
    result: dict = {
        "success": False,
        "key_configured": key != "",
        "model_requested": OPENROUTER_DEMO_MODEL,
    }
    if not key:
        result["error"] = "OPENROUTER_API_KEY is not set on this service"
        return result

    body = {
        "model": OPENROUTER_DEMO_MODEL,
        "max_tokens": 16,
        "temperature": 0,
        "messages": [{"role": "user", "content": "Reply with the single word OK."}],
    }
    req = urllib.request.Request(
        OPENROUTER_DEMO_ENDPOINT,
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": "Bearer " + key,
            "X-Title": "medConnect OpenRouter connectivity test",
        },
        method="POST",
    )

    started = time.monotonic()
    raw = ""
    try:
        with urllib.request.urlopen(req, timeout=_TIMEOUT_SECONDS) as resp:
            status = int(getattr(resp, "status", 200))
            raw = resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as exc:
        status = int(exc.code)
        try:
            raw = exc.read().decode("utf-8", "replace")
        except Exception:
            raw = ""
    except Exception as exc:
        result["latency_ms"] = int((time.monotonic() - started) * 1000)
        result["error"] = "network: " + type(exc).__name__
        logger.warning("OpenRouter test: network error %s", type(exc).__name__)
        return result

    result["latency_ms"] = int((time.monotonic() - started) * 1000)
    result["http_status"] = status
    logger.info("OpenRouter test: http %s in %sms", status, result["latency_ms"])

    try:
        decoded = json.loads(raw) if raw else {}
    except json.JSONDecodeError:
        decoded = {}
    if not isinstance(decoded, dict):
        decoded = {}

    if 200 <= status < 300:
        try:
            reply = str(decoded["choices"][0]["message"]["content"] or "").strip()
        except (KeyError, IndexError, TypeError):
            reply = ""
        usage = decoded.get("usage") if isinstance(decoded.get("usage"), dict) else {}
        result["success"] = True
        result["model_returned"] = str(decoded.get("model") or "")
        result["reply_preview"] = _scrub(reply, key)[:40]
        result["usage"] = {
            k: usage[k] for k in ("prompt_tokens", "completion_tokens", "total_tokens") if k in usage
        }
        return result

    err = decoded.get("error") if isinstance(decoded.get("error"), dict) else {}
    result["error"] = _scrub(str(err.get("message") or "OpenRouter returned an error"), key)
    return result
