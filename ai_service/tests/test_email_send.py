"""Email endpoint checks. Does not open a real SMTP connection."""

from __future__ import annotations

import os
import sys
import unittest
from contextlib import asynccontextmanager
from pathlib import Path
from unittest.mock import patch

_SERVICE_ROOT = Path(__file__).resolve().parents[1]
if str(_SERVICE_ROOT) not in sys.path:
    sys.path.insert(0, str(_SERVICE_ROOT))

TOKEN = "unit-test-service-token"
EMAIL_KEY = "unit-test-email-key"
MAIL_PASSWORD = "unit-test-mail-secret"
os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN

from fastapi.testclient import TestClient  # noqa: E402

from app.core.config import get_settings  # noqa: E402
from app.main import create_app  # noqa: E402


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


class _FakeSMTP:
    sent = None
    login_user = ""

    def __init__(self, host, port, timeout=None):
        self.host = host
        self.port = port
        self.timeout = timeout

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc, tb):
        return False

    def ehlo(self):
        return None

    def starttls(self):
        return None

    def login(self, username, password):
        _FakeSMTP.login_user = username
        if password != MAIL_PASSWORD:
            raise AssertionError("unexpected password passed to SMTP")

    def send_message(self, message):
        _FakeSMTP.sent = message


class EmailSendTests(unittest.TestCase):
    def setUp(self) -> None:
        os.environ["MEDCONNECT_AI_SERVICE_TOKEN"] = TOKEN
        os.environ["EMAIL_API_KEY"] = EMAIL_KEY
        os.environ["MAIL_HOST"] = "smtp.gmail.com"
        os.environ["MAIL_PORT"] = "587"
        os.environ["MAIL_USERNAME"] = "sender@gmail.com"
        os.environ["MAIL_PASSWORD"] = MAIL_PASSWORD
        os.environ["MAIL_FROM_EMAIL"] = "sender@gmail.com"
        os.environ["MAIL_FROM_NAME"] = "MedConnect Bago City"
        get_settings.cache_clear()
        self.client = _client()
        _FakeSMTP.sent = None
        _FakeSMTP.login_user = ""

    def tearDown(self) -> None:
        self.client.__exit__(None, None, None)
        get_settings.cache_clear()

    def _headers(self, email_key: str | None = EMAIL_KEY, service: bool = True) -> dict:
        headers = {}
        if service:
            headers["Authorization"] = f"Bearer {TOKEN}"
        if email_key is not None:
            headers["X-Email-Api-Key"] = email_key
        return headers

    def _body(self, **overrides) -> dict:
        payload = {
            "recipient": "patient@gmail.com",
            "subject": "Follow-up reminder",
            "html": "<p>Hello</p>",
            "text": "Hello",
        }
        payload.update(overrides)
        return payload

    def test_missing_service_credential_is_401(self) -> None:
        response = self.client.post("/email/send", json=self._body(), headers=self._headers(service=False))
        self.assertEqual(response.status_code, 401)
        self.assertNotIn(EMAIL_KEY, response.text)
        self.assertNotIn(MAIL_PASSWORD, response.text)

    def test_missing_email_key_is_401(self) -> None:
        response = self.client.post("/email/send", json=self._body(), headers=self._headers(email_key=None))
        self.assertEqual(response.status_code, 401)
        self.assertEqual(response.json()["success"], False)

    def test_wrong_email_key_is_403(self) -> None:
        response = self.client.post(
            "/email/send",
            json=self._body(),
            headers=self._headers(email_key="wrong-key"),
        )
        self.assertEqual(response.status_code, 403)
        self.assertNotIn("wrong-key", response.text)
        self.assertNotIn(MAIL_PASSWORD, response.text)

    def test_invalid_recipient_is_400(self) -> None:
        response = self.client.post(
            "/email/send",
            json=self._body(recipient="not-an-email"),
            headers=self._headers(),
        )
        self.assertEqual(response.status_code, 400)
        self.assertIn("invalid", response.json()["message"].lower())

    def test_missing_html_is_400(self) -> None:
        response = self.client.post(
            "/email/send",
            json=self._body(html="  "),
            headers=self._headers(),
        )
        self.assertEqual(response.status_code, 400)
        self.assertEqual(response.json()["success"], False)

    def test_send_succeeds_without_leaking_secrets(self) -> None:
        with patch("app.routers.email.smtplib.SMTP", _FakeSMTP):
            with self.assertLogs("medconnect.api", level="WARNING") as logs:
                response = self.client.post("/email/send", json=self._body(text=""), headers=self._headers())
                self.client.post("/email/send", json=self._body(recipient="bad"), headers=self._headers())
        self.assertEqual(response.status_code, 200)
        body = response.json()
        self.assertEqual(body, {"success": True, "message": "Email sent."})
        self.assertNotIn(MAIL_PASSWORD, response.text)
        self.assertNotIn(EMAIL_KEY, response.text)
        self.assertEqual(_FakeSMTP.login_user, "sender@gmail.com")
        self.assertIsNotNone(_FakeSMTP.sent)
        self.assertEqual(_FakeSMTP.sent["To"], "patient@gmail.com")
        joined = "\n".join(logs.output)
        self.assertNotIn(MAIL_PASSWORD, joined)
        self.assertNotIn(EMAIL_KEY, joined)

    def test_smtp_failure_hides_password(self) -> None:
        class BrokenSMTP(_FakeSMTP):
            def login(self, username, password):
                raise smtplib_error(password)

        with patch("app.routers.email.smtplib.SMTP", BrokenSMTP):
            with self.assertLogs("medconnect.api", level="WARNING") as logs:
                response = self.client.post("/email/send", json=self._body(), headers=self._headers())
        self.assertEqual(response.status_code, 502)
        self.assertEqual(response.json()["message"], "Email could not be sent.")
        self.assertNotIn(MAIL_PASSWORD, response.text)
        self.assertNotIn(MAIL_PASSWORD, "\n".join(logs.output))

    def test_port_465_uses_ssl_without_starttls(self) -> None:
        os.environ["MAIL_PORT"] = "465"

        class SSLClient(_FakeSMTP):
            starttls_called = False

            def starttls(self):
                SSLClient.starttls_called = True
                raise AssertionError("starttls must not run on port 465")

        class PlainSMTP:
            def __init__(self, *args, **kwargs):
                raise AssertionError("plain SMTP must not be used on port 465")

        with patch("app.routers.email.smtplib.SMTP_SSL", SSLClient), patch(
            "app.routers.email.smtplib.SMTP", PlainSMTP
        ):
            response = self.client.post("/email/send", json=self._body(), headers=self._headers())
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["success"], True)
        self.assertFalse(SSLClient.starttls_called)
        self.assertEqual(_FakeSMTP.login_user, "sender@gmail.com")
        self.assertNotIn(MAIL_PASSWORD, response.text)
        self.assertNotIn(EMAIL_KEY, response.text)

    def test_gemini_generate_still_rejects_empty_payload(self) -> None:
        response = self.client.post(
            "/gemini/generate",
            json={"payload": {}},
            headers={"Authorization": f"Bearer {TOKEN}"},
        )
        self.assertEqual(response.status_code, 400)
        self.assertIn("contents", response.text)


def smtplib_error(password: str) -> Exception:
    return RuntimeError(f"auth failed for secret {password}")


if __name__ == "__main__":
    suite = unittest.defaultTestLoader.loadTestsFromTestCase(EmailSendTests)
    result = unittest.TextTestRunner(verbosity=2).run(suite)
    print("PASS" if result.wasSuccessful() else "FAIL")
    raise SystemExit(0 if result.wasSuccessful() else 1)
