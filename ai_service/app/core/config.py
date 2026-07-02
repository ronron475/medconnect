"""Application configuration loaded from environment variables."""

from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv

_ROOT = Path(__file__).resolve().parents[2]
_PROJECT = _ROOT.parent
load_dotenv(_PROJECT / ".env")


class Settings:
    """Runtime settings for the medConnect Python API."""

    app_name: str = "medConnect AI API"
    app_version: str = "2.0.0"
    host: str = os.environ.get("MEDCONNECT_AI_HOST", "127.0.0.1")
    port: int = int(os.environ.get("MEDCONNECT_AI_PORT", "8765"))
    debug: bool = os.environ.get("MEDCONNECT_AI_DEBUG", "0").lower() in ("1", "true", "yes")

    cors_origins: list[str] = [
        o.strip()
        for o in os.environ.get("MEDCONNECT_CORS_ORIGINS", "*").split(",")
        if o.strip()
    ]

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
    return Settings()
