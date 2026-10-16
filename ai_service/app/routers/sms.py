"""TextBee SMS for the existing Railway service.

The TextBee API key stays in the environment. Callers authenticate with the
shared service token. A disabled flag returns a mock and does not call TextBee.
"""

from __future__ import annotations

import asyncio
import json
import logging
import os
import re

import httpx
from fastapi import APIRouter
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ConfigDict, Field

router = APIRouter(tags=["SMS"])
logger = logging.getLogger("medconnect.api")

_PHONE_RE = re.compile(r"^\+\d{10,15}$")
_REF_RE = re.compile(r"^[A-Za-z0-9_-]{1,80}$")
_MAX_MESSAGE = 500
_TEXTBEE_URL = "https://api.textbee.dev/api/v1/gateway/send-sms"
_ACCEPTED_MESSAGE = (
    "TextBee accepted the SMS for delivery. Acceptance does not confirm the patient received it."
)
_MOCK_MESSAGE = "SMS was not sent. TextBee live sending is disabled."


class SmsSendRequest(BaseModel):
    model_config = ConfigDict(extra="ignore")

    recipient: str = ""
    message: str = Field(default="", max_length=2000)


def _env(name: str) -> str:
    return os.environ.get(name, "").strip()


def _send_enabled() -> bool:
    return _env("TEXTBEE_SEND_ENABLED").lower() == "true"


def _safe_ref(value: object) -> str | None:
    if not isinstance(value, str):
        return None
    ref = value.strip()
    if _REF_RE.fullmatch(ref) is None:
        return None
    return ref


def _payload(body: SmsSendRequest) -> dict:
    recipient = body.recipient.strip()
    message = body.message.strip()
    if _PHONE_RE.fullmatch(recipient) is None:
        return {}
    if message == "" or len(message) > _MAX_MESSAGE:
        return {}
    payload = {"recipients": [recipient], "message": message}
    device_id = _env("TEXTBEE_DEVICE_ID")
    if device_id != "":
        payload["deviceId"] = device_id
    return payload


def _mock() -> dict:
    return {
        "success": True,
        "status": "mocked",
        "message": _MOCK_MESSAGE,
        "provider_ref": None,
        "mocked": True,
        "disposition": "mocked",
        "delivered": False,
    }


def _failure(status_code: int, message: str, disposition: str) -> JSONResponse:
    return JSONResponse(
        status_code=status_code,
        content={
            "success": False,
            "status": "failed",
            "message": message,
            "provider_ref": None,
            "mocked": False,
            "disposition": disposition,
            "delivered": False,
        },
    )


def _deliver(payload: dict, api_key: str) -> tuple[int, str]:
    timeout = httpx.Timeout(10.0, connect=5.0)
    with httpx.Client(timeout=timeout) as client:
        response = client.post(
            _TEXTBEE_URL,
            json=payload,
            headers={
                "Content-Type": "application/json",
                "x-api-key": api_key,
            },
        )
    return response.status_code, response.text[:2000]


def _accepted_ref(raw: str) -> str | None:
    try:
        decoded = json.loads(raw)
    except json.JSONDecodeError:
        return None
    if not isinstance(decoded, dict):
        return None
    data = decoded.get("data")
    candidates = [
        decoded.get("smsBatchId"),
        data.get("smsBatchId") if isinstance(data, dict) else None,
        decoded.get("_id"),
    ]
    for candidate in candidates:
        ref = _safe_ref(candidate)
        if ref is not None:
            return ref
    return None


@router.post("/sms/send", summary="Send one SMS through TextBee", response_model=None)
async def send_sms(body: SmsSendRequest) -> dict | JSONResponse:
    payload = _payload(body)
    if payload == {}:
        return _failure(400, "Phone number or message is invalid.", "rejected")

    if not _send_enabled():
        return _mock()

    api_key = _env("TEXTBEE_API_KEY")
    if api_key == "":
        return _failure(503, "SMS gateway is not configured.", "rejected")

    try:
        status_code, raw = await asyncio.to_thread(_deliver, payload, api_key)
    except httpx.TimeoutException:
        logger.warning("sms send failed: timeout")
        return _failure(504, "TextBee did not confirm whether the SMS was accepted.", "uncertain")
    except httpx.HTTPError:
        logger.warning("sms send failed: transport")
        return _failure(502, "TextBee did not confirm whether the SMS was accepted.", "uncertain")
    except Exception:
        logger.warning("sms send failed: error")
        return _failure(502, "TextBee did not confirm whether the SMS was accepted.", "uncertain")

    if 200 <= status_code < 300:
        return {
            "success": True,
            "status": "accepted",
            "message": _ACCEPTED_MESSAGE,
            "provider_ref": _accepted_ref(raw),
            "mocked": False,
            "disposition": "accepted",
            "delivered": False,
        }

    if 400 <= status_code < 500:
        logger.warning("sms send rejected: http %s", status_code)
        return _failure(502, "TextBee rejected the SMS.", "rejected")

    logger.warning("sms send uncertain: http %s", status_code)
    return _failure(502, "TextBee did not confirm whether the SMS was accepted.", "uncertain")
