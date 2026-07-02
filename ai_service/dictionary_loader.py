"""Load medical_dictionary.csv for Hiligaynon/Ilonggo → English mapping."""

from __future__ import annotations

import csv
from functools import lru_cache
from pathlib import Path

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_DICTIONARY_CSV = _DATA_DIR / "medical_dictionary.csv"


@lru_cache(maxsize=1)
def load_dictionary_rows() -> list[dict[str, str]]:
    if not _DICTIONARY_CSV.is_file():
        return []
    rows: list[dict[str, str]] = []
    with _DICTIONARY_CSV.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            local = (row.get("local_term") or "").strip()
            english = (row.get("english_term") or "").strip()
            category = (row.get("category") or "").strip().lower()
            if local and english:
                rows.append(
                    {
                        "dictionary_id": int(row.get("dictionary_id") or 0),
                        "local_term": local,
                        "english_term": english,
                        "category": category,
                    }
                )
    return rows


@lru_cache(maxsize=1)
def dictionary_row_index() -> dict[str, dict[str, str]]:
    index: dict[str, dict[str, str]] = {}
    for row in load_dictionary_rows():
        key = row["local_term"].lower()
        if key not in index:
            index[key] = row
    return index


@lru_cache(maxsize=1)
def local_to_english_map() -> dict[str, str]:
    mapping: dict[str, str] = {}
    for row in load_dictionary_rows():
        key = row["local_term"].lower()
        if key not in mapping:
            mapping[key] = row["english_term"]
    return mapping


@lru_cache(maxsize=1)
def local_terms_by_length() -> list[str]:
    terms = sorted(local_to_english_map().keys(), key=len, reverse=True)
    return terms


@lru_cache(maxsize=1)
def dictionary_stats() -> dict[str, int]:
    rows = load_dictionary_rows()
    conditions = sum(1 for r in rows if r["category"] == "condition")
    allergies = sum(1 for r in rows if r["category"] == "allergy")
    return {
        "loaded": len(rows),
        "conditions": conditions,
        "allergies": allergies,
        "path": str(_DICTIONARY_CSV),
    }


def stats() -> dict[str, int]:
    return dictionary_stats()


def lookup(local: str) -> dict[str, str] | None:
    return dictionary_row_index().get(local.strip().lower())


def translate_local(local_term: str) -> str | None:
    entry = lookup(local_term)
    return entry["english_term"] if entry else None


def translate_text(text: str) -> str:
    import re

    l2e = local_to_english_map()
    working = text.lower()
    for term in _dictionary_phrase_candidates(working, l2e):
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        working = pattern.sub(l2e[term], working)
    return working.strip()


def _dictionary_phrase_candidates(text: str, mapping: dict[str, str] | None = None, max_phrase_words: int = 8) -> list[str]:
    """Return only dictionary phrases that appear as word n-grams in text (fast path)."""
    if not text:
        return []
    l2e = mapping if mapping is not None else local_to_english_map()
    words = text.split()
    if not words:
        return []
    found: set[str] = set()
    word_count = len(words)
    for i in range(word_count):
        for j in range(i + 1, min(word_count, i + max_phrase_words) + 1):
            phrase = " ".join(words[i:j])
            if phrase in l2e:
                found.add(phrase)
    return sorted(found, key=len, reverse=True)
