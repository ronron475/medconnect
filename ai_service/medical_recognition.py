"""Field-level recognition helpers for Step 3 profile validation."""

from __future__ import annotations

import html
import re
from typing import Any


def detected_keywords_from_items(items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
        {
            "local_term": item.get("local_term") or "",
            "english_term": item.get("english_term") or "",
            "dictionary_category": item.get("category") or "",
            "was_translated": bool(item.get("was_translated")),
            "input_language": item.get("input_language") or "unknown",
            "translation_status": item.get("status") or "",
        }
        for item in items
    ]


def build_highlight(translated_english: str, term_results: list[dict[str, Any]]) -> dict[str, Any]:
    if not translated_english:
        return {"html": "", "segments": []}

    valid_terms: list[dict[str, Any]] = []
    for term in term_results:
        if term.get("display_status") != "valid" and not term.get("highlight"):
            continue
        std = term.get("standardized_term")
        if not std:
            continue
        valid_terms.append(
            {"phrase": str(std), "term_type": term.get("term_type") or term.get("field") or "", "record_id": term.get("dataset_record_id")}
        )
        en = term.get("english_term") or ""
        if en and en != std:
            valid_terms.append(
                {"phrase": str(en), "term_type": term.get("term_type") or term.get("field") or "", "record_id": term.get("dataset_record_id")}
            )

    valid_terms.sort(key=lambda x: len(x["phrase"]), reverse=True)
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
        return {
            "html": html.escape(translated_english),
            "segments": [{"text": translated_english, "valid": False}],
        }

    markers.sort(key=lambda x: x["start"])
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
            html_parts.append(html.escape(plain))
        highlight_text = translated_english[marker["start"] : marker["end"]]
        segments.append(
            {"text": highlight_text, "valid": True, "term_type": marker["term_type"], "record_id": marker["record_id"]}
        )
        html_parts.append(
            f'<mark class="nlp-valid-term" data-term-type="{html.escape(marker["term_type"])}" '
            f'data-record-id="{marker.get("record_id") or ""}">{html.escape(highlight_text)}</mark>'
        )
        cursor = marker["end"]
    if cursor < len(translated_english):
        plain = translated_english[cursor:]
        segments.append({"text": plain, "valid": False})
        html_parts.append(html.escape(plain))

    return {"html": "".join(html_parts), "segments": segments}


def build_field_recognition(
    original_input: str,
    preprocessing_block: dict[str, Any],
    translation_block: dict[str, Any],
    term_results: list[dict[str, Any]],
) -> dict[str, Any]:
    translated_english = (translation_block.get("english_text") or preprocessing_block.get("english_preview") or "").strip()
    from preprocess import remove_fillers

    translated_english = remove_fillers(translated_english)
    highlight = build_highlight(translated_english, term_results)
    valid = sum(1 for t in term_results if t.get("display_status") == "valid")
    invalid = len(term_results) - valid
    return {
        "original_input": original_input,
        "normalized_input": preprocessing_block.get("normalized") or "",
        "translated_english": translated_english,
        "highlighted_english": highlight["html"],
        "highlight_segments": highlight["segments"],
        "detected_keywords": detected_keywords_from_items(translation_block.get("items") or []),
        "valid_count": valid,
        "invalid_count": invalid,
        "total_count": len(term_results),
    }


def detect_field_language(preprocessing_block: dict[str, Any], translation_block: dict[str, Any]) -> str:
    has_translation = any(item.get("was_translated") for item in translation_block.get("items") or [])
    original = preprocessing_block.get("original") or ""
    has_non_ascii = bool(re.search(r"[^\x00-\x7F]", original))
    if not has_non_ascii and not has_translation:
        return "english"
    if has_translation and has_non_ascii:
        return "hiligaynon_mixed"
    if has_translation or has_non_ascii:
        return "hiligaynon"
    return "english"


def term_type_label(field: str, category: str = "") -> str:
    cat = (category or "").lower()
    if cat in ("symptom", "symptoms"):
        return "symptom"
    if cat in ("allergy", "allergies") or field == "allergies":
        return "allergy"
    return "condition"
