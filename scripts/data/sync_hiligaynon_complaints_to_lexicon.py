"""
Merge data/nlp/hiligaynon_patient_complaints.csv into the symptom lexicon.

Adds full complaint lines and alternative_spellings as Hiligaynon variants when they
map to a classifier symptom key (via medical_term / normalized_symptom).

Run:
    python scripts/data/sync_hiligaynon_complaints_to_lexicon.py
"""

from __future__ import annotations

import csv
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPLAINTS = ROOT / "data" / "nlp" / "hiligaynon_patient_complaints.csv"
LEXICON = ROOT / "data" / "nlp" / "hiligaynon_symptom_lexicon.json"
PHRASE_FILE = ROOT / "data" / "nlp" / "hiligaynon_symptom_phrases.json"
META = ROOT / "ai_service" / "models" / "disease_classifier_meta.json"


def normalize_key(raw: str) -> str:
    cleaned = re.sub(r"\s+", "_", (raw or "").strip().lower())
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    return cleaned.strip("_")


def main() -> None:
    if not COMPLAINTS.is_file():
        raise SystemExit(f"Missing {COMPLAINTS}")

    meta = json.loads(META.read_text(encoding="utf-8"))
    valid_keys = set(meta.get("symptom_columns") or [])

    # English medical_term → archive symptom key (when names differ).
    term_to_key: dict[str, str] = {}
    for key in valid_keys:
        term_to_key[normalize_key(key)] = key
        term_to_key[normalize_key(key.replace("_", " "))] = key
    term_to_key["chest_pain"] = "chest_pain"
    term_to_key["runny_nose"] = "runny_nose"
    term_to_key["rhinorrhea"] = "runny_nose"

    lex = json.loads(LEXICON.read_text(encoding="utf-8"))
    symptoms = lex.setdefault("symptoms", {})
    phrases_payload = json.loads(PHRASE_FILE.read_text(encoding="utf-8"))
    phrases = phrases_payload.setdefault("phrases", {})

    lex_added = 0
    phrase_added = 0

    with COMPLAINTS.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            term = normalize_key(row.get("medical_term") or row.get("normalized_symptom") or "")
            key = term_to_key.get(term) or term_to_key.get(normalize_key(row.get("normalized_symptom") or ""))
            if not key or key not in valid_keys:
                continue

            candidates: list[str] = []
            complaint = (row.get("patient_complaint_hiligaynon") or "").strip()
            if complaint and len(complaint) >= 8:
                candidates.append(complaint)
            for alt in (row.get("alternative_spellings") or "").split(";"):
                alt = alt.strip()
                if alt and len(alt) >= 4:
                    candidates.append(alt)

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
            existing = {p.lower() for p in hil}
            for phrase in candidates:
                if phrase.lower() == "vertigo" and key == "dizziness":
                    continue
                if phrase.lower() not in existing:
                    hil.append(phrase)
                    existing.add(phrase.lower())
                    lex_added += 1
            entry["hiligaynon"] = hil

            # Short phrases (not whole complaints) also feed the round-trip phrase table.
            short_pool = [
                p for p in candidates if len(p.split()) <= 6 and len(p) >= 5
            ]
            if short_pool:
                cur = list(phrases.get(key, []))
                ex2 = {p.lower() for p in cur}
                for phrase in short_pool[:4]:
                    if phrase.lower() not in ex2:
                        cur.append(phrase)
                        ex2.add(phrase.lower())
                        phrase_added += 1
                phrases[key] = cur

    LEXICON.write_text(json.dumps(lex, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    PHRASE_FILE.write_text(
        json.dumps(phrases_payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(f"Updated lexicon (+{lex_added} variants)")
    print(f"Updated phrase file (+{phrase_added} short phrases)")


if __name__ == "__main__":
    main()
