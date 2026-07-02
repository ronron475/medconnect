# Hiligaynon Medical Symptoms CSV

**File:** `hiligaynon_medical_symptoms.csv`  
**Entries:** 850+ unique Hiligaynon terms (no duplicate `hiligaynon_term` rows)

## Columns

| Column | Description |
|--------|-------------|
| `hiligaynon_term` | Primary Hiligaynon/Ilonggo symptom or phrase |
| `alternative_spellings` | Semicolon-separated synonyms and spelling variants |
| `english_translation` | Patient-friendly English label |
| `medical_term` | Standardized clinical term (SNOMED/ICD-style) |
| `category` | Medical specialty category |
| `severity_level` | Low, Medium, High, or Critical (triage hint) |
| `confidence_keywords` | Semicolon-separated related English keywords for fuzzy/NLP matching |

## Categories covered

- Dermatology
- Respiratory
- Gastroenterology
- Neurological
- Cardiovascular
- Urology
- Musculoskeletal
- Ophthalmology
- Otology
- Mental Health
- General Medicine
- Women's Health
- Pediatric
- Infectious Disease
- Emergency

## Regenerate

```bash
python scripts/data/build_hiligaynon_medical_symptoms_csv.py
```

Edit `scripts/data/build_hiligaynon_medical_symptoms_csv.py` to add new symptom groups, then re-run the script.

## Example row

```csv
kakatol,"kakatul;katol;makatol;ga katol",itchiness,pruritus,Dermatology,Low,"itch;skin;allergy;rash;scratch"
```

## Use cases

- NLP symptom extraction
- RapidFuzz fuzzy matching (80–90% threshold)
- Hiligaynon → English translation
- Medical triage severity classification
- AI consultation transcript analysis
