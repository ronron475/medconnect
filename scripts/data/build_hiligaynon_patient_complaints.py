#!/usr/bin/env python3
"""
Generate data/nlp/hiligaynon_patient_complaints.csv — realistic Hiligaynon telemedicine complaints.

Prioritizes natural patient language over formal medical terminology.
"""

from __future__ import annotations

import csv
import itertools
import random
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "hiligaynon_patient_complaints.csv"
TARGET_ROWS = 8_000
MIN_PER_SYMPTOM = 25
RNG = random.Random(42)

INTENSITY = ["gid", "grabe", "grabeh", "sing malala", "daw malala", "sobra", "dako gid", "amo gid"]
TIME_MARKERS = ["subong", "halin sang aga", "kagapon", "pirmi", "sige", "halin sang gabii", "karon", "since yesterday"]
OPENERS = [
    "", "daw ", "pareho ", "amuni ", "basin ", "feel ko nga ", "nagapanibugho ko kay ",
    "complain ko kay ", "sige ko ", "may ara ko nga ",
]
CLOSERS = ["", " gid", " man", " subong", " ko", " ako", " gid ko", " gid ako", " na gid"]
POSSESSIVE = ["akon", "ko", "nakon", "sang ... ko", "sa ... ko"]

SPELLING_SWAPS = [
    ("tiyan", "tyan"), ("sip-on", "sipon"), ("sip-on", "sip on"), ("sipon", "sip-on"),
    ("kakatol", "kakatul"), ("kakatul", "kakatol"), ("dughan", "dughan"), ("hilanat", "lagnat"),
    ("ginhawa", "ginhawa"), ("kalipong", "lipong"), ("panulok", "panan-awon"),
    ("dalunggan", "dulunggan"), ("dulunggan", "dalunggan"), ("lawas", "kalawasan"),
]


@dataclass
class SymptomGroup:
    normalized_symptom: str
    english_translation: str
    medical_term: str
    body_system: str
    urgency_level: str
    alternative_spellings: list[str]
    possible_conditions: list[str]
    confidence_keywords: list[str]
    seeds: list[str] = field(default_factory=list)
    templates: list[str] = field(default_factory=list)


def norm(text: str) -> str:
    return re.sub(r"\s+", " ", text.lower().strip())


def apply_spelling_variants(phrase: str) -> list[str]:
    out = {phrase}
    for old, new in SPELLING_SWAPS:
        if old in phrase:
            out.add(phrase.replace(old, new))
    return list(out)


def expand_template(template: str, symptom: str, body: str = "") -> str:
    text = template
    text = text.replace("{symptom}", symptom)
    text = text.replace("{body}", body)
    text = text.replace("{intensity}", RNG.choice(INTENSITY))
    text = text.replace("{time}", RNG.choice(TIME_MARKERS))
    text = text.replace("{opener}", RNG.choice(OPENERS))
    text = text.replace("{closer}", RNG.choice(CLOSERS))
    return re.sub(r"\s+", " ", text).strip()


def symptom_groups() -> list[SymptomGroup]:
    g: list[SymptomGroup] = []

    def add(
        normalized, english, medical, body_system, urgency,
        alts, conditions, keywords, seeds, templates,
    ):
        g.append(SymptomGroup(
            normalized_symptom=normalized,
            english_translation=english,
            medical_term=medical,
            body_system=body_system,
            urgency_level=urgency,
            alternative_spellings=alts,
            possible_conditions=conditions,
            confidence_keywords=keywords,
            seeds=seeds,
            templates=templates,
        ))

    # HEADACHE
    add(
        "sakit ulo", "headache", "cephalalgia", "nervous", "Medium",
        ["masakit ulo", "sakit sang ulo", "ulo masakit", "hapdi ulo", "sakit sa ulo", "headache"],
        ["migraine", "tension headache", "cluster headache", "sinusitis"],
        ["ulo", "headache", "pain", "head"],
        [
            "Masakit gid akon ulo subong",
            "Sakit sang ulo ko",
            "Nagasakit ulo ko halin sang aga",
            "Daw mabutho akon ulo",
            "Grabe gid sakit ulo ko",
            "Nagapulaw mata ko kag sakit ulo",
            "Masakit ulo ko gid",
            "Ulo ko masakit subong",
            "May sakit ulo ko",
            "Headache gid ko subong",
        ],
        [
            "{opener}masakit {intensity} akon ulo {time}{closer}",
            "{opener}sakit sang ulo ko {time}{closer}",
            "{opener}nagasakit ulo ko {time}{closer}",
            "{opener}daw mabutho akon ulo{closer}",
            "{opener}grabe gid sakit ulo ko{closer}",
            "{opener}masakit gid ulo ko {time}{closer}",
            "{opener}may ara ko sakit ulo {time}{closer}",
            "{opener}hilo ko kag sakit ulo{closer}",
            "{opener}pirmi sakit ulo ko{closer}",
            "{opener}ind gid masarangan ulo ko{closer}",
            "{opener}nagapulaw mata ko kag sakit ulo{closer}",
            "{opener}pareho may pressure sa ulo ko{closer}",
            "{opener}grabe headache ko{closer}",
            "{opener}masakit ulo ko halin sang gabii{closer}",
            "{opener}sakit ulo gid ko subong{closer}",
        ],
    )

    # RESPIRATORY
    add(
        "difficulty breathing", "shortness of breath", "dyspnea", "respiratory", "High",
        ["ginakapos ginhawa", "gutok", "budlay ginhawa", "ginakapos", "lisod magginhawa", "hingal"],
        ["asthma", "COPD", "pneumonia", "anxiety", "heart failure"],
        ["breathing", "lungs", "dyspnea", "respiratory"],
        [
            "Budlay gid akon ginhawa",
            "Ginakapos ko ginhawa",
            "Daw indi ko ka ginhawa tarong",
            "Ginahingal ko pirmi",
            "Ga utok-utok akon dughan",
            "Daw indi ko ka ginhawa",
            "Gapus gid ko ginhawa",
            "Shortness of breath gid ko",
        ],
        [
            "{opener}budlay {intensity} akon ginhawa{closer}",
            "{opener}ginakapos ko ginhawa {time}{closer}",
            "{opener}daw indi ko ka ginhawa tarong{closer}",
            "{opener}ginahingal ko {time}{closer}",
            "{opener}ga utok-utok akon dughan{closer}",
            "{opener}lisod magginhawa ko {time}{closer}",
            "{opener}gutok gid ko {time}{closer}",
            "{opener}daw ginapigos ko ginhawa{closer}",
            "{opener}indi ko makaginhawa maayo{closer}",
            "{opener}grabe hingal ko{closer}",
            "{opener}budlay ginhawa ko subong{closer}",
            "{opener}may ubo ko kag budlay ginhawa{closer}",
            "{opener}daw may tightness dughan kag indi ko ginhawa{closer}",
            "{opener}ginakapos gid ko halin sang aga{closer}",
            "{opener}hard to breathe gid ko{closer}",
        ],
    )
    add(
        "ubo", "cough", "cough", "respiratory", "Low",
        ["ginauubo", "nagaubo", "ubuhan", "ubo-ubo", "cough"],
        ["URTI", "bronchitis", "pneumonia", "asthma", "GERD"],
        ["cough", "ubo", "respiratory"],
        ["May ubo ko kag sip-on", "Ubo gid ko", "Sige ko ubo", "Dry cough ko", "May ubo ko sing dugo"],
        [
            "{opener}may ubo ko {time}{closer}",
            "{opener}ubo {intensity} ko{closer}",
            "{opener}sige ko ubo {time}{closer}",
            "{opener}ginauubo ko pirmi{closer}",
            "{opener}nagaubo ko sing malala{closer}",
            "{opener}ubo kag sipon ko{closer}",
            "{opener}may cough ko{closer}",
            "{opener}ubo gid ko halin sang aga{closer}",
            "{opener}daw indi maubuhan ko{closer}",
            "{opener}grabe ubo ko subong{closer}",
        ],
    )
    add(
        "sip-on", "runny nose", "rhinitis", "respiratory", "Low",
        ["sipon", "sip on", "barado ilong", "ginasipon", "congested nose"],
        ["common cold", "allergic rhinitis", "sinusitis"],
        ["nose", "sipon", "congestion", "rhinitis"],
        ["May sip-on ko", "Barado ilong ko", "Sipon gid ko", "Runny nose ko"],
        [
            "{opener}may sip-on ko {time}{closer}",
            "{opener}barado ilong ko{closer}",
            "{opener}sipon gid ko{closer}",
            "{opener}ginasipon ko{closer}",
            "{opener}may sipon kag ubo ko{closer}",
            "{opener}daw barado gid ilong ko{closer}",
            "{opener}congested ako{closer}",
            "{opener}sip on gid ko{closer}",
        ],
    )

    # DIGESTIVE
    add(
        "sakit tiyan", "stomach pain", "abdominal pain", "digestive", "Medium",
        ["masakit tiyan", "sakit tyan", "sakit sikmura", "stomach pain", "tiyan masakit"],
        ["gastritis", "GERD", "appendicitis", "IBS", "food poisoning"],
        ["stomach", "abdomen", "tiyan", "pain"],
        [
            "Masakit akon tiyan",
            "Kalibanga gid ko",
            "Sige ko suka",
            "Kasukaon gid ko",
            "Wala ko gana magkaon",
            "Daw ginapanuhot ko",
            "Masakit gid tiyan ko subong",
        ],
        [
            "{opener}masakit akon tiyan {time}{closer}",
            "{opener}sakit tiyan ko {intensity}{closer}",
            "{opener}grabe gid sakit tiyan ko{closer}",
            "{opener}daw ginapigos tiyan ko{closer}",
            "{opener}masakit tyan ko subong{closer}",
            "{opener}stomach pain gid ko{closer}",
            "{opener}may ara ko sakit tiyan{closer}",
            "{opener}tiyan ko masakit gid{closer}",
            "{opener}hapdi gid tiyan ko{closer}",
            "{opener}daw may cramps tiyan ko{closer}",
        ],
    )
    add(
        "kalibanga", "diarrhea", "diarrhea", "digestive", "Medium",
        ["gakalibanga", "lbm", "tulo-tulo", "loose stool", "ginatulo"],
        ["gastroenteritis", "food poisoning", "IBS", "infection"],
        ["diarrhea", "stool", "kalibanga", "gi"],
        ["Kalibanga gid ko", "Sige ko kalibanga", "LBM gid ko", "Tulo-tulo gid ko"],
        [
            "{opener}kalibanga {intensity} ko {time}{closer}",
            "{opener}sige ko kalibanga{closer}",
            "{opener}lbm gid ko{closer}",
            "{opener}ginatulo gid ko{closer}",
            "{opener}daw indi mauntat kalibanga ko{closer}",
            "{opener}grabe diarrhea ko{closer}",
            "{opener}tulo-tulo gid ko subong{closer}",
        ],
    )
    add(
        "suka", "vomiting", "vomiting", "digestive", "Medium",
        ["nagsuka", "ginasuka", "sumuka", "vomiting", "nagsusuka"],
        ["gastroenteritis", "migraine", "pregnancy", "food poisoning"],
        ["vomit", "suka", "nausea", "gi"],
        ["Sige ko suka", "Nagsuka gid ko", "Suka gid ko", "Vomiting gid ko"],
        [
            "{opener}sige ko suka {time}{closer}",
            "{opener}nagsuka gid ko{closer}",
            "{opener}grabe suka ko{closer}",
            "{opener}daw indi mauntat suka ko{closer}",
            "{opener}kasukaon kag suka gid ko{closer}",
            "{opener}vomiting gid ko subong{closer}",
        ],
    )
    add(
        "kasukaon", "nausea", "nausea", "digestive", "Low",
        ["ginakasukaon", "nahihilo", "nausea", "daw mahilo ko"],
        ["gastroenteritis", "migraine", "pregnancy", "vertigo"],
        ["nausea", "kasukaon", "hilo", "gi"],
        ["Kasukaon gid ko", "Daw mahilo ko", "Nahihilo gid ko", "Nausea gid ko"],
        [
            "{opener}kasukaon {intensity} ko{closer}",
            "{opener}daw mahilo gid ko{closer}",
            "{opener}ginakasukaon ko pirmi{closer}",
            "{opener}feel ko nga kasukaon ko{closer}",
            "{opener}nausea gid ko subong{closer}",
        ],
    )
    add(
        "wala gana kaon", "loss of appetite", "anorexia", "digestive", "Low",
        ["dili gana kaon", "wala gana", "loss of appetite", "dili ko gusto magkaon"],
        ["infection", "depression", "gastritis", "cancer"],
        ["appetite", "eat", "gana", "anorexia"],
        ["Wala ko gana magkaon", "Dili ko gana magkaon", "Wala gid gana ko magkaon"],
        [
            "{opener}wala ko gana magkaon {time}{closer}",
            "{opener}dili ko gusto magkaon{closer}",
            "{opener}wala gid gana ko{closer}",
            "{opener}daw indi ko makaon maayo{closer}",
            "{opener}loss of appetite gid ko{closer}",
        ],
    )
    add(
        "panuhot", "bloating", "abdominal distension", "digestive", "Low",
        ["ginapanuhot", "bloated", "busog tiyan", "daw ginapanuhot ko"],
        ["IBS", "gastritis", "constipation", "food intolerance"],
        ["bloat", "gas", "panuhot", "stomach"],
        ["Daw ginapanuhot ko", "Busog gid tiyan ko", "Bloated gid ko"],
        [
            "{opener}daw ginapanuhot ko {time}{closer}",
            "{opener}panuhot gid tiyan ko{closer}",
            "{opener}bloated gid ko{closer}",
            "{opener}daw may gas tiyan ko{closer}",
        ],
    )

    # CHEST PAIN
    add(
        "sakit dughan", "chest pain", "chest pain", "cardiovascular", "Critical",
        ["masakit dughan", "sakit sang dughan", "hapdi dughan", "chest pain", "masakit dibdib"],
        ["angina", "myocardial infarction", "GERD", "anxiety", "costochondritis"],
        ["chest", "heart", "dughan", "pain"],
        [
            "Masakit akon dughan",
            "May ginabatyag ko nga sakit sa dughan",
            "Daw ginapigos akon dughan",
            "Gakurot akon dughan",
            "Kusog tibok sang tagipusuon ko",
            "Gapigos gid dughan ko",
        ],
        [
            "{opener}masakit akon dughan {time}{closer}",
            "{opener}may ginabatyag ko nga sakit sa dughan{closer}",
            "{opener}daw ginapigos akon dughan{closer}",
            "{opener}gakurot akon dughan {intensity}{closer}",
            "{opener}kusog tibok sang tagipusuon ko{closer}",
            "{opener}gapigos gid dughan ko{closer}",
            "{opener}chest pain gid ko subong{closer}",
            "{opener}grabe sakit dughan ko{closer}",
            "{opener}daw may pressure sa dughan ko{closer}",
            "{opener}masakit dibdib ko {time}{closer}",
            "{opener}may ara ko chest pain{closer}",
            "{opener}daw ginapunaw ko tungod dughan{closer}",
        ],
    )

    # SKIN
    add(
        "kakatol lawas", "body itchiness", "pruritus", "integumentary", "Low",
        ["kakatul lawas", "katol sa lawas", "ga katol lawas", "kumakati lawas", "itchy body"],
        ["allergic reaction", "eczema", "scabies", "dry skin", "urticaria"],
        ["itch", "skin", "kakatol", "pruritus"],
        [
            "Kakatol akon lawas",
            "Kakatul gid ko",
            "May butlig ko",
            "May bugas-bugas ko",
            "Naga pula akon panit",
            "May hubag ko",
            "Kakatul lawas ko",
        ],
        [
            "{opener}kakatol akon lawas {time}{closer}",
            "{opener}kakatul {intensity} ko{closer}",
            "{opener}may butlig ko{closer}",
            "{opener}may bugas-bugas ko{closer}",
            "{opener}naga pula akon panit{closer}",
            "{opener}may hubag ko{closer}",
            "{opener}ga katol gid lawas ko{closer}",
            "{opener}daw indi mauntat kakatol ko{closer}",
            "{opener}itchy gid lawas ko{closer}",
            "{opener}kumakati gid lawas ko{closer}",
            "{opener}may rashes ko{closer}",
            "{opener}grabe kakatul ko subong{closer}",
        ],
    )

    # HAIR
    add(
        "galagas buhok", "hair loss", "alopecia", "integumentary", "Low",
        ["nagahulog buhok", "numipis buhok", "falling hair", "hair loss", "kalbo"],
        ["telogen effluvium", "alopecia areata", "androgenic alopecia", "stress", "nutritional deficiency"],
        ["hair", "buhok", "alopecia", "loss"],
        [
            "Galagas buhok ko",
            "Nagahulog buhok ko",
            "Numipis buhok ko",
            "Damo gid hulog buhok ko",
            "Daw makalbo na ko",
            "Hair fall gid ko",
        ],
        [
            "{opener}galagas buhok ko {time}{closer}",
            "{opener}nagahulog buhok ko {intensity}{closer}",
            "{opener}numipis buhok ko{closer}",
            "{opener}damo gid hulog buhok ko{closer}",
            "{opener}daw makalbo na ko{closer}",
            "{opener}hair fall gid ko{closer}",
            "{opener}daw nagakalas buhok ko{closer}",
            "{opener}grabe hair loss ko{closer}",
            "{opener}upod na buhok ko{closer}",
        ],
    )

    # URINARY
    add(
        "masakit mag ihi", "painful urination", "dysuria", "urinary", "Medium",
        ["sakit mag ihi", "hapdi mag ihi", "dysuria", "masakit mag-ihi", "burning ihi"],
        ["UTI", "urethritis", "kidney stone", "STI"],
        ["urine", "ihi", "pain", "uti"],
        [
            "Masakit mag ihi",
            "Sige ko ihi",
            "May dugo sa ihi ko",
            "Budlay ko ihi",
            "Daw may hapdi mag ihi",
            "Masakit gid mag ihi ko",
        ],
        [
            "{opener}masakit mag ihi {time}{closer}",
            "{opener}sige ko ihi {time}{closer}",
            "{opener}may dugo sa ihi ko{closer}",
            "{opener}budlay ko ihi{closer}",
            "{opener}daw may hapdi mag ihi{closer}",
            "{opener}grabe sakit mag ihi ko{closer}",
            "{opener}daw may burning pag ihi ko{closer}",
            "{opener}frequent urination gid ko{closer}",
            "{opener}dili ko maayo ihi{closer}",
            "{opener}UTI daw ko{closer}",
        ],
    )
    add(
        "may dugo sa ihi", "bloody urine", "hematuria", "urinary", "High",
        ["dugo sa ihi", "bloody urine", "ihing may dugo"],
        ["UTI", "kidney stone", "bladder cancer", "glomerulonephritis"],
        ["blood", "urine", "ihi", "hematuria"],
        ["May dugo sa ihi ko", "Dugo sa ihi ko", "Bloody urine ko"],
        [
            "{opener}may dugo sa ihi ko {time}{closer}",
            "{opener}dugo gid sa ihi ko{closer}",
            "{opener}daw may blood pag ihi ko{closer}",
            "{opener}grabe dugo sa ihi ko{closer}",
        ],
    )

    # MUSCULOSKELETAL
    add(
        "masakit likod", "back pain", "back pain", "musculoskeletal", "Medium",
        ["sakit likod", "likod masakit", "back pain", "lower back pain"],
        ["muscle strain", "herniated disc", "kidney stone", "osteoporosis"],
        ["back", "likod", "pain", "spine"],
        ["Masakit likod ko", "Sakit likod ko gid", "Back pain gid ko"],
        [
            "{opener}masakit likod ko {time}{closer}",
            "{opener}sakit likod ko {intensity}{closer}",
            "{opener}back pain gid ko{closer}",
            "{opener}daw indi ko makaatubang tungod likod{closer}",
            "{opener}grabe sakit likod ko{closer}",
            "{opener}lower back pain gid ko{closer}",
        ],
    )
    add(
        "masakit tuhod", "knee pain", "knee pain", "musculoskeletal", "Medium",
        ["sakit tuhod", "tuhod masakit", "knee pain"],
        ["osteoarthritis", "ligament injury", "meniscus tear", "gout"],
        ["knee", "tuhod", "pain", "joint"],
        ["Masakit tuhod ko", "Sakit tuhod ko gid", "Knee pain gid ko"],
        [
            "{opener}masakit tuhod ko {time}{closer}",
            "{opener}sakit tuhod ko {intensity}{closer}",
            "{opener}knee pain gid ko{closer}",
            "{opener}daw indi ko makalakat tungod tuhod{closer}",
        ],
    )
    add(
        "luya lawas", "body weakness", "asthenia", "musculoskeletal", "Medium",
        ["mahina lawas", "ginluya lawas", "weak body", "luya gid lawas"],
        ["anemia", "infection", "depression", "chronic fatigue"],
        ["weak", "luya", "lawas", "strength"],
        ["Luya lawas ko", "Mahina lawas ko", "Luya gid lawas ko"],
        [
            "{opener}luya lawas ko {time}{closer}",
            "{opener}mahina lawas ko {intensity}{closer}",
            "{opener}daw indi ko kabakod lakat{closer}",
            "{opener}weak gid lawas ko{closer}",
            "{opener}luya gid lawas ko subong{closer}",
        ],
    )
    add(
        "kapoy", "fatigue", "fatigue", "general", "Low",
        ["kapoy gid", "ginakapoy", "pagod", "tired", "exhausted"],
        ["anemia", "infection", "depression", "sleep deprivation", "diabetes"],
        ["tired", "fatigue", "kapoy", "weak"],
        ["Kapoy gid ko", "Pagod gid ko", "Tired gid ko subong"],
        [
            "{opener}kapoy {intensity} ko {time}{closer}",
            "{opener}pagod gid ko{closer}",
            "{opener}daw wala ako energy{closer}",
            "{opener}tired gid ko subong{closer}",
            "{opener}ginakapoy gid ko{closer}",
            "{opener}masakit kalawasan ko kag kapoy gid ko{closer}",
        ],
    )
    add(
        "masakit kalawasan", "body pain", "myalgia", "musculoskeletal", "Medium",
        ["sakit lawas", "masakit lawas", "body aches", "body pain"],
        ["viral infection", "flu", "fibromyalgia", "overexertion"],
        ["body", "lawas", "pain", "muscle"],
        ["Masakit kalawasan ko", "Sakit lawas ko gid", "Body pain gid ko"],
        [
            "{opener}masakit kalawasan ko {time}{closer}",
            "{opener}sakit lawas ko {intensity}{closer}",
            "{opener}body pain gid ko{closer}",
            "{opener}daw masakit bilog lawas ko{closer}",
        ],
    )

    # NEUROLOGICAL
    add(
        "kalipong", "dizziness", "dizziness", "nervous", "Medium",
        ["lipong", "nalipong", "hilo", "vertigo", "dizzy"],
        ["vertigo", "hypotension", "anemia", "inner ear disorder", "migraine"],
        ["dizzy", "kalipong", "vertigo", "balance"],
        [
            "Kalipong gid ko",
            "Lipong ko pirmi",
            "Pamamanhid akon kamot",
            "Nagakurog akon kamot",
            "Daw matumba ko",
            "Kalipong gid ko subong",
        ],
        [
            "{opener}kalipong {intensity} ko {time}{closer}",
            "{opener}lipong ko pirmi{closer}",
            "{opener}daw matumba ko{closer}",
            "{opener}daw mahilo gid ko{closer}",
            "{opener}dizzy gid ko subong{closer}",
            "{opener}grabe kalipong ko{closer}",
            "{opener}kalipong gid ko kag maluya{closer}",
        ],
    )
    add(
        "pamamanhid", "numbness", "paresthesia", "nervous", "Medium",
        ["manhid", "ginamanhid", "numb", "walay pagbati"],
        ["stroke", "diabetes", "carpal tunnel", "nerve compression"],
        ["numb", "tingling", "manhid", "nerve"],
        ["Pamamanhid akon kamot", "Mani hid kamot ko", "Numb kamot ko"],
        [
            "{opener}pamamanhid akon kamot {time}{closer}",
            "{opener}manhid gid kamot ko{closer}",
            "{opener}daw wala pagbati kamot ko{closer}",
            "{opener}numb gid tiil ko{closer}",
            "{opener}pamamanhid lawas ko{closer}",
        ],
    )
    add(
        "nagakurog", "tremor", "tremor", "nervous", "Medium",
        ["kurog", "ginakurog", "shaking", "nagauyog"],
        ["parkinsonism", "anxiety", "hyperthyroidism", "essential tremor"],
        ["tremor", "shaking", "kurog", "nerve"],
        ["Nagakurog akon kamot", "Kurog gid kamot ko", "Shaking gid ko"],
        [
            "{opener}nagakurog akon kamot {time}{closer}",
            "{opener}kurog gid kamot ko{closer}",
            "{opener}daw nagauyog kamot ko{closer}",
            "{opener}shaking gid ko{closer}",
        ],
    )

    # MENTAL HEALTH
    add(
        "indi katulog", "insomnia", "insomnia", "mental", "Medium",
        ["dili makatulog", "dili ko katulog", "insomnia", "cannot sleep"],
        ["insomnia", "anxiety", "depression", "sleep apnea"],
        ["sleep", "insomnia", "katulog", "rest"],
        [
            "Indi ko katulog",
            "Kabalaka gid ko",
            "Stress gid ko",
            "Pirmi ko paminsar",
            "Daw naluya gid akon pamatyag",
            "Pirme ko ginakulbaan",
        ],
        [
            "{opener}indi ko katulog {time}{closer}",
            "{opener}dili makatulog ko{closer}",
            "{opener}insomnia gid ko{closer}",
            "{opener}daw indi ko makatulog maayo{closer}",
            "{opener}pirmi ko awake{closer}",
        ],
    )
    add(
        "kabalaka", "anxiety", "anxiety", "mental", "Medium",
        ["ginakabalaka", "worry", "kulba", "ginakulbaan", "anxious"],
        ["generalized anxiety", "panic disorder", "stress", "depression"],
        ["anxiety", "worry", "kabalaka", "stress"],
        ["Kabalaka gid ko", "Pirme ko ginakulbaan", "Anxious gid ko"],
        [
            "{opener}kabalaka {intensity} ko {time}{closer}",
            "{opener}pirme ko ginakulbaan{closer}",
            "{opener}anxious gid ko{closer}",
            "{opener}daw stressed gid ko{closer}",
            "{opener}grabe worry ko{closer}",
        ],
    )
    add(
        "stress", "stress", "stress", "mental", "Medium",
        ["ginastress", "stressed", "sobra stress", "overwhelmed"],
        ["burnout", "anxiety", "depression", "adjustment disorder"],
        ["stress", "mental", "burnout", "overwhelmed"],
        ["Stress gid ko", "Sobra stress ko", "Stressed gid ko"],
        [
            "{opener}stress {intensity} ko{closer}",
            "{opener}sobra stress ko{closer}",
            "{opener}stressed gid ko subong{closer}",
            "{opener}daw overwhelmed ko{closer}",
        ],
    )
    add(
        "depresyon", "depression", "depression", "mental", "High",
        ["depression", "maluoy", "sad", "ginadepresyon"],
        ["major depression", "adjustment disorder", "bipolar disorder"],
        ["depression", "sad", "mental", "mood"],
        ["Depresyon gid ko", "Maluoy gid ko", "Depressed gid ko"],
        [
            "{opener}depresyon {intensity} ko{closer}",
            "{opener}daw naluya gid akon pamatyag{closer}",
            "{opener}depressed gid ko{closer}",
            "{opener}maluoy gid ko subong{closer}",
        ],
    )

    # EYES
    add(
        "pula mata", "red eyes", "conjunctival injection", "sensory", "Low",
        ["nagapula mata", "mapula mata", "red eyes", "pamula mata"],
        ["conjunctivitis", "allergy", "dry eye", "uveitis"],
        ["eye", "red", "mata", "conjunctivitis"],
        [
            "Pula akon mata",
            "Nagaluha mata ko",
            "Kakatol akon mata",
            "Malain panulok ko",
            "Daw malab-ong akon panulok",
        ],
        [
            "{opener}pula akon mata {time}{closer}",
            "{opener}nagaluha mata ko{closer}",
            "{opener}kakatol akon mata{closer}",
            "{opener}malain panulok ko{closer}",
            "{opener}daw malab-ong akon panulok{closer}",
            "{opener}red eyes gid ko{closer}",
            "{opener}blurry vision gid ko{closer}",
        ],
    )

    # EARS
    add(
        "masakit dalunggan", "ear pain", "otalgia", "sensory", "Medium",
        ["sakit dalunggan", "ear pain", "hapdi dalunggan", "masakit dulunggan"],
        ["otitis media", "otitis externa", "ear infection", "TMJ"],
        ["ear", "dalunggan", "pain", "otalgia"],
        [
            "Masakit dalunggan ko",
            "May tingog akon dalunggan",
            "Daw indi ko kabati maayo",
            "Nabugto akon dulunggan",
        ],
        [
            "{opener}masakit dalunggan ko {time}{closer}",
            "{opener}may tingog akon dalunggan{closer}",
            "{opener}daw indi ko kabati maayo{closer}",
            "{opener}nabugto akon dulunggan{closer}",
            "{opener}ringing ears gid ko{closer}",
            "{opener}tinnitus gid ko{closer}",
            "{opener}ear pain gid ko{closer}",
        ],
    )

    # FEVER
    add(
        "hilanat", "fever", "fever", "general", "Medium",
        ["lagnat", "ginahilantan", "mainit lawas", "init lawas", "may fever"],
        ["viral infection", "UTI", "dengue", "COVID-19", "typhoid"],
        ["fever", "temperature", "hilanat", "infection"],
        [
            "Ginahilantan ko",
            "Mainit lawas ko",
            "May hilanat ko",
            "Nagapanugnaw ko kag ginahilantan",
            "May fever ko",
        ],
        [
            "{opener}ginahilantan ko {time}{closer}",
            "{opener}mainit lawas ko {intensity}{closer}",
            "{opener}may hilanat ko{closer}",
            "{opener}nagapanugnaw ko kag ginahilantan{closer}",
            "{opener}may fever ko subong{closer}",
            "{opener}daw mainit gid lawas ko{closer}",
            "{opener}lagnat gid ko{closer}",
        ],
    )

    # EMERGENCY
    add(
        "severe dyspnea", "severe shortness of breath", "acute dyspnea", "respiratory", "Critical",
        ["dili makaginhawa", "ginakapos sing malala", "cannot breathe"],
        ["asthma attack", "pulmonary embolism", "anaphylaxis", "pneumonia"],
        ["breathing", "emergency", "dyspnea", "critical"],
        ["Daw indi ko ka ginhawa", "Gapigos gid dughan ko", "Dili ko makaginhawa"],
        [
            "{opener}daw indi ko ka ginhawa{closer}",
            "{opener}gapigos gid dughan ko{closer}",
            "{opener}dili ko makaginhawa {intensity}{closer}",
            "{opener}grabe indi ko ginhawa{closer}",
            "{opener}emergency daw indi ko ginhawa{closer}",
        ],
    )
    add(
        "loss of consciousness", "unconsciousness", "syncope", "multi-system", "Critical",
        ["nawala malay", "nadulaan malay", "nagapunaw", "fainting", "nawala panimuot"],
        ["syncope", "seizure", "stroke", "hypoglycemia", "cardiac arrest"],
        ["unconscious", "faint", "malay", "emergency"],
        ["Nadulaan ko malay", "Nawala ko malay", "Nagapunaw ko", "Fainted ko"],
        [
            "{opener}nadulaan ko malay {time}{closer}",
            "{opener}nawala ko malay{closer}",
            "{opener}nagapunaw ko{closer}",
            "{opener}daw nadulaan ko malay{closer}",
            "{opener}fainted gid ko{closer}",
        ],
    )
    add(
        "kombulsyon", "seizure", "seizure", "nervous", "Critical",
        ["konvulsion", "convulsion", "nagakonvulsion", "seizure"],
        ["epilepsy", "febrile seizure", "stroke", "hypoglycemia"],
        ["seizure", "convulsion", "emergency", "kombulsyon"],
        ["May kombulsyon ko", "Nagakonvulsion ko", "Seizure ko"],
        [
            "{opener}may kombulsyon ko {time}{closer}",
            "{opener}nagakonvulsion ko{closer}",
            "{opener}seizure gid ko{closer}",
            "{opener}grabe convulsion ko{closer}",
        ],
    )
    add(
        "severe bleeding", "severe bleeding", "hemorrhage", "multi-system", "Critical",
        ["dugo sing malala", "grabe nga dugo", "nagabulos dugo"],
        ["trauma", "GI bleed", "postoperative bleed", "obstetric hemorrhage"],
        ["bleeding", "blood", "emergency", "hemorrhage"],
        ["May grabe nga dugo nga naga gwa", "Grabe dugo nga naga gwa", "Dugo sing malala"],
        [
            "{opener}may grabe nga dugo nga naga gwa{closer}",
            "{opener}dugo sing malala{closer}",
            "{opener}grabe bleeding ko{closer}",
            "{opener}daw indi mauntat dugo{closer}",
        ],
    )
    add(
        "stroke symptoms", "stroke symptoms", "stroke", "nervous", "Critical",
        ["pamamanhid sa lawas", "dili makabaton", "weakness one side", "facial droop"],
        ["ischemic stroke", "hemorrhagic stroke", "TIA"],
        ["stroke", "paralysis", "emergency", "weakness"],
        ["Daw naluya isa ka bahin sang lawas ko", "Dili ko mabaton maayo", "Stroke daw ko"],
        [
            "{opener}daw naluya isa ka bahin sang lawas ko{closer}",
            "{opener}dili ko mabaton maayo{closer}",
            "{opener}daw stroke ko{closer}",
            "{opener}daw indi ko makabaton sa isa ka kamot{closer}",
            "{opener}daw nagaluya isa ka bahin lawas ko{closer}",
        ],
    )

    return g


def generate_complaints(group: SymptomGroup) -> list[str]:
    complaints: set[str] = set()

    for seed in group.seeds:
        complaints.add(seed)
        complaints.update(apply_spelling_variants(seed))

    symptom = group.normalized_symptom
    for _ in range(MIN_PER_SYMPTOM * 2):
        for template in group.templates:
            complaint = expand_template(template, symptom)
            if 8 <= len(complaint) <= 95:
                complaints.add(complaint)
                complaints.update(apply_spelling_variants(complaint))

    # Mixed English-Hiligaynon shortcuts
    eng = group.english_translation
    for suffix in [" gid ko", " ko subong", " gid ko subong", " ko since yesterday", " gid"]:
        complaints.add(f"May {eng} ko{suffix}".strip())
        complaints.add(f"{eng.capitalize()} gid ko{suffix}".strip())

    # Shorthand / incomplete sentences
    for alt in group.alternative_spellings[:6]:
        for sfx in [" gid", " ko", " gid ko", " subong"]:
            complaints.add(f"{alt}{sfx}".strip())

    return sorted(complaints, key=len)


def build_rows() -> list[dict[str, str]]:
    rows: dict[str, dict[str, str]] = {}
    groups = symptom_groups()

    for group in groups:
        alt_str = ";".join(group.alternative_spellings)
        cond_str = ";".join(group.possible_conditions)
        kw_str = ";".join(group.confidence_keywords)

        for complaint in generate_complaints(group):
            key = norm(complaint)
            if not key or key in rows:
                continue
            rows[key] = {
                "patient_complaint_hiligaynon": complaint,
                "normalized_symptom": group.normalized_symptom,
                "english_translation": group.english_translation,
                "medical_term": group.medical_term,
                "body_system": group.body_system,
                "urgency_level": group.urgency_level,
                "alternative_spellings": alt_str,
                "possible_conditions": cond_str,
                "confidence_keywords": kw_str,
            }

    # Expand to TARGET_ROWS with conversational compounds
    base = list(rows.values())
    compound_patterns = [
        "subong {c}",
        "halin sang aga {c}",
        "kag may fever ko pa gid, {c}",
        "daw grabe na, {c}",
        "doctor, {c}",
        "help, {c}",
        "pirmi ko {c}",
        "bago lang ko {c}",
        "{c} kag indi ko katulog",
        "{c} tapos kapoy gid ko",
    ]
    idx = 0
    while len(rows) < TARGET_ROWS and base:
        src = base[idx % len(base)]
        c = src["patient_complaint_hiligaynon"]
        for pat in compound_patterns:
            text = pat.format(c=c).strip()
            key = norm(text)
            if key in rows or len(text) > 110:
                continue
            rows[key] = {**src, "patient_complaint_hiligaynon": text}
            if len(rows) >= TARGET_ROWS:
                break
        idx += 1
        if idx > len(base) * len(compound_patterns) * 2:
            break

    result = list(rows.values())
    result.sort(key=lambda r: (r["body_system"], r["normalized_symptom"], norm(r["patient_complaint_hiligaynon"])))
    for i, row in enumerate(result, start=1):
        row["id"] = str(i)
    return result


def main() -> None:
    rows = build_rows()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "id",
        "patient_complaint_hiligaynon",
        "normalized_symptom",
        "english_translation",
        "medical_term",
        "body_system",
        "urgency_level",
        "alternative_spellings",
        "possible_conditions",
        "confidence_keywords",
    ]
    with OUT.open("w", newline="", encoding="utf-8") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)

    systems: dict[str, int] = {}
    for row in rows:
        systems[row["body_system"]] = systems.get(row["body_system"], 0) + 1

    print(f"Wrote {len(rows):,} patient complaints to {OUT}")
    for system, count in sorted(systems.items(), key=lambda x: -x[1]):
        print(f"  {system}: {count:,}")

    index = {norm(r["patient_complaint_hiligaynon"]): r for r in rows}
    print("\nSample complaint checks:")
    for phrase in [
        "Masakit gid akon ulo subong",
        "Budlay gid akon ginhawa",
        "Galagas buhok ko",
        "Kakatol akon lawas",
        "Kalipong gid ko",
    ]:
        hit = index.get(norm(phrase))
        print(f"  {phrase}: {'OK -> ' + hit['english_translation'] if hit else 'MISSING'}")


if __name__ == "__main__":
    main()
