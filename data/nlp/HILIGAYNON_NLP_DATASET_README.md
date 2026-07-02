# Hiligaynon Medical NLP Dataset (10,000+ rows)

**File:** `hiligaynon_medical_nlp_dataset.csv`  
**Generator:** `scripts/data/build_hiligaynon_nlp_dataset.py`

## Columns

| Column | Description |
|--------|-------------|
| `id` | Unique row ID |
| `hiligaynon_term` | Primary Hiligaynon/Ilonggo phrase |
| `alternative_spellings` | Semicolon-separated synonyms and spelling variants |
| `english_translation` | Patient-friendly English label |
| `medical_term` | Standardized clinical term (SNOMED/ICD-style) |
| `medical_category` | Specialty (Dermatology, Respiratory, etc.) |
| `body_system` | Body system (integumentary, respiratory, nervous, etc.) |
| `severity` | Low, Medium, High, or Critical (triage hint) |
| `symptom_keywords` | Semicolon-separated English symptom keywords |
| `confidence_keywords` | Semicolon-separated fuzzy/NLP matching keywords |

## Regenerate

```bash
py scripts/data/build_hiligaynon_nlp_dataset.py
```

## Categories

- Dermatology (itchiness, rash, hair loss, hives)
- Respiratory (cough, rhinitis, dyspnea, wheezing)
- Cardiovascular (chest pain, palpitations, angina)
- Neurological (headache, dizziness, seizure, stroke)
- Gastroenterology / Digestive (abdominal pain, diarrhea, nausea)
- Urology / Urinary (dysuria, hematuria, retention)
- Musculoskeletal (back pain, weakness, myalgia)
- Ophthalmology / Otology (red eyes, ear pain, hearing loss)
- Mental Health (anxiety, depression, insomnia)
- Emergency (severe bleeding, unconsciousness, heart attack)

## Integration

The dataset is loaded automatically by:

- `HiligaynonNlpDataset.php` / `hiligaynon_nlp_dataset_loader.py`
- `SymptomLexicon` variant index (merged with JSON lexicon)
- `MedicalTermFilter` medical term lexicon
- `MedicalTranslator` / `medical_translator.py` translation
- `NlpPreprocessor` keyword extraction and English preview
- `HiligaynonSymptomMatcher` phrase + fuzzy recognition

## Example rows

```csv
galagas buhok,...,hair loss,alopecia,Dermatology,integumentary,Low,hair;loss;alopecia,hair;balding;alopecia;shedding
kakatul lawas,...,itchiness,pruritus,Dermatology,integumentary,Low,itch;body;skin,itch;body;skin;pruritus
sakit sang dughan,...,chest pain,chest pain,Cardiovascular,cardiovascular,Critical,chest pain;angina,chest;heart;pain;emergency
```

## Required phrases (always included)

- galagas buhok → hair loss / alopecia
- kakatul lawas → itchiness / pruritus
- sakit sang dughan → chest pain
- kalipong → dizziness
- luya lawas → body weakness
- sakit sang ulo → headache
- budlay ginhawa → shortness of breath
- masakit mag ihi → painful urination
- kapoy gid ko → fatigue
