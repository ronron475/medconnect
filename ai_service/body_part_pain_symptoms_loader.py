"""Load body-part pain phrase → official symptom mappings."""

from __future__ import annotations

import csv
from functools import lru_cache
from pathlib import Path

_DATA_PATH = Path(__file__).resolve().parent.parent / "data" / "nlp" / "body_part_pain_symptoms.csv"


def _normalize_key(text: str) -> str:
    import re

    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


@lru_cache(maxsize=1)
def _alias_index() -> dict[str, dict[str, str]]:
    index: dict[str, dict[str, str]] = {}
    if not _DATA_PATH.is_file():
        return index

    with _DATA_PATH.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            alias = (row.get("english_alias") or "").strip().lower()
            if not alias:
                continue
            index[alias] = {
                "canonical_english": (row.get("canonical_english") or "").strip(),
                "official_symptom": (row.get("official_symptom") or "").strip(),
                "body_part": (row.get("body_part") or "").strip(),
            }
    return index


def lookup(english: str) -> dict[str, str] | None:
    key = _normalize_key(english)
    if not key:
        return None
    return _alias_index().get(key)


def canonical_english(english: str) -> str:
    entry = lookup(english)
    if entry and entry.get("canonical_english"):
        return entry["canonical_english"]
    return english.strip()


def official_symptom_name(english: str) -> str | None:
    entry = lookup(english)
    if entry and entry.get("official_symptom"):
        return entry["official_symptom"]
    return None
