"""Phrase-first Hiligaynon → English translation."""

from __future__ import annotations

import re
from functools import lru_cache
from typing import Any

from hiligaynon_language_detector import detect as detect_lang
from hiligaynon_text_normalizer import normalize, phrase_variants
from medical_misspellings_loader import apply_misspelling_corrections

_SWELLING_BODY_PARTS: dict[str, dict[str, str]] = {
    "unto": {"english": "swollen gums", "medical_keyword": "swollen gums", "category": "symptom", "body_part": "gums"},
    "unud": {"english": "swollen gums", "medical_keyword": "swollen gums", "category": "symptom", "body_part": "gums"},
    "ngipon": {"english": "swollen gums", "medical_keyword": "swollen gums", "category": "symptom", "body_part": "gums"},
    "tiyan": {"english": "abdominal swelling", "medical_keyword": "abdominal swelling", "category": "symptom", "body_part": "abdomen"},
    "mata": {"english": "swollen eyes", "medical_keyword": "swollen eyes", "category": "symptom", "body_part": "eyes"},
    "tiil": {"english": "swollen foot", "medical_keyword": "foot swelling", "category": "symptom", "body_part": "foot"},
    "lawas": {"english": "body swelling", "medical_keyword": "body swelling", "category": "symptom", "body_part": "body"},
}


def detect_language(text: str) -> str:
    return detect_lang(text).get("primary", "unknown")


def is_hiligaynon_input(text: str) -> bool:
    return bool(detect_lang(text).get("is_local"))


def _format_result(english: str, medical_keyword: str, category: str, body_part: str, source: str) -> dict[str, Any]:
    return {
        "english": english.strip(),
        "medical_keyword": (medical_keyword or english).strip(),
        "category": category or "symptom",
        "body_part": body_part,
        "source": source,
        "input_language": "hiligaynon",
    }


def _translate_contextual(normalized: str) -> dict[str, Any] | None:
    rules = [
        (r"\b(?:may\s+)?nanah\b.*\bpilas\b|\bpilas\b.*\bnanah\b", "infected wound", "infected wound", "symptom", "skin"),
        (r"\bwala\b.*\bkusog\b", "weakness", "weakness", "symptom", "body"),
        (r"\bubo\b.*\bsipon\b|\bsipon\b.*\bubo\b", "cough and runny nose", "common cold", "symptom", "respiratory"),
        (r"\bsuka\b.*\bkalibanga\b|\bkalibanga\b.*\bsuka\b", "vomiting and diarrhea", "gastroenteritis", "symptom", "abdomen"),
        (r"\b(?:ga\s+)?pito\b.*\bdughan\b", "chest pain", "chest pain", "pain", "chest"),
        (r"\bkapoy\b.*\blawas\b", "fatigue", "fatigue", "symptom", "body"),
        (r"\bindi ko kaginhawa\b|\bindi ko makaginhawa\b", "cannot breathe", "respiratory distress", "emergency", "respiratory"),
        (r"\bbudlay\b.*\bginhawa\b", "difficulty breathing", "dyspnea", "symptom", "respiratory"),
        (r"\bmay\s+nana\s+sa\s+bilat\b", "vaginal infection", "vaginal infection", "condition", "vagina"),
        (r"\bmay\s+nana\s+sa\s+ari\b", "penile infection", "penile infection", "condition", "penis"),
        (r"\bnagadugo\s+bilat\b|\bgadugo\s+bilat\b", "vaginal bleeding", "vaginal bleeding", "symptom", "vagina"),
        (r"\bgadugo\s+ari\b|\bnagadugo\s+ari\b", "penile bleeding", "penile bleeding", "symptom", "penis"),
        (r"\b(?:ga\s?hubag|gahabok|gahubag)\s+(?:akon\s+)?itlog\b", "testicular swelling", "testicular swelling", "symptom", "testicle"),
        (r"\b(?:masakit|gasakit)\s+(?:akon\s+)?(?:ari|itlog|bilat)\b", "genital pain", "genital pain", "symptom", "genital"),
        (r"\b(?:kakatol|gakatol)\s+bilat\b", "vaginal itching", "vaginal itching", "symptom", "vagina"),
    ]
    for pattern, en, kw, cat, part in rules:
        if re.search(pattern, normalized):
            return _format_result(en, kw, cat, part, "contextual_pattern")

    for part, meta in _SWELLING_BODY_PARTS.items():
        if re.search(rf"(?:ga\s+)?hubag(?:-hubag)?\s+(?:ang|sang)\s+{re.escape(part)}\b", normalized):
            return _format_result(meta["english"], meta["medical_keyword"], meta["category"], meta["body_part"], "contextual_swelling")
        if re.search(rf"\b(?:ga\s+)?hubag\b.*\b{re.escape(part)}\b", normalized) and "lawas" not in normalized:
            return _format_result(meta["english"], meta["medical_keyword"], meta["category"], meta["body_part"], "contextual_swelling_loose")

    if re.search(r"\bhubag\s+lawas\b", normalized):
        return _format_result("hives", "hives", "symptom", "body", "contextual_hives")
    return None


@lru_cache(maxsize=1)
def _dictionary_lookup() -> dict[str, str]:
    from dictionary_loader import local_to_english_map
    return {k.lower(): v for k, v in local_to_english_map().items()}


def translate_full_phrase(text: str) -> dict[str, Any] | None:
    corrected = apply_misspelling_corrections(text)
    for variant in phrase_variants(corrected) + phrase_variants(text):
        normalized = normalize(variant) if variant else normalize(corrected)

        try:
            from medical_entity_extractor import extract_primary_entity

            entity = extract_primary_entity(variant or corrected)
            if entity and entity.get("english_term"):
                en = str(entity["english_term"])
                return _format_result(
                    en,
                    entity.get("condition") or entity.get("symptom") or en,
                    "condition" if entity.get("type") == "condition" else "symptom",
                    str(entity.get("body_part") or ""),
                    str(entity.get("source") or "phrase_entity"),
                )
        except ImportError:
            pass

        try:
            from symptom_phrases_loader import lookup_phrase

            phrase = lookup_phrase(normalized)
            if phrase:
                en = phrase["english_term"]
                return _format_result(
                    en,
                    phrase.get("condition") or phrase.get("symptom") or en,
                    "condition" if phrase.get("condition") else "symptom",
                    str(phrase.get("body_part") or ""),
                    "symptom_phrases",
                )
        except ImportError:
            pass

        exact = _dictionary_lookup().get(normalized)
        if exact:
            return _format_result(exact, exact, "symptom", "", "exact_dictionary")

        contextual = _translate_contextual(normalized)
        if contextual:
            return contextual

        try:
            from hiligaynon_pain_recognition_loader import lookup as pain_lookup
            entry = pain_lookup(normalized)
            if entry and entry.get("english"):
                en = str(entry["english"])
                return _format_result(en, en, str(entry.get("pain_category") or "symptom"), str(entry.get("body_part") or ""), "exact_phrase_pain")
        except ImportError:
            pass

        from dictionary_loader import translate_text
        english = translate_text(normalized)
        if english and english.lower() != normalized:
            return _format_result(english, english, "symptom", "", "phrase_dictionary")

    return None
