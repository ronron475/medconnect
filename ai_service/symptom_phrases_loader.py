"""Phrase-level symptom index from symptom_phrases.csv and WV expansions."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_NLP = Path(__file__).resolve().parent.parent / "data" / "nlp"
_SOURCES = (
    _NLP / "symptom_phrases.csv",
    _NLP / "hiligaynon_wv_expansion.csv",
    _NLP / "hiligaynon_reproductive_expansion.csv",
    _NLP / "hiligaynon_combinatorial_phrases.csv",
    _NLP / "hiligaynon_conditions_combinatorial.csv",
    _NLP / "step6_triage_exemplars.csv",
    _NLP / "symptom_phrases_seed.csv",
)


def normalize(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


@lru_cache(maxsize=1)
def phrase_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for path in _SOURCES:
        if not path.is_file():
            continue
        with path.open(encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            for raw in reader:
                hil = (raw.get("hiligaynon_term") or "").strip()
                eng = (raw.get("english_term") or raw.get("english_translation") or "").strip()
                if not hil or not eng:
                    continue
                key = normalize(hil)
                if key in index:
                    continue
                cat = (raw.get("medical_category") or "general").strip()
                sev = (raw.get("severity") or "Low").strip()
                tri = (raw.get("triage_level") or "routine").strip()
                index[key] = {
                    "hiligaynon_term": hil,
                    "english_term": eng,
                    "medical_category": cat,
                    "severity": sev,
                    "triage_level": tri,
                    "body_part": _infer_body_part(hil, eng),
                    "symptom": _infer_symptom(eng),
                    "condition": eng if cat in {"infection", "injury", "trauma", "gynecologic_symptom"} else "",
                    "source": path.name,
                }
    return index


@lru_cache(maxsize=1)
def phrases_by_length() -> list[str]:
    return sorted(phrase_index().keys(), key=len, reverse=True)


def _infer_body_part(hil: str, eng: str) -> str:
    mapping = {
        "itlog": "testicle", "itlug": "testicle", "bilat": "vagina", "bilad": "vagina",
        "ari": "penis", "bayag": "scrotum", "kipay": "vulva", "singit": "groin",
        "mata": "eye", "kamot": "hand", "tiil": "foot", "ulo": "head",
    }
    low = hil.lower()
    for hil_part, eng_part in mapping.items():
        if re.search(rf"\b{re.escape(hil_part)}\b", low):
            return eng_part
    eng_low = eng.lower()
    for part in ("vagina", "penis", "testicle", "scrotum", "vulva", "groin", "eye", "hand", "foot"):
        if part in eng_low:
            return part
    return ""


def _infer_symptom(eng: str) -> str:
    low = eng.lower()
    for token in ("infection", "bleeding", "swelling", "pain", "itching", "lump", "wound", "redness"):
        if token in low:
            return token
    return "symptom"


def lookup_phrase(text: str) -> dict[str, Any] | None:
    return phrase_index().get(normalize(text))


def scan_phrases(text: str) -> list[dict[str, Any]]:
    """Longest non-overlapping phrase matches (static CSV + combinatorial engine)."""
    if not text:
        return []
    working = normalize(text)
    matches = _scan_static_phrases(working)
    try:
        from phrase_combinatorial_engine import match_phrases

        for entry in match_phrases(text):
            phrase = entry.get("matched_phrase") or ""
            if phrase and not any(m.get("matched_phrase") == phrase for m in matches):
                matches.append(entry)
    except ImportError:
        pass
    matches.sort(key=lambda m: m["span"][0])
    return matches


def _phrase_candidates_from_text(working: str, max_phrase_words: int = 8) -> list[str]:
    """Only check phrase n-grams present in text against the index (not all 28k phrases)."""
    index = phrase_index()
    words = working.split()
    if not words:
        return []
    found: set[str] = set()
    word_count = len(words)
    for i in range(word_count):
        for j in range(i + 1, min(word_count, i + max_phrase_words) + 1):
            phrase = " ".join(words[i:j])
            if phrase in index:
                found.add(phrase)
    return sorted(found, key=len, reverse=True)


def _scan_static_phrases(working: str) -> list[dict[str, Any]]:
    occupied = [False] * max(len(working), 1)
    index = phrase_index()
    matches: list[dict[str, Any]] = []

    for phrase in _phrase_candidates_from_text(working):
        pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)")
        for match in pattern.finditer(working):
            start, end = match.start(), match.end()
            if any(occupied[start:end]):
                continue
            entry = index.get(phrase)
            if not entry:
                continue
            for i in range(start, end):
                occupied[i] = True
            matches.append({**entry, "matched_phrase": phrase, "span": [start, end]})
    matches.sort(key=lambda m: m["span"][0])
    return matches


def clear_cache() -> None:
    phrase_index.cache_clear()
    phrases_by_length.cache_clear()
