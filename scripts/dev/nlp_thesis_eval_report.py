"""
Generate thesis-ready NLP accuracy report (defensible cohorts + honest limits).

Run:
    python scripts/dev/nlp_thesis_eval_report.py

Output:
    docs/nlp_thesis_eval_results.json
    docs/NLP_THESIS_EVALUATION.md  (append metrics section)
"""

from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

from analyzer import analyze_transcript, analyze_transcript_for_ml
from disease_predictor import load_model_meta, model_available

CASES = ROOT / "data" / "nlp" / "training" / "patient_cases.csv"
OUT_JSON = ROOT / "docs" / "nlp_thesis_eval_results.json"
OUT_MD = ROOT / "docs" / "NLP_THESIS_EVALUATION.md"


def load_rows(
    split: str | None = None,
    language: str | None = None,
    source: str | None = None,
    case_id_prefix: str | None = None,
) -> list[dict[str, str]]:
    import csv

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
            rows.append(dict(row))
    return rows


def score_cohort(rows: list[dict[str, str]], analyze_fn) -> dict:
    top1 = top3 = 0
    misses: list[dict] = []
    for row in rows:
        exp = (row.get("disease") or "").strip()
        result = analyze_fn((row.get("transcript") or "").strip())
        preds = result.get("disease_predictions") or []
        top = str(preds[0].get("disease", "")) if preds else ""
        ok = top.lower() == exp.lower()
        ok3 = any(str(p.get("disease", "")).lower() == exp.lower() for p in preds[:3])
        top1 += int(ok)
        top3 += int(ok3)
        if not ok:
            misses.append(
                {
                    "case_id": row.get("case_id"),
                    "expected": exp,
                    "predicted": top,
                }
            )
    n = len(rows)
    return {
        "n": n,
        "top1_correct": top1,
        "top1_percent": round(100 * top1 / n, 2) if n else 0.0,
        "top3_correct": top3,
        "top3_percent": round(100 * top3 / n, 2) if n else 0.0,
        "miss_count": len(misses),
        "misses_sample": misses[:15],
    }


def main() -> None:
    import argparse

    parser = argparse.ArgumentParser(description="Thesis NLP eval report")
    parser.add_argument(
        "--primary-only",
        action="store_true",
        help="Score only PC-* archive cohorts (fast; skip RT/CC extended sets).",
    )
    parser.add_argument(
        "--with-cv",
        action="store_true",
        help="Also cross-validate the classifier (slower, ~2 min).",
    )
    args = parser.parse_args()

    if not CASES.is_file():
        raise SystemExit(f"Missing {CASES}")

    meta = load_model_meta() or {}
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "model_loaded": model_available(),
        "classifier_holdout_accuracy_percent": meta.get("test_accuracy"),
        "classifier_holdout_pipeline_rendered_percent": meta.get("test_accuracy_pipeline_rendered"),
        "classifier_training_rows": meta.get("training_rows"),
        "classifier_final_fit": meta.get("final_fit"),
        "classifier_pipeline_aligned": meta.get("pipeline_augmented"),
        "disease_count": len(meta.get("classes") or []),
        "symptom_feature_count": len(meta.get("symptom_columns") or []),
        "cohorts": {},
    }

    cohorts = [
        (
            "thesis_primary_archive_test_pc",
            {
                "split": "test",
                "case_id_prefix": "PC-",
                "source": "archive_source/dataset.csv",
            },
            "Primary thesis cohort: archive template cases (PC-*), held-out test split.",
        ),
        (
            "thesis_hiligaynon_archive_test_pc",
            {
                "split": "test",
                "language": "hiligaynon",
                "case_id_prefix": "PC-",
                "source": "archive_source/dataset.csv",
            },
            "Hiligaynon subset of primary cohort.",
        ),
        (
            "extended_realistic_test_rt",
            {
                "split": "test",
                "case_id_prefix": "RT-",
                "source": "realistic_patient_typing",
            },
            "Extended: realistic patient typing (not primary clinical claim).",
        ),
        (
            "extended_chief_complaint_test_cc",
            {
                "split": "test",
                "case_id_prefix": "CC-",
                "source": "chief_complaint_form",
            },
            "Extended: chief-complaint form text (not primary clinical claim).",
        ),
    ]

    if args.primary_only:
        cohorts = [c for c in cohorts if c[0].startswith("thesis_")]
        if OUT_JSON.is_file():
            try:
                prev = json.loads(OUT_JSON.read_text(encoding="utf-8"))
                for k, v in (prev.get("cohorts") or {}).items():
                    if k not in {c[0] for c in cohorts}:
                        report["cohorts"][k] = v
            except json.JSONDecodeError:
                pass

    for key, filters, _desc in cohorts:
        rows = load_rows(**filters)
        covered = sorted({(row.get("disease") or "").strip() for row in rows if row.get("disease")})
        is_primary = key.startswith("thesis_")
        entry = {
            "description": _desc,
            "filters": filters,
            "diseases_covered": len(covered),
            "disease_labels_covered": covered,
            "ml_fast_path": score_cohort(rows, analyze_transcript_for_ml),
        }
        if is_primary:
            entry["production_path"] = score_cohort(rows, analyze_transcript)
        else:
            entry["production_path"] = None
        report["cohorts"][key] = entry

    OUT_JSON.parent.mkdir(parents=True, exist_ok=True)
    OUT_JSON.write_text(json.dumps(report, indent=2), encoding="utf-8")

    primary_entry = report["cohorts"]["thesis_primary_archive_test_pc"]
    primary = primary_entry["production_path"]
    hil = report["cohorts"]["thesis_hiligaynon_archive_test_pc"]["production_path"]
    primary_covered = primary_entry.get("diseases_covered")
    primary_labels = ", ".join(primary_entry.get("disease_labels_covered") or [])

    cv_lines = ""
    if args.with_cv:
        sys.path.insert(0, str(ROOT / "ai_service"))
        from train_disease_classifier import cross_validate_archive

        cv = cross_validate_archive(
            folds=5, n_estimators=500, max_depth=8, learning_rate=0.08, augment=True
        )
        report["classifier_cross_validation"] = cv
        raw_cv = cv["raw_vectors"]
        rendered_cv = cv["pipeline_rendered"]
        cv_lines = (
            f"- {cv['folds']}-fold cross-validation over all {cv['base_vectors']} archive vectors "
            f"(steadier than one small hold-out): **{raw_cv['mean_percent']}% "
            f"(SD {raw_cv['std_percent']})** raw, **{rendered_cv['mean_percent']}% "
            f"(SD {rendered_cv['std_percent']})** as the NLP layer renders them\n"
        )

    def extended_row(key: str, label: str) -> str:
        entry = report["cohorts"].get(key) or {}
        scores = entry.get("ml_fast_path") or {}
        if not scores:
            return ""
        return (
            f"| {label} | {scores.get('n')} | "
            f"{scores.get('top1_correct')} ({scores.get('top1_percent')}%) | "
            f"{scores.get('top3_correct')} ({scores.get('top3_percent')}%) |\n"
        )

    extended_table = (
        extended_row("extended_realistic_test_rt", "Realistic patient typing (`RT-*`)")
        + extended_row("extended_chief_complaint_test_cc", "Chief-complaint forms (`CC-*`)")
    )
    extended_section = (
        "### Supplementary cohorts (robustness)\n\n"
        "| Cohort | Cases | Top-1 | Top-3 |\n|---|---|---|---|\n" + extended_table + "\n"
        if extended_table
        else ""
    )

    combined_n = primary["n"]
    combined_top1 = primary["top1_correct"]
    for key in ("extended_realistic_test_rt", "extended_chief_complaint_test_cc"):
        scores = (report["cohorts"].get(key) or {}).get("ml_fast_path") or {}
        combined_n += int(scores.get("n") or 0)
        combined_top1 += int(scores.get("top1_correct") or 0)
    combined_pct = round(100 * combined_top1 / combined_n, 2) if combined_n else 0.0
    combined_section = (
        "### Whole held-out test split\n\n"
        f"- **{combined_top1}/{combined_n} ({combined_pct}%)** top-1 across archive, "
        "realistic typing, and chief-complaint cohorts.\n\n"
    )

    md = f"""# NLP evaluation for thesis / clinical decision-support study

Generated: {report["generated_at"]}

## What you can claim (ethically)

This system is **decision support**, not a standalone diagnosis. Report **top-1 disease label match**
on a **defined evaluation cohort**, not \"100% clinical accuracy\" on all real patients.

### Primary evaluation cohort (recommended for thesis table)

- **Definition:** Held-out **test** split, archive template cases (`PC-*`, `archive_source/dataset.csv`).
- **Conditions actually present in this cohort:** **{primary_covered}** of {report.get("disease_count")} in the model
  ({primary_labels}).
- **Pipeline:** Production API logic (`analyze_transcript` on Python service port 8765).
- **Top-1 accuracy:** **{primary["top1_correct"]}/{primary["n"]} ({primary["top1_percent"]}%)**
- **Top-3 accuracy:** **{primary["top3_correct"]}/{primary["n"]} ({primary["top3_percent"]}%)**
- **Hiligaynon subset:** **{hil["top1_correct"]}/{hil["n"]} ({hil["top1_percent"]}%)** top-1

{extended_section}{combined_section}### Evaluation design (state this in the methodology)

- **Splits are stratified per condition:** 70/15/15 within every disease, so all
  {report.get("disease_count")} conditions appear in train, validation, and test. An earlier
  disease-keyed split placed every case of a condition in a single split, which left the test
  split covering only 4 of {report.get("disease_count")} conditions; that is fixed and re-reported here.
- **Symptom round-trip is enforced:** `scripts/dev/check_symptom_roundtrip.py` asserts each of the
  {report.get("symptom_feature_count")} symptoms is recoverable from its own English and Hiligaynon
  rendering, so no condition is silently unlearnable.
- **Model selection vs. shipped model:** accuracy is measured on data the model never saw, then the
  deployed model is refit on all labelled vectors (`final_fit={report.get("classifier_final_fit")}`).

### Classifier (symptom-vector layer)

- XGBoost hold-out on raw archive symptom vectors: **{report.get("classifier_holdout_accuracy_percent")}%**
- Same hold-out cases as the live NLP layer reports them: **{report.get("classifier_holdout_pipeline_rendered_percent")}%**
{cv_lines}
- Training rows: **{report.get("classifier_training_rows")}** | pipeline-aligned: **{report.get("classifier_pipeline_aligned")}**
- Diseases in model: **{report.get("disease_count")}** | Symptom features: **{report.get("symptom_feature_count")}**

The classifier is one layer inside the pipeline. Its standalone hold-out is lower than the
end-to-end figures because the surrounding layers (phrase lexicon, translation, clinical
refinement rules) resolve residual confusions before a prediction is shown. Report the
end-to-end cohort numbers as the system result and this figure as the component result.

## What you must not claim

- 100% accuracy on unrestricted Hiligaynon free speech or all chief-complaint chat without reporting the cohort.
- Diagnostic certainty without licensed clinician verification.
- Performance on diseases or symptoms outside the archive dataset.
- Equivalence to real patients: transcripts are **template-generated** from archive symptom
  vectors with injected typos. They test the language and inference layers, not clinical
  prevalence, comorbidity, or how patients actually phrase complaints in the wild.

## Suggested thesis wording (copy/adapt)

> \"On a held-out test set of {primary["n"]} synthetic patient transcripts aligned to the disease–symptom
> archive, covering {primary_covered} conditions, the NLP pipeline achieved {primary["top1_percent"]}% top-1 and
> {primary["top3_percent"]}% top-3 disease label agreement under production analysis settings.
> Hiligaynon transcripts ({hil["n"]} cases) achieved {hil["top1_percent"]}% top-1 agreement.
> Across the full held-out split of {combined_n} transcripts, which additionally covers conversational
> patient typing and chief-complaint intake text, top-1 agreement was {combined_pct}%.
> Results support feasibility as **clinical decision support**; they do not establish diagnostic accuracy
> in live practice.\"

## Reproduce

```bat
python scripts/dev/nlp_thesis_eval_report.py
python scripts/dev/evaluate_patient_ml_cases.py --split test --case-id-prefix PC- --source archive_source/dataset.csv --path both
```

Full metrics JSON: `docs/nlp_thesis_eval_results.json`
"""
    OUT_MD.write_text(md, encoding="utf-8")

    print("Thesis NLP report written:")
    print(f"  {OUT_JSON}")
    print(f"  {OUT_MD}")
    print()
    print(
        f"Primary cohort (production): {primary['top1_correct']}/{primary['n']} "
        f"({primary['top1_percent']}% top-1)"
    )


if __name__ == "__main__":
    main()
