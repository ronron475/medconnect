"""
Build patient-style training cases from archive_source/dataset.csv.

Generates natural-language transcripts (English + Hiligaynon) mapped to diseases
and canonical symptom keys for ML evaluation and future NLP training.

Run:
    python scripts/data/build_patient_training_dataset.py

Output:
    data/nlp/training/patient_cases.csv
    data/nlp/training/patient_cases.jsonl
"""

from __future__ import annotations

import csv
import json
import random
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARCHIVE = ROOT / "data" / "nlp" / "archive_source"
DICTIONARY = ROOT / "data" / "nlp" / "medical_dictionary.csv"
OUT_DIR = ROOT / "data" / "nlp" / "training"
OUT_CSV = OUT_DIR / "patient_cases.csv"
OUT_JSONL = OUT_DIR / "patient_cases.jsonl"

# Extra Hiligaynon/Ilonggo patient phrases for archive symptom keys.
HILIGAYNON_SYMPTOM_PHRASES: dict[str, list[str]] = {
    "high_fever": ["hilanat", "ginahilanat", "mataas nga hilanat"],
    "mild_fever": ["hilanat", "gamay nga hilanat"],
    "cough": ["ubo"],
    "chest_pain": ["sakit dughan", "hapdi dughan"],
    "breathlessness": ["budlay ginhawa", "ginabudlayan ginhawa"],
    "headache": ["sakit ulo", "labad ulo"],
    "vomiting": ["suka", "nagsuka"],
    "nausea": ["nahilo", "nagasuka"],
    "diarrhoea": ["kalibanga"],
    "stomach_pain": ["sakit tiyan", "panakit tiyan"],
    "abdominal_pain": ["sakit tiyan"],
    "belly_pain": ["sakit tiyan"],
    "fatigue": ["kakapoy", "ginakapoy"],
    "dizziness": ["kalipong", "nagalipong"],
    "skin_rash": ["rashes", "hubag sa panit"],
    "itching": ["katol", "ginakatol"],
    "joint_pain": ["sakit lutahan"],
    "back_pain": ["sakit likod"],
    "runny_nose": ["sip-on", "sip on"],
    "throat_irritation": ["sakit tutunlan"],
    "chills": ["ginapanas-an", "mabinugnaw"],
    "continuous_sneezing": ["sip-on", "ubo"],
    "watering_from_eyes": ["nagaluha ang mata"],
    "shivering": ["nagakurog"],
}

EN_TEMPLATES = [
    "I have been having {symptoms} for a few days.",
    "The patient complains of {symptoms}.",
    "My symptoms include {symptoms}.",
    "For the past week I have experienced {symptoms}.",
    "I came in because of {symptoms}.",
    "Lately I feel {symptoms}.",
]

HIL_TEMPLATES = [
    "May {symptoms} ako.",
    "Ang pasyente may {symptoms}.",
    "Nagabalati ako sang {symptoms}.",
    "Subong may {symptoms} ang pasyente.",
    "Kag {symptoms} ko sa sulod sang isa ka semana.",
]


def normalize_symptom(raw: str) -> str:
    cleaned = re.sub(r"\s+", "_", (raw or "").strip().lower())
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    return cleaned.strip("_")


def symptom_to_english_phrase(key: str) -> str:
    return key.replace("_", " ")


def load_dictionary_hiligaynon() -> dict[str, list[str]]:
    mapping: dict[str, list[str]] = {}
    if not DICTIONARY.is_file():
        return mapping
    with DICTIONARY.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            english = (row.get("english_term") or "").strip().lower()
            local = (row.get("local_term") or "").strip()
            if not english or not local:
                continue
            mapping.setdefault(english, [])
            if local not in mapping[english]:
                mapping[english].append(local)
    return mapping


def hiligaynon_phrase_for_symptom(key: str, dictionary: dict[str, list[str]]) -> str:
    if key in HILIGAYNON_SYMPTOM_PHRASES:
        return random.choice(HILIGAYNON_SYMPTOM_PHRASES[key])
    english = symptom_to_english_phrase(key)
    if english in dictionary:
        return random.choice(dictionary[english])
    for term, locals in dictionary.items():
        if term in english or english in term:
            return random.choice(locals)
    return english


def join_phrases(phrases: list[str], language: str) -> str:
    phrases = [p.strip() for p in phrases if p.strip()]
    if not phrases:
        return ""
    if len(phrases) == 1:
        return phrases[0]
    if language == "hiligaynon":
        if len(phrases) == 2:
            return f"{phrases[0]} kag {phrases[1]}"
        return ", ".join(phrases[:-1]) + f", kag {phrases[-1]}"
    return ", ".join(phrases[:-1]) + f" and {phrases[-1]}"


def load_disease_symptom_rows() -> list[tuple[str, tuple[str, ...]]]:
    dataset = ARCHIVE / "dataset.csv"
    if not dataset.is_file():
        raise FileNotFoundError(f"Missing dataset: {dataset}")

    unique: dict[tuple[str, tuple[str, ...]], None] = {}
    with dataset.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            disease = (row.get("Disease") or "").strip()
            if not disease:
                continue
            symptoms: list[str] = []
            for key, value in row.items():
                if not key.startswith("Symptom_"):
                    continue
                symptom = normalize_symptom(value)
                if symptom:
                    symptoms.append(symptom)
            if not symptoms:
                continue
            signature = (disease, tuple(sorted(set(symptoms))))
            unique[signature] = None
    return list(unique.keys())


def assign_split(disease: str, seed: int = 42) -> str:
    rng = random.Random(f"{seed}:{disease}")
    roll = rng.random()
    if roll < 0.7:
        return "train"
    if roll < 0.85:
        return "val"
    return "test"


def build_cases() -> list[dict[str, str | int]]:
    dictionary = load_dictionary_hiligaynon()
    rows = load_disease_symptom_rows()
    cases: list[dict[str, str | int]] = []
    case_id = 0

    for disease, symptom_keys in rows:
        english_phrases = [symptom_to_english_phrase(k) for k in symptom_keys]
        hil_phrases = [hiligaynon_phrase_for_symptom(k, dictionary) for k in symptom_keys]
        symptom_blob = ";".join(symptom_keys)

        for template_idx, template in enumerate(EN_TEMPLATES):
            case_id += 1
            cases.append(
                {
                    "case_id": f"PC-{case_id:05d}",
                    "disease": disease,
                    "language": "english",
                    "transcript": template.format(symptoms=join_phrases(english_phrases, "english")),
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"en_{template_idx + 1}",
                    "split": assign_split(disease),
                    "source": "archive_source/dataset.csv",
                }
            )

        for template_idx, template in enumerate(HIL_TEMPLATES):
            case_id += 1
            cases.append(
                {
                    "case_id": f"PC-{case_id:05d}",
                    "disease": disease,
                    "language": "hiligaynon",
                    "transcript": template.format(symptoms=join_phrases(hil_phrases, "hiligaynon")),
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"hil_{template_idx + 1}",
                    "split": assign_split(disease),
                    "source": "archive_source/dataset.csv",
                }
            )

        # Mixed teleconsultation-style line (Hiligaynon + English medicines optional)
        case_id += 1
        mixed = (
            f"May {join_phrases(hil_phrases[:2], 'hiligaynon')} ang pasyente. "
            f"Also reports {join_phrases(english_phrases[2:], 'english')}."
            if len(symptom_keys) > 2
            else f"May {join_phrases(hil_phrases, 'hiligaynon')} kag {join_phrases(english_phrases, 'english')}."
        )
        cases.append(
            {
                "case_id": f"PC-{case_id:05d}",
                "disease": disease,
                "language": "mixed",
                "transcript": mixed,
                "symptom_keys": symptom_blob,
                "symptom_count": len(symptom_keys),
                "template_id": "mixed_1",
                "split": assign_split(disease),
                "source": "archive_source/dataset.csv",
            }
        )

    return cases


def write_outputs(cases: list[dict[str, str | int]]) -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "case_id",
        "disease",
        "language",
        "transcript",
        "symptom_keys",
        "symptom_count",
        "template_id",
        "split",
        "source",
    ]

    with OUT_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(cases)

    with OUT_JSONL.open("w", encoding="utf-8") as handle:
        for row in cases:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def main() -> None:
    random.seed(42)
    cases = build_cases()
    write_outputs(cases)

    by_lang: dict[str, int] = {}
    by_split: dict[str, int] = {}
    diseases = set()
    for row in cases:
        by_lang[str(row["language"])] = by_lang.get(str(row["language"]), 0) + 1
        by_split[str(row["split"])] = by_split.get(str(row["split"]), 0) + 1
        diseases.add(str(row["disease"]))

    print("medConnect patient training dataset")
    print("===================================")
    print(f"Output CSV:   {OUT_CSV}")
    print(f"Output JSONL: {OUT_JSONL}")
    print(f"Total cases:  {len(cases)}")
    print(f"Diseases:     {len(diseases)}")
    print(f"By language:  {by_lang}")
    print(f"By split:     {by_split}")
    print()
    print("Evaluate ML pipeline:")
    print("  python scripts/dev/evaluate_patient_ml_cases.py")


if __name__ == "__main__":
    main()
