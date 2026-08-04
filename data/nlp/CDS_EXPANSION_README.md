# CDS NLP Expansion (compatible with existing medConnect NLP)

This expansion **enhances** the existing PHP/Python triage NLP. It does **not** replace architecture, UI, or engines.

## Regenerate datasets

```bat
ai_service\.venv\Scripts\python.exe scripts\data\build_cds_expansion_datasets.py
```

## Key expandable CSVs

| File | Role |
|------|------|
| `medical_symptoms.csv` | Symptom vocabulary |
| `english_medical_terms.csv` | English terms |
| `filipino_medical_terms.csv` | Filipino terms |
| `hiligaynon_medical_terms.csv` | Hiligaynon terms |
| `symptom_synonyms.csv` | Synonym map (runtime + training) |
| `negation_words.csv` | Negation patterns |
| `misspellings.csv` / `medical_misspellings.csv` | Spelling corrections |
| `emergency_red_flags.csv` | Emergency rules |
| `urgent_conditions.csv` / `non_urgent_conditions.csv` | Priority catalogs |
| `duration_patterns.csv` / `temperature_patterns.csv` / `pain_scale.csv` | Clinical extractors |
| `risk_factors.csv` / `chronic_conditions.csv` | Risk analysis |
| `symptom_combinations.csv` | Combination rules |
| `chief_complaint_examples.csv` | Training complaints |
| `translation_dictionary.csv` | Local→English map |
| `symptom_knowledge_base.json` | Severity weights + multilingual synonyms |
| `red_flags_library.json` | Structured red flags |

Existing ICD `medical_conditions.csv`, `triage_rules.csv`, `emergency_flags.csv`, and `body_parts.csv` are **preserved**.

## Runtime enhancements (no architecture change)

- `NegationDetector` — denied symptoms are not extracted
- `MedicalMisspellingsLoader` — loads expanded misspellings + abbreviations
- `SymptomKnowledgeBase` — boosts synonyms from Hiligaynon/Filipino CSV banks
- `ClinicalTriageEngine` — still the triage authority (NON-URGENT / URGENT / EMERGENCY)

## Rules

- Never diagnose
- Never prescribe
- Red flags override score
- Confidence &lt; 60% → `Needs Healthcare Provider Review`
- Add new terms/rules by editing CSV/JSON only
