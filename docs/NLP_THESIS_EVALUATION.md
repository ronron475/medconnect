# NLP evaluation for thesis / clinical decision-support study

Generated: 2026-07-28T19:12:23.308264+00:00

## What you can claim (ethically)

This system is **decision support**, not a standalone diagnosis. Report **top-1 disease label match**
on a **defined evaluation cohort**, not "100% clinical accuracy" on all real patients.

### Primary evaluation cohort (recommended for thesis table)

- **Definition:** Held-out **test** split, archive template cases (`PC-*`, `archive_source/dataset.csv`).
- **Conditions actually present in this cohort:** **41** of 41 in the model
  ((vertigo) Paroymsal  Positional Vertigo, AIDS, Acne, Alcoholic hepatitis, Allergy, Arthritis, Bronchial Asthma, Cervical spondylosis, Chicken pox, Chronic cholestasis, Common Cold, Dengue, Diabetes, Dimorphic hemmorhoids(piles), Drug Reaction, Fungal infection, GERD, Gastroenteritis, Heart attack, Hepatitis B, Hepatitis C, Hepatitis D, Hepatitis E, Hypertension, Hyperthyroidism, Hypoglycemia, Hypothyroidism, Impetigo, Jaundice, Malaria, Migraine, Osteoarthristis, Paralysis (brain hemorrhage), Peptic ulcer diseae, Pneumonia, Psoriasis, Tuberculosis, Typhoid, Urinary tract infection, Varicose veins, hepatitis A).
- **Pipeline:** Production API logic (`analyze_transcript` on Python service port 8765).
- **Top-1 accuracy:** **772/772 (100.0%)**
- **Top-3 accuracy:** **772/772 (100.0%)**
- **Hiligaynon subset:** **456/456 (100.0%)** top-1

### Supplementary cohorts (robustness)

| Cohort | Cases | Top-1 | Top-3 |
|---|---|---|---|
| Realistic patient typing (`RT-*`) | 2921 | 2921 (100.0%) | 2921 (100.0%) |
| Chief-complaint forms (`CC-*`) | 5336 | 4769 (89.37%) | 4935 (92.49%) |

### Whole held-out test split

- **8462/9029 (93.72%)** top-1 across archive, realistic typing, and chief-complaint cohorts.

### Evaluation design (state this in the methodology)

- **Splits are stratified per condition:** 70/15/15 within every disease, so all
  41 conditions appear in train, validation, and test. An earlier
  disease-keyed split placed every case of a condition in a single split, which left the test
  split covering only 4 of 41 conditions; that is fixed and re-reported here.
- **Symptom round-trip is enforced:** `scripts/dev/check_symptom_roundtrip.py` asserts each of the
  131 symptoms is recoverable from its own English and Hiligaynon
  rendering, so no condition is silently unlearnable.
- **Model selection vs. shipped model:** accuracy is measured on data the model never saw, then the
  deployed model is refit on all labelled vectors (`final_fit=all_labeled_data`).

### Classifier (symptom-vector layer)

- XGBoost hold-out on raw archive symptom vectors: **100.0%**
- Same hold-out cases as the live NLP layer reports them: **100.0%**
- 5-fold cross-validation over all 304 archive vectors (steadier than one small hold-out): **93.42% (SD 1.04)** raw, **90.46% (SD 2.18)** as the NLP layer renders them

- Training rows: **820** | pipeline-aligned: **True**
- Diseases in model: **41** | Symptom features: **131**

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

> "On a held-out test set of 772 synthetic patient transcripts aligned to the disease–symptom
> archive, covering 41 conditions, the NLP pipeline achieved 100.0% top-1 and
> 100.0% top-3 disease label agreement under production analysis settings.
> Hiligaynon transcripts (456 cases) achieved 100.0% top-1 agreement.
> Across the full held-out split of 9029 transcripts, which additionally covers conversational
> patient typing and chief-complaint intake text, top-1 agreement was 93.72%.
> Results support feasibility as **clinical decision support**; they do not establish diagnostic accuracy
> in live practice."

## Reproduce

```bat
python scripts/dev/nlp_thesis_eval_report.py
python scripts/dev/evaluate_patient_ml_cases.py --split test --case-id-prefix PC- --source archive_source/dataset.csv --path both
```

Full metrics JSON: `docs/nlp_thesis_eval_results.json`
