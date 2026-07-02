"""AI-powered Hiligaynon/Ilonggo medical language understanding for Step 2 translation."""

from __future__ import annotations

import json
import logging
import re
from typing import Any

from ai_interpreter_config import (
    AI_INTERPRETER_ENABLED,
    GROQ_MODEL,
    OPENAI_API_KEY,
    OPENAI_MODEL,
    LOCAL_LLAMA_MODEL,
    LOCAL_LLAMA_URL,
    provider_chain,
)
from groq_client import groq_chat_completion

SYSTEM_PROMPT = """You are a medical language assistant for a Philippine telemedicine system.
Patients may write in Hiligaynon/Ilonggo, English, or mixed dialect with slang, misspellings, abbreviations, and incomplete sentences.

Your job is to produce a natural English medical interpretation and extract structured medical concepts ONLY from what the patient wrote.

STRICT RULES:
- Do NOT diagnose diseases or invent conditions not implied by the input.
- Do NOT create new medical condition names — use common English medical terms (symptoms, conditions, allergies).
- Do NOT bypass validation — output terms that can be checked against standard medical datasets.
- Improve translation and clarify intent; extract symptoms, conditions, allergies, body parts, severity, and duration when present.
- If unsure, lower confidence rather than guessing.

Respond with JSON only (no markdown):
{
  "english_interpretation": "natural English summary of patient input",
  "confidence_score": 0-100,
  "concepts": [
    {
      "term": "english medical term",
      "type": "symptom|condition|allergy|body_part|severity|duration",
      "body_part": "optional body part or null",
      "severity": "optional mild|moderate|severe or null",
      "duration": "optional duration phrase or null",
      "confidence": 0-100
    }
  ],
    "notes": "brief note on dialect/slang handling or empty string"
}"""


logger = logging.getLogger("medconnect.nlp.groq")


def _extract_json(text: str) -> dict[str, Any]:
    text = (text or "").strip()
    if not text:
        return {}
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass
    match = re.search(r"\{[\s\S]*\}", text)
    if match:
        try:
            return json.loads(match.group(0))
        except json.JSONDecodeError:
            return {}
    return {}


def _chat_completion(provider: str, user_prompt: str) -> tuple[str, str, str]:
    """Returns (content, provider, model)."""
    if provider == "groq":
        content, model_used = groq_chat_completion(
            [
                {"role": "system", "content": SYSTEM_PROMPT},
                {"role": "user", "content": user_prompt},
            ],
            json_mode=True,
        )
        return content, "groq", model_used

    if provider == "openai":
        if not OPENAI_API_KEY:
            raise RuntimeError("OpenAI API key not configured")
        import urllib.request

        body = json.dumps(
            {
                "model": OPENAI_MODEL,
                "temperature": 0.1,
                "response_format": {"type": "json_object"},
                "messages": [
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {"role": "user", "content": user_prompt},
                ],
            }
        ).encode("utf-8")
        req = urllib.request.Request(
            "https://api.openai.com/v1/chat/completions",
            data=body,
            headers={
                "Content-Type": "application/json",
                "Authorization": f"Bearer {OPENAI_API_KEY}",
            },
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=25) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        content = str(data["choices"][0]["message"]["content"] or "").strip()
        if not content:
            raise ValueError("Empty OpenAI response")
        return content, "openai", OPENAI_MODEL

    if provider == "local":
        import urllib.request

        base = LOCAL_LLAMA_URL.rstrip("/")
        body = json.dumps(
            {
                "model": LOCAL_LLAMA_MODEL,
                "stream": False,
                "format": "json",
                "messages": [
                    {"role": "system", "content": SYSTEM_PROMPT},
                    {"role": "user", "content": user_prompt},
                ],
            }
        ).encode("utf-8")
        req = urllib.request.Request(
            f"{base}/api/chat",
            data=body,
            headers={"Content-Type": "application/json"},
            method="POST",
        )
        with urllib.request.urlopen(req, timeout=25) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        content = str(data.get("message", {}).get("content") or "").strip()
        if not content:
            raise ValueError("Empty local Llama response")
        return content, "local_llama", LOCAL_LLAMA_MODEL

    raise RuntimeError(f"Unknown provider: {provider}")


def _format_match_lines(matches: list[dict[str, Any]], local_key: str = "local_term", english_key: str = "english_term") -> str:
    if not matches:
        return "(none)"
    lines = []
    for row in matches[:12]:
        local = row.get(local_key) or row.get("matched_term") or "?"
        english = row.get(english_key) or row.get("english") or "?"
        lines.append(f"  - {local} → {english}")
    if len(matches) > 12:
        lines.append(f"  … and {len(matches) - 12} more")
    return "\n".join(lines)


def _build_user_prompt(
    field_label: str,
    original_text: str,
    dictionary_english: str,
    dictionary_terms: list[str],
    pipeline_context: dict[str, Any] | None = None,
) -> str:
    terms = ", ".join(dictionary_terms) if dictionary_terms else "(none)"
    ctx = pipeline_context or {}
    stages = ctx.get("stages") or {}
    dict_matches = ctx.get("dictionary_matches") or stages.get("medical_dictionary", {}).get("matches") or []
    dataset_matches = ctx.get("dataset_matches") or stages.get("hiligaynon_dataset", {}).get("matches") or []
    keywords = ctx.get("keywords") or stages.get("keyword_extraction", {}).get("keywords") or []

    keyword_text = ", ".join(keywords) if keywords else "(none)"

    return (
        f"Field: {field_label}\n"
        f"Original patient input:\n{original_text or '(empty)'}\n\n"
        "PIPELINE CONTEXT (use this to improve interpretation — do not invent terms):\n"
        f"1. Medical Dictionary matches:\n{_format_match_lines(dict_matches)}\n\n"
        f"2. Hiligaynon Dataset matches:\n{_format_match_lines(dataset_matches)}\n\n"
        f"3. Extracted keywords: {keyword_text}\n\n"
        f"4. Dictionary translation so far:\n{dictionary_english or '(none)'}\n"
        f"5. Dictionary-matched English terms: {terms}\n\n"
        "Perform contextual language understanding on the original Hiligaynon/Ilonggo input. "
        "Resolve slang, misspellings, and mixed dialect using the pipeline context above. "
        "Produce a natural English medical interpretation and extract implied medical concepts "
        "for downstream fuzzy matching and dataset validation."
    )


def _normalize_concepts(raw: dict[str, Any]) -> list[dict[str, Any]]:
    concepts: list[dict[str, Any]] = []
    for row in raw.get("concepts") or []:
        if not isinstance(row, dict):
            continue
        term = str(row.get("term") or "").strip()
        if not term:
            continue
        ctype = str(row.get("type") or "symptom").strip().lower()
        if ctype in ("severity", "duration", "body_part"):
            continue
        if ctype not in ("symptom", "condition", "allergy"):
            ctype = "symptom"
        concepts.append(
            {
                "term": term,
                "type": ctype,
                "body_part": row.get("body_part"),
                "severity": row.get("severity"),
                "duration": row.get("duration"),
                "confidence": max(0, min(100, int(row.get("confidence") or 0))),
            }
        )
    return concepts


PROFILE_SYSTEM_PROMPT = SYSTEM_PROMPT + """

When interpreting a registration profile with TWO fields, respond with JSON:
{
  "conditions": {
    "english_interpretation": "...",
    "confidence_score": 0-100,
    "concepts": [ ... same concept shape as above ... ],
    "notes": ""
  },
  "allergies": {
    "english_interpretation": "...",
    "confidence_score": 0-100,
    "concepts": [ ... ],
    "notes": ""
  }
}
If a field is empty, return empty english_interpretation and an empty concepts array for that field."""


def _result_from_parsed(
    parsed: dict[str, Any],
    dictionary_english: str,
    original_text: str,
) -> dict[str, Any]:
    concepts = _normalize_concepts(parsed)
    score = max(0, min(100, int(parsed.get("confidence_score") or 0)))
    if score == 0 and concepts:
        score = int(round(sum(c["confidence"] for c in concepts) / len(concepts)))
    english = str(parsed.get("english_interpretation") or dictionary_english or original_text).strip()
    if not english and not original_text.strip():
        return {
            "status": "skipped",
            "provider": None,
            "model": None,
            "english_interpretation": "",
            "confidence_score": 0,
            "concepts": [],
            "notes": "No input text",
            "groq_error": None,
            "provider_errors": {},
        }
    if not english:
        raise ValueError("Empty AI interpretation")
    return {
        "status": "complete",
        "provider": "groq",
        "model": None,
        "english_interpretation": english,
        "confidence_score": score,
        "concepts": concepts,
        "notes": str(parsed.get("notes") or "").strip(),
        "groq_error": None,
        "provider_errors": {},
    }


def interpret_profile_fields_combined(
    conditions_original: str,
    conditions_english: str,
    conditions_terms: list[str],
    conditions_ctx: dict[str, Any] | None,
    allergies_original: str,
    allergies_english: str,
    allergies_terms: list[str],
    allergies_ctx: dict[str, Any] | None,
) -> dict[str, dict[str, Any]]:
    """Single Groq request for conditions + allergies (about 2x faster than sequential)."""
    conditions_original = (conditions_original or "").strip()
    allergies_original = (allergies_original or "").strip()

    user_prompt = (
        "Interpret BOTH registration profile fields in one response.\n\n"
        "=== Medical conditions & symptoms ===\n"
        + _build_user_prompt(
            "Medical conditions & symptoms",
            conditions_original,
            conditions_english,
            conditions_terms,
            conditions_ctx,
        )
        + "\n\n=== Known allergies ===\n"
        + _build_user_prompt(
            "Known allergies",
            allergies_original,
            allergies_english,
            allergies_terms,
            allergies_ctx,
        )
    )

    provider_errors: dict[str, str] = {}
    groq_error: str | None = None

    for provider in provider_chain():
        try:
            logger.info("AI combined profile analysis: provider=%s", provider)
            if provider == "groq":
                content, used_model = groq_chat_completion(
                    [
                        {"role": "system", "content": PROFILE_SYSTEM_PROMPT},
                        {"role": "user", "content": user_prompt},
                    ],
                    json_mode=True,
                )
                used_provider = "groq"
            else:
                content, used_provider, used_model = _chat_completion(provider, user_prompt)

            parsed = _extract_json(content)
            if not isinstance(parsed, dict):
                raise ValueError("Invalid combined AI JSON")

            out: dict[str, dict[str, Any]] = {}
            field_map = (
                ("conditions", conditions_original, conditions_english),
                ("allergies", allergies_original, allergies_english),
            )
            for key, original, english in field_map:
                block = parsed.get(key) if isinstance(parsed.get(key), dict) else {}
                field_result = _result_from_parsed(block, english, original)
                field_result["provider"] = used_provider
                field_result["model"] = used_model
                out[key] = field_result
            return out
        except Exception as exc:
            msg = str(exc)
            provider_errors[provider] = msg
            if provider == "groq":
                groq_error = msg
            logger.exception("Combined provider %s failed: %s", provider, msg)

    primary_error = groq_error or next(iter(provider_errors.values()), "No AI provider available")
    failed = {
        "status": "unavailable",
        "provider": None,
        "model": None,
        "english_interpretation": "",
        "confidence_score": 0,
        "concepts": [],
        "notes": primary_error,
        "groq_error": groq_error,
        "provider_errors": provider_errors,
    }
    return {"conditions": dict(failed), "allergies": dict(failed)}


def interpret_medical_text(
    field_label: str,
    original_text: str,
    dictionary_english: str = "",
    dictionary_terms: list[str] | None = None,
    pipeline_context: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Run Groq contextual analysis for one profile field."""
    original_text = (original_text or "").strip()
    if not AI_INTERPRETER_ENABLED:
        return {
            "status": "disabled",
            "provider": None,
            "model": None,
            "english_interpretation": dictionary_english or original_text,
            "confidence_score": 0,
            "concepts": [],
            "notes": "AI interpreter disabled via MEDCONNECT_AI_INTERPRETER=0",
            "groq_error": None,
            "provider_errors": {},
        }

    if original_text == "" and not dictionary_english:
        return {
            "status": "skipped",
            "provider": None,
            "model": None,
            "english_interpretation": "",
            "confidence_score": 0,
            "concepts": [],
            "notes": "No input text",
            "groq_error": None,
            "provider_errors": {},
        }

    user_prompt = _build_user_prompt(
        field_label,
        original_text,
        dictionary_english,
        dictionary_terms or [],
        pipeline_context,
    )

    provider_errors: dict[str, str] = {}
    groq_error: str | None = None

    for provider in provider_chain():
        try:
            logger.info("AI context analysis: provider=%s field=%s", provider, field_label)
            content, used_provider, used_model = _chat_completion(provider, user_prompt)
            parsed = _extract_json(content)
            concepts = _normalize_concepts(parsed)
            score = max(0, min(100, int(parsed.get("confidence_score") or 0)))
            if score == 0 and concepts:
                score = int(round(sum(c["confidence"] for c in concepts) / len(concepts)))
            english = str(parsed.get("english_interpretation") or dictionary_english or original_text).strip()
            if not english:
                raise ValueError("Empty AI interpretation")
            result = {
                "status": "complete",
                "provider": used_provider if used_provider == "groq" else used_provider,
                "model": used_model,
                "english_interpretation": english,
                "confidence_score": score,
                "concepts": concepts,
                "notes": str(parsed.get("notes") or "").strip(),
                "groq_error": None,
                "provider_errors": provider_errors,
            }
            return result
        except Exception as exc:
            msg = str(exc)
            provider_errors[provider] = msg
            if provider == "groq":
                groq_error = msg
            logger.exception("Provider %s failed for field %s: %s", provider, field_label, msg)

    primary_error = groq_error or next(iter(provider_errors.values()), "No AI provider available")
    return {
        "status": "unavailable",
        "provider": None,
        "model": None,
        "english_interpretation": dictionary_english or original_text,
        "confidence_score": 0,
        "concepts": [],
        "notes": primary_error,
        "groq_error": groq_error,
        "provider_errors": provider_errors,
    }
