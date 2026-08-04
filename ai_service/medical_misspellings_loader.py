"""Normalize medical misspellings / abbreviations before phrase matching."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path

_NLP = Path(__file__).resolve().parent.parent / "data" / "nlp"
_FILES = [
    _NLP / "medical_misspellings.csv",
    _NLP / "misspellings.csv",
    _NLP / "medical_abbreviations.csv",
]


@lru_cache(maxsize=1)
def misspelling_map() -> dict[str, str]:
    mapping: dict[str, str] = {}
    for path in _FILES:
        if not path.is_file():
            continue
        with path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                if path.name == "medical_abbreviations.csv":
                    correct = (row.get("expansion") or "").strip().lower()
                    wrong = (row.get("abbreviation") or "").strip().lower()
                else:
                    correct = (row.get("correct_term") or "").strip().lower()
                    wrong = (row.get("misspelling") or "").strip().lower()
                if wrong and re.search(r"\d{3,}$", wrong):
                    continue
                if correct and wrong and wrong not in mapping:
                    mapping[wrong] = correct
    try:
        from phrase_combinatorial_engine import misspelling_map as engine_map

        for wrong, correct in engine_map().items():
            if wrong not in mapping:
                mapping[wrong] = correct
    except ImportError:
        pass
    return mapping


def apply_misspelling_corrections(text: str) -> str:
    if not text:
        return ""
    working = text.lower()
    for wrong, correct in sorted(misspelling_map().items(), key=lambda x: -len(x[0])):
        if wrong == correct or len(wrong) < 2:
            continue
        working = re.sub(r"(?<!\w)" + re.escape(wrong) + r"(?!\w)", correct, working)
    return re.sub(r"\s+", " ", working).strip()


def clear_cache() -> None:
    misspelling_map.cache_clear()
