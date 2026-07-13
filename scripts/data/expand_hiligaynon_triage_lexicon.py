#!/usr/bin/env python3
"""
Expand Hiligaynon medical language coverage for AI-Powered Triage.

Merges curated formal/informal patient expressions, slang, abbreviations,
alternative spellings, and misspellings into:
  - data/nlp/hiligaynon_symptom_lexicon.json  (fuzzy matcher)
  - data/nlp/medical_dictionary.csv          (exact → English before AI)
  - data/nlp/hiligaynon_symptoms.csv
  - data/nlp/hiligaynon_conditions.csv
  - data/nlp/medical_misspellings.csv
  - data/nlp/hiligaynon_medical_nlp_dataset.csv (append unique phrases)

Many Hiligaynon forms map to one standardized English medical concept.
"""

from __future__ import annotations

import csv
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"

# ---------------------------------------------------------------------------
# Symptom concepts: key → english, medical_term, category, hiligaynon variants
# ---------------------------------------------------------------------------
SYMPTOM_EXPAND: dict[str, dict] = {
    "fever": {
        "english": "fever",
        "medical_term": "fever",
        "category": "general",
        "hiligaynon": [
            "hilantan", "hilanat", "ginahilanat", "ginahilanat ko", "ginahilantan",
            "gilantan", "lagnat", "may lagnat", "mainit lawas", "mainit lawas ko",
            "init lawas", "init lawas ko", "ginahilanat sing taas", "may hilanat",
            "naghilanat", "ga hilanat", "ga-hilanat", "high fever", "taas hilanat",
        ],
    },
    "chills": {
        "english": "chills",
        "medical_term": "chills",
        "category": "general",
        "hiligaynon": [
            "ginatugnaw", "ginatugnaw ko", "tugnaw", "ginatugnaw lawas",
            "ginakaligtan", "nagaligtan", "ginapaginatugnaw", "malamig lawas",
            "ginakurig", "nagakurig tungod tugnaw",
        ],
    },
    "fever_with_chills": {
        "english": "fever with chills",
        "medical_term": "fever with chills",
        "category": "general",
        "hiligaynon": [
            "ginakurog kag ginatugnaw", "ginakurog kag ginatugnaw ko",
            "hilanat kag tugnaw", "ginahilanat kag ginatugnaw",
            "mainit lawas kag ginatugnaw", "fever with chills",
        ],
    },
    "rhinorrhea": {
        "english": "runny nose",
        "medical_term": "rhinorrhea",
        "category": "respiratory",
        "hiligaynon": [
            "ginasip-on", "ginasip-on ko", "ginasipon", "sip-on", "sipon", "sip on",
            "nagasipon", "barado ilong", "ginabarado ilong", "nagaawas sipon",
            "may sip-on", "may sipon", "colds",
        ],
    },
    "cough": {
        "english": "cough",
        "medical_term": "cough",
        "category": "respiratory",
        "hiligaynon": [
            "ubo", "ginaubo", "ginaubo ko", "ginauubo", "nagauubo", "nagaubo",
            "ubuhan", "kuhul", "ginakuhul", "gahika", "gahika ko", "may ubo",
            "dry cough", "uga ubo", "basa ubo",
        ],
    },
    "dyspnea": {
        "english": "difficulty breathing",
        "medical_term": "dyspnea",
        "category": "respiratory",
        "hiligaynon": [
            "ginaginhawa ko budlay", "ginaginhawa budlay", "budlay magginhawa",
            "budlay ginhawa", "lisod magginhawa", "ginabudlayan ginhawa",
            "ginakapos ginhawa", "ginakapos", "shortness of breath",
            "indi makaginhawa maayo", "dula ginhawa",
        ],
    },
    "breathlessness": {
        "english": "shortness of breath",
        "medical_term": "dyspnea",
        "category": "respiratory",
        "hiligaynon": [
            "ginakulbaan ko magginhawa", "ginakulbaan magginhawa", "kulbaan magginhawa",
            "ginahingal", "hingal", "bahing", "ginabahing", "kapos ginhawa",
            "SOB", "breathless",
        ],
    },
    "headache": {
        "english": "headache",
        "medical_term": "headache",
        "category": "neurological",
        "hiligaynon": [
            "ginasakit ulo", "ginasakit ulo ko", "sakit ulo", "sakit sang ulo",
            "masakit ulo", "labad ulo", "labad ang ulo", "sakit sa ulo",
            "ulo masakit", "nagasakit ulo", "migraine", "migren",
        ],
    },
    "sore_throat": {
        "english": "sore throat",
        "medical_term": "pharyngitis",
        "category": "general",
        "hiligaynon": [
            "ginasakit tutunlan", "ginasakit tutunlan ko", "sakit tutunlan",
            "masakit tutunlan", "hapdi tutunlan", "hapdi tutunlan ko",
            "sakit sa tutunlan", "sore throat",
        ],
    },
    "chest_pain": {
        "english": "chest pain",
        "medical_term": "chest pain",
        "category": "cardiovascular",
        "hiligaynon": [
            "ginasakit dughan", "ginasakit dughan ko", "sakit dughan", "hapdi dughan",
            "masakit dughan", "sakit dibdib", "masakit dibdib", "pig-ot dughan",
            "chest pain",
        ],
    },
    "abdominal_pain": {
        "english": "stomach pain",
        "medical_term": "abdominal pain",
        "category": "digestive",
        "hiligaynon": [
            "ginasakit tiyan", "ginasakit tiyan ko", "sakit tiyan", "masakit tiyan",
            "sakit sikmura", "masakit sikmura", "ginalain tiyan", "panakit tiyan",
            "baldom tiyan", "ginabaldom", "stomach pain", "sakit sa tiyan",
        ],
    },
    "dizziness": {
        "english": "dizziness",
        "medical_term": "dizziness",
        "category": "neurological",
        "hiligaynon": [
            "ginakalipong", "ginakalipong ko", "kalipong", "nalipong", "nagalipong",
            "nahilo", "nahihilo", "lipong", "dizzy", "ginahilo",
        ],
    },
    "vomiting": {
        "english": "vomiting",
        "medical_term": "vomiting",
        "category": "digestive",
        "hiligaynon": [
            "ginasuka", "ginasuka ko", "suka", "nagsuka", "nagsusuka", "sumuka",
            "ginagsuka", "nagasuka", "vomiting",
        ],
    },
    "nausea": {
        "english": "nausea",
        "medical_term": "nausea",
        "category": "digestive",
        "hiligaynon": [
            "ginapalanuka", "ginapalanuka ko", "kasukaon", "ginakasukaon",
            "nahihilo tiyan", "ginabalik tiyan", "nausea", "daw magasuka",
            "palanuka", "gana magasuka",
        ],
    },
    "diarrhea": {
        "english": "diarrhea",
        "medical_term": "diarrhea",
        "category": "digestive",
        "hiligaynon": [
            "ginakalibanga", "ginakalibanga ko", "kalibanga", "gakalibanga",
            "nagakalibanga", "ginatulo", "tulo-tulo", "lbm", "LBM", "diarrhea",
            "ginatulo sing dugo",
        ],
    },
    "constipation": {
        "english": "constipation",
        "medical_term": "constipation",
        "category": "digestive",
        "hiligaynon": [
            "constipated ko", "constipation", "constipated", "indi makaibot",
            "tig-a tae", "budlay mag-ibot", "ginabuot", "dili makalibang",
            "ginadula kalibanga", "gina-constipation",
        ],
    },
    "fatigue": {
        "english": "fatigue",
        "medical_term": "fatigue",
        "category": "general",
        "hiligaynon": [
            "ginakapoy gid", "ginakapoy gid ko", "kapoy gid", "kapoy", "kakapoy",
            "ginakapoy", "mahina lawas", "luya", "fatigue", "pagod gid",
        ],
    },
    "anorexia": {
        "english": "loss of appetite",
        "medical_term": "anorexia",
        "category": "digestive",
        "hiligaynon": [
            "wala ko gana magkaon", "wala gana magkaon", "wala gana kaon",
            "wala gana", "gakadula gana kaon", "dili gana kaon", "ginawala gana",
            "wala appetite", "indi gusto magkaon", "nawala gana",
        ],
    },
    "edema": {
        "english": "swelling",
        "medical_term": "edema",
        "category": "general",
        "hiligaynon": [
            "ginapalanhubag", "ginapalanhubag ko", "gahubag", "gahabok", "hubag",
            "habok", "namaga", "nagahubag", "swelling", "palanhubag",
        ],
    },
    "foot_edema": {
        "english": "swollen feet",
        "medical_term": "pedal edema",
        "category": "general",
        "hiligaynon": [
            "ginahubag ang tiil", "ginahubag ang tiil ko", "gahubag tiil",
            "hubag tiil", "namaga tiil", "swollen feet", "gahabok tiil",
        ],
    },
    "hand_edema": {
        "english": "swollen hands",
        "medical_term": "hand edema",
        "category": "general",
        "hiligaynon": [
            "ginahubag ang kamot", "ginahubag ang kamot ko", "gahubag kamot",
            "hubag kamot", "namaga kamot", "swollen hands", "gahabok kamot",
        ],
    },
    "paresthesia": {
        "english": "numbness",
        "medical_term": "paresthesia",
        "category": "neurological",
        "hiligaynon": [
            "ginapalanum", "ginapalanum ko", "pamamanhid", "ginapamamanhid",
            "manhid", "ginamanhid", "nagamanhid", "numbness", "palanum",
            "daw wala batyag",
        ],
    },
    "tremor": {
        "english": "tremor",
        "medical_term": "tremor",
        "category": "neurological",
        "hiligaynon": [
            "ginakurog", "ginakurog ko", "nagakurog", "kurog", "ginatay-og",
            "nagatay-og", "tremors", "shaking",
        ],
    },
    "bloating": {
        "english": "bloating",
        "medical_term": "abdominal distension",
        "category": "digestive",
        "hiligaynon": [
            "ginapanuhot", "ginapanuhot ko", "panuhot", "ginapanuhot tiyan",
            "busog tiyan", "ginabusog tiyan", "bloating", "gas tiyan",
        ],
    },
    "difficulty_walking": {
        "english": "difficulty walking",
        "medical_term": "gait difficulty",
        "category": "musculoskeletal",
        "hiligaynon": [
            "ginabudlayan ko lakat", "ginabudlayan lakat", "budlay maglakat",
            "lisod maglakat", "indi makalakat maayo", "difficulty walking",
            "ginapalangluya maglakat",
        ],
    },
    "eye_irritation": {
        "english": "eye irritation",
        "medical_term": "eye irritation",
        "category": "eye_ear",
        "hiligaynon": [
            "ginahapdos ang mata", "ginahapdos ang mata ko", "hapdos mata",
            "ginasakit mata", "eye irritation", "makati mata", "ginakati mata",
        ],
    },
    "conjunctivitis": {
        "english": "red eyes",
        "medical_term": "conjunctivitis",
        "category": "eye_ear",
        "hiligaynon": [
            "ginapula mata", "ginapula mata ko", "naga pula mata", "nagapula mata",
            "pamula mata", "mapula mata", "red eyes", "pula mata",
        ],
    },
    "pruritus": {
        "english": "itchy skin",
        "medical_term": "pruritus",
        "category": "skin",
        "hiligaynon": [
            "ginakati ang panit", "ginakati ang panit ko", "ginakati", "kakatol",
            "kakatul", "katol", "makatol", "itchy skin", "ga katol panit",
            "kumakati panit",
        ],
    },
    "rash": {
        "english": "skin rash",
        "medical_term": "rash",
        "category": "skin",
        "hiligaynon": [
            "may rashes", "may rashes ko", "rashes", "rash", "bugas", "butlig",
            "galis", "pantal", "pantal-pantal", "butlig-butlig", "gapula lawas",
            "skin rash", "may pantal",
        ],
    },
    "epistaxis": {
        "english": "nosebleed",
        "medical_term": "epistaxis",
        "category": "general",
        "hiligaynon": [
            "ginadugo ilong", "ginadugo ilong ko", "nagaibus dugo sa ilong",
            "nagdugo ilong", "dugo sa ilong", "nosebleed", "epistaxis",
        ],
    },
    "bleeding": {
        "english": "bleeding",
        "medical_term": "hemorrhage",
        "category": "general",
        "hiligaynon": [
            "ginadugo", "ginadugo ko", "nagdugo", "nagadugo", "may dugo",
            "bleeding", "indi mapunggan dugo",
        ],
    },
    "weakness": {
        "english": "weakness",
        "medical_term": "asthenia",
        "category": "general",
        "hiligaynon": [
            "ginapalangluya", "ginapalangluya ko", "luya", "mahina lawas",
            "galuya lawas", "ginakaluya", "weakness", "palangluya",
            "ginamahina",
        ],
    },
    "insomnia": {
        "english": "difficulty sleeping",
        "medical_term": "insomnia",
        "category": "mental_health",
        "hiligaynon": [
            "ginakabudlayan ko tulog", "ginakabudlayan tulog", "indi makatulog",
            "budlay magtulog", "wala tulog", "dili makatulog", "indi katulog",
            "difficulty sleeping", "insomnia", "gina-insomnia",
        ],
    },
    "wheezing": {
        "english": "wheezing",
        "medical_term": "wheezing",
        "category": "respiratory",
        "hiligaynon": ["hubak", "nagahubak", "ginahubak", "singaw", "wheezing", "hika sound"],
    },
}

# Illness / condition concepts (Hiligaynon + common patient labels → English)
CONDITION_EXPAND: list[tuple[str, str, str, str, str]] = [
    # hiligaynon_term, english_term, medical_category, severity, triage_level
    ("trangkaso", "influenza", "Infectious", "Medium", "urgent"),
    ("trangkaso ko", "influenza", "Infectious", "Medium", "urgent"),
    ("may trangkaso", "influenza", "Infectious", "Medium", "urgent"),
    ("flu", "influenza", "Infectious", "Medium", "urgent"),
    ("influenza", "influenza", "Infectious", "Medium", "urgent"),
    ("sip-on", "common cold", "Respiratory", "Mild", "non_urgent"),
    ("sipon", "common cold", "Respiratory", "Mild", "non_urgent"),
    ("may sip-on", "common cold", "Respiratory", "Mild", "non_urgent"),
    ("colds", "common cold", "Respiratory", "Mild", "non_urgent"),
    ("common cold", "common cold", "Respiratory", "Mild", "non_urgent"),
    ("hilanat", "fever", "General", "Medium", "urgent"),
    ("may hilanat", "fever", "General", "Medium", "urgent"),
    ("ubo", "cough", "Respiratory", "Mild", "non_urgent"),
    ("may ubo", "cough", "Respiratory", "Mild", "non_urgent"),
    ("hika", "asthma", "Respiratory", "High", "urgent"),
    ("hika ko", "asthma", "Respiratory", "High", "urgent"),
    ("asthma", "asthma", "Respiratory", "High", "urgent"),
    ("hubak", "asthma", "Respiratory", "High", "urgent"),
    ("pneumonia", "pneumonia", "Respiratory", "Critical", "emergency"),
    ("may pneumonia", "pneumonia", "Respiratory", "Critical", "emergency"),
    ("pulmonya", "pneumonia", "Respiratory", "Critical", "emergency"),
    ("tuberculosis", "tuberculosis", "Infectious", "High", "urgent"),
    ("TB", "tuberculosis", "Infectious", "High", "urgent"),
    ("tb", "tuberculosis", "Infectious", "High", "urgent"),
    ("T.B.", "tuberculosis", "Infectious", "High", "urgent"),
    ("tisis", "tuberculosis", "Infectious", "High", "urgent"),
    ("may TB", "tuberculosis", "Infectious", "High", "urgent"),
    ("dengue", "dengue fever", "Infectious", "Critical", "emergency"),
    ("dengue fever", "dengue fever", "Infectious", "Critical", "emergency"),
    ("may dengue", "dengue fever", "Infectious", "Critical", "emergency"),
    ("DB", "dengue fever", "Infectious", "Critical", "emergency"),
    ("leptospirosis", "leptospirosis", "Infectious", "Critical", "emergency"),
    ("lepto", "leptospirosis", "Infectious", "Critical", "emergency"),
    ("may leptospirosis", "leptospirosis", "Infectious", "Critical", "emergency"),
    ("COVID", "COVID-19", "Infectious", "High", "urgent"),
    ("covid", "COVID-19", "Infectious", "High", "urgent"),
    ("COVID-19", "COVID-19", "Infectious", "High", "urgent"),
    ("covid 19", "COVID-19", "Infectious", "High", "urgent"),
    ("corona", "COVID-19", "Infectious", "High", "urgent"),
    ("coronavirus", "COVID-19", "Infectious", "High", "urgent"),
    ("high blood", "hypertension", "Cardiovascular", "High", "urgent"),
    ("highblood", "hypertension", "Cardiovascular", "High", "urgent"),
    ("alta presyon", "hypertension", "Cardiovascular", "High", "urgent"),
    ("taas BP", "hypertension", "Cardiovascular", "High", "urgent"),
    ("taas blood pressure", "hypertension", "Cardiovascular", "High", "urgent"),
    ("hypertension", "hypertension", "Cardiovascular", "High", "urgent"),
    ("HPN", "hypertension", "Cardiovascular", "High", "urgent"),
    ("low blood", "hypotension", "Cardiovascular", "Medium", "urgent"),
    ("lowblood", "hypotension", "Cardiovascular", "Medium", "urgent"),
    ("baba BP", "hypotension", "Cardiovascular", "Medium", "urgent"),
    ("hypotension", "hypotension", "Cardiovascular", "Medium", "urgent"),
    ("diabetes", "diabetes mellitus", "Endocrine", "Medium", "urgent"),
    ("diabetis", "diabetes mellitus", "Endocrine", "Medium", "urgent"),
    ("may diabetes", "diabetes mellitus", "Endocrine", "Medium", "urgent"),
    ("asukal", "diabetes mellitus", "Endocrine", "Medium", "urgent"),
    ("DM", "diabetes mellitus", "Endocrine", "Medium", "urgent"),
    ("UTI", "urinary tract infection", "Urinary", "Medium", "urgent"),
    ("uti", "urinary tract infection", "Urinary", "Medium", "urgent"),
    ("may UTI", "urinary tract infection", "Urinary", "Medium", "urgent"),
    ("impeksyon sa ihi", "urinary tract infection", "Urinary", "Medium", "urgent"),
    ("hyperacidity", "hyperacidity", "Digestive", "Mild", "non_urgent"),
    ("hyper acidity", "hyperacidity", "Digestive", "Mild", "non_urgent"),
    ("asido", "hyperacidity", "Digestive", "Mild", "non_urgent"),
    ("gastric", "hyperacidity", "Digestive", "Mild", "non_urgent"),
    ("ulcer", "peptic ulcer", "Digestive", "Medium", "urgent"),
    ("ulser", "peptic ulcer", "Digestive", "Medium", "urgent"),
    ("may ulcer", "peptic ulcer", "Digestive", "Medium", "urgent"),
    ("sakit sa sikmura ulcer", "peptic ulcer", "Digestive", "Medium", "urgent"),
    ("migraine", "migraine", "Neurological", "Medium", "urgent"),
    ("migren", "migraine", "Neurological", "Medium", "urgent"),
    ("may migraine", "migraine", "Neurological", "Medium", "urgent"),
    ("arthritis", "arthritis", "Musculoskeletal", "Medium", "urgent"),
    ("artraytis", "arthritis", "Musculoskeletal", "Medium", "urgent"),
    ("rheumatism", "arthritis", "Musculoskeletal", "Medium", "urgent"),
    ("rayuma", "arthritis", "Musculoskeletal", "Medium", "urgent"),
    ("heart disease", "heart disease", "Cardiovascular", "High", "urgent"),
    ("sakit sa tagipusuon", "heart disease", "Cardiovascular", "High", "urgent"),
    ("sakit puso", "heart disease", "Cardiovascular", "High", "urgent"),
    ("cardiac disease", "heart disease", "Cardiovascular", "High", "urgent"),
    ("stroke", "stroke", "Neurological", "Critical", "emergency"),
    ("atake sa utok", "stroke", "Neurological", "Critical", "emergency"),
    ("CVA", "stroke", "Neurological", "Critical", "emergency"),
    ("kidney disease", "kidney disease", "Urinary", "High", "urgent"),
    ("sakit sa kidney", "kidney disease", "Urinary", "High", "urgent"),
    ("sakit bato", "kidney disease", "Urinary", "High", "urgent"),
    ("CKD", "chronic kidney disease", "Urinary", "High", "urgent"),
    ("allergy", "allergy", "Allergy", "Medium", "urgent"),
    ("alergiya", "allergy", "Allergy", "Medium", "urgent"),
    ("may allergy", "allergy", "Allergy", "Medium", "urgent"),
    ("skin infection", "skin infection", "Infection", "Medium", "urgent"),
    ("impeksyon sa panit", "skin infection", "Infection", "Medium", "urgent"),
    ("chickenpox", "chickenpox", "Infectious", "Medium", "urgent"),
    ("chicken pox", "chickenpox", "Infectious", "Medium", "urgent"),
    ("bulutong tubig", "chickenpox", "Infectious", "Medium", "urgent"),
    ("varicella", "chickenpox", "Infectious", "Medium", "urgent"),
    ("measles", "measles", "Infectious", "High", "urgent"),
    ("tigdas", "measles", "Infectious", "High", "urgent"),
    ("may tigdas", "measles", "Infectious", "High", "urgent"),
    ("mumps", "mumps", "Infectious", "Medium", "urgent"),
    ("beke", "mumps", "Infectious", "Medium", "urgent"),
    ("may beke", "mumps", "Infectious", "Medium", "urgent"),
    ("parotitis", "mumps", "Infectious", "Medium", "urgent"),
]

# Common misspellings / informal typing → correct Hiligaynon root
MISSPELLINGS: list[tuple[str, str, str]] = [
    # correct, misspelling, term_type
    ("hilanat", "hilantan", "symptom_root"),
    ("hilanat", "hilant", "symptom_root"),
    ("hilanat", "hilantat", "symptom_root"),
    ("hilanat", "ginahilant", "symptom_root"),
    ("ginatugnaw", "ginatugnaww", "symptom_root"),
    ("ginatugnaw", "ginatugaw", "symptom_root"),
    ("ginasip-on", "ginasipon", "symptom_root"),
    ("ginasip-on", "ginasipn", "symptom_root"),
    ("ginasip-on", "sippon", "symptom_root"),
    ("ubo", "uboo", "symptom_root"),
    ("ubo", "uhbo", "symptom_root"),
    ("budlay ginhawa", "budlay ginhawaa", "symptom_phrase"),
    ("budlay magginhawa", "budlai magginhawa", "symptom_phrase"),
    ("ginasakit ulo", "ginasakit ulo ko", "symptom_phrase"),
    ("ginasakit ulo", "ginasakitulo", "symptom_phrase"),
    ("ginasakit tutunlan", "ginasakit tutunlan ko", "symptom_phrase"),
    ("ginasakit tutunlan", "ginasakit tutunaln", "symptom_phrase"),
    ("ginasakit dughan", "ginasakit dugan", "symptom_phrase"),
    ("ginasakit tiyan", "ginasakit tyan", "symptom_phrase"),
    ("ginakalipong", "ginakalipng", "symptom_root"),
    ("ginakalipong", "ginakalipon", "symptom_root"),
    ("ginasuka", "ginasuka ko", "symptom_phrase"),
    ("ginapalanuka", "ginapalanuka ko", "symptom_phrase"),
    ("ginapalanuka", "ginapalanukaa", "symptom_root"),
    ("ginapalanuka", "ginapalanuka", "symptom_root"),
    ("ginakalibanga", "ginakalibanga ko", "symptom_phrase"),
    ("ginakalibanga", "ginakalibnga", "symptom_root"),
    ("constipation", "constipated", "symptom_root"),
    ("ginakapoy", "ginakapoyy", "symptom_root"),
    ("ginapalanhubag", "ginapalanhubg", "symptom_root"),
    ("ginapalanum", "ginapalanum", "symptom_root"),
    ("ginakurog", "ginakurogg", "symptom_root"),
    ("ginapanuhot", "ginapanuot", "symptom_root"),
    ("ginahapdos", "ginahapdos mata", "symptom_phrase"),
    ("ginakati", "ginakati panit", "symptom_phrase"),
    ("ginadugo ilong", "ginadugo ilng", "symptom_phrase"),
    ("trangkaso", "trankaso", "condition"),
    ("trangkaso", "trangkasu", "condition"),
    ("trangkaso", "trangkazoo", "condition"),
    ("pneumonia", "neumonia", "condition"),
    ("pneumonia", "punemonia", "condition"),
    ("pneumonia", "pnumonia", "condition"),
    ("tuberculosis", "tubercolosis", "condition"),
    ("tuberculosis", "tiberculosis", "condition"),
    ("leptospirosis", "leptospirsis", "condition"),
    ("leptospirosis", "leptospiroses", "condition"),
    ("diabetes", "diebetes", "condition"),
    ("diabetes", "diabetez", "condition"),
    ("hypertension", "hight blood", "condition"),
    ("hypertension", "hig blood", "condition"),
    ("measles", "measeles", "condition"),
    ("mumps", "mumpss", "condition"),
    ("chickenpox", "chikenpox", "condition"),
    ("chickenpox", "chickenpoxx", "condition"),
]


def _uniq_keep_order(items: list[str]) -> list[str]:
    seen: set[str] = set()
    out: list[str] = []
    for x in items:
        k = re.sub(r"\s+", " ", (x or "").strip().lower())
        if not k or k in seen:
            continue
        seen.add(k)
        out.append(k)
    return out


def merge_lexicon() -> tuple[int, int]:
    path = NLP / "hiligaynon_symptom_lexicon.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    symptoms = data.setdefault("symptoms", {})
    new_keys = 0
    new_variants = 0

    for key, spec in SYMPTOM_EXPAND.items():
        variants = _uniq_keep_order(list(spec["hiligaynon"]))
        if key not in symptoms:
            symptoms[key] = {
                "english": spec["english"],
                "medical_term": spec["medical_term"],
                "category": spec["category"],
                "hiligaynon": variants,
            }
            new_keys += 1
            new_variants += len(variants)
            continue

        entry = symptoms[key]
        existing = _uniq_keep_order(list(entry.get("hiligaynon") or []))
        before = len(existing)
        merged = _uniq_keep_order(existing + variants)
        entry["hiligaynon"] = merged
        entry["english"] = entry.get("english") or spec["english"]
        entry["medical_term"] = entry.get("medical_term") or spec["medical_term"]
        entry["category"] = entry.get("category") or spec["category"]
        new_variants += max(0, len(merged) - before)

    path.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    return new_keys, new_variants


def merge_dictionary() -> int:
    path = NLP / "medical_dictionary.csv"
    rows = list(csv.DictReader(path.open(encoding="utf-8")))
    existing = {(r.get("local_term") or "").strip().lower() for r in rows}
    max_id = max((int(r["dictionary_id"]) for r in rows if str(r.get("dictionary_id", "")).isdigit()), default=0)
    added = 0

    def add(local: str, english: str, category: str) -> None:
        nonlocal max_id, added
        key = re.sub(r"\s+", " ", local.strip().lower())
        if not key or key in existing:
            return
        max_id += 1
        rows.append(
            {
                "dictionary_id": str(max_id),
                "local_term": key,
                "english_term": english.strip(),
                "category": category,
            }
        )
        existing.add(key)
        added += 1

    for key, spec in SYMPTOM_EXPAND.items():
        eng = spec["medical_term"] or spec["english"]
        for v in spec["hiligaynon"]:
            add(v, eng, "symptom")

    for hil, eng, cat, _sev, _tri in CONDITION_EXPAND:
        add(hil, eng, "condition")

    # Extra informal body-part / patient sayings
    extras = [
        ("mainit lawas ko", "fever", "symptom"),
        ("ginasip-on ko", "runny nose", "symptom"),
        ("ginakulbaan ko magginhawa", "shortness of breath", "symptom"),
        ("ginasakit ulo ko", "headache", "symptom"),
        ("ginasakit tutunlan ko", "sore throat", "symptom"),
        ("ginasakit dughan ko", "chest pain", "symptom"),
        ("ginasakit tiyan ko", "abdominal pain", "symptom"),
        ("ginakalipong ko", "dizziness", "symptom"),
        ("ginahilanat ko", "fever", "symptom"),
        ("ginasuka ko", "vomiting", "symptom"),
        ("ginapalanuka ko", "nausea", "symptom"),
        ("ginakalibanga ko", "diarrhea", "symptom"),
        ("constipated ko", "constipation", "symptom"),
        ("ginakapoy gid ko", "fatigue", "symptom"),
        ("ginapalanhubag ko", "swelling", "symptom"),
        ("ginapalanum ko", "numbness", "symptom"),
        ("ginakurog ko", "tremor", "symptom"),
        ("ginapanuhot ko", "bloating", "symptom"),
        ("ginakurog kag ginatugnaw ko", "fever with chills", "symptom"),
        ("ginabudlayan ko lakat", "difficulty walking", "symptom"),
        ("ginahapdos ang mata ko", "eye irritation", "symptom"),
        ("ginapula mata ko", "red eyes", "symptom"),
        ("ginakati ang panit ko", "itchy skin", "symptom"),
        ("may rashes ko", "rash", "symptom"),
        ("ginadugo ilong ko", "nosebleed", "symptom"),
        ("ginadugo ko", "bleeding", "symptom"),
        ("ginapalangluya ko", "weakness", "symptom"),
        ("ginahubag ang tiil ko", "swollen feet", "symptom"),
        ("ginahubag ang kamot ko", "swollen hands", "symptom"),
        ("ginakabudlayan ko tulog", "difficulty sleeping", "symptom"),
        ("ginatugnaw ko", "chills", "symptom"),
    ]
    for local, eng, cat in extras:
        add(local, eng, cat)

    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=["dictionary_id", "local_term", "english_term", "category"])
        w.writeheader()
        w.writerows(rows)
    return added


def merge_symptoms_conditions() -> tuple[int, int]:
    sym_path = NLP / "hiligaynon_symptoms.csv"
    cond_path = NLP / "hiligaynon_conditions.csv"
    fields = [
        "hiligaynon_term",
        "english_term",
        "medical_category",
        "severity",
        "triage_level",
        "status",
    ]

    def load(path: Path) -> list[dict]:
        if not path.is_file():
            return []
        return list(csv.DictReader(path.open(encoding="utf-8")))

    def save(path: Path, rows: list[dict]) -> None:
        with path.open("w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
            w.writeheader()
            for r in sorted(rows, key=lambda x: (x.get("hiligaynon_term") or "").lower()):
                w.writerow({k: r.get(k, "") for k in fields})

    symptoms = load(sym_path)
    conditions = load(cond_path)
    sym_keys = {(r.get("hiligaynon_term") or "").strip().lower() for r in symptoms}
    cond_keys = {(r.get("hiligaynon_term") or "").strip().lower() for r in conditions}
    added_s = added_c = 0

    cat_map = {
        "general": "General",
        "respiratory": "Respiratory",
        "digestive": "Digestive",
        "neurological": "Neurological",
        "cardiovascular": "Cardiovascular",
        "skin": "Dermatology",
        "eye_ear": "ENT",
        "musculoskeletal": "Musculoskeletal",
        "mental_health": "Mental Health",
        "urinary": "Urinary",
    }

    for _key, spec in SYMPTOM_EXPAND.items():
        eng = spec["english"]
        cat = cat_map.get(spec["category"], "General")
        for v in spec["hiligaynon"]:
            k = v.strip().lower()
            if k in sym_keys:
                continue
            symptoms.append(
                {
                    "hiligaynon_term": k,
                    "english_term": eng,
                    "medical_category": cat,
                    "severity": "Medium",
                    "triage_level": "urgent" if eng in {
                        "chest pain", "difficulty breathing", "shortness of breath",
                        "fever with chills", "bleeding",
                    } else "non_urgent",
                    "status": "active",
                }
            )
            sym_keys.add(k)
            added_s += 1

    for hil, eng, cat, sev, tri in CONDITION_EXPAND:
        k = hil.strip().lower()
        if k in cond_keys:
            continue
        conditions.append(
            {
                "hiligaynon_term": k,
                "english_term": eng,
                "medical_category": cat,
                "severity": sev,
                "triage_level": tri,
                "status": "active",
            }
        )
        cond_keys.add(k)
        added_c += 1

    save(sym_path, symptoms)
    save(cond_path, conditions)
    return added_s, added_c


def merge_misspellings() -> int:
    path = NLP / "medical_misspellings.csv"
    fields = ["correct_term", "misspelling", "term_type", "status"]
    rows = list(csv.DictReader(path.open(encoding="utf-8"))) if path.is_file() else []
    existing = {
        ((r.get("correct_term") or "").lower(), (r.get("misspelling") or "").lower())
        for r in rows
    }
    added = 0
    for correct, miss, ttype in MISSPELLINGS:
        c, m = correct.strip().lower(), miss.strip().lower()
        if not c or not m or c == m:
            continue
        key = (c, m)
        if key in existing:
            continue
        rows.append({"correct_term": c, "misspelling": m, "term_type": ttype, "status": "active"})
        existing.add(key)
        added += 1
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(rows)
    return added


def append_nlp_dataset() -> int:
    path = NLP / "hiligaynon_medical_nlp_dataset.csv"
    if not path.is_file():
        return 0
    with path.open(encoding="utf-8", newline="") as f:
        reader = csv.DictReader(f)
        fields = list(reader.fieldnames or [])
        rows = list(reader)
    if not fields:
        return 0
    existing = {(r.get("hiligaynon_term") or "").strip().lower() for r in rows}
    max_id = 0
    for r in rows:
        try:
            max_id = max(max_id, int(str(r.get("id") or "0")))
        except ValueError:
            pass
    added = 0

    def add_row(hil: str, eng: str, medical: str, category: str) -> None:
        nonlocal max_id, added
        key = hil.strip().lower()
        if not key or key in existing:
            return
        max_id += 1
        row = {k: "" for k in fields}
        row.update(
            {
                "id": str(max_id),
                "hiligaynon_term": key,
                "alternative_spellings": "",
                "english_translation": eng,
                "medical_term": medical,
                "medical_category": category,
                "body_system": category,
                "severity": "moderate",
                "symptom_keywords": eng.replace(" ", ";"),
                "confidence_keywords": key,
            }
        )
        rows.append(row)
        existing.add(key)
        added += 1

    for _key, spec in SYMPTOM_EXPAND.items():
        for v in spec["hiligaynon"]:
            add_row(v, spec["english"], spec["medical_term"], spec["category"])
    for hil, eng, cat, _sev, _tri in CONDITION_EXPAND:
        add_row(hil, eng, eng, cat.lower())

    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)
    return added


def main() -> None:
    k, v = merge_lexicon()
    d = merge_dictionary()
    s, c = merge_symptoms_conditions()
    m = merge_misspellings()
    n = append_nlp_dataset()
    lex = json.loads((NLP / "hiligaynon_symptom_lexicon.json").read_text(encoding="utf-8"))
    print("Hiligaynon triage lexicon expansion complete")
    print(f"  lexicon: +{k} keys, +{v} variants (total keys={len(lex.get('symptoms', {}))})")
    print(f"  medical_dictionary.csv: +{d}")
    print(f"  hiligaynon_symptoms.csv: +{s}")
    print(f"  hiligaynon_conditions.csv: +{c}")
    print(f"  medical_misspellings.csv: +{m}")
    print(f"  hiligaynon_medical_nlp_dataset.csv: +{n}")


if __name__ == "__main__":
    main()
