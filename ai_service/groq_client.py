"""Groq API client — SDK-first with urllib fallback, health checks, and structured errors."""

from __future__ import annotations

import json
import logging
import os
import urllib.error
import urllib.request
from typing import Any

from ai_interpreter_config import AI_INTERPRETER_TIMEOUT, GROQ_API_KEY, GROQ_MODEL

logger = logging.getLogger("medconnect.nlp.groq")

_groq_sdk_client: Any = None
_startup_health: dict[str, Any] | None = None


def log_startup_config() -> None:
    """Log Groq env configuration at service startup (never log the key itself)."""
    logger.info("GROQ_API_KEY loaded=%s", bool(GROQ_API_KEY))
    logger.info("GROQ_MODEL=%s", GROQ_MODEL)
    logger.info("env GROQ_MODEL=%s", os.getenv("GROQ_MODEL") or "(unset)")
    logger.info("env MEDCONNECT_GROQ_MODEL=%s", os.getenv("MEDCONNECT_GROQ_MODEL") or "(unset)")


def get_groq_sdk_client() -> Any:
    """Return a cached Groq SDK client, or None if unavailable."""
    global _groq_sdk_client
    if not GROQ_API_KEY:
        return None
    if _groq_sdk_client is not None:
        return _groq_sdk_client
    try:
        from groq import Groq

        _groq_sdk_client = Groq(api_key=GROQ_API_KEY, timeout=float(AI_INTERPRETER_TIMEOUT))
        logger.info("Groq SDK client initialized successfully model=%s", GROQ_MODEL)
    except Exception as exc:
        logger.exception("Groq SDK initialization failed: %s", exc)
        return None
    return _groq_sdk_client


def _format_http_error(exc: urllib.error.HTTPError) -> str:
    body = ""
    try:
        body = exc.read().decode("utf-8", errors="replace")[:400]
    except Exception:
        pass
    detail = body
    try:
        parsed = json.loads(body)
        err = parsed.get("error") or {}
        if isinstance(err, dict) and err.get("message"):
            detail = str(err["message"])
    except Exception:
        pass
    if exc.code == 403 and ("1010" in detail or "1010" in body):
        return (
            "HTTP 403: Groq API access blocked (Cloudflare 1010). "
            "Verify GROQ_API_KEY at console.groq.com, rotate the key if needed, "
            "and ensure outbound HTTPS to api.groq.com is allowed."
        )
    if exc.code == 401:
        return "HTTP 401: Invalid Groq API key — set a valid GROQ_API_KEY in .env"
    return f"HTTP {exc.code}: {detail or exc.reason}"


def groq_chat_completion(
    messages: list[dict[str, str]],
    *,
    json_mode: bool = True,
    temperature: float = 0.1,
) -> tuple[str, str]:
    """
    Send a chat completion to Groq.
    Returns (content, model_used).
    Raises RuntimeError with a descriptive message on failure.
    """
    if not GROQ_API_KEY:
        raise RuntimeError("Groq API key not configured — set GROQ_API_KEY in .env")

    prompt_preview = ""
    for msg in reversed(messages):
        if msg.get("role") == "user":
            prompt_preview = str(msg.get("content") or "")[:500]
            break

    logger.info("Sending request to Groq model=%s", GROQ_MODEL)
    logger.info("Groq prompt preview: %s", prompt_preview)

    client = get_groq_sdk_client()
    if client is not None:
        try:
            kwargs: dict[str, Any] = {
                "model": GROQ_MODEL,
                "messages": messages,
                "temperature": temperature,
            }
            if json_mode:
                kwargs["response_format"] = {"type": "json_object"}
            response = client.chat.completions.create(**kwargs)
            content = str(response.choices[0].message.content or "").strip()
            model_used = str(getattr(response, "model", None) or GROQ_MODEL)
            logger.info("Groq response received model=%s chars=%d", model_used, len(content))
            logger.debug("Groq response body: %s", content[:2000])
            if not content:
                raise ValueError("Empty Groq response")
            return content, model_used
        except Exception as exc:
            err = str(exc)
            exc_name = type(exc).__name__
            if (
                "CERTIFICATE_VERIFY_FAILED" in err
                or "ConnectError" in exc_name
                or "APIConnectionError" in exc_name
                or "Connection error" in err
            ):
                logger.warning("Groq SDK failed — falling back to urllib: %s", err)
            else:
                logger.exception("Groq SDK request failed: %s", exc)
                raise RuntimeError(str(exc)) from exc

    payload: dict[str, Any] = {
        "model": GROQ_MODEL,
        "temperature": temperature,
        "messages": messages,
    }
    if json_mode:
        payload["response_format"] = {"type": "json_object"}

    body = json.dumps(payload).encode("utf-8")
    req = urllib.request.Request(
        "https://api.groq.com/openai/v1/chat/completions",
        data=body,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {GROQ_API_KEY}",
            "User-Agent": "medConnect-NLP/1.0 (Python; medical-profile-nlp)",
            "Accept": "application/json",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=AI_INTERPRETER_TIMEOUT) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        content = str(data["choices"][0]["message"]["content"] or "").strip()
        model_used = str(data.get("model") or GROQ_MODEL)
        logger.info("Groq urllib response received model=%s chars=%d", model_used, len(content))
        if not content:
            raise ValueError("Empty Groq response")
        return content, model_used
    except urllib.error.HTTPError as exc:
        msg = _format_http_error(exc)
        logger.exception("Groq HTTP error: %s", msg)
        raise RuntimeError(msg) from exc
    except urllib.error.URLError as exc:
        logger.exception("Groq connection error: %s", exc)
        raise RuntimeError(f"Groq connection error: {exc.reason}") from exc
    except Exception as exc:
        logger.exception("Groq request failed: %s", exc)
        raise RuntimeError(str(exc)) from exc


def test_groq_health(force: bool = False) -> dict[str, Any]:
    """Run a lightweight Groq ping and cache the result."""
    global _startup_health
    if _startup_health is not None and not force:
        return _startup_health

    if not GROQ_API_KEY:
        _startup_health = {
            "groq": False,
            "provider": None,
            "model": GROQ_MODEL,
            "status": "missing_key",
            "error": "GROQ_API_KEY not configured",
        }
        return _startup_health

    try:
        content, model_used = groq_chat_completion(
            [{"role": "user", "content": "Reply only: OK"}],
            json_mode=False,
            temperature=0,
        )
        ok = content.strip().upper().startswith("OK")
        _startup_health = {
            "groq": ok,
            "provider": "groq" if ok else None,
            "model": model_used,
            "status": "online" if ok else "unexpected_response",
            "response_preview": content[:80],
            "error": None if ok else f"Unexpected response: {content[:80]!r}",
        }
        logger.info("Groq health check passed model=%s", model_used)
    except Exception as exc:
        logger.exception("Groq health check failed: %s", exc)
        _startup_health = {
            "groq": False,
            "provider": None,
            "model": GROQ_MODEL,
            "status": "offline",
            "error": str(exc),
        }

    return _startup_health


def groq_health_payload() -> dict[str, Any]:
    """Payload for GET /api/groq_health."""
    health = test_groq_health(force=False)
    online = bool(health.get("groq"))
    return {
        "success": True,
        "groq": online,
        "provider": "groq" if online else None,
        "model": health.get("model") or GROQ_MODEL,
        "status": "online" if online else str(health.get("status") or "offline"),
        "error": health.get("error"),
        "configured": bool(GROQ_API_KEY),
    }
