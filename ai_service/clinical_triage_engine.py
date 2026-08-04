"""Evidence-based clinical triage CDS engine (v3).

Rule-based severity scoring with red-flag override, duration/pain/temperature/
risk modifiers, confidence gating, and explainable structured output.

Never diagnoses disease and never prescribes medication.
"""

from __future__ import annotations

from typing import Any

from clinical_feature_extractors import extract_all_features
from negation_detector import detect_negated_concepts, filter_red_flags, filter_symptoms
from symptom_knowledge_base import (
    match_symptoms,
    scan_red_flags_library,
    scoring_config,
)

CONFIDENCE_THRESHOLD = 60
REVIEW_RECOMMENDATION = "Needs Healthcare Provider Review"

PRIORITY_MAP = {
    "NON-URGENT": "Normal",
    "URGENT": "High",
    "EMERGENCY": "Critical",
}

RECOMMENDATION_MAP = {
    "NON-URGENT": "Schedule the patient for a regular consultation.",
    "URGENT": "Arrange prompt clinical evaluation within hours to 24 hours.",
    "EMERGENCY": "Refer for immediate emergency care now.",
}


def _confidence_level(score: int) -> dict[str, Any]:
    if score >= 90:
        return {"level": "very_high", "label": "Very High", "accepted": True}
    if score >= 75:
        return {"level": "high", "label": "High", "accepted": True}
    if score >= CONFIDENCE_THRESHOLD:
        return {"level": "moderate", "label": "Moderate", "accepted": True}
    return {"level": "review_needed", "label": "Review Needed", "accepted": False}


def _collect_from_entities(entities: list[dict[str, Any]]) -> tuple[list[str], list[str], list[str]]:
    symptoms: list[str] = []
    conditions: list[str] = []
    body_parts: list[str] = []
    for e in entities:
        eng = (e.get("english_term") or "").strip()
        if not eng:
            continue
        sym = (e.get("symptom") or "").strip()
        cond = (e.get("condition") or "").strip()
        bp = (e.get("body_part") or "").strip()
        if sym and sym not in {"symptom"}:
            symptoms.append(sym.replace("_", " "))
        if eng:
            if cond or "infection" in eng.lower() or e.get("type") == "condition":
                conditions.append(eng)
            else:
                symptoms.append(eng)
        if bp:
            body_parts.append(bp)
    return (
        list(dict.fromkeys(symptoms)),
        list(dict.fromkeys(conditions)),
        list(dict.fromkeys(body_parts)),
    )


def _merge_csv_red_flags(original: str, english: str) -> list[dict[str, Any]]:
    flags = scan_red_flags_library(original, english)
    try:
        from emergency_flags_loader import scan_emergency_flags

        csv_flags = scan_emergency_flags(original, english)
    except ImportError:
        csv_flags = []

    seen = {(f.get("flag_name") or "").lower() for f in flags}
    for f in csv_flags:
        name = (f.get("flag_name") or f.get("english_pattern") or "").strip()
        if not name or name.lower() in seen:
            continue
        flags.append(
            {
                "flag_id": f.get("flag_id") or "",
                "flag_name": name,
                "category": f.get("category") or "",
                "auto_triage": (f.get("auto_triage") or "EMERGENCY").upper(),
                "severity_points": 12,
                "clinical_rationale": f.get("clinical_rationale") or "",
                "matched_on": f.get("matched_on") or "",
                "matched_pattern": f.get("english_pattern") or f.get("hiligaynon_pattern") or "",
                "english_pattern": f.get("english_pattern") or "",
                "source": "emergency_flags.csv",
            }
        )
        seen.add(name.lower())
    return flags


def _score_from_kb(
    kb_symptoms: list[dict[str, Any]],
    features: dict[str, Any],
    red_flags: list[dict[str, Any]],
) -> tuple[int, dict[str, Any]]:
    cfg = scoring_config()
    duration_mods = cfg.get("duration_modifiers") or {}
    pain_mods = cfg.get("pain_scale_modifiers") or {}
    temp_mods = cfg.get("temperature_modifiers") or {}
    risk_bonus = int(cfg.get("risk_factor_bonus") or 2)
    high_risk_bonus = int(cfg.get("high_risk_with_chest_or_breathing_bonus") or 6)

    score = 0
    contributions: list[dict[str, Any]] = []

    for sym in kb_symptoms:
        pts = int(sym.get("severity_weight") or 0)
        score += pts
        contributions.append(
            {
                "factor": sym.get("symptom_name"),
                "points": pts,
                "type": "symptom",
            }
        )

    # Avoid double-counting red-flag points already represented by danger symptoms
    danger_ids = {s.get("id") for s in kb_symptoms if s.get("danger_sign")}
    for flag in red_flags:
        # If a matching danger symptom already contributed heavily, skip additive flag points
        fname = (flag.get("flag_name") or "").lower()
        already = any(
            (s.get("symptom_name") or "").lower() in fname or fname in (s.get("symptom_name") or "").lower()
            for s in kb_symptoms
            if s.get("danger_sign")
        )
        if already or danger_ids:
            continue
        pts = int(flag.get("severity_points") or 12)
        score += pts
        contributions.append({"factor": flag.get("flag_name"), "points": pts, "type": "red_flag"})

    duration = features.get("duration") or {}
    bucket = duration.get("bucket") or "unknown"
    if bucket in duration_mods:
        pts = int(duration_mods[bucket] or 0)
        if pts:
            # Prolonged fever escalation example: fever + 5 days
            feverish = any((s.get("id") == "fever") for s in kb_symptoms)
            if feverish and bucket == "5_plus_days":
                pts = max(pts, 4)
            score += pts
            contributions.append({"factor": f"Duration ({duration.get('label')})", "points": pts, "type": "duration"})

    pain = features.get("pain_scale") or {}
    pain_key = pain.get("modifier_key") or ""
    if pain_key in pain_mods:
        pts = int(pain_mods[pain_key] or 0)
        if pts:
            score += pts
            contributions.append({"factor": pain.get("label") or "Pain scale", "points": pts, "type": "pain"})

    temp = features.get("temperature") or {}
    temp_key = temp.get("modifier_key") or ""
    # Only add temperature modifier if fever symptom weight was not already counted,
    # except for high fever which adds extra urgency.
    has_fever_symptom = any(s.get("id") == "fever" for s in kb_symptoms)
    if temp_key in temp_mods:
        pts = int(temp_mods[temp_key] or 0)
        if has_fever_symptom and temp_key in {"fever", "low_grade"}:
            pts = 0
        if has_fever_symptom and temp_key == "high_fever":
            pts = max(0, pts - 2)  # net +2 on top of fever weight
        if pts:
            score += pts
            contributions.append({"factor": temp.get("label") or "Temperature", "points": pts, "type": "temperature"})

    risks = features.get("risk_factors") or []
    if risks:
        pts = risk_bonus * min(len(risks), 3)
        # High-risk cardiopulmonary combination
        has_cardio_resp = any(
            s.get("id") in {"chest_pain", "difficulty_breathing", "palpitations"} for s in kb_symptoms
        )
        risk_ids = {r.get("id") for r in risks}
        if has_cardio_resp and risk_ids.intersection({"heart_disease", "hypertension", "asthma", "pregnant", "senior"}):
            pts = max(pts, high_risk_bonus)
        score += pts
        labels = ", ".join(r.get("label") for r in risks[:3] if r.get("label"))
        contributions.append({"factor": f"Risk factors ({labels})", "points": pts, "type": "risk"})

    factors = {
        "primary_symptom": (kb_symptoms[0].get("symptom_name") if kb_symptoms else ""),
        "symptom_severity": (
            "severe" if score >= 12 else "moderate" if score >= 6 else "mild"
        ),
        "symptom_duration": duration.get("label") or duration.get("raw") or "",
        "symptom_count": len(kb_symptoms),
        "pain_intensity": pain.get("band") or "",
        "pain_score": pain.get("score"),
        "temperature": temp.get("label") or "",
        "age_group": features.get("age_group") or "Unknown",
        "risk_factors": [r.get("label") for r in risks if r.get("label")],
        "score_contributions": contributions,
        "duration_bucket": bucket,
    }
    return score, factors


def _classify(
    score: int,
    red_flags: list[dict[str, Any]],
    kb_symptoms: list[dict[str, Any]] | None = None,
) -> str:
    if red_flags:
        return "EMERGENCY"
    for sym in kb_symptoms or []:
        if sym.get("danger_sign") or int(sym.get("emergency_weight") or 0) >= 8:
            return "EMERGENCY"
    if score >= 12:
        return "EMERGENCY"
    if score >= 6:
        return "URGENT"
    return "NON-URGENT"


def _display_to_level(display: str) -> tuple[str, str]:
    if display == "EMERGENCY":
        return "EMERGENCY", "EMERGENCY"
    if display == "URGENT":
        return "HIGH", "URGENT"
    return "LOW", "NON_URGENT"


def _build_reason(
    display: str,
    symptoms: list[str],
    duration_label: str,
    red_flags: list[dict[str, Any]],
    risk_labels: list[str],
    score: int,
    vague: bool,
) -> str:
    if vague and not symptoms and not red_flags:
        return (
            "The complaint is too vague to support a confident triage recommendation. "
            "A healthcare provider should review the case."
        )
    if display == "EMERGENCY":
        if red_flags:
            names = ", ".join(
                (f.get("flag_name") or f.get("english_pattern") or "warning sign") for f in red_flags[:3]
            )
            return (
                f"Emergency warning sign(s) detected ({names}). "
                "Immediate emergency evaluation is recommended for patient safety."
            )
        return (
            f"Severity score is {score}, which meets emergency triage criteria based on "
            "detected high-acuity symptoms and clinical modifiers."
        )
    if display == "URGENT":
        sym = ", ".join(symptoms[:4]) if symptoms else "reported symptoms"
        dur = f" Duration: {duration_label}." if duration_label else ""
        risk = f" Risk factors: {', '.join(risk_labels)}." if risk_labels else ""
        return (
            f"The presentation includes {sym.lower()} with a severity score of {score}.{dur}{risk} "
            "No confirmed emergency red flag was required for escalation, but prompt clinician review is warranted."
        )
    sym = ", ".join(symptoms[:4]) if symptoms else "mild symptoms"
    dur = f" Duration is {duration_label.lower()}." if duration_label else ""
    return (
        f"The complaint contains {sym.lower()} with no emergency warning signs{',' if dur else '.'}"
        f"{dur} Severity score is {score} (non-urgent range)."
    )


def _compute_confidence(
    base_confidence: int,
    kb_symptoms: list[dict[str, Any]],
    features: dict[str, Any],
    red_flags: list[dict[str, Any]],
    validated_terms: list[str],
) -> int:
    score = max(0, min(100, int(base_confidence or 0)))
    if score == 0:
        # Bootstrap from structured matches when upstream confidence missing
        if kb_symptoms:
            score = 70 + min(20, len(kb_symptoms) * 5)
        elif validated_terms:
            score = 65
        else:
            score = 40

    weak_only = bool(kb_symptoms) and all(
        (s.get("id") in {"fatigue"} or int(s.get("severity_weight") or 0) <= 1) for s in kb_symptoms
    )
    if features.get("vague_complaint"):
        # Vague free-text (e.g. "I don't feel well") must stay low-confidence
        if not red_flags and (not kb_symptoms or weak_only):
            score = min(score, 42)
    if not kb_symptoms and not red_flags:
        score = min(score, 50)
    if kb_symptoms and (features.get("duration") or {}).get("label"):
        score = min(100, score + 5)
    if red_flags:
        score = min(100, max(score, 85))
    if len(kb_symptoms) >= 2:
        score = min(100, score + 3)
    return int(score)


def assess(
    original_text: str = "",
    english_text: str = "",
    entities: list[dict[str, Any]] | None = None,
    validated_terms: list[str] | None = None,
    confidence_score: int = 0,
) -> dict[str, Any]:
    """Multi-factor clinical urgency assessment with explainable CDS output."""
    original = (original_text or "").strip()
    english = (english_text or "").strip()
    entities = entities or []
    validated_terms = validated_terms or []

    # Spelling / abbreviation normalization (CSV-expandable)
    try:
        from medical_misspellings_loader import apply_misspelling_corrections

        original = apply_misspelling_corrections(original) or original
        english = apply_misspelling_corrections(english or original) or english
    except ImportError:
        pass

    if not entities and original:
        try:
            from medical_entity_extractor import extract_entities

            entities = extract_entities(original)
        except ImportError:
            pass

    entity_symptoms, conditions, body_parts = _collect_from_entities(entities)
    for term in validated_terms:
        t = term.strip()
        if t and t not in entity_symptoms and t not in conditions:
            entity_symptoms.append(t)

    negated_concepts = detect_negated_concepts(f"{original} {english}")
    features = extract_all_features(original, english, negated_concepts)
    kb_symptoms = match_symptoms(
        original,
        english,
        extra_terms=entity_symptoms + validated_terms,
    )
    # Negation: never keep denied/negated symptoms
    kb_symptoms = filter_symptoms(kb_symptoms, original, english)

    # Ensure entity-only symptoms still surface in explanation even if not in KB
    detected_names = [s["symptom_name"] for s in kb_symptoms if s.get("symptom_name")]
    for name in entity_symptoms:
        pretty = name.strip().title() if name == name.lower() else name.strip()
        if pretty and pretty not in detected_names:
            # Unknown entity contributes small weight (1) without diagnosing
            kb_symptoms.append(
                {
                    "id": f"entity_{pretty.lower().replace(' ', '_')}",
                    "symptom_name": pretty,
                    "medical_category": "general",
                    "severity_weight": 1,
                    "emergency_weight": 0,
                    "urgent_weight": 0,
                    "danger_sign": False,
                    "recommended_action": "Clinician review recommended if persistent.",
                    "matched_term": name,
                }
            )
            detected_names.append(pretty)
    kb_symptoms = filter_symptoms(kb_symptoms, original, english)
    detected_names = [s["symptom_name"] for s in kb_symptoms if s.get("symptom_name")]

    red_flags = filter_red_flags(_merge_csv_red_flags(original, english), original, english)
    severity_score, factors = _score_from_kb(kb_symptoms, features, red_flags)
    display = _classify(severity_score, red_flags, kb_symptoms)
    triage_level, classification = _display_to_level(display)

    confidence = _compute_confidence(
        confidence_score, kb_symptoms, features, red_flags, validated_terms
    )
    conf = _confidence_level(confidence)

    duration_label = (features.get("duration") or {}).get("label") or ""
    risk_labels = [r.get("label") for r in (features.get("risk_factors") or []) if r.get("label")]
    reason = _build_reason(
        display,
        detected_names,
        duration_label,
        red_flags,
        risk_labels,
        severity_score,
        bool(features.get("vague_complaint")),
    )

    recommendation = RECOMMENDATION_MAP[display]
    # Prefer KB action from highest-acuity matched symptom when emergency/danger
    for sym in kb_symptoms:
        if sym.get("danger_sign") and sym.get("recommended_action"):
            recommendation = str(sym["recommended_action"])
            break
    if red_flags:
        recommendation = "Refer for immediate emergency care now."

    needs_review = confidence < CONFIDENCE_THRESHOLD
    if needs_review:
        recommendation = REVIEW_RECOMMENDATION
        # Keep classification for safety if red flags exist; otherwise soft-hold
        if not red_flags and display != "EMERGENCY":
            reason = (
                f"Confidence is {confidence}% (below {CONFIDENCE_THRESHOLD}%). "
                + reason
            )

    emergency_flag_names = list(
        dict.fromkeys(f.get("flag_name") or f.get("english_pattern", "") for f in red_flags)
    )
    icon_map = {"NON-URGENT": "🟢", "URGENT": "🟡", "EMERGENCY": "🔴"}
    clinical_reasoning = reason

    # Structured CDS payload (canonical)
    recommendation_payload = {
        "chief_complaint": original_text or original,
        "detected_symptoms": detected_names,
        "duration": duration_label or None,
        "pain_scale": (features.get("pain_scale") or {}).get("label") or None,
        "temperature": (features.get("temperature") or {}).get("label") or None,
        "red_flags": emergency_flag_names,
        "risk_factors": risk_labels,
        "age_group": features.get("age_group") or "Unknown",
        "severity_score": severity_score,
        "classification": display,
        "priority": PRIORITY_MAP[display],
        "confidence": confidence,
        "reason": reason,
        "recommendation": recommendation,
        "needs_provider_review": needs_review,
        "disclaimer": (
            "This is a triage decision-support recommendation only. "
            "It does not diagnose disease and does not prescribe medication."
        ),
    }

    result = {
        "triage_display": display,
        "triage_classification": classification,
        "triage_level": triage_level,
        "triage_icon": icon_map.get(display, "🟢"),
        "priority": PRIORITY_MAP[display],
        "severity_score": severity_score,
        "severity": factors.get("symptom_severity", "mild"),
        "confidence_score": confidence,
        "confidence": confidence,
        "confidence_display": f"{confidence}%",
        "confidence_level": conf["level"],
        "confidence_level_label": conf["label"],
        "confidence_accepted": conf["accepted"] and not needs_review,
        "confidence_threshold": CONFIDENCE_THRESHOLD,
        "needs_provider_review": needs_review,
        "detected_symptoms": detected_names,
        "detected_conditions": [],  # intentionally empty — no diagnosis
        "detected_body_parts": list(dict.fromkeys(body_parts)),
        "duration": duration_label,
        "pain_scale": features.get("pain_scale") or {},
        "temperature": features.get("temperature") or {},
        "risk_factors": risk_labels,
        "age_group": features.get("age_group") or "Unknown",
        "emergency_flags": emergency_flag_names,
        "red_flags": emergency_flag_names,
        "red_flags_triggered": red_flags,
        "assessment_factors": factors,
        "clinical_reasoning": clinical_reasoning,
        "reason": reason,
        "recommendation": recommendation,
        "recommended_action": recommendation,
        "recommendation_payload": recommendation_payload,
        "kb_matched_symptoms": kb_symptoms,
        "normalized_text": original,
        "negated_concepts": features.get("negated_concepts") or [],
        "source": "clinical_triage_engine_v3",
        "engine_version": "3.1",
    }

    try:
        from triage_self_validation import validate as self_validate

        result = self_validate(
            result,
            {
                "original_input": original_text or original,
                "normalized_text": original,
                "english_text": english,
            },
        )
    except ImportError:
        pass

    return result
