"""
Merge curated Hiligaynon phrases into data/nlp/hiligaynon_symptom_lexicon.json.

Sources (in order):
  1. data/nlp/hiligaynon_symptom_phrases.json  (authoritative round-trip table)
  2. HILIGAYNON_SYMPTOM_PHRASES in build_patient_training_dataset.py

Run after editing phrases:
    python scripts/data/sync_training_phrases_to_lexicon.py
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts" / "data"))

from build_patient_training_dataset import HILIGAYNON_SYMPTOM_PHRASES  # noqa: E402

LEXICON = ROOT / "data" / "nlp" / "hiligaynon_symptom_lexicon.json"
PHRASE_FILE = ROOT / "data" / "nlp" / "hiligaynon_symptom_phrases.json"


def _merge(symptoms: dict, key: str, phrases: list[str]) -> int:
    entry = symptoms.get(key)
    if not isinstance(entry, dict):
        entry = {
            "english": key.replace("_", " "),
            "medical_term": key,
            "category": "general",
            "hiligaynon": [],
        }
        symptoms[key] = entry
    hil = list(entry.get("hiligaynon") or [])
    existing = {p.lower().strip() for p in hil}
    added = 0
    for phrase in phrases:
        p = phrase.strip()
        if p and p.lower() not in existing:
            hil.append(p)
            existing.add(p.lower())
            added += 1
    entry["hiligaynon"] = hil
    return added


def main() -> None:
    data = json.loads(LEXICON.read_text(encoding="utf-8"))
    symptoms = data.setdefault("symptoms", {})
    added = 0

    if PHRASE_FILE.is_file():
        payload = json.loads(PHRASE_FILE.read_text(encoding="utf-8"))
        for key, phrases in (payload.get("phrases") or {}).items():
            added += _merge(symptoms, key, list(phrases))

    for key, phrases in HILIGAYNON_SYMPTOM_PHRASES.items():
        added += _merge(symptoms, key, list(phrases))

    LEXICON.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Updated {LEXICON}")
    print(f"Added {added} new Hiligaynon variant(s). Restart AI service to reload lexicon.")


if __name__ == "__main__":
    main()
