"""List Hiligaynon test misses (fast ML path or production)."""
from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

from analyzer import analyze_transcript, analyze_transcript_for_ml  # noqa: E402

CASES = ROOT / "data" / "nlp" / "training" / "patient_cases.csv"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--path", choices=["ml", "production"], default="ml")
    parser.add_argument("--case-id-prefix", default=None, help="e.g. PC- for archive thesis cohort")
    parser.add_argument("--source", default=None)
    args = parser.parse_args()
    analyze_fn: Callable[[str], dict[str, Any]] = (
        analyze_transcript if args.path == "production" else analyze_transcript_for_ml
    )

    rows = []
    with CASES.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            if row.get("split") != "test" or row.get("language") != "hiligaynon":
                continue
            if args.source and row.get("source") != args.source:
                continue
            cid = (row.get("case_id") or "").strip()
            if args.case_id_prefix and not cid.startswith(args.case_id_prefix):
                continue
            rows.append(row)

    hits = 0
    misses: list[dict] = []
    for row in rows:
        transcript = (row.get("transcript") or "").strip()
        expected = (row.get("disease") or "").strip()
        result = analyze_fn(transcript)
        preds = result.get("disease_predictions") or []
        top = str(preds[0].get("disease", "")) if preds else ""
        if top.lower() == expected.lower():
            hits += 1
        else:
            misses.append(
                {
                    "case_id": row.get("case_id"),
                    "expected": expected,
                    "got": top,
                    "keys": result.get("model_symptom_keys"),
                    "expected_keys": row.get("symptom_keys"),
                    "transcript": transcript[:120],
                }
            )

    total = len(rows)
    print(f"Path: {args.path}")
    print(f"Hiligaynon test: {hits}/{total} top-1 ({100 * hits / total:.1f}%)")
    print(f"Misses: {len(misses)}")
    for m in misses:
        print("---")
        print(m["case_id"], "exp=", m["expected"], "got=", m["got"])
        print("  detected:", m["keys"])
        print("  expected:", m["expected_keys"])
        print("  ", m["transcript"])


if __name__ == "__main__":
    main()
