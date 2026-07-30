"""
Build disease-labeled scenarios from hiligaynon_patient_complaints.csv.

Maps possible_conditions to archive diseases and symptom keys from medical_term.
Output merges into patient_cases via merge_patient_training_corpus.py.

Run:
    python scripts/data/build_hiligaynon_complaint_scenarios.py
"""

from __future__ import annotations

import csv
import json
import random
import re
from pathlib import Path

from build_patient_training_dataset import assign_split, load_disease_symptom_rows, normalize_symptom

ROOT = Path(__file__).resolve().parents[2]
COMPLAINTS = ROOT / "data" / "nlp" / "hiligaynon_patient_complaints.csv"
ARCHIVE = ROOT / "data" / "nlp" / "archive_source" / "dataset.csv"
OUT_DIR = ROOT / "data" / "nlp" / "training"
OUT_CSV = OUT_DIR / "patient_hiligaynon_complaint_scenarios.csv"
OUT_JSONL = OUT_DIR / "patient_hiligaynon_complaint_scenarios.jsonl"
META = ROOT / "ai_service" / "models" / "disease_classifier_meta.json"

MAX_PER_DISEASE = 120
MAX_TOTAL = 6000

CONDITION_ALIASES: dict[str, str] = {
    "uti": "Urinary tract infection",
    "asthma": "Bronchial Asthma",
    "migraine": "Migraine",
    "gerd": "GERD",
    "pneumonia": "Pneumonia",
    "typhoid": "Typhoid",
    "dengue": "Dengue",
    "malaria": "Malaria",
    "tuberculosis": "Tuberculosis",
    "diabetes": "Diabetes ",
    "hypertension": "Hypertension ",
    "jaundice": "Jaundice",
    "psoriasis": "Psoriasis",
    "arthritis": "Arthritis",
    "allergy": "Allergy",
    "anemia": "Malaria",
    "gastritis": "Peptic ulcer diseae",
    "food poisoning": "Gastroenteritis",
    "sinusitis": "Common Cold",
    "infection": "Fungal infection",
    "ibs": "Gastroenteritis",
    "kidney stone": "Urinary tract infection",
    "hepatitis": "Hepatitis B",
    "heart attack": "Heart attack",
    "stroke": "Paralysis (brain hemorrhage)",
}


def load_archive_diseases() -> list[str]:
    diseases: list[str] = []
    with ARCHIVE.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            d = (row.get("Disease") or "").strip()
            if d:
                diseases.append(d)
    return sorted(set(diseases))


def disease_from_conditions(raw: str, archive: set[str]) -> str | None:
    for part in (raw or "").split(";"):
        part = part.strip()
        if not part:
            continue
        low = part.lower()
        if low in CONDITION_ALIASES:
            name = CONDITION_ALIASES[low].strip()
            if name in archive:
                return name
        for disease in archive:
            dlow = disease.strip().lower()
            if low == dlow or low in dlow or dlow in low:
                return disease.strip()
    return None


def symptom_key_for_row(row: dict[str, str], valid_keys: set[str]) -> str | None:
    for field in ("medical_term", "normalized_symptom", "english_translation"):
        key = normalize_symptom(row.get(field) or "")
        if key in valid_keys:
            return key
        spaced = normalize_symptom((row.get(field) or "").replace("_", " "))
        if spaced in valid_keys:
            return spaced
    return None


def disease_symptom_map() -> dict[str, set[str]]:
    mapping: dict[str, set[str]] = {}
    for disease, keys in load_disease_symptom_rows():
        mapping.setdefault(disease, set()).update(keys)
    return mapping


def build_cases() -> list[dict[str, str | int]]:
    archive_list = load_archive_diseases()
    archive_set = set(archive_list)
    meta = json.loads(META.read_text(encoding="utf-8"))
    valid_keys = set(meta.get("symptom_columns") or [])
    by_disease_symptoms = disease_symptom_map()

    rng = random.Random(2028)
    per_disease: dict[str, int] = {}
    cases: list[dict[str, str | int]] = []
    case_id = 0

    with COMPLAINTS.open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    rng.shuffle(rows)

    for row in rows:
        if len(cases) >= MAX_TOTAL:
            break
        transcript = (row.get("patient_complaint_hiligaynon") or "").strip()
        if len(transcript) < 10:
            continue
        disease = disease_from_conditions(row.get("possible_conditions") or "", archive_set)
        sym_key = symptom_key_for_row(row, valid_keys)
        if not disease or not sym_key:
            continue
        if sym_key not in by_disease_symptoms.get(disease, set()):
            continue
        if per_disease.get(disease, 0) >= MAX_PER_DISEASE:
            continue

        symptom_keys = sorted(by_disease_symptoms[disease])
        try:
            from analyzer import analyze_transcript_for_ml

            res = analyze_transcript_for_ml(transcript)
            preds = res.get("disease_predictions") or []
            if not preds:
                continue
            top = str(preds[0].get("disease") or "")
            if top.lower() != disease.lower():
                continue
        except Exception:
            continue

        case_id += 1
        per_disease[disease] = per_disease.get(disease, 0) + 1
        cases.append(
            {
                "case_id": f"HC-{case_id:06d}",
                "disease": disease,
                "language": "hiligaynon",
                "transcript": transcript,
                "symptom_keys": ";".join(symptom_keys),
                "symptom_count": len(symptom_keys),
                "template_id": f"complaint_{sym_key}",
                "split": assign_split(disease, seed=2028, group="hil_complaint"),
                "source": "hiligaynon_patient_complaints",
            }
        )

    return cases


def write_outputs(cases: list[dict[str, str | int]]) -> None:
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
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    with OUT_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(cases)
    with OUT_JSONL.open("w", encoding="utf-8") as handle:
        for row in cases:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def main() -> None:
    cases = build_cases()
    write_outputs(cases)
    by_split: dict[str, int] = {}
    for row in cases:
        by_split[str(row["split"])] = by_split.get(str(row["split"]), 0) + 1
    print("Hiligaynon complaint scenarios")
    print(f"  Output: {OUT_CSV}")
    print(f"  Total:  {len(cases)}")
    print(f"  Split:  {by_split}")


if __name__ == "__main__":
    main()
