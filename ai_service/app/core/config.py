"""Application configuration loaded from environment variables."""

from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv
from urllib.parse import urlparse

_ROOT = Path(__file__).resolve().parents[2]
_PROJECT = _ROOT.parent
load_dotenv(_PROJECT / ".env")

# Browser origins that may call this service. Never "*".
_DEFAULT_CORS_ORIGINS = (
    "https://medconnect.bccbsis.com",
    "http://localhost",
    "http://127.0.0.1",
)


def resolve_cors_origins() -> list[str]:
    """Allowlisted MedConnect origins. A configured '*' is ignored."""
    origins: list[str] = []

    def add(origin: str) -> None:
        value = origin.strip().rstrip("/")
        if value == "" or value == "*" or value in origins:
            return
        origins.append(value)

    for origin in _DEFAULT_CORS_ORIGINS:
        add(origin)

    app_url = os.environ.get("MEDCONNECT_APP_URL", "").strip()
    if app_url:
        parsed = urlparse(app_url)
        if parsed.scheme and parsed.netloc:
            add(f"{parsed.scheme}://{parsed.netloc}")

    raw = os.environ.get("MEDCONNECT_CORS_ORIGINS", "")
    for part in raw.split(","):
        add(part)

    return origins


class Settings:
    """Runtime settings for the medConnect Python API."""

    app_name: str = "medConnect AI API"
    app_version: str = "2.0.0"
    host: str = os.environ.get("MEDCONNECT_AI_HOST", "127.0.0.1")
    # Prefer platform PORT (Railway) so the public proxy matches the listener.
    # Local/dev can still set MEDCONNECT_AI_PORT when PORT is unset.
    port: int = int(
        os.environ.get("PORT") or os.environ.get("MEDCONNECT_AI_PORT") or "8765"
    )
    debug: bool = os.environ.get("MEDCONNECT_AI_DEBUG", "0").lower() in ("1", "true", "yes")

    # Shared secret for protected routes. Railway/PHP env only — never hardcoded.
    service_token: str = os.environ.get("MEDCONNECT_AI_SERVICE_TOKEN", "").strip()

    ocr_space_api_key: str = os.environ.get("OCR_SPACE_API_KEY", "")
    ocr_space_endpoint: str = os.environ.get(
        "OCR_SPACE_ENDPOINT", "https://api.ocr.space/parse/image"
    )
    ocr_max_file_size: int = int(os.environ.get("OCR_MAX_FILE_SIZE", str(5 * 1024 * 1024)))
    ocr_debug: bool = os.environ.get("OCR_DEBUG", "0").lower() in ("1", "true", "yes")

    log_dir: Path = _PROJECT / "storage" / "logs"
    data_dir: Path = _PROJECT / "data" / "nlp"

    analyze_timeout: int = int(os.environ.get("MEDCONNECT_AI_ANALYZE_TIMEOUT", "120"))


@lru_cache
def get_settings() -> Settings:
    settings = Settings()
    settings.cors_origins = resolve_cors_origins()
    settings.service_token = os.environ.get("MEDCONNECT_AI_SERVICE_TOKEN", "").strip()
    return settings
