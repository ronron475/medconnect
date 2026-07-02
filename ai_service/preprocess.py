"""NLP preprocessing: normalize local patient text before translation and validation."""

from __future__ import annotations

import re
from typing import Any

from dictionary_loader import load_dictionary_rows, local_terms_by_length, local_to_english_map, dictionary_row_index, _dictionary_phrase_candidates
from medical_term_filter import apply_to_field, is_medical_term, is_stop_word

# Common Hiligaynon / Ilonggo / Tagalog filler words (whole-word removal)
FILLER_WORDS = frozenset({
    "may", "ako", "ko", "ikaw", "siya", "kami", "tayo", "nila", "sila",
    "kag", "ka", "og", "ug", "ang", "nga", "sa", "si", "ni", "kay",
    "na", "pa", "ba", "ho", "po", "din", "rin", "lang", "gid", "man",
    "gyud", "gud", " gihapon", " gihapon", "wala", "walay", "dili", "hindi",
    "oo", "yes", "no", "none", "wala", "walang", "mayroon", "meron",
    "sing", "sang", "ang", "yung", "yun", "yan", "ito", "iyan", "iyon",
    "mga", "ng", "muna", "na", "pa", "naman", "talaga", "pala", "raw",
    "daw", "kuno", "lang", "lamang", "palang", "na", "pa", "rin", "din",
    "the", "a", "an", "and", "or", "to", "of", "in", "on", "at", "for",
    "with", "have", "has", "had", "am", "is", "are", "was", "were", "be",
    "been", "being", "my", "me", "i", "we", "you", "he", "she", "they",
    "it", "this", "that", "these", "those", "very", "so", "just", "also",
    "pero", "pero", "kay", "tungod", "bang", "gid", "man", "gyapon",
    "ako", "ko", "mo", "nimo", "namon", "nato", "nila", "ila",
    "existing", "known", "allergy", "allergies", "condition", "conditions",
    "medical", "history", "patient", "ako", "ko",
    "akon", "kon",
})

SKIP_KEYWORDS = frozenset({
    "none", "n/a", "na", "wala", "walay", "no", "unknown", "nil", "null",
    "wala sang", "walang", "none known", "no known",
})


def collapse_repeated_characters(text: str, max_repeat: int = 2) -> str:
    """Reduce stretched typing: grabeeeeee -> grabee."""
    if not text:
        return ""
    return re.sub(r"(.)\1{2,}", lambda m: m.group(1) * max_repeat, text)


def normalize_text(text: str) -> str:
    """Lowercase, strip punctuation, collapse whitespace and repeated characters."""
    if not text or not text.strip():
        return ""
    try:
        from medical_misspellings_loader import apply_misspelling_corrections

        text = apply_misspelling_corrections(text)
    except ImportError:
        pass
    lowered = collapse_repeated_characters(text.lower().strip())
    # Keep letters, digits, spaces; turn other chars into space
    cleaned = re.sub(r"[^a-z0-9\s\-]", " ", lowered)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


def remove_fillers(text: str) -> str:
    """Remove common filler words token-by-token."""
    if not text:
        return ""
    tokens = text.split()
    kept = [t for t in tokens if t not in FILLER_WORDS and not is_stop_word(t)]
    return " ".join(kept).strip()


def _phrase_positions(text: str, phrase: str) -> list[tuple[int, int]]:
    """Find non-overlapping whole-phrase spans in text."""
    if not phrase or not text:
        return []
    pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)")
    return [(m.start(), m.end()) for m in pattern.finditer(text)]


def _lexicon_phrase_candidates(text: str, max_phrase_words: int = 8) -> list[str]:
    """Lexicon phrases present in text — avoids scanning 50k+ variants per request."""
    try:
        from symptom_lexicon_loader import variant_index
    except Exception:
        return []

    vidx = variant_index()
    if not vidx or not text:
        return []

    words = text.split()
    if not words:
        return []

    found: set[str] = set()
    word_count = len(words)
    for i in range(word_count):
        for j in range(i + 1, min(word_count, i + max_phrase_words) + 1):
            phrase = " ".join(words[i:j])
            if phrase in vidx:
                found.add(phrase)

    return sorted(found, key=len, reverse=True)


def extract_keywords(text: str, category: str | None = None) -> list[str]:
    """
    Extract medical keyword phrases from cleaned text.
    Longest dictionary + lexicon match first, then leftover meaningful tokens.
    """
    if not text:
        return []

    working = text
    max_len = len(working)
    terms = [t for t in _dictionary_phrase_candidates(working) if len(t) <= max_len]
    for variant in _lexicon_phrase_candidates(working):
        if variant not in terms:
            terms.append(variant)
    terms.sort(key=len, reverse=True)
    occupied = [False] * len(working)

    # Collect non-overlapping dictionary matches (longest phrases win)
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

    ordered: list[str] = []
    seen: set[str] = set()

    def add_unique(item: str) -> None:
        key = item.lower().strip()
        if not key or key in seen or key in SKIP_KEYWORDS:
            return
        seen.add(key)
        ordered.append(item)

    for _, _, term in matched:
        add_unique(term)

    for m in re.finditer(r"[a-z0-9]+", working):
        if any(occupied[m.start() : m.end()]):
            continue
        token = m.group(0)
        if token in FILLER_WORDS or is_stop_word(token):
            continue
        if len(token) < 2 and token not in {"tb", "dm"}:
            continue
        if not is_medical_term(token):
            continue
        add_unique(token)

    return ordered


def extract_token_dictionary_keywords(text: str) -> list[str]:
    """Token-level lookup against dictionary and Hiligaynon NLP dataset."""
    if not text:
        return []
    keywords: list[str] = []
    seen: set[str] = set()
    for token in text.split():
        token = token.strip()
        if not token or token in FILLER_WORDS or is_stop_word(token):
            continue
        key = token.lower()
        if key in seen:
            continue
        found = False
        try:
            from dictionary_loader import lookup as dict_lookup
            if dict_lookup(token):
                found = True
        except ImportError:
            pass
        if not found:
            try:
                from hiligaynon_nlp_dataset_loader import lookup as nlp_lookup
                if nlp_lookup(token):
                    found = True
            except ImportError:
                pass
        if not found and is_medical_term(token):
            found = True
        if found:
            seen.add(key)
            keywords.append(token)
    return keywords


def preprocess_medical_text(
    text: str,
    field: str = "conditions",
) -> dict[str, Any]:
    """
    Full preprocessing pipeline for one input field.
    field: 'conditions' | 'allergies'
    """
    original = text or ""
    normalized = normalize_text(original)
    cleaned = remove_fillers(normalized)
    cat = "allergy" if field == "allergies" else "condition"
    phrase_kw = extract_keywords(normalized, category=cat)
    token_kw = extract_keywords(cleaned, category=cat)
    dict_token_kw = extract_token_dictionary_keywords(normalized) + extract_token_dictionary_keywords(cleaned)
    seen: set[str] = set()
    keywords: list[str] = []
    for kw in phrase_kw + token_kw + dict_token_kw:
        key = kw.lower()
        if key and key not in seen:
            seen.add(key)
            keywords.append(kw)

    l2e = local_to_english_map()
    english_preview = normalized

    def _apply_preview(candidate: str) -> None:
        nonlocal english_preview
        if candidate and candidate.lower() != normalized.lower():
            english_preview = candidate

    try:
        from hiligaynon_pain_recognition_loader import translate_text as pain_translate_text

        _apply_preview(pain_translate_text(normalized))
    except ImportError:
        pass

    if english_preview == normalized:
        try:
            from hiligaynon_medical_knowledge_base_loader import translate_text as kb_translate_text

            _apply_preview(kb_translate_text(normalized))
        except ImportError:
            pass

    if english_preview == normalized:
        try:
            from hiligaynon_patient_complaints_loader import translate_text as patient_translate_text

            _apply_preview(patient_translate_text(normalized))
        except ImportError:
            pass

    if english_preview == normalized:
        try:
            from hiligaynon_nlp_dataset_loader import translate_text as nlp_translate_text

            _apply_preview(nlp_translate_text(normalized))
        except ImportError:
            pass

    for term in _dictionary_phrase_candidates(normalized, l2e):
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        english_preview = pattern.sub(l2e[term], english_preview)

    if not keywords and cleaned:
        for part in re.split(r"\s*(?:,|;| and | og | kag )\s*", english_preview or cleaned):
            part = part.strip()
            if part and part.lower() not in SKIP_KEYWORDS and is_medical_term(part):
                keywords.append(part)

    return apply_to_field(
        {
            "original": original,
            "normalized": normalized,
            "cleaned": cleaned,
            "english_preview": english_preview.strip(),
            "keywords": keywords,
            "keywords_text": " ".join(keywords),
            "field": field,
        }
    )


def translate_keywords(keywords: list[str]) -> list[dict[str, str]]:
    """Map extracted local keywords to English using the dictionary."""
    l2e = local_to_english_map()
    out: list[dict[str, str]] = []
    for kw in keywords:
        english = l2e.get(kw.lower(), kw)
        out.append({"local": kw, "english": english})
    return out


def preprocess_profile(
    allergies: str,
    conditions: str,
) -> dict[str, Any]:
    """Preprocess both registration step-3 fields."""
    allergy_block = preprocess_medical_text(allergies, "allergies")
    condition_block = preprocess_medical_text(conditions, "conditions")

    return {
        "allergies": allergy_block,
        "conditions": condition_block,
        "dictionary": __import__("dictionary_loader").dictionary_stats(),
    }
