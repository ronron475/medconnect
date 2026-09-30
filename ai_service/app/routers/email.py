"""Transactional email for Hostinger. Gmail SMTP credentials stay in the environment."""

from __future__ import annotations

import asyncio
import logging
import os
import re
import smtplib
from email.message import EmailMessage
from email.utils import formataddr

from fastapi import APIRouter, HTTPException, Request
from pydantic import BaseModel, Field

from app.middleware.service_auth import tokens_match

router = APIRouter(tags=["Email"])
logger = logging.getLogger("medconnect.api")

_EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")
_HEADER_BREAK = re.compile(r"[\r\n]")
_MAX_SUBJECT = 200
_MAX_HTML = 100_000
_MAX_TEXT = 50_000


class EmailSendRequest(BaseModel):
    recipient: str = ""
    subject: str = ""
    html: str = ""
    text: str = Field(default="")


def _env(name: str, default: str = "") -> str:
    return os.environ.get(name, default).strip()


def _require_email_key(request: Request) -> None:
    expected = _env("EMAIL_API_KEY")
    if expected == "":
        raise HTTPException(status_code=503, detail="Email service is not configured.")

    provided = (request.headers.get("x-email-api-key") or "").strip()
    if provided == "":
        raise HTTPException(status_code=401, detail="Unauthorized")
    if not tokens_match(provided, expected):
        raise HTTPException(status_code=403, detail="Forbidden")


def _validated_message(body: EmailSendRequest) -> tuple[str, str, str, str]:
    recipient = body.recipient.strip()
    subject = body.subject.strip()
    html = body.html.strip()
    text = body.text.strip()

    if recipient == "" or subject == "" or html == "":
        raise HTTPException(status_code=400, detail="recipient, subject, and html are required.")
    if _HEADER_BREAK.search(recipient) or _HEADER_BREAK.search(subject):
        raise HTTPException(status_code=400, detail="Recipient email is invalid.")
    if not _EMAIL_RE.fullmatch(recipient):
        raise HTTPException(status_code=400, detail="Recipient email is invalid.")
    if len(subject) > _MAX_SUBJECT or len(html) > _MAX_HTML or len(text) > _MAX_TEXT:
        raise HTTPException(status_code=400, detail="Email content is too long.")
    return recipient, subject, html, text


def _smtp_settings() -> tuple[str, int, str, str, str, str]:
    host = _env("MAIL_HOST", "smtp.gmail.com") or "smtp.gmail.com"
    port_raw = _env("MAIL_PORT", "587") or "587"
    username = _env("MAIL_USERNAME")
    password = _env("MAIL_PASSWORD")
    from_email = _env("MAIL_FROM_EMAIL")
    from_name = _env("MAIL_FROM_NAME", "MedConnect Bago City") or "MedConnect Bago City"
    try:
        port = int(port_raw)
    except ValueError:
        port = 0
    if (
        host == ""
        or port <= 0
        or username == ""
        or password == ""
        or from_email == ""
        or not _EMAIL_RE.fullmatch(from_email)
        or _HEADER_BREAK.search(from_name)
    ):
        raise HTTPException(status_code=503, detail="Email service is not configured.")
    return host, port, username, password, from_email, from_name


def _deliver(
    host: str,
    port: int,
    username: str,
    password: str,
    from_email: str,
    from_name: str,
    recipient: str,
    subject: str,
    html: str,
    text: str,
) -> None:
    message = EmailMessage()
    message["Subject"] = subject
    message["From"] = formataddr((from_name, from_email))
    message["To"] = recipient
    if text:
        message.set_content(text)
        message.add_alternative(html, subtype="html")
    else:
        message.set_content(html, subtype="html")

    if port == 465:
        smtp_client = smtplib.SMTP_SSL(host, port, timeout=12)
    else:
        smtp_client = smtplib.SMTP(host, port, timeout=12)
    with smtp_client as smtp:
        smtp.ehlo()
        if port != 465:
            smtp.starttls()
            smtp.ehlo()
        smtp.login(username, password)
        smtp.send_message(message)


@router.post("/email/send", summary="Send a MedConnect transactional email")
async def send_email(body: EmailSendRequest, request: Request) -> dict:
    _require_email_key(request)
    recipient, subject, html, text = _validated_message(body)
    host, port, username, password, from_email, from_name = _smtp_settings()
    try:
        await asyncio.to_thread(
            _deliver,
            host,
            port,
            username,
            password,
            from_email,
            from_name,
            recipient,
            subject,
            html,
            text,
        )
    except HTTPException:
        raise
    except Exception as exc:
        logger.warning("email send failed: %s", type(exc).__name__)
        raise HTTPException(status_code=502, detail="Email could not be sent.") from exc

    return {"success": True, "message": "Email sent."}
