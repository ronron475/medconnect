# Patient-based ML training data

Natural-language patient transcripts for testing and training the teleconsultation AI pipeline.

## Build

```bat
scripts\data\build_patient_training_dataset.bat
```

Or:

```bash
python scripts/data/build_patient_training_dataset.py
```

## Source

- `data/nlp/archive_source/dataset.csv` — disease + symptom combinations
- `data/nlp/medical_dictionary.csv` — Hiligaynon/Ilonggo symptom phrases

## Output

| File | Description |
|------|-------------|
| `patient_cases.csv` | Main dataset (CSV) |
| `patient_cases.jsonl` | Same data (JSON Lines) |

### Columns

| Column | Meaning |
|--------|---------|
| `case_id` | Unique ID (`PC-00001`) |
| `disease` | Expected disease label |
| `language` | `english`, `hiligaynon`, or `mixed` |
| `transcript` | Patient-style text (what a patient might say) |
| `symptom_keys` | Canonical symptom keys (`;` separated) |
| `split` | `train`, `val`, or `test` |

## Evaluate ML pipeline

After training the classifier (`ai_service/train_disease_classifier.py`):

```bash
python scripts/dev/evaluate_patient_ml_cases.py --split test
python scripts/dev/evaluate_patient_ml_cases.py --language hiligaynon --limit 20
```

## Full workflow

```bat
scripts\data\build_patient_training_dataset.bat
ai_service\.venv\Scripts\python.exe ai_service\train_disease_classifier.py
ai_service\.venv\Scripts\python.exe scripts\dev\evaluate_patient_ml_cases.py --split test
```

## Patient sayings for demo / training

Copy-paste scripts for video calls and AI testing:

- `patient_sayings_for_demo.txt` — full cheat sheet (Hiligaynon + English)

Random line in terminal:

```bash
python scripts/dev/print_patient_saying.py --quick
python scripts/dev/print_patient_saying.py --language hiligaynon --count 3
```
