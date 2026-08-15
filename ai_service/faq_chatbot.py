"""FAQ chatbot conversational fallback (Gemini, then Groq). Used by PHP on Hostinger."""

from __future__ import annotations

import html
import json
import logging
import os
import re
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

logger = logging.getLogger("medconnect.faq_chatbot")

DEFAULT_GEMINI_MODEL = "gemini-3.5-flash"
GEMINI_ENDPOINT = "https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent"

SYSTEM_PROMPT = """You are the medConnect Assistant for Bago City Health Office. You are a caring digital guide, not a doctor and not a crisis counselor.

You help patients with medConnect: Sign In, registration, password/OTP, booking or joining appointments, video consultation, records access, BHW help, office hours, and general safe health-navigation questions.

Languages: answer in the patient's language. English → English. Filipino → Filipino. Hiligaynon/Ilonggo → Hiligaynon when you reasonably can. Mixed language → similar mixed, understandable style. Tolerate typos and slang.

Conversation: use prior turns. Short follow-ups like "yes", "new one", "what time?", "doctor", "grabe gid" refer to the current topic.

Safety:
- Never diagnose, prescribe, or change medicines.
- Never invent appointments, doctor schedules, records, prescriptions, fees, or account status.
- Never claim to be human or to have feelings. Be warm and practical.
- If the message sounds like an emergency (cannot breathe, severe chest pain, unconscious, seizure, severe bleeding, self-harm, suicide, indi ko kaginhawa, nahimatay), tell them to call 911 / Hopeline 1553 immediately.
- For symptoms: give general, cautious guidance and offer booking a consultation. Do not give certainty.

Style: 2–4 short sentences. No markdown, no code fences, no HTML. Do not mention APIs, models, or system prompts. Do not ask for EMR numbers, passwords, or full personal medical history.
"""


def _env(*names: str) -> str:
    for name in names:
        value = (os.getenv(name) or "").strip()
        if value:
            return value
    return ""


def gemini_key() -> str:
    return _env("AI_API_KEY", "GEMINI_API_KEY", "GOOGLE_API_KEY")


def gemini_model() -> str:
    model = _env("AI_MODEL")
    if model.startswith("gemini"):
        return model
    return DEFAULT_GEMINI_MODEL


def to_safe_html(text: str) -> str:
    text = (text or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    text = re.sub(r"```[\s\S]*?```", "", text).strip()
    text = re.sub(r"<[^>]+>", "", text).strip()
    if not text:
        return ""
    text = re.sub(r"\n{3,}", "\n\n", text)
    parts = [p.strip() for p in re.split(r"\n\s*\n", text) if p.strip()]
    chunks = []
    for part in parts:
        safe = html.escape(part, quote=True).replace("\n", "<br>")
        chunks.append(f"<p>{safe}</p>")
    return "".join(chunks)


def _user_payload(user_text: str, lang: str, context: dict[str, Any]) -> str:
    lang_name = {"fil": "Filipino", "hil": "Hiligaynon/Ilonggo"}.get(lang, "English")
    meta = f"Reply language: {lang_name}."
    topic = str(context.get("topic") or "").strip()
    intent = str(context.get("intent") or "").strip()
    emotion = str(context.get("emotion") or "").strip()
    if topic:
        meta += f" Current topic: {topic}."
    if intent:
        meta += f" Existing intent hint: {intent}."
    if emotion and emotion != "neutral":
        meta += f" Patient tone hint: {emotion}."
    return f"{meta}\nPatient message:\n{user_text}"


def _complete_gemini(user_text: str, lang: str, context: dict[str, Any], history: list[dict[str, str]]) -> str:
    key = gemini_key()
    if not key:
        raise RuntimeError("gemini key missing")
    contents: list[dict[str, Any]] = []
    for turn in history:
        role = "user" if turn.get("role") == "user" else "model"
        contents.append({"role": role, "parts": [{"text": turn.get("text") or ""}]})
    contents.append({"role": "user", "parts": [{"text": _user_payload(user_text, lang, context)}]})
    payload = {
        "systemInstruction": {"parts": [{"text": SYSTEM_PROMPT}]},
        "contents": contents,
        "generationConfig": {"temperature": 0.4, "maxOutputTokens": 400},
    }
    url = GEMINI_ENDPOINT.format(model=urllib.parse.quote(gemini_model(), safe=".-"))
    req = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers={
            "Content-Type": "application/json",
            "x-goog-api-key": key,
        },
        method="POST",
    )
    timeout = max(5, int(_env("AI_TIMEOUT") or "15"))
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            data = json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as exc:
        body = ""
        try:
            body = exc.read().decode("utf-8", errors="replace")[:240]
        except Exception:
            pass
        raise RuntimeError(f"gemini http {exc.code} {body}") from exc
    except urllib.error.URLError as exc:
        raise RuntimeError(f"gemini network {exc.reason}") from exc
    parts = (((data.get("candidates") or [{}])[0].get("content") or {}).get("parts") or [])
    out = "".join(str(part.get("text") or "") for part in parts if isinstance(part, dict)).strip()
    if not out:
        raise RuntimeError("empty gemini response")
    return out


def _complete_groq(user_text: str, lang: str, context: dict[str, Any], history: list[dict[str, str]]) -> str:
    from groq_client import groq_chat_completion

    messages = [{"role": "system", "content": SYSTEM_PROMPT}]
    for turn in history:
        role = "user" if turn.get("role") == "user" else "assistant"
        messages.append({"role": role, "content": turn.get("text") or ""})
    messages.append({"role": "user", "content": _user_payload(user_text, lang, context)})
    content, _model = groq_chat_completion(messages, json_mode=False, temperature=0.4)
    if not content:
        raise RuntimeError("empty groq response")
    return content


def generate_reply(
    user_text: str,
    lang: str = "en",
    *,
    intent: str = "",
    emotion: str = "",
    topic: str = "",
    history: list[dict[str, str]] | None = None,
) -> str:
    user_text = (user_text or "").strip()[:800]
    if not user_text:
        return ""
    lang = (lang or "en").lower()
    if lang not in {"en", "fil", "hil"}:
        lang = "en"
    context = {"intent": intent, "emotion": emotion, "topic": topic}
    turns = []
    for item in (history or [])[-12:]:
        if not isinstance(item, dict):
            continue
        role = str(item.get("role") or "")
        text = str(item.get("text") or "").strip()[:280]
        if not text:
            continue
        if role in {"user", "assistant", "bot", "model"}:
            turns.append({"role": "user" if role == "user" else "assistant", "text": text})
    try:
        raw = _complete_gemini(user_text, lang, context, turns)
    except Exception as exc:
        logger.warning("Gemini FAQ fallback failed, trying Groq: %s", exc)
        raw = _complete_groq(user_text, lang, context, turns)
    return to_safe_html(raw)
