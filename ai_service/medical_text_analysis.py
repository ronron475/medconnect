"""NLP translation and medical term recognition for Hiligaynon/Ilonggo/English text."""

from __future__ import annotations

import re
from typing import Any

from dictionary_loader import lookup, stats, translate_local, translate_text
from medical_dataset_validator import validate_text_analysis
from medical_fuzzy_matcher import match_text_queue
from medical_term_filter import is_medical_term
from medical_translator import translate_term
from preprocess import preprocess_medical_text


def _split_phrases(english: str) -> list[str]:
    return [part.strip() for part in re.split(r"\s*,\s*", english) if part.strip()]


def _append_queue_item(queue: list[dict[str, Any]], item: dict[str, Any], seen: set[str]) -> None:
    match_term = (item.get("match_term") or item.get("english_term") or "").strip()
    local_term = (item.get("local_term") or "").strip()
    if not match_term:
        return
    if not is_medical_term(local_term) and not is_medical_term(match_term):
        return
    key = match_term.lower()
    if key in seen:
        return
    seen.add(key)
    queue.append(
        {
            "local_term": item.get("local_term") or "",
            "english_term": item.get("english_term") or match_term,
            "match_term": match_term,
            "category": item.get("category") or "",
            "status": item.get("status") or "",
            "input_language": item.get("input_language") or "unknown",
            "was_translated": bool(item.get("was_translated")),
        }
    )


def translate_text_block(preprocessing: dict[str, Any]) -> dict[str, Any]:
    keywords = preprocessing.get("keywords") or []
    normalized = preprocessing.get("normalized") or ""
    cleaned = preprocessing.get("cleaned") or ""
    english_preview = preprocessing.get("english_preview") or ""
    original = preprocessing.get("original") or normalized

    phrase_input = normalized or cleaned or original
    try:
        from hiligaynon_phrase_translator import is_hiligaynon_input, translate_full_phrase
        from medical_concept_extractor import extract_from_translation
        from medical_translator import translate_english_concept

        if phrase_input and is_hiligaynon_input(phrase_input):
            phrase_translation = translate_full_phrase(phrase_input)
            items: list[dict[str, Any]] = []
            validation_queue: list[dict[str, Any]] = []
            seen: set[str] = set()
            matched = 0
            unmatched = 0

            if phrase_translation:
                for concept in extract_from_translation(phrase_translation):
                    item = translate_english_concept(
                        concept["english"],
                        phrase_input,
                        concept.get("category") or "auto",
                        str(phrase_translation.get("source") or "phrase_translation"),
                    )
                    item["medical_keyword"] = concept.get("medical_keyword") or concept["english"]
                    item["body_part"] = concept.get("body_part") or ""
                    item["input_language"] = "hiligaynon"
                    items.append(item)
                    if item.get("status") == "matched":
                        matched += 1
                    else:
                        unmatched += 1
                    _append_queue_item(validation_queue, item, seen)

            if not validation_queue and english_preview:
                for phrase in _split_phrases(english_preview):
                    item = translate_english_concept(phrase, phrase_input, "auto", "english_preview")
                    items.append(item)
                    _append_queue_item(validation_queue, item, seen)

            english_text = (phrase_translation or {}).get("english") or english_preview or translate_text(phrase_input)
            total = len(validation_queue)
            return {
                "status": "complete" if unmatched == 0 and total else ("partial" if matched else "unmatched"),
                "status_label": "Phrase-first Hiligaynon translation",
                "english_text": english_text,
                "english_preview": english_preview or english_text,
                "phrase_translation": phrase_translation,
                "matched_count": matched,
                "unmatched_count": unmatched,
                "total_count": total,
                "items": items,
                "validation_queue": validation_queue,
                "translate_first": True,
                "pipeline": "phrase_first",
            }
    except ImportError:
        pass

    items = []
    validation_queue: list[dict[str, Any]] = []
    seen: set[str] = set()
    matched = 0
    unmatched = 0

    for keyword in keywords:
        item = translate_term(keyword, "auto")
        items.append(item)
        if item.get("status") == "matched":
            matched += 1
        else:
            unmatched += 1
        _append_queue_item(validation_queue, item, seen)

    if not items and (normalized or cleaned):
        full_item = translate_term(translate_text(normalized) or normalized or cleaned, "auto")
        items.append(full_item)
        if full_item.get("status") == "matched":
            matched += 1
        else:
            unmatched += 1
        _append_queue_item(validation_queue, full_item, seen)

    if not validation_queue and english_preview:
        for phrase in _split_phrases(english_preview):
            item = translate_term(phrase, "auto")
            items.append(item)
            _append_queue_item(validation_queue, item, seen)

    english_text = translate_text(normalized) or english_preview or cleaned
    total = len(validation_queue)
    if total == 0:
        status = "empty"
        label = "No keywords to translate"
    elif unmatched == 0:
        status = "complete"
        label = f"All terms translated to English ({matched}/{max(1, len(keywords))})"
    elif matched == 0:
        status = "unmatched"
        label = "Could not map all terms to English via dictionary"
    else:
        status = "partial"
        label = f"Partial translation to English ({matched}/{max(1, len(keywords))})"

    return {
        "status": status,
        "status_label": label,
        "english_text": english_text,
        "english_preview": english_preview or english_text,
        "matched_count": matched,
        "unmatched_count": unmatched,
        "total_count": total,
        "items": items,
        "validation_queue": validation_queue,
        "translate_first": True,
    }


def _term_type_label(category: str) -> str:
    key = (category or "").lower()
    if key in ("allergy", "allergies"):
        return "allergy"
    if key in ("symptom", "symptoms"):
        return "symptom"
    return "condition"


def build_term_results(
    translation: dict[str, Any],
    fuzzy_matching: dict[str, Any],
    dataset_validation: dict[str, Any],
) -> list[dict[str, Any]]:
    fuzzy_by_en: dict[str, dict[str, Any]] = {}
    for row in fuzzy_matching.get("results") or []:
        key = (row.get("match_term") or row.get("english_term") or "").lower()
        if key:
            fuzzy_by_en[key] = row

    dataset_by_en: dict[str, dict[str, Any]] = {}
    for field in ("conditions", "allergies", "symptoms"):
        for row in (dataset_validation.get(field) or {}).get("results") or []:
            key = (row.get("english_term") or "").lower()
            if key:
                dataset_by_en[key] = row

    terms: list[dict[str, Any]] = []
    for item in translation.get("validation_queue") or []:
        english = item.get("english_term") or ""
        key = english.lower()
        fuzzy = fuzzy_by_en.get(key)
        dataset = dataset_by_en.get(key)
        dataset_valid = (dataset or {}).get("final_status") == "valid"
        fuzzy_accepted = (fuzzy or {}).get("validation_status") == "accepted"
        display_valid = dataset_valid and fuzzy_accepted
        term_type = _term_type_label(str((dataset or {}).get("category") or (fuzzy or {}).get("category") or ""))

        terms.append(
            {
                "term_type": term_type,
                "field": term_type,
                "original_local": item.get("local_term") or "",
                "input_language": item.get("input_language") or "unknown",
                "was_translated": bool(item.get("was_translated")),
                "english_term": english,
                "standardized_term": (
                    (dataset or {}).get("record", {}).get("name")
                    or (fuzzy or {}).get("standardized_term")
                    if display_valid
                    else None
                ),
                "dataset_record_id": (dataset or {}).get("record", {}).get("record_id") if dataset_valid else None,
                "dataset_table": (dataset or {}).get("dataset_table") if display_valid else None,
                "dataset_source": (dataset or {}).get("dataset_source") if display_valid else None,
                "matched_record": (dataset or {}).get("record") if display_valid else None,
                "fuzzy_score": int((fuzzy or {}).get("similarity_score") or 0),
                "translation_status": item.get("status") or "",
                "match_language": "english",
                "dataset_valid": dataset_valid,
                "display_status": "valid" if display_valid else "invalid",
                "validation_status": "valid" if display_valid else "invalid",
                "highlight": display_valid,
                "user_message": (
                    f"Found in official {term_type} dataset."
                    if display_valid
                    else f'"{english}" is not listed in the official {term_type} dataset.'
                ),
            }
        )
    return terms


def build_highlight(translated_english: str, term_results: list[dict[str, Any]]) -> dict[str, Any]:
    if not translated_english:
        return {"html": "", "segments": []}

    valid_terms: list[dict[str, Any]] = []
    for term in term_results:
        if not term.get("highlight") or not term.get("standardized_term"):
            continue
        valid_terms.append(
            {
                "phrase": str(term["standardized_term"]),
                "term_type": term.get("term_type") or "",
                "record_id": term.get("dataset_record_id"),
            }
        )
        if term.get("english_term") and term.get("english_term") != term.get("standardized_term"):
            valid_terms.append(
                {
                    "phrase": str(term["english_term"]),
                    "term_type": term.get("term_type") or "",
                    "record_id": term.get("dataset_record_id"),
                }
            )

    valid_terms.sort(key=lambda item: len(item["phrase"]), reverse=True)
    markers: list[dict[str, Any]] = []
    for term in valid_terms:
        phrase = term["phrase"]
        if not phrase:
            continue
        pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", re.I)
        for match in pattern.finditer(translated_english):
            markers.append(
                {
                    "start": match.start(),
                    "end": match.end(),
                    "phrase": match.group(0),
                    "term_type": term["term_type"],
                    "record_id": term["record_id"],
                }
            )

    if not markers:
        return {"html": translated_english, "segments": [{"text": translated_english, "valid": False}]}

    markers.sort(key=lambda item: item["start"])
    merged: list[dict[str, Any]] = []
    for marker in markers:
        if merged and marker["start"] < merged[-1]["end"]:
            continue
        merged.append(marker)

    segments: list[dict[str, Any]] = []
    html_parts: list[str] = []
    cursor = 0
    for marker in merged:
        if marker["start"] > cursor:
            plain = translated_english[cursor : marker["start"]]
            segments.append({"text": plain, "valid": False})
            html_parts.append(plain)
        highlight_text = translated_english[marker["start"] : marker["end"]]
        segments.append(
            {
                "text": highlight_text,
                "valid": True,
                "term_type": marker["term_type"],
                "record_id": marker["record_id"],
            }
        )
        html_parts.append(
            f'<mark class="nlp-valid-term" data-term-type="{marker["term_type"]}" '
            f'data-record-id="{marker.get("record_id") or ""}">{highlight_text}</mark>'
        )
        cursor = marker["end"]

    if cursor < len(translated_english):
        plain = translated_english[cursor:]
        segments.append({"text": plain, "valid": False})
        html_parts.append(plain)

    return {"html": "".join(html_parts), "segments": segments}


def detect_language(preprocessing: dict[str, Any], translation: dict[str, Any]) -> str:
    has_translation = any(item.get("was_translated") for item in translation.get("items") or [])
    original = preprocessing.get("original") or ""
    has_non_ascii = bool(re.search(r"[^\x00-\x7F]", original))
    if has_translation and has_non_ascii:
        return "hiligaynon_mixed"
    if has_translation:
        return "hiligaynon"
    if has_non_ascii:
        return "ilonggo"
    return "english"


def build_summary(term_results: list[dict[str, Any]], valid_count: int, invalid_count: int, total_count: int) -> str:
    if total_count == 0:
        return "No medical terms were extracted from your input."

    parts: list[str] = []
    for term in term_results:
        if not term.get("highlight"):
            continue
        label = (term.get("term_type") or "term").title()
        inp = term.get("original_local") or term.get("english_term") or ""
        std = term.get("standardized_term") or ""
        parts.append(f"{label}: {inp} → {std} (verified)")

    if not parts:
        return (
            f"Extracted {total_count} term(s); none matched the official "
            "Medical Conditions, Allergies, or Symptoms datasets."
        )

    summary = ". ".join(parts) + "."
    if invalid_count > 0:
        summary += f" {invalid_count} term(s) were not found in official datasets and are not highlighted."
    return summary


def analyze_medical_text(text: str, model_status: dict[str, str] | None = None) -> dict[str, Any]:
    try:
        from hiligaynon_medical_nlp_pipeline import analyze as pipeline_analyze
        result = pipeline_analyze(text)
        if model_status:
            result["model_status"] = model_status
        return result
    except ImportError:
        pass

    text = text.strip()
    if not text:
        return {
            "original_input": "",
            "summary": "Enter Hiligaynon, Ilonggo, or English text to analyze.",
            "validation_status": "empty",
        }

    preprocessing = preprocess_medical_text(text, "medical_text")
    translation = translate_text_block(preprocessing)
    fuzzy_matching = match_text_queue(translation.get("validation_queue") or [])
    dataset_validation = validate_text_analysis(fuzzy_matching)
    term_results = build_term_results(translation, fuzzy_matching, dataset_validation)
    translated_english = translation.get("english_text") or ""
    highlight = build_highlight(translated_english, term_results)

    valid_count = int(dataset_validation.get("valid_count") or 0)
    invalid_count = int(dataset_validation.get("invalid_count") or 0)
    total_count = int(dataset_validation.get("total_count") or 0)

    detected_keywords = [
        {
            "local_term": item.get("local_term") or "",
            "english_term": item.get("english_term") or "",
            "dictionary_category": item.get("category") or "",
            "was_translated": bool(item.get("was_translated")),
            "input_language": item.get("input_language") or "unknown",
            "translation_status": item.get("status") or "",
        }
        for item in translation.get("items") or []
    ]

    result: dict[str, Any] = {
        "workflow": {
            "version": "1.0",
            "steps": [
                "normalize",
                "translate_to_english",
                "extract_medical_terms",
                "fuzzy_match_datasets",
                "dataset_validate",
                "highlight_valid_terms",
            ],
            "policy": (
                "Hiligaynon/Ilonggo terms are translated via medical_dictionary.csv before matching. "
                "Only conditions, allergies, and symptoms found in official datasets are highlighted as valid."
            ),
        },
        "original_input": text,
        "normalized_input": preprocessing.get("normalized") or "",
        "detected_language": detect_language(preprocessing, translation),
        "preprocessing": preprocessing,
        "translation": translation,
        "translated_english": translated_english,
        "highlighted_english": highlight["html"],
        "highlight_segments": highlight["segments"],
        "detected_keywords": detected_keywords,
        "fuzzy_matching": fuzzy_matching,
        "dataset_validation": dataset_validation,
        "matched_records": dataset_validation.get("matched_records") or [],
        "term_results": term_results,
        "valid_count": valid_count,
        "invalid_count": invalid_count,
        "total_count": total_count,
        "validation_status": (
            "empty"
            if total_count == 0
            else "complete"
            if invalid_count == 0 and valid_count > 0
            else "partial"
            if valid_count > 0
            else "none"
        ),
        "validation_status_label": dataset_validation.get("overall_status_label") or "",
        "summary": build_summary(term_results, valid_count, invalid_count, total_count),
        "engine": "python-medical-text-nlp",
        "dictionary": stats(),
    }
    if model_status is not None:
        result["model_status"] = model_status
    return result
