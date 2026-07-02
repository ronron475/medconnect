"""Step 6: Standards-based clinical urgency detection."""

from __future__ import annotations

from typing import Any

from clinical_triage_engine import assess


def detect(
    original: str,
    english: str,
    phrase_translation: dict[str, Any],
    concepts: list[dict[str, Any]],
    validated_terms: list[str] | None = None,
    confidence_score: int = 0,
) -> dict[str, Any]:
    entities: list[dict[str, Any]] = []
    try:
        from medical_entity_extractor import extract_entities

        entities = extract_entities(original)
    except ImportError:
        pass

    if not entities and concepts:
        for c in concepts:
            entities.append(
                {
                    "english_term": c.get("english") or c.get("medical_keyword") or "",
                    "symptom": c.get("symptom") or "",
                    "condition": c.get("condition") or "",
                    "body_part": c.get("body_part") or "",
                    "severity": c.get("severity") or "",
                    "category": c.get("category") or "symptom",
                    "type": c.get("classification") or "symptom",
                }
            )

    result = assess(
        original_text=original,
        english_text=english or (phrase_translation or {}).get("english", ""),
        entities=entities,
        validated_terms=validated_terms or [],
        confidence_score=confidence_score,
    )

    return {
        "triage_level": result.get("triage_level", "LOW"),
        "triage_display": result.get("triage_display", "NON-URGENT"),
        "severity": result.get("severity", "mild"),
        "reason": result.get("reason", ""),
        "clinical_reasoning": result.get("clinical_reasoning", ""),
        "source": result.get("source", "clinical_triage_engine_v2"),
        **result,
    }
