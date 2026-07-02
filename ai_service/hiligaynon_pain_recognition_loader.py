"""Load hiligaynon_pain_recognition.csv — dedicated Hiligaynon pain NLP dataset."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_PAIN_CSV = _DATA_DIR / "hiligaynon_pain_recognition.csv"


def pain_csv_path() -> Path:
    return _PAIN_CSV


def clear_cache() -> None:
    load_rows.cache_clear()
    complaint_index.cache_clear()
    complaints_by_length.cache_clear()


def normalize_complaint(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


@lru_cache(maxsize=1)
def load_rows() -> tuple[dict[str, str], ...]:
    if not _PAIN_CSV.is_file():
        return ()
    rows: list[dict[str, str]] = []
    with _PAIN_CSV.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for raw in reader:
            complaint = (raw.get("hiligaynon_complaint") or "").strip()
            if not complaint:
                continue
            rows.append(
                {
                    "id": (raw.get("id") or "").strip(),
                    "hiligaynon_complaint": complaint,
                    "normalized_symptom": (raw.get("normalized_symptom") or "").strip(),
                    "english_translation": (raw.get("english_translation") or "").strip(),
                    "medical_term": (raw.get("medical_term") or "").strip(),
                    "body_part": (raw.get("body_part") or "").strip(),
                    "pain_category": (raw.get("pain_category") or "").strip(),
                    "severity_level": (raw.get("severity_level") or "medium").strip(),
                    "alternative_spellings": (raw.get("alternative_spellings") or "").strip(),
                }
            )
    return tuple(rows)


@lru_cache(maxsize=1)
def complaint_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}
    for row in load_rows():
        meta = {
            "id": row["id"],
            "english": row["english_translation"],
            "normalized_symptom": row["normalized_symptom"],
            "medical_term": row["medical_term"],
            "category": row["pain_category"],
            "body_part": row["body_part"],
            "pain_category": row["pain_category"],
            "severity_level": row["severity_level"],
            "canonical_complaint": row["hiligaynon_complaint"],
        }
        variants = [row["hiligaynon_complaint"], row["normalized_symptom"]]
        if row["alternative_spellings"]:
            variants.extend(v.strip() for v in row["alternative_spellings"].split(";") if v.strip())
        for variant in variants:
            key = normalize_complaint(variant)
            if key and key not in index:
                index[key] = {**meta, "matched_term": variant}
    return index


@lru_cache(maxsize=1)
def complaints_by_length() -> list[str]:
    return sorted(complaint_index().keys(), key=len, reverse=True)


def lookup(complaint: str) -> dict[str, Any] | None:
    return complaint_index().get(normalize_complaint(complaint))


def translate_complaint(text: str) -> str:
    entry = lookup(text)
    return (entry or {}).get("english") or ""


def translate_text(text: str) -> str:
    from nlp_text_scan import translate_with_index

    return translate_with_index(
        text,
        complaint_index(),
        normalize_complaint,
        lambda value: translate_complaint(value) or "",
    )


def pain_stats() -> dict[str, Any]:
    rows = load_rows()
    parts = sorted({r["body_part"] for r in rows if r["body_part"]})
    return {
        "path": str(_PAIN_CSV),
        "row_count": len(rows),
        "variant_count": len(complaint_index()),
        "body_parts": parts,
    }
