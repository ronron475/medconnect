"""Cohere Rerank — ranks existing documents only (Care Tips matching).

Does NOT generate, rewrite, diagnose, prescribe, or modify tip content.
"""

from __future__ import annotations

import json
import logging
import os
import urllib.error
import urllib.request
from typing import Any

logger = logging.getLogger("medconnect.cohere")

COHERE_RERANK_ENDPOINT = "https://api.cohere.com/v2/rerank"
DEFAULT_MODEL = "rerank-v3.5"


def _env(*names: str) -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return ""


def cohere_api_key() -> str:
    return _env("COHERE_API_KEY", "COHERE_KEY")


def cohere_rerank_enabled() -> bool:
    raw = (_env("COHERE_RERANK_ENABLED") or "1").lower()
    if raw in {"0", "false", "no", "off"}:
        return False
    return bool(cohere_api_key())


def cohere_rerank_model() -> str:
    return _env("COHERE_RERANK_MODEL") or DEFAULT_MODEL


def rerank_documents(
    query: str,
    documents: list[str],
    top_n: int = 3,
    model: str | None = None,
    timeout: int = 12,
) -> list[dict[str, Any]]:
    """Return [{index, score}, ...] best-first. Empty list on failure/disabled."""
    query = (query or "").strip()
    docs = [str(d).strip() for d in documents if str(d).strip()]
    if not query or not docs:
        return []
    if not cohere_rerank_enabled():
        logger.info("Cohere rerank skipped — disabled or missing COHERE_API_KEY")
        return []

    top_n = max(1, min(len(docs), int(top_n or 3)))
    use_model = (model or cohere_rerank_model()).strip() or DEFAULT_MODEL
    payload = {
        "model": use_model,
        "query": query[:1000],
        "documents": [{"text": d[:2000]} for d in docs],
        "top_n": top_n,
    }

    req = urllib.request.Request(
        COHERE_RERANK_ENDPOINT,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "Accept": "application/json",
            "Authorization": f"Bearer {cohere_api_key()}",
        },
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=max(5, timeout)) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = ""
        try:
            body = exc.read().decode("utf-8", errors="replace")[:240]
        except Exception:
            body = str(exc)
        logger.warning("Cohere rerank HTTP %s: %s", exc.code, body)
        raise RuntimeError(f"Cohere HTTP {exc.code}") from exc
    except Exception as exc:
        logger.warning("Cohere rerank failed: %s", exc)
        raise

    out: list[dict[str, Any]] = []
    for row in data.get("results") or []:
        if not isinstance(row, dict):
            continue
        try:
            idx = int(row.get("index"))
            score = float(row.get("relevance_score"))
        except (TypeError, ValueError):
            continue
        if idx < 0 or idx >= len(docs):
            continue
        out.append({"index": idx, "score": score})

    out.sort(key=lambda r: r["score"], reverse=True)
    return out
