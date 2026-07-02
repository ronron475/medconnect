#!/usr/bin/env python3
"""
Western Visayas Hiligaynon — reproductive, genital, urinary, and sensitive complaint datasets.

Outputs / updates:
  data/nlp/body_parts.csv              (anatomy only — not symptoms)
  data/nlp/hiligaynon_symptoms.csv
  data/nlp/hiligaynon_conditions.csv
  data/nlp/symptom_phrases.csv
  data/nlp/symptom_synonyms.csv
  data/nlp/medical_misspellings.csv
  data/nlp/triage_rules.csv
  data/nlp/hiligaynon_reproductive_expansion.csv
  Merges into medical_dictionary.csv + hiligaynon_medical_nlp_dataset.csv
"""

from __future__ import annotations

import csv
import itertools
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"

CSV_FIELDS = [
    "hiligaynon_term",
    "english_term",
    "medical_category",
    "severity",
    "triage_level",
    "status",
]

BODY_PART_FIELDS = [
    "hiligaynon_term",
    "english_term",
    "body_system",
    "anatomy_category",
    "status",
]

NLP_FIELDS = [
    "id",
    "hiligaynon_term",
    "alternative_spellings",
    "english_translation",
    "medical_term",
    "medical_category",
    "body_system",
    "severity",
    "symptom_keywords",
    "confidence_keywords",
]


@dataclass(frozen=True)
class Record:
    hiligaynon_term: str
    english_term: str
    medical_category: str
    severity: str
    triage_level: str
    status: str = "active"
    body_system: str = "general"
    body_part: str = ""
    symptom: str = ""
    condition: str = ""
    is_condition: bool = False

    def key(self) -> tuple[str, str]:
        return (self.hiligaynon_term.lower().strip(), self.english_term.lower().strip())


RECORDS: dict[tuple[str, str], Record] = {}
BODY_PARTS: dict[str, tuple[str, str, str]] = {}  # hil -> (english, body_system, anatomy_category)


def add(rec: Record) -> None:
    term = rec.hiligaynon_term.strip()
    if not term or len(term) < 2:
        return
    k = rec.key()
    if k in RECORDS:
        return
    RECORDS[k] = rec


def add_many(items: list[tuple[str, str, str, str, str]]) -> None:
    for hil, eng, cat, sev, tri in items:
        add(
            Record(
                hiligaynon_term=hil,
                english_term=eng,
                medical_category=cat,
                severity=sev,
                triage_level=tri,
                is_condition=cat in {"Infection", "Injury", "Trauma", "Emergency", "Gynecologic"},
            )
        )


# ── Anatomy (never validated as standalone symptoms) ─────────────────────────
ANATOMY: list[tuple[str, str, str, str]] = [
    ("itlog", "testicle", "male_reproductive", "male_reproductive"),
    ("itlug", "testicle", "male_reproductive", "male_reproductive"),
    ("bilat", "vagina", "female_reproductive", "female_reproductive"),
    ("bilad", "vagina", "female_reproductive", "female_reproductive"),
    ("ari", "penis", "male_reproductive", "male_reproductive"),
    ("ari ko", "penis", "male_reproductive", "male_reproductive"),
    ("hita", "thigh", "musculoskeletal", "musculoskeletal"),
    ("singit", "groin", "musculoskeletal", "musculoskeletal"),
    ("bayag", "scrotum", "male_reproductive", "male_reproductive"),
    ("kipay", "vulva", "female_reproductive", "female_reproductive"),
    ("ubong", "vulva", "female_reproductive", "female_reproductive"),
    ("pisngi", "cheek", "head", "head"),
    ("dila", "tongue", "oral", "oral"),
    ("titi", "breast", "female_reproductive", "female_reproductive"),
    ("ubot", "anus", "gastrointestinal", "gastrointestinal"),
    ("tae", "rectum", "gastrointestinal", "gastrointestinal"),
]

POSSESSIVES = ["ko", "akon", "ko gid", "akon gid", "ako", "akon subong", "gid ko"]
SLANG_POS = ["ko", "akon", "ko gid", "akon gid", "ko man"]

SWELL = ["gahabok", "gahubag", "hubag", "ginahubag", "gahubag gid", "hubag gid"]
PAIN = ["masakit", "gasakit", "hapdi", "sakit", "masakit gid", "gasakit gid", "sakit gid"]
ITCH = ["gakatol", "kakatol", "ginakatol", "gakatol gid", "kakatol gid"]
RED = ["gapula", "mapula", "ginapula"]
BLEED = ["gadugo", "nagadugo", "nagdugo", "gakadugo", "nagdugo gid", "gadugo gid"]
LUMP = ["may bukol sa", "may bukol ko sa", "daw may bukol sa", "may bukol sa akon", "may bukol"]
INFECT = ["may nana sa", "may nana sa akon", "may nana", "may nana ko sa"]
WOUND = ["may pilas sa", "may pilas ko sa", "may pilas sa akon"]
SEVERE_BLEED = [
    "grabe gid nagadugo",
    "grabe gid nagdugo",
    "indi mapunggan ang dugo sa",
    "dugo gid indi mapunggan sa",
    "dugo gid sa",
]

MISSPELLINGS = {
    "bilat": ["bilat", "bilad"],
    "itlog": ["itlog", "itlug"],
    "ari": ["ari", "ary"],
    "gadugo": ["gadugo", "nagadugo", "nagdugo", "gakadugo"],
    "gahabok": ["gahabok", "gahubag", "gahubok"],
    "kakatol": ["kakatol", "gakatol", "ginakatol"],
    "masakit": ["masakit", "gasakit", "masaket"],
}


def body_variants(hil: str) -> list[str]:
    return list(dict.fromkeys(MISSPELLINGS.get(hil, [hil])))


def register_anatomy() -> None:
    for hil, eng, body_sys, anatomy in ANATOMY:
        BODY_PARTS[hil.lower()] = (eng, body_sys, anatomy)


def generate_anatomy_csv_rows() -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for hil, eng, body_sys, anatomy in ANATOMY:
        for variant in body_variants(hil.split()[0]):
            key = variant.lower()
            if key in seen:
                continue
            seen.add(key)
            rows.append(
                {
                    "hiligaynon_term": variant,
                    "english_term": eng,
                    "body_system": body_sys,
                    "anatomy_category": anatomy,
                    "status": "active",
                }
            )
    return sorted(rows, key=lambda r: r["hiligaynon_term"].lower())


def _tri(severity: str) -> str:
    return {"mild": "non_urgent", "moderate": "urgent", "severe": "urgent", "critical": "emergency"}.get(
        severity.lower(), "routine"
    )


def generate_reproductive_symptoms() -> None:
    """Male / female reproductive symptom combinations."""
    specs: list[tuple[str, str, list[str], str, str, str, str, str]] = [
        # body, body_eng, prefixes, english, category, severity, triage, symptom_root
        ("itlog", "testicle", SWELL, "testicular swelling", "male_reproductive_symptom", "moderate", "urgent", "swelling"),
        ("itlog", "testicle", PAIN, "testicular pain", "male_reproductive_symptom", "moderate", "urgent", "pain"),
        ("itlog", "testicle", ITCH, "testicular itching", "male_reproductive_symptom", "mild", "non_urgent", "itching"),
        ("itlog", "testicle", RED, "testicular redness", "male_reproductive_symptom", "moderate", "urgent", "redness"),
        ("itlog", "testicle", BLEED, "testicular bleeding", "male_reproductive_symptom", "severe", "urgent", "bleeding"),
        ("itlog", "testicle", LUMP, "testicular lump", "male_reproductive_symptom", "severe", "urgent", "lump"),
        ("itlog", "testicle", INFECT, "testicular infection", "infection", "severe", "urgent", "infection"),
        ("itlog", "testicle", WOUND, "testicular wound", "injury", "moderate", "urgent", "wound"),
        ("ari", "penis", SWELL, "penile swelling", "male_reproductive_symptom", "moderate", "urgent", "swelling"),
        ("ari", "penis", PAIN, "penile pain", "male_reproductive_symptom", "moderate", "urgent", "pain"),
        ("ari", "penis", ITCH, "penile itching", "male_reproductive_symptom", "mild", "non_urgent", "itching"),
        ("ari", "penis", RED, "penile redness", "male_reproductive_symptom", "moderate", "urgent", "redness"),
        ("ari", "penis", BLEED, "penile bleeding", "male_reproductive_symptom", "severe", "urgent", "bleeding"),
        ("ari", "penis", LUMP, "penile lump", "male_reproductive_symptom", "severe", "urgent", "lump"),
        ("ari", "penis", INFECT, "penile infection", "infection", "severe", "urgent", "infection"),
        ("ari", "penis", WOUND, "penile wound", "injury", "moderate", "urgent", "wound"),
        ("bilat", "vagina", SWELL, "vaginal swelling", "female_reproductive_symptom", "moderate", "urgent", "swelling"),
        ("bilat", "vagina", PAIN, "vaginal pain", "female_reproductive_symptom", "moderate", "urgent", "pain"),
        ("bilat", "vagina", ITCH, "vaginal itching", "female_reproductive_symptom", "mild", "non_urgent", "itching"),
        ("bilat", "vagina", RED, "vaginal redness", "female_reproductive_symptom", "moderate", "urgent", "redness"),
        ("bilat", "vagina", BLEED, "vaginal bleeding", "gynecologic_symptom", "severe", "urgent", "bleeding"),
        ("bilat", "vagina", LUMP, "vaginal lump", "female_reproductive_symptom", "severe", "urgent", "lump"),
        ("bilat", "vagina", INFECT, "vaginal infection", "infection", "severe", "urgent", "infection"),
        ("bilat", "vagina", WOUND, "vaginal wound", "injury", "moderate", "urgent", "wound"),
        ("bayag", "scrotum", SWELL, "scrotal swelling", "male_reproductive_symptom", "moderate", "urgent", "swelling"),
        ("bayag", "scrotum", PAIN, "scrotal pain", "male_reproductive_symptom", "moderate", "urgent", "pain"),
        ("kipay", "vulva", ITCH, "vulvar itching", "female_reproductive_symptom", "mild", "non_urgent", "itching"),
        ("kipay", "vulva", PAIN, "vulvar pain", "female_reproductive_symptom", "moderate", "urgent", "pain"),
        ("singit", "groin", SWELL, "groin swelling", "musculoskeletal", "moderate", "urgent", "swelling"),
        ("singit", "groin", PAIN, "groin pain", "musculoskeletal", "moderate", "urgent", "pain"),
        ("singit", "groin", LUMP, "groin lump", "musculoskeletal", "severe", "urgent", "lump"),
    ]

    for body, body_eng, prefixes, english, category, severity, triage, symptom in specs:
        body_sys = BODY_PARTS.get(body, ("", "general", ""))[1]
        for bv in body_variants(body):
            for prefix in prefixes:
                if prefix.startswith("may ") or prefix.startswith("daw "):
                    templates = [f"{prefix} {bv} ko"] + [
                        f"{prefix} {bv} {pos}".replace("  ", " ") for pos in ["ko", "akon"]
                    ] + [f"{prefix} akon {bv}"]
                else:
                    templates = [
                        f"{prefix} {bv} {pos}".replace("  ", " ")
                        for pos in POSSESSIVES
                    ]
                    templates += [f"{prefix} akon {bv}", f"{prefix} {bv} ko gid"]
                for t in templates:
                    add(
                        Record(
                            hiligaynon_term=re.sub(r"\s+", " ", t).strip(),
                            english_term=english,
                            medical_category=category,
                            severity=severity.capitalize() if severity != "non_urgent" else "Low",
                            triage_level=triage if triage != "non_urgent" else "routine",
                            body_system=body_sys,
                            body_part=body_eng,
                            symptom=symptom,
                            condition=english if category == "infection" else "",
                            is_condition=category in {"infection", "injury", "gynecologic_symptom"},
                        )
                    )


def generate_user_examples() -> None:
    """Exact and natural patient statements from requirements."""
    examples = [
        ("gahabok itlog ko", "swollen testicle", "male_reproductive_symptom", "Moderate", "urgent"),
        ("gahubag itlog ko", "testicular swelling", "male_reproductive_symptom", "Moderate", "urgent"),
        ("masakit itlog ko", "testicular pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("may bukol sa itlog ko", "testicular lump", "male_reproductive_symptom", "High", "urgent"),
        ("kakatol bilat ko", "vaginal itching", "female_reproductive_symptom", "Low", "routine"),
        ("gakatol bilat ko", "vaginal itching", "female_reproductive_symptom", "Low", "routine"),
        ("gapula bilat ko", "vaginal redness", "female_reproductive_symptom", "Moderate", "urgent"),
        ("masakit bilat ko", "vaginal pain", "female_reproductive_symptom", "Moderate", "urgent"),
        ("gahabok bilat ko", "vaginal swelling", "female_reproductive_symptom", "Moderate", "urgent"),
        ("may bukol sa bilat ko", "vaginal lump", "female_reproductive_symptom", "High", "urgent"),
        ("gadugo bilat ko", "vaginal bleeding", "gynecologic_symptom", "High", "urgent"),
        ("may nana sa bilat ko", "vaginal infection", "infection", "High", "urgent"),
        ("masakit ari ko", "penile pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("gahabok ari ko", "penile swelling", "male_reproductive_symptom", "Moderate", "urgent"),
        ("may bukol sa ari ko", "penile lump", "male_reproductive_symptom", "High", "urgent"),
        ("gadugo ari ko", "penile bleeding", "male_reproductive_symptom", "High", "urgent"),
        ("may nana sa ari ko", "penile infection", "infection", "High", "urgent"),
        ("may pilas sa ari ko", "penile wound", "injury", "Moderate", "urgent"),
        ("gahabok akon itlog", "swollen testicle", "male_reproductive_symptom", "Moderate", "urgent"),
        ("gahubag gid itlog ko", "testicular swelling", "male_reproductive_symptom", "Moderate", "urgent"),
        ("masakit gid ari ko", "penile pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("may pilas ko sa ari", "penile wound", "injury", "Moderate", "urgent"),
        ("kakatol gid bilat ko", "vaginal itching", "female_reproductive_symptom", "Low", "routine"),
        ("nagadugo bilat ko", "vaginal bleeding", "gynecologic_symptom", "High", "urgent"),
        ("may bukol ko sa bilat", "vaginal lump", "female_reproductive_symptom", "High", "urgent"),
        ("daw may bukol sa itlog ko", "testicular lump", "male_reproductive_symptom", "High", "urgent"),
        ("masakit gid akon itlog", "testicular pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("gakadugo ari ko", "penile bleeding", "male_reproductive_symptom", "High", "urgent"),
        ("gahabok gid bilat ko", "vaginal swelling", "female_reproductive_symptom", "Moderate", "urgent"),
        ("grabe gid nagadugo bilat ko", "severe vaginal bleeding", "gynecologic_symptom", "Critical", "emergency"),
        ("indi mapunggan ang dugo sa ari", "uncontrolled penile bleeding", "male_reproductive_symptom", "Critical", "emergency"),
        ("nautod ari ko", "penile amputation", "trauma", "Critical", "emergency"),
    ]
    add_many(examples)


def generate_severe_emergency() -> None:
    for body, eng in [("bilat", "vagina"), ("ari", "penis"), ("itlog", "testicle")]:
        for bv in body_variants(body):
            for prefix in SEVERE_BLEED:
                add(
                    Record(
                        hiligaynon_term=f"{prefix} {bv} ko".replace("  ", " "),
                        english_term=f"severe {eng} bleeding",
                        medical_category="gynecologic_symptom" if eng == "vagina" else "male_reproductive_symptom",
                        severity="Critical",
                        triage_level="emergency",
                        body_part=eng,
                        symptom="bleeding",
                        is_condition=True,
                    )
                )
            add(
                Record(
                    hiligaynon_term=f"nautod {bv} ko",
                    english_term=f"{eng} amputation",
                    medical_category="trauma",
                    severity="Critical",
                    triage_level="emergency",
                    body_part=eng,
                    is_condition=True,
                )
            )


def generate_urinary_genital() -> None:
    items = [
        ("masakit pag-ihi ko", "painful urination", "urinary_symptom", "Moderate", "urgent"),
        ("masakit mag-ihi ko", "painful urination", "urinary_symptom", "Moderate", "urgent"),
        ("masakit pag-ihi ko sa bilat", "painful urination with vaginal pain", "urinary_symptom", "Moderate", "urgent"),
        ("duguon ihi ko", "bloody urine", "urinary_symptom", "High", "urgent"),
        ("may dugo sa ihi ko", "bloody urine", "urinary_symptom", "High", "urgent"),
        ("damsi mag-ihi ko", "frequent urination", "urinary_symptom", "Low", "routine"),
        ("wala ko maka-ihi", "urinary retention", "urinary_symptom", "High", "emergency"),
        ("budlay mag-ihi ko", "difficulty urinating", "urinary_symptom", "High", "urgent"),
        ("mapait ihi ko", "foul urine", "urinary_symptom", "Moderate", "routine"),
        ("masakit pag-ihi kag masakit bilat ko", "UTI symptoms", "urinary_symptom", "Moderate", "urgent"),
        ("may nana sa ihi ko", "urinary infection", "infection", "High", "urgent"),
        ("masakit singit ko", "groin pain", "musculoskeletal", "Moderate", "urgent"),
        ("may pantal sa singit ko", "groin rash", "dermatological", "Low", "routine"),
        ("masakit pag-ihi ko kag may nana sa bilat ko", "urinary tract infection", "infection", "High", "urgent"),
    ]
    add_many(items)


def generate_natural_telemedicine() -> None:
    openers = ["subong", "karon", "doc", "help", "basin", "daw", "ginpangayo ko doctor"]
    seeds = [
        ("may nana sa bilat ko", "vaginal infection", "infection", "High", "urgent"),
        ("gadugo bilat ko", "vaginal bleeding", "gynecologic_symptom", "High", "urgent"),
        ("masakit ari ko", "penile pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("gahubag itlog ko", "testicular swelling", "male_reproductive_symptom", "Moderate", "urgent"),
        ("kakatol bilat ko", "vaginal itching", "female_reproductive_symptom", "Low", "routine"),
    ]
    add_many([(s[0], s[1], s[2], s[3], s[4]) for s in seeds])
    for hil, eng, cat, sev, tri in seeds:
        for opener in openers:
            add(
                Record(
                    hiligaynon_term=f"{opener} {hil}".strip(),
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=cat == "infection",
                )
            )


def generate_abbreviations() -> None:
    abbrevs = [
        ("sakit bilat", "vaginal pain", "female_reproductive_symptom", "Moderate", "urgent"),
        ("sakit ari", "penile pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("sakit itlog", "testicular pain", "male_reproductive_symptom", "Moderate", "urgent"),
        ("hubag bilat", "vaginal swelling", "female_reproductive_symptom", "Moderate", "urgent"),
        ("hubag ari", "penile swelling", "male_reproductive_symptom", "Moderate", "urgent"),
        ("dugo bilat", "vaginal bleeding", "gynecologic_symptom", "High", "urgent"),
        ("dugo ari", "penile bleeding", "male_reproductive_symptom", "High", "urgent"),
        ("nana bilat", "vaginal infection", "infection", "High", "urgent"),
        ("nana ari", "penile infection", "infection", "High", "urgent"),
        ("UTI ko", "urinary tract infection", "urinary_symptom", "Moderate", "urgent"),
    ]
    add_many(abbrevs)


def generate_typo_variants() -> None:
    priority = [r for r in RECORDS.values() if r.triage_level in {"urgent", "emergency"} or r.severity in {"High", "Critical"}]
    rules = [
        (r"\bbilat\b", "bilad"),
        (r"\bitlog\b", "itlug"),
        (r"\bakon\b", "acun"),
        (r"\bko\b", "ku"),
        (r"\bgadugo\b", "nagadugo"),
        (r"\bgahabok\b", "gahubag"),
        (r"\bkakatol\b", "gakatol"),
    ]
    for rec in priority[:600]:
        term = rec.hiligaynon_term
        for pattern, repl in rules:
            if re.search(pattern, term):
                alt = re.sub(pattern, repl, term, count=1)
                if alt != term:
                    add(
                        Record(
                            hiligaynon_term=alt,
                            english_term=rec.english_term,
                            medical_category=rec.medical_category,
                            severity=rec.severity,
                            triage_level=rec.triage_level,
                            body_system=rec.body_system,
                            body_part=rec.body_part,
                            symptom=rec.symptom,
                            condition=rec.condition,
                            is_condition=rec.is_condition,
                        )
                    )


def build_symptom_synonyms() -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for rec in RECORDS.values():
        key = (rec.english_term.lower(), rec.hiligaynon_term.lower())
        if key in seen:
            continue
        seen.add(key)
        rows.append(
            {
                "canonical_english": rec.english_term,
                "hiligaynon_term": rec.hiligaynon_term,
                "synonym_type": "patient_phrase",
                "medical_category": rec.medical_category,
                "status": "active",
            }
        )
    return sorted(rows, key=lambda r: (r["canonical_english"].lower(), r["hiligaynon_term"].lower()))


def build_misspellings() -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for correct, variants in MISSPELLINGS.items():
        for v in variants:
            if v == correct or " ko" in v or v.endswith(" ko"):
                continue
            key = f"{correct}|{v}"
            if key in seen:
                continue
            seen.add(key)
            rows.append(
                {
                    "correct_term": correct,
                    "misspelling": v,
                    "term_type": "reproductive_anatomy",
                    "status": "active",
                }
            )
    extra = [
        ("gadugo", "nagdugo"), ("masakit", "gasaket"), ("nagdugo", "nagadugo"),
        ("magginhawa", "mag ginhawa"), ("gasakit", "ga sakit"),
    ]
    for c, m in extra:
        key = f"{c}|{m}"
        if key not in seen:
            seen.add(key)
            rows.append({"correct_term": c, "misspelling": m, "term_type": "symptom", "status": "active"})
    return rows


def build_triage_rules() -> list[dict[str, str]]:
    rules = [
        ("kakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive_symptom", "Routine reproductive symptom"),
        ("gakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive_symptom", "Routine reproductive symptom"),
        ("may nana sa bilat ko", "vaginal infection", "urgent", "severe", "infection", "Possible genital infection — urgent evaluation"),
        ("may nana sa ari ko", "penile infection", "urgent", "severe", "infection", "Possible genital infection — urgent evaluation"),
        ("gahabok itlog ko", "swollen testicle", "urgent", "moderate", "male_reproductive_symptom", "Testicular swelling — urgent evaluation"),
        ("gahubag itlog ko", "testicular swelling", "urgent", "moderate", "male_reproductive_symptom", "Testicular swelling — urgent evaluation"),
        ("gadugo ari ko", "penile bleeding", "urgent", "severe", "male_reproductive_symptom", "Genital bleeding — urgent care"),
        ("gadugo bilat ko", "vaginal bleeding", "urgent", "severe", "gynecologic_symptom", "Vaginal bleeding — urgent care"),
        ("grabe gid nagadugo bilat ko", "severe vaginal bleeding", "emergency", "critical", "gynecologic_symptom", "Emergency — severe bleeding"),
        ("indi mapunggan ang dugo sa ari", "uncontrolled penile bleeding", "emergency", "critical", "male_reproductive_symptom", "Emergency — uncontrolled bleeding"),
        ("nautod ari ko", "penile amputation", "emergency", "critical", "trauma", "Emergency — severe genital trauma"),
        ("wala ko maka-ihi", "urinary retention", "emergency", "severe", "urinary_symptom", "Emergency — cannot urinate"),
        ("masakit dughan ko", "chest pain", "emergency", "severe", "cardiovascular", "Emergency — chest pain"),
        ("budlay magginhawa ko", "difficulty breathing", "emergency", "critical", "respiratory", "Emergency — breathing difficulty"),
    ]
    out: list[dict[str, str]] = []
    for hil, eng, tri, sev, cat, reason in rules:
        out.append(
            {
                "hiligaynon_pattern": hil,
                "english_pattern": eng,
                "triage_level": tri,
                "severity": sev,
                "medical_category": cat,
                "reason": reason,
                "status": "active",
            }
        )
    return out


def write_csv(path: Path, rows: list[dict[str, str]], fields: list[str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        for row in rows:
            w.writerow(row)


def merge_csv_records(path: Path, new_records: list[Record], fields: list[str]) -> int:
    existing: set[tuple[str, str]] = set()
    rows: list[dict[str, str]] = []
    if path.is_file():
        with path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                k = ((row.get("hiligaynon_term") or "").lower(), (row.get("english_term") or "").lower())
                existing.add(k)
                rows.append(row)
    added = 0
    for rec in new_records:
        k = rec.key()
        if k in existing:
            continue
        existing.add(k)
        rows.append(
            {
                "hiligaynon_term": rec.hiligaynon_term,
                "english_term": rec.english_term,
                "medical_category": rec.medical_category,
                "severity": rec.severity,
                "triage_level": rec.triage_level,
                "status": rec.status,
            }
        )
        added += 1
    write_csv(path, rows, fields)
    return added


def merge_anatomy_body_parts(new_rows: list[dict[str, str]]) -> None:
    path = NLP / "body_parts.csv"
    existing: set[str] = set()
    rows: list[dict[str, str]] = []
    if path.is_file():
        with path.open(encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            for row in reader:
                hil = (row.get("hiligaynon_term") or "").strip().lower()
                if not hil:
                    continue
                if "body_system" not in row and "medical_category" in row:
                    row = {
                        "hiligaynon_term": row.get("hiligaynon_term", ""),
                        "english_term": row.get("english_term", ""),
                        "body_system": row.get("medical_category", "general"),
                        "anatomy_category": row.get("medical_category", "general"),
                        "status": row.get("status", "active"),
                    }
                existing.add(hil)
                rows.append(row)
    for row in new_rows:
        hil = row["hiligaynon_term"].lower()
        if hil in existing:
            continue
        existing.add(hil)
        rows.append(row)
    write_csv(path, rows, BODY_PART_FIELDS)


def merge_medical_dictionary(records: list[Record]) -> int:
    dict_path = NLP / "medical_dictionary.csv"
    existing: dict[str, tuple[str, str, str]] = {}
    if dict_path.is_file():
        with dict_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                local = (row.get("local_term") or "").strip().lower()
                if local:
                    existing[local] = (
                        row.get("local_term") or "",
                        row.get("english_term") or "",
                        row.get("category") or "condition",
                    )
    added = 0
    anatomy_terms = {a[0].lower() for a in ANATOMY}
    for rec in records:
        local = rec.hiligaynon_term.strip()
        key = local.lower()
        if key in existing or len(local) < 2:
            continue
        base = local.split()[0]
        cat = "body_part" if base in anatomy_terms and rec.medical_category == "Body Part" else "condition"
        if rec.is_condition or rec.medical_category in {"infection", "injury", "trauma", "gynecologic_symptom"}:
            cat = "condition"
        existing[key] = (local, rec.english_term, cat)
        added += 1
    with dict_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["dictionary_id", "local_term", "english_term", "category"])
        for i, (_, (lt, en, cat)) in enumerate(sorted(existing.items(), key=lambda x: x[1][0].lower()), start=1):
            w.writerow([i, lt, en, cat])
    return added


def append_nlp_dataset(records: list[Record]) -> int:
    nlp_path = NLP / "hiligaynon_medical_nlp_dataset.csv"
    existing_terms: set[str] = set()
    max_id = 0
    rows_out: list[dict[str, str]] = []
    if nlp_path.is_file():
        with nlp_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                term = (row.get("hiligaynon_term") or "").strip().lower()
                if term:
                    existing_terms.add(term)
                try:
                    max_id = max(max_id, int(row.get("id") or 0))
                except ValueError:
                    pass
                rows_out.append(row)
    added = 0
    for rec in records:
        term = rec.hiligaynon_term.strip()
        if term.lower() in existing_terms:
            continue
        max_id += 1
        existing_terms.add(term.lower())
        kw = rec.english_term.lower().replace(" ", ";")
        rows_out.append(
            {
                "id": str(max_id),
                "hiligaynon_term": term,
                "alternative_spellings": "",
                "english_translation": rec.english_term,
                "medical_term": rec.english_term,
                "medical_category": rec.medical_category,
                "body_system": rec.body_system or "general",
                "severity": rec.severity,
                "symptom_keywords": kw,
                "confidence_keywords": kw,
            }
        )
        added += 1
    with nlp_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=NLP_FIELDS, extrasaction="ignore")
        w.writeheader()
        for row in rows_out:
            w.writerow(row)
    return added


def merge_into_symptom_phrases(records: list[Record]) -> int:
    path = NLP / "symptom_phrases.csv"
    existing: set[tuple[str, str]] = set()
    rows: list[dict[str, str]] = []
    if path.is_file():
        with path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                k = ((row.get("hiligaynon_term") or "").lower(), (row.get("english_term") or "").lower())
                existing.add(k)
                rows.append(row)
    added = 0
    for rec in records:
        k = rec.key()
        if k in existing:
            continue
        existing.add(k)
        rows.append(
            {
                "hiligaynon_term": rec.hiligaynon_term,
                "english_term": rec.english_term,
                "medical_category": rec.medical_category,
                "severity": rec.severity,
                "triage_level": rec.triage_level,
                "status": rec.status,
            }
        )
        added += 1
    write_csv(path, rows, CSV_FIELDS)
    return added


def main() -> None:
    register_anatomy()
    generate_reproductive_symptoms()
    generate_user_examples()
    generate_severe_emergency()
    generate_urinary_genital()
    generate_natural_telemedicine()
    generate_abbreviations()
    generate_typo_variants()

    all_records = sorted(RECORDS.values(), key=lambda r: r.hiligaynon_term.lower())
    print(f"Generated {len(all_records)} reproductive / genital / urinary records")

    anatomy_rows = generate_anatomy_csv_rows()
    merge_anatomy_body_parts(anatomy_rows)

    write_csv(NLP / "hiligaynon_reproductive_expansion.csv", [
        {
            "hiligaynon_term": r.hiligaynon_term,
            "english_term": r.english_term,
            "medical_category": r.medical_category,
            "severity": r.severity,
            "triage_level": r.triage_level,
            "status": r.status,
        }
        for r in all_records
    ], CSV_FIELDS)

    symptoms_added = merge_csv_records(NLP / "hiligaynon_symptoms.csv", all_records, CSV_FIELDS)
    conditions_added = merge_csv_records(
        NLP / "hiligaynon_conditions.csv",
        [r for r in all_records if r.is_condition or r.medical_category in {"infection", "injury", "gynecologic_symptom", "trauma"}],
        CSV_FIELDS,
    )

    merge_added = merge_into_symptom_phrases(all_records)
    merge_synonyms_path = NLP / "symptom_synonyms.csv"
    existing_syn: set[tuple[str, str]] = set()
    syn_rows: list[dict[str, str]] = []
    if merge_synonyms_path.is_file():
        with merge_synonyms_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                k = ((row.get("hiligaynon_term") or "").lower(), (row.get("canonical_english") or "").lower())
                existing_syn.add(k)
                syn_rows.append(row)
    for row in build_symptom_synonyms():
        k = (row["hiligaynon_term"].lower(), row["canonical_english"].lower())
        if k not in existing_syn:
            existing_syn.add(k)
            syn_rows.append(row)
    write_csv(merge_synonyms_path, syn_rows, [
        "canonical_english", "hiligaynon_term", "synonym_type", "medical_category", "status",
    ])

    miss_path = NLP / "medical_misspellings.csv"
    write_csv(miss_path, build_misspellings(), ["correct_term", "misspelling", "term_type", "status"])

    tri_path = NLP / "triage_rules.csv"
    existing_tri: set[str] = set()
    tri_rows: list[dict[str, str]] = []
    if tri_path.is_file():
        with tri_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                k = (row.get("hiligaynon_pattern") or "").lower()
                existing_tri.add(k)
                tri_rows.append(row)
    for row in build_triage_rules():
        k = row["hiligaynon_pattern"].lower()
        if k not in existing_tri:
            existing_tri.add(k)
            tri_rows.append(row)
    write_csv(tri_path, tri_rows, [
        "hiligaynon_pattern", "english_pattern", "triage_level", "severity", "medical_category", "reason", "status",
    ])

    dict_added = merge_medical_dictionary(all_records)
    nlp_added = append_nlp_dataset(all_records)

    print(f"body_parts.csv anatomy rows merged: {len(anatomy_rows)}")
    print(f"hiligaynon_symptoms.csv merged: +{symptoms_added}")
    print(f"hiligaynon_conditions.csv merged: +{conditions_added}")
    print(f"symptom_phrases.csv merged: +{merge_added}")
    print(f"medical_dictionary.csv merged: +{dict_added}")
    print(f"hiligaynon_medical_nlp_dataset.csv appended: +{nlp_added}")


if __name__ == "__main__":
    main()
