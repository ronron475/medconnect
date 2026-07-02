#!/usr/bin/env python3
"""
Generate Western Visayas Hiligaynon telemedicine NLP datasets.

Outputs:
  data/nlp/hiligaynon_symptoms.csv
  data/nlp/hiligaynon_conditions.csv
  data/nlp/symptom_phrases.csv
  data/nlp/body_parts.csv
  data/nlp/hiligaynon_wv_expansion.csv  (master)
  Merges tokens into medical_dictionary.csv
  Appends rows to hiligaynon_medical_nlp_dataset.csv
"""

from __future__ import annotations

import csv
import itertools
import re
from dataclasses import dataclass
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
    is_condition: bool = False

    def key(self) -> tuple[str, str]:
        return (self.hiligaynon_term.lower().strip(), self.english_term.lower().strip())


RECORDS: dict[tuple[str, str], Record] = {}


def add(rec: Record) -> None:
    term = rec.hiligaynon_term.strip()
    if not term or len(term) < 2:
        return
    k = rec.key()
    if k in RECORDS:
        return
    RECORDS[k] = rec


def add_many(items: list[tuple[str, str, str, str, str]]) -> None:
    """(hil_term, english, category, severity, triage)"""
    for hil, eng, cat, sev, tri in items:
        add(
            Record(
                hiligaynon_term=hil,
                english_term=eng,
                medical_category=cat,
                severity=sev,
                triage_level=tri,
                is_condition=cat in {"Fracture", "Infection", "Trauma", "Emergency"},
            )
        )


# ── Body parts (Hiligaynon → English, body_system) ───────────────────────────
BODY_PARTS: list[tuple[str, str, str, str]] = [
    ("ulo", "head", "Neurological", "head"),
    ("mata", "eye", "Eye", "eye"),
    ("dalunggan", "ear", "Ear", "ear"),
    ("ilong", "nose", "Respiratory", "nose"),
    ("baba", "mouth", "Oral", "mouth"),
    ("ngipon", "tooth", "Oral", "tooth"),
    ("dila", "tongue", "Oral", "tongue"),
    ("liog", "neck", "Musculoskeletal", "neck"),
    ("abaga", "shoulder", "Musculoskeletal", "shoulder"),
    ("dughan", "chest", "Cardiovascular", "chest"),
    ("tiyan", "abdomen", "Gastrointestinal", "abdomen"),
    ("likod", "back", "Musculoskeletal", "back"),
    ("kamot", "hand", "Musculoskeletal", "hand"),
    ("tudlo", "finger", "Musculoskeletal", "finger"),
    ("tuhod", "knee", "Musculoskeletal", "knee"),
    ("tiil", "foot", "Musculoskeletal", "foot"),
    ("paa", "foot", "Musculoskeletal", "foot"),
    ("pisngi", "cheek", "Oral", "face"),
    ("bukong", "forehead", "Neurological", "head"),
    ("tai", "buttocks", "Musculoskeletal", "hip"),
    ("siko", "elbow", "Musculoskeletal", "elbow"),
    ("buuk", "arm", "Musculoskeletal", "arm"),
    ("batiis", "leg", "Musculoskeletal", "leg"),
    ("kuko", "nail", "Dermatological", "nail"),
    ("buhok", "hair", "Dermatological", "hair"),
    ("pilas", "wound", "Trauma", "skin"),
    ("balat", "skin", "Dermatological", "skin"),
    ("tungod", "throat", "Respiratory", "throat"),
    ("awang", "jaw", "Oral", "jaw"),
    ("tadlong", "forearm", "Musculoskeletal", "arm"),
    ("tikod", "heel", "Musculoskeletal", "foot"),
    ("tungtong", "ankle", "Musculoskeletal", "ankle"),
    ("lawas", "body", "General", "general"),
    ("tagipusuon", "heart", "Cardiovascular", "heart"),
    ("atay", "liver", "Gastrointestinal", "liver"),
    ("bato", "kidney", "Urinary", "kidney"),
    ("utok", "brain", "Neurological", "brain"),
    ("titi", "breast", "General", "breast"),
    ("baywang", "waist", "Musculoskeletal", "waist"),
    ("lawi", "nape", "Musculoskeletal", "neck"),
]

POSSESSIVES = ["ko", "akon", "gid ko", "subong", "man"]
LOCATION_MARKERS = ["sa", "sang", "kay"]

PAIN_PREFIXES = ["gasakit", "sakit", "ginasakit", "masakit", "hapdi", "ga sakit", "sakit gid"]
SWELL_PREFIXES = ["gahabok", "hubag", "naghubag", "ginahubag", "gahubag", "ginapamulang", "hubag gid"]
PUS_PREFIXES = ["may nana", "may nana sa", "may nana akon", "may nana ko sa", "may nana sa akon"]

MISSPELLING_MAP = {
    "nana": ["nana", "nanaa", "nanna", "nana "],
    "gahika": ["gahika", "gahhika", "gihika", "gahika gid", "gahika ko gid"],
    "gasakit": ["gasakit", "gasaket", "gasakit gid", "ga sakit", "gasakit ko gid"],
    "gahabok": ["gahabok", "gahubag", "gahubok", "gahubag gid"],
    "magginhawa": ["magginhawa", "mag ginhawa", "magginhawa ko", "magginhawa gid"],
    "pilas": ["pilas", "pilas ko", "pilas gid"],
    "nabali": ["nabali", "nabali gid", "nabali ko", "nabali na"],
    "nautod": ["nautod", "nautod gid", "nautod ko", "nautod na"],
    "mata": ["mata", "mata ko", "mata ko gid"],
    "kamot": ["kamot", "kamot ko", "kamot ko gid"],
    "tiil": ["tiil", "tiil ko", "tiiil"],
    "dalunggan": ["dalunggan", "dalunggan ko", "dalungan"],
    "hilanat": ["hilanat", "hilanat ko", "hilanat gid"],
    "nagdugo": ["nagdugo", "nagdugo gid", "nagdugo ko"],
    "ginkagat": ["ginkagat", "gin kagat", "ginkagat ko"],
    "nakuryente": ["nakuryente", "nakuryente ko", "na kuryente"],
}

# Regional / slang (Western Visayas telemedicine)
SLANG_POSSESSIVE = ["ko", "akon", "ko gid", "akon gid", "ko man", "ako", "akon subong"]
REGIONAL_BODY = {
    "mata": ["mata", "mata ko", "mata ko gid"],
    "kamot": ["kamot", "kamot ko", "kamot ko gid"],
    "tiil": ["tiil", "paa", "tiil ko", "paa ko"],
    "ulo": ["ulo", "ulo ko", "olok"],
    "tiyan": ["tiyan", "tiyan ko", "tina-i"],
}
NATURAL_OPENERS = [
    "subong",
    "karon",
    "ginpangayo ko doctor",
    "basin",
    "daw",
    "wala ko kasugtan",
    "ginabalaka ko",
    "dugay na",
    "bag-o lang",
]
NATURAL_CLOSERS = ["gid", "man", "gid ko", "ko gid", "na gid", "subong", "karon"]
DURATION_MARKERS = ["dugay na", "3 ka adlaw", "5 ka adlaw", "semana na", "bag-o lang", "gahapon", "kagapon", "subong lang"]
CONNECTORS = ["kag", "sang", "upod sang", "upod sa"]
QUESTION_STARTERS = ["basin", "daw", "posible", "ano ang buhaton", "perme"]


def variants(word: str) -> list[str]:
    base = [word]
    for key, alts in MISSPELLING_MAP.items():
        if key in word:
            base.extend(a for a in alts if a != word)
    return list(dict.fromkeys(base))


def generate_body_parts() -> None:
    for hil, eng, cat, _ in BODY_PARTS:
        add(
            Record(
                hiligaynon_term=hil,
                english_term=eng,
                medical_category="Body Part",
                severity="Low",
                triage_level="routine",
                body_system=cat,
            )
        )
        for alt in variants(hil):
            if alt != hil:
                add(
                    Record(
                        hiligaynon_term=alt,
                        english_term=eng,
                        medical_category="Body Part",
                        severity="Low",
                        triage_level="routine",
                        body_system=cat,
                    )
                )


def generate_pain() -> None:
    for hil_body, eng_body, body_sys, _ in BODY_PARTS:
        eng_pain = f"{eng_body} pain"
        if eng_body == "abdomen":
            eng_pain = "abdominal pain"
        if eng_body == "chest":
            eng_pain = "chest pain"
        sev = "High" if eng_body in {"chest", "head", "abdomen"} else "Medium"
        tri = "urgent" if eng_body in {"chest", "head"} else "routine"
        patterns: set[str] = set()
        for prefix in PAIN_PREFIXES:
            for pos in POSSESSIVES:
                patterns.add(f"{prefix} {hil_body} {pos}")
                patterns.add(f"{prefix} akon {hil_body}")
            patterns.add(f"sakit {hil_body} ko")
            patterns.add(f"sakit sa {hil_body} ko")
            patterns.add(f"masakit {hil_body} ko")
            patterns.add(f"hapdi {hil_body} ko")
        for p in patterns:
            add(
                Record(
                    hiligaynon_term=p,
                    english_term=eng_pain,
                    medical_category="Pain",
                    severity=sev,
                    triage_level=tri,
                    body_system=body_sys,
                )
            )


def generate_swelling() -> None:
    eng_suffix = {
        "dila": "swollen tongue",
        "mata": "eye swelling",
        "kamot": "hand swelling",
        "tiil": "foot swelling",
        "paa": "foot swelling",
        "pisngi": "facial swelling",
        "baba": "lip swelling",
        "liog": "neck swelling",
        "tuhod": "knee swelling",
        "ulo": "head swelling",
        "tudlo": "finger swelling",
        "abaga": "shoulder swelling",
        "siko": "elbow swelling",
        "batiis": "leg swelling",
        "buuk": "arm swelling",
        "ilong": "nasal swelling",
        "dalunggan": "ear swelling",
        "tungod": "throat swelling",
        "tiyan": "abdominal swelling",
        "tikod": "heel swelling",
        "tungtong": "ankle swelling",
        "lawas": "body swelling",
    }
    for hil_body, eng_body, body_sys, _ in BODY_PARTS:
        eng = eng_suffix.get(hil_body, f"{eng_body} swelling")
        for prefix in SWELL_PREFIXES:
            for pos in POSSESSIVES + SLANG_POSSESSIVE[:4]:
                add(
                    Record(
                        hiligaynon_term=f"{prefix} {hil_body} {pos}".replace("  ", " "),
                        english_term=eng,
                        medical_category="Swelling",
                        severity="Medium" if hil_body not in {"tungod", "dila"} else "High",
                        triage_level="emergency" if hil_body in {"tungod", "dila"} else "urgent",
                        body_system=body_sys,
                    )
                )
            add(
                Record(
                    hiligaynon_term=f"{prefix} akon {hil_body}",
                    english_term=eng,
                    medical_category="Swelling",
                    severity="Medium",
                    triage_level="urgent",
                    body_system=body_sys,
                )
            )
            add(
                Record(
                    hiligaynon_term=f"hubag {hil_body} ko",
                    english_term=eng,
                    medical_category="Swelling",
                    severity="Medium",
                    triage_level="urgent",
                    body_system=body_sys,
                )
            )


def generate_pus_infection() -> None:
    eng_map = {
        "mata": "eye infection",
        "pilas": "infected wound",
        "kamot": "skin infection",
        "tiil": "infected foot",
        "paa": "infected foot",
        "tudlo": "infected finger",
        "ilong": "infected nose",
        "dalunggan": "infected ear",
        "baba": "oral infection",
        "balat": "skin infection",
        "kuko": "nail infection",
        "pilas sa kamot": "infected hand wound",
    }
    add_many(
        [
            ("may nana ko", "pus", "Infection", "Medium", "urgent"),
            ("may nana ako", "pus", "Infection", "Medium", "urgent"),
            ("may nana", "pus", "Infection", "Medium", "urgent"),
        ]
    )
    for hil_body, eng_body, body_sys, _ in BODY_PARTS:
        if hil_body in {"pilas"}:
            continue
        eng = eng_map.get(hil_body, f"{eng_body} infection")
        templates = [
            f"may nana {hil_body} ko",
            f"may nana akon {hil_body}",
            f"may nana sa {hil_body} ko",
            f"may nana ko sa {hil_body}",
            f"may nana sa akon {hil_body}",
            f"may nana {hil_body}",
        ]
        sev = "High" if hil_body in {"mata", "pilas", "tudlo"} else "Medium"
        tri = "urgent" if hil_body == "mata" else "routine"
        for t in templates:
            add(
                Record(
                    hiligaynon_term=t,
                    english_term=eng,
                    medical_category="Infection",
                    severity=sev,
                    triage_level=tri,
                    body_system=body_sys,
                    is_condition=True,
                )
            )


def generate_eye_conditions() -> None:
    items = [
        ("may black eye ko", "black eye", "Eye", "Medium", "urgent"),
        ("may black eye ako", "black eye", "Eye", "Medium", "urgent"),
        ("gapula mata ko", "red eye", "Eye", "Medium", "urgent"),
        ("gapula akon mata", "red eye", "Eye", "Medium", "urgent"),
        ("gahubag mata ko", "eye swelling", "Eye", "Medium", "urgent"),
        ("gahubag akon mata", "eye swelling", "Eye", "Medium", "urgent"),
        ("nagaluha mata ko", "watery eyes", "Eye", "Low", "routine"),
        ("nagaluha akon mata", "watery eyes", "Eye", "Low", "routine"),
        ("may nana mata ko", "eye infection", "Eye", "High", "urgent"),
        ("may nana akon mata", "eye infection", "Eye", "High", "urgent"),
        ("gakatol mata ko", "itchy eyes", "Eye", "Low", "routine"),
        ("gakatol akon mata", "itchy eyes", "Eye", "Low", "routine"),
        ("masakit mata ko", "eye pain", "Eye", "Medium", "urgent"),
        ("sakit mata ko", "eye pain", "Eye", "Medium", "urgent"),
        ("gasakit mata ko", "eye pain", "Eye", "Medium", "urgent"),
        ("buling mata ko", "blurred vision", "Eye", "Medium", "urgent"),
        ("dulom mata ko", "vision loss", "Eye", "Critical", "emergency"),
    ]
    add_many(items)


def generate_respiratory() -> None:
    items = [
        ("gahika ko", "cough", "Respiratory", "Medium", "routine"),
        ("gahika gid ko", "severe cough", "Respiratory", "High", "urgent"),
        ("gahika ako", "cough", "Respiratory", "Medium", "routine"),
        ("ubo ko", "cough", "Respiratory", "Medium", "routine"),
        ("ginauubo ko", "cough", "Respiratory", "Medium", "routine"),
        ("ginahilanat ko kag gahika ko", "fever with cough", "Respiratory", "High", "urgent"),
        ("may hilanat ko kag ubo", "fever with cough", "Respiratory", "High", "urgent"),
        ("budlay magginhawa ko", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("budlay ko magginhawa", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("budlay gid ko magginhawa", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("lisod magginhawa ko", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("ginabudlayan ginhawa ko", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("dula ginhawa ko", "shortness of breath", "Respiratory", "Critical", "emergency"),
        ("ginadula ginhawa ko", "shortness of breath", "Respiratory", "Critical", "emergency"),
        ("mapula lawas ko kag gahika", "fever with cough", "Respiratory", "High", "urgent"),
    ]
    add_many(items)
    for v in variants("gahika"):
        add(
            Record(
                hiligaynon_term=f"{v} ko",
                english_term="cough",
                medical_category="Respiratory",
                severity="Medium",
                triage_level="routine",
            )
        )


def generate_wounds() -> None:
    wound_sites = [
        ("kamot", "hand wound"),
        ("tiil", "foot wound"),
        ("paa", "foot wound"),
        ("ulo", "head wound"),
        ("tudlo", "finger wound"),
        ("batiis", "leg wound"),
        ("buuk", "arm wound"),
        ("likod", "back wound"),
        ("tiyan", "abdominal wound"),
    ]
    for site, eng in wound_sites:
        templates = [
            f"may pilas ko sa {site}",
            f"may pilas ako sa {site}",
            f"may pilas sa {site} ko",
            f"may pilas akon {site}",
            f"nagdugo pilas ko sa {site}",
            f"nagdugo pilas sa {site} ko",
            f"nagdugo ang pilas sa {site} ko",
        ]
        for t in templates:
            add(
                Record(
                    hiligaynon_term=t,
                    english_term=eng,
                    medical_category="Wound",
                    severity="Medium",
                    triage_level="urgent",
                    is_condition=True,
                )
            )
    add_many(
        [
            ("nagdugo pilas ko", "bleeding wound", "Wound", "High", "emergency"),
            ("nagdugo ang pilas ko", "bleeding wound", "Wound", "High", "emergency"),
            ("dugo gid ang pilas ko", "bleeding wound", "Wound", "High", "emergency"),
        ]
    )


def generate_amputation() -> None:
    sites = [
        ("kamot", "amputated hand"),
        ("tudlo", "amputated finger"),
        ("tiil", "amputated foot"),
        ("paa", "amputated foot"),
        ("kuko", "nail avulsion"),
        ("buuk", "amputated arm"),
    ]
    for site, eng in sites:
        for prefix in ["nautod", "nautod gid", "nautod ko ang", "nautod ang"]:
            add(
                Record(
                    hiligaynon_term=f"{prefix} {site} ko".replace("  ", " "),
                    english_term=eng,
                    medical_category="Trauma",
                    severity="Critical",
                    triage_level="emergency",
                    is_condition=True,
                )
            )
            add(
                Record(
                    hiligaynon_term=f"{prefix} akon {site}",
                    english_term=eng,
                    medical_category="Trauma",
                    severity="Critical",
                    triage_level="emergency",
                    is_condition=True,
                )
            )


def generate_fractures() -> None:
    sites = [
        ("kamot", "hand fracture", "suspected hand fracture"),
        ("tiil", "foot fracture", "suspected foot fracture"),
        ("tudlo", "finger fracture", "suspected finger fracture"),
        ("tuhod", "knee fracture", "suspected knee fracture"),
        ("buuk", "arm fracture", "suspected arm fracture"),
        ("batiis", "leg fracture", "suspected leg fracture"),
        ("ulo", "skull fracture", "suspected skull fracture"),
    ]
    for site, eng, suspected in sites:
        for template, english in [
            (f"daw mabali {site} ko", suspected),
            (f"daw mabali akon {site}", suspected),
            (f"nabali {site} ko", eng),
            (f"nabali akon {site}", eng),
            (f"nabali gid {site} ko", eng),
        ]:
            add(
                Record(
                    hiligaynon_term=template,
                    english_term=english,
                    medical_category="Fracture",
                    severity="High",
                    triage_level="emergency",
                    is_condition=True,
                )
            )


def generate_trauma() -> None:
    items = [
        ("ginsumbag ko", "physical assault", "Trauma", "High", "urgent"),
        ("ginsumbag ako", "physical assault", "Trauma", "High", "urgent"),
        ("nabun-og ko", "bruise", "Trauma", "Low", "routine"),
        ("nabun-og ako", "bruise", "Trauma", "Low", "routine"),
        ("naipit ko", "crush injury", "Trauma", "High", "urgent"),
        ("naipit ang kamot ko", "crush injury", "Trauma", "High", "urgent"),
        ("nabunggo ko", "collision injury", "Trauma", "High", "urgent"),
        ("natumba ko", "fall injury", "Trauma", "Medium", "urgent"),
        ("nadanlog ko", "slip injury", "Trauma", "Medium", "urgent"),
        ("nahuad ko", "fall injury", "Trauma", "Medium", "urgent"),
        ("nabalian ko", "sprain", "Trauma", "Medium", "routine"),
    ]
    add_many(items)


def generate_weakness() -> None:
    items = [
        ("galuya ko", "weakness", "General", "Medium", "routine"),
        ("galuya lawas ko", "body weakness", "General", "Medium", "routine"),
        ("galuya gid lawas ko", "body weakness", "General", "High", "urgent"),
        ("wala ko kusog", "fatigue", "General", "Medium", "routine"),
        ("wala na ko kusog", "fatigue", "General", "Medium", "routine"),
        ("kapoy gid ko", "severe fatigue", "General", "Medium", "routine"),
        ("kapoy ko gid", "severe fatigue", "General", "Medium", "routine"),
        ("huyang lawas ko", "weakness", "General", "Medium", "routine"),
        ("dali kapoy ko", "fatigue", "General", "Low", "routine"),
    ]
    add_many(items)


def generate_hair() -> None:
    items = [
        ("galagas buhok ko", "hair loss", "Dermatological", "Low", "routine"),
        ("nagapanipis buhok ko", "hair thinning", "Dermatological", "Low", "routine"),
        ("nagakalbo ko", "baldness", "Dermatological", "Low", "routine"),
        ("nagkalbo ako", "baldness", "Dermatological", "Low", "routine"),
        ("nagaagay buhok ko", "hair loss", "Dermatological", "Low", "routine"),
    ]
    add_many(items)


def generate_accidents() -> None:
    items = [
        ("nabunggo ko sa bato", "head injury from rock", "Trauma", "High", "emergency"),
        ("nabunggo ako sa bato", "head injury from rock", "Trauma", "High", "emergency"),
        ("nabunggo ko sa semento", "head injury from concrete", "Trauma", "Critical", "emergency"),
        ("nabunggo ko sa pader", "wall collision injury", "Trauma", "High", "urgent"),
        ("nabunggo ko sa salakyan", "vehicle collision injury", "Trauma", "Critical", "emergency"),
        ("nabangga ako sang salakyan", "vehicle collision injury", "Trauma", "Critical", "emergency"),
        ("nabangga ko sang motor", "vehicle collision injury", "Trauma", "Critical", "emergency"),
    ]
    add_many(items)


def generate_electrical() -> None:
    items = [
        ("nakuryente ko", "electrical injury", "Trauma", "Critical", "emergency"),
        ("nakuryente ako", "electrical injury", "Trauma", "Critical", "emergency"),
        ("nakuryente gamay ko", "minor electrical shock", "Trauma", "Medium", "urgent"),
        ("nakuryente gid ko", "electrical injury", "Trauma", "Critical", "emergency"),
    ]
    add_many(items)


def generate_bites() -> None:
    agents = [
        ("lamok", "mosquito bite"),
        ("ipis", "cockroach bite"),
        ("ido", "dog bite"),
        ("kuring", "cat bite"),
        ("sapat", "animal bite"),
        ("ahas", "snake bite"),
        ("manok", "animal bite"),
    ]
    for agent, eng in agents:
        sev = "Critical" if agent in {"ido", "ahas"} else "Medium"
        tri = "emergency" if agent in {"ido", "ahas"} else "urgent"
        for template in [
            f"ginkagat sang {agent} ko",
            f"ginkagat ako sang {agent}",
            f"ginkagat {agent} ako",
            f"ginakagat sang {agent} ko",
            f"may kagat sang {agent} ko",
        ]:
            add(
                Record(
                    hiligaynon_term=template,
                    english_term=eng,
                    medical_category="Bite",
                    severity=sev,
                    triage_level=tri,
                    is_condition=True,
                )
            )


def generate_head_injury() -> None:
    items = [
        ("nadanlug ko kag nagdugo ulo", "head injury with bleeding", "Trauma", "Critical", "emergency"),
        ("nabuklan ko sa ulo", "head hematoma", "Trauma", "High", "emergency"),
        ("nagdugo ulo ko", "head bleeding", "Trauma", "Critical", "emergency"),
        ("nagdugo ang ulo ko", "head bleeding", "Trauma", "Critical", "emergency"),
        ("naghubag ulo ko", "head swelling", "Trauma", "High", "emergency"),
        ("nabun-og ulo ko", "head bruise", "Trauma", "Medium", "urgent"),
        ("natumba ko kag nasamdan ulo ko", "head injury from fall", "Trauma", "Critical", "emergency"),
    ]
    add_many(items)


def generate_fever_general() -> None:
    items = [
        ("ginahilanat ko", "fever", "General", "Medium", "routine"),
        ("may hilanat ko", "fever", "General", "Medium", "routine"),
        ("init lawas ko", "fever", "General", "Medium", "routine"),
        ("mapula lawas ko", "fever", "General", "Medium", "routine"),
        ("mainit lawas ko", "fever", "General", "Medium", "routine"),
        ("ginahilanat gid ko", "high fever", "General", "High", "urgent"),
        ("38 hilanat ko", "fever", "General", "Medium", "routine"),
        ("39 hilanat ko", "high fever", "General", "High", "urgent"),
    ]
    add_many(items)


def generate_bleeding() -> None:
    sites = [
        ("ulo", "head bleeding"),
        ("ilong", "nosebleed"),
        ("baba", "oral bleeding"),
        ("kamot", "hand bleeding"),
        ("tudlo", "finger bleeding"),
        ("tiil", "foot bleeding"),
        ("pilas", "bleeding wound"),
    ]
    for site, eng in sites:
        for t in [
            f"nagdugo {site} ko",
            f"nagdugo akon {site}",
            f"nagdugo gid {site} ko",
            f"dugo gid ang {site} ko",
            f"may dugo sa {site} ko",
        ]:
            add(
                Record(
                    hiligaynon_term=t,
                    english_term=eng,
                    medical_category="Bleeding",
                    severity="High" if site in {"ulo", "pilas"} else "Medium",
                    triage_level="emergency" if site in {"ulo", "pilas"} else "urgent",
                    is_condition=True,
                )
            )


def generate_itching_rash() -> None:
    sites = [
        ("balat", "itchy skin"),
        ("kamot", "itchy hand"),
        ("tiil", "itchy foot"),
        ("mata", "itchy eyes"),
        ("tudlo", "itchy finger"),
    ]
    for site, eng in sites:
        for prefix in ["gakatol", "ginakatol", "makati", "gakatol gid"]:
            for pos in POSSESSIVES:
                add(
                    Record(
                        hiligaynon_term=f"{prefix} {site} {pos}".replace("  ", " "),
                        english_term=eng,
                        medical_category="Dermatological",
                        severity="Low",
                        triage_level="routine",
                    )
                )
    add_many(
        [
            ("may pantal ko", "skin rash", "Dermatological", "Low", "routine"),
            ("mapula balat ko", "skin rash", "Dermatological", "Medium", "routine"),
            ("may pantal sa lawas ko", "skin rash", "Dermatological", "Medium", "routine"),
        ]
    )


def generate_gi_symptoms() -> None:
    items = [
        ("ginabaldom ko", "abdominal pain", "Gastrointestinal", "Medium", "urgent"),
        ("ginabaldom gid ko", "severe abdominal pain", "Gastrointestinal", "High", "urgent"),
        ("ginasuka ko", "nausea", "Gastrointestinal", "Medium", "routine"),
        ("ginasuka kag ginabaldom ko", "nausea with abdominal pain", "Gastrointestinal", "High", "urgent"),
        ("ginadudul-om ko", "diarrhea", "Gastrointestinal", "Medium", "routine"),
        ("ginadudul-om gid ko", "severe diarrhea", "Gastrointestinal", "High", "urgent"),
        ("wala ko gusto magkaon", "loss of appetite", "Gastrointestinal", "Low", "routine"),
        ("ginahubag tiyan ko", "abdominal swelling", "Gastrointestinal", "Medium", "urgent"),
    ]
    add_many(items)


def generate_neuro_symptoms() -> None:
    items = [
        ("nahilo ko", "dizziness", "Neurological", "Medium", "urgent"),
        ("nahilo gid ko", "severe dizziness", "Neurological", "High", "urgent"),
        ("nahulog ko", "syncope", "Neurological", "High", "emergency"),
        ("nawala ko panan-aw", "vision loss", "Neurological", "Critical", "emergency"),
        ("naguyam ko", "seizure", "Neurological", "Critical", "emergency"),
        ("daw mabali ulo ko", "suspected head injury", "Neurological", "High", "emergency"),
    ]
    add_many(items)


def generate_natural_patient_statements() -> None:
    """Conversational telemedicine phrases — alternate word order and fillers."""
    seeds: list[tuple[str, str, str, str, str]] = [
        ("may nana akon mata subong", "eye infection", "Infection", "High", "urgent"),
        ("gahabok dila ko gid", "swollen tongue", "Swelling", "High", "emergency"),
        ("gasakit ulo ko karon", "head pain", "Pain", "Medium", "urgent"),
        ("ubo ko kag ginahilanat", "fever with cough", "Respiratory", "High", "urgent"),
        ("budlay gid ko magginhawa subong", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("may pilas ko sa kamot kag nagdugo", "bleeding hand wound", "Wound", "High", "emergency"),
        ("nautod tudlo ko sang makina", "amputated finger", "Trauma", "Critical", "emergency"),
        ("nabali kamot ko sang natumba", "hand fracture", "Fracture", "High", "emergency"),
        ("ginsumbag ko kag nabun-og ulo", "physical assault with head bruise", "Trauma", "High", "emergency"),
        ("galuya lawas ko kag kapoy gid", "body weakness with severe fatigue", "General", "Medium", "routine"),
        ("nabunggo ko sa salakyan kag nagdugo ulo", "vehicle collision with head bleeding", "Trauma", "Critical", "emergency"),
        ("ginkagat sang ido ko kag nagdugo", "dog bite with bleeding", "Bite", "Critical", "emergency"),
        ("nakuryente ko sang kuryente", "electrical injury", "Trauma", "Critical", "emergency"),
        ("nagdugo ulo ko pagkatapos natumba", "head bleeding after fall", "Trauma", "Critical", "emergency"),
        ("gapula mata ko kag gakatol", "red itchy eyes", "Eye", "Medium", "urgent"),
    ]
    add_many(seeds)
    for hil, eng, cat, sev, tri in seeds:
        for opener in NATURAL_OPENERS:
            for closer in NATURAL_CLOSERS[:5]:
                phrase = f"{opener} {hil} {closer}".strip()
                phrase = re.sub(r"\s+", " ", phrase)
                add(
                    Record(
                        hiligaynon_term=phrase,
                        english_term=eng,
                        medical_category=cat,
                        severity=sev,
                        triage_level=tri,
                        is_condition=cat in {"Infection", "Trauma", "Fracture", "Wound", "Bite"},
                    )
                )


def generate_abbreviations() -> None:
    """Chat-style abbreviations common in telemedicine messaging."""
    abbrevs = [
        ("ubo+ hilanat", "fever with cough", "Respiratory", "High", "urgent"),
        ("ubo/hilanat", "fever with cough", "Respiratory", "High", "urgent"),
        ("SOB ko", "shortness of breath", "Respiratory", "Critical", "emergency"),
        ("dugo ulo", "head bleeding", "Bleeding", "Critical", "emergency"),
        ("dugo ilong", "nosebleed", "Bleeding", "Medium", "urgent"),
        ("dugo pilas", "bleeding wound", "Bleeding", "High", "emergency"),
        ("hubag mata", "eye swelling", "Swelling", "Medium", "urgent"),
        ("pula mata", "red eye", "Eye", "Medium", "urgent"),
        ("sakit dughan", "chest pain", "Pain", "High", "emergency"),
        ("sakit ulo", "head pain", "Pain", "Medium", "urgent"),
        ("sakit tiyan", "abdominal pain", "Pain", "Medium", "urgent"),
        ("kagat ido", "dog bite", "Bite", "Critical", "emergency"),
        ("kagat lamok", "mosquito bite", "Bite", "Low", "routine"),
    ]
    add_many(abbrevs)


def expand_pain_regional() -> None:
    """Extra pain phrases using regional body terms and slang possessives."""
    core = [
        ("ulo", "head pain", "Neurological"),
        ("mata", "eye pain", "Eye"),
        ("dughan", "chest pain", "Cardiovascular"),
        ("tiyan", "abdominal pain", "Gastrointestinal"),
        ("likod", "back pain", "Musculoskeletal"),
        ("tuhod", "knee pain", "Musculoskeletal"),
    ]
    for hil_body, eng, body_sys in core:
        regional = REGIONAL_BODY.get(hil_body, [hil_body])
        for rb in regional:
            for pos in SLANG_POSSESSIVE:
                for prefix in ["sakit", "gasakit", "masakit", "hapdi"]:
                    add(
                        Record(
                            hiligaynon_term=f"{prefix} {rb} {pos}".replace("  ", " "),
                            english_term=eng,
                            medical_category="Pain",
                            severity="High" if hil_body in {"dughan", "ulo"} else "Medium",
                            triage_level="emergency" if hil_body == "dughan" else "routine",
                            body_system=body_sys,
                        )
                    )


def expand_typo_variants() -> None:
    """Duplicate high-value emergency phrases with common misspellings."""
    priority = [r for r in RECORDS.values() if r.triage_level == "emergency" or r.severity == "Critical"]
    typo_rules = [
        (r"\bko\b", "ku"),
        (r"\bakon\b", "acun"),
        (r"\bmata\b", "mataa"),
        (r"\bgasakit\b", "gasaket"),
        (r"\bgahika\b", "gihika"),
        (r"\bnana\b", "nanna"),
        (r"\bnagdugo\b", "nag dugo"),
        (r"\bmagginhawa\b", "mag ginhawa"),
        (r"\bdughan\b", "dughan"),
        (r"\btiyan\b", "tina-i"),
        (r"\bdalunggan\b", "dalungan"),
        (r"\bginkagat\b", "gin kagat"),
        (r"\bnabali\b", "nabali na"),
        (r"\bnautod\b", "nautod na"),
        (r"\bgahabok\b", "gahubag"),
        (r"\bbudlay\b", "budlay gid"),
    ]
    for rec in priority[:900]:
        term = rec.hiligaynon_term
        for pattern, repl in typo_rules:
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
                            is_condition=rec.is_condition,
                        )
                    )


def generate_burns() -> None:
    sites = [
        ("kamot", "hand burn"),
        ("tiil", "foot burn"),
        ("mata", "eye burn"),
        ("lawas", "body burn"),
        ("tiyan", "abdominal burn"),
        ("buuk", "arm burn"),
        ("batiis", "leg burn"),
        ("balat", "skin burn"),
    ]
    for site, eng in sites:
        for t in [
            f"nasunog {site} ko",
            f"nasunog akon {site}",
            f"nasunog gid {site} ko",
            f"nasunog ko ang {site}",
            f"mapula nasunog {site} ko",
            f"may pilas nasunog sa {site} ko",
        ]:
            add(
                Record(
                    hiligaynon_term=t,
                    english_term=eng,
                    medical_category="Burn",
                    severity="High" if site in {"mata", "lawas"} else "Medium",
                    triage_level="emergency" if site == "mata" else "urgent",
                    is_condition=True,
                )
            )
    add_many(
        [
            ("nasunog ko sang mainit nga tubig", "scalding burn", "Burn", "High", "urgent"),
            ("nasunog ko sang mantika", "thermal burn", "Burn", "High", "urgent"),
            ("nasunog ko sang kuryente", "electrical burn", "Burn", "Critical", "emergency"),
        ]
    )


def generate_urinary() -> None:
    items = [
        ("masakit pag-ihi ko", "painful urination", "Urinary", "Medium", "urgent"),
        ("masakit mag-ihi ko", "painful urination", "Urinary", "Medium", "urgent"),
        ("duguon ihi ko", "bloody urine", "Urinary", "High", "urgent"),
        ("may dugo sa ihi ko", "bloody urine", "Urinary", "High", "urgent"),
        ("damsi ihi ko", "frequent urination", "Urinary", "Low", "routine"),
        ("damsi gid mag-ihi ko", "frequent urination", "Urinary", "Medium", "routine"),
        ("wala ko maka-ihi", "urinary retention", "Urinary", "High", "emergency"),
        ("budlay mag-ihi ko", "difficulty urinating", "Urinary", "High", "urgent"),
        ("mapait ihi ko", "foul urine", "Urinary", "Medium", "routine"),
        ("ginahubag bato ko", "kidney swelling", "Urinary", "High", "urgent"),
    ]
    add_many(items)


def generate_ent() -> None:
    """Ear, nose, throat phrases."""
    items = [
        ("may barog ilong ko", "nasal congestion", "ENT", "Low", "routine"),
        ("barog ilong ko", "nasal congestion", "ENT", "Low", "routine"),
        ("ginatulo ilong ko", "runny nose", "ENT", "Low", "routine"),
        ("ginatulo gid ilong ko", "runny nose", "ENT", "Medium", "routine"),
        ("gahubag ilong ko", "nasal swelling", "ENT", "Medium", "routine"),
        ("wala ko maka-amim", "loss of smell", "ENT", "Medium", "routine"),
        ("wala ko maka-dungog", "hearing loss", "ENT", "Medium", "urgent"),
        ("may tunog sa dalunggan ko", "ear ringing", "ENT", "Low", "routine"),
        ("nagatulo dugo sa dalunggan ko", "ear bleeding", "ENT", "High", "urgent"),
        ("may nana sa dalunggan ko", "ear infection", "ENT", "Medium", "urgent"),
        ("masakit paglamon ko", "painful swallowing", "ENT", "Medium", "urgent"),
        ("masakit tungod ko", "sore throat", "ENT", "Medium", "routine"),
        ("mapula tungod ko", "sore throat", "ENT", "Medium", "routine"),
        ("gahubag tungod ko", "throat swelling", "ENT", "High", "emergency"),
        ("gahubag liog ko", "neck swelling", "Swelling", "High", "urgent"),
        ("may bukol sa liog ko", "neck lump", "ENT", "Medium", "urgent"),
    ]
    add_many(items)


def generate_dental() -> None:
    items = [
        ("masakit ngipon ko", "tooth pain", "Dental", "Medium", "routine"),
        ("gasakit ngipon ko", "tooth pain", "Dental", "Medium", "routine"),
        ("nagdugo ngipon ko", "bleeding gums", "Dental", "Medium", "routine"),
        ("nagluha ngipon ko", "loose tooth", "Dental", "Medium", "routine"),
        ("nabali ngipon ko", "broken tooth", "Dental", "Medium", "urgent"),
        ("nautod ngipon ko", "avulsed tooth", "Dental", "High", "urgent"),
        ("may bukol sa baba ko", "oral swelling", "Dental", "Medium", "urgent"),
        ("mapait baba ko", "bad breath", "Dental", "Low", "routine"),
    ]
    add_many(items)


def generate_cardiac() -> None:
    items = [
        ("masakit dughan ko", "chest pain", "Cardiovascular", "High", "emergency"),
        ("gasakit dughan ko", "chest pain", "Cardiovascular", "High", "emergency"),
        ("masakit dughan ko kag galuya", "chest pain with weakness", "Cardiovascular", "Critical", "emergency"),
        ("dulom dughan ko", "chest tightness", "Cardiovascular", "High", "emergency"),
        ("madasig tagipusuon ko", "palpitations", "Cardiovascular", "Medium", "urgent"),
        ("madasig kuno tagipusuon ko", "palpitations", "Cardiovascular", "Medium", "urgent"),
        ("mapaso dughan ko", "chest burning", "Cardiovascular", "High", "emergency"),
        ("ginahampak dughan ko", "chest pressure", "Cardiovascular", "Critical", "emergency"),
        ("alta presyon ko", "hypertension", "Cardiovascular", "Medium", "routine"),
        ("may alta presyon ako", "hypertension", "Cardiovascular", "Medium", "routine"),
        ("mababa presyon ko", "hypotension", "Cardiovascular", "Medium", "urgent"),
    ]
    add_many(items)


def generate_stroke_neuro_emergency() -> None:
    items = [
        ("daw indi ko makahambal", "speech difficulty", "Neurological", "Critical", "emergency"),
        ("wala ko maka-hambal maayo", "speech difficulty", "Neurological", "Critical", "emergency"),
        ("daw indi ko makabaton sang kamot ko", "arm weakness", "Neurological", "Critical", "emergency"),
        ("daw indi ko makabaton sang tiil ko", "leg weakness", "Neurological", "Critical", "emergency"),
        ("daw indi ko makita maayo", "vision change", "Neurological", "Critical", "emergency"),
        ("daw indi ko makilala ang tawo", "confusion", "Neurological", "Critical", "emergency"),
        ("daw indi ko makabaton sang lawas ko", "facial droop", "Neurological", "Critical", "emergency"),
        ("naguyam ko kag wala ko maka-agi", "seizure", "Neurological", "Critical", "emergency"),
        ("nagtumba lawas ko", "syncope", "Neurological", "High", "emergency"),
        ("wala ko maka-agi sang naguyam", "post-seizure confusion", "Neurological", "High", "emergency"),
    ]
    add_many(items)


def generate_mental_health() -> None:
    items = [
        ("wala ko maka-tulog", "insomnia", "Mental Health", "Low", "routine"),
        ("dugay na wala ko maka-tulog", "insomnia", "Mental Health", "Medium", "routine"),
        ("ginabalaka ko gid", "anxiety", "Mental Health", "Medium", "routine"),
        ("ginakulbaan ko", "anxiety", "Mental Health", "Medium", "routine"),
        ("wala ko gusto mabuhi", "depression", "Mental Health", "Critical", "emergency"),
        ("gusto ko magpakamatay", "suicidal ideation", "Mental Health", "Critical", "emergency"),
        ("wala ko gusto mag-obra", "depression", "Mental Health", "Medium", "urgent"),
        ("ginahampak ulo ko", "headache", "Neurological", "Medium", "routine"),
        ("masakit ulo ko gid", "severe headache", "Neurological", "High", "urgent"),
    ]
    add_many(items)


def generate_allergy() -> None:
    items = [
        ("may alerdyi ako", "allergy", "Allergy", "Medium", "routine"),
        ("may alerdyi ko sa tambal", "drug allergy", "Allergy", "High", "urgent"),
        ("ginahubag lawas ko pagkaon", "food allergy reaction", "Allergy", "High", "emergency"),
        ("gahubag lawas ko", "allergic reaction", "Allergy", "High", "emergency"),
        ("gahubag lawas ko kag gakatol", "allergic reaction with itching", "Allergy", "Critical", "emergency"),
        ("gahubag lawas ko kag budlay magginhawa", "anaphylaxis", "Allergy", "Critical", "emergency"),
        ("gahubag mata ko kag ilong ko", "allergic rhinitis", "Allergy", "Low", "routine"),
    ]
    add_many(items)


def generate_numbness_burning() -> None:
    sites = [
        ("kamot", "hand numbness"),
        ("tiil", "foot numbness"),
        ("buuk", "arm numbness"),
        ("batiis", "leg numbness"),
        ("tudlo", "finger numbness"),
        ("lawas", "body numbness"),
    ]
    for site, eng_n in sites:
        for prefix in ["maniwang", "wala ko mabatyag", "daw wala ko mabatyag", "ginamanwang"]:
            add(
                Record(
                    hiligaynon_term=f"{prefix} {site} ko",
                    english_term=eng_n,
                    medical_category="Neurological",
                    severity="Medium",
                    triage_level="urgent",
                )
            )
    for site, eng_b in [
        ("kamot", "burning hand sensation"),
        ("tiil", "burning foot sensation"),
        ("balat", "burning skin sensation"),
        ("tiyan", "burning abdominal sensation"),
    ]:
        for prefix in ["mapaso", "ginapaso", "mapaso gid"]:
            add(
                Record(
                    hiligaynon_term=f"{prefix} {site} ko",
                    english_term=eng_b,
                    medical_category="General",
                    severity="Medium",
                    triage_level="routine",
                )
            )


def generate_vomiting_dehydration() -> None:
    items = [
        ("ginasuka ko", "vomiting", "Gastrointestinal", "Medium", "urgent"),
        ("ginasuka gid ko", "severe vomiting", "Gastrointestinal", "High", "urgent"),
        ("ginasuka kag ginadudul-om ko", "vomiting with diarrhea", "Gastrointestinal", "High", "urgent"),
        ("ginasuka dugo ko", "bloody vomit", "Gastrointestinal", "Critical", "emergency"),
        ("may dugo sa suka ko", "bloody vomit", "Gastrointestinal", "Critical", "emergency"),
        ("wala ko maka-inom tubig", "dehydration", "Gastrointestinal", "High", "urgent"),
        ("maluya ko tungod sa init", "heat exhaustion", "General", "High", "urgent"),
        ("nahubasan ko sang init", "heat exhaustion", "General", "High", "urgent"),
        ("ginahubasan init ko", "heat stroke", "General", "Critical", "emergency"),
    ]
    add_many(items)


def generate_poisoning_choking() -> None:
    items = [
        ("naka-inom ko sang lason", "poisoning", "Emergency", "Critical", "emergency"),
        ("naka-inom sang tambal sobra", "medication overdose", "Emergency", "Critical", "emergency"),
        ("natabunan ko", "choking", "Emergency", "Critical", "emergency"),
        ("wala ko maka-ginhawa tungod sa pagkaon", "choking", "Emergency", "Critical", "emergency"),
        ("natabunan sang pagkaon", "choking on food", "Emergency", "Critical", "emergency"),
        ("nalumos ko", "drowning", "Emergency", "Critical", "emergency"),
        ("daw indi ko maka-ginhawa tungod sa alerdyi", "allergic airway obstruction", "Emergency", "Critical", "emergency"),
    ]
    add_many(items)


def generate_diabetes_chronic() -> None:
    items = [
        ("may diabetes ako", "diabetes", "Endocrine", "Medium", "routine"),
        ("may diabetes ko", "diabetes", "Endocrine", "Medium", "routine"),
        ("mataas ang asukal ko", "hyperglycemia", "Endocrine", "High", "urgent"),
        ("mababa ang asukar ko", "hypoglycemia", "Endocrine", "High", "emergency"),
        ("nahilo ko tungod sa asukar", "hypoglycemia", "Endocrine", "High", "emergency"),
        ("ginauhaw gid ko", "excessive thirst", "Endocrine", "Medium", "routine"),
        ("damsi mag-ihi ko", "frequent urination", "Endocrine", "Medium", "routine"),
        ("nagapanipis lawas ko", "weight loss", "General", "Medium", "routine"),
        ("nagataas timbang ko", "weight gain", "General", "Low", "routine"),
    ]
    add_many(items)


def generate_combined_symptoms() -> None:
    """Common multi-symptom telemedicine complaints (X kag Y)."""
    pairs: list[tuple[str, str, str, str, str, str]] = [
        ("ginahilanat ko", "ubo ko", "fever with cough", "Respiratory", "High", "urgent"),
        ("ginahilanat ko", "ginabaldom ko", "fever with abdominal pain", "General", "High", "urgent"),
        ("sakit ulo ko", "ginahilanat ko", "headache with fever", "General", "Medium", "urgent"),
        ("sakit dughan ko", "gahika ko", "chest pain with cough", "Cardiovascular", "Critical", "emergency"),
        ("sakit dughan ko", "dula ginhawa ko", "chest pain with shortness of breath", "Cardiovascular", "Critical", "emergency"),
        ("ginasuka ko", "ginadudul-om ko", "vomiting with diarrhea", "Gastrointestinal", "High", "urgent"),
        ("gahubag mata ko", "gahika ko", "eye swelling with cough", "Eye", "Medium", "urgent"),
        ("nagdugo pilas ko", "ginahilanat ko", "bleeding wound with fever", "Infection", "High", "emergency"),
        ("galuya ko", "ginahilanat ko", "weakness with fever", "General", "High", "urgent"),
        ("gahubag lawas ko", "gakatol lawas ko", "body swelling with itching", "Allergy", "High", "emergency"),
        ("sakit tiyan ko", "ginasuka ko", "abdominal pain with nausea", "Gastrointestinal", "High", "urgent"),
        ("sakit likod ko", "galuya ko", "back pain with weakness", "Musculoskeletal", "Medium", "urgent"),
        ("nahilo ko", "ginahilanat ko", "dizziness with fever", "General", "Medium", "urgent"),
        ("gahubag tungod ko", "ginahilanat ko", "throat swelling with fever", "ENT", "High", "emergency"),
        ("nagdugo ulo ko", "nahilo ko", "head bleeding with dizziness", "Trauma", "Critical", "emergency"),
        ("may nana mata ko", "gapula mata ko", "eye infection with red eye", "Eye", "High", "urgent"),
        ("gasakit ulo ko", "ginahilanat ko", "head pain with fever", "Pain", "High", "urgent"),
        ("gahubag kamot ko", "nagdugo kamot ko", "hand swelling with bleeding", "Trauma", "High", "emergency"),
    ]
    for left, right, eng, cat, sev, tri in pairs:
        add(
            Record(
                hiligaynon_term=f"{left} kag {right}",
                english_term=eng,
                medical_category=cat,
                severity=sev,
                triage_level=tri,
                is_condition=tri == "emergency",
            )
        )
        add(
            Record(
                hiligaynon_term=f"{left} upod sang {right}",
                english_term=eng,
                medical_category=cat,
                severity=sev,
                triage_level=tri,
                is_condition=tri == "emergency",
            )
        )
        for dur in ["subong", "dugay na", "3 ka adlaw na"]:
            add(
                Record(
                    hiligaynon_term=f"{dur} {left} kag {right}",
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=tri == "emergency",
                )
            )


def generate_duration_phrases() -> None:
    """Symptoms with duration — common in telemedicine intake."""
    seeds = [
        ("ginahilanat ko", "fever", "General", "Medium", "routine"),
        ("gahika ko", "cough", "Respiratory", "Medium", "routine"),
        ("gasakit ulo ko", "head pain", "Pain", "Medium", "routine"),
        ("ginadudul-om ko", "diarrhea", "Gastrointestinal", "Medium", "routine"),
        ("may pilas ko sa kamot", "hand wound", "Wound", "Medium", "urgent"),
        ("may nana akon mata", "eye infection", "Infection", "High", "urgent"),
        ("galuya lawas ko", "body weakness", "General", "Medium", "routine"),
        ("gahubag tiyan ko", "abdominal swelling", "Gastrointestinal", "Medium", "urgent"),
    ]
    for hil, eng, cat, sev, tri in seeds:
        for dur in DURATION_MARKERS:
            add(
                Record(
                    hiligaynon_term=f"{dur} {hil}",
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=cat in {"Infection", "Wound"},
                )
            )
            add(
                Record(
                    hiligaynon_term=f"{hil} {dur}",
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=cat in {"Infection", "Wound"},
                )
            )


def generate_question_phrases() -> None:
    """Patient questions phrased as symptoms for NLP matching."""
    seeds = [
        ("may nana akon mata", "eye infection", "Infection", "High", "urgent"),
        ("nabali kamot ko", "hand fracture", "Fracture", "High", "emergency"),
        ("budlay magginhawa ko", "difficulty breathing", "Respiratory", "Critical", "emergency"),
        ("nagdugo ulo ko", "head bleeding", "Bleeding", "Critical", "emergency"),
        ("ginkagat sang ido ko", "dog bite", "Bite", "Critical", "emergency"),
        ("gasakit dughan ko", "chest pain", "Cardiovascular", "High", "emergency"),
    ]
    for hil, eng, cat, sev, tri in seeds:
        for q in QUESTION_STARTERS:
            add(
                Record(
                    hiligaynon_term=f"{q} {hil}",
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=True,
                )
            )
            add(
                Record(
                    hiligaynon_term=f"{q} nga {hil}",
                    english_term=eng,
                    medical_category=cat,
                    severity=sev,
                    triage_level=tri,
                    is_condition=True,
                )
            )


def generate_telemedicine_chat() -> None:
    """Short chat-style messages (Messenger/Viber teleconsult)."""
    chats = [
        ("doc may nana mata ko", "eye infection", "Infection", "High", "urgent"),
        ("doc gahika ko 3 days na", "cough", "Respiratory", "Medium", "routine"),
        ("doc sakit ulo ko", "head pain", "Pain", "Medium", "routine"),
        ("doc hilanat ko", "fever", "General", "Medium", "routine"),
        ("doc dula ginhawa ko pls", "shortness of breath", "Respiratory", "Critical", "emergency"),
        ("doc nabali kamot ko", "hand fracture", "Fracture", "High", "emergency"),
        ("doc nagdugo pilas ko", "bleeding wound", "Wound", "High", "emergency"),
        ("doc ginkagat ido ko", "dog bite", "Bite", "Critical", "emergency"),
        ("doc alta presyon ko", "hypertension", "Cardiovascular", "Medium", "routine"),
        ("doc may diabetes ako", "diabetes", "Endocrine", "Medium", "routine"),
        ("doc gahubag tungod ko", "throat swelling", "ENT", "High", "emergency"),
        ("doc masakit dughan ko", "chest pain", "Cardiovascular", "High", "emergency"),
        ("doc ginadudulom ko", "diarrhea", "Gastrointestinal", "Medium", "routine"),
        ("doc ginasuka ko", "vomiting", "Gastrointestinal", "Medium", "urgent"),
        ("doc nahilo ko", "dizziness", "Neurological", "Medium", "urgent"),
        ("doc may pantal lawas ko", "skin rash", "Dermatological", "Medium", "routine"),
        ("doc gahubag kamot ko", "hand swelling", "Swelling", "Medium", "urgent"),
        ("doc wala ko maka-ihi", "urinary retention", "Urinary", "High", "emergency"),
        ("doc nakuryente ko", "electrical injury", "Trauma", "Critical", "emergency"),
        ("help dula ginhawa ko", "shortness of breath", "Respiratory", "Critical", "emergency"),
        ("help nagdugo ulo ko", "head bleeding", "Bleeding", "Critical", "emergency"),
        ("help nautod tudlo ko", "amputated finger", "Trauma", "Critical", "emergency"),
    ]
    add_many(chats)
    for hil, eng, cat, sev, tri in chats:
        add(
            Record(
                hiligaynon_term=hil.replace("doc ", "").replace("help ", ""),
                english_term=eng,
                medical_category=cat,
                severity=sev,
                triage_level=tri,
                is_condition=tri == "emergency",
            )
        )


def generate_pregnancy_womens() -> None:
    items = [
        ("burod ako", "pregnancy", "Obstetric", "Low", "routine"),
        ("may burod ako", "pregnancy", "Obstetric", "Low", "routine"),
        ("masakit tiyan ko nga burod", "pregnancy abdominal pain", "Obstetric", "High", "urgent"),
        ("nagdugo ako nga burod", "pregnancy bleeding", "Obstetric", "Critical", "emergency"),
        ("nagdugo ko nga burod", "pregnancy bleeding", "Obstetric", "Critical", "emergency"),
        ("ginahubag tiil ko nga burod", "pregnancy leg swelling", "Obstetric", "Medium", "urgent"),
        ("wala ko maka-agom", "missed period", "Obstetric", "Low", "routine"),
    ]
    add_many(items)


def generate_skin_conditions() -> None:
    items = [
        ("may baras sa balat ko", "skin rash", "Dermatological", "Low", "routine"),
        ("may butlig sa balat ko", "skin lump", "Dermatological", "Medium", "routine"),
        ("may bukol sa balat ko", "skin lump", "Dermatological", "Medium", "routine"),
        ("maputi pantal ko", "skin rash", "Dermatological", "Low", "routine"),
        ("mapula pantal ko", "skin rash", "Dermatological", "Medium", "routine"),
        ("may nana sa pilas ko", "infected wound", "Infection", "High", "urgent"),
        ("mapait ang pilas ko", "infected wound", "Infection", "Medium", "urgent"),
        ("ginapasmo balat ko", "skin rash", "Dermatological", "Low", "routine"),
        ("ginapangas balat ko", "dry skin", "Dermatological", "Low", "routine"),
        ("ginabuka balat ko", "skin cracking", "Dermatological", "Low", "routine"),
    ]
    add_many(items)


def generate_musculoskeletal_extra() -> None:
    sites = [
        ("likod", "back pain", "Musculoskeletal"),
        ("liog", "neck pain", "Musculoskeletal"),
        ("abaga", "shoulder pain", "Musculoskeletal"),
        ("siko", "elbow pain", "Musculoskeletal"),
        ("tungtong", "ankle pain", "Musculoskeletal"),
        ("tikod", "heel pain", "Musculoskeletal"),
        ("baywang", "waist pain", "Musculoskeletal"),
    ]
    for site, eng, body_sys in sites:
        for prefix in PAIN_PREFIXES + ["masakit paglihok", "masakit kon maglihok"]:
            for pos in SLANG_POSSESSIVE:
                add(
                    Record(
                        hiligaynon_term=f"{prefix} {site} {pos}".replace("  ", " "),
                        english_term=eng,
                        medical_category="Pain",
                        severity="Medium",
                        triage_level="routine",
                        body_system=body_sys,
                    )
                )
    sprain_sites = [
        ("tungtong", "ankle sprain"),
        ("tuhod", "knee sprain"),
        ("siko", "elbow sprain"),
        ("kamot", "wrist sprain"),
    ]
    for site, eng in sprain_sites:
        for t in [f"nabalian {site} ko", f"nabalian akon {site}", f"nabalian gid {site} ko"]:
            add(
                Record(
                    hiligaynon_term=t,
                    english_term=eng,
                    medical_category="Trauma",
                    severity="Medium",
                    triage_level="routine",
                    is_condition=True,
                )
            )


def write_csv(path: Path, rows: list[Record], fields: list[str] | None = None) -> None:
    fields = fields or CSV_FIELDS
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        for rec in rows:
            w.writerow(
                {
                    "hiligaynon_term": rec.hiligaynon_term,
                    "english_term": rec.english_term,
                    "medical_category": rec.medical_category,
                    "severity": rec.severity,
                    "triage_level": rec.triage_level,
                    "status": rec.status,
                }
            )


def merge_medical_dictionary(records: list[Record]) -> int:
    dict_path = NLP / "medical_dictionary.csv"
    existing: dict[str, tuple[str, str, str]] = {}
    if dict_path.is_file():
        with dict_path.open(encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            for row in reader:
                local = (row.get("local_term") or "").strip().lower()
                if local:
                    existing[local] = (
                        row.get("local_term") or "",
                        row.get("english_term") or "",
                        row.get("category") or "condition",
                    )
    added = 0
    next_id = len(existing) + 1
    for rec in records:
        local = rec.hiligaynon_term.strip()
        key = local.lower()
        if key in existing or len(local) < 2:
            continue
        cat = "condition" if rec.is_condition or rec.medical_category != "Body Part" else "condition"
        existing[key] = (local, rec.english_term, cat)
        added += 1
    with dict_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["dictionary_id", "local_term", "english_term", "category"])
        for i, (local, (lt, en, cat)) in enumerate(sorted(existing.items(), key=lambda x: x[1][0].lower()), start=1):
            w.writerow([i, lt, en, cat])
    return added


def append_nlp_dataset(records: list[Record]) -> int:
    nlp_path = NLP / "hiligaynon_medical_nlp_dataset.csv"
    existing_terms: set[str] = set()
    max_id = 0
    rows_out: list[dict[str, str]] = []
    if nlp_path.is_file():
        with nlp_path.open(encoding="utf-8", newline="") as f:
            reader = csv.DictReader(f)
            for row in reader:
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
                "body_system": rec.body_system,
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


def main() -> None:
    generate_body_parts()
    generate_pain()
    generate_swelling()
    generate_pus_infection()
    generate_eye_conditions()
    generate_respiratory()
    generate_wounds()
    generate_amputation()
    generate_fractures()
    generate_trauma()
    generate_weakness()
    generate_hair()
    generate_accidents()
    generate_electrical()
    generate_bites()
    generate_head_injury()
    generate_fever_general()
    generate_bleeding()
    generate_itching_rash()
    generate_gi_symptoms()
    generate_neuro_symptoms()
    generate_natural_patient_statements()
    generate_abbreviations()
    expand_pain_regional()
    expand_typo_variants()
    generate_burns()
    generate_urinary()
    generate_ent()
    generate_dental()
    generate_cardiac()
    generate_stroke_neuro_emergency()
    generate_mental_health()
    generate_allergy()
    generate_numbness_burning()
    generate_vomiting_dehydration()
    generate_poisoning_choking()
    generate_diabetes_chronic()
    generate_combined_symptoms()
    generate_duration_phrases()
    generate_question_phrases()
    generate_telemedicine_chat()
    generate_pregnancy_womens()
    generate_skin_conditions()
    generate_musculoskeletal_extra()

    all_records = sorted(RECORDS.values(), key=lambda r: r.hiligaynon_term.lower())
    print(f"Generated {len(all_records)} unique Hiligaynon records")

    write_csv(NLP / "hiligaynon_wv_expansion.csv", all_records)
    write_csv(
        NLP / "body_parts.csv",
        [r for r in all_records if r.medical_category == "Body Part"],
    )
    write_csv(
        NLP / "hiligaynon_symptoms.csv",
        [r for r in all_records if r.medical_category not in {"Body Part", "Fracture"} or not r.is_condition],
    )
    write_csv(
        NLP / "hiligaynon_conditions.csv",
        [r for r in all_records if r.is_condition or r.medical_category in {"Fracture", "Infection", "Trauma", "Wound", "Bite"}],
    )
    write_csv(NLP / "symptom_phrases.csv", all_records)

    dict_added = merge_medical_dictionary(all_records)
    nlp_added = append_nlp_dataset(all_records)

    print(f"Wrote body_parts.csv, hiligaynon_symptoms.csv, hiligaynon_conditions.csv, symptom_phrases.csv")
    print(f"Merged {dict_added} new terms into medical_dictionary.csv")
    print(f"Appended {nlp_added} rows to hiligaynon_medical_nlp_dataset.csv")


if __name__ == "__main__":
    main()
