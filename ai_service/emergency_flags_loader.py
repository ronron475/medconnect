"""Emergency red-flag patterns — auto-elevate triage to EMERGENCY."""

from __future__ import annotations

import csv
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA = Path(__file__).resolve().parent.parent / "data" / "nlp" / "emergency_flags.csv"


@lru_cache(maxsize=1)
def load_flags() -> tuple[dict[str, Any], ...]:
    if not _DATA.is_file():
        return ()
    flags: list[dict[str, Any]] = []
    with _DATA.open(encoding="utf-8", newline="") as f:
        for row in csv.DictReader(f):
            hil = (row.get("hiligaynon_pattern") or "").strip().lower()
            eng = (row.get("english_pattern") or "").strip().lower()
            if not hil and not eng:
                continue
            flags.append(
                {
                    "flag_id": row.get("flag_id") or "",
                    "flag_name": row.get("flag_name") or "",
                    "hiligaynon_pattern": hil,
                    "english_pattern": eng,
                    "body_system": row.get("body_system") or "",
                    "category": row.get("category") or "",
                    "auto_triage": (row.get("auto_triage") or "EMERGENCY").upper(),
                    "severity": row.get("severity") or "critical",
                    "clinical_rationale": row.get("clinical_rationale") or "",
                }
            )
    flags.sort(key=lambda x: -len(x.get("hiligaynon_pattern") or ""))
    return tuple(flags)


def scan_emergency_flags(original: str, english: str = "") -> list[dict[str, Any]]:
    hay_hil = (original or "").lower()
    hay_eng = (english or "").lower()
    matched: list[dict[str, Any]] = []
    seen: set[str] = set()
    for flag in load_flags():
        fid = flag.get("flag_id") or flag.get("flag_name") or ""
        if fid in seen:
            continue
        hil_pat = flag.get("hiligaynon_pattern") or ""
        eng_pat = flag.get("english_pattern") or ""
        if hil_pat and hil_pat in hay_hil:
            matched.append({**flag, "matched_on": "hiligaynon"})
            seen.add(fid)
        elif eng_pat and eng_pat in hay_eng:
            matched.append({**flag, "matched_on": "english"})
            seen.add(fid)
    return matched


def clear_cache() -> None:
    load_flags.cache_clear()
