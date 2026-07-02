#!/usr/bin/env python3
"""
Generate data/nlp/hiligaynon_medical_nlp_dataset.csv — 10,000+ Hiligaynon medical NLP rows.

Columns:
  id, hiligaynon_term, alternative_spellings, english_translation, medical_term,
  medical_category, body_system, severity, symptom_keywords, confidence_keywords
"""

from __future__ import annotations

import csv
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "hiligaynon_medical_nlp_dataset.csv"
TARGET_ROWS = 10_000
MAX_VARIANTS_PER_CONCEPT = 180
REQUIRED_PHRASES: dict[str, tuple[str, str, str, str, str, str, str]] = {
    # phrase -> (english, medical_term, category, severity, symptom_kw, conf_kw, body_system)
    "kakatul lawas": ("body itchiness", "pruritus", "Dermatology", "Low", "itch;body;skin", "itch;body;skin;pruritus", "integumentary"),
    "kakatol lawas": ("body itchiness", "pruritus", "Dermatology", "Low", "itch;body;skin", "itch;body;skin;pruritus", "integumentary"),
    "ga katol akon lawas": ("body itchiness", "pruritus", "Dermatology", "Low", "itch;body;skin", "itch;body;skin;pruritus", "integumentary"),
    "kakatul gid lawas ko": ("body itchiness", "pruritus", "Dermatology", "Low", "itch;body;skin", "itch;body;skin;pruritus", "integumentary"),
    "may katol ko sa lawas": ("body itchiness", "pruritus", "Dermatology", "Low", "itch;body;skin", "itch;body;skin;pruritus", "integumentary"),
}

BODY_PARTS = [
    "ulo", "mata", "ilong", "baba", "ngipon", "lagos", "dila", "liog",
    "dughan", "tagipusuon", "tiyan", "pus-on", "likod", "kamot", "tiil",
    "tuhod", "siko", "dalunggan", "lawas", "panit", "buhok", "tutunlan",
    "baton", "kalawasan", "dibdib", "sikmura",
]

CATEGORY_BODY_SYSTEM = {
    "Dermatology": "integumentary",
    "Respiratory": "respiratory",
    "Cardiovascular": "cardiovascular",
    "Neurological": "nervous",
    "Gastroenterology": "digestive",
    "Digestive": "digestive",
    "Urology": "urinary",
    "Urinary": "urinary",
    "Musculoskeletal": "musculoskeletal",
    "Ophthalmology": "sensory",
    "Otology": "sensory",
    "Mental Health": "mental",
    "Emergency": "multi-system",
    "General Medicine": "general",
    "Infectious Disease": "immune",
    "Women's Health": "reproductive",
    "Pediatric": "general",
}


@dataclass
class Concept:
    roots: list[str]
    english: str
    medical_term: str
    category: str
    severity: str
    symptom_keywords: str
    confidence_keywords: str
    extra_variants: list[str] = field(default_factory=list)


def norm(term: str) -> str:
    return re.sub(r"\s+", " ", term.lower().strip())


def spelling_variants(term: str) -> list[str]:
    out = [term]
    t = term.strip()
    swaps = [
        (t.replace("-", " "),),
        (t.replace("-", ""),),
        (t.replace(" ", "-"),),
    ]
    if "sip-on" in t:
        swaps += [(t.replace("sip-on", "sipon"),), (t.replace("sip-on", "sip on"),)]
    if "sipon" in t:
        swaps += [(t.replace("sipon", "sip-on"),), (t.replace("sipon", "sip on"),)]
    if "tiyan" in t:
        swaps.append((t.replace("tiyan", "tyan"),))
    if "kakatol" in t:
        swaps.append((t.replace("kakatol", "kakatul"),))
    if "kakatul" in t:
        swaps.append((t.replace("kakatul", "kakatol"),))
    for s in swaps:
        out.append(s[0])
    return [x.strip() for x in out if x.strip() and len(x.strip()) >= 2]


def prefix_variants(term: str) -> list[str]:
    base = term.strip()
    out = [base]
    if " " in base:
        out.extend(["gin" + base, "nag" + base, "may " + base])
        if base.startswith("ga "):
            out.append("gina " + base[3:])
            out.append("naga" + base[2:])
    else:
        out.extend(["gin" + base, "nag" + base, "ga " + base, "gina " + base, "may " + base])
    return [x.strip() for x in out if x.strip()]


def suffix_variants(term: str) -> list[str]:
    base = term.strip()
    suffixes = [" gid", " gid ko", " ko", " ako", " man", " subong", " sing malala", " sing grabe"]
    return [base] + [base + s for s in suffixes]


def sentence_wrap(term: str) -> list[str]:
    t = term.strip()
    return [
        t,
        f"may {t}",
        f"may {t} ko",
        f"may {t} ako",
        f"ga {t}",
        f"gina {t}",
        f"naga {t}",
        f"ako {t}",
        f"gid {t}",
        f"gid ko {t}",
        f"subong {t}",
        f"ang {t} ko",
        f"ginafeel ko ang {t}",
    ]


def itch_phrases(part: str = "lawas") -> list[str]:
    return [
        f"kakatol {part}", f"kakatul {part}", f"katol sa {part}", f"ga katol {part}",
        f"kumakati {part}", f"ga katol akon {part}", f"kakatol bilog {part}",
        f"kakatul gid {part} ko", f"may katol ko sa {part}", f"ginakati {part}",
        f"nagakati {part}", f"katol {part}", f"makatol {part}", f"gina katol {part}",
        f"nagakakatol {part}", f"kakatol sa {part}", f"kakatul sa {part}",
    ]


def pain_phrases(part: str) -> list[str]:
    return [
        f"sakit {part}", f"masakit {part}", f"sakit sang {part}", f"hapdi {part}",
        f"ginasakit {part}", f"nagasakit {part}", f"may sakit {part}",
        f"may sakit ko sa {part}", f"{part} masakit", f"ginamasakit {part}",
        f"panakit {part}",
    ]


def weakness_phrases(part: str) -> list[str]:
    return [
        f"luya {part}", f"ginluya {part}", f"nagluya {part}", f"may luya {part}",
        f"mahina {part}", f"ginmahina {part}", f"luya gid {part} ko", f"ginaluya {part}",
    ]


def concepts() -> list[Concept]:
    c: list[Concept] = []

    def add(roots, english, medical, category, severity, sym_kw, conf_kw, extra=None):
        c.append(
            Concept(
                roots=list(roots),
                english=english,
                medical_term=medical,
                category=category,
                severity=severity,
                symptom_keywords=sym_kw,
                confidence_keywords=conf_kw,
                extra_variants=list(extra or []),
            )
        )

    # DERMATOLOGY
    add(["kakatul lawas", "kakatol lawas", "kumakati lawas", "ga katol lawas", "katol sa lawas",
         "kakatol", "kakatul", "katol", "makatol", "ga katol", "gina katol", "nagakakatol", "kumakati", "kinatol", "kinatulan"],
        "itchiness", "pruritus", "Dermatology", "Low", "itch;pruritus;skin", "itch;skin;allergy;rash;scratch",
        itch_phrases("lawas") + itch_phrases("panit") + itch_phrases("mata"))
    add(["bugas", "butlig", "galis", "pamula", "pantal", "pantal-pantal", "nagapamula", "naga pula panit", "pamula panit", "nagapanit"],
        "rash", "rash", "Dermatology", "Low", "rash;eruption;skin", "skin;spots;allergy;eruption")
    add(["hubag", "hubag-hubag", "kalisngaw", "alipunga", "nagahubag", "ginahubag"],
        "hives", "urticaria", "Dermatology", "Medium", "hives;urticaria;wheals", "swelling;allergy;skin;wheals")
    add(["galagas buhok", "nagahulog buhok", "nagnipis buhok", "kadamo hulog sang buhok", "numipis buhok", "upod buhok", "kalbo", "nagadula buhok", "nagalagas buhok"],
        "hair loss", "alopecia", "Dermatology", "Low", "hair;loss;alopecia;balding", "hair;balding;alopecia;shedding")
    add(["nagakati mata", "ginakati mata", "katol sa mata", "naga katol mata"],
        "itchy eyes", "ocular pruritus", "Ophthalmology", "Low", "eye;itch;ocular", "eye;itch;allergy")

    # RESPIRATORY
    add(["ubo", "ginaubo", "ginauubo", "nagaubo", "nagauubo", "ubuhan", "kuhul", "ginakuhul"],
        "cough", "cough", "Respiratory", "Low", "cough;respiratory", "cough;respiratory;lung;throat")
    add(["sip-on", "sipon", "sip on", "ginasip-on", "ginasipon", "nagasipon", "barado ilong", "ginabarado ilong"],
        "runny nose", "rhinitis", "Respiratory", "Low", "rhinitis;congestion;nose", "nose;congestion;cold;rhinitis")
    add(["ginakapos ginhawa", "ginaginhawa budlay", "budlay ginhawa", "lisod magginhawa", "ginakapos", "ginagutok"],
        "shortness of breath", "dyspnea", "Respiratory", "High", "dyspnea;breathing;sob", "breathing;dyspnea;asthma;lung")
    add(["hubak", "ginahubak", "nagahubak", "singaw", "ginasingaw", "wheezing"],
        "wheezing", "wheezing", "Respiratory", "High", "wheezing;asthma", "wheezing;asthma;lung;breathing")
    add(["bahing", "ginabahing", "ginahingal", "hingal", "nagahingal"],
        "breathlessness", "dyspnea", "Respiratory", "High", "breathlessness;gasping", "breath;gasping;exertion")
    add(["masakit tutunlan", "sakit tutunlan", "hapdi tutunlan", "ginasakit tutunlan"],
        "sore throat", "pharyngitis", "Respiratory", "Low", "sore throat;pharyngitis", "throat;pain;pharyngitis")
    add(["asma", "asthma", "hika", "ginahika", "may hika"],
        "asthma", "asthma", "Respiratory", "High", "asthma;wheezing", "asthma;wheezing;breathing")
    add(["dugo sa ubo", "nagauubo sing dugo", "bloody cough"],
        "hemoptysis", "hemoptysis", "Emergency", "Critical", "hemoptysis;bloody cough", "blood;cough;lung;emergency")

    # CARDIOVASCULAR
    add(["sakit dughan", "sakit sang dughan", "masakit dughan", "hapdi dughan", "masakit dibdib", "sakit dibdib", "ginasakit dughan", "chest pain"],
        "chest pain", "chest pain", "Cardiovascular", "Critical", "chest pain;angina", "chest;heart;pain;emergency")
    add(["kusog tibok sang tagipusuon", "kusog tibok", "naga palpitate", "palpitations", "ginapalpitate", "mabilis tibok"],
        "palpitations", "palpitations", "Cardiovascular", "Medium", "palpitations;heart rate", "heart;palpitation;arrhythmia")
    add(["kulba dughan", "kaba dughan", "ginakaba", "nagakaba"],
        "chest anxiety", "chest discomfort", "Cardiovascular", "Medium", "anxiety;chest", "chest;anxiety;stress")
    add(["ginatight dughan", "tight chest", "chest tightness"],
        "chest tightness", "angina", "Cardiovascular", "Critical", "chest tightness;angina", "chest;heart;angina")
    add(["heart attack", "atake sa tagipusuon", "atake sa puso", "heart attack symptoms"],
        "heart attack", "myocardial infarction", "Emergency", "Critical", "heart attack;mi", "heart;attack;chest;pain;emergency")

    # NEUROLOGICAL
    add(["sakit ulo", "masakit ulo", "ulo masakit", "sakit sang ulo", "nagasakit ulo", "ginasakit ulo", "hapdi ulo", "panakit ulo"],
        "headache", "cephalalgia", "Neurological", "Medium", "headache;cephalalgia", "head;pain;headache;migraine")
    add(["kalipong", "lipong", "nalipong", "ginakalipong", "nagakalipong", "kalipong gid ko"],
        "dizziness", "dizziness", "Neurological", "Medium", "dizziness;vertigo", "dizzy;vertigo;balance")
    add(["pamamanhid", "ginapamamanhid", "manhid", "ginamanhid", "nagamanhid", "walay pagbati"],
        "numbness", "paresthesia", "Neurological", "Medium", "numbness;paresthesia", "tingling;nerve;loss;sensation")
    add(["nagakurog", "ginakurog", "kurog", "ginatay-og", "nagatay-og", "nagauyog"],
        "tremor", "tremor", "Neurological", "Medium", "tremor;shaking", "shaking;trembling;parkinson")
    add(["kombulsyon", "konvulsion", "ginakonvulsion", "nagakonvulsion", "seizure", "convulsion"],
        "seizure", "seizure", "Emergency", "Critical", "seizure;convulsion", "seizure;convulsion;epilepsy;emergency")
    add(["stroke", "stroke symptoms", "pamamanhid sa lawas", "dili makabaton maayo"],
        "stroke symptoms", "stroke", "Emergency", "Critical", "stroke;cva", "stroke;paralysis;speech;emergency")

    # DIGESTIVE
    add(["sakit tiyan", "sakit tyan", "masakit tiyan", "ginalain tiyan", "panakit tiyan", "sakit sikmura", "masakit sikmura", "ginasakit tiyan"],
        "stomach pain", "abdominal pain", "Gastroenterology", "Medium", "abdominal pain;stomach", "stomach;abdomen;pain;gi")
    add(["kalibanga", "gakalibanga", "ginakalibanga", "nagakalibanga", "ginatulo", "tulo-tulo", "lbm"],
        "diarrhea", "diarrhea", "Gastroenterology", "Medium", "diarrhea;loose stool", "diarrhea;stool;gi;infection")
    add(["kasukaon", "ginakasukaon", "nahihilo tiyan", "nausea"],
        "nausea", "nausea", "Gastroenterology", "Low", "nausea", "nausea;vomit;stomach")
    add(["suka", "nagsuka", "nagsusuka", "sumuka", "ginagsuka", "ginasuka", "sige ko suka"],
        "vomiting", "vomiting", "Gastroenterology", "Medium", "vomiting", "vomit;nausea;gi")
    add(["ginapanuhot", "panuhot", "ginapanuhot tiyan", "busog tiyan", "bloated stomach"],
        "bloating", "abdominal distension", "Gastroenterology", "Low", "bloating;distension", "bloat;gas;stomach")
    add(["wala gana kaon", "gakadula gana kaon", "wala gana", "dili gana kaon", "wala ko gana magkaon", "ginawala gana"],
        "loss of appetite", "anorexia", "Gastroenterology", "Low", "anorexia;appetite", "appetite;eat;anorexia")
    add(["buot", "constipation", "dili makalibang", "ginabuot", "ginadula kalibanga"],
        "constipation", "constipation", "Gastroenterology", "Low", "constipation", "constipation;bowel;gi")
    add(["dugo sa dumi", "may dugo sa dumi", "ginadugo tae", "dugo sa tae"],
        "bloody stool", "hematochezia", "Gastroenterology", "High", "hematochezia;gi bleeding", "blood;stool;gi;bleeding")

    # URINARY
    add(["masakit mag ihi", "masakit mag-ihi", "sakit mag ihi", "hapdi mag ihi", "ginasakit mag ihi"],
        "painful urination", "dysuria", "Urology", "Medium", "dysuria;urination pain", "urine;pain;uti;burning")
    add(["sige ihi", "sigehon ihi", "daku ihi", "frequent urination", "ginasige ihi"],
        "frequent urination", "polyuria", "Urology", "Medium", "polyuria;frequency", "urine;frequency;diabetes;uti")
    add(["may dugo sa ihi", "dugo sa ihi", "ginadugo ihi", "bloody urine"],
        "bloody urine", "hematuria", "Urology", "High", "hematuria;blood urine", "blood;urine;uti;kidney")
    add(["indi maka ihi", "dili maka ihi", "indi makaihi", "dili makaihi", "urinary retention"],
        "inability to urinate", "urinary retention", "Urology", "High", "urinary retention", "urine;retention;bladder")

    # MUSCULOSKELETAL
    add(["masakit likod", "sakit likod", "ginasakit likod", "panakit likod", "back pain"],
        "back pain", "back pain", "Musculoskeletal", "Medium", "back pain", "back;pain;spine;muscle")
    add(["masakit tuhod", "sakit tuhod", "ginasakit tuhod", "knee pain"],
        "knee pain", "knee pain", "Musculoskeletal", "Medium", "knee pain", "knee;pain;joint")
    add(["masakit kalawasan", "sakit lawas", "masakit lawas", "sakit kalawasan", "body pain", "ginasakit lawas"],
        "body pain", "myalgia", "Musculoskeletal", "Low", "myalgia;body pain", "body;pain;muscle;ache")
    add(["luya lawas", "ginluya lawas", "nagluya lawas", "mahina lawas", "ginamahina lawas", "ginaluya lawas"],
        "body weakness", "asthenia", "Musculoskeletal", "Medium", "weakness;asthenia", "weak;fatigue;body;strength")
    add(["luya kamot", "ginaluya kamot", "mahina kamot", "ginamahina kamot"],
        "arm weakness", "upper limb weakness", "Musculoskeletal", "Medium", "arm weakness", "arm;weak;limb")
    add(["luya tiil", "ginaluya tiil", "mahina tiil", "ginamahina tiil"],
        "leg weakness", "lower limb weakness", "Musculoskeletal", "Medium", "leg weakness", "leg;weak;limb")

    # EYES / EARS
    add(["naga pula mata", "nagapula mata", "mapula mata", "red eyes", "ginapula mata"],
        "red eyes", "conjunctival injection", "Ophthalmology", "Low", "red eyes;conjunctivitis", "eye;red;conjunctivitis")
    add(["malain panulok", "malain panan-awon", "blurry vision", "dili maayo panulok"],
        "blurred vision", "blurred vision", "Ophthalmology", "Medium", "blurred vision", "vision;blur;eye")
    add(["nagaluha mata", "ginluha mata", "watery eyes", "dagta sa mata"],
        "watery eyes", "epiphora", "Ophthalmology", "Low", "watery eyes;epiphora", "eye;tear;watery")
    add(["masakit dalunggan", "sakit dalunggan", "hapdi dalunggan", "ear pain"],
        "ear pain", "otalgia", "Otology", "Medium", "ear pain;otalgia", "ear;pain;otalgia")
    add(["naga tingog dalunggan", "nagatingog dalunggan", "ringing ears", "tinnitus"],
        "ringing in ears", "tinnitus", "Otology", "Low", "tinnitus;ringing", "ear;ringing;tinnitus")
    add(["indi ka baton maayo", "dili ka baton maayo", "dili makabati", "hearing loss"],
        "hearing difficulty", "hearing loss", "Otology", "Medium", "hearing loss", "hearing;deaf;ear")

    # MENTAL HEALTH
    add(["kabalaka", "ginakabalaka", "nagakabalaka", "worry", "anxiety"],
        "anxiety", "anxiety", "Mental Health", "Medium", "anxiety;worry", "anxiety;worry;stress")
    add(["stress", "ginastress", "stressed", "sobra stress"],
        "stress", "stress", "Mental Health", "Medium", "stress", "stress;mental;burnout")
    add(["depresyon", "depression", "ginadepresyon", "maluoy gid"],
        "depression", "depression", "Mental Health", "High", "depression", "depression;sad;mental")
    add(["indi katulog", "dili makatulog", "insomnia", "ginainomnia"],
        "insomnia", "insomnia", "Mental Health", "Medium", "insomnia;sleep", "sleep;insomnia;rest")
    add(["sobra katulog", "daku katulog", "hypersomnia"],
        "excessive sleepiness", "hypersomnia", "Mental Health", "Low", "hypersomnia;sleepiness", "sleep;fatigue;excessive")

    # GENERAL / EMERGENCY
    add(["kapoy", "kapoy gid", "kapoy gid ko", "ginakapoy", "ginakapoy gid", "kakapoy", "fatigue", "pagod"],
        "fatigue", "fatigue", "General Medicine", "Low", "fatigue;tiredness", "tired;fatigue;weak;exhaustion")
    add(["hilanat", "ginahilantan", "ginahilanat", "mainit lawas", "may lagnat", "lagnat", "init lawas", "fever"],
        "fever", "fever", "General Medicine", "Medium", "fever;pyrexia", "fever;temperature;infection")
    add(["ginatugnaw", "tugnaw", "ginatugnaw lawas", "chills"],
        "chills", "chills", "General Medicine", "Low", "chills", "chill;fever;cold")
    add(["severe shortness of breath", "ginakapos gid ginhawa", "dili makaginhawa", "ginakapos sing malala"],
        "severe shortness of breath", "acute dyspnea", "Emergency", "Critical", "severe dyspnea;sob", "breathing;emergency;dyspnea;critical")
    add(["nagapunaw", "fainting", "nawala panimuot", "unconscious", "unconsciousness", "wala panimuot"],
        "unconsciousness", "loss of consciousness", "Emergency", "Critical", "unconscious;syncope", "unconscious;faint;collapse;emergency")
    add(["severe bleeding", "dugo sing malala", "ginatulo sing dugo sing malala", "daku nga dugo"],
        "severe bleeding", "hemorrhage", "Emergency", "Critical", "hemorrhage;bleeding", "blood;bleeding;emergency;trauma")
    add(["nagatulo dugo", "ginatulo dugo", "bleeding", "dugo"],
        "bleeding", "hemorrhage", "Emergency", "Critical", "bleeding;hemorrhage", "blood;bleeding;wound")

    # Body-part pain / weakness batches (controlled, no recursive explosion)
    for part in BODY_PARTS:
        add(pain_phrases(part), f"{part} pain", "pain", "General Medicine", "Medium", f"pain;{part}", f"pain;{part};ache")
    for part in ("lawas", "kamot", "tiil", "kalawasan"):
        add(weakness_phrases(part), f"{part} weakness", "asthenia", "Musculoskeletal", "Medium", f"weakness;{part}", f"weak;{part};strength")

    return c


def expand_concept(concept: Concept) -> list[str]:
    seeds = list(dict.fromkeys(concept.roots + concept.extra_variants))
    terms: list[str] = []
    seen: set[str] = set()

    def push(raw: str) -> None:
        key = norm(raw)
        if not key or key in seen or len(key) < 2 or len(key) > 85:
            return
        seen.add(key)
        terms.append(raw.strip())

    for seed in seeds:
        push(seed)
        for v in spelling_variants(seed):
            push(v)
        for v in prefix_variants(seed):
            push(v)
        for v in suffix_variants(seed):
            push(v)
        for v in sentence_wrap(seed):
            push(v)
        if len(terms) >= MAX_VARIANTS_PER_CONCEPT:
            break

    return terms[:MAX_VARIANTS_PER_CONCEPT]


def build_rows() -> list[dict[str, str]]:
    grouped: dict[str, dict] = {}
    group_members: dict[str, list[str]] = {}

    for concept in concepts():
        body_system = CATEGORY_BODY_SYSTEM.get(concept.category, "general")
        group_key = norm(f"{concept.medical_term}|{concept.english}")
        group_members.setdefault(group_key, [])

        for term in expand_concept(concept):
            key = norm(term)
            if key in grouped:
                continue
            grouped[key] = {
                "hiligaynon_term": term,
                "english_translation": concept.english,
                "medical_term": concept.medical_term,
                "medical_category": concept.category,
                "body_system": body_system,
                "severity": concept.severity,
                "symptom_keywords": concept.symptom_keywords,
                "confidence_keywords": concept.confidence_keywords,
                "group_key": group_key,
            }
            group_members[group_key].append(term)

    # Fill to TARGET_ROWS with sentence-pattern compounds
    filler_templates = [
        "subong {t}", "gid {t}", "gid ko {t}", "may {t} ako", "may {t} ko",
        "ang {t} ko", "ginafeel ko ang {t}", "naga experience ko sang {t}",
        "sobra {t}", "grabeh {t}", "malala ang {t} ko", "ginabal-an ko sang {t}",
        "naga complain ko sang {t}", "dako gid ang {t} ko", "wala ko kasulay sang {t}",
    ]
    base_rows = list(grouped.values())
    idx = 0
    while len(grouped) < TARGET_ROWS and base_rows:
        row = base_rows[idx % len(base_rows)]
        base = row["hiligaynon_term"]
        for pat in filler_templates:
            term = pat.format(t=base).strip()
            key = norm(term)
            if key in grouped or len(term) > 90:
                continue
            grouped[key] = {
                "hiligaynon_term": term,
                "english_translation": row["english_translation"],
                "medical_term": row["medical_term"],
                "medical_category": row["medical_category"],
                "body_system": row["body_system"],
                "severity": row["severity"],
                "symptom_keywords": row["symptom_keywords"],
                "confidence_keywords": row["confidence_keywords"],
                "group_key": row["group_key"],
            }
            group_members[row["group_key"]].append(term)
            if len(grouped) >= TARGET_ROWS:
                break
        idx += 1
        if idx > len(base_rows) * len(filler_templates):
            break

    for key, row in grouped.items():
        siblings = [t for t in group_members.get(row["group_key"], []) if norm(t) != key]
        alts = sorted(dict.fromkeys(siblings), key=lambda x: (-len(x), x))[:35]
        row["alternative_spellings"] = ";".join(alts)

    rows = list(grouped.values())
    rows.sort(key=lambda r: (r["medical_category"], r["english_translation"], norm(r["hiligaynon_term"])))

    # Guarantee required phrases exist even if expansion cap skipped them
    for phrase, meta in REQUIRED_PHRASES.items():
        key = norm(phrase)
        if key not in grouped:
            english, medical, category, severity, sym_kw, conf_kw, body_system = meta
            grouped[key] = {
                "hiligaynon_term": phrase,
                "english_translation": english,
                "medical_term": medical,
                "medical_category": category,
                "body_system": body_system,
                "severity": severity,
                "symptom_keywords": sym_kw,
                "confidence_keywords": conf_kw,
                "group_key": norm(f"{medical}|{english}"),
                "alternative_spellings": "",
            }
    rows = list(grouped.values())
    rows.sort(key=lambda r: (r["medical_category"], r["english_translation"], norm(r["hiligaynon_term"])))
    for i, row in enumerate(rows, start=1):
        row["id"] = str(i)
    return rows


def main() -> None:
    rows = build_rows()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "id", "hiligaynon_term", "alternative_spellings", "english_translation",
        "medical_term", "medical_category", "body_system", "severity",
        "symptom_keywords", "confidence_keywords",
    ]
    with OUT.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    cats: dict[str, int] = {}
    for r in rows:
        cats[r["medical_category"]] = cats.get(r["medical_category"], 0) + 1

    print(f"Wrote {len(rows):,} entries to {OUT}")
    for cat, count in sorted(cats.items(), key=lambda x: -x[1]):
        print(f"  {cat}: {count:,}")

    index = {norm(r["hiligaynon_term"]): r for r in rows}
    print("\nRequired phrase coverage:")
    for phrase in [
        "galagas buhok", "kakatul lawas", "sakit sang dughan", "kalipong", "luya lawas",
        "sakit sang ulo", "budlay ginhawa", "masakit mag ihi", "kalipong gid ko", "kapoy gid ko",
    ]:
        hit = index.get(norm(phrase))
        print(f"  {phrase}: {'OK -> ' + hit['english_translation'] if hit else 'MISSING'}")


if __name__ == "__main__":
    main()
