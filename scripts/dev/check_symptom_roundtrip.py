"""
Verify every dataset symptom survives the NLP pipeline in both languages.

A symptom that cannot be recovered from its own rendered text is invisible to the
classifier no matter how long it trains, so this guards the data/NLP contract:

  1. canonical English wording  -> symptom key
  2. curated Hiligaynon phrase  -> symptom key
  3. generator's chosen phrase  -> symptom key

Run:
    python scripts/dev/check_symptom_roundtrip.py
Exit code 1 if any symptom fails, so it can gate a build.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))
sys.path.insert(0, str(ROOT / "scripts" / "data"))

from analyzer import translate_hiligaynon  # noqa: E402
from disease_predictor import extract_model_symptoms, load_model_meta  # noqa: E402
from build_patient_training_dataset import (  # noqa: E402
    CURATED_SYMPTOM_PHRASES,
    hiligaynon_phrase_for_symptom,
    load_dictionary_hiligaynon,
    symptom_to_english_phrase,
)


def detected(text: str) -> list[str]:
    return extract_model_symptoms(translate_hiligaynon(text))


def main() -> int:
    meta = load_model_meta() or {}
    columns = list(meta.get("symptom_columns") or [])
    if not columns:
        print("No trained model metadata found; train the classifier first.")
        return 1

    dictionary = load_dictionary_hiligaynon()
    failures: list[tuple[str, str, str, list[str]]] = []

    variants: dict[str, list[str]] = {}
    variant_file = ROOT / "data" / "nlp" / "hiligaynon_symptom_variants.json"
    if variant_file.is_file():
        variants = json.loads(variant_file.read_text(encoding="utf-8")).get("variants") or {}

    for key in columns:
        checks: list[tuple[str, str]] = [("english", symptom_to_english_phrase(key))]
        for phrase in CURATED_SYMPTOM_PHRASES.get(key, []):
            checks.append(("hiligaynon", phrase))
        for phrase in variants.get(key, []):
            checks.append(("variant", phrase))
        # Sample the generator a few times since it picks among variants at random.
        for _ in range(6):
            checks.append(("generated", hiligaynon_phrase_for_symptom(key, dictionary)))

        for kind, text in checks:
            found = detected(text)
            if key not in found:
                failures.append((key, kind, text, found))

    checked = len(columns)
    variant_count = sum(len(v) for v in variants.values())
    if not failures:
        print(
            f"All {checked} symptoms round-trip in English and Hiligaynon "
            f"({variant_count} spelling/prefix variants included)."
        )
        return 0

    print(f"{len(failures)} failing renderings across {checked} symptoms:")
    seen: set[tuple[str, str]] = set()
    for key, kind, text, found in failures:
        if (key, text) in seen:
            continue
        seen.add((key, text))
        print(f"  {key:30} [{kind:10}] {text!r:44} -> {found}")
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
