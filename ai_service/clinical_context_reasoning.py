"""Contextual clinical reasoning for triage — mirrors PHP ClinicalContextReasoningEngine."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

_BASE = Path(__file__).resolve().parent.parent
_RULES_PATH = _BASE / "data" / "nlp" / "clinical_context_rules.json"
_CONFIG: dict[str, Any] | None = None


def _load_config() -> dict[str, Any]:
    global _CONFIG
    if _CONFIG is not None:
        return _CONFIG
    if not _RULES_PATH.is_file():
        _CONFIG = {"rules": [], "fallback": {}, "global": {}}
        return _CONFIG
    try:
        _CONFIG = json.loads(_RULES_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        _CONFIG = {"rules": [], "fallback": {}, "global": {}}
    return _CONFIG


def _symptom_ids(kb_symptoms: list[dict[str, Any]]) -> list[str]:
    ids: list[str] = []
    for sym in kb_symptoms:
        sid = (sym.get("id") or "").strip().lower()
        if sid:
            ids.append(sid)
        name = (sym.get("symptom_name") or "").strip().lower().replace(" ", "_")
        if name:
            ids.append(name)
    return list(dict.fromkeys(ids))


def _has_clinical_modifiers(features: dict[str, Any]) -> bool:
    if (features.get("duration") or {}).get("label"):
        return True
    if (features.get("pain_scale") or {}).get("label"):
        return True
    if (features.get("temperature") or {}).get("label"):
        return True
    if features.get("risk_factors"):
        return True
    return False


def _build_feature_keys(
    features: dict[str, Any],
    kb_symptoms: list[dict[str, Any]],
) -> dict[str, bool]:
    pain_key = (features.get("pain_scale") or {}).get("modifier_key") or ""
    temp_key = (features.get("temperature") or {}).get("modifier_key") or ""
    bucket = (features.get("duration") or {}).get("bucket") or ""
    risks = features.get("risk_factors") or []
    risk_ids = [r.get("id") for r in risks if isinstance(r, dict) and r.get("id")]

    has_fever = any(s.get("id") == "fever" for s in kb_symptoms)
    hay = (features.get("raw_text") or "").lower()
    if not has_fever and any(t in hay for t in ("fever", "lagnat", "hilanat", "nilalagnat")):
        has_fever = True

    return {
        "has_fever": bool(has_fever or temp_key),
        "has_high_fever": temp_key == "high_fever" or any(x in hay for x in ("39", "40", "high fever", "mataas na lagnat")),
        "pain_moderate_or_severe": pain_key in {"moderate", "severe"},
        "duration_1_to_2_days": bucket in {"1_to_2_days", "3_to_4_days"},
        "duration_3_plus_days": bucket in {"3_to_4_days", "5_plus_days"},
        "has_pediatric_risk": "pediatric" in risk_ids or "child" in risk_ids,
        "has_chronic_risk": bool(set(risk_ids) & {"diabetes", "heart_disease", "hypertension", "chronic_disease", "immunocompromised"}),
    }


def _match_indicators(
    hay: str,
    symptom_ids: list[str],
    context_factors: dict[str, bool],
    indicator_set: dict[str, Any],
) -> list[str]:
    hits: list[str] = []
    for pattern in indicator_set.get("patterns") or []:
        p = str(pattern).strip().lower()
        if p and p in hay:
            hits.append(p)
    for sid in indicator_set.get("symptom_ids") or []:
        sid_l = str(sid).strip().lower()
        if sid_l and sid_l in symptom_ids:
            hits.append(f"symptom:{sid_l}")
    for key in indicator_set.get("feature_keys") or []:
        k = str(key).strip()
        if k and context_factors.get(k):
            hits.append(f"feature:{k}")
    return list(dict.fromkeys(hits))


def _priority(display: str) -> int:
    d = display.upper()
    if d == "EMERGENCY":
        return 3
    if d == "URGENT":
        return 2
    return 1


def _score_for_display(display: str, score: int) -> int:
    d = display.upper()
    if d == "EMERGENCY":
        return max(score, 12)
    if d == "URGENT":
        return max(min(score, 11), 6)
    return min(score, 5)


def _red_flag_reason(red_flags: list[dict[str, Any]]) -> str:
    names = [
        (f.get("flag_name") or f.get("english_pattern") or "warning sign")
        for f in red_flags[:3]
    ]
    return (
        f"Emergency warning sign(s) detected ({', '.join(names)}). "
        "Immediate emergency evaluation is recommended."
    )


def _evaluate_rule(
    rule: dict[str, Any],
    hay: str,
    symptom_ids: list[str],
    context_factors: dict[str, bool],
) -> dict[str, Any]:
    emergency = rule.get("emergency") or {}
    urgent = rule.get("urgent") or {}

    emergency_hits = _match_indicators(hay, symptom_ids, context_factors, emergency)
    if emergency_hits:
        return {
            "display": "EMERGENCY",
            "reason": rule.get("emergency_reason") or "Associated emergency warning signs detected with primary complaint.",
            "needs_provider_review": False,
            "emergency_hits": emergency_hits,
            "urgent_hits": [],
        }

    urgent_hits = _match_indicators(hay, symptom_ids, context_factors, urgent)
    if urgent_hits:
        return {
            "display": "URGENT",
            "reason": rule.get("urgent_reason") or "Moderate-risk features present with primary complaint.",
            "needs_provider_review": False,
            "emergency_hits": [],
            "urgent_hits": urgent_hits,
        }

    isolated = str(rule.get("isolated_classification") or "NON-URGENT").upper()
    if isolated not in {"NON-URGENT", "URGENT", "EMERGENCY"}:
        isolated = "NON-URGENT"

    return {
        "display": isolated,
        "reason": rule.get("isolated_reason") or "Primary complaint evaluated using full clinical context.",
        "needs_provider_review": False,
        "emergency_hits": [],
        "urgent_hits": [],
    }


def apply_context_reasoning(
    original: str,
    english: str,
    kb_symptoms: list[dict[str, Any]],
    features: dict[str, Any],
    red_flags: list[dict[str, Any]],
    score: int,
    preliminary_display: str,
) -> dict[str, Any]:
    cfg = _load_config()
    global_cfg = cfg.get("global") or {}
    fallback = cfg.get("fallback") or {}
    rules = cfg.get("rules") or []

    hay = f"{original} {english}".strip().lower()
    symptom_ids = _symptom_ids(kb_symptoms)
    context_factors = _build_feature_keys(features, kb_symptoms)

    if red_flags:
        return {
            "display": "EMERGENCY",
            "score": max(score, 12),
            "needs_provider_review": False,
            "reason": _red_flag_reason(red_flags),
            "rule_id": "CTX_RED_FLAG",
            "rule_name": "Emergency red flags",
            "evaluated_context": ["Emergency red flags present"],
            "sufficient_context": True,
            "factors": {"clinical_context_resolved": True, "context_source": "red_flags", "clinical_context_rule": "CTX_RED_FLAG"},
        }

    best_match: dict[str, Any] | None = None
    best_rule: dict[str, Any] | None = None
    for rule in rules:
        if not isinstance(rule, dict):
            continue
        primary = [str(x).lower() for x in (rule.get("primary_symptoms") or [])]
        if not primary or not set(primary) & set(symptom_ids):
            continue
        matched = _evaluate_rule(rule, hay, symptom_ids, context_factors)
        if best_match is None or _priority(matched["display"]) > _priority(best_match["display"]):
            best_match = matched
            best_rule = rule

    if best_match and best_rule:
        display = best_match["display"]
        return {
            "display": display,
            "score": _score_for_display(display, score),
            "needs_provider_review": bool(best_match.get("needs_provider_review")),
            "reason": best_match.get("reason") or "",
            "rule_id": best_rule.get("id") or "CTX",
            "rule_name": best_rule.get("name") or "Clinical context",
            "evaluated_context": list(best_rule.get("evaluate_for") or []),
            "sufficient_context": not bool(best_match.get("needs_provider_review")),
            "factors": {
                "clinical_context_resolved": True,
                "clinical_context_rule": best_rule.get("id") or "",
                "context_classification": display,
                "context_emergency_hits": best_match.get("emergency_hits") or [],
                "context_urgent_hits": best_match.get("urgent_hits") or [],
                "clinical_context_name": best_rule.get("name") or "",
                "clinical_context_reason": best_match.get("reason") or "",
                "clinical_context_sufficient": not bool(best_match.get("needs_provider_review")),
            },
        }

    requires_context = [str(x).lower() for x in (fallback.get("requires_context_symptoms") or [])]
    has_context_symptom = bool(set(requires_context) & set(symptom_ids))
    symptom_count = len(kb_symptoms)
    has_modifiers = _has_clinical_modifiers(features)
    danger_alone = False
    if fallback.get("danger_sign_requires_context", True):
        for sym in kb_symptoms:
            if sym.get("danger_sign") and symptom_count <= 2 and not has_modifiers:
                danger_alone = True
                break

    insufficient = False
    reason = ""
    if fallback.get("single_symptom_review", True) and symptom_count == 1 and not has_modifiers and has_context_symptom:
        insufficient = True
        reason = str(
            fallback.get("single_symptom_review_reason")
            or global_cfg.get("insufficient_review_reason")
            or "Insufficient clinical information to determine urgency safely."
        )
    elif danger_alone and symptom_count <= 1:
        insufficient = True
        reason = str(global_cfg.get("insufficient_review_reason") or "Insufficient clinical information to determine urgency safely.")

    if insufficient:
        return {
            "display": preliminary_display,
            "score": min(score, 5),
            "needs_provider_review": True,
            "reason": reason,
            "rule_id": "CTX_FALLBACK",
            "rule_name": "Insufficient context",
            "evaluated_context": ["Primary symptom", "Associated symptoms", "Duration", "Severity", "Risk factors"],
            "sufficient_context": False,
            "factors": {
                "clinical_context_resolved": True,
                "clinical_context_rule": "CTX_FALLBACK",
                "insufficient_context": True,
                "clinical_context_name": "Insufficient context",
                "clinical_context_reason": reason,
                "clinical_context_sufficient": False,
            },
        }

    return {
        "display": preliminary_display,
        "score": score,
        "needs_provider_review": False,
        "reason": "Triage based on combined symptom profile, modifiers, and clinical scoring.",
        "rule_id": "CTX_NONE",
        "rule_name": "Combined assessment",
        "evaluated_context": [],
        "sufficient_context": True,
        "factors": {"clinical_context_resolved": False},
    }


def filter_context_gated_red_flags(
    red_flags: list[dict[str, Any]],
    original: str,
    english: str,
    kb_symptoms: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    if not red_flags:
        return red_flags

    cfg = _load_config()
    rules = cfg.get("rules") or []
    hay = f"{original} {english}".strip().lower()
    symptom_ids = _symptom_ids(kb_symptoms)
    context_factors = _build_feature_keys({}, kb_symptoms)

    filtered: list[dict[str, Any]] = []
    for flag in red_flags:
        flag_name = str(flag.get("flag_name") or flag.get("english_pattern") or "").lower()
        flag_id = str(flag.get("flag_id") or "").upper()
        is_gated = flag_id == "RF001" or "chest pain" in flag_name
        if not is_gated:
            filtered.append(flag)
            continue

        keep = False
        for rule in rules:
            if not isinstance(rule, dict):
                continue
            primary = [str(x).lower() for x in (rule.get("primary_symptoms") or [])]
            if not primary or not set(primary) & set(symptom_ids):
                continue
            emergency = rule.get("emergency") or {}
            if _match_indicators(hay, symptom_ids, context_factors, emergency):
                keep = True
                break
        if keep:
            filtered.append(flag)

    return filtered
