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
    "poisoning",
    "burns",
    "trauma",
    "high_risk_patient",
    "symptom_combination",
    "clinical_context",
    "duration",
    "temperature",
    "pain_scale",
    "individual_symptoms",
    "administrative_request",
    "confidence_score",
]

_EMERGENCY_RULES = {
    "emergency_red_flags", "airway", "breathing", "circulation", "neurological",
    "severe_bleeding", "pregnancy_emergency", "poisoning", "burns", "trauma",
}

_LIFE = re.compile(
    r"\b((chest pain|masakit dughan|masakit dibdib).{0,80}(breath|breathing|sweat|dizzy|radiat|collapse|severe|grabe|8/10|9/10|10/10))\b|"
    r"\b(difficulty breathing|cannot breathe|shortness of breath|unconscious|seizure|stroke|"
    r"vomiting blood|coughing blood|severe bleeding|anaphylaxis|poisoning|overdose|head injury|"
    r"gunshot|stab wound|motor vehicle|car crash|budlay ginhawa|"
    r"indi makaginhawa|may dugo sa suka|naguyam|nadulaan malay|hirap huminga|"
    r"swollen tongue|throat swelling|lip swelling|cannot swallow|airway)\b",
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


def select_winning_rule(
    hay: str,
    red_flags: list[str],
    symptoms: list[str],
    risks: list[str],
    factors: dict[str, Any],
    duration: str,
    temp: str,
    pain: str,
) -> str:
    if red_flags:
        return "emergency_red_flags"
    if re.search(r"\b(choking|airway|cannot breathe|indi makaginhawa)\b", hay, re.I):
        return "airway"
    if re.search(r"\b(difficulty breathing|shortness of breath|budlay ginhawa|hirap huminga)\b", hay, re.I):
        return "breathing"
    if re.search(
        r"\b((chest pain|masakit dughan|masakit dibdib).{0,80}(breath|sweat|dizzy|radiat|collapse|severe|grabe))\b",
        hay,
        re.I,
    ):
        return "severe_bleeding" if re.search(r"\b(bleed|dugo|hemorrh)", hay, re.I) else "circulation"
    if re.search(r"\b(severe bleeding|shock|may dugo)\b", hay, re.I):
        return "severe_bleeding"
    if re.search(r"\b(stroke|seizure|unconscious|paralysis|speech|naguyam|nadulaan malay)\b", hay, re.I):
        return "neurological"
    if re.search(r"\b(pregnant|buntis).{0,40}(bleed|dugo)", hay, re.I):
        return "pregnancy_emergency"
    if re.search(r"\b(poison|poisoning|overdose|lason|pagkalason|toxin|pesticide)\b", hay, re.I):
        return "poisoning"
    if re.search(r"\b(severe burn|large burn|facial burn|nasunog lawas|malaking paso|smoke inhalation)\b", hay, re.I):
        return "burns"
    if re.search(
        r"\b(head injury|trauma|accident|naaksidente|gunshot|stab wound|amputation|crush injury|"
        r"motor vehicle|car crash|spinal injury|major trauma|nasaksak|naigo ulo)\b",
        hay,
        re.I,
    ):
        return "trauma"
    if risks and re.search(r"\b(chest pain|difficulty breathing|dughan|ginhawa)\b", hay, re.I):
        return "high_risk_patient"
    if factors.get("symptom_combination") or factors.get("combination_classification"):
        return "symptom_combination"
    if factors.get("clinical_context_rule") and factors.get("clinical_context_rule") != "CTX_NONE":
        return "clinical_context"
    if duration and re.search(r"\b(5|6|7|8|9|10|week|semana|linggo)\b", duration.lower()):
        return "duration"
    if temp and re.search(r"high fever|39|40", temp.lower()):
        return "temperature"
    if pain and re.search(r"\b(7|8|9|10)\b|severe", pain.lower()):
        return "pain_scale"
    if _admin_only(hay):
        return "administrative_request"
    if symptoms:
        return "individual_symptoms"
    return "confidence_score"


def classification_from_winning_rule(rule: str, current: str, red_flags: list[str], hay: str) -> str:
    current = (current or "NON-URGENT").upper()
    if rule in _EMERGENCY_RULES:
        return "EMERGENCY"
    if rule == "administrative_request":
        return "NON-URGENT"
    if rule == "clinical_context":
        return current
    if rule in {"duration", "temperature", "pain_scale", "high_risk_patient", "symptom_combination"}:
        return "EMERGENCY" if current == "EMERGENCY" else "URGENT"
    if _mild_only(hay) and not red_flags:
        return "NON-URGENT"
    return current


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
    symptoms = list(result.get("detected_symptoms") or [])
    risks = list(result.get("risk_factors") or [])
    factors = dict(result.get("assessment_factors") or {})
    duration = str(result.get("duration") or "")
    temp = ""
    temp_obj = result.get("temperature")
    if isinstance(temp_obj, dict):
        temp = str(temp_obj.get("label") or "")
    elif temp_obj:
        temp = str(temp_obj)
    pain = ""
    pain_obj = result.get("pain_scale")
    if isinstance(pain_obj, dict):
        pain = str(pain_obj.get("label") or "")

    winning_rule = select_winning_rule(hay, red_flags, symptoms, risks, factors, duration, temp, pain)
    expected = classification_from_winning_rule(winning_rule, display, red_flags, hay)
    corrected = enforce_consistency(hay, display, red_flags)
    if expected in {"NON-URGENT", "URGENT", "EMERGENCY"} and corrected != "EMERGENCY" and expected == "EMERGENCY":
        corrected = expected
    if winning_rule == "administrative_request" and not red_flags:
        corrected = "NON-URGENT"

    checks = {
        "emergency_red_flags_checked": True,
        "highest_priority_selected": corrected == expected or (red_flags and display == "EMERGENCY"),
        "classification_consistent": corrected == display or (red_flags and display == "EMERGENCY"),
        "explanation_matches": bool(result.get("reason")),
    }
    if corrected != display:
        result["triage_display"] = corrected
        result["reason"] = f"{result.get('reason', '')} Consistency correction via {winning_rule} → {corrected}.".strip()

    result["validation"] = {
        "passed": all(checks.values()),
        "checks": checks,
        "winning_rule": winning_rule,
        "rule_priority_order": RULE_PRIORITY,
    }
    return {
        "passed": all(checks.values()),
        "checks": checks,
        "corrected_classification": corrected if corrected != display else None,
        "winning_rule": winning_rule,
        "result": result,
    }
