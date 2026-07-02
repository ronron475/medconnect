"""medConnect unified FastAPI application."""

from __future__ import annotations

from contextlib import asynccontextmanager

from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.core.config import get_settings
from app.core.exceptions import register_exception_handlers
from app.core.logging_config import setup_logging
from app.core.startup import run_startup_tasks
from app.middleware.security import SecurityHeadersMiddleware
from app.routers import (
    consultation,
    fuzzy,
    health,
    medical_text,
    ml,
    ocr,
    profile,
    root,
    symptoms,
    transcription,
)


@asynccontextmanager
async def lifespan(app: FastAPI):
    logger = setup_logging()
    settings = get_settings()
    logger.info(
        "Starting %s v%s on %s:%s",
        settings.app_name,
        settings.app_version,
        settings.host,
        settings.port,
    )
    run_startup_tasks()
    yield
    logger.info("Shutting down medConnect API")


def create_app() -> FastAPI:
    settings = get_settings()
    app = FastAPI(
        title=settings.app_name,
        version=settings.app_version,
        description=(
            "Unified medConnect Python API — NLP, ML, transcription, fuzzy matching, "
            "and Philippine National ID OCR."
        ),
        docs_url="/docs",
        redoc_url="/redoc",
        openapi_url="/openapi.json",
        lifespan=lifespan,
    )

    origins = settings.cors_origins if settings.cors_origins != ["*"] else ["*"]
    app.add_middleware(
        CORSMiddleware,
        allow_origins=origins,
        allow_credentials=True,
        allow_methods=["*"],
        allow_headers=["*"],
    )
    app.add_middleware(SecurityHeadersMiddleware)

    register_exception_handlers(app)

    app.include_router(root.router)
    app.include_router(health.router)
    app.include_router(transcription.router)
    app.include_router(consultation.router)
    app.include_router(profile.router)
    app.include_router(medical_text.router)
    app.include_router(symptoms.router)
    app.include_router(ml.router)
    app.include_router(fuzzy.router)
    app.include_router(ocr.router)

    return app


app = create_app()
