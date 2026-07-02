"""Load hiligaynon_medical_nlp_dataset.csv — 10,000+ Hiligaynon medical NLP rows."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_DATASET_CSV = _DATA_DIR / "hiligaynon_medical_nlp_dataset.csv"
_SUPPLEMENTARY_CSVS = (
    _DATA_DIR / "symptom_phrases.csv",
    _DATA_DIR / "hiligaynon_symptoms.csv",
    _DATA_DIR / "hiligaynon_wv_expansion.csv",
    _DATA_DIR / "hiligaynon_reproductive_expansion.csv",
)


def dataset_path() -> Path:
    return _DATASET_CSV


def clear_cache() -> None:
    load_rows.cache_clear()
    term_index.cache_clear()
    terms_by_length.cache_clear()


def normalize_term(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def _map_wv_row(raw: dict[str, str], seq: int) -> dict[str, str] | None:
    term = (raw.get("hiligaynon_term") or "").strip()
    if not term:
        return None
    english = (raw.get("english_term") or raw.get("english_translation") or "").strip()
    kw = english.lower().replace(" ", ";")
    return {
        "id": str(seq),
        "hiligaynon_term": term,
        "alternative_spellings": (raw.get("alternative_spellings") or "").strip(),
        "english_translation": english,
        "medical_term": english,
        "medical_category": (raw.get("medical_category") or raw.get("category") or "General").strip(),
        "body_system": (raw.get("body_system") or "general").strip(),
        "severity": (raw.get("severity") or raw.get("severity_level") or "Low").strip(),
        "symptom_keywords": (raw.get("symptom_keywords") or kw).strip(),
        "confidence_keywords": (raw.get("confidence_keywords") or kw).strip(),
    }


def _load_csv_rows(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    out: list[dict[str, str]] = []
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for raw in reader:
            if "english_term" in (reader.fieldnames or []):
                mapped = _map_wv_row(raw, len(out) + 1)
                if mapped:
                    out.append(mapped)
                continue
            term = (raw.get("hiligaynon_term") or "").strip()
            if not term:
                continue
            out.append(
                {
                    "id": (raw.get("id") or "").strip(),
                    "hiligaynon_term": term,
                    "alternative_spellings": (raw.get("alternative_spellings") or "").strip(),
                    "english_translation": (raw.get("english_translation") or "").strip(),
                    "medical_term": (raw.get("medical_term") or "").strip(),
                    "medical_category": (raw.get("medical_category") or raw.get("category") or "").strip(),
                    "body_system": (raw.get("body_system") or "general").strip(),
                    "severity": (raw.get("severity") or raw.get("severity_level") or "Low").strip(),
                    "symptom_keywords": (raw.get("symptom_keywords") or "").strip(),
                    "confidence_keywords": (raw.get("confidence_keywords") or "").strip(),
                }
            )
    return out


@lru_cache(maxsize=1)
def load_rows() -> tuple[dict[str, str], ...]:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for path in (_DATASET_CSV, *_SUPPLEMENTARY_CSVS):
        for row in _load_csv_rows(path):
            key = normalize_term(row["hiligaynon_term"])
            if not key or key in seen:
                continue
            seen.add(key)
            rows.append(row)
    return tuple(rows)


@lru_cache(maxsize=1)
def term_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for row in load_rows():
        meta = {
            "id": row["id"],
            "english": row["english_translation"],
            "medical_term": row["medical_term"],
            "category": (row["medical_category"] or "general").lower(),
            "body_system": row["body_system"],
            "severity": row["severity"],
            "symptom_keywords": row["symptom_keywords"],
            "confidence_keywords": row["confidence_keywords"],
            "canonical_variant": row["hiligaynon_term"],
        }
        variants = [row["hiligaynon_term"]]
        if row["alternative_spellings"]:
            variants.extend(v.strip() for v in row["alternative_spellings"].split(";") if v.strip())
        for variant in variants:
            key = normalize_term(variant)
            if key and key not in index:
                index[key] = {**meta, "matched_term": variant}
    return index


@lru_cache(maxsize=1)
def terms_by_length() -> list[str]:
    return sorted(term_index().keys(), key=len, reverse=True)


def lookup(term: str) -> dict[str, Any] | None:
    return term_index().get(normalize_term(term))


def translate_term(text: str) -> str:
    entry = lookup(text)
    return (entry or {}).get("english") or ""


def translate_text(text: str) -> str:
    from nlp_text_scan import translate_with_index

    return translate_with_index(
        text,
        term_index(),
        normalize_term,
        lambda value: translate_term(value) or "",
    )


def dataset_stats() -> dict[str, Any]:
    rows = load_rows()
    categories = sorted({r["medical_category"] for r in rows if r["medical_category"]})
    return {
        "path": str(_DATASET_CSV),
        "row_count": len(rows),
        "variant_count": len(term_index()),
        "categories": categories,
    }
