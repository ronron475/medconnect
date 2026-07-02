# Hiligaynon Patient Complaints Dataset

**File:** `hiligaynon_patient_complaints.csv`  
**Generator:** `scripts/data/build_hiligaynon_patient_complaints.py`  
**Rows:** 12,500+ realistic telemedicine complaints

## Purpose

Captures how Hiligaynon (Ilonggo) patients **actually speak** during online video consultations — conversational language, slang, misspellings, dialect variants, and mixed Hiligaynon/English — rather than formal medical dictionary terms.

## Columns

| Column | Description |
|--------|-------------|
| `id` | Unique row ID |
| `patient_complaint_hiligaynon` | Full natural patient complaint phrase |
| `normalized_symptom` | Core symptom token (e.g. `sakit ulo`) |
| `english_translation` | Patient-friendly English |
| `medical_term` | Clinical term (e.g. `cephalalgia`, `dyspnea`) |
| `body_system` | nervous, respiratory, digestive, etc. |
| `urgency_level` | Low / Medium / High / Critical |
| `alternative_spellings` | Semicolon-separated spelling/slang variants |
| `possible_conditions` | Semicolon-separated differential diagnoses |
| `confidence_keywords` | Fuzzy/NLP matching keywords |

## Categories

- Headache, Respiratory, Digestive, Chest pain
- Skin, Hair, Urinary, Musculoskeletal
- Neurological, Mental health, Eyes, Ears, Fever
- Emergency (severe dyspnea, unconsciousness, seizure, bleeding, stroke)

## Regenerate

```bash
py scripts/data/build_hiligaynon_patient_complaints.py
```

## Example rows

```csv
1,"Masakit gid akon ulo subong",sakit ulo,headache,cephalalgia,nervous,Medium,"masakit ulo;sakit sang ulo",migraine;tension headache,ulo;headache;pain
2,"Budlay gid akon ginhawa",difficulty breathing,shortness of breath,dyspnea,respiratory,High,"ginakapos ginhawa;budlay ginhawa",asthma;COPD;pneumonia,breathing;lungs
```

## Integration

Loaded automatically by:

- `HiligaynonPatientComplaints.php` / `hiligaynon_patient_complaints_loader.py`
- Merged into `SymptomLexicon` variant index
- `MedicalTermFilter`, `MedicalTranslator`, `NlpPreprocessor`
- `HiligaynonSymptomMatcher` phrase + fuzzy recognition

Works alongside `hiligaynon_medical_nlp_dataset.csv` (dictionary-style terms).

## Design features

- 25–50+ variations per symptom group
- Conversational templates (`daw`, `gid`, `subong`, `pirmi`, `grabe`)
- Spelling variants (`kakatol`/`kakatul`, `sip-on`/`sipon`, `tiyan`/`tyan`)
- Mixed Hiligaynon + English (`May fever ko`, `Shortness of breath gid ko`)
- Incomplete/shorthand phrases (`ubo gid`, `kalipong ko subong`)
- Telemedicine compounds (`doctor, {complaint}`, `help, {complaint}`)
