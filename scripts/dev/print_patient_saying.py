#!/usr/bin/env python3
"""Print a random patient saying for training/demo.

Usage:
    python scripts/dev/print_patient_saying.py
    python scripts/dev/print_patient_saying.py --language hiligaynon
    python scripts/dev/print_patient_saying.py --language english --count 5
"""

from __future__ import annotations

import argparse
import csv
import random
from pathlib import Path

CASES = Path(__file__).resolve().parents[2] / "data" / "nlp" / "training" / "patient_cases.csv"

QUICK_SAYINGS = [
    ("hiligaynon", "May hilanat kag ubo ako tatlo ka adlaw na. Nag-inom sang paracetamol.", "Common cold / fever"),
    ("hiligaynon", "May sakit dughan kag budlay ang ginhawa ko.", "URGENT — chest + breathing"),
    ("hiligaynon", "May sakit tiyan ako kag nagsuka. Wala ako gana magkaon.", "GERD / stomach"),
    ("english", "I have chest pain and difficulty breathing since this morning.", "URGENT"),
    ("mixed", "May hilanat kag ubo. I also feel tired and my head hurts.", "Mixed teleconsult"),
    ("english", "I have been sneezing, shivering, and my eyes are watering.", "Allergy"),
    ("english", "High fever, cough, chest pain, and fatigue for three days.", "Pneumonia-like"),
]


def load_cases(language: str | None) -> list[dict[str, str]]:
    if not CASES.is_file():
        return []
    rows: list[dict[str, str]] = []
    with CASES.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            if language and row.get("language") != language:
                continue
            rows.append(row)
    return rows


def main() -> None:
    parser = argparse.ArgumentParser(description="Print patient sayings for ML/video training")
    parser.add_argument("--language", choices=["english", "hiligaynon", "mixed"], default=None)
    parser.add_argument("--count", type=int, default=1)
    parser.add_argument("--quick", action="store_true", help="Use short demo lines only")
    args = parser.parse_args()

    print("medConnect — patient saying for training")
    print("=" * 44)

    if args.quick:
        pool = QUICK_SAYINGS
        if args.language:
            pool = [item for item in pool if item[0] == args.language]
        for idx, (lang, text, label) in enumerate(random.sample(pool, min(args.count, len(pool))), 1):
            print(f"\n[{idx}] {label} ({lang})")
            print(f"Say: \"{text}\"")
        return

    cases = load_cases(args.language)
    if not cases:
        print("No patient_cases.csv found. Run build_patient_training_dataset.py first.")
        return

    picks = random.sample(cases, min(args.count, len(cases)))
    for idx, row in enumerate(picks, 1):
        print(f"\n[{idx}] Expected disease: {row.get('disease')}")
        print(f"Language: {row.get('language')} | Case: {row.get('case_id')}")
        print(f"Say: \"{row.get('transcript')}\"")
        print(f"Symptoms: {row.get('symptom_keys')}")


if __name__ == "__main__":
    main()
