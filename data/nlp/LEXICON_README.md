# Hiligaynon Symptom Lexicon

Admin-expandable JSON lexicon for teleconsultation and triage NLP.

**File:** `data/nlp/hiligaynon_symptom_lexicon.json`

## Add symptoms without code changes

Edit the JSON file and add or extend an entry:

```json
"pruritus": {
  "english": "itchiness",
  "medical_term": "pruritus",
  "category": "skin",
  "hiligaynon": [
    "kakatol",
    "kakatul",
    "katol",
    "makatol"
  ],
  "alternate_spellings": [
    "ga katol",
    "nagakakatol"
  ]
}
```

Restart the Python AI service to clear in-memory cache, or call `symptom_lexicon_loader.clear_cache()` in a reload hook.

## Settings

| Field | Default | Description |
|-------|---------|-------------|
| `fuzzy_threshold` | 85 | RapidFuzz WRatio acceptance (80–90 recommended) |
| `fuzzy_threshold_min` | 80 | Lower bound |
| `fuzzy_threshold_max` | 90 | Upper bound |

## API

- Python: `POST http://127.0.0.1:8765/recognize-symptoms` body `{"text": "..."}`
- PHP: `POST /app/api/ai/recognize_symptoms.php` (authenticated)

## Output per detection

```json
{
  "detected_symptom": "kakatul",
  "normalized_symptom": "kakatol",
  "english_translation": "itchiness",
  "medical_term": "pruritus",
  "category": "skin",
  "confidence": 92,
  "match_method": "fuzzy"
}
```
