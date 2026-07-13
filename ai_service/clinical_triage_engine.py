"""Standards-based multi-factor clinical urgency classification (Step 6)."""

from __future__ import annotations

import re
from typing import Any

CONFIDENCE_THRESHOLD = 85

SEVERITY_SCORES = {"mild": 12, "low": 12, "moderate": 42, "medium": 42, "severe": 72, "high": 72, "critical": 92}
BODY_SYSTEM_URGENCY = {
    "cardiovascular": 25, "respiratory": 25, "neurological": 22, "trauma": 20,
    "bleeding": 30, "infection": 18, "gynecologic": 20, "male_reproductive": 20,
}


def _confidence_level(score: int) -> dict[str, Any]:
    if score >= 95:
        return {"level": "very_high", "label": "Very High", "accepted": True}
    if score >= 90:
        return {"level": "high", "label": "High", "accepted": True}
    if score >= CONFIDENCE_THRESHOLD:
        return {"level": "moderate", "label": "Moderate", "accepted": True}
    return {"level": "review_needed", "label": "Review Needed", "accepted": False}


def _extract_duration(text: str) -> str:
    low = (text or "").lower()
    for pat in [r"\d+\s*ka\s*adlaw", r"dugay\s+na", r"bag-o\s+lang", r"semana\s+na", r"gahapon"]:
        m = re.search(pat, low)
        if m:
            return m.group(0)
    return ""


def _bleeding_status(text: str, entities: list[dict[str, Any]]) -> str:
    low = text.lower()
    if any(x in low for x in ["indi mapunggan", "grabe gid nagadugo", "uncontrolled", "massive bleeding"]):
        return "severe_uncontrolled"
    if any("bleed" in (e.get("english_term") or e.get("symptom") or "").lower() for e in entities):
        if any(x in low for x in ["grabe", "gid", "severe"]):
            return "moderate"
        return "minor"
    if any(x in low for x in ["nagdugo", "gadugo", "nagadugo", "dugo"]):
        return "minor"
    return "none"


def _breathing_status(text: str) -> str:
    low = text.lower()
    if any(x in low for x in ["indi ko makaginhawa", "cannot breathe", "respiratory distress", "choking"]):
        return "severe_distress"
    if any(x in low for x in ["budlay magginhawa", "dula ginhawa", "difficulty breathing", "shortness of breath"]):
        return "moderate_difficulty"
    return "normal"


def _consciousness_status(text: str) -> str:
    low = text.lower()
    if any(x in low for x in ["loss of consciousness", "unconscious", "nadulaan ko malay", "nagpunaw", "nag collapse"]):
        return "altered"
    return "normal"


def _pain_intensity(entities: list[dict[str, Any]], text: str) -> str:
    low = text.lower()
    if "gid" in low or "grabe" in low or "severe" in low:
        return "severe"
    for e in entities:
        sev = (e.get("severity") or "").lower()
        if sev in {"severe", "high", "critical"}:
            return "severe"
        if sev in {"moderate", "medium"}:
            return "moderate"
    if any("pain" in (e.get("english_term") or "").lower() for e in entities):
        return "moderate"
    return "mild"


def _effective_symptom_severity(entities: list[dict[str, Any]], text: str) -> str:
    """Map phrase severity to clinical scoring severity, respecting phrase triage_level."""
    raw = _pain_intensity(entities, text)
    for e in entities:
        tri = (e.get("triage_level") or "").lower()
        if tri in {"non_urgent", "non-urgent", "routine", "low"}:
            return "mild"
        if tri in {"urgent", "high"} and raw in {"severe", "critical"}:
            return "moderate"
    return raw


def _apply_rule_score_bounds(score: int, tri: str) -> int:
    tri = (tri or "").upper().replace("-", "_")
    if tri == "EMERGENCY":
        return max(score, 75)
    if tri in {"HIGH", "URGENT"}:
        return max(min(score, 74), 45)
    if tri in {"LOW", "NON_URGENT", "ROUTINE"}:
        return min(score, 37)
    return score


def _lookup_csv_condition_severity(
    entities: list[dict[str, Any]],
    conditions: list[str],
    symptoms: list[str],
) -> dict[str, Any] | None:
    """Resolve highest CSV severity for detected conditions/symptoms (not LLM)."""
    try:
        from condition_severity_loader import lookup_condition_severity
    except ImportError:
        return None
    terms: list[str] = []
    for e in entities:
        for key in ("english_term", "condition", "symptom", "hiligaynon_term"):
            val = (e.get(key) or "").strip()
            if val:
                terms.append(val)
        tri = (e.get("triage_level") or "").strip().lower().replace("-", "_")
        # Phrase CSV triage_level also contributes as a virtual condition match.
        if tri in {"emergency", "urgent", "non_urgent", "routine"}:
            mapped = {
                "emergency": "EMERGENCY",
                "urgent": "URGENT",
                "non_urgent": "NON_URGENT",
                "routine": "NON_URGENT",
            }[tri if tri != "routine" else "routine"]
            terms.append(f"__phrase_{mapped}")
    terms.extend(conditions)
    terms.extend(symptoms)
    # Resolve real terms first
    real_terms = [t for t in terms if not t.startswith("__phrase_")]
    hit = lookup_condition_severity(*real_terms) if real_terms else None
    if hit:
        return hit
    # Fallback: max phrase triage_level from entities
    rank = {"NON_URGENT": 0, "URGENT": 1, "EMERGENCY": 2}
    best_level = ""
    for e in entities:
        tri = (e.get("triage_level") or "").strip().lower().replace("-", "_")
        if tri in {"non_urgent", "routine", "low"}:
            level = "NON_URGENT"
        elif tri in {"urgent", "high"}:
            level = "URGENT"
        elif tri in {"emergency", "critical"}:
            level = "EMERGENCY"
        else:
            continue
        if not best_level or rank[level] > rank[best_level]:
            best_level = level
    if not best_level:
        return None
    return {
        "medical_condition": "phrase_triage_level",
        "severity_level": best_level,
        "urgency_score": 20 if best_level == "NON_URGENT" else 55 if best_level == "URGENT" else 90,
        "emergency_flag": best_level == "EMERGENCY",
        "recommended_action": "",
        "source": "entity.triage_level",
    }


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


def _score_factors(
    original: str,
    english: str,
    entities: list[dict[str, Any]],
    validated_terms: list[str],
) -> tuple[int, dict[str, Any]]:
    text = f"{original} {english}".lower()
    factors: dict[str, Any] = {
        "primary_symptom": entities[0].get("english_term") if entities else (validated_terms[0] if validated_terms else ""),
        "symptom_severity": _effective_symptom_severity(entities, text),
        "symptom_duration": _extract_duration(original),
        "symptom_count": max(len(validated_terms), len(entities)),
        "body_system": entities[0].get("category") if entities else "general",
        "bleeding_status": _bleeding_status(text, entities),
        "breathing_status": _breathing_status(text),
        "consciousness_status": _consciousness_status(text),
        "pain_intensity": _effective_symptom_severity(entities, text),
        "infection_indicators": [],
        "neurological_indicators": [],
        "injury_mechanism": None,
    }

    score = 0
    sev = factors["symptom_severity"]
    score += SEVERITY_SCORES.get(sev, 12)

    bleed = factors["breathing_status"]
    if factors["breathing_status"] == "severe_distress":
        score += 95
    elif factors["breathing_status"] == "moderate_difficulty":
        score += 45

    if factors["bleeding_status"] == "severe_uncontrolled":
        score += 90
    elif factors["bleeding_status"] == "moderate":
        score += 50
    elif factors["bleeding_status"] == "minor":
        score += 28

    if factors["consciousness_status"] == "altered":
        score += 95

    for e in entities:
        eng = (e.get("english_term") or "").lower()
        cat = (e.get("category") or "").lower()
        if "infection" in eng or "nana" in text or "pus" in eng:
            factors["infection_indicators"].append(eng or "infection")
            score += 35
        if any(x in eng for x in ["stroke", "seizure", "weakness", "speech", "confusion", "vision loss"]):
            factors["neurological_indicators"].append(eng)
            score += 75
        if any(x in eng for x in ["fracture", "amputation", "trauma", "collision", "fall injury"]):
            factors["injury_mechanism"] = eng
            score += 55
        for sys_key, pts in BODY_SYSTEM_URGENCY.items():
            if sys_key in cat or sys_key in eng:
                score += pts
                factors["body_system"] = sys_key
                break

    if factors["symptom_count"] >= 2:
        score += 12
    if "fever" in text or "hilanat" in text:
        if factors["symptom_count"] >= 2:
            score += 22

    matched_rule: dict[str, Any] | None = None
    try:
        from triage_rules_loader import load_rules

        for rule in load_rules():
            hil = rule.get("hiligaynon_pattern") or ""
            eng = rule.get("english_pattern") or ""
            if hil and hil in original.lower():
                matched_rule = rule
                tri = (rule.get("triage_level") or "").upper()
                score = _apply_rule_score_bounds(score, tri)
                factors["matched_triage_rule"] = hil
                break
            if eng and eng in english.lower():
                matched_rule = rule
                tri = (rule.get("triage_level") or "").upper()
                score = _apply_rule_score_bounds(score, tri)
                factors["matched_triage_rule"] = eng
                break
    except ImportError:
        pass

    return min(score, 100), factors


def _build_reasoning(
    display: str,
    symptoms: list[str],
    conditions: list[str],
    body_parts: list[str],
    factors: dict[str, Any],
    emergency_flags: list[dict[str, Any]],
) -> str:
    sym_text = ", ".join(symptoms[:4]) if symptoms else "reported symptoms"
    cond_text = ", ".join(conditions[:2]) if conditions else ""
    bp_text = ", ".join(body_parts[:2]) if body_parts else ""

    if display == "EMERGENCY":
        names = [f.get("flag_name") or f.get("english_pattern") for f in emergency_flags]
        flag_txt = ", ".join(names[:3]) if names else "established emergency warning signs"
        return (
            f"Symptoms match established emergency warning criteria ({flag_txt}) "
            "and may pose an immediate threat to life or function."
        )

    if display == "URGENT":
        parts = []
        if cond_text:
            parts.append(f"The presence of {cond_text.lower()}")
        elif sym_text:
            parts.append(f"The presence of {sym_text.lower()}")
        if factors.get("infection_indicators"):
            parts.append("with infection indicators")
        if factors.get("bleeding_status") not in (None, "none"):
            parts.append(f"with {factors['bleeding_status'].replace('_', ' ')}")
        if bp_text:
            parts.append(f"affecting the {bp_text}")
        lead = " ".join(parts) if parts else "Clinical findings"
        return f"{lead} suggests a potentially serious condition that should be evaluated by a healthcare provider within hours."

    return (
        "Symptoms are mild, stable, and do not currently indicate a serious medical condition "
        "requiring immediate intervention."
    )


def assess(
    original_text: str = "",
    english_text: str = "",
    entities: list[dict[str, Any]] | None = None,
    validated_terms: list[str] | None = None,
    confidence_score: int = 0,
) -> dict[str, Any]:
    """Multi-factor clinical urgency assessment with explainable output."""
    original = (original_text or "").strip()
    english = (english_text or "").strip()
    entities = entities or []
    validated_terms = validated_terms or []

    if not entities and original:
        try:
            from medical_entity_extractor import extract_entities

            entities = extract_entities(original)
        except ImportError:
            pass

    symptoms, conditions, body_parts = _collect_from_entities(entities)
    for term in validated_terms:
        t = term.strip()
        if t and t not in symptoms and t not in conditions:
            if any(x in t.lower() for x in ["infection", "fracture", "trauma"]):
                conditions.append(t)
            else:
                symptoms.append(t)
    symptoms = list(dict.fromkeys(symptoms))
    conditions = list(dict.fromkeys(conditions))

    try:
        from emergency_flags_loader import scan_emergency_flags

        red_flags = scan_emergency_flags(original, english)
    except ImportError:
        red_flags = []

    urgency_score, factors = _score_factors(original, english, entities, validated_terms)

    # CSV condition/phrase severity classifications constrain the composite score.
    csv_severity = _lookup_csv_condition_severity(entities, conditions, symptoms)
    if csv_severity:
        factors["csv_condition_severity"] = csv_severity
        urgency_score = _apply_rule_score_bounds(urgency_score, csv_severity["severity_level"])
        factors["matched_condition_severity"] = csv_severity.get("medical_condition") or ""

    if red_flags:
        display = "EMERGENCY"
        priority = "Critical"
        triage_level = "EMERGENCY"
        classification = "EMERGENCY"
        recommendation = "Seek emergency medical care immediately."
        reason_short = red_flags[0].get("clinical_rationale") or "Emergency red flag detected."
    elif urgency_score >= 75:
        display = "EMERGENCY"
        priority = "Critical"
        triage_level = "EMERGENCY"
        classification = "EMERGENCY"
        recommendation = (
            (csv_severity or {}).get("recommended_action")
            or "Seek emergency medical care immediately."
        )
        reason_short = "Composite urgency score indicates emergency-level concern (CSV-backed)."
    elif urgency_score >= 38:
        display = "URGENT"
        priority = "Medium"
        triage_level = "HIGH"
        classification = "URGENT"
        recommendation = (
            (csv_severity or {}).get("recommended_action")
            or "Consult a healthcare provider as soon as possible."
        )
        reason_short = "CSV severity / symptom profile warrants prompt evaluation."
    else:
        display = "NON-URGENT"
        priority = "Low"
        triage_level = "LOW"
        classification = "NON_URGENT"
        recommendation = (
            (csv_severity or {}).get("recommended_action")
            or "Routine consultation."
        )
        reason_short = "CSV severity classifications indicate non-urgent presentation."

    conf = _confidence_level(confidence_score)
    clinical_reasoning = _build_reasoning(display, symptoms, conditions, body_parts, factors, red_flags)
    emergency_flag_names = list(dict.fromkeys(f.get("flag_name") or f.get("english_pattern", "") for f in red_flags))

    icon_map = {"NON-URGENT": "🟢", "URGENT": "🟡", "EMERGENCY": "🔴"}

    return {
        "triage_display": display,
        "triage_classification": classification,
        "triage_level": triage_level,
        "triage_icon": icon_map.get(display, "🟢"),
        "priority": priority,
        "severity_score": urgency_score,
        "severity": factors.get("symptom_severity", "mild"),
        "confidence_score": confidence_score,
        "confidence_display": f"{confidence_score}%" if confidence_score > 0 else "—",
        "confidence_level": conf["level"],
        "confidence_level_label": conf["label"],
        "confidence_accepted": conf["accepted"],
        "confidence_threshold": CONFIDENCE_THRESHOLD,
        "detected_symptoms": symptoms,
        "detected_conditions": conditions,
        "detected_body_parts": body_parts,
        "emergency_flags": emergency_flag_names,
        "red_flags_triggered": red_flags,
        "assessment_factors": factors,
        "clinical_reasoning": clinical_reasoning,
        "reason": clinical_reasoning,
        "recommendation": recommendation,
        "recommended_action": recommendation,
        "source": "clinical_triage_engine_v2",
        "engine_version": "2.0",
    }
