# Clinical Triage CDS (v3)

Evidence-based, rule-driven triage decision support for medConnect.

**Not a chatbot. Not a diagnostic engine. Not a prescribing system.**

## Pipeline

```
Patient Input
→ Normalize Text
→ Correct Misspellings
→ Detect Language
→ Translate Hiligaynon/Filipino → English
→ Tokenize / Remove stopwords
→ Extract Symptoms
→ Extract Duration / Pain Scale / Temperature
→ Extract Risk Factors / Age Group
→ Detect Emergency Red Flags
→ Calculate Severity Score
→ Determine Highest Priority
→ Generate Explanation
→ Return Recommendation
```

## Knowledge bases (code-free extensibility)

| File | Purpose |
|------|---------|
| `data/nlp/symptom_knowledge_base.json` | Symptoms, weights, synonyms, Hiligaynon/Filipino terms, actions |
| `data/nlp/red_flags_library.json` | Emergency red flags with mild-exclusion policy |
| `data/nlp/emergency_flags.csv` | Legacy/expanded red-flag patterns (still scanned) |

Add new symptoms/synonyms/rules in JSON without changing NLP code.

## Severity bands

| Score | Classification |
|------:|----------------|
| 0–5 | NON-URGENT |
| 6–11 | URGENT |
| 12+ | EMERGENCY |

**Red flags always override** the numeric score (unless clearly mild per library exclusions).

## Confidence rule

If confidence **&lt; 60%**, recommendation becomes:

`Needs Healthcare Provider Review`

## Engines

- Python: `ai_service/clinical_triage_engine.py` + `hiligaynon_medical_nlp_pipeline.py`
- PHP fallback: `app/core/ClinicalTriageEngine.php` + `HiligaynonMedicalNlpPipeline.php`

## Canonical JSON output

Returned as `clinical_recommendation` / `recommendation_payload`:

```json
{
  "chief_complaint": "I have fever",
  "detected_symptoms": ["Fever"],
  "duration": "Today",
  "red_flags": [],
  "risk_factors": [],
  "severity_score": 2,
  "classification": "NON-URGENT",
  "priority": "Normal",
  "confidence": 97,
  "reason": "...",
  "recommendation": "Schedule the patient for a regular consultation."
}
```

## Smoke test

```bat
ai_service\.venv\Scripts\python.exe scripts\dev\smoke_clinical_triage_fast.py
```

## Clinical references (guidance only)

WHO, CDC, ACEP, NHS, ESI, CTAS, Philippine DOH — used to shape rules; text is not copied from those sources.
