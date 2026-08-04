# Triage NLP Validation Suite

Continuous testing and QA for the medConnect clinical triage CDS.

## Generate datasets

```bat
ai_service\.venv\Scripts\python.exe scripts\data\build_triage_validation_dataset.py
```

Produces:

| File | Rows |
|------|-----:|
| `english_chief_complaints_validation.csv` | 10,000 |
| `filipino_chief_complaints_validation.csv` | 10,000 |
| `hiligaynon_chief_complaints_validation.csv` | 10,000 |
| `mixed_language_complaints_validation.csv` | 5,000 |
| `misspelled_complaints_validation.csv` | 5,000 |
| `emergency_scenarios_validation.csv` | 3,000 |
| `urgent_scenarios_validation.csv` | 3,000 |
| `non_urgent_scenarios_validation.csv` | 4,000 |
| `triage_validation_master.csv` | 40,000 |
| `triage_validation_gold.csv` | curated gold cases |

Every row includes `expected_classification`: `NON-URGENT` | `URGENT` | `EMERGENCY`.

## Evaluate

```bat
c:\xampp\php\php.exe scripts\dev\evaluate_triage_validation.php --gold
c:\xampp\php\php.exe scripts\dev\evaluate_triage_validation.php --file=data/nlp/validation/triage_validation_gold.csv
```

## Runtime self-validation

`TriageSelfValidationEngine` runs after every `ClinicalTriageEngine::assess()` call:

1. Pipeline checklist (language, spelling, symptoms, negation, duration, temp, pain, risks, red flags, explanation)
2. Consistency rejection (e.g. mild fever ≠ EMERGENCY; chest pain + dyspnea ≠ NON-URGENT)
3. Rule conflict priority (Emergency → Airway → Breathing → … → Admin → Confidence)
4. Confidence &lt; 60% → `Needs Healthcare Provider Review`
5. Knowledge suggestions for unknown terms (CSV-only expansion hints)

Core PHP NLP logic is not rewritten for new vocabulary — add rows to CSVs instead.
