"""Auth and CORS checks for the Railway AI service. Does not call Gemini."""

from __future__ import annotations

import os
import sys
import unittest
from contextlib import asynccontextmanager
from pathlib import Path

_SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(_SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(_SERVICE_ROOT))

TOKEN = "unit-test-service-token"
os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN
os.environ.pop("MEDCONNECT_CORS_ORIGINS", None)

from fastapi.testclient import TestClient  # noqa: E402

from app.core.config import get_settings, resolve_cors_origins  # noqa: E402
from app.main import create_app  # noqa: E402

ALLOWED_ORIGIN = "https://medconnect.bccbsis.com"
EVIL_ORIGIN = "https://evil.example"


@asynccontextmanager
async def _no_startup(_app):
    yield


def _client() -> TestClient:
    get_settings.cache_clear()
    app = create_app()
    app.router.lifespan_context = _no_startup
    client = TestClient(app)
    client.__enter__()
    return client


class ServiceAuthTests(unittest.TestCase):
    def setUp(self) -> None:
        os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN
        os.environ.pop("MEDCONNECT_CORS_ORIGINS", None)
        get_settings.cache_clear()
        self.client = _client()

    def tearDown(self) -> None:
        self.client.__exit__(None, None, None)
        get_settings.cache_clear()

    def test_gemini_generate_missing_credential_is_401(self) -> None:
        response = self.client.post("/gemini/generate", json={"payload": {}})
        self.assertEqual(response.status_code, 401)
        self.assertNotIn(TOKEN, response.text)
        self.assertNotIn("AI_API_KEY", response.text)
        self.assertNotIn("GEMINI_API_KEY", response.text)

    def test_gemini_generate_invalid_credential_is_403(self) -> None:
        response = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers={"Authorization": "Bearer not-the-token"},
        )
        self.assertEqual(response.status_code, 403)
        self.assertNotIn(TOKEN, response.text)
        self.assertNotIn("not-the-token", response.text)

    def test_gemini_generate_valid_credential_reaches_route(self) -> None:
        response = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers={"Authorization": f"Bearer {TOKEN}"},
        )
        self.assertEqual(response.status_code, 400)
        self.assertIn("contents", response.text)
        self.assertNotIn(TOKEN, response.text)

    def test_alias_and_header_token(self) -> None:
        missing = self.client.post("/nlp-step3-demo/gemini-generate", json={"payload": {}})
        self.assertEqual(missing.status_code, 401)
        accepted = self.client.post(
            "/nlp-step3-demo/gemini-generate",
            json={"payload": {}},
            headers={"X-MedConnect-Service-Token": TOKEN},
        )
        self.assertEqual(accepted.status_code, 400)

    def test_health_stays_public(self) -> None:
        response = self.client.get("/health")
        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertTrue(body.get("success"))

    def test_unconfigured_secret_rejects_protected_route(self) -> None:
        os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = ""
        get_settings.cache_clear()
        response = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers={"Authorization": f"Bearer {TOKEN}"},
        )
        self.assertEqual(response.status_code, 401)
        self.assertNotIn(TOKEN, response.text)

    def test_allowed_origin_and_preflight(self) -> None:
        preflight = self.client.options(
            "/gemini/generate",
            headers={
                "Origin": ALLOWED_ORIGIN,
                "Access-Control-Request-Method": "POST",
                "Access-Control-Request-Headers": "authorization,content-type",
            },
        )
        self.assertNotEqual(preflight.status_code, 401)
        self.assertEqual(preflight.headers.get("access-control-allow-origin"), ALLOWED_ORIGIN)

    def test_wildcard_and_unknown_origin_are_rejected(self) -> None:
        os.environ["MEDCONNECT_CORS_ORIGINS"] = "*"
        get_settings.cache_clear()
        origins = resolve_cors_origins()
        self.assertNotIn("*", origins)
        self.client.__exit__(None, None, None)
        self.client = _client()
        response = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers={"Origin": EVIL_ORIGIN},
        )
        self.assertEqual(response.status_code, 401)
        allowed = response.headers.get("access-control-allow-origin")
        self.assertNotEqual(allowed, "*")
        self.assertNotEqual(allowed, EVIL_ORIGIN)


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(ServiceAuthTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("PASS" if result.wasSuccessful() else "FAIL")
    raise SystemExit(0 if result.wasSuccessful() else 1)
