"""Print test-split top-1 misses from patient_cases.csv (ML fast path)."""
from __future__ import annotations

import csv
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

from analyzer import analyze_transcript_for_ml  # noqa: E402

CASES = ROOT / "data" / "nlp" / "training" / "patient_cases.csv"


def main() -> None:
    rows = [
        r
        for r in csv.DictReader(CASES.open(encoding="utf-8"))
        if r.get("split") == "test"
    ]
    misses: list[dict] = []
    for i, r in enumerate(rows):
        if i % 500 == 0:
            print(f"... {i}/{len(rows)}", flush=True)
        res = analyze_transcript_for_ml((r.get("transcript") or "").strip())
        preds = res.get("disease_predictions") or []
        top = str(preds[0]["disease"]) if preds else ""
        if top.lower() != (r.get("disease") or "").lower():
            exp = {k for k in (r.get("symptom_keys") or "").split(";") if k}
            det = set(res.get("model_symptom_keys") or [])
            misses.append(
                {
                    "id": r.get("case_id"),
                    "source": r.get("source"),
                    "exp": r.get("disease"),
                    "got": top,
                    "conf": preds[0].get("confidence") if preds else None,
                    "missing": sorted(exp - det),
                    "extra": sorted(det - exp),
                    "top3": [(str(p["disease"]), p["confidence"]) for p in preds[:3]],
                    "text": (r.get("transcript") or "")[:400],
                }
            )

    print(f"misses: {len(misses)} of {len(rows)}", flush=True)
    for m in misses:
        print("=" * 72, flush=True)
        print(f"[{m['id']}] source={m['source']}", flush=True)
        print(f"  expected: {m['exp']}", flush=True)
        print(f"  got:      {m['got']} ({m['conf']})", flush=True)
        print(f"  top3:     {m['top3']}", flush=True)
        print(f"  missing:  {m['missing']}", flush=True)
        print(f"  extra:    {m['extra']}", flush=True)
        print(f"  text:     {m['text']}", flush=True)


if __name__ == "__main__":
    main()
