"""
Merge archive template cases + realistic typing scenarios into patient_cases.csv.

Run:
    python scripts/data/build_patient_training_dataset.py
    python scripts/data/build_realistic_patient_scenarios.py
    python scripts/data/merge_patient_training_corpus.py
    python scripts/data/sync_realistic_phrases_to_dictionary.py
    python scripts/data/sync_training_phrases_to_lexicon.py
"""

from __future__ import annotations

import csv
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TRAINING = ROOT / "data" / "nlp" / "training"
ARCHIVE_CASES = TRAINING / "patient_cases.csv"
REALISTIC = TRAINING / "patient_realistic_scenarios.csv"
CHIEF = TRAINING / "patient_chief_complaint_scenarios.csv"
HIL_COMPLAINT = TRAINING / "patient_hiligaynon_complaint_scenarios.csv"
OUT_CSV = TRAINING / "patient_cases.csv"
OUT_JSONL = TRAINING / "patient_cases.jsonl"
BACKUP = TRAINING / "patient_cases_archive_only.csv.bak"

FIELDNAMES = [
    "case_id",
    "disease",
    "language",
    "transcript",
    "symptom_keys",
    "symptom_count",
    "template_id",
    "split",
    "source",
]


def read_csv(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    rows: list[dict[str, str]] = []
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            rows.append({k: str(row.get(k) or "") for k in FIELDNAMES})
    return rows


def main() -> None:
    if not ARCHIVE_CASES.is_file():
        raise SystemExit(
            "Run first: python scripts/data/build_patient_training_dataset.py"
        )
    if not REALISTIC.is_file():
        raise SystemExit(
            "Run first: python scripts/data/build_realistic_patient_scenarios.py"
        )

    # patient_cases.csv is both the archive builder's output and this merge's output, so
    # read the archive rows back out by source. The backup is only a fallback: preferring
    # it silently kept stale archive rows (old splits, old phrasing) after a rebuild.
    archive_rows = [
        r
        for r in read_csv(ARCHIVE_CASES)
        if r.get("source") == "archive_source/dataset.csv"
    ]
    if not archive_rows and BACKUP.is_file():
        archive_rows = read_csv(BACKUP)

    realistic_rows = read_csv(REALISTIC)
    chief_rows = read_csv(CHIEF) if CHIEF.is_file() else []
    hil_complaint_rows = read_csv(HIL_COMPLAINT) if HIL_COMPLAINT.is_file() else []

    if archive_rows:
        with BACKUP.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=FIELDNAMES)
            writer.writeheader()
            writer.writerows(archive_rows)

    sys.path.insert(0, str(Path(__file__).resolve().parent))
    from build_patient_training_dataset import tidy_transcript

    seen_ids: set[str] = set()
    merged: list[dict[str, str]] = []
    for row in archive_rows + realistic_rows + chief_rows + hil_complaint_rows:
        cid = row.get("case_id", "")
        if cid in seen_ids:
            continue
        seen_ids.add(cid)
        row["transcript"] = tidy_transcript(row.get("transcript", ""))
        merged.append(row)

    with OUT_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDNAMES)
        writer.writeheader()
        writer.writerows(merged)

    with OUT_JSONL.open("w", encoding="utf-8") as handle:
        for row in merged:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")

    by_source: dict[str, int] = {}
    for row in merged:
        src = row.get("source") or "unknown"
        by_source[src] = by_source.get(src, 0) + 1

    print("Merged patient training corpus")
    print(f"  Archive rows:   {len(archive_rows)}")
    print(f"  Realistic rows: {len(realistic_rows)}")
    print(f"  Chief CC rows:  {len(chief_rows)}")
    print(f"  Hil complaint:  {len(hil_complaint_rows)}")
    print(f"  Total written:  {len(merged)} -> {OUT_CSV}")
    print(f"  By source:      {by_source}")


if __name__ == "__main__":
    main()
