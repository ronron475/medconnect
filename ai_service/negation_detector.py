"""Negation detection — never extract negated symptoms."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_PATH = Path(__file__).resolve().parent.parent / "data" / "nlp" / "negation_words.csv"

_ALIASES = {
    "fever": ["fever", "lagnat", "hilanat"],
    "cough": ["cough", "ubo"],
    "chest pain": ["chest pain", "dughan", "dibdib"],
    "difficulty breathing": ["difficulty breathing", "shortness of breath", "ginhawa", "dyspnea"],
    "vomiting": ["vomiting", "suka"],
    "dizziness": ["dizziness", "dizzy", "lipong"],
}

_BUILTIN = [
    ("no fever", "fever"),
    ("no cough", "cough"),
    ("no chest pain", "chest pain"),
    ("no vomiting", "vomiting"),
    ("not dizzy", "dizziness"),
    ("wala akong lagnat", "fever"),
    ("wala akong ubo", "cough"),
    ("wala ko ginaubo", "cough"),
    ("wala ko lagnat", "fever"),
    ("indi budlay ginhawa", "difficulty breathing"),
    ("indi masakit dughan", "chest pain"),
    ("indi gasuka", "vomiting"),
    ("hindi ako nilalagnat", "fever"),
    ("walang sakit sa dibdib", "chest pain"),
    ("no shortness of breath", "difficulty breathing"),
]


@lru_cache(maxsize=1)
def load_patterns() -> tuple[tuple[str, str], ...]:
    rows: list[tuple[str, str]] = []
    if _PATH.is_file():
        with _PATH.open(encoding="utf-8", newline="") as handle:
            for row in csv.DictReader(handle):
                pattern = re.sub(r"\s*#\d+\s*$", "", (row.get("pattern") or "").strip().lower())
                concept = (row.get("negated_concept") or "").strip().lower()
                if pattern and concept and "case" not in pattern:
                    rows.append((pattern, concept))
    rows.extend(_BUILTIN)
    rows.sort(key=lambda x: len(x[0]), reverse=True)
    return tuple(dict.fromkeys(rows))


def detect_negated_concepts(text: str) -> list[str]:
    hay = (text or "").lower().strip()
    if not hay:
        return []
    negated: list[str] = []
    for pattern, concept in load_patterns():
        if pattern and pattern in hay:
            negated.append(concept)
    for m in re.finditer(
        r"\b(?:no|not|without|denies|wala(?:\s+ako(?:ng)?)?|wala\s+ko|indi|hindi(?:\s+ako)?)\s+([a-z\-\s]{3,40})",
        hay,
    ):
        negated.append(m.group(1).strip())
    return list(dict.fromkeys(n for n in negated if n))


def _is_negated(name: str, matched: str, sid: str, negated: list[str]) -> bool:
    name = (name or "").lower()
    matched = (matched or "").lower()
    sid = (sid or "").lower().replace("_", " ")
    for neg in negated:
        if not neg:
            continue
        if (name and (neg in name or name in neg)) or (matched and (neg in matched or matched in neg)) or (
            sid and (neg in sid or sid in neg)
        ):
            return True
        for concept, words in _ALIASES.items():
            if neg == concept or neg in words:
                for w in words:
                    if w in name or w in matched or w.replace(" ", "_") in sid.replace(" ", "_"):
                        return True
    return False


def filter_symptoms(symptoms: list[dict[str, Any]], original: str, english: str = "") -> list[dict[str, Any]]:
    negated = detect_negated_concepts(f"{original} {english}")
    if not negated or not symptoms:
        return symptoms
    kept: list[dict[str, Any]] = []
    for sym in symptoms:
        if _is_negated(
            str(sym.get("symptom_name") or sym.get("english_term") or ""),
            str(sym.get("matched_term") or ""),
            str(sym.get("id") or ""),
            negated,
        ):
            continue
        kept.append(sym)
    return kept


def filter_red_flags(flags: list[dict[str, Any]], original: str, english: str = "") -> list[dict[str, Any]]:
    hay = f"{original} {english}".lower().strip()
    if not hay or not flags:
        return flags
    kept: list[dict[str, Any]] = []
    for flag in flags:
        pat = (
            str(flag.get("matched_pattern") or flag.get("english_pattern") or flag.get("flag_name") or "")
            .lower()
            .strip()
        )
        negated = False
        for neg in ("no ", "not ", "wala ", "indi ", "hindi ", "without ", "denies "):
            if pat and f"{neg}{pat}" in hay:
                negated = True
                break
        if "indi budlay ginhawa" in hay and "breath" in pat:
            negated = True
        if "indi masakit dughan" in hay and "chest" in pat:
            negated = True
        if not negated:
            kept.append(flag)
    return kept


def clear_cache() -> None:
    load_patterns.cache_clear()
