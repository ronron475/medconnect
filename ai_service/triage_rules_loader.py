"""Clinical triage rules from triage_rules.csv."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA = Path(__file__).resolve().parent.parent / "data" / "nlp" / "triage_rules.csv"

_LEVEL_MAP = {
    "non_urgent": "LOW",
    "routine": "LOW",
    "urgent": "HIGH",
    "emergency": "EMERGENCY",
    "critical": "EMERGENCY",
}


@lru_cache(maxsize=1)
def load_rules() -> tuple[dict[str, Any], ...]:
    if not _DATA.is_file():
        return ()
    rules: list[dict[str, Any]] = []
    with _DATA.open(encoding="utf-8", newline="") as f:
        for row in csv.DictReader(f):
            hil = (row.get("hiligaynon_pattern") or "").strip().lower()
            eng = (row.get("english_pattern") or "").strip().lower()
            if not hil and not eng:
                continue
            tri = (row.get("triage_level") or "routine").strip().lower()
            rules.append(
                {
                    "hiligaynon_pattern": hil,
                    "english_pattern": eng,
                    "triage_level": _LEVEL_MAP.get(tri, tri.upper()),
                    "severity": (row.get("severity") or "moderate").strip().lower(),
                    "medical_category": (row.get("medical_category") or "").strip(),
                    "reason": (row.get("reason") or "").strip(),
                }
            )
    rules.sort(key=lambda r: -len(r.get("hiligaynon_pattern") or ""))
    return tuple(rules)


def match_triage(original: str, english: str = "") -> dict[str, str] | None:
    hay_hil = (original or "").lower()
    hay_eng = (english or "").lower()
    for rule in load_rules():
        hil_pat = rule.get("hiligaynon_pattern") or ""
        eng_pat = rule.get("english_pattern") or ""
        if hil_pat and hil_pat in hay_hil:
            return {
                "triage_level": rule["triage_level"],
                "severity": rule["severity"],
                "reason": rule["reason"] or f"Matched triage rule: {hil_pat}",
                "source": "triage_rules.csv",
            }
        if eng_pat and eng_pat in hay_eng:
            return {
                "triage_level": rule["triage_level"],
                "severity": rule["severity"],
                "reason": rule["reason"] or f"Matched triage rule: {eng_pat}",
                "source": "triage_rules.csv",
            }
    return None


def clear_cache() -> None:
    load_rules.cache_clear()
