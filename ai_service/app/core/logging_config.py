"""Structured logging setup."""

from __future__ import annotations

import logging
import sys

from app.core.config import get_settings


def setup_logging() -> logging.Logger:
    settings = get_settings()
    settings.log_dir.mkdir(parents=True, exist_ok=True)

    handlers: list[logging.Handler] = [
        logging.FileHandler(settings.log_dir / "ai_service.log", encoding="utf-8"),
    ]
    try:
        if sys.stdout is not None:
            handlers.append(logging.StreamHandler())
    except Exception:
        pass

    logging.basicConfig(
        level=logging.DEBUG if settings.debug else logging.INFO,
        format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
        handlers=handlers,
        force=True,
    )
    return logging.getLogger("medconnect.api")
