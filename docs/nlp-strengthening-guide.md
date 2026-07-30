# NLP strengthening guide (MedConnect)

This guide explains how to push **internal test accuracy** toward **100%** on `patient_cases.csv` and keep **production** (`analyze_transcript` on port **8765**) aligned.

**Important:** 100% on this suite is **not** clinical accuracy on real free speech. It means every row in the synthetic test split matches the expected archive disease label.

---

## 1. Verify (run after every NLP change)

```bat
ai_service\.venv\Scripts\python.exe scripts\dev\evaluate_patient_ml_cases.py --split test --path both
ai_service\.venv\Scripts\python.exe scripts\dev\find_hiligaynon_misses.py
scripts\dev\nlp_deploy_check.bat
```

- **`--path ml`** — fast eval path (`analyze_transcript_for_ml`).
- **`--path production`** — same disease logic as teleconsultation / FastAPI.
- **`--path both`** — confirm they match (target: same top-1 on test split).

Restart after data changes:

```bat
ai_service\restart_ai_service_silent.bat
```

---

## 2. Pipeline layers (fix misses in order)

| Layer | File(s) | What to add |
|--------|---------|-------------|
| **A. Hiligaynon phrases** | `data/nlp/hiligaynon_symptom_lexicon.json` | `hiligaynon` variants per `symptom_key` (longest phrases first in matcher). |
| **B. Training phrase sync** | `scripts/data/build_patient_training_dataset.py` → run `sync_training_phrases_to_lexicon.py` | Keeps lexicon aligned with generated test transcripts. |
| **C. Dictionary translation** | `data/nlp/medical_dictionary.csv` | `local_term` → `english_term` for words not in lexicon. |
| **D. Analyzer shortcuts** | `ai_service/analyzer.py` (`HILIGAYNON_DICTIONARY`, `SYMPTOM_TERMS`) | High-frequency phrases used in translation pass. |
| **E. Model symptom aliases** | `ai_service/disease_predictor.py` (`SYMPTOM_ALIASES`, `LEXICON_KEY_TO_MODEL`) | English / lexicon labels → archive column names (`stomach_pain`, `mild_fever`, …). |
| **F. Classifier** | `ai_service/train_disease_classifier.py` | Retrain only if symptom vectors are correct but ML still wrong (~95% hold-out). |
| **G. Refine rules** | `disease_predictor.refine_disease_predictions()` | Last resort for overlapping diseases (liver/GI, GERD vs cardiac). Prefer A–E first. |

Production ML inputs use **lexicon JSON only** (stable keys) while UI still shows **full fuzzy** detections (`analyze_transcript`).

---

## 3. Detail: adding a new Hiligaynon symptom phrase

1. Find the **archive symptom key** in `data/nlp/archive_source/dataset.csv` / `disease_classifier_meta.json` → `symptom_columns`.
2. Add entry or variants in `hiligaynon_symptom_lexicon.json`:

```json
"burning_micturition": {
  "english": "burning urination",
  "medical_term": "burning_micturition",
  "category": "urinary",
  "hiligaynon": ["hapdi mag-ihi", "hapdi pag-ihi"]
}
```

3. Add alias if English text differs: `SYMPTOM_ALIASES["burning urination"] = "burning_micturition"`.
4. Add `LEXICON_KEY_TO_MODEL["burning_micturition"] = "burning_micturition"`.
5. Optional: `HILIGAYNON_DICTIONARY["hapdi mag-ihi"] = "burning urination"`.
6. Re-run eval `--path both`.

---

## 4. Detail: fixing a miss on the test CSV

1. `find_hiligaynon_misses.py` → note `detected` vs `expected_keys`.
2. If **detected** is missing a key → layers A–D.
3. If **detected** matches expected but **wrong disease** → layer F or G (overlap).
4. If transcript uses **English** in a Hiligaynon row → ensure dictionary/lexicon includes that English phrase as a variant.

---

## 5. What “100%” means here

| Metric | Typical meaning |
|--------|------------------|
| Test split 420 cases, `--path both` | Internal synthetic suite (deploy gate). |
| `test_accuracy` in meta JSON | ~95% symptom-vector classifier hold-out. |
| Real Hiligaynon conversation | Expect lower; collect new rows and add to lexicon. |

---

## 6. Optional expansion CSVs

Bulk local terms (non-symptom) can go to:

- `data/nlp/hiligaynon_nlp_expansion_2026.csv` → merge into `medical_dictionary.csv`
- `data/nlp/hiligaynon_medical_nlp_dataset.csv` (large; used for fuzzy UI, not ML keys)

Do not rely on the 60k+ CSV alone for ML keys; use **lexicon JSON** for stable symptom mapping.
