#!/usr/bin/env python3
"""
Generate data/nlp/hiligaynon_pain_recognition.csv — dedicated Hiligaynon pain NLP dataset.

Target: 100+ variations per pain category (19 categories = 2,000+ rows minimum).
"""

from __future__ import annotations

import csv
import random
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "hiligaynon_pain_recognition.csv"
MIN_PER_CATEGORY = 100
RNG = random.Random(77)

INTENSITY = ["gid", "grabe", "grabeh", "sobra", "sing malala", "daw malala", "malala gid"]
TIME = ["subong", "halin sang aga", "kagapon", "pirmi", "sige", "karon"]
OPENERS = ["", "daw ", "pareho ", "may ara ko nga ", "feel ko nga ", "basin "]
CLOSERS = ["", " gid", " ko", " gid ko", " ako", " man", " subong", " na gid"]
PAIN_WORDS = ["sakit", "masakit", "kirot", "hapdi", "panakit", "pain", "kirot", "sore"]

SPELLING = [
    ("tiyan", "tyan"), ("tiyan", "tian"), ("dughan", "duhan"), ("dalunggan", "dulunggan"),
    ("ngipon", "ngipun"), ("tutunlan", "tutunlan"), ("kalawasan", "lawas"), ("ulo", "utok"),
    ("mata", "mata"), ("liog", "liog"), ("abaga", "abaga"),
]

TYPO = [
    ("masakit", "masaket"), ("masakit", "masakti"), ("sakit", "saket"), ("kirot", "kirt"),
    ("hapdi", "hapde"), ("dalunggan", "dalungan"), ("tuhod", "tuhud"),
]


@dataclass
class PainCategory:
    normalized_symptom: str
    english_translation: str
    medical_term: str
    body_part: str
    pain_category: str
    severity_level: str
    hiligaynon_roots: list[str]
    seeds: list[str] = field(default_factory=list)


def norm(t: str) -> str:
    return re.sub(r"\s+", " ", t.lower().strip())


def spelling_variants(phrase: str) -> set[str]:
    out = {phrase}
    for a, b in SPELLING:
        if a in phrase:
            out.add(phrase.replace(a, b))
    return out


def typo_variants(phrase: str) -> set[str]:
    out = {phrase}
    for a, b in TYPO:
        if a in phrase:
            out.add(phrase.replace(a, b))
    return out


def pain_categories() -> list[PainCategory]:
    c: list[PainCategory] = []

    def add(norm_sym, en, med, part, cat, sev, roots, seeds=None):
        c.append(PainCategory(norm_sym, en, med, part, cat, sev, roots, seeds or []))

    # HEAD
    add("head pain", "headache", "cephalalgia", "head", "neurological pain", "medium",
        ["sakit ulo", "sakit sang ulo", "masakit ulo", "ulo masakit", "nagasakit ulo",
         "kirot ulo", "daw mabutho ulo", "bug-at ulo", "ginasakit ulo", "hapdi ulo", "panakit ulo"],
        ["Sakit gid ulo ko", "Masakit gid akon ulo", "Grabe sakit ulo ko", "Headache gid ko"])

    # EYE
    add("eye pain", "eye pain", "ophthalmalgia", "eye", "ocular pain", "medium",
        ["sakit mata", "masakit mata", "kirot mata", "hapdi mata", "naga sakit mata",
         "ga luha mata", "daw may buhangin mata", "ginasakit mata", "panakit mata"],
        ["Masakit gid akon mata", "Sakit mata ko subong", "Eye pain gid ko"])

    # EAR
    add("ear pain", "ear pain", "otalgia", "ear", "ear pain", "medium",
        ["sakit dalunggan", "masakit dalunggan", "kirot dalunggan", "hapdi dalunggan",
         "naga sakit dalunggan", "ginasakit dalunggan", "sakit dulunggan"],
        ["Masakit dalunggan ko", "Kirot dalunggan ko gid"])

    # TOOTH
    add("tooth pain", "toothache", "odontalgia", "tooth", "dental pain", "medium",
        ["sakit ngipon", "masakit ngipon", "kirot ngipon", "naga sakit ngipon",
         "hubag lagos", "hapdi ngipon", "ginasakit ngipon"],
        ["Sakit ngipon ko gid", "Toothache gid ko"])

    # THROAT
    add("throat pain", "throat pain", "pharyngitis", "throat", "pharyngeal pain", "medium",
        ["sakit tutunlan", "masakit tutunlan", "hapdi tutunlan", "budlay tulon",
         "kirot tutunlan", "ginasakit tutunlan", "sore throat"],
        ["Masakit tutunlan ko", "Hapdi tutunlan gid ko"])

    # CHEST
    add("chest pain", "chest pain", "chest pain", "chest", "cardiac pain", "high",
        ["sakit dughan", "masakit dughan", "kirot dughan", "gapigos dughan", "gakurot dughan",
         "sakit sang dughan", "hapdi dughan", "masakit dibdib", "sakit dibdib"],
        ["Gapigos gid dughan ko", "Gakurot akon dughan", "Chest pain gid ko"])

    # NECK
    add("neck pain", "neck pain", "cervicalgia", "neck", "cervical pain", "medium",
        ["sakit liog", "masakit liog", "kirot liog", "gahi liog", "hapdi liog", "ginasakit liog"],
        ["Sakit liog ko gid", "Gahi liog ko"])

    # SHOULDER
    add("shoulder pain", "shoulder pain", "shoulder pain", "shoulder", "musculoskeletal pain", "medium",
        ["sakit abaga", "masakit abaga", "kirot abaga", "hapdi abaga", "ginasakit abaga"],
        ["Masakit abaga ko"])

    # ARM
    add("arm pain", "arm pain", "arm pain", "arm", "musculoskeletal pain", "medium",
        ["sakit kamot", "masakit kamot", "kirot kamot", "sakit bukton", "hapdi kamot", "ginasakit kamot"],
        ["Sakit kamot ko gid", "Arm pain gid ko"])

    # HAND
    add("hand pain", "hand pain", "hand pain", "hand", "musculoskeletal pain", "medium",
        ["sakit palad", "masakit palad", "kirot palad", "hapdi palad", "ginasakit palad"],
        ["Kirot palad ko"])

    # BACK
    add("back pain", "back pain", "dorsalgia", "back", "spinal pain", "medium",
        ["sakit likod", "masakit likod", "kirot likod", "ga sakit likod", "hapdi likod",
         "ginasakit likod", "lower back pain", "upper back pain"],
        ["Masakit likod ko gid", "Ga sakit likod ko", "Back pain gid ko"])

    # ABDOMINAL
    add("abdominal pain", "abdominal pain", "abdominal pain", "abdomen", "abdominal pain", "medium",
        ["sakit tiyan", "masakit tiyan", "kirot tiyan", "ga sakit tiyan", "sakit pus-on",
         "sakit tyan", "hapdi tiyan", "ginasakit tiyan", "sakit sikmura"],
        ["Masakit akon tiyan", "Sakit tiyan ko gid", "Stomach pain gid ko"])

    # HIP
    add("hip pain", "hip pain", "hip pain", "hip", "musculoskeletal pain", "medium",
        ["sakit hawak", "masakit hawak", "kirot hawak", "hapdi hawak", "ginasakit hawak"],
        ["Sakit hawak ko"])

    # LEG
    add("leg pain", "leg pain", "leg pain", "leg", "musculoskeletal pain", "medium",
        ["sakit tiil", "masakit tiil", "kirot tiil", "hapdi tiil", "ginasakit tiil"],
        ["Masakit tiil ko gid", "Leg pain gid ko"])

    # KNEE
    add("knee pain", "knee pain", "knee pain", "knee", "musculoskeletal pain", "medium",
        ["sakit tuhod", "masakit tuhod", "kirot tuhod", "hapdi tuhod", "ginasakit tuhod"],
        ["Masakit tuhod ko", "Knee pain gid ko"])

    # FOOT
    add("foot pain", "foot pain", "foot pain", "foot", "musculoskeletal pain", "medium",
        ["sakit talampakan", "masakit talampakan", "kirot talampakan", "hapdi talampakan",
         "sakit tiil", "ginasakit talampakan"],
        ["Kirot talampakan ko"])

    # JOINT
    add("joint pain", "joint pain", "arthralgia", "joint", "articular pain", "medium",
        ["sakit lutahan", "masakit lutahan", "kirot lutahan", "hapdi lutahan", "ginasakit lutahan",
         "rayuma", "arthritis pain"],
        ["Sakit lutahan ko gid", "Joint pain gid ko"])

    # MUSCLE
    add("muscle pain", "muscle pain", "myalgia", "muscle", "muscular pain", "medium",
        ["sakit kalawasan", "masakit kalawasan", "kirot kalawasan", "kapoy lawas",
         "masakit lawas", "sakit lawas", "ginasakit kalawasan"],
        ["Masakit kalawasan ko", "Muscle pain gid ko"])

    # FULL BODY
    add("body pain", "generalized pain", "myalgia", "body", "generalized pain", "medium",
        ["sakit bilog lawas", "masakit bilog lawas", "kirot bilog lawas", "luya lawas",
         "kapoy gid lawas", "masakit lawas", "sakit lawas", "body pain", "whole body pain"],
        ["Masakit bilog lawas ko", "Kapoy gid lawas ko", "Body pain gid ko"])

    return c


def augment_category(cat: PainCategory) -> list[str]:
    complaints: set[str] = set()

    def add(s: str) -> None:
        s = re.sub(r"\s+", " ", s.strip())
        if 3 <= len(s) <= 100:
            complaints.add(s)

    for seed in cat.seeds:
        add(seed)
        for v in spelling_variants(seed):
            add(v)
        for v in typo_variants(seed):
            add(v)

    part_word = cat.body_part
    roots = cat.hiligaynon_roots

    for root in roots:
        add(root)
        for v in spelling_variants(root):
            add(v)
        for v in typo_variants(root):
            add(v)

    templates = [
        "{opener}masakit {intensity} akon {part}{closer}",
        "{opener}sakit {part} ko {time}{closer}",
        "{opener}kirot {part} ko {intensity}{closer}",
        "{opener}hapdi {part} ko{closer}",
        "{opener}grabe gid {root}{closer}",
        "{opener}may ara ko {root} {time}{closer}",
        "{opener}daw {root} gid ko{closer}",
        "{opener}pirmi ko {root}{closer}",
        "{opener}{root} gid ko subong{closer}",
        "{opener}feel ko nga {root} ko{closer}",
        "{opener}{en} gid ko{closer}",
        "{opener}may {en} ko{closer}",
        "{opener}ako {root} {time}{closer}",
        "{opener}nagasakit {part} ko{closer}",
        "{opener}ginasakit {part} ko{closer}",
    ]

    part_map = {
        "head": "ulo", "eye": "mata", "ear": "dalunggan", "tooth": "ngipon",
        "throat": "tutunlan", "chest": "dughan", "neck": "liog", "shoulder": "abaga",
        "arm": "kamot", "hand": "palad", "back": "likod", "abdomen": "tiyan",
        "hip": "hawak", "leg": "tiil", "knee": "tuhod", "foot": "talampakan",
        "joint": "lutahan", "muscle": "kalawasan", "body": "lawas",
    }
    hil_part = part_map.get(cat.body_part, cat.body_part)

    while len(complaints) < MIN_PER_CATEGORY:
        t = RNG.choice(templates)
        root = RNG.choice(roots)
        text = t.format(
            opener=RNG.choice(OPENERS),
            intensity=RNG.choice(INTENSITY),
            time=RNG.choice(TIME),
            closer=RNG.choice(CLOSERS),
            root=root,
            part=hil_part,
            en=cat.english_translation.lower(),
        )
        add(text)
        for v in spelling_variants(text):
            add(v)
        if len(complaints) >= MIN_PER_CATEGORY + 20:
            break

    # Slang abbreviations
    for root in roots[:8]:
        for sfx in [" gid", " gid ko", " ko", " man", " bah", " no"]:
            add(root + sfx)
        add(root.replace("sakit", "skit") if "sakit" in root else root + " skit")

    return list(complaints)


def build_rows() -> list[dict[str, str]]:
    rows: dict[str, dict[str, str]] = {}
    group_alts: dict[str, list[str]] = {}

    for cat in pain_categories():
        alts = cat.hiligaynon_roots
        alt_str = ";".join(alts)
        group_key = norm(f"{cat.medical_term}|{cat.normalized_symptom}")
        group_alts.setdefault(group_key, []).extend(alts)

        for complaint in augment_category(cat):
            key = norm(complaint)
            if key in rows:
                continue
            rows[key] = {
                "hiligaynon_complaint": complaint,
                "normalized_symptom": cat.normalized_symptom,
                "english_translation": cat.english_translation,
                "medical_term": cat.medical_term,
                "body_part": cat.body_part,
                "pain_category": cat.pain_category,
                "severity_level": cat.severity_level,
                "alternative_spellings": alt_str,
                "_group": group_key,
            }

    for row in rows.values():
        alts = sorted(set(group_alts.get(row.pop("_group", ""), [])), key=len, reverse=True)
        row["alternative_spellings"] = ";".join(alts[:25])

    result = list(rows.values())
    result.sort(key=lambda r: (r["body_part"], r["normalized_symptom"], norm(r["hiligaynon_complaint"])))
    for i, row in enumerate(result, start=1):
        row["id"] = str(i)
    return result


def main() -> None:
    rows = build_rows()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    fields = [
        "id", "hiligaynon_complaint", "normalized_symptom", "english_translation",
        "medical_term", "body_part", "pain_category", "severity_level", "alternative_spellings",
    ]
    with OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)

    by_part: dict[str, int] = {}
    for r in rows:
        by_part[r["body_part"]] = by_part.get(r["body_part"], 0) + 1

    print(f"Wrote {len(rows):,} pain records to {OUT}")
    for part, n in sorted(by_part.items(), key=lambda x: -x[1]):
        print(f"  {part}: {n:,}")

    index = {norm(r["hiligaynon_complaint"]): r for r in rows}
    checks = [
        ("Masakit gid akon mata", "eye pain"),
        ("Sakit gid ulo ko", "head pain"),
        ("Gapigos gid dughan ko", "chest pain"),
        ("Masakit likod ko gid", "back pain"),
    ]
    print("\nNormalization checks:")
    for phrase, expected in checks:
        hit = index.get(norm(phrase))
        ok = hit and hit["normalized_symptom"] == expected
        print(f"  {phrase}: {'OK -> ' + hit['english_translation'] if ok else 'MISSING/MISMATCH'}")


if __name__ == "__main__":
    main()
