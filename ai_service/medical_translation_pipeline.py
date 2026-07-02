"""Step 2 — Medical Translation pipeline with explicit stage ordering.

Sequence:
  Patient Input → Medical Dictionary → Hiligaynon Dataset → Keyword Extraction
  → Groq Context Analysis → English Interpretation → (Fuzzy Matching → Validation)
"""

from __future__ import annotations

import re
from concurrent.futures import ThreadPoolExecutor
from typing import Any

from dictionary_loader import load_dictionary_rows, local_to_english_map, dictionary_row_index, _dictionary_phrase_candidates
from medical_translator import translate_field, _overall_status, _status_label
from translation_ai_enrichment import enrich_field_with_ai, enrich_profile_fields_with_ai

PIPELINE_SEQUENCE = [
    "patient_input",
    "medical_dictionary",
    "hiligaynon_dataset",
    "keyword_extraction",
    "groq_context_analysis",
    "english_interpretation",
]

PIPELINE_LABELS = {
    "patient_input": "Patient Input",
    "medical_dictionary": "Medical Dictionary",
    "hiligaynon_dataset": "Hiligaynon Dataset",
    "keyword_extraction": "Keyword Extraction",
    "groq_context_analysis": "Groq Context Analysis",
    "english_interpretation": "English Interpretation",
    "fuzzy_matching": "Fuzzy Matching",
    "validation": "Validation",
}


def _phrase_positions(text: str, phrase: str) -> list[tuple[int, int]]:
    if not phrase or not text:
        return []
    pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", re.I)
    return [(m.start(), m.end()) for m in pattern.finditer(text)]


def _scan_longest_matches(text: str, terms: list[str]) -> list[dict[str, Any]]:
    """Non-overlapping longest-match scan for a term list."""
    if not text or not terms:
        return []
    working = text.lower()
    occupied = [False] * len(working)
    candidates: list[tuple[int, int, str]] = []
    for term in terms:
        for start, end in _phrase_positions(working, term):
            candidates.append((start, end, term))
    candidates.sort(key=lambda x: (-(x[1] - x[0]), x[0]))

    matched: list[tuple[int, int, str]] = []
    for start, end, term in candidates:
        if any(occupied[start:end]):
            continue
        for i in range(start, end):
            occupied[i] = True
        matched.append((start, end, term))
    matched.sort(key=lambda x: x[0])
    return [{"local_term": term, "span": [start, end]} for start, end, term in matched]


def scan_medical_dictionary(text: str) -> list[dict[str, Any]]:
    """Stage 2: find medical_dictionary.csv matches in patient text."""
    normalized = text.lower().strip()
    if not normalized:
        return []
    l2e = local_to_english_map()
    terms = _dictionary_phrase_candidates(normalized, l2e)
    raw = _scan_longest_matches(normalized, terms)
    index = dictionary_row_index()
    out: list[dict[str, Any]] = []
    for row in raw:
        local = row["local_term"]
        entry = index.get(local.lower())
        if entry:
            out.append(
                {
                    "local_term": entry["local_term"],
                    "english_term": entry["english_term"],
                    "category": entry["category"],
                    "dictionary_id": entry.get("dictionary_id"),
                    "source": "medical_dictionary",
                }
            )
    return out


def scan_hiligaynon_dataset(text: str) -> list[dict[str, Any]]:
    """Stage 3: phrase-first Hiligaynon dataset + symptom phrase matches."""
    normalized = text.lower().strip()
    if not normalized:
        return []

    out: list[dict[str, Any]] = []
    seen: set[str] = set()

    try:
        from medical_entity_extractor import extract_entities

        for ent in extract_entities(text):
            hil = str(ent.get("hiligaynon_term") or "")
            eng = str(ent.get("english_term") or "")
            key = hil.lower()
            if not key or key in seen:
                continue
            seen.add(key)
            out.append(
                {
                    "local_term": hil,
                    "english_term": eng,
                    "category": ent.get("category") or "symptom",
                    "body_part": ent.get("body_part"),
                    "symptom": ent.get("symptom"),
                    "condition": ent.get("condition"),
                    "severity": ent.get("severity"),
                    "source": "phrase_entity_extraction",
                }
            )
        if out:
            return out
    except ImportError:
        pass

    try:
        from hiligaynon_nlp_dataset_loader import term_index, terms_by_length
    except ImportError:
        return []

    raw = _scan_longest_matches(normalized, terms_by_length())
    index = term_index()
    for row in raw:
        entry = index.get(row["local_term"])
        if not entry:
            continue
        key = row["local_term"]
        if key in seen:
            continue
        seen.add(key)
        out.append(
            {
                "local_term": row["local_term"],
                "english_term": entry.get("english") or entry.get("medical_term") or "",
                "category": entry.get("category") or "general",
                "body_system": entry.get("body_system"),
                "dataset_id": entry.get("id"),
                "source": "hiligaynon_nlp_dataset",
            }
        )
    return out


def scan_medical_entities(text: str) -> list[dict[str, Any]]:
    """Structured entity extraction for pipeline diagnostics."""
    try:
        from medical_entity_extractor import extract_entities

        return extract_entities(text)
    except ImportError:
        return []


def build_field_pipeline_context(
    original_text: str,
    preprocessed: dict[str, Any],
) -> dict[str, Any]:
    """Build staged context for Groq after dictionary, dataset, and keyword stages."""
    normalized = (preprocessed.get("normalized") or "").strip()
    scan_text = normalized or original_text.strip()
    keywords = list(preprocessed.get("keywords") or [])

    dict_matches = scan_medical_dictionary(scan_text)
    dataset_matches = scan_hiligaynon_dataset(scan_text)
    entity_matches = scan_medical_entities(original_text)

    for row in dict_matches:
        local = str(row.get("local_term") or "")
        if local and local not in keywords:
            keywords.append(local)

    stages: dict[str, Any] = {
        "patient_input": {
            "status": "complete" if original_text.strip() else "empty",
            "label": PIPELINE_LABELS["patient_input"],
            "text": original_text,
            "normalized": normalized,
        },
        "medical_dictionary": {
            "status": "complete" if dict_matches else ("empty" if not scan_text else "none"),
            "label": PIPELINE_LABELS["medical_dictionary"],
            "match_count": len(dict_matches),
            "matches": dict_matches,
        },
        "hiligaynon_dataset": {
            "status": "complete" if dataset_matches else ("empty" if not scan_text else "none"),
            "label": PIPELINE_LABELS["hiligaynon_dataset"],
            "match_count": len(dataset_matches),
            "matches": dataset_matches,
            "entity_extraction": entity_matches,
            "entity_count": len(entity_matches),
        },
        "keyword_extraction": {
            "status": "complete" if keywords else ("empty" if not scan_text else "none"),
            "label": PIPELINE_LABELS["keyword_extraction"],
            "keywords": keywords,
            "keywords_text": " ".join(keywords),
        },
    }

    return {
        "stages": stages,
        "dictionary_matches": dict_matches,
        "dataset_matches": dataset_matches,
        "keywords": keywords,
        "scan_text": scan_text,
    }


def _attach_field_pipeline_stages(
    field_block: dict[str, Any],
    context: dict[str, Any],
) -> dict[str, Any]:
    stages = dict(context["stages"])
    ai_block = field_block.get("ai_interpretation") or {}
    groq_status = ai_block.get("status") or "unavailable"
    english_text = str(field_block.get("english_text") or "").strip()

    stages["groq_context_analysis"] = {
        "status": groq_status,
        "label": PIPELINE_LABELS["groq_context_analysis"],
        "provider": ai_block.get("provider"),
        "model": ai_block.get("model"),
        "confidence_score": ai_block.get("confidence_score"),
        "notes": ai_block.get("notes"),
        "groq_error": ai_block.get("groq_error"),
        "groq_attempted": ai_block.get("groq_attempted", groq_status in ("fallback", "unavailable")),
        "groq_skipped": bool(ai_block.get("groq_skipped")),
        "context_sources": {
            "dictionary_matches": len(context["dictionary_matches"]),
            "dataset_matches": len(context["dataset_matches"]),
            "keywords": len(context["keywords"]),
        },
    }
    stages["english_interpretation"] = {
        "status": "complete" if english_text else "empty",
        "label": PIPELINE_LABELS["english_interpretation"],
        "english_text": english_text,
        "english_preview": field_block.get("english_preview") or english_text,
        "validation_queue_count": len(field_block.get("validation_queue") or []),
        "ai_concepts_added": int(field_block.get("ai_concepts_added") or 0),
    }

    field_block["pipeline"] = {
        "version": "1.0",
        "sequence": list(PIPELINE_SEQUENCE),
        "downstream": ["fuzzy_matching", "validation"],
        "stages": stages,
    }
    return field_block


def prepare_field_translation(
    preprocessed: dict[str, Any],
    original_text: str,
    expected_category: str,
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Dictionary + dataset stages only (Groq deferred for batch/combined call)."""
    context = build_field_pipeline_context(original_text, preprocessed)
    translation = translate_field(preprocessed, expected_category)
    return translation, context


def run_field_translation(
    preprocessed: dict[str, Any],
    original_text: str,
    expected_category: str,
) -> dict[str, Any]:
    """Run Step 2 for one field through dictionary → dataset → keywords → Groq → English."""
    translation, context = prepare_field_translation(preprocessed, original_text, expected_category)
    translation = enrich_field_with_ai(
        translation,
        original_text,
        expected_category,
        pipeline_context=context,
    )
    return _attach_field_pipeline_stages(translation, context)


def run_medical_translation(
    preprocessing: dict[str, Any],
    conditions_original: str = "",
    allergies_original: str = "",
) -> dict[str, Any]:
    """Run full Step 2 translation for conditions and allergies fields."""
    prep_conditions = preprocessing.get("conditions") or {}
    prep_allergies = preprocessing.get("allergies") or {}

    conditions_original = conditions_original or str(prep_conditions.get("original") or "")
    allergies_original = allergies_original or str(prep_allergies.get("original") or "")

    with ThreadPoolExecutor(max_workers=2) as executor:
        future_conditions = executor.submit(
            prepare_field_translation, prep_conditions, conditions_original, "condition"
        )
        future_allergies = executor.submit(
            prepare_field_translation, prep_allergies, allergies_original, "allergy"
        )
        conditions, conditions_ctx = future_conditions.result()
        allergies, allergies_ctx = future_allergies.result()

    conditions, allergies = enrich_profile_fields_with_ai(
        conditions,
        allergies,
        conditions_original,
        allergies_original,
        conditions_ctx,
        allergies_ctx,
    )
    conditions = _attach_field_pipeline_stages(conditions, conditions_ctx)
    allergies = _attach_field_pipeline_stages(allergies, allergies_ctx)

    combined_parts = [p for p in (conditions.get("english_text"), allergies.get("english_text")) if p]
    combined_english = " | ".join(combined_parts)

    total_m = int(conditions.get("matched_count") or 0) + int(allergies.get("matched_count") or 0)
    total_u = int(conditions.get("unmatched_count") or 0) + int(allergies.get("unmatched_count") or 0)
    total = int(conditions.get("total_count") or 0) + int(allergies.get("total_count") or 0)
    overall = _overall_status(total_m, total_u, total)

    ai_conditions = conditions.get("ai_interpretation") or {}
    ai_allergies = allergies.get("ai_interpretation") or {}

    def _ai_priority(block: dict[str, Any]) -> int:
        order = {"complete": 3, "fallback": 2, "disabled": 1, "skipped": 0, "unavailable": 0}
        return order.get(str(block.get("status") or ""), 0)

    active = ai_conditions if _ai_priority(ai_conditions) >= _ai_priority(ai_allergies) else ai_allergies

    scores = [s for s in [ai_conditions.get("confidence_score"), ai_allergies.get("confidence_score")] if s]
    overall_confidence = int(round(sum(scores) / len(scores))) if scores else 0

    ai_interpretation = {
        "status": active.get("status") or "unavailable",
        "provider": active.get("provider") if active.get("status") == "complete" else active.get("provider"),
        "model": active.get("model") if active.get("status") == "complete" else active.get("model"),
        "overall_confidence": overall_confidence,
        "english_interpretation": combined_english,
        "conditions": ai_conditions,
        "allergies": ai_allergies,
        "detected_concepts": (ai_conditions.get("concepts") or []) + (ai_allergies.get("concepts") or []),
        "groq_error": active.get("groq_error") or ai_conditions.get("groq_error") or ai_allergies.get("groq_error"),
        "groq_attempted": bool(
            active.get("groq_attempted")
            or ai_conditions.get("groq_attempted")
            or ai_allergies.get("groq_attempted")
            or active.get("status") in ("fallback", "unavailable")
        ),
        "policy": (
            "Groq contextual analysis improves translation only. "
            "All concepts must pass fuzzy matching (Step 3) and dataset validation (Step 4)."
        ),
        "concepts_queued_for_validation": int(conditions.get("ai_concepts_added") or 0)
        + int(allergies.get("ai_concepts_added") or 0),
        "primary_provider": "groq",
    }

    return {
        "allergies": allergies,
        "conditions": conditions,
        "combined_english": combined_english,
        "overall_status": overall,
        "overall_status_label": _status_label(overall, total_m, total),
        "ai_interpretation": ai_interpretation,
        "pipeline": {
            "version": "1.0",
            "sequence": list(PIPELINE_SEQUENCE) + ["fuzzy_matching", "validation"],
            "labels": PIPELINE_LABELS,
            "conditions": (conditions.get("pipeline") or {}).get("stages") or {},
            "allergies": (allergies.get("pipeline") or {}).get("stages") or {},
        },
    }
