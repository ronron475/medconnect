"""
Evaluate disease ML pipeline on patient_cases.csv transcripts.

Run (AI service not required — uses disease_predictor directly):
    python scripts/dev/evaluate_patient_ml_cases.py
    python scripts/dev/evaluate_patient_ml_cases.py --split test
    python scripts/dev/evaluate_patient_ml_cases.py --language hiligaynon --limit 20
    python scripts/dev/evaluate_patient_ml_cases.py --split test --path production
    python scripts/dev/evaluate_patient_ml_cases.py --split test --path both
"""

from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path
from typing import Any, Callable

ROOT = Path(__file__).resolve().parents[2]
CASES = ROOT / "data" / "nlp" / "training" / "patient_cases.csv"
AI_SERVICE = ROOT / "ai_service"

sys.path.insert(0, str(AI_SERVICE))

from analyzer import analyze_transcript, analyze_transcript_for_ml
from disease_predictor import model_available  # noqa: E402


def load_cases(
    split: str | None,
    language: str | None,
    limit: int | None,
    source: str | None = None,
    case_id_prefix: str | None = None,
) -> list[dict[str, str]]:
    if not CASES.is_file():
        raise SystemExit(
            f"Missing {CASES}\nRun: python scripts/data/build_patient_training_dataset.py"
        )

    rows: list[dict[str, str]] = []
    with CASES.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            if split and row.get("split") != split:
                continue
            if language and row.get("language") != language:
                continue
            if source and row.get("source") != source:
                continue
            cid = (row.get("case_id") or "").strip()
            if case_id_prefix and not cid.startswith(case_id_prefix):
                continue
            rows.append(row)

    if limit is not None:
        rows = rows[:limit]
    return rows


def top_prediction_matches(result: dict, expected_disease: str) -> tuple[bool, str, float]:
    predictions = result.get("disease_predictions") or []
    if not predictions:
        return False, "", 0.0
    top = predictions[0]
    disease = str(top.get("disease") or "")
    confidence = float(top.get("confidence") or 0)
    matched = disease.lower() == expected_disease.lower()
    return matched, disease, confidence


def run_eval(
    cases: list[dict[str, str]],
    label: str,
    analyze_fn: Callable[[str], dict[str, Any]],
    verbose: bool,
) -> tuple[int, int, int]:
    correct_top1 = 0
    correct_top3 = 0
    total = 0

    print(f"--- {label} ---")
    for row in cases:
        transcript = (row.get("transcript") or "").strip()
        expected = (row.get("disease") or "").strip()
        analyzed = analyze_fn(transcript)
        predictions = analyzed.get("disease_predictions") or []

        top_ok, top_disease, top_conf = top_prediction_matches(analyzed, expected)
        top3_ok = any(
            str(item.get("disease", "")).lower() == expected.lower() for item in predictions[:3]
        )

        total += 1
        correct_top1 += int(top_ok)
        correct_top3 += int(top3_ok)

        if verbose:
            status = "OK" if top_ok else "MISS"
            print(
                f"[{status}] {row.get('case_id')} ({row.get('language')}) "
                f"expected={expected} | top={top_disease} ({top_conf}%)"
            )
            if not top_ok and predictions:
                alts = ", ".join(
                    f"{p.get('disease')} ({p.get('confidence')}%)" for p in predictions[:3]
                )
                print(f"      transcript: {transcript[:90]}...")
                print(f"      predicted:  {alts}")

    if total:
        print(
            f"Top-1: {correct_top1}/{total} ({100 * correct_top1 / total:.1f}%) | "
            f"Top-3: {correct_top3}/{total} ({100 * correct_top3 / total:.1f}%)"
        )
    print()
    return correct_top1, correct_top3, total


def main() -> None:
    parser = argparse.ArgumentParser(description="Evaluate ML on patient training cases")
    parser.add_argument("--split", choices=["train", "val", "test"], default=None)
    parser.add_argument("--language", choices=["english", "hiligaynon", "mixed"], default=None)
    parser.add_argument("--limit", type=int, default=None)
    parser.add_argument(
        "--path",
        choices=["ml", "production", "both"],
        default="ml",
        help="ml=analyze_transcript_for_ml; production=analyze_transcript (FastAPI parity)",
    )
    parser.add_argument("--verbose", action="store_true", help="Print every case")
    parser.add_argument("--source", default=None, help="Filter by source column")
    parser.add_argument(
        "--case-id-prefix",
        default=None,
        help="Filter case_id prefix (e.g. PC- for archive thesis cohort)",
    )
    args = parser.parse_args()

    if not model_available():
        print("WARNING: disease_classifier.joblib not found.")
        print("Run: python ai_service/train_disease_classifier.py")
        print()

    cases = load_cases(
        args.split,
        args.language,
        args.limit,
        source=args.source,
        case_id_prefix=args.case_id_prefix,
    )
    if not cases:
        print("No cases matched filters.")
        return

    print("medConnect patient ML evaluation")
    print("==============================")
    print(f"Cases: {len(cases)} | split={args.split or 'all'} | language={args.language or 'all'}")
    if args.source:
        print(f"  source={args.source}")
    if args.case_id_prefix:
        print(f"  case_id_prefix={args.case_id_prefix}")
    print()

    verbose = args.verbose or len(cases) <= 30

    if args.path in ("ml", "both"):
        run_eval(cases, "analyze_transcript_for_ml (eval fast path)", analyze_transcript_for_ml, verbose)
    if args.path in ("production", "both"):
        run_eval(cases, "analyze_transcript (production / port 8765)", analyze_transcript, verbose)


if __name__ == "__main__":
    main()
