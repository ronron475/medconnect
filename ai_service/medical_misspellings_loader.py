"""Normalize Hiligaynon misspellings before phrase matching."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path

_DATA = Path(__file__).resolve().parent.parent / "data" / "nlp" / "medical_misspellings.csv"


@lru_cache(maxsize=1)
def misspelling_map() -> dict[str, str]:
    mapping: dict[str, str] = {}
    if _DATA.is_file():
        with _DATA.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                correct = (row.get("correct_term") or "").strip().lower()
                wrong = (row.get("misspelling") or "").strip().lower()
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
        if wrong == correct or len(wrong) < 3:
            continue
        working = re.sub(r"(?<!\w)" + re.escape(wrong) + r"(?!\w)", correct, working)
    return re.sub(r"\s+", " ", working).strip()


def clear_cache() -> None:
    misspelling_map.cache_clear()
