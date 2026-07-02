# Hiligaynon Pain Recognition Dataset

**File:** `hiligaynon_pain_recognition.csv`  
**Generator:** `scripts/data/build_hiligaynon_pain_recognition.py`  
**Records:** 2,700+ (100+ per body part category)

## Purpose

Dedicated dataset to normalize all Hiligaynon pain complaints into standardized English symptoms and medical concepts.

## Columns

| Column | Description |
|--------|-------------|
| `id` | Unique record ID |
| `hiligaynon_complaint` | Pain complaint phrase |
| `normalized_symptom` | Standardized symptom (e.g. `head pain`, `eye pain`) |
| `english_translation` | English label (e.g. `headache`, `toothache`) |
| `medical_term` | Clinical term (e.g. `cephalalgia`, `odontalgia`) |
| `body_part` | head, eye, ear, tooth, throat, chest, neck, shoulder, arm, hand, back, abdomen, hip, leg, knee, foot, joint, muscle, body |
| `pain_category` | neurological pain, ocular pain, cardiac pain, etc. |
| `severity_level` | low / medium / high |
| `alternative_spellings` | Semicolon-separated variants |

## Body parts covered (19 categories)

Head, Eye, Ear, Tooth, Throat, Chest, Neck, Shoulder, Arm, Hand, Back, Abdomen, Hip, Leg, Knee, Foot, Joint, Muscle, Full Body

## Variation types per category

- 100+ complaint variations each
- Spelling mistakes (`tyan`/`tiyan`, `dulunggan`/`dalunggan`)
- Slang (`kirot`, `hapdi`, `gapigos`, `gakurot`)
- Typos (`masaket`, `saket`)
- Mixed Hiligaynon-English (`headache gid ko`, `chest pain gid ko`)
- Full sentences (`Masakit gid akon mata`, `Sakit gid ulo ko`)

## Normalization examples

| Input | normalized_symptom | english_translation | medical_term |
|-------|-------------------|----------------------|--------------|
| Masakit gid akon mata | eye pain | eye pain | ophthalmalgia |
| Sakit gid ulo ko | head pain | headache | cephalalgia |
| Gapigos gid dughan ko | chest pain | chest pain | chest pain |
| Masakit likod ko gid | back pain | back pain | dorsalgia |

## Regenerate

```bash
py scripts/data/build_hiligaynon_pain_recognition.py
```

## Integration

- `HiligaynonPainRecognition.php` / `hiligaynon_pain_recognition_loader.py`
- **Priority source** for pain lookups in `MedicalTranslator`, `NlpPreprocessor`, `SymptomLexicon`
- Merged with medical knowledge base, patient complaints, and NLP dictionary datasets
