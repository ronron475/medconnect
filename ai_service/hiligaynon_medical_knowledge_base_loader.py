"""Load hiligaynon_medical_knowledge_base.csv — master Hiligaynon medical NLP KB."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_KB_CSV = _DATA_DIR / "hiligaynon_medical_knowledge_base.csv"


def kb_path() -> Path:
    return _KB_CSV


def clear_cache() -> None:
    load_rows.cache_clear()
    statement_index.cache_clear()
    statements_by_length.cache_clear()


def normalize_statement(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


@lru_cache(maxsize=1)
def load_rows() -> tuple[dict[str, str], ...]:
    if not _KB_CSV.is_file():
        return ()
    rows: list[dict[str, str]] = []
    with _KB_CSV.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for raw in reader:
            stmt = (raw.get("patient_statement") or "").strip()
            if not stmt:
                continue
            rows.append(
                {
                    "id": (raw.get("id") or "").strip(),
                    "patient_statement": stmt,
                    "normalized_symptom": (raw.get("normalized_symptom") or "").strip(),
                    "english_translation": (raw.get("english_translation") or "").strip(),
                    "medical_term": (raw.get("medical_term") or "").strip(),
                    "icd_category": (raw.get("icd_category") or "").strip(),
                    "body_system": (raw.get("body_system") or "general").strip(),
                    "urgency_level": (raw.get("urgency_level") or "Low").strip(),
                    "possible_conditions": (raw.get("possible_conditions") or "").strip(),
                    "alternative_spellings": (raw.get("alternative_spellings") or "").strip(),
                    "related_symptoms": (raw.get("related_symptoms") or "").strip(),
                    "confidence_keywords": (raw.get("confidence_keywords") or "").strip(),
                }
            )
    return tuple(rows)


@lru_cache(maxsize=1)
def statement_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for row in load_rows():
        meta = {
            "id": row["id"],
            "english": row["english_translation"],
            "normalized_symptom": row["normalized_symptom"],
            "medical_term": row["medical_term"],
            "category": row["body_system"],
            "body_system": row["body_system"],
            "icd_category": row["icd_category"],
            "urgency_level": row["urgency_level"],
            "possible_conditions": row["possible_conditions"],
            "related_symptoms": row["related_symptoms"],
            "confidence_keywords": row["confidence_keywords"],
            "canonical_statement": row["patient_statement"],
        }
        variants = [row["patient_statement"], row["normalized_symptom"]]
        if row["alternative_spellings"]:
            variants.extend(v.strip() for v in row["alternative_spellings"].split(";") if v.strip())
        for variant in variants:
            key = normalize_statement(variant)
            if key and key not in index:
                index[key] = {**meta, "matched_term": variant}
    return index


@lru_cache(maxsize=1)
def statements_by_length() -> list[str]:
    return sorted(statement_index().keys(), key=len, reverse=True)


def lookup(statement: str) -> dict[str, Any] | None:
    return statement_index().get(normalize_statement(statement))


def translate_statement(text: str) -> str:
    entry = lookup(text)
    return (entry or {}).get("english") or ""


def translate_text(text: str) -> str:
    from nlp_text_scan import translate_with_index

    return translate_with_index(
        text,
        statement_index(),
        normalize_statement,
        lambda value: translate_statement(value) or "",
    )


def kb_stats() -> dict[str, Any]:
    rows = load_rows()
    systems = sorted({r["body_system"] for r in rows if r["body_system"]})
    return {
        "path": str(_KB_CSV),
        "row_count": len(rows),
        "variant_count": len(statement_index()),
        "body_systems": systems,
    }
