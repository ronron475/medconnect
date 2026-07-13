# Condition Triage Severity Registry

Canonical **NON_URGENT | URGENT | EMERGENCY** classifications for MedConnect AI triage.

## Runtime (what the engine uses)

| File | Role |
|------|------|
| `condition_triage_severity.csv` | Curated conditions → severity (primary) |
| `triage_rules.csv` | Phrase pattern score bounds |
| `emergency_flags.csv` | Hard EMERGENCY red flags |
| Phrase CSVs (`hiligaynon_*.csv`, `symptom_phrases.csv`, …) | `triage_level` + `severity` per phrase |

`ClinicalTriageEngine` (PHP + Python) is **rule/CSV-based**. The LLM is not used for the final triage class.

## Maintainability

1. Add or edit a row in `condition_triage_severity.csv`.
2. Optionally add Hiligaynon phrases with `triage_level` in the phrase CSVs.
3. Re-validate:

```bash
python scripts/data/validate_and_fix_triage_datasets.py
```

No engine code changes are required for new conditions.

## Optional ICD overlay

`medical_conditions_triage_overlay.csv` (~74k rows) assigns severity to ICD condition names for QA/coverage.
Runtime loading is off by default. Enable in Python with:

`MEDCONNECT_LOAD_ICD_TRIAGE_OVERLAY=1`

## Columns (registry)

`id, medical_condition, symptom, category, severity_level, urgency_score, emergency_flag, recommended_action, provider_required, hospital_referral, language, synonyms, keywords, hiligaynon_term, status`
