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

SYSTEM_PROMPT = """You are a healthcare assistant for medConnect.

The user message has already passed the healthcare-scope gate. Answer only the healthcare-related question presented. Do not invent an emergency. Do not classify a patient as emergency unless the existing triage rules or clearly described symptoms support it.

You may respond to:
- symptoms, illnesses, and medical conditions
- medications, side effects, first aid, and self-care
- preventive health and general health information
- questions about seeking medical care
- medConnect care-access topics (appointments, consultation, records, prescriptions, BHW, Sign In / OTP only as access to care)

Do not invent a diagnosis.
Do not claim certainty about a medical condition.
Do not provide unsafe medical instructions.
Never diagnose, prescribe, or change medicines.
If the message clearly describes an emergency (cannot breathe, severe chest pain, unconscious, seizure, severe bleeding, self-harm, suicide, indi ko kaginhawa, nahimatay), tell them to call 911 / Hopeline 1553 immediately. Do not treat money problems, time, weather, identity questions, or other non-symptom chat as emergencies.

Languages: answer in the patient's language. English → English. Filipino → Filipino. Hiligaynon/Ilonggo → Hiligaynon when you reasonably can. Tolerate typos and slang.

Always reply in this exact format:
CLASSIFICATION: HEALTHCARE|POSSIBLY_HEALTHCARE
REPLY:
<your reply in 2–4 short sentences, no markdown, no HTML, no code fences>
"""

CLASS_GREETING = "GREETING"
CLASS_HEALTHCARE = "HEALTHCARE"
CLASS_POSSIBLY = "POSSIBLY_HEALTHCARE"
CLASS_NON = "NON_HEALTHCARE"


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
    pack = generate_assist(
        user_text,
        lang,
        intent=intent,
        emotion=emotion,
        topic=topic,
        history=history,
    )
    return str(pack.get("html") or "")


def generate_assist(
    user_text: str,
    lang: str = "en",
    *,
    intent: str = "",
    emotion: str = "",
    topic: str = "",
    history: list[dict[str, str]] | None = None,
) -> dict[str, str]:
    user_text = (user_text or "").strip()[:800]
    if not user_text:
        return {"html": "", "classification": CLASS_POSSIBLY, "raw": ""}
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
    parsed = parse_model_reply(raw)
    classification = parsed["classification"]
    reply = parsed["reply"]
    if classification == CLASS_NON or reply.strip().upper() == "OUT_OF_SCOPE":
        return {"html": "", "classification": CLASS_NON, "raw": "OUT_OF_SCOPE"}
    return {
        "html": to_safe_html(reply),
        "classification": classification,
        "raw": reply,
    }


def parse_model_reply(raw: str) -> dict[str, str]:
    text = (raw or "").replace("\r\n", "\n").strip()
    text = re.sub(r"^```(?:json)?\s*", "", text, flags=re.I)
    text = re.sub(r"\s*```$", "", text).strip()
    if not text:
        return {"classification": CLASS_POSSIBLY, "reply": ""}
    if text[0] in "{[":
        try:
            decoded = json.loads(text)
        except json.JSONDecodeError:
            decoded = None
        if isinstance(decoded, dict):
            classification = _normalize_classification(
                str(decoded.get("classification") or decoded.get("CLASSIFICATION") or "")
            )
            reply = str(decoded.get("reply") or decoded.get("REPLY") or decoded.get("text") or "").strip()
            if classification:
                return _normalize_parsed(classification, reply)
    if re.match(r"^\s*OUT_OF_SCOPE\s*$", text, flags=re.I):
        return {"classification": CLASS_NON, "reply": "OUT_OF_SCOPE"}
    class_match = re.search(
        r"CLASSIFICATION\s*:\s*(GREETING|HEALTHCARE|POSSIBLY_HEALTHCARE|NON_HEALTHCARE)\b",
        text,
        flags=re.I,
    )
    classification = _normalize_classification(class_match.group(1) if class_match else "")
    reply_match = re.search(r"\bREPLY\s*:\s*(.*)$", text, flags=re.I | re.S)
    if reply_match:
        reply = reply_match.group(1).strip()
    elif classification:
        reply = re.sub(r"CLASSIFICATION\s*:\s*[A-Z_]+\s*", "", text, flags=re.I).strip()
    else:
        reply = text
    if not classification:
        if re.match(r"^\s*OUT_OF_SCOPE\s*$", reply, flags=re.I) or (
            "OUT_OF_SCOPE" in text and len(text) < 48
        ):
            classification = CLASS_NON
            reply = "OUT_OF_SCOPE"
        else:
            classification = CLASS_POSSIBLY
    return _normalize_parsed(classification, reply)


def _normalize_classification(value: str) -> str:
    v = (value or "").strip().upper().replace(" ", "_").replace("-", "_")
    aliases = {
        "OUT_OF_SCOPE": CLASS_NON,
        "NONHEALTHCARE": CLASS_NON,
        "UNRELATED": CLASS_NON,
        "POSSIBLY": CLASS_POSSIBLY,
        "AMBIGUOUS": CLASS_POSSIBLY,
        "CLARIFY": CLASS_POSSIBLY,
        "MEDICAL": CLASS_HEALTHCARE,
        "HEALTH": CLASS_HEALTHCARE,
    }
    v = aliases.get(v, v)
    if v in {CLASS_GREETING, CLASS_HEALTHCARE, CLASS_POSSIBLY, CLASS_NON}:
        return v
    return v


def _normalize_parsed(classification: str, reply: str) -> dict[str, str]:
    classification = _normalize_classification(classification)
    reply = (reply or "").strip()
    if classification == CLASS_NON or re.match(r"^\s*OUT_OF_SCOPE\s*$", reply, flags=re.I):
        return {"classification": CLASS_NON, "reply": "OUT_OF_SCOPE"}
    return {"classification": classification or CLASS_HEALTHCARE, "reply": reply}
