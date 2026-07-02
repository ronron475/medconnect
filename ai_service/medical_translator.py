"""Translate preprocessed local terms to English using medical_dictionary.csv."""

from __future__ import annotations

from typing import Any

from dictionary_loader import (
    dictionary_row_index,
    load_dictionary_rows,
    local_to_english_map,
    _dictionary_phrase_candidates,
)
from medical_term_filter import is_medical_term


def _lookup(local: str) -> dict[str, str] | None:
    return dictionary_row_index().get(local.strip().lower())


def _translate_text_phrase(text: str) -> str:
    l2e = local_to_english_map()
    working = text.lower()
    import re

    for term in _dictionary_phrase_candidates(working, l2e):
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        working = pattern.sub(l2e[term], working)
    return working.strip()


def translate_english_concept(
    english: str,
    local_source: str,
    expected_category: str,
    note: str = "english_concept",
) -> dict[str, Any]:
    """Map an English medical concept for dataset validation (never raw Hiligaynon)."""
    try:
        from body_part_pain_symptoms_loader import canonical_english
        english = canonical_english(english.strip())
    except ImportError:
        english = english.strip()

    if not english:
        return {
            "local_term": local_source,
            "english_term": local_source,
            "match_term": local_source,
            "category": expected_category,
            "status": "unmatched",
            "ready_for_validation": True,
            "translation_note": "unmapped",
            "input_language": "hiligaynon",
            "was_translated": False,
        }

    entry = _lookup(english)
    was_translated = english.lower() != local_source.lower()
    category = (entry or {}).get("category") or expected_category
    return {
        "local_term": local_source,
        "english_term": english,
        "match_term": english,
        "category": category,
        "dictionary_id": (entry or {}).get("dictionary_id"),
        "status": "matched" if entry or was_translated else "unmatched",
        "category_match": expected_category == "auto" or category == expected_category,
        "ready_for_validation": True,
        "translation_note": note,
        "input_language": "hiligaynon" if was_translated else "english",
        "was_translated": was_translated,
    }


def translate_term(local_term: str, expected_category: str) -> dict[str, Any]:
    local_term = local_term.strip()

    try:
        from hiligaynon_pain_recognition_loader import lookup as pain_lookup

        pain_entry = pain_lookup(local_term)
        if pain_entry and pain_entry.get("english"):
            english = str(pain_entry["english"])
            return {
                "local_term": local_term,
                "english_term": english,
                "match_term": english,
                "category": pain_entry.get("pain_category") or expected_category,
                "dictionary_id": pain_entry.get("id"),
                "status": "matched",
                "category_match": expected_category == "auto" or (pain_entry.get("pain_category") or "") == expected_category,
                "ready_for_validation": True,
                "translation_note": "hiligaynon_pain_recognition",
                "input_language": "hiligaynon",
                "was_translated": True,
            }
    except ImportError:
        pass

    try:
        from hiligaynon_nlp_dataset_loader import lookup as nlp_lookup
        from body_part_pain_symptoms_loader import canonical_english as normalize_pain_english

        nlp_entry = nlp_lookup(local_term)
        if nlp_entry and nlp_entry.get("english"):
            english = normalize_pain_english(str(nlp_entry["english"]))
            return {
                "local_term": local_term,
                "english_term": english,
                "match_term": english,
                "category": nlp_entry.get("category") or expected_category,
                "dictionary_id": nlp_entry.get("id"),
                "status": "matched",
                "category_match": expected_category == "auto" or (nlp_entry.get("category") or "") == expected_category,
                "ready_for_validation": True,
                "translation_note": "hiligaynon_nlp_dataset",
                "input_language": "hiligaynon",
                "was_translated": True,
            }
    except ImportError:
        pass

    try:
        from hiligaynon_medical_knowledge_base_loader import lookup as kb_lookup

        kb_entry = kb_lookup(local_term)
        if kb_entry and kb_entry.get("english"):
            english = str(kb_entry["english"])
            return {
                "local_term": local_term,
                "english_term": english,
                "match_term": english,
                "category": kb_entry.get("body_system") or expected_category,
                "dictionary_id": kb_entry.get("id"),
                "status": "matched",
                "category_match": expected_category == "auto" or (kb_entry.get("body_system") or "") == expected_category,
                "ready_for_validation": True,
                "translation_note": "hiligaynon_medical_knowledge_base",
                "input_language": "hiligaynon",
                "was_translated": True,
            }
    except ImportError:
        pass

    try:
        from hiligaynon_patient_complaints_loader import lookup as complaint_lookup

        complaint_entry = complaint_lookup(local_term)
        if complaint_entry and complaint_entry.get("english"):
            english = str(complaint_entry["english"])
            return {
                "local_term": local_term,
                "english_term": english,
                "match_term": english,
                "category": complaint_entry.get("body_system") or expected_category,
                "dictionary_id": complaint_entry.get("id"),
                "status": "matched",
                "category_match": expected_category == "auto" or (complaint_entry.get("body_system") or "") == expected_category,
                "ready_for_validation": True,
                "translation_note": "hiligaynon_patient_complaints",
                "input_language": "hiligaynon",
                "was_translated": True,
            }
    except ImportError:
        pass

    entry = _lookup(local_term)

    if entry is not None:
        english = entry["english_term"]
        was_translated = english.lower() != local_term.lower()
        return {
            "local_term": local_term,
            "english_term": english,
            "match_term": english,
            "category": entry["category"],
            "dictionary_id": entry.get("dictionary_id"),
            "status": "matched",
            "category_match": expected_category == "auto" or entry["category"] == expected_category,
            "ready_for_validation": True,
            "translation_note": "dictionary" if was_translated else "english_input",
            "input_language": "hiligaynon" if was_translated else "english",
            "was_translated": was_translated,
        }

    phrase_en = _translate_text_phrase(local_term)
    if phrase_en and phrase_en.lower() != local_term.lower():
        return {
            "local_term": local_term,
            "english_term": phrase_en,
            "match_term": phrase_en,
            "category": expected_category,
            "dictionary_id": None,
            "status": "matched",
            "category_match": False,
            "ready_for_validation": True,
            "translation_note": "phrase_dictionary",
            "input_language": "hiligaynon",
            "was_translated": True,
        }

    symptom_item = _lookup_symptom_lexicon(local_term, expected_category)
    if symptom_item is not None:
        return symptom_item

    return {
        "local_term": local_term,
        "english_term": local_term,
        "match_term": local_term,
        "category": expected_category,
        "dictionary_id": None,
        "status": "unmatched",
        "category_match": False,
        "ready_for_validation": True,
        "translation_note": "english_input" if local_term.isascii() else "unmapped",
        "input_language": "english" if local_term.isascii() else "hiligaynon",
        "was_translated": False,
    }


def _overall_status(matched: int, unmatched: int, total: int) -> str:
    if total == 0:
        return "empty"
    if unmatched == 0:
        return "complete"
    if matched == 0:
        return "unmatched"
    return "partial"


def _status_label(status: str, matched: int, total: int) -> str:
    labels = {
        "complete": f"All terms translated ({matched}/{total})",
        "partial": f"Partial translation ({matched}/{total} matched)",
        "unmatched": "No dictionary matches — terms kept as-is for fuzzy validation",
        "empty": "No keywords to translate",
    }
    return labels.get(status, status)


def _lookup_symptom_lexicon(local_term: str, expected_category: str) -> dict[str, Any] | None:
    items = _translate_from_symptom_matcher(local_term, expected_category)
    return items[0] if items else None


def _translate_from_symptom_matcher(text: str, expected_category: str) -> list[dict[str, Any]]:
    try:
        from hiligaynon_symptom_matcher import recognize_symptoms
    except ImportError:
        return []

    result = recognize_symptoms(text)
    detections = result.get("detections") or []
    if not detections:
        return []

    detections = sorted(
        detections,
        key=lambda row: (
            -len(str(row.get("detected_symptom") or "")),
            -int(row.get("confidence") or 0),
        ),
    )
    best = detections[0]
    english = str(best.get("english_translation") or "").strip()
    if not english:
        return []

    return [
        {
            "local_term": str(best.get("detected_symptom") or text),
            "english_term": english,
            "match_term": english,
            "category": str(best.get("category") or "symptom"),
            "dictionary_id": None,
            "status": "matched",
            "category_match": expected_category == "auto" or str(best.get("category") or "") == expected_category,
            "ready_for_validation": True,
            "translation_note": "hiligaynon_symptom_lexicon",
            "input_language": "hiligaynon",
            "was_translated": True,
        }
    ]


def translate_field(preprocessed: dict[str, Any], expected_category: str) -> dict[str, Any]:
    keywords = preprocessed.get("keywords") or []
    cleaned = (preprocessed.get("cleaned") or "").strip()
    normalized = (preprocessed.get("normalized") or cleaned).strip()
    field = preprocessed.get("field") or "conditions"
    english_preview = (preprocessed.get("english_preview") or "").strip()

    items: list[dict[str, Any]] = []
    queue: list[dict[str, Any]] = []
    matched = 0
    unmatched = 0
    seen_english: set[str] = set()

    def append_queue(item: dict[str, Any]) -> None:
        if not item.get("ready_for_validation"):
            return
        match_term = (item.get("match_term") or item.get("english_term") or "").strip()
        local_term = (item.get("local_term") or "").strip()
        if not match_term:
            return
        if not is_medical_term(local_term) and not is_medical_term(match_term):
            return
        key = match_term.lower()
        if key in seen_english:
            return
        seen_english.add(key)
        queue.append(
            {
                "local_term": item["local_term"],
                "english_term": item["english_term"],
                "match_term": match_term,
                "category": item["category"],
                "status": item["status"],
                "input_language": item.get("input_language"),
                "was_translated": item.get("was_translated"),
            }
        )

    for kw in keywords:
        item = translate_term(str(kw), expected_category)
        items.append(item)
        if item["status"] == "matched":
            matched += 1
        else:
            unmatched += 1
        append_queue(item)

    try:
        from preprocess import extract_token_dictionary_keywords, remove_fillers, normalize_text

        cleaned_tokens = extract_token_dictionary_keywords(remove_fillers(normalize_text(cleaned or normalized)))
        for token in cleaned_tokens:
            if any(str(kw).lower() == token.lower() for kw in keywords):
                continue
            item = translate_term(token, expected_category)
            items.append(item)
            if item["status"] == "matched":
                matched += 1
            else:
                unmatched += 1
            append_queue(item)
    except ImportError:
        pass

    if not items and (normalized or cleaned):
        phrase_text = cleaned or normalized
        symptom_items = _translate_from_symptom_matcher(phrase_text, expected_category)
        if symptom_items:
            for item in symptom_items:
                items.append(item)
                if item["status"] == "matched":
                    matched += 1
                else:
                    unmatched += 1
                append_queue(item)
        else:
            full_item = translate_term(phrase_text, expected_category)
            items.append(full_item)
            if full_item["status"] == "matched":
                matched += 1
            else:
                unmatched += 1
            append_queue(full_item)

    if not queue and english_preview and not items:
        for phrase in [p.strip() for p in english_preview.split(",") if p.strip()]:
            item = translate_term(phrase, expected_category)
            items.append(item)
            append_queue(item)

    english_parts = []
    seen: set[str] = set()
    for q in queue:
        en = (q.get("match_term") or q.get("english_term") or "").strip()
        if en and en.lower() not in seen:
            seen.add(en.lower())
            english_parts.append(en)
    english_text = ", ".join(english_parts)

    if not english_text and cleaned:
        english_text = _translate_text_phrase(cleaned)

    total = len(queue) if queue else len(keywords)
    status = _overall_status(matched, unmatched, max(total, 1))

    return {
        "field": field,
        "expected_category": expected_category,
        "status": status,
        "status_label": _status_label(status, matched, max(total, 1)),
        "english_text": english_text,
        "english_preview": english_preview or english_text,
        "matched_count": matched,
        "unmatched_count": unmatched,
        "total_count": total,
        "items": items,
        "validation_queue": queue,
    }


def translate_profile(preprocessing: dict[str, Any]) -> dict[str, Any]:
    allergies = translate_field(preprocessing.get("allergies") or {}, "allergy")
    conditions = translate_field(preprocessing.get("conditions") or {}, "condition")

    combined_parts = [p for p in (conditions.get("english_text"), allergies.get("english_text")) if p]
    combined_english = " | ".join(combined_parts)

    total_m = allergies["matched_count"] + conditions["matched_count"]
    total_u = allergies["unmatched_count"] + conditions["unmatched_count"]
    total = allergies["total_count"] + conditions["total_count"]
    overall = _overall_status(total_m, total_u, total)

    return {
        "allergies": allergies,
        "conditions": conditions,
        "combined_english": combined_english,
        "overall_status": overall,
        "overall_status_label": _status_label(overall, total_m, total),
    }
