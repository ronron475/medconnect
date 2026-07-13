"""Load canonical condition triage severity from CSV (not LLM)."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_NLP = Path(__file__).resolve().parent.parent / "data" / "nlp"

_STORAGE_TO_CLASS = {
    "non_urgent": "NON_URGENT",
    "non-urgent": "NON_URGENT",
    "routine": "NON_URGENT",
    "low": "NON_URGENT",
    "urgent": "URGENT",
    "high": "URGENT",
    "emergency": "EMERGENCY",
    "critical": "EMERGENCY",
    "non_urgent".upper(): "NON_URGENT",
    "URGENT": "URGENT",
    "EMERGENCY": "EMERGENCY",
    "NON_URGENT": "NON_URGENT",
}


def _norm(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def _canon(raw: str) -> str:
    v = (raw or "").strip().upper().replace("-", "_").replace(" ", "_")
    if v in {"NON_URGENT", "URGENT", "EMERGENCY"}:
        return v
    low = (raw or "").strip().lower().replace("-", "_").replace(" ", "_")
    return _STORAGE_TO_CLASS.get(low, "")


@lru_cache(maxsize=1)
def condition_severity_index() -> dict[str, dict[str, Any]]:
    """
    Index English condition/synonym/Hiligaynon → severity metadata.
    Runtime uses curated condition_triage_severity.csv.
    ICD overlay is opt-in via MEDCONNECT_LOAD_ICD_TRIAGE_OVERLAY=1 (large).
    """
    import os

    index: dict[str, dict[str, Any]] = {}

    def add(key: str, meta: dict[str, Any], prefer: bool = False) -> None:
        k = _norm(key)
        if not k:
            return
        if k in index and not prefer:
            return
        index[k] = meta

    registry = _NLP / "condition_triage_severity.csv"
    if registry.is_file():
        with registry.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                level = _canon(row.get("severity_level") or "")
                if not level:
                    continue
                meta = {
                    "medical_condition": (row.get("medical_condition") or "").strip(),
                    "severity_level": level,
                    "urgency_score": int(row.get("urgency_score") or 0) or (
                        20 if level == "NON_URGENT" else 55 if level == "URGENT" else 90
                    ),
                    "emergency_flag": (row.get("emergency_flag") or "0").strip() in {"1", "true", "yes"},
                    "recommended_action": (row.get("recommended_action") or "").strip(),
                    "provider_required": (row.get("provider_required") or "0").strip() in {"1", "true", "yes"},
                    "hospital_referral": (row.get("hospital_referral") or "0").strip() in {"1", "true", "yes"},
                    "source": "condition_triage_severity.csv",
                }
                add(meta["medical_condition"], meta, prefer=True)
                for part in (row.get("synonyms") or "").split(";"):
                    add(part, meta)
                add(row.get("hiligaynon_term") or "", meta)
                for part in (row.get("keywords") or "").split(";"):
                    add(part, meta)

    if os.environ.get("MEDCONNECT_LOAD_ICD_TRIAGE_OVERLAY", "").strip() in {"1", "true", "yes"}:
        overlay = _NLP / "medical_conditions_triage_overlay.csv"
        if overlay.is_file():
            with overlay.open(encoding="utf-8", newline="") as f:
                for row in csv.DictReader(f):
                    level = _canon(row.get("severity_level") or "")
                    name = (row.get("medical_condition") or "").strip()
                    if not level or not name:
                        continue
                    meta = {
                        "medical_condition": name,
                        "severity_level": level,
                        "urgency_score": int(row.get("urgency_score") or 0) or (
                            20 if level == "NON_URGENT" else 55 if level == "URGENT" else 90
                        ),
                        "emergency_flag": (row.get("emergency_flag") or "0").strip() in {"1", "true", "yes"},
                        "recommended_action": (row.get("recommended_action") or "").strip(),
                        "provider_required": (row.get("provider_required") or "0").strip() in {"1", "true", "yes"},
                        "hospital_referral": (row.get("hospital_referral") or "0").strip() in {"1", "true", "yes"},
                        "source": "medical_conditions_triage_overlay.csv",
                    }
                    add(name, meta, prefer=False)

    return index


def lookup_condition_severity(*terms: str) -> dict[str, Any] | None:
    """Return highest-urgency exact match among terms (CSV-backed, no LLM)."""
    index = condition_severity_index()
    rank = {"NON_URGENT": 0, "URGENT": 1, "EMERGENCY": 2}
    best: dict[str, Any] | None = None
    for term in terms:
        meta = index.get(_norm(term))
        if not meta:
            continue
        if best is None or rank[meta["severity_level"]] > rank[best["severity_level"]]:
            best = meta
    return best


def clear_cache() -> None:
    condition_severity_index.cache_clear()
