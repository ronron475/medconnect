"""
Append Hiligaynon lines from realistic scenarios + training phrases to medical_dictionary.csv.

Run after build_realistic_patient_scenarios.py:
    python scripts/data/sync_realistic_phrases_to_dictionary.py
"""

from __future__ import annotations

import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DICT = ROOT / "data" / "nlp" / "medical_dictionary.csv"
REALISTIC = ROOT / "data" / "nlp" / "training" / "patient_realistic_scenarios.csv"
EXPANSION = ROOT / "data" / "nlp" / "patient_typing_dictionary_2026.csv"

# Short Hiligaynon chat fragments → English (for translation pass).
TYPING_FRAGMENTS: dict[str, str] = {
    "pasuyi": "please help",
    "pasuyi po": "please help",
    "subong": "now",
    "gid": "really",
    "g gihapon": "still",
    " gihapon": "still",
    "kanina pa": "since earlier",
    "basi maayo": "maybe okay",
    "basi ma check": "maybe can check",
    "wala ko kasiguruhan": "not sure",
    "type ko lang": "i will type",
    "ginatype ko": "i typed",
    "concern ko": "my concern",
    "telehealth": "telehealth",
    "online consult": "online consultation",
    "register ako": "i register",
    "halong": "hello",
    "pasuyi doc": "please help doctor",
    "good morning doc": "good morning doctor",
    "doc": "doctor",
    "po doc": "doctor",
    "pls": "please",
    "pero": "but",
    "lang": "only",
    "sang isa ka semana": "for one week",
    "3 days na": "3 days already",
    "3 days": "3 days",
    "since yesterday": "since yesterday",
    "after lunch": "after lunch",
    "worried about": "worried about",
    "not sure": "not sure",
    "help doc": "help doctor",
    "sorry late": "sorry late",
    "typing my concern": "typing my concern",
    "consult lang": "consult only",
    "for telehealth": "for telehealth",
    "registration symptom field": "registration symptom field",
    "my child has": "my child has",
    "asawa ko may": "my spouse has",
    "nag chat ko": "i chatted",
    "wala iba nga sintomas": "no other symptoms",
    "nag message ko": "i messaged",
    "para sa form": "for the form",
    "daw": "seems",
    "permí": "always",
    "chief complaint": "chief complaint",
    "cc": "chief complaint",
    "reklamo": "complaint",
    "unang reklamo": "chief complaint",
    "rason sang pag consult": "reason for consultation",
    "presenting complaint": "presenting complaint",
    "reason for visit": "reason for visit",
    "reason for consult": "reason for consultation",
    "main concern": "main concern",
    "primary complaint": "primary complaint",
    "health concern": "health concern",
    "intake form": "intake form",
    "e-consult": "online consultation",
    "virtual consult": "online consultation",
}


def load_existing_locals() -> set[str]:
    out: set[str] = set()
    if not DICT.is_file():
        return out
    with DICT.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            loc = (row.get("local_term") or "").strip().lower()
            if loc:
                out.add(loc)
    return out


def next_id() -> int:
    max_id = 0
    if DICT.is_file():
        with DICT.open(encoding="utf-8", newline="") as handle:
            for row in csv.DictReader(handle):
                try:
                    max_id = max(max_id, int(row.get("dictionary_id") or 0))
                except ValueError:
                    continue
    return max_id + 1


def extract_hil_phrases_from_realistic() -> list[tuple[str, str]]:
    pairs: list[tuple[str, str]] = []
    if not REALISTIC.is_file():
        return pairs
    with REALISTIC.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            if row.get("language") not in ("hiligaynon", "mixed"):
                continue
            text = (row.get("transcript") or "").strip()
            keys = (row.get("symptom_keys") or "").split(";")
            if not text or not keys:
                continue
            # Use first symptom as coarse English anchor for whole-line local snippets.
            en = keys[0].replace("_", " ")
            if len(text) >= 4 and len(text) <= 120:
                pairs.append((text.lower(), en))
    return pairs


def main() -> None:
    existing = load_existing_locals()
    new_rows: list[dict[str, str]] = []
    seq = next_id()

    for local, english in TYPING_FRAGMENTS.items():
        key = local.lower().strip()
        if key in existing:
            continue
        new_rows.append(
            {
                "dictionary_id": str(seq),
                "local_term": local,
                "english_term": english,
                "category": "condition",
            }
        )
        existing.add(key)
        seq += 1

    # Skip full-line transcript import (noisy English mapping). Fragments above are enough.

    EXPANSION.parent.mkdir(parents=True, exist_ok=True)
    with EXPANSION.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=["dictionary_id", "local_term", "english_term", "category"],
        )
        writer.writeheader()
        writer.writerows(new_rows)

    if not DICT.is_file():
        print(f"Missing {DICT}")
        return

    # Append expansion to main dictionary.
    with DICT.open("a", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=["dictionary_id", "local_term", "english_term", "category"],
        )
        for row in new_rows:
            writer.writerow(row)

    print(f"Added {len(new_rows)} dictionary rows")
    print(f"Expansion copy: {EXPANSION}")


if __name__ == "__main__":
    main()
