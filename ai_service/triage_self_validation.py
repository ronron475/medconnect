"""Self-validation / consistency layer for Python clinical triage (mirrors PHP)."""

from __future__ import annotations

import re
from typing import Any

RULE_PRIORITY = [
    "emergency_red_flags",
    "airway",
    "breathing",
    "circulation",
    "neurological",
    "severe_bleeding",
    "pregnancy_emergency",
    "high_risk_patient",
    "symptom_combination",
    "duration",
    "temperature",
    "pain_scale",
    "individual_symptoms",
    "administrative_request",
    "confidence_score",
]

_LIFE = re.compile(
    r"\b(chest pain|difficulty breathing|cannot breathe|shortness of breath|unconscious|seizure|stroke|"
    r"vomiting blood|coughing blood|severe bleeding|anaphylaxis|poisoning|masakit dughan|budlay ginhawa|"
    r"indi makaginhawa|may dugo sa suka|naguyam|nadulaan malay|hirap huminga|masakit ang dibdib)\b",
    re.I,
)
_MILD = re.compile(
    r"\b(i have fever|may lagnat|nilalagnat|i have cough|may ubo|runny nose|sip-?on|"
    r"follow[- ]?up|medicine refill|refill of my|maintenance medicine)\b",
    re.I,
)
_ADMIN = re.compile(r"\b(follow[- ]?up|check[- ]?up|medicine refill|refill|maintenance medicine)\b", re.I)


def _has_life(hay: str) -> bool:
    return bool(_LIFE.search(hay))


def _mild_only(hay: str) -> bool:
    if _has_life(hay):
        return False
    if not _MILD.search(hay):
        return False
    return not re.search(r"\b(5 days|one week|severe|grabe|blood|dugo|chest|dughan|breath|ginhawa)\b", hay, re.I)


def _admin_only(hay: str) -> bool:
    return bool(_ADMIN.search(hay)) and not _has_life(hay)


def enforce_consistency(hay: str, display: str, red_flags: list[str]) -> str:
    display = (display or "NON-URGENT").upper()
    if red_flags or _has_life(hay):
        return "EMERGENCY"
    if _admin_only(hay) or _mild_only(hay):
        return "NON-URGENT"
    return display


def validate(result: dict[str, Any], context: dict[str, Any] | None = None) -> dict[str, Any]:
    context = context or {}
    original = str(context.get("original_input") or result.get("recommendation_payload", {}).get("chief_complaint") or "")
    normalized = str(context.get("normalized_text") or result.get("normalized_text") or original)
    english = str(context.get("english_text") or "")
    hay = f"{original} {normalized} {english}".lower()
    display = str(result.get("triage_display") or "NON-URGENT")
    red_flags = list(result.get("red_flags") or [])
    corrected = enforce_consistency(hay, display, red_flags)

    checks = {
        "emergency_red_flags_checked": True,
        "classification_consistent": corrected == display or (red_flags and display == "EMERGENCY"),
        "explanation_matches": bool(result.get("reason")),
    }
    if corrected != display:
        result = dict(result)
        result["triage_display"] = corrected
        result["triage_level"] = "EMERGENCY" if corrected == "EMERGENCY" else ("HIGH" if corrected == "URGENT" else "LOW")
        result["triage_classification"] = "EMERGENCY" if corrected == "EMERGENCY" else (
            "URGENT" if corrected == "URGENT" else "NON_URGENT"
        )
        result["priority"] = {"NON-URGENT": "Normal", "URGENT": "High", "EMERGENCY": "Critical"}[corrected]
        rec = {
            "NON-URGENT": "Schedule the patient for a regular consultation.",
            "URGENT": "Arrange prompt clinical evaluation within hours to 24 hours.",
            "EMERGENCY": "Refer for immediate emergency care now.",
        }[corrected]
        result["recommendation"] = rec
        result["recommended_action"] = rec
        result["reason"] = (result.get("reason") or "") + f" Consistency correction → {corrected}."
        checks["classification_consistent"] = True

    conf = int(result.get("confidence_score") or result.get("confidence") or 0)
    if conf < 60 and corrected != "EMERGENCY" and not red_flags:
        result["needs_provider_review"] = True
        result["recommendation"] = "Needs Healthcare Provider Review"
        result["recommended_action"] = "Needs Healthcare Provider Review"
        result["reason"] = "Insufficient information for a reliable triage classification. " + str(result.get("reason") or "")

    result["validation"] = {
        "passed": all(checks.values()),
        "checks": checks,
        "failures": [k for k, v in checks.items() if not v],
        "winning_rule": "emergency_red_flags" if red_flags else ("administrative_request" if _admin_only(hay) else "individual_symptoms"),
        "rule_priority_order": RULE_PRIORITY,
    }
    result["normalized_text"] = normalized
    payload = dict(result.get("recommendation_payload") or {})
    payload.update(
        {
            "chief_complaint": original,
            "normalized_text": normalized,
            "classification": result.get("triage_display"),
            "confidence": conf,
            "reason": result.get("reason"),
            "recommendation": result.get("recommendation"),
            "validation_passed": result["validation"]["passed"],
        }
    )
    result["recommendation_payload"] = payload
    return result
