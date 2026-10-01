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
GEMINI_FALLBACK_MODEL = "gemini-3.8-flash"
GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"
# Fixed free model whose endpoint accepts JSON mode. Not a rotating router.
OPENROUTER_DEMO_MODEL = "nvidia/nemotron-3-super-120b-a12b:free"
OPENROUTER_DEMO_ENDPOINT = "https://openrouter.ai/api/v1/chat/completions"

_startup_health: dict[str, Any] | None = None


def _env(*names: str) -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return ""


def gemini_api_key() -> str:
    return _env("AI_API_KEY", "GEMINI_API_KEY", "GOOGLE_API_KEY")


def _normalize_gemini_model(name: str) -> str:
    """Strip quotes some env UIs store in the value. Empty if not a Gemini id."""
    model = (name or "").strip().strip('"').strip("'")
    if model.lower().startswith("gemini"):
        return model
    return ""


def gemini_model_name() -> str:
    model = _normalize_gemini_model(_env("AI_MODEL"))
    if model:
        return model
    return DEFAULT_GEMINI_MODEL


def log_startup_config() -> None:
    """Log Gemini env configuration at service startup (never log the key)."""
    logger.info("GEMINI/AI_API_KEY loaded=%s", bool(gemini_api_key()))
    logger.info("OPENROUTER_API_KEY loaded=%s", bool(_env("OPENROUTER_API_KEY")))
    logger.info("AI_MODEL=%s", gemini_model_name())
    logger.info("env AI_ENABLED=%s", os.getenv("AI_ENABLED") or "(unset)")
    logger.info("env AI_PROVIDER=%s", os.getenv("AI_PROVIDER") or "(unset)")


def _extract_gemini_text(data: dict[str, Any]) -> str:
    texts: list[str] = []
    for cand in data.get("candidates") or []:
        content = cand.get("content") or {}
        for part in content.get("parts") or []:
            if not isinstance(part, dict) or part.get("thought"):
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
        "maxOutputTokens": 256,
        # Gemini 3.5 Flash spends maxOutputTokens on hidden thoughts. thinkingBudget 0
        # can hang until the read times out. MINIMAL leaves the reply as plain text.
        "thinkingConfig": {"thinkingLevel": "MINIMAL"},
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


def _parts_text(node: Any) -> str:
    if not isinstance(node, dict):
        return ""
    parts = node.get("parts")
    if not isinstance(parts, list):
        return ""
    chunks: list[str] = []
    for part in parts:
        if not isinstance(part, dict):
            continue
        piece = str(part.get("text") or "")
        if piece.strip():
            chunks.append(piece)
    return "\n".join(chunks)


def _openrouter_body_from_gemini(payload: dict[str, Any]) -> dict[str, Any] | None:
    """Copy the Gemini system instruction and contents into one chat request."""
    messages: list[dict[str, str]] = []
    system = _parts_text(payload.get("systemInstruction"))
    if system:
        messages.append({"role": "system", "content": system})

    contents = payload.get("contents")
    if isinstance(contents, list):
        for turn in contents:
            if not isinstance(turn, dict):
                continue
            text = _parts_text(turn)
            if not text:
                continue
            role = str(turn.get("role") or "user").strip().lower()
            messages.append({
                "role": "assistant" if role in {"model", "assistant"} else "user",
                "content": text,
            })

    if not any(message.get("role") == "user" for message in messages):
        return None

    gen = payload.get("generationConfig")
    gen = gen if isinstance(gen, dict) else {}
    try:
        temperature = float(gen.get("temperature", 0.1))
    except (TypeError, ValueError):
        temperature = 0.1
    try:
        max_tokens = int(gen.get("maxOutputTokens", 1024))
    except (TypeError, ValueError):
        max_tokens = 1024

    body: dict[str, Any] = {
        "model": OPENROUTER_DEMO_MODEL,
        "temperature": temperature,
        "max_tokens": max(64, min(4096, max_tokens)),
        "messages": messages,
    }
    # Gemini responseMimeType application/json. OpenRouter enforces that as JSON mode.
    # Do not send reasoning.enabled=false. This model only accepts effort medium/low;
    # a disable flag is rejected, the fallback returns nothing, and PHP stops on Gemini HTTP 429.
    if str(gen.get("responseMimeType") or "").strip().lower() == "application/json":
        body["response_format"] = {"type": "json_object"}
        body["max_tokens"] = max(int(body["max_tokens"]), 2048)
    return body


def _openrouter_content_text(content: Any) -> str:
    if isinstance(content, str):
        return content.strip()
    if not isinstance(content, list):
        return ""
    chunks: list[str] = []
    for item in content:
        if isinstance(item, str) and item.strip():
            chunks.append(item.strip())
        elif isinstance(item, dict):
            piece = str(item.get("text") or "").strip()
            if piece:
                chunks.append(piece)
    return "\n".join(chunks).strip()


def _openrouter_json_object(text: str) -> str:
    text = text.strip()
    if text.startswith("{") and text.endswith("}"):
        return text
    start = text.find("{")
    end = text.rfind("}")
    if start >= 0 and end > start:
        return text[start:end + 1].strip()
    return ""


def _openrouter_choice_text(decoded: dict[str, Any]) -> str:
    """Model text for the existing Gemini parser. Empty content is not a success."""
    try:
        message = decoded["choices"][0]["message"]
    except (KeyError, IndexError, TypeError):
        return ""
    if not isinstance(message, dict):
        return ""
    text = _openrouter_content_text(message.get("content"))
    if text:
        return text
    # Default reasoning can fill max_tokens and leave content empty.
    # Pass only a JSON object through, which is what the PHP parser accepts.
    return _openrouter_json_object(_openrouter_content_text(message.get("reasoning")))


def _openrouter_http_complete(payload: dict[str, Any], timeout: int) -> str | None:
    """One OpenRouter completion. Returns model text, or None. Never logs the key."""
    key = _env("OPENROUTER_API_KEY")
    if not key:
        return None
    body = _openrouter_body_from_gemini(payload)
    if body is None:
        return None

    req = urllib.request.Request(
        OPENROUTER_DEMO_ENDPOINT,
        data=json.dumps(body).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": "Bearer " + key,
            "HTTP-Referer": "https://medconnect.bccbsis.com/public/gemini_clinical_interview_demo.php",
            "X-Title": "medConnect clinical interview demo",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            raw = resp.read().decode("utf-8")
            code = int(getattr(resp, "status", 200))
    except urllib.error.HTTPError as exc:
        logger.warning("OpenRouter demo quota fallback unavailable: http %s", exc.code)
        return None
    except Exception:
        logger.warning("OpenRouter demo quota fallback unavailable")
        return None

    if code < 200 or code >= 300:
        logger.warning("OpenRouter demo quota fallback unavailable: http %s", code)
        return None
    try:
        decoded = json.loads(raw)
        text = _openrouter_choice_text(decoded if isinstance(decoded, dict) else {})
    except (TypeError, json.JSONDecodeError):
        return None
    return text or None


def _fallback_text_pack(model: str, text: str) -> dict[str, Any]:
    return {
        "model": model,
        "text": text,
        "response": {
            "candidates": [
                {"content": {"parts": [{"text": text}]}},
            ],
        },
    }


def _groq_model_id() -> str:
    try:
        from ai_interpreter_config import GROQ_MODEL

        model = str(GROQ_MODEL or "").strip()
        if model:
            return model
    except Exception:
        pass
    return "openai/gpt-oss-120b"


def _groq_http_complete(payload: dict[str, Any], timeout: int) -> str | None:
    """One Groq completion after OpenRouter miss. Returns model text, or None."""
    _ = timeout
    try:
        from ai_interpreter_config import GROQ_API_KEY
    except Exception:
        return None
    if not GROQ_API_KEY:
        return None
    body = _openrouter_body_from_gemini(payload)
    if body is None:
        return None
    messages = body.get("messages")
    if not isinstance(messages, list) or not messages:
        return None
    json_mode = str(
        ((payload.get("generationConfig") or {}) if isinstance(payload.get("generationConfig"), dict) else {}).get(
            "responseMimeType"
        )
        or ""
    ).strip().lower() == "application/json"
    try:
        temperature = float(body.get("temperature") or 0.1)
    except (TypeError, ValueError):
        temperature = 0.1
    try:
        from groq_client import groq_chat_completion

        text, _model = groq_chat_completion(
            messages,
            json_mode=json_mode,
            temperature=temperature,
        )
    except Exception:
        logger.warning("Groq demo quota fallback unavailable")
        return None
    text = (text or "").strip()
    return text or None


def _quota_fallback_pack(payload: dict[str, Any], timeout: int) -> dict[str, Any] | None:
    """Gemini HTTP 429 only. OpenRouter first, then Groq. Same pack shape as generate_content."""
    text = _openrouter_http_complete(payload, timeout)
    if text:
        return _fallback_text_pack(OPENROUTER_DEMO_MODEL, text)
    text = _groq_http_complete(payload, timeout)
    if text:
        logger.info("Gemini HTTP 429; Groq quota fallback returned text")
        return _fallback_text_pack(_groq_model_id(), text)
    return None


def _payload_for_secondary_gemini(payload: dict[str, Any]) -> dict[str, Any]:
    """Keep prompt/content; drop Gemini 3.5 thinkingLevel MINIMAL (rejected by 3.8 Flash)."""
    body = dict(payload or {})
    gen = body.get("generationConfig")
    if isinstance(gen, dict) and "thinkingConfig" in gen:
        gen = dict(gen)
        gen.pop("thinkingConfig", None)
        body["generationConfig"] = gen
    return body


def _try_secondary_gemini_model(
    payload: dict[str, Any],
    primary_model: str,
    key: str,
    timeout: int,
) -> dict[str, Any] | None:
    """One Gemini 3.8 generateContent call after primary HTTP 429. No retry loop."""
    fallback = GEMINI_FALLBACK_MODEL
    if not fallback or primary_model == fallback:
        return None
    body = _payload_for_secondary_gemini(payload)
    try:
        data = _post_generate(body, fallback, key, timeout)
    except Exception:
        logger.warning("Gemini HTTP 429; fallback model %s failed", fallback)
        return None
    text = _extract_gemini_text(data)
    if not text:
        logger.warning("Gemini HTTP 429; fallback model %s returned empty text", fallback)
        return None
    logger.info("Gemini HTTP 429; fallback model %s returned text", fallback)
    return {
        "model": fallback,
        "text": text,
        "response": data,
    }


def generate_content(
    payload: dict[str, Any],
    *,
    model: str | None = None,
    timeout: int | None = None,
) -> dict[str, Any]:
    """
    Proxy generateContent using the Railway AI_API_KEY.
    Used by Hostinger PHP demos that must not store the Gemini key locally.
    """
    key = gemini_api_key()
    if not key:
        raise RuntimeError("Gemini API key not configured — set AI_API_KEY on Railway")

    # Railway AI_MODEL is the production primary. Hostinger PHP often still sends
    # the old default gemini-3.5-flash, which would ignore a Lite switch on Railway.
    env_model = _normalize_gemini_model(_env("AI_MODEL"))
    requested = _normalize_gemini_model(model or "")
    use_model = env_model or requested or DEFAULT_GEMINI_MODEL
    # Demo interview prompts need headroom; Google 503 "high demand" also needs retries.
    wait = max(5, min(60, int(timeout if timeout is not None else (_env("AI_TIMEOUT") or "30"))))

    body = dict(payload or {})
    try:
        data = _post_generate(body, use_model, key, wait)
    except urllib.error.HTTPError as exc:
        err_body = ""
        try:
            err_body = exc.read().decode("utf-8", errors="replace")[:400]
        except Exception:
            pass
        # Quota is not transient. Do not retry the primary model; try Gemini 3.8 once, then OpenRouter.
        if exc.code == 429:
            recovered = _try_secondary_gemini_model(body, use_model, key, wait)
            if recovered is not None:
                return recovered
            recovered = _quota_fallback_pack(body, wait)
            if recovered is not None:
                used = str(recovered.get("model") or "")
                logger.info("Gemini HTTP 429; quota fallback returned text model=%s", used)
                return recovered
            raise RuntimeError(f"Gemini HTTP 429: {err_body or exc.reason}") from exc
        # Retry without thinkingConfig when the model rejects it (same as PHP demo path).
        if exc.code == 400:
            gen = body.get("generationConfig")
            if isinstance(gen, dict) and "thinkingConfig" in gen:
                gen = dict(gen)
                gen.pop("thinkingConfig", None)
                body = dict(body)
                body["generationConfig"] = gen
                try:
                    data = _post_generate(body, use_model, key, wait)
                except Exception as retry_exc:
                    raise RuntimeError(f"Gemini HTTP {exc.code}: {err_body or exc.reason}") from retry_exc
            else:
                raise RuntimeError(f"Gemini HTTP {exc.code}: {err_body or exc.reason}") from exc
        elif exc.code in {500, 502, 503}:
            # Transient high-demand / overload. HTTP 429 is handled above and is not retried.
            import time

            data = None
            last_error: Exception = exc
            for delay in (1.0, 2.0, 3.5):
                time.sleep(delay)
                try:
                    data = _post_generate(body, use_model, key, wait)
                    break
                except Exception as retry_exc:
                    last_error = retry_exc
            if data is None:
                detail = err_body or getattr(last_error, "reason", None) or str(last_error) or exc.reason
                raise RuntimeError(f"Gemini HTTP {exc.code}: {detail}") from last_error
        else:
            raise RuntimeError(f"Gemini HTTP {exc.code}: {err_body or exc.reason}") from exc
    except urllib.error.URLError as exc:
        # One reconnect retry on read timeout / transient network.
        import time

        time.sleep(1.2)
        try:
            data = _post_generate(body, use_model, key, wait)
        except Exception as retry_exc:
            raise RuntimeError(f"Gemini connection error: {exc.reason}") from retry_exc

    text = _extract_gemini_text(data)
    if not text:
        # Empty candidate is usually thoughts consuming maxOutputTokens. Ask for
        # minimal thinking and enough room for the JSON body.
        gen = body.get("generationConfig")
        if isinstance(gen, dict):
            gen2 = dict(gen)
            gen2["thinkingConfig"] = {"thinkingLevel": "MINIMAL"}
            try:
                gen2["maxOutputTokens"] = max(int(gen2.get("maxOutputTokens") or 0), 4096)
            except (TypeError, ValueError):
                gen2["maxOutputTokens"] = 4096
            body2 = dict(body)
            body2["generationConfig"] = gen2
            try:
                data = _post_generate(body2, use_model, key, wait)
                text = _extract_gemini_text(data)
            except Exception:
                pass

    return {
        "model": use_model,
        "text": text,
        "response": data,
    }
