"""Load anatomy-only body_parts.csv — terms must not be validated as symptoms."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA = Path(__file__).resolve().parent.parent / "data" / "nlp" / "body_parts.csv"


def normalize(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


@lru_cache(maxsize=1)
def load_rows() -> tuple[dict[str, str], ...]:
    if not _DATA.is_file():
        return ()
    rows: list[dict[str, str]] = []
    with _DATA.open(encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        for raw in reader:
            hil = (raw.get("hiligaynon_term") or "").strip()
            if not hil:
                continue
            rows.append(
                {
                    "hiligaynon_term": hil,
                    "english_term": (raw.get("english_term") or "").strip(),
                    "body_system": (raw.get("body_system") or raw.get("anatomy_category") or "general").strip(),
                    "anatomy_category": (raw.get("anatomy_category") or raw.get("body_system") or "general").strip(),
                    "status": (raw.get("status") or "active").strip(),
                }
            )
    return tuple(rows)


@lru_cache(maxsize=1)
def term_set() -> frozenset[str]:
    terms: set[str] = set()
    for row in load_rows():
        terms.add(normalize(row["hiligaynon_term"]))
        base = row["hiligaynon_term"].split()[0]
        terms.add(normalize(base))
    return frozenset(terms)


@lru_cache(maxsize=1)
def english_term_set() -> frozenset[str]:
    terms: set[str] = set()
    for row in load_rows():
        english = normalize(row["english_term"])
        if english:
            terms.add(english)
    return frozenset(terms)


@lru_cache(maxsize=1)
def term_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for row in load_rows():
        meta = {
            "english": row["english_term"],
            "body_system": row["body_system"],
            "anatomy_category": row["anatomy_category"],
            "type": "body_part",
        }
        for variant in {row["hiligaynon_term"], row["hiligaynon_term"].split()[0]}:
            key = normalize(variant)
            if key and key not in index:
                index[key] = {**meta, "matched_term": variant}
    return index


def is_body_part(term: str) -> bool:
    key = normalize(term)
    if not key:
        return False
    if key in term_set():
        return True
    if key in english_term_set():
        return True
    return any(key == normalize(row["hiligaynon_term"]) for row in load_rows())


def is_english_body_part(term: str) -> bool:
    key = normalize(term)
    return bool(key) and key in english_term_set()


def lookup(term: str) -> dict[str, Any] | None:
    return term_index().get(normalize(term))


def clear_cache() -> None:
    load_rows.cache_clear()
    term_set.cache_clear()
    english_term_set.cache_clear()
    term_index.cache_clear()
