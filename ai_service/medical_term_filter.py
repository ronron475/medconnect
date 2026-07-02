"""Filter non-medical tokens before translation and validation."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

from dictionary_loader import load_dictionary_rows, local_terms_by_length, local_to_english_map

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"

STOP_WORDS = frozenset({
    "may", "ako", "ko", "ikaw", "siya", "kami", "tayo", "nila", "sila",
    "kag", "ka", "og", "ug", "ang", "nga", "sa", "si", "ni", "kay",
    "na", "pa", "ba", "ho", "po", "din", "rin", "lang", "gid", "man",
    "gyud", "gud", " gihapon", "wala", "walay", "dili", "hindi",
    "oo", "yes", "no", "none", "walang", "mayroon", "meron",
    "sing", "sang", "yung", "yun", "yan", "ito", "iya", "iyan", "iyon",
    "mga", "ng", "muna", "naman", "talaga", "pala", "raw", "daw", "kuno",
    "lamang", "palang", "gyapon", "mo", "nimo", "namon", "nato", "ila",
    "tungod", "bang", "pero", "sakit", "gamot",
    "the", "a", "an", "and", "or", "to", "of", "in", "on", "at", "for",
    "with", "have", "has", "had", "am", "is", "are", "was", "were", "be",
    "been", "being", "my", "me", "i", "we", "you", "he", "she", "they", "it",
    "this", "that", "these", "those", "very", "so", "just", "also", "as",
    "by", "from", "into", "about", "but", "not", "do", "does", "did",
    "can", "could", "would", "should", "will", "shall", "might", "must",
    "than", "then", "there", "here", "when", "where", "why", "how",
    "all", "each", "every", "both", "few", "more", "most", "other", "some",
    "such", "only", "own", "same", "too", "up", "out", "off", "over",
    "existing", "known", "allergy", "allergies", "condition", "conditions",
    "medical", "history", "patient", "symptom", "symptoms", "problem", "problems",
    "medication", "medicine", "unknown", "n/a", "nil", "null",
    "none known", "no known", "wala sang",
})


def normalize_key(term: str) -> str:
    return re.sub(r"\s+", " ", (term or "").lower().strip())


def is_stop_word(term: str) -> bool:
    key = normalize_key(term)
    return not key or key in STOP_WORDS


@lru_cache(maxsize=1)
def _lexicon() -> frozenset[str]:
    terms: set[str] = set()

    def add(value: str) -> None:
        key = normalize_key(value)
        if key and key not in STOP_WORDS:
            terms.add(key)

    for row in load_dictionary_rows():
        add(row["local_term"])
        add(row["english_term"])

    csv_specs = [
        (_DATA_DIR / "allergies.csv", ("allergy_name", "search_name")),
        (_DATA_DIR / "medical_conditions.csv", ("condition_name",)),
        (_DATA_DIR / "symptoms.csv", ("symptom_name",)),
    ]
    for path, columns in csv_specs:
        if not path.is_file():
            continue
        with path.open(encoding="utf-8", newline="") as handle:
            reader = csv.DictReader(handle)
            for row in reader:
                for column in columns:
                    add(row.get(column) or "")

    try:
        from symptom_lexicon_loader import variant_index

        for variant in variant_index().keys():
            add(variant)
    except ImportError:
        pass

    try:
        from hiligaynon_nlp_dataset_loader import term_index as nlp_term_index

        for variant, meta in nlp_term_index().items():
            add(variant)
            add(str(meta.get("english") or ""))
    except ImportError:
        pass

    try:
        from hiligaynon_pain_recognition_loader import complaint_index as pain_index

        for variant, meta in pain_index().items():
            add(variant)
            add(str(meta.get("english") or ""))
    except ImportError:
        pass

    try:
        from hiligaynon_medical_knowledge_base_loader import statement_index as kb_index

        for variant, meta in kb_index().items():
            add(variant)
            add(str(meta.get("english") or ""))
    except ImportError:
        pass

    try:
        from hiligaynon_patient_complaints_loader import complaint_index as patient_index

        for variant, meta in patient_index().items():
            add(variant)
            add(str(meta.get("english") or ""))
    except ImportError:
        pass

    return frozenset(terms)


def _translate_text_phrase(text: str) -> str:
    l2e = local_to_english_map()
    working = text.lower()
    for term in sorted(l2e.keys(), key=len, reverse=True):
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        working = pattern.sub(l2e[term], working)
    return working.strip()


def is_medical_term(term: str) -> bool:
    if is_stop_word(term):
        return False
    key = normalize_key(term)
    if not key:
        return False

    try:
        from body_parts_loader import is_body_part

        if is_body_part(key):
            return False
    except ImportError:
        pass

    lexicon = _lexicon()
    if key in lexicon:
        return True

    l2e = local_to_english_map()
    if key in l2e:
        return True

    translated = _translate_text_phrase(term)
    translated_key = normalize_key(translated)
    if translated_key and translated_key != key and translated_key in lexicon:
        return True

    return False


def extract_dictionary_phrases(text: str) -> list[str]:
    if not text:
        return []
    occupied = [False] * len(text)
    candidates: list[tuple[int, int, str]] = []
    for term in local_terms_by_length():
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        for match in pattern.finditer(text):
            candidates.append((match.start(), match.end(), term))
    candidates.sort(key=lambda item: (-(item[1] - item[0]), item[0]))

    matched: list[str] = []
    for start, end, term in candidates:
        if any(occupied[start:end]):
            continue
        for index in range(start, end):
            occupied[index] = True
        matched.append(term)
    return list(dict.fromkeys(matched))


def _prune_subsumed(terms: list[str]) -> list[str]:
    if len(terms) <= 1:
        return terms
    terms = sorted(terms, key=len, reverse=True)
    kept: list[str] = []
    for term in terms:
        term_lower = term.lower()
        if any(re.search(r"(?<!\w)" + re.escape(term_lower) + r"(?!\w)", existing.lower()) for existing in kept):
            continue
        kept.append(term)
    return kept


def strip_stop_padding(term: str) -> str:
    words = (term or "").split()
    while words and is_stop_word(words[0]):
        words.pop(0)
    while words and is_stop_word(words[-1]):
        words.pop()
    return " ".join(words)


def normalize_accepted_term(term: str) -> str:
    stripped = strip_stop_padding(term)
    if not stripped:
        return ""
    if is_medical_term(stripped):
        return stripped
    return term.strip() if is_medical_term(term) else ""


def filter_keywords(keywords: list[str], normalized: str = "") -> dict[str, Any]:
    seen: set[str] = set()
    candidates: list[str] = []
    for term in list(keywords) + extract_dictionary_phrases(normalized):
        key = normalize_key(term)
        if key and key not in seen:
            seen.add(key)
            candidates.append(term.strip())

    accepted: list[str] = []
    discarded: list[str] = []
    for term in candidates:
        normalized_term = normalize_accepted_term(term)
        if not normalized_term:
            discarded.append(term)
            continue
        if is_medical_term(normalized_term):
            accepted.append(normalized_term)
            if normalized_term != term:
                discarded.append(term)
        else:
            discarded.append(term)
    accepted = _prune_subsumed(accepted)

    return {
        "accepted": accepted,
        "discarded": discarded,
        "accepted_count": len(accepted),
        "discarded_count": len(discarded),
    }


def apply_to_field(field: dict[str, Any]) -> dict[str, Any]:
    normalized = field.get("normalized") or ""
    filtered = filter_keywords(field.get("keywords") or [], normalized)
    field = dict(field)
    field["keywords"] = filtered["accepted"]
    field["keywords_text"] = " ".join(filtered["accepted"])
    field["medical_term_filter"] = {
        "accepted": filtered["accepted"],
        "discarded": filtered["discarded"],
        "accepted_count": filtered["accepted_count"],
        "discarded_count": filtered["discarded_count"],
        "lexicon_sources": ["dictionary", "conditions", "allergies", "symptoms"],
        "policy": "Only recognized medical dictionary and dataset terms proceed to translation and validation.",
    }
    return field


def filter_validation_queue(queue: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    accepted: list[dict[str, Any]] = []
    discarded: list[dict[str, Any]] = []
    for item in queue:
        local = item.get("local_term") or ""
        english = item.get("match_term") or item.get("english_term") or ""
        if is_medical_term(str(local)) or is_medical_term(str(english)):
            accepted.append(item)
        else:
            discarded.append(item)
    return {"accepted": accepted, "discarded": discarded}
