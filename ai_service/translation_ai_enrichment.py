"""Merge AI medical interpretation into Step 2 translation (still requires Steps 3–6 validation)."""

from __future__ import annotations

import hashlib
import json
from typing import Any

from medical_ai_interpreter import interpret_medical_text, interpret_profile_fields_combined
from dictionary_fallback import build_dictionary_fallback_interpretation
from medical_translator import translate_english_concept, _overall_status, _status_label

_INTERPRET_CACHE: dict[str, dict[str, Any]] = {}
_INTERPRET_CACHE_MAX = 96


def _interpret_cache_key(
    field_label: str,
    original_text: str,
    dictionary_english: str,
    dictionary_terms: tuple[str, ...],
) -> str:
    payload = json.dumps(
        {
            "field": field_label,
            "original": original_text.strip(),
            "english": dictionary_english.strip(),
            "terms": dictionary_terms,
        },
        sort_keys=True,
        ensure_ascii=False,
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _cache_get(key: str) -> dict[str, Any] | None:
    hit = _INTERPRET_CACHE.get(key)
    return dict(hit) if isinstance(hit, dict) else None


def _cache_put(key: str, value: dict[str, Any]) -> None:
    if len(_INTERPRET_CACHE) >= _INTERPRET_CACHE_MAX:
        _INTERPRET_CACHE.pop(next(iter(_INTERPRET_CACHE)))
    _INTERPRET_CACHE[key] = dict(value)


def _pipeline_sufficient_for_groq_skip(
    field_block: dict[str, Any],
    pipeline_context: dict[str, Any] | None,
) -> bool:
    """Skip Groq when dictionary + Hiligaynon dataset already fully translated the field."""
    if int(field_block.get("unmatched_count") or 0) > 0:
        return False
    if int(field_block.get("matched_count") or 0) <= 0:
        return False
    english = str(field_block.get("english_text") or "").strip()
    if not english:
        return False
    ctx = pipeline_context or {}
    dataset_matches = ctx.get("dataset_matches") or []
    dict_matches = ctx.get("dictionary_matches") or []
    return bool(dataset_matches or dict_matches)


def _build_pipeline_ai_result(
    field_block: dict[str, Any],
    expected_category: str,
) -> dict[str, Any]:
    english = str(field_block.get("english_text") or "").strip()
    concepts: list[dict[str, Any]] = []
    for item in field_block.get("validation_queue") or field_block.get("items") or []:
        term = str(item.get("match_term") or item.get("english_term") or "").strip()
        if not term:
            continue
        ctype = str(item.get("category") or expected_category).lower()
        concepts.append(
            {
                "term": term,
                "type": {"symptom": "symptom", "condition": "condition", "allergy": "allergy"}.get(
                    ctype, expected_category
                ),
                "body_part": item.get("body_part"),
                "severity": item.get("severity"),
                "duration": item.get("duration"),
                "confidence": 92,
            }
        )
    score = 92 if concepts else 85
    return {
        "status": "complete",
        "provider": "pipeline",
        "model": None,
        "english_interpretation": english,
        "confidence_score": score,
        "concepts": concepts,
        "notes": "Groq skipped — Medical Dictionary and Hiligaynon dataset already produced a complete translation.",
        "groq_error": None,
        "provider_errors": {},
        "groq_skipped": True,
    }


def _field_dictionary_terms(field_block: dict[str, Any]) -> list[str]:
    terms: list[str] = []
    for item in field_block.get("items") or []:
        en = str(item.get("english_term") or "").strip()
        if en:
            terms.append(en)
    return terms


def _merge_ai_into_field(
    field_block: dict[str, Any],
    ai_result: dict[str, Any],
    expected_category: str,
    original_text: str,
) -> dict[str, Any]:
    items = list(field_block.get("items") or [])
    queue = list(field_block.get("validation_queue") or [])
    seen = {str(q.get("match_term") or q.get("english_term") or "").lower() for q in queue if q.get("match_term") or q.get("english_term")}

    matched = int(field_block.get("matched_count") or 0)
    unmatched = int(field_block.get("unmatched_count") or 0)
    ai_added = 0

    for concept in ai_result.get("concepts") or []:
        term = str(concept.get("term") or "").strip()
        if not term:
            continue
        ctype = str(concept.get("type") or expected_category).lower()
        category = {"symptom": "symptom", "condition": "condition", "allergy": "allergy"}.get(ctype, expected_category)

        if term.lower() in seen:
            continue

        item = translate_english_concept(term, original_text or term, category, "ai_interpreter")
        item["ai_confidence"] = int(concept.get("confidence") or 0)
        item["ai_body_part"] = concept.get("body_part")
        item["ai_severity"] = concept.get("severity")
        item["ai_duration"] = concept.get("duration")
        item["source"] = "ai_interpreter"
        items.append(item)

        match_term = str(item.get("match_term") or item.get("english_term") or "").strip()
        if match_term and item.get("ready_for_validation"):
            seen.add(match_term.lower())
            queue.append(
                {
                    "local_term": item.get("local_term") or original_text,
                    "english_term": item.get("english_term") or match_term,
                    "match_term": match_term,
                    "category": item.get("category") or category,
                    "status": item.get("status"),
                    "input_language": item.get("input_language"),
                    "was_translated": item.get("was_translated"),
                    "source": "ai_interpreter",
                    "ai_confidence": item.get("ai_confidence"),
                }
            )
            ai_added += 1
            if item.get("status") == "matched":
                matched += 1
            else:
                unmatched += 1

    english_text = str(ai_result.get("english_interpretation") or field_block.get("english_text") or "").strip()
    total = len(queue) if queue else int(field_block.get("total_count") or 0)
    status = _overall_status(matched, unmatched, max(total, 1))

    enriched = dict(field_block)
    enriched.update(
        {
            "items": items,
            "validation_queue": queue,
            "english_text": english_text or field_block.get("english_text", ""),
            "matched_count": matched,
            "unmatched_count": unmatched,
            "total_count": max(total, len(queue)),
            "status": status,
            "status_label": _status_label(status, matched, max(total, 1)),
            "ai_interpretation": ai_result,
            "ai_concepts_added": ai_added,
        }
    )
    return enriched


def enrich_field_with_ai(
    field_block: dict[str, Any],
    original_text: str,
    expected_category: str,
    pipeline_context: dict[str, Any] | None = None,
    ai_result: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Apply Groq context analysis to a single translation field."""
    field_label = "Known allergies" if expected_category == "allergy" else "Medical conditions & symptoms"

    usable_ai = (
        ai_result
        if isinstance(ai_result, dict) and str(ai_result.get("status") or "") == "complete"
        else None
    )
    if usable_ai is None:
        if _pipeline_sufficient_for_groq_skip(field_block, pipeline_context):
            usable_ai = _build_pipeline_ai_result(field_block, expected_category)
        else:
            dict_terms = tuple(_field_dictionary_terms(field_block))
            cache_key = _interpret_cache_key(
                field_label,
                original_text,
                str(field_block.get("english_text") or ""),
                dict_terms,
            )
            usable_ai = _cache_get(cache_key)
            if usable_ai is None:
                usable_ai = interpret_medical_text(
                    field_label,
                    original_text,
                    str(field_block.get("english_text") or ""),
                    list(dict_terms),
                    pipeline_context=pipeline_context,
                )
                if usable_ai.get("status") == "complete":
                    _cache_put(cache_key, usable_ai)
    ai_result = usable_ai

    if ai_result.get("status") != "complete" and original_text.strip():
        ctx = pipeline_context or {}
        groq_error = ai_result.get("groq_error") or ai_result.get("notes")
        fallback = build_dictionary_fallback_interpretation(
            original_text,
            ctx.get("dictionary_matches") or [],
            ctx.get("dataset_matches") or [],
            ctx.get("keywords") or [],
            groq_error=str(groq_error) if groq_error else None,
        )
        if int(fallback.get("confidence_score") or 0) > 0:
            fallback["provider_errors"] = ai_result.get("provider_errors") or {}
            ai_result = fallback

    return _merge_ai_into_field(field_block, ai_result, expected_category, original_text)


def enrich_profile_fields_with_ai(
    conditions_block: dict[str, Any],
    allergies_block: dict[str, Any],
    conditions_original: str,
    allergies_original: str,
    conditions_ctx: dict[str, Any] | None,
    allergies_ctx: dict[str, Any] | None,
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Run Groq once for both fields when possible (faster than two sequential calls)."""
    conditions_skip = _pipeline_sufficient_for_groq_skip(conditions_block, conditions_ctx)
    allergies_skip = _pipeline_sufficient_for_groq_skip(allergies_block, allergies_ctx)

    if conditions_skip:
        conditions_block = enrich_field_with_ai(
            conditions_block,
            conditions_original,
            "condition",
            conditions_ctx,
            ai_result=_build_pipeline_ai_result(conditions_block, "condition"),
        )
    if allergies_skip:
        allergies_block = enrich_field_with_ai(
            allergies_block,
            allergies_original,
            "allergy",
            allergies_ctx,
            ai_result=_build_pipeline_ai_result(allergies_block, "allergy"),
        )

    need_conditions = (
        not conditions_skip
        and conditions_original.strip()
    )
    need_allergies = (
        not allergies_skip
        and allergies_original.strip()
    )

    if need_conditions and need_allergies:
        combined = interpret_profile_fields_combined(
            conditions_original,
            str(conditions_block.get("english_text") or ""),
            _field_dictionary_terms(conditions_block),
            conditions_ctx,
            allergies_original,
            str(allergies_block.get("english_text") or ""),
            _field_dictionary_terms(allergies_block),
            allergies_ctx,
        )
        c_ai = combined.get("conditions") if combined.get("conditions", {}).get("status") == "complete" else None
        a_ai = combined.get("allergies") if combined.get("allergies", {}).get("status") == "complete" else None
        conditions_block = enrich_field_with_ai(
            conditions_block,
            conditions_original,
            "condition",
            conditions_ctx,
            ai_result=c_ai,
        )
        allergies_block = enrich_field_with_ai(
            allergies_block,
            allergies_original,
            "allergy",
            allergies_ctx,
            ai_result=a_ai,
        )
    elif need_conditions:
        conditions_block = enrich_field_with_ai(
            conditions_block, conditions_original, "condition", conditions_ctx
        )
    elif need_allergies:
        allergies_block = enrich_field_with_ai(
            allergies_block, allergies_original, "allergy", allergies_ctx
        )

    return conditions_block, allergies_block


def enrich_translation_with_ai(
    preprocessing: dict[str, Any],
    translation: dict[str, Any],
    conditions_original: str = "",
    allergies_original: str = "",
    pipeline_context: dict[str, Any] | None = None,
    single_field: str | None = None,
) -> dict[str, Any]:
    """Apply Groq contextual language understanding after dictionary translation."""
    conditions_block = dict(translation.get("conditions") or {})
    allergies_block = dict(translation.get("allergies") or {})

    prep_conditions = preprocessing.get("conditions") or {}
    prep_allergies = preprocessing.get("allergies") or {}

    conditions_original = conditions_original or str(prep_conditions.get("original") or "")
    allergies_original = allergies_original or str(prep_allergies.get("original") or "")

    conditions_ctx = pipeline_context if single_field != "allergy" else None
    allergies_ctx = pipeline_context if single_field == "allergy" else None

    ai_conditions = interpret_medical_text(
        "Medical conditions & symptoms",
        conditions_original,
        str(conditions_block.get("english_text") or ""),
        _field_dictionary_terms(conditions_block),
        pipeline_context=conditions_ctx,
    )
    ai_allergies = interpret_medical_text(
        "Known allergies",
        allergies_original,
        str(allergies_block.get("english_text") or ""),
        _field_dictionary_terms(allergies_block),
        pipeline_context=allergies_ctx,
    )

    conditions_block = _merge_ai_into_field(conditions_block, ai_conditions, "condition", conditions_original)
    allergies_block = _merge_ai_into_field(allergies_block, ai_allergies, "allergy", allergies_original)

    combined_parts = [p for p in (conditions_block.get("english_text"), allergies_block.get("english_text")) if p]
    combined_english = " | ".join(combined_parts)

    total_m = int(conditions_block.get("matched_count") or 0) + int(allergies_block.get("matched_count") or 0)
    total_u = int(conditions_block.get("unmatched_count") or 0) + int(allergies_block.get("unmatched_count") or 0)
    total = int(conditions_block.get("total_count") or 0) + int(allergies_block.get("total_count") or 0)
    overall = _overall_status(total_m, total_u, total)

    active = ai_conditions if ai_conditions.get("status") == "complete" else ai_allergies
    if ai_conditions.get("status") != "complete" and ai_allergies.get("status") == "complete":
        active = ai_allergies
    elif ai_conditions.get("status") == "complete" and ai_allergies.get("status") == "complete":
        active = ai_conditions

    scores = [s for s in [ai_conditions.get("confidence_score"), ai_allergies.get("confidence_score")] if s]
    overall_confidence = int(round(sum(scores) / len(scores))) if scores else 0

    ai_interpretation = {
        "status": active.get("status") or "unavailable",
        "provider": active.get("provider"),
        "model": active.get("model"),
        "overall_confidence": overall_confidence,
        "english_interpretation": combined_english,
        "conditions": ai_conditions,
        "allergies": ai_allergies,
        "detected_concepts": (ai_conditions.get("concepts") or []) + (ai_allergies.get("concepts") or []),
        "policy": (
            "Groq contextual analysis improves translation only. "
            "All concepts must pass fuzzy matching (Step 3) and dataset validation (Step 4)."
        ),
        "primary_provider": "groq",
        "concepts_queued_for_validation": int(conditions_block.get("ai_concepts_added") or 0)
        + int(allergies_block.get("ai_concepts_added") or 0),
    }

    enriched = dict(translation)
    enriched.update(
        {
            "conditions": conditions_block,
            "allergies": allergies_block,
            "combined_english": combined_english,
            "overall_status": overall,
            "overall_status_label": _status_label(overall, total_m, total),
            "ai_interpretation": ai_interpretation,
        }
    )
    return enriched
