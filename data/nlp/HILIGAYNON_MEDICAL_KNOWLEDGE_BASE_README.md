# Hiligaynon Medical Knowledge Base (Master NLP KB)

**File:** `hiligaynon_medical_knowledge_base.csv`  
**Generator:** `scripts/data/build_hiligaynon_medical_knowledge_base.py`  
**Records:** 25,000+

## Purpose

The largest Hiligaynon medical NLP knowledge base for AI video consultation, symptom checking, clinical decision support, and triage. Captures **real patient language** — slang, misspellings, mixed Hiligaynon-English-Tagalog, incomplete sentences, and emotional descriptions.

## Columns

| Column | Description |
|--------|-------------|
| `id` | Unique record ID |
| `patient_statement` | Full natural patient complaint |
| `normalized_symptom` | Core symptom token |
| `english_translation` | Patient-friendly English |
| `medical_term` | Clinical term |
| `icd_category` | Specialty + ICD chapter hint |
| `body_system` | Body system |
| `urgency_level` | Low / Medium / High / Critical |
| `possible_conditions` | Differential diagnoses |
| `alternative_spellings` | Spelling/slang variants |
| `related_symptoms` | Associated symptoms |
| `confidence_keywords` | Fuzzy/NLP keywords |

## Specialties covered

General Medicine, Dermatology, Cardiology, Pulmonology, Gastroenterology, Neurology, Psychiatry, Pediatrics, Geriatrics, Orthopedics, Ophthalmology, ENT, Urology, Gynecology, Infectious Disease, Endocrinology, Oncology, Emergency Medicine

## Augmentation per symptom

- ~50 spelling variants
- ~50 sentence variations
- ~20 slang variations
- ~20 mixed-language inputs
- ~20 typo variations
- ~20 telemedicine dialogue examples
- Emotional descriptions (`daw maluya gid ko`, `daw lain gid pamatyag ko`)

## Regenerate

```bash
py scripts/data/build_hiligaynon_medical_knowledge_base.py
```

## Related datasets

| File | Records | Focus |
|------|---------|-------|
| `hiligaynon_medical_knowledge_base.csv` | 25,000+ | Master KB — patient statements |
| `hiligaynon_patient_complaints.csv` | 12,500+ | Telemedicine complaints |
| `hiligaynon_medical_nlp_dataset.csv` | 11,700+ | Dictionary-style terms |

All three are merged into the live NLP pipeline.

## Integration

- `HiligaynonMedicalKnowledgeBase.php` / `hiligaynon_medical_knowledge_base_loader.py`
- Primary source in `SymptomLexicon`, `MedicalTranslator`, `NlpPreprocessor`
- Merged with patient complaints + NLP dictionary datasets

## Example

```csv
1,"Masakit gid akon ulo subong",sakit ulo,headache,cephalalgia,Neurology (G89),nervous,Medium,migraine;tension headache,masakit ulo;sakit sang ulo,nausea;dizziness,ulo;headache;pain
```
