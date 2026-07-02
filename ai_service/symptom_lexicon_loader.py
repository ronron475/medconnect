"""Load Hiligaynon symptom lexicon from JSON (admin-expandable, no code changes)."""

from __future__ import annotations

import json
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_LEXICON_JSON = _DATA_DIR / "hiligaynon_symptom_lexicon.json"


def lexicon_path() -> Path:
    return _LEXICON_JSON


def clear_cache() -> None:
    load_lexicon.cache_clear()
    variant_index.cache_clear()
    variants_by_length.cache_clear()


@lru_cache(maxsize=1)
def load_lexicon() -> dict[str, Any]:
    if not _LEXICON_JSON.is_file():
        return {
            "version": "0",
            "fuzzy_threshold": 85,
            "symptoms": {},
        }
    with _LEXICON_JSON.open(encoding="utf-8") as handle:
        data = json.load(handle)
    if not isinstance(data, dict):
        return {"version": "0", "fuzzy_threshold": 85, "symptoms": {}}
    data.setdefault("fuzzy_threshold", 85)
    data.setdefault("symptoms", {})
    return data


def fuzzy_threshold() -> int:
    lex = load_lexicon()
    raw = int(lex.get("fuzzy_threshold") or 85)
    lo = int(lex.get("fuzzy_threshold_min") or 80)
    hi = int(lex.get("fuzzy_threshold_max") or 90)
    return max(lo, min(hi, raw))


@lru_cache(maxsize=1)
def variant_index() -> dict[str, dict[str, Any]]:
    """Map normalized Hiligaynon variant -> symptom entry metadata."""
    index: dict[str, dict[str, Any]] = {}
    symptoms = load_lexicon().get("symptoms") or {}
    for key, entry in symptoms.items():
        if not isinstance(entry, dict):
            continue
        meta = {
            "symptom_key": key,
            "english": (entry.get("english") or "").strip(),
            "medical_term": (entry.get("medical_term") or key).strip(),
            "category": (entry.get("category") or "general").strip(),
        }
        variants = list(entry.get("hiligaynon") or [])
        alt = entry.get("alternate_spellings") or []
        if isinstance(alt, list):
            variants.extend(alt)
        for variant in variants:
            norm = _normalize_variant(str(variant))
            if norm and norm not in index:
                index[norm] = {**meta, "canonical_variant": norm}

    try:
        from hiligaynon_nlp_dataset_loader import term_index as nlp_term_index

        for norm, csv_meta in nlp_term_index().items():
            if norm in index:
                continue
            index[norm] = {
                "symptom_key": csv_meta.get("medical_term") or "",
                "english": csv_meta.get("english") or "",
                "medical_term": csv_meta.get("medical_term") or "",
                "category": csv_meta.get("category") or "general",
                "canonical_variant": csv_meta.get("canonical_variant") or norm,
                "severity": csv_meta.get("severity") or "",
                "body_system": csv_meta.get("body_system") or "",
            }
    except ImportError:
        pass

    try:
        from hiligaynon_pain_recognition_loader import complaint_index as pain_index

        for norm, meta in pain_index().items():
            if norm in index:
                continue
            index[norm] = {
                "symptom_key": meta.get("medical_term") or "",
                "english": meta.get("english") or "",
                "medical_term": meta.get("medical_term") or "",
                "category": meta.get("pain_category") or "pain",
                "canonical_variant": meta.get("canonical_complaint") or norm,
                "body_part": meta.get("body_part") or "",
                "severity_level": meta.get("severity_level") or "",
            }
    except ImportError:
        pass

    try:
        from hiligaynon_medical_knowledge_base_loader import statement_index as kb_index

        for norm, meta in kb_index().items():
            if norm in index:
                continue
            index[norm] = {
                "symptom_key": meta.get("medical_term") or "",
                "english": meta.get("english") or "",
                "medical_term": meta.get("medical_term") or "",
                "category": meta.get("body_system") or "general",
                "canonical_variant": meta.get("canonical_statement") or norm,
                "urgency_level": meta.get("urgency_level") or "",
                "body_system": meta.get("body_system") or "",
                "icd_category": meta.get("icd_category") or "",
            }
    except ImportError:
        pass

    try:
        from hiligaynon_patient_complaints_loader import complaint_index as patient_index

        for norm, meta in patient_index().items():
            if norm in index:
                continue
            index[norm] = {
                "symptom_key": meta.get("medical_term") or "",
                "english": meta.get("english") or "",
                "medical_term": meta.get("medical_term") or "",
                "category": meta.get("body_system") or "general",
                "canonical_variant": meta.get("canonical_complaint") or norm,
                "urgency_level": meta.get("urgency_level") or "",
                "body_system": meta.get("body_system") or "",
            }
    except ImportError:
        pass

    return index


@lru_cache(maxsize=1)
def variants_by_length() -> list[str]:
    return sorted(variant_index().keys(), key=len, reverse=True)


def _normalize_variant(text: str) -> str:
    import re

    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text


def lexicon_stats() -> dict[str, Any]:
    symptoms = load_lexicon().get("symptoms") or {}
    variants = variant_index()
    return {
        "version": load_lexicon().get("version"),
        "path": str(_LEXICON_JSON),
        "symptom_count": len(symptoms),
        "variant_count": len(variants),
        "fuzzy_threshold": fuzzy_threshold(),
        "categories": sorted(
            {
                (entry.get("category") or "general")
                for entry in symptoms.values()
                if isinstance(entry, dict)
            }
        ),
    }
