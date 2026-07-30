"""
Chief complaint / registration form scenarios (clinical intake style).

Run:
    python scripts/data/build_chief_complaint_scenarios.py
    python scripts/data/merge_patient_training_corpus.py

Output:
    data/nlp/training/patient_chief_complaint_scenarios.csv
"""

from __future__ import annotations

import csv
import json
import random
from pathlib import Path

from build_patient_training_dataset import (
    hiligaynon_phrase_for_symptom,
    join_phrases,
    load_dictionary_hiligaynon,
    load_disease_symptom_rows,
    symptom_to_english_phrase,
)
from build_realistic_patient_scenarios import _assign_split_realistic, _light_typo, CHAT_NOISE

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "data" / "nlp" / "training"
OUT_CSV = OUT_DIR / "patient_chief_complaint_scenarios.csv"
OUT_JSONL = OUT_DIR / "patient_chief_complaint_scenarios.jsonl"

CHIEF_COMPLAINT_EN = [
    "Chief Complaint: {symptoms}",
    "CC: {symptoms}",
    "Chief complaint / HPI: {symptoms}",
    "Reason for visit: {symptoms}",
    "Reason for consult: {symptoms}",
    "Presenting complaint: {symptoms}",
    "What brings you in today? {symptoms}",
    "Patient chief complaint is {symptoms}",
    "Main concern today: {symptoms}",
    "Primary complaint: {symptoms}",
    "Problem listed: {symptoms}",
    "Health concern: {symptoms}",
    "Symptoms (chief complaint): {symptoms}",
    "Intake form - chief complaint: {symptoms}",
    "Registration chief complaint field: {symptoms}",
    "Telehealth chief complaint: {symptoms}",
    "Online intake CC: {symptoms}",
    "MedConnect CC box: {symptoms}",
    "Patient states chief complaint of {symptoms}",
    "Reports chief complaint of {symptoms}",
    "Subjective: {symptoms}",
    "S/O chief complaint {symptoms}",
    "Clinic form chief complaint {symptoms}",
    "Barangay health CC {symptoms}",
    "First complaint {symptoms}",
    "Main symptom concern {symptoms}",
    "Visit reason {symptoms}",
    "Why patient came: {symptoms}",
    "Complaint today {symptoms}",
    "Current complaint {symptoms}",
    "Patient typed CC as {symptoms}",
    "E-consult chief complaint {symptoms}",
    "Virtual consult CC {symptoms}",
    "Health center form CC {symptoms}",
    "RHU intake {symptoms}",
    "Patient portal complaint {symptoms}",
    "Symptom checker input {symptoms}",
    "Describe concern: {symptoms}",
    "Please enter chief complaint {symptoms}",
    "CC (patient entry) {symptoms}",
]

CHIEF_COMPLAINT_HIL = [
    "Chief complaint: {symptoms}",
    "CC: {symptoms}",
    "Chief complaint ko {symptoms}",
    "CC ko {symptoms}",
    "Rason sang pag consult {symptoms}",
    "Rason ko nga nag consult {symptoms}",
    "Unang reklamo {symptoms}",
    "Reklamo ko {symptoms}",
    "Chief complaint sang pasyente {symptoms}",
    "Form chief complaint {symptoms}",
    "Field chief complaint {symptoms}",
    "Ano chief complaint {symptoms}",
    "Ano ang reklamo {symptoms}",
    "Reklamo subong {symptoms}",
    "Chief complaint subong {symptoms}",
    "CC subong {symptoms}",
    "Telehealth CC {symptoms}",
    "Online consult CC {symptoms}",
    "MedConnect CC {symptoms}",
    "Registration CC {symptoms}",
    "Intake form {symptoms}",
    "Barangay form CC {symptoms}",
    "RHU chief complaint {symptoms}",
    "Health center CC {symptoms}",
    "Pasyente nagsugid {symptoms}",
    "Nagsugid ang pasyente {symptoms}",
    "Chief complaint gid {symptoms}",
    "CC lang {symptoms}",
    "Ginatype CC {symptoms}",
    "Type ko CC {symptoms}",
    "Chief complaint field {symptoms}",
    "Virtual consult reklamo {symptoms}",
    "E-consult CC {symptoms}",
    "Portal complaint {symptoms}",
    "Symptom field {symptoms}",
    "Describe concern {symptoms}",
    "Ilagay chief complaint {symptoms}",
    "Patient entry CC {symptoms}",
    "CC patient typed {symptoms}",
    "Unang problema {symptoms}",
    # Broader Hiligaynon intake / barangay / elderly phrasing.
    "Ano ang imo reklamo? {symptoms}",
    "Ano ang ginabatyag mo? {symptoms}",
    "Istorya sang pasyente: {symptoms}",
    "Sa RHU form: {symptoms}",
    "Sa barangay health station: {symptoms}",
    "Para sa teleconsultation: {symptoms}",
    "Reklamo sang tigulang: {symptoms}",
    "Reklamo sang bata: {symptoms}",
    "Ginahambal sang asawa: {symptoms}",
    "Ginahambal sang anak: {symptoms}",
    "Pangunang reklamo subong adlaw: {symptoms}",
    "Sulod sang isa ka semana: {symptoms}",
    "Halín kagabi may {symptoms}",
    "Indi na mabatas: {symptoms}",
    "Emergency bala? {symptoms}",
    "Urgent consult: {symptoms}",
    "Follow-up reklamo: {symptoms}",
    "Pagbalik sa klinika tungod {symptoms}",
    "MedConnect intake (Hiligaynon): {symptoms}",
    "Patient portal (Ilonggo): {symptoms}",
    "Symptom checker entry: {symptoms}",
    "Sa registration: {symptoms}",
]

CHIEF_COMPLAINT_MIXED = [
    "Chief complaint: {symptoms_hil} / {symptoms_en}",
    "CC {symptoms_hil} and {symptoms_en}",
    "Form CC {symptoms_hil} plus {symptoms_en}",
    "Patient CC {symptoms_hil} also {symptoms_en}",
    "Intake {symptoms_hil} kag {symptoms_en}",
    "Telehealth CC {symptoms_hil} + {symptoms_en}",
    "Reklamo {symptoms_hil} reports {symptoms_en}",
    "Hiligaynon CC {symptoms_hil} english {symptoms_en}",
    "Mixed CC {symptoms_hil} & {symptoms_en}",
    "Registration {symptoms_hil} / {symptoms_en}",
    "Chief complaint mixed {symptoms_hil} and {symptoms_en}",
    "CC field {symptoms_hil} plus {symptoms_en}",
    "Online form {symptoms_hil} also {symptoms_en}",
    "Barangay CC {symptoms_hil} {symptoms_en}",
    "Portal CC {symptoms_hil} & {symptoms_en}",
]


def build_cases() -> list[dict[str, str | int]]:
    dictionary = load_dictionary_hiligaynon()
    rows = load_disease_symptom_rows()
    rng = random.Random(2027)
    cases: list[dict[str, str | int]] = []
    case_id = 0

    for disease, symptom_keys in rows:
        english_phrases = [symptom_to_english_phrase(k) for k in symptom_keys]
        hil_phrases = [hiligaynon_phrase_for_symptom(k, dictionary) for k in symptom_keys]
        symptom_blob = ";".join(symptom_keys)
        en_joined = join_phrases(english_phrases, "english")
        hil_joined = join_phrases(hil_phrases, "hiligaynon")

        for template in CHIEF_COMPLAINT_EN:
            case_id += 1
            noise = rng.choice(CHAT_NOISE)
            transcript = _light_typo((noise + template.format(symptoms=en_joined)).strip(), rng)
            cases.append(
                {
                    "case_id": f"CC-{case_id:06d}",
                    "disease": disease,
                    "language": "english",
                    "transcript": transcript,
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"chief_en_{case_id}",
                    "split": _assign_split_realistic(disease),
                    "source": "chief_complaint_form",
                }
            )

        for template in CHIEF_COMPLAINT_HIL:
            case_id += 1
            noise = rng.choice(CHAT_NOISE)
            transcript = _light_typo((noise + template.format(symptoms=hil_joined)).strip(), rng)
            cases.append(
                {
                    "case_id": f"CC-{case_id:06d}",
                    "disease": disease,
                    "language": "hiligaynon",
                    "transcript": transcript,
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"chief_hil_{case_id}",
                    "split": _assign_split_realistic(disease),
                    "source": "chief_complaint_form",
                }
            )

        if len(symptom_keys) >= 2:
            mid = max(1, len(symptom_keys) // 2)
            hil_part = join_phrases(hil_phrases[:mid], "hiligaynon")
            en_part = join_phrases(english_phrases[mid:], "english")
            if len(symptom_keys) == 2:
                hil_part = join_phrases(hil_phrases[:1], "hiligaynon")
                en_part = join_phrases(english_phrases[1:], "english")
            for template in CHIEF_COMPLAINT_MIXED:
                case_id += 1
                transcript = _light_typo(
                    template.format(symptoms_hil=hil_part, symptoms_en=en_part).strip(),
                    rng,
                )
                cases.append(
                    {
                        "case_id": f"CC-{case_id:06d}",
                        "disease": disease,
                        "language": "mixed",
                        "transcript": transcript,
                        "symptom_keys": symptom_blob,
                        "symptom_count": len(symptom_keys),
                        "template_id": f"chief_mix_{case_id}",
                        "split": _assign_split_realistic(disease),
                        "source": "chief_complaint_form",
                    }
                )

    return cases


def main() -> None:
    cases = build_cases()
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

    by_lang: dict[str, int] = {}
    by_split: dict[str, int] = {}
    for row in cases:
        by_lang[str(row["language"])] = by_lang.get(str(row["language"]), 0) + 1
        by_split[str(row["split"])] = by_split.get(str(row["split"]), 0) + 1
    print("Chief complaint scenarios")
    print(f"Output: {OUT_CSV}")
    print(f"Total:  {len(cases)}")
    print(f"Lang:   {by_lang}")
    print(f"Split:  {by_split}")


if __name__ == "__main__":
    main()
