#!/usr/bin/env python3
"""Generate data/nlp/body_part_pain_symptoms.csv — maps pain phrases to official symptoms.csv names."""

from __future__ import annotations

import csv
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "body_part_pain_symptoms.csv"

# english_alias -> official symptom name in symptoms.csv
MAPPINGS: list[tuple[str, str, str, str]] = [
    # canonical_english, official_symptom, body_part, notes
    ("eye pain", "Eye Pain", "eye", "ophthalmalgia"),
    ("headache", "Headache", "head", "cephalalgia"),
    ("head pain", "Headache", "head", "cephalalgia"),
    ("ear pain", "Ear Pain", "ear", "otalgia"),
    ("toothache", "Toothache", "tooth", "odontalgia"),
    ("tooth pain", "Toothache", "tooth", "odontalgia"),
    ("throat pain", "Throat Pain", "throat", "pharyngalgia"),
    ("sore throat", "Sore Throat", "throat", "pharyngitis symptom"),
    ("chest pain", "Chest Pain", "chest", "cardiac/respiratory"),
    ("neck pain", "Neck Pain", "neck", "cervicalgia"),
    ("shoulder pain", "Shoulder Pain", "shoulder", "musculoskeletal"),
    ("arm pain", "Upper Arm Pain", "arm", "upper limb"),
    ("hand pain", "Hand Pain", "hand", "musculoskeletal"),
    ("back pain", "Back Pain", "back", "dorsalgia"),
    ("abdominal pain", "Abdominal Pain", "abdomen", "abdominal"),
    ("stomach pain", "Stomach Pain", "abdomen", "abdominal"),
    ("hip pain", "Hip Pain", "hip", "musculoskeletal"),
    ("leg pain", "Leg Pain", "leg", "lower limb"),
    ("knee pain", "Knee Pain", "knee", "musculoskeletal"),
    ("foot pain", "Foot Pain", "foot", "musculoskeletal"),
    ("joint pain", "Joint Pain", "joint", "arthralgia"),
    ("muscle pain", "Muscle Pain", "muscle", "myalgia"),
    ("generalized pain", "Myalgia", "body", "whole body"),
    ("body pain", "Myalgia", "body", "whole body"),
    ("whole body pain", "Myalgia", "body", "whole body"),
]

# Hiligaynon body-part word + "pain" aliases (from bad NLP translations)
HIL_PART_ALIASES: dict[str, str] = {
    "mata": "eye pain",
    "ulo": "headache",
    "utok": "headache",
    "dalunggan": "ear pain",
    "dulunggan": "ear pain",
    "ngipon": "toothache",
    "ngipun": "toothache",
    "tutunlan": "throat pain",
    "dughan": "chest pain",
    "dibdib": "chest pain",
    "liog": "neck pain",
    "abaga": "shoulder pain",
    "kamot": "arm pain",
    "bukton": "arm pain",
    "palad": "hand pain",
    "likod": "back pain",
    "tiyan": "abdominal pain",
    "tyan": "abdominal pain",
    "tian": "abdominal pain",
    "pus-on": "abdominal pain",
    "sikmura": "abdominal pain",
    "hawak": "hip pain",
    "tiil": "leg pain",
    "tuhod": "knee pain",
    "tuhud": "knee pain",
    "talampakan": "foot pain",
    "lutahan": "joint pain",
    "kalawasan": "muscle pain",
    "lawas": "muscle pain",
}


def main() -> None:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    row_id = 1

    def add(alias: str, canonical: str, official: str, body_part: str, note: str) -> None:
        nonlocal row_id
        key = alias.strip().lower()
        if not key or key in seen:
            return
        seen.add(key)
        rows.append(
            {
                "id": str(row_id),
                "english_alias": key,
                "canonical_english": canonical,
                "official_symptom": official,
                "body_part": body_part,
                "notes": note,
            }
        )
        row_id += 1

    for canonical, official, body_part, note in MAPPINGS:
        add(canonical, canonical, official, body_part, note)

    for part_word, canonical in HIL_PART_ALIASES.items():
        official = next((m[1] for m in MAPPINGS if m[0] == canonical), "")
        body = next((m[2] for m in MAPPINGS if m[0] == canonical), part_word)
        add(f"{part_word} pain", canonical, official, body, f"Hiligaynon part alias: {part_word}")

    OUT.parent.mkdir(parents=True, exist_ok=True)
    with OUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=[
                "id",
                "english_alias",
                "canonical_english",
                "official_symptom",
                "body_part",
                "notes",
            ],
        )
        writer.writeheader()
        writer.writerows(rows)

    print(f"Wrote {len(rows)} rows to {OUT}")


if __name__ == "__main__":
    main()
