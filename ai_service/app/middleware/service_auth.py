"""Shared-secret gate for protected AI routes.

The secret is read from MEDCONNECT_AI_SERVICE_TOKEN (Railway / server env).
It is never logged or written into responses.
"""

from __future__ import annotations

import hashlib
import hmac

from starlette.middleware.base import BaseHTTPMiddleware
from starlette.requests import Request
from starlette.responses import JSONResponse, Response

from app.core.config import get_settings

# Liveness only. Railway healthcheckPath is /health.
_PUBLIC_GET_PATHS = frozenset({"/health", "/api/health"})


def tokens_match(provided: str, expected: str) -> bool:
    if provided == "" or expected == "":
        return False
    return hmac.compare_digest(
        hashlib.sha256(provided.encode("utf-8")).digest(),
        hashlib.sha256(expected.encode("utf-8")).digest(),
    )


def presented_token(request: Request) -> str | None:
    """Return a caller token, or None when no credential was sent."""
    direct = request.headers.get("x-medconnect-service-token", "").strip()
    if direct != "":
        return direct

    authorization = request.headers.get("authorization", "").strip()
    if authorization == "":
        return None

    scheme, _, rest = authorization.partition(" ")
    if scheme.lower() != "bearer":
        return ""
    token = rest.strip()
    return token if token != "" else None


def is_public_request(request: Request) -> bool:
    if request.method == "OPTIONS":
        return True
    return request.method == "GET" and request.url.path in _PUBLIC_GET_PATHS


class ServiceAuthMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next) -> Response:
        if is_public_request(request):
            return await call_next(request)

        expected = get_settings().service_token
        provided = presented_token(request)

        if expected == "" or provided is None:
            return _reject(401, "Unauthorized")
        if provided == "" or not tokens_match(provided, expected):
            return _reject(403, "Forbidden")

        return await call_next(request)


def _reject(status_code: int, message: str) -> JSONResponse:
    headers = {"WWW-Authenticate": "Bearer"} if status_code == 401 else None
    return JSONResponse(
        status_code=status_code,
        content={"success": False, "message": message},
        headers=headers,
    )
