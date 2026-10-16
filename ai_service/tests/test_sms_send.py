"""SMS endpoint checks. Does not call TextBee."""

from __future__ import annotations

import os
import sys
import unittest
from contextlib import asynccontextmanager
from pathlib import Path
from unittest.mock import patch

import httpx

_SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(_SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(_SERVICE_ROOT))

TOKEN = "unit-test-service-token"
TEXTBEE_KEY = "unit-test-textbee-secret"
os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN

from fastapi.testclient import TestClient  # noqa: E402

from app.core.config import get_settings  # noqa: E402
from app.main import create_app  # noqa: E402

PHONE = "+639171234567"
MESSAGE = "Follow-up reminder from the test."


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


class SmsSendTests(unittest.TestCase):
    def setUp(self) -> None:
        os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN
        os.environ["TEXTBEE_API_KEY"] = TEXTBEE_KEY
        os.environ["TEXTBEE_DEVICE_ID"] = "unit-device"
        os.environ["TEXTBEE_SEND_ENABLED"] = "false"
        get_settings.cache_clear()
        self.client = _client()
        self.outbound = 0

    def tearDown(self) -> None:
        self.client.__exit__(None, None, None)
        get_settings.cache_clear()

    def _headers(self, token: str | None = TOKEN) -> dict:
        if token is None:
            return {}
        return {"Authorization": f"Bearer {token}"}

    def _body(self, **overrides) -> dict:
        payload = {"recipient": PHONE, "message": MESSAGE}
        payload.update(overrides)
        return payload

    def _reject_outbound(self, *_args, **_kwargs):
        self.outbound += 1
        raise AssertionError("TextBee was contacted")

    def test_missing_service_token_is_rejected(self) -> None:
        with patch("app.routers.sms._deliver", self._reject_outbound):
            response = self.client.post("/sms/send", json=self._body())
        self.assertEqual(response.status_code, 401)
        self.assertEqual(self.outbound, 0)
        self.assertNotIn(TOKEN, response.text)
        self.assertNotIn(TEXTBEE_KEY, response.text)

    def test_invalid_service_token_is_rejected(self) -> None:
        with patch("app.routers.sms._deliver", self._reject_outbound):
            response = self.client.post(
                "/sms/send",
                json=self._body(),
                headers=self._headers("not-the-token"),
            )
        self.assertEqual(response.status_code, 403)
        self.assertEqual(self.outbound, 0)
        self.assertNotIn("not-the-token", response.text)
        self.assertNotIn(TEXTBEE_KEY, response.text)

    def test_disabled_send_is_mocked_without_network(self) -> None:
        for flag in ("false", "", "1", "yes", "on"):
            os.environ["TEXTBEE_SEND_ENABLED"] = flag
            with patch("app.routers.sms._deliver", self._reject_outbound):
                response = self.client.post("/sms/send", json=self._body(), headers=self._headers())
            self.assertEqual(response.status_code, 200, flag)
            body = response.json()
            self.assertEqual(body["disposition"], "mocked")
            self.assertTrue(body["mocked"])
            self.assertFalse(body["delivered"])
            self.assertIn("not sent", body["message"].lower())
            self.assertNotIn(PHONE, response.text)
            self.assertNotIn(TEXTBEE_KEY, response.text)
        self.assertEqual(self.outbound, 0)

    def test_missing_textbee_credentials_do_not_send(self) -> None:
        os.environ["TEXTBEE_SEND_ENABLED"] = "true"
        os.environ["TEXTBEE_API_KEY"] = "  "
        with patch("app.routers.sms._deliver", self._reject_outbound):
            response = self.client.post("/sms/send", json=self._body(), headers=self._headers())
        self.assertEqual(response.status_code, 503)
        self.assertEqual(response.json()["disposition"], "rejected")
        self.assertEqual(self.outbound, 0)
        self.assertNotIn(TEXTBEE_KEY, response.text)
        self.assertNotIn(PHONE, response.text)

    def test_invalid_phone_or_message_is_rejected(self) -> None:
        with patch("app.routers.sms._deliver", self._reject_outbound):
            phone = self.client.post(
                "/sms/send",
                json=self._body(recipient="09171234567"),
                headers=self._headers(),
            )
            empty = self.client.post(
                "/sms/send",
                json=self._body(message="   "),
                headers=self._headers(),
            )
            long_message = self.client.post(
                "/sms/send",
                json=self._body(message="A" * 501),
                headers=self._headers(),
            )
        self.assertEqual(phone.status_code, 400)
        self.assertEqual(empty.status_code, 400)
        self.assertEqual(long_message.status_code, 400)
        self.assertEqual(self.outbound, 0)
        self.assertNotIn("09171234567", phone.text)
        self.assertNotIn(TEXTBEE_KEY, long_message.text)

    def test_acceptance_is_not_delivery(self) -> None:
        os.environ["TEXTBEE_SEND_ENABLED"] = "true"

        def _accepted(payload, api_key):
            self.outbound += 1
            self.assertEqual(api_key, TEXTBEE_KEY)
            self.assertNotIn("api_key", payload)
            self.assertEqual(payload["deviceId"], "unit-device")
            return 200, '{"smsBatchId":"batch-1","status":"SENT"}'

        with patch("app.routers.sms._deliver", _accepted):
            response = self.client.post("/sms/send", json=self._body(), headers=self._headers())
        body = response.json()
        self.assertEqual(response.status_code, 200)
        self.assertEqual(body["disposition"], "accepted")
        self.assertFalse(body["delivered"])
        self.assertIn("does not confirm", body["message"].lower())
        self.assertNotIn("delivered", body["message"].lower())
        self.assertEqual(body["provider_ref"], "batch-1")
        self.assertNotIn(TEXTBEE_KEY, response.text)
        self.assertEqual(self.outbound, 1)

    def test_timeout_and_error_hide_sensitive_data(self) -> None:
        os.environ["TEXTBEE_SEND_ENABLED"] = "true"

        def _timeout(_payload, _api_key):
            self.outbound += 1
            raise httpx.TimeoutException(f"timed out with {TEXTBEE_KEY} for {PHONE} {MESSAGE}")

        with patch("app.routers.sms._deliver", _timeout):
            with self.assertLogs("medconnect.api", level="WARNING") as logs:
                response = self.client.post("/sms/send", json=self._body(), headers=self._headers())
        self.assertEqual(response.status_code, 504)
        self.assertEqual(response.json()["disposition"], "uncertain")
        leaked = response.text + "\n" + "\n".join(logs.output)
        self.assertNotIn(TEXTBEE_KEY, leaked)
        self.assertNotIn(PHONE, leaked)
        self.assertNotIn(MESSAGE, leaked)
        self.assertNotIn(TOKEN, leaked)

    def test_health_and_ai_route_remain_available(self) -> None:
        health = self.client.get("/health")
        self.assertEqual(health.status_code, 200)
        self.assertTrue(health.json().get("success"))
        ai = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers=self._headers(),
        )
        self.assertEqual(ai.status_code, 400)
        self.assertIn("contents", ai.text)
        faq = self.client.post("/faq-chatbot/assist", json={})
        self.assertEqual(faq.status_code, 401)


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(SmsSendTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("PASS" if result.wasSuccessful() else "FAIL")
    raise SystemExit(0 if result.wasSuccessful() else 1)
