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

SYSTEM_PROMPT = """You are a SECONDARY comprehension classifier for the medConnect Assistant (City Health Office).

Primary NLP already failed or had low confidence. Your job is to decide what the patient message means.
Do NOT write a patient-facing reply. Do NOT diagnose. Do NOT prescribe. Do NOT assign a final triage level.

Understand English, Filipino/Tagalog, Hiligaynon/Ilonggo, Taglish, mixed languages, slang, and common misspellings.

Allowed classifications:
- MEDICAL_SYMPTOM: a real symptom/illness complaint (including informal wording and reasonable misspellings).
- MEDICAL_FOLLOWUP_ANSWER: answering the CURRENT CHATBOT QUESTION, including misspellings that map to a reasonable clinical answer (e.g. "olo" → ulo/head when asked where it hurts).
- MEDCONNECT_SERVICE: appointments, video consult, records, login/account, BHW, City Health Office services.
- GREETING: hello/hi/good morning style openings with no other content.
- NONSENSE_OR_PRANK: keyboard smash, gibberish, meaningless filler, joking/test spam with no recoverable meaning.
- UNKNOWN: potentially meaningful text you cannot reliably interpret — do not guess.
- HEALTH_RELATED: legacy alias for MEDICAL_SYMPTOM or MEDCONNECT_SERVICE.
- NON_HEALTH_RELATED: real non-health chat that is NOT gibberish.
- UNCLEAR: legacy alias for UNKNOWN when unsure.

Critical rules:
- MISSPELLING ≠ NONSENSE. If a reasonable intended meaning exists in context, classify that meaning.
- Never treat gibberish as MEDICAL_* or MEDCONNECT_SERVICE just because letters resemble medical roots.
- Laughing / "test lang" / keyboard smash without a real concern → NONSENSE_OR_PRANK.
- If unsure, prefer UNKNOWN with lower confidence — do not force a guess.

Return ONLY this JSON object (no markdown, no extra keys, no reply text):
{"understood":true,"confidence":0.95,"classification":"MEDICAL_SYMPTOM","normalized_text":"masakit akon ulo","meaning":"head pain","clinical_entities":{"symptom":"headache","body_location":"head"}}

For nonsense:
{"understood":false,"confidence":0.97,"classification":"NONSENSE_OR_PRANK","normalized_text":null,"meaning":null,"clinical_entities":{}}

confidence must be a number from 0.0 to 1.0
"""

CLASS_HEALTH_RELATED = "HEALTH_RELATED"
CLASS_NON_HEALTH_RELATED = "NON_HEALTH_RELATED"
CLASS_UNCLEAR = "UNCLEAR"
CLASS_NONSENSE_OR_PRANK = "NONSENSE_OR_PRANK"
CLASS_UNKNOWN = "UNKNOWN"
CLASS_GREETING = "GREETING"
CLASS_MEDICAL_SYMPTOM = "MEDICAL_SYMPTOM"
CLASS_MEDICAL_FOLLOWUP = "MEDICAL_FOLLOWUP_ANSWER"
CLASS_MEDCONNECT_SERVICE = "MEDCONNECT_SERVICE"
CLASS_HEALTHCARE = CLASS_HEALTH_RELATED
CLASS_POSSIBLY = CLASS_UNCLEAR
CLASS_NON = CLASS_NON_HEALTH_RELATED
CLASSIFY_CONFIDENCE_THRESHOLD = 0.80

_HEALTH_CLASSES = {
    CLASS_HEALTH_RELATED,
    CLASS_MEDICAL_SYMPTOM,
    CLASS_MEDICAL_FOLLOWUP,
    CLASS_MEDCONNECT_SERVICE,
}
_CLARIFY_CLASSES = {
    CLASS_UNCLEAR,
    CLASS_UNKNOWN,
    CLASS_NONSENSE_OR_PRANK,
}


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


def is_internal_classification_payload(text: str) -> bool:
    plain = re.sub(r"<[^>]+>", "", (text or "")).strip()
    if not plain:
        return False
    if re.search(r'"is_healthcare_related"\s*:', plain, flags=re.I) or re.search(
        r'"isHealthcareRelated"\s*:', plain, flags=re.I
    ):
        return True
    return bool(
        re.match(r"^\s*\{[\s\S]*\}\s*$", plain)
        and re.search(r'"(intent|classification|normalized_meaning|urgency|confidence)"\s*:', plain, flags=re.I)
    )


def sanitize_patient_html(text: str, lang: str = "en") -> str:
    plain = re.sub(r"<[^>]+>", "", (text or "")).strip()
    if not plain or not is_internal_classification_payload(plain):
        return text or ""
    match = re.search(r"\{[\s\S]*\}", plain)
    if match:
        try:
            decoded = json.loads(match.group(0))
        except json.JSONDecodeError:
            decoded = None
        if isinstance(decoded, dict):
            is_health = decoded.get("is_healthcare_related", decoded.get("isHealthcareRelated"))
            reply = str(decoded.get("reply") or decoded.get("response") or decoded.get("text") or "").strip()
            if is_health in (False, 0, "false", "0"):
                return ""
            if reply and not is_internal_classification_payload(reply):
                return to_safe_html(reply)
    if re.search(r'"is_healthcare_related"\s*:\s*false', plain, flags=re.I):
        return ""
    return ""


def to_safe_html(text: str) -> str:
    text = (text or "").replace("\r\n", "\n").replace("\r", "\n").strip()
    text = re.sub(r"```[\s\S]*?```", "", text).strip()
    text = re.sub(r"<[^>]+>", "", text).strip()
    if not text:
        return ""
    if is_internal_classification_payload(text):
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
    parts = [
        "Classify this single user message as a SECONDARY comprehension check. "
        "Do not write a chatbot reply. Do not diagnose or prescribe.",
        f"Preferred UI language: {lang_name}.",
    ]
    current_q = str(context.get("current_question") or context.get("pending_prompt") or "").strip()
    if current_q:
        parts.append("CURRENT CHATBOT QUESTION:\n" + current_q[:400])
    expected = str(context.get("expected_answer_type") or "").strip()
    if expected:
        parts.append("Expected answer type: " + expected[:120])
    medical = str(context.get("accumulated_medical") or "").strip()
    if medical:
        parts.append("Accumulated medical information so far:\n" + medical[:400])
    parts.append("CURRENT PATIENT MESSAGE:\n" + user_text)
    return "\n\n".join(parts)


def _complete_gemini(user_text: str, lang: str, context: dict[str, Any], history: list[dict[str, str]]) -> str:
    key = gemini_key()
    if not key:
        raise RuntimeError("gemini key missing")
    contents: list[dict[str, Any]] = [
        {"role": "user", "parts": [{"text": _user_payload(user_text, lang, context)}]}
    ]
    payload = {
        "systemInstruction": {"parts": [{"text": SYSTEM_PROMPT}]},
        "contents": contents,
        "generationConfig": {
            "temperature": 0.1,
            "maxOutputTokens": 220,
            "responseMimeType": "application/json",
        },
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
    messages.append({"role": "user", "content": _user_payload(user_text, lang, context)})
    content, _model = groq_chat_completion(messages, json_mode=False, temperature=0.1)
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
) -> dict[str, Any]:
    user_text = (user_text or "").strip()[:800]
    if not user_text:
        return {"html": "", "classification": CLASS_UNCLEAR, "raw": "", "confidence": None}
    lang = (lang or "en").lower()
    if lang not in {"en", "fil", "hil"}:
        lang = "en"
    context = {
        "intent": intent,
        "emotion": emotion,
        "topic": topic,
        "current_question": str((history and len(history) and history[-1].get("text")) or ""),
    }
    try:
        raw = _complete_gemini(user_text, lang, context, history or [])
    except Exception as exc:
        logger.warning("Gemini FAQ fallback failed, trying Groq: %s", exc)
        raw = _complete_groq(user_text, lang, context, history or [])
    parsed = parse_model_reply(raw)
    classification = _apply_confidence_gate(
        str(parsed.get("classification") or CLASS_UNCLEAR),
        parsed.get("confidence"),
    )
    return {
        "html": "",
        "classification": classification,
        "raw": raw if str(raw).strip().startswith("{") else json.dumps(
            {"classification": classification, "confidence": parsed.get("confidence")},
            ensure_ascii=False,
        ),
        "is_healthcare_related": classification == CLASS_HEALTH_RELATED,
        "intent": parsed.get("intent") or "",
        "language": parsed.get("language") or "",
        "normalized_meaning": parsed.get("normalized_meaning") or "",
        "urgency": parsed.get("urgency") or "NON_URGENT",
        "confidence": parsed.get("confidence"),
    }


def parse_model_reply(raw: str) -> dict[str, Any]:
    text = (raw or "").replace("\r\n", "\n").strip()
    text = re.sub(r"^```(?:json)?\s*", "", text, flags=re.I)
    text = re.sub(r"\s*```$", "", text).strip()
    if not text:
        return {"classification": CLASS_UNCLEAR, "reply": ""}
    json_match = re.search(r"\{[\s\S]*\}", text)
    if json_match:
        try:
            decoded = json.loads(json_match.group(0))
        except json.JSONDecodeError:
            decoded = None
        if isinstance(decoded, dict):
            class_raw = str(decoded.get("classification") or decoded.get("CLASSIFICATION") or "").strip()
            classification = _normalize_classification(class_raw)
            is_health = decoded.get("is_healthcare_related", decoded.get("isHealthcareRelated"))
            if class_raw == "" and is_health in (True, 1, "true", "1"):
                classification = CLASS_HEALTH_RELATED
            elif class_raw == "" and is_health in (False, 0, "false", "0"):
                classification = CLASS_NON_HEALTH_RELATED
            intent = str(decoded.get("intent") or "").strip().lower().replace("-", "_")
            if classification == CLASS_UNCLEAR and class_raw == "" and intent in {
                "non_healthcare",
                "nonhealthcare",
                "out_of_scope",
                "non_health_related",
            }:
                classification = CLASS_NON_HEALTH_RELATED
            if classification or class_raw or is_health is not None:
                # Collapse fine medical/service classes to HEALTH_RELATED for PHP routing
                # while preserving fine label in intent when useful.
                fine = classification
                if classification in {
                    CLASS_MEDICAL_SYMPTOM,
                    CLASS_MEDICAL_FOLLOWUP,
                    CLASS_MEDCONNECT_SERVICE,
                }:
                    classification = CLASS_HEALTH_RELATED
                elif classification == CLASS_GREETING:
                    classification = CLASS_GREETING
                elif classification in {CLASS_NONSENSE_OR_PRANK, CLASS_UNKNOWN}:
                    pass
                parsed = _normalize_parsed(classification or CLASS_UNCLEAR, "")
                parsed["intent"] = str(decoded.get("intent") or fine or "").strip()
                parsed["language"] = str(decoded.get("language") or "").strip()
                meaning = str(
                    decoded.get("meaning")
                    or decoded.get("normalized_meaning")
                    or decoded.get("normalizedMeaning")
                    or ""
                ).strip()
                normalized_text = str(
                    decoded.get("normalized_text") or decoded.get("normalizedText") or ""
                ).strip()
                parsed["normalized_meaning"] = meaning or normalized_text
                parsed["urgency"] = str(decoded.get("urgency") or "NON_URGENT").strip().upper()
                try:
                    parsed["confidence"] = float(decoded.get("confidence")) if decoded.get("confidence") is not None else None
                except (TypeError, ValueError):
                    parsed["confidence"] = None
                return parsed
    if re.match(r"^\s*OUT_OF_SCOPE\s*$", text, flags=re.I):
        return {"classification": CLASS_NON_HEALTH_RELATED, "reply": ""}
    class_match = re.search(
        r"CLASSIFICATION\s*:\s*(HEALTH_RELATED|NON_HEALTH_RELATED|UNCLEAR|UNKNOWN|NONSENSE_OR_PRANK|NONSENSE|GREETING|HEALTHCARE|POSSIBLY_HEALTHCARE|NON_HEALTHCARE|MEDICAL_SYMPTOM|MEDICAL_FOLLOWUP_ANSWER|MEDCONNECT_SERVICE)\b",
        text,
        flags=re.I,
    )
    classification = _normalize_classification(class_match.group(1) if class_match else "")
    if not classification:
        classification = CLASS_UNCLEAR
    return _normalize_parsed(classification, "")


def _normalize_classification(value: str) -> str:
    v = (value or "").strip().upper().replace(" ", "_").replace("-", "_")
    aliases = {
        "OUT_OF_SCOPE": CLASS_NON_HEALTH_RELATED,
        "NONHEALTHCARE": CLASS_NON_HEALTH_RELATED,
        "NON_HEALTHCARE": CLASS_NON_HEALTH_RELATED,
        "UNRELATED": CLASS_NON_HEALTH_RELATED,
        "POSSIBLY": CLASS_UNCLEAR,
        "POSSIBLY_HEALTHCARE": CLASS_UNCLEAR,
        "AMBIGUOUS": CLASS_UNCLEAR,
        "CLARIFY": CLASS_UNCLEAR,
        "GREETING": CLASS_GREETING,
        "HI": CLASS_GREETING,
        "HELLO": CLASS_GREETING,
        "MEDICAL": CLASS_HEALTH_RELATED,
        "HEALTH": CLASS_HEALTH_RELATED,
        "HEALTHCARE": CLASS_HEALTH_RELATED,
        "MEDICAL_SYMPTOM": CLASS_MEDICAL_SYMPTOM,
        "SYMPTOM": CLASS_MEDICAL_SYMPTOM,
        "SYMPTOMS": CLASS_MEDICAL_SYMPTOM,
        "MEDICAL_FOLLOWUP_ANSWER": CLASS_MEDICAL_FOLLOWUP,
        "FOLLOWUP": CLASS_MEDICAL_FOLLOWUP,
        "FOLLOW_UP": CLASS_MEDICAL_FOLLOWUP,
        "MEDCONNECT_SERVICE": CLASS_MEDCONNECT_SERVICE,
        "SERVICE": CLASS_MEDCONNECT_SERVICE,
        "SERVICES": CLASS_MEDCONNECT_SERVICE,
        "NONSENSE_OR_PRANK": CLASS_NONSENSE_OR_PRANK,
        "NONSENSE": CLASS_NONSENSE_OR_PRANK,
        "PRANK": CLASS_NONSENSE_OR_PRANK,
        "GIBBERISH": CLASS_NONSENSE_OR_PRANK,
        "TEST_INPUT": CLASS_NONSENSE_OR_PRANK,
        "UNKNOWN": CLASS_UNKNOWN,
    }
    v = aliases.get(v, v)
    if v in {
        CLASS_HEALTH_RELATED,
        CLASS_NON_HEALTH_RELATED,
        CLASS_UNCLEAR,
        CLASS_NONSENSE_OR_PRANK,
        CLASS_UNKNOWN,
        CLASS_GREETING,
        CLASS_MEDICAL_SYMPTOM,
        CLASS_MEDICAL_FOLLOWUP,
        CLASS_MEDCONNECT_SERVICE,
    }:
        return v
    return CLASS_UNCLEAR


def _apply_confidence_gate(classification: str, confidence: Any) -> str:
    classification = _normalize_classification(classification)
    if classification in _CLARIFY_CLASSES or classification == "":
        return CLASS_UNKNOWN if classification == CLASS_UNKNOWN else (
            CLASS_NONSENSE_OR_PRANK if classification == CLASS_NONSENSE_OR_PRANK else CLASS_UNCLEAR
        )
    try:
        if confidence is not None and float(confidence) < CLASSIFY_CONFIDENCE_THRESHOLD:
            return CLASS_UNKNOWN
    except (TypeError, ValueError):
        return classification
    # PHP maps fine medical classes to HEALTH_RELATED for routing
    if classification in {CLASS_MEDICAL_SYMPTOM, CLASS_MEDICAL_FOLLOWUP, CLASS_MEDCONNECT_SERVICE}:
        return CLASS_HEALTH_RELATED
    return classification


def _normalize_parsed(classification: str, reply: str) -> dict[str, Any]:
    classification = _normalize_classification(classification)
    if classification in {CLASS_MEDICAL_SYMPTOM, CLASS_MEDICAL_FOLLOWUP, CLASS_MEDCONNECT_SERVICE}:
        classification = CLASS_HEALTH_RELATED
    return {"classification": classification or CLASS_UNCLEAR, "reply": ""}
