"""Configuration for AI medical language interpretation (Groq / OpenAI / local Llama)."""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any


def _load_project_env() -> None:
    """Load project root .env into os.environ (does not override existing vars)."""
    env_path = Path(__file__).resolve().parent.parent / ".env"
    if not env_path.is_file():
        return
    for line in env_path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        name, value = line.split("=", 1)
        name = name.strip()
        value = value.strip().strip('"').strip("'")
        if name and name not in os.environ:
            os.environ[name] = value


_load_project_env()


def _env(name: str, default: str = "") -> str:
    return (os.environ.get(name) or default).strip()


GROQ_API_KEY = _env("GROQ_API_KEY", _env("MEDCONNECT_GROQ_API_KEY"))
OPENAI_API_KEY = _env("OPENAI_API_KEY", _env("MEDCONNECT_OPENAI_API_KEY"))
LOCAL_LLAMA_URL = _env("MEDCONNECT_LOCAL_LLAMA_URL", "http://127.0.0.1:11434")
LOCAL_LLAMA_MODEL = _env("MEDCONNECT_LOCAL_LLAMA_MODEL", "llama3")

GROQ_MODEL = _env("GROQ_MODEL", _env("MEDCONNECT_GROQ_MODEL", "llama-3.3-70b-versatile"))
OPENAI_MODEL = _env("MEDCONNECT_OPENAI_MODEL", "gpt-4o-mini")

AI_INTERPRETER_ENABLED = _env("MEDCONNECT_AI_INTERPRETER", "1").lower() not in ("0", "false", "no", "off")
AI_INTERPRETER_TIMEOUT = max(5, int(_env("MEDCONNECT_AI_INTERPRETER_TIMEOUT", "25") or "25"))


def provider_chain() -> list[str]:
    """Ordered providers to try."""
    order = _env("MEDCONNECT_AI_PROVIDER_ORDER", "groq,openai,local")
    names = [p.strip().lower() for p in order.split(",") if p.strip()]
    if not names:
        names = ["groq", "openai", "local"]
    return names


def provider_status() -> dict[str, Any]:
    return {
        "enabled": AI_INTERPRETER_ENABLED,
        "groq_configured": bool(GROQ_API_KEY),
        "openai_configured": bool(OPENAI_API_KEY),
        "local_llama_url": LOCAL_LLAMA_URL,
        "local_llama_model": LOCAL_LLAMA_MODEL,
        "primary_model": GROQ_MODEL,
        "provider_order": provider_chain(),
    }
