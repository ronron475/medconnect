"""Mocked Gemini HTTP 429 uses Gemini 3.8 once, then existing OpenRouter.

Does not call Google or OpenRouter. Does not print API keys.
"""

from __future__ import annotations

import io
import json
import os
import sys
import unittest
import urllib.error
from contextlib import asynccontextmanager
from pathlib import Path
from unittest.mock import patch

_SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(_SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(_SERVICE_ROOT))

os.environ.setdefault("MEDCONNECT_AI_SERVICE_TOKEN", "unit-test-service-token")

import gemini_client  # noqa: E402

try:
    from fastapi.testclient import TestClient  # noqa: E402
    from app.core.config import get_settings  # noqa: E402
    from app.main import create_app  # noqa: E402

    _FASTAPI_IMPORT_ERROR = ""
except Exception as exc:  # pragma: no cover - optional route smoke
    TestClient = None  # type: ignore[misc, assignment]
    get_settings = None  # type: ignore[misc, assignment]
    create_app = None  # type: ignore[misc, assignment]
    _FASTAPI_IMPORT_ERROR = str(exc)

GEMINI_KEY = "unit-gemini-key"
OPENROUTER_KEY = "unit-openrouter-key"
SYSTEM = "Return JSON only. Never assign Emergency, Urgent, or Non-Urgent."
USER = "MODE: START_INTERVIEW\nPatient input: I have a headache since yesterday"
OPENROUTER_TEXT = '{"classification":"HEALTH_RELATED","clinical_facts":{"symptom":"headache"}}'
GEMINI_38_TEXT = '{"classification":"HEALTH_RELATED","clinical_facts":{"symptom":"headache"},"source":"gemini-3.8"}'


def _http_error(code: int, body: bytes = b"") -> urllib.error.HTTPError:
    return urllib.error.HTTPError(
        url="https://generativelanguage.googleapis.com/mock",
        code=code,
        msg="mock",
        hdrs=None,
        fp=io.BytesIO(body),
    )


def _gemini_payload() -> dict:
    return {
        "systemInstruction": {"parts": [{"text": SYSTEM}]},
        "contents": [{"role": "user", "parts": [{"text": USER}]}],
        "generationConfig": {
            "temperature": 0.1,
            "maxOutputTokens": 1024,
            "responseMimeType": "application/json",
        },
    }


def _gemini_ok(text: str = OPENROUTER_TEXT):
    return {"candidates": [{"content": {"parts": [{"text": text}]}}]}


@asynccontextmanager
async def _no_startup(_app):
    yield


class GeminiOpenRouterQuotaFallbackTests(unittest.TestCase):
    def setUp(self) -> None:
        os.environ["AI_API_KEY"] = GEMINI_KEY
        os.environ["OPENROUTER_API_KEY"] = OPENROUTER_KEY
        self.payload = _gemini_payload()

    def tearDown(self) -> None:
        os.environ.pop("OPENROUTER_API_KEY", None)

    def _assert_no_keys(self, blob: str) -> None:
        self.assertNotIn(GEMINI_KEY, blob)
        self.assertNotIn(OPENROUTER_KEY, blob)

    def test_gemini_success_does_not_call_fallback_or_openrouter(self) -> None:
        models: list[str] = []

        def _post(payload, model, key, timeout):
            models.append(model)
            return _gemini_ok()

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete"
        ) as openrouter:
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(models, ["gemini-3.5-flash"])
        self.assertNotIn(gemini_client.GEMINI_FALLBACK_MODEL, models)
        openrouter.assert_not_called()
        self.assertEqual(pack["text"], OPENROUTER_TEXT)
        self.assertEqual(pack["model"], "gemini-3.5-flash")
        self._assert_no_keys(json.dumps(pack))

    def test_http_429_gemini_38_success_skips_openrouter(self) -> None:
        models: list[str] = []

        def _post(payload, model, key, timeout):
            models.append(model)
            if model == "gemini-3.5-flash":
                raise _http_error(429, b'{"error":{"code":429}}')
            return _gemini_ok(GEMINI_38_TEXT)

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete"
        ) as openrouter:
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(models, ["gemini-3.5-flash", gemini_client.GEMINI_FALLBACK_MODEL])
        openrouter.assert_not_called()
        self.assertEqual(pack["text"], GEMINI_38_TEXT)
        self.assertEqual(pack["model"], gemini_client.GEMINI_FALLBACK_MODEL)
        self.assertEqual(
            pack["response"]["candidates"][0]["content"]["parts"][0]["text"],
            GEMINI_38_TEXT,
        )
        self._assert_no_keys(json.dumps(pack))

    def test_http_429_strips_thinking_config_for_gemini_38_only(self) -> None:
        seen: list[tuple[str, dict]] = []

        def _post(payload, model, key, timeout):
            seen.append((model, payload))
            if model == "gemini-3.5-flash":
                raise _http_error(429, b'{"error":{"code":429}}')
            return _gemini_ok(GEMINI_38_TEXT)

        body = _gemini_payload()
        body["generationConfig"] = dict(body["generationConfig"])
        body["generationConfig"]["thinkingConfig"] = {"thinkingLevel": "MINIMAL"}

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete"
        ) as openrouter:
            pack = gemini_client.generate_content(body, model="gemini-3.5-flash", timeout=15)

        self.assertEqual([row[0] for row in seen], ["gemini-3.5-flash", gemini_client.GEMINI_FALLBACK_MODEL])
        primary = seen[0][1]
        secondary = seen[1][1]
        self.assertEqual(primary["contents"], body["contents"])
        self.assertEqual(secondary["contents"], body["contents"])
        self.assertIn("thinkingConfig", (primary.get("generationConfig") or {}))
        self.assertNotIn("thinkingConfig", (secondary.get("generationConfig") or {}))
        openrouter.assert_not_called()
        self.assertEqual(pack["model"], gemini_client.GEMINI_FALLBACK_MODEL)

    def test_github_openrouter_model_and_json_format_preserved(self) -> None:
        self.assertEqual(
            gemini_client.OPENROUTER_DEMO_MODEL,
            "nvidia/nemotron-3-super-120b-a12b:free",
        )
        body = gemini_client._openrouter_body_from_gemini(self.payload)
        self.assertIsNotNone(body)
        assert body is not None
        self.assertEqual(body["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(body["response_format"], {"type": "json_object"})
        self.assertNotIn("reasoning", body)

    def test_github_openrouter_reasoning_helper_unchanged(self) -> None:
        decoded = {
            "choices": [{
                "message": {
                    "role": "assistant",
                    "content": None,
                    "reasoning": "draft\n" + OPENROUTER_TEXT,
                },
            }],
        }
        text = gemini_client._openrouter_choice_text(decoded)
        self.assertEqual(text, OPENROUTER_TEXT)

    def test_http_429_gemini_38_empty_calls_openrouter(self) -> None:
        models: list[str] = []

        def _post(payload, model, key, timeout):
            models.append(model)
            if model == "gemini-3.5-flash":
                raise _http_error(429, b'{"error":{"code":429}}')
            return {"candidates": [{"content": {"parts": [{"text": ""}]}}]}

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete", return_value=OPENROUTER_TEXT
        ) as openrouter:
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(models, ["gemini-3.5-flash", gemini_client.GEMINI_FALLBACK_MODEL])
        openrouter.assert_called_once()
        self.assertEqual(pack["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(pack["text"], OPENROUTER_TEXT)

    def test_http_429_gemini_38_thought_only_calls_openrouter(self) -> None:
        models: list[str] = []
        thought = {
            "candidates": [{
                "content": {
                    "parts": [{"text": "internal thought", "thought": True}],
                },
            }],
        }

        def _post(payload, model, key, timeout):
            models.append(model)
            if model == "gemini-3.5-flash":
                raise _http_error(429, b'{"error":{"code":429}}')
            return thought

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete", return_value=OPENROUTER_TEXT
        ) as openrouter:
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(models, ["gemini-3.5-flash", gemini_client.GEMINI_FALLBACK_MODEL])
        openrouter.assert_called_once()
        self.assertEqual(pack["model"], gemini_client.OPENROUTER_DEMO_MODEL)

    def test_http_429_gemini_38_fail_calls_openrouter_once(self) -> None:
        models: list[str] = []
        seen: list[dict] = []

        def _post(payload, model, key, timeout):
            models.append(model)
            raise _http_error(429, b'{"error":{"code":429}}')

        def _openrouter(payload, timeout):
            body = gemini_client._openrouter_body_from_gemini(payload)
            self.assertIsNotNone(body)
            seen.append(body)
            return OPENROUTER_TEXT

        with patch.object(gemini_client, "_post_generate", side_effect=_post), patch.object(
            gemini_client, "_openrouter_http_complete", side_effect=_openrouter
        ):
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(models, ["gemini-3.5-flash", gemini_client.GEMINI_FALLBACK_MODEL])
        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0]["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(seen[0]["response_format"], {"type": "json_object"})
        self.assertEqual(pack["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(pack["text"], OPENROUTER_TEXT)

    def test_http_429_calls_openrouter_once_with_the_same_context(self) -> None:
        seen: list[dict] = []

        def _openrouter(payload, timeout):
            body = gemini_client._openrouter_body_from_gemini(payload)
            self.assertIsNotNone(body)
            seen.append(body)
            self.assertGreaterEqual(timeout, 5)
            return OPENROUTER_TEXT

        with patch.object(gemini_client, "_post_generate", side_effect=_http_error(429, b'{"error":{"code":429}}')), \
             patch.object(gemini_client, "_openrouter_http_complete", side_effect=_openrouter):
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(len(seen), 1)
        messages = seen[0]["messages"]
        self.assertEqual(messages[0], {"role": "system", "content": SYSTEM})
        self.assertEqual(messages[1], {"role": "user", "content": USER})
        self.assertEqual(seen[0]["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(seen[0]["response_format"], {"type": "json_object"})
        self.assertNotIn("reasoning", seen[0])
        self.assertGreaterEqual(seen[0]["max_tokens"], 2048)
        self.assertEqual(pack["text"], OPENROUTER_TEXT)
        self.assertEqual(pack["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self.assertEqual(
            pack["response"]["candidates"][0]["content"]["parts"][0]["text"],
            OPENROUTER_TEXT,
        )
        self._assert_no_keys(json.dumps(pack))

    def test_gemini_success_does_not_call_openrouter(self) -> None:
        with patch.object(gemini_client, "_post_generate", side_effect=lambda *_a, **_k: _gemini_ok()) as gemini, \
             patch.object(gemini_client, "_openrouter_http_complete") as openrouter:
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(gemini.call_count, 1)
        openrouter.assert_not_called()
        self.assertEqual(pack["text"], OPENROUTER_TEXT)
        self.assertEqual(pack["model"], "gemini-3.5-flash")
        self._assert_no_keys(json.dumps(pack))

    def test_http_503_does_not_call_openrouter_or_gemini_38(self) -> None:
        models: list[str] = []

        def _raise_503(payload, model, key, timeout):
            models.append(model)
            raise _http_error(503, b"high demand")

        with patch.object(gemini_client, "_post_generate", side_effect=_raise_503), \
             patch("time.sleep"), \
             patch.object(gemini_client, "_openrouter_http_complete") as openrouter:
            with self.assertRaises(RuntimeError) as caught:
                gemini_client.generate_content(self.payload, timeout=15)

        openrouter.assert_not_called()
        self.assertNotIn(gemini_client.GEMINI_FALLBACK_MODEL, models)
        self.assertIn("503", str(caught.exception))
        self.assertNotIn("429", str(caught.exception))
        self._assert_no_keys(str(caught.exception))

    def test_http_400_does_not_call_openrouter(self) -> None:
        with patch.object(gemini_client, "_post_generate", side_effect=_http_error(400, b"bad request")), \
             patch.object(gemini_client, "_openrouter_http_complete") as openrouter:
            with self.assertRaises(RuntimeError) as caught:
                gemini_client.generate_content(self.payload, timeout=15)

        openrouter.assert_not_called()
        self.assertIn("400", str(caught.exception))
        self._assert_no_keys(str(caught.exception))

    def test_http_429_without_openrouter_key_does_not_call_out(self) -> None:
        os.environ.pop("OPENROUTER_API_KEY", None)
        with patch.object(gemini_client, "_post_generate", side_effect=_http_error(429, b"quota")), \
             patch("urllib.request.urlopen") as urlopen:
            with self.assertRaises(RuntimeError) as caught:
                gemini_client.generate_content(self.payload, timeout=15)

        urlopen.assert_not_called()
        self.assertIn("429", str(caught.exception))
        self._assert_no_keys(str(caught.exception))

    def test_existing_route_returns_php_data_shape(self) -> None:
        if TestClient is None or get_settings is None or create_app is None:
            self.skipTest("ai_service app extras not installed: " + _FASTAPI_IMPORT_ERROR)
        get_settings.cache_clear()
        app = create_app()
        app.router.lifespan_context = _no_startup
        client = TestClient(app)
        client.__enter__()
        try:
            with patch.object(gemini_client, "_post_generate", side_effect=_http_error(429, b"quota")), \
                 patch.object(gemini_client, "_openrouter_http_complete", return_value=OPENROUTER_TEXT) as openrouter:
                response = client.post(
                    "/gemini/generate",
                    json={"payload": self.payload, "model": "gemini-3.5-flash", "timeout": 15},
                    headers={"Authorization": "Bearer unit-test-service-token"},
                )
        finally:
            client.__exit__(None, None, None)
            get_settings.cache_clear()

        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertTrue(body.get("success"))
        self.assertEqual(body["data"]["text"], OPENROUTER_TEXT)
        self.assertIn("candidates", body["data"]["response"])
        self.assertEqual(openrouter.call_count, 1)
        self._assert_no_keys(response.text)

    def test_gemini_429_openrouter_json_uses_existing_response_pack(self) -> None:
        """HTTP 429 → Gemini 3.8 fail → one OpenRouter completion → JSON text in the Gemini pack."""

        class _Response:
            status = 200

            def read(self) -> bytes:
                return json.dumps({
                    "choices": [{
                        "message": {
                            "role": "assistant",
                            "content": None,
                            "reasoning": "draft\n" + OPENROUTER_TEXT,
                        },
                    }],
                }).encode("utf-8")

            def __enter__(self):
                return self

            def __exit__(self, *_args):
                return False

        seen: list[dict] = []

        def _urlopen(req, timeout=0):
            seen.append(json.loads(req.data.decode("utf-8")))
            self.assertGreaterEqual(timeout, 5)
            return _Response()

        with patch.object(gemini_client, "_post_generate", side_effect=_http_error(429, b'{"error":{"code":429}}')), \
             patch("urllib.request.urlopen", side_effect=_urlopen):
            pack = gemini_client.generate_content(self.payload, model="gemini-3.5-flash", timeout=15)

        self.assertEqual(len(seen), 1)
        self.assertEqual(seen[0]["response_format"], {"type": "json_object"})
        self.assertNotIn("reasoning", seen[0])
        self.assertEqual(pack["text"], OPENROUTER_TEXT)
        self.assertEqual(
            pack["response"]["candidates"][0]["content"]["parts"][0]["text"],
            OPENROUTER_TEXT,
        )
        self.assertEqual(pack["model"], gemini_client.OPENROUTER_DEMO_MODEL)
        self._assert_no_keys(json.dumps(pack))
        self._assert_no_keys(json.dumps(seen))


if __name__ == "__main__":
    unittest.main()
