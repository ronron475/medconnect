"""Centralized exception handling — PHP-compatible JSON responses."""

from __future__ import annotations

import logging

from fastapi import FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from starlette.exceptions import HTTPException as StarletteHTTPException

logger = logging.getLogger("medconnect.api")


def register_exception_handlers(app: FastAPI) -> None:
    @app.exception_handler(StarletteHTTPException)
    async def http_exception_handler(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        detail = exc.detail
        if isinstance(detail, dict):
            message = detail.get("message", str(detail))
        else:
            message = str(detail)
        logger.warning("HTTP %s %s — %s", exc.status_code, request.url.path, message)
        return JSONResponse(
            status_code=exc.status_code,
            content={"success": False, "message": message},
        )

    @app.exception_handler(RequestValidationError)
    async def validation_exception_handler(
        request: Request, exc: RequestValidationError
    ) -> JSONResponse:
        errors = exc.errors()
        message = errors[0].get("msg", "Validation error") if errors else "Validation error"
        logger.warning("Validation error on %s: %s", request.url.path, message)
        return JSONResponse(
            status_code=422,
            content={"success": False, "message": message, "errors": errors},
        )

    @app.exception_handler(Exception)
    async def unhandled_exception_handler(request: Request, exc: Exception) -> JSONResponse:
        logger.exception("Unhandled error on %s", request.url.path)
        return JSONResponse(
            status_code=500,
            content={"success": False, "message": "Internal server error."},
        )
