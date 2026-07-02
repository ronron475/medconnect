# NLP reference datasets

Official healthcare terminology for registration validation, fuzzy matching, and autocomplete.

## Medical conditions (ICD-10-CM)

| Source | CMS **Code Descriptions in Tabular Order** (NCHS/CDC ICD-10-CM) |
| Fields | `icd10_code`, `condition_name`, `icd10_category`, `chapter_code`, `chapter_title`, `long_description` |
| Billable codes only | Header rows excluded (`is_billable = 1`) |
| No duplicates | Unique ICD-10 code and condition name |

**Build:**

```bash
python scripts/data/build_icd10_conditions.py
```

**Output:**

- `data/nlp/icd10/medical_conditions_part_XX.csv` (10,000 rows per file)
- `data/nlp/medical_conditions.csv` (NLP/PHP export with `condition_id`, `category`, `description`)

## Symptoms (comprehensive healthcare database)

| Sources | [MedlinePlus Symptoms Index](https://medlineplus.gov/symptoms.html) (NLM), CMS ICD-10-CM Chapter XVIII (R00–R94), [Human Phenotype Ontology](https://hpo.jax.org/), curated clinical terms |
| Fields | `symptom_id`, `symptom_name`, `category`, `description`, `related_body_system` |
| Categories | respiratory, cardiovascular, gastrointestinal, neurological, mental_health, dermatological, musculoskeletal, urinary, reproductive, endocrine_metabolic, infectious, hematologic, sensory, pediatric, geriatric, pain, general, environmental, oral |
| Body systems | respiratory, cardiovascular, gastrointestinal, nervous, mental_behavioral, integumentary, musculoskeletal, urinary, reproductive, endocrine, immune, hematologic, special_senses, genitourinary, oral, general |
| Coverage | Common and uncommon symptoms; acute/chronic variants (ICD-10); physical, mental, and neurological signs; pediatric and geriatric syndromes; patient-friendly names and clinical terminology |
| Rules | Deduplicated normalized names; standardized medical terminology; suitable for registration, symptom checking, NLP, keyword extraction, fuzzy matching, and triage |

**Build** (requires `medical_conditions.csv` for ICD-10 R codes):

```bash
python scripts/data/build_comprehensive_symptoms.py
```

MedlinePlus-only rebuild (legacy, smaller dataset):

```bash
python scripts/data/build_medlineplus_symptoms.py
```

**Output:**

- `data/nlp/symptoms/symptoms_part_XX.csv`
- `data/nlp/symptoms.csv`

Attribution: National Library of Medicine — [MedlinePlus.gov](https://medlineplus.gov/); CMS/NCHS ICD-10-CM; Human Phenotype Ontology

## Allergies (clinical reference)

| Categories | medication, food, environmental, insect, latex, chemical |
| Standards | FDA major allergens, drug allergy nomenclature, environmental/insect/latex/chemical sensitizer lists |
| Rules | No duplicates, no standalone abbreviations, no non-medical filler terms |

**Build:**

```bash
python scripts/data/build_allergies_official.py
```

**Output:**

- `data/nlp/allergies/allergies_part_XX.csv`
- `data/nlp/allergies.csv`

## MySQL import

1. Create tables:

```bash
mysql -u root -p medconnect < database/schema_nlp_reference.sql
```

2. Build CSV files (see above).

3. Import:

```bash
php scripts/data/import_nlp_datasets.php --truncate
```

Or Python (requires `mysql-connector-python`):

```bash
python scripts/data/import_nlp_datasets.py
```

Windows one-step build:

```bat
scripts\data\build_all_datasets.bat
```

## Python AI service (avoid PHP fallback)

1. First time (or after moving the project):

```bat
ai_service\install_ai_dependencies.bat
```

2. Start before using NLP demo or teleconsultation AI:

```bat
start_ai_service.bat
```

3. Verify: open `http://127.0.0.1:8765/health` or run `php scripts/dev/check_ai_service.php`

## Autocomplete queries

```sql
-- Conditions
SELECT condition_name, icd10_code, icd10_category
FROM nlp_medical_conditions
WHERE search_name LIKE CONCAT('%', ?, '%')
   OR condition_name LIKE CONCAT('%', ?, '%')
LIMIT 20;

-- Allergies
SELECT allergy_name, category
FROM nlp_allergies
WHERE search_name LIKE CONCAT('%', ?, '%')
LIMIT 20;
```

## Regenerating from CMS

The ICD-10 builder downloads the current fiscal-year ZIP from [CMS ICD-10-CM files](https://www.cms.gov/medicare/coding-billing/icd-10-codes). Cached source text is stored in `data/nlp/source/`.
