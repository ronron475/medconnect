#!/usr/bin/env python3
"""
Generate Hiligaynon medical training CSV batches.

Schema: local_term,english_translation,category,medical_keyword,severity,body_system
Default: 10,000 unique rows per batch -> data/nlp/hiligaynon_medical_training_batch_NN.csv
"""

from __future__ import annotations

import argparse
import csv
import itertools
import random
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "data" / "nlp"
DEFAULT_TARGET = 10_000
RNG = random.Random(2026)

INTENSITY = ["gid", "grabe", "grabeh", "sobra", "sing malala", "daw malala", "malala gid", "dako gid"]
TIME = ["subong", "karon", "halin sang aga", "kagapon", "pirmi", "sige", "halin sang gabii", "since kagapon"]
OPENERS = [
    "", "daw ", "pareho ", "amuni ", "basin ", "feel ko nga ", "complain ko kay ",
    "sige ko ", "may ara ko nga ", "dok, ", "doc, ", "doktor, ",
]
CLOSERS = ["", " gid", " man", " subong", " ko", " ako", " gid ko", " gid ako", " na gid", " bah"]
TAGALOG_MIX = [" din", " po", " po gid", " talaga", " na po", " nga po"]
ENGLISH_MIX = [" gid", " man", " na", " talaga"]

SPELLING = [
    ("tiyan", "tyan"), ("tiyan", "tian"), ("sip-on", "sipon"), ("sip-on", "sip on"),
    ("dughan", "duhan"), ("dalunggan", "dulunggan"), ("hilanat", "lagnat"),
    ("kakatol", "kakatul"), ("ginhawa", "ginhawa"), ("kalipong", "lipong"),
    ("kalibanga", "kalibanga"), ("masakit", "masaket"), ("sakit", "saket"),
    ("lingin", "linggin"), ("nahilo", "nahilu"), ("ubo", "ubuhon"),
]

TYPO = [
    ("masakit", "masakti"), ("sakit", "skit"), ("ginhawa", "ginhwa"),
    ("kalibanga", "kalibangga"), ("hilanat", "hilanat"), ("dughan", "dughn"),
]


@dataclass
class Concept:
    english: str
    medical_keyword: str
    category: str
    severity: str
    body_system: str
    roots: list[str] = field(default_factory=list)
    seeds: list[str] = field(default_factory=list)
    templates: list[str] = field(default_factory=list)


def norm(t: str) -> str:
    return re.sub(r"\s+", " ", t.lower().strip())


def spelling_variants(phrase: str) -> set[str]:
    out = {phrase}
    for a, b in SPELLING:
        if a in phrase:
            out.add(phrase.replace(a, b))
    for a, b in TYPO:
        if a in phrase:
            out.add(phrase.replace(a, b))
    out.add(phrase.replace("-", " "))
    out.add(phrase.replace("-", ""))
    if phrase.startswith("ga ") and "gin" not in phrase:
        out.add("gina " + phrase[3:])
        out.add("naga" + phrase[2:])
    if not phrase.startswith(("gin", "nag", "may ", "dok")):
        if " " in phrase:
            out.add("gin" + phrase)
            out.add("nag" + phrase)
        out.add("may " + phrase)
    return {re.sub(r"\s+", " ", x).strip() for x in out if 2 <= len(x.strip()) <= 120}


def expand_template(tpl: str, root: str, en: str) -> str:
    text = tpl
    text = text.replace("{root}", root)
    text = text.replace("{en}", en.lower())
    text = text.replace("{intensity}", RNG.choice(INTENSITY))
    text = text.replace("{time}", RNG.choice(TIME))
    text = text.replace("{opener}", RNG.choice(OPENERS))
    text = text.replace("{closer}", RNG.choice(CLOSERS))
    text = text.replace("{tag}", RNG.choice(TAGALOG_MIX))
    text = text.replace("{eng}", RNG.choice(ENGLISH_MIX))
    return re.sub(r"\s+", " ", text).strip()


def concepts() -> list[Concept]:
    c: list[Concept] = []

    def add(en, kw, cat, sev, sys, roots, seeds=None, templates=None):
        c.append(Concept(en, kw, cat, sev, sys, roots, seeds or [], templates or []))

    # HEADACHE
    add("headache", "headache", "symptom", "moderate", "neurological",
        ["sakit ulo", "sakit sang ulo", "masakit ulo", "masakit akon ulo", "ga sakit ulo ko",
         "nagasakit ulo", "hapdi ulo", "kirot ulo", "panakit ulo", "ulo masakit", "sakit sa ulo"],
        ["Masakit gid akon ulo subong", "Grabe sakit sang ulo ko", "Pirme sakit ulo ko",
         "Dok, sakit gid ulo ko subong.", "Daw ga linog akon ulo.", "Headache gid ko"],
        ["{opener}masakit {intensity} akon ulo {time}{closer}",
         "{opener}sakit sang ulo ko {time}{closer}",
         "{opener}ga sakit ulo ko {time}{closer}",
         "{opener}grabe sakit sang ulo ko{closer}",
         "{opener}pirme sakit ulo ko{tag}{closer}",
         "{opener}daw ga linog akon ulo{closer}",
         "{opener}{root} {intensity}{closer}"])

    # DIZZINESS
    add("dizziness", "dizziness", "symptom", "moderate", "neurological",
        ["lingin ulo", "ga lingin ulo ko", "nahilo ko", "nahilu ko", "permi ko gina hilo",
         "ginahilo", "nagahilo", "kalipong", "lipong", "daw matumba ko", "ginakalipong",
         "nagakalipong", "nahihilo", "nahihilo ko"],
        ["Nahilo gid ko subong", "Lingin ulo ko permi", "Daw matumba ko"],
        ["{opener}lingin ulo ko {time}{closer}",
         "{opener}nahilo ko {intensity}{closer}",
         "{opener}permi ko gina hilo{closer}",
         "{opener}daw matumba ko{closer}",
         "{opener}{root} {time}{closer}"])

    # EYE PAIN
    add("eye pain", "eye pain", "pain", "moderate", "ophthalmological",
        ["sakit mata", "masakit mata", "ga hapdi mata ko", "hapdi mata", "kirot mata",
         "nagasakit mata", "ginasakit mata", "panakit mata", "malain panulok ko",
         "indi ko kaklaro kita", "daw may buhangin mata", "ga luha mata"],
        ["Masakit gid akon mata", "Indi ko klaro makita", "Eye pain gid ko"],
        ["{opener}sakit mata ko {time}{closer}",
         "{opener}ga hapdi mata ko{closer}",
         "{opener}malain panulok ko{closer}",
         "{opener}indi ko kaklaro kita{closer}",
         "{opener}{root} {intensity}{closer}"])

    # CHEST PAIN
    add("chest pain", "chest pain", "pain", "severe", "cardiovascular",
        ["sakit dughan", "masakit dughan", "ga pito dughan ko", "may kasakit sa dughan ko",
         "sakit sang dughan", "masakit dibdib", "hapdi dughan", "gapigos dughan",
         "gakurot dughan", "sakit tagipusuon", "chest pain"],
        ["Gapigos gid dughan ko", "May kasakit sa dughan ko subong"],
        ["{opener}sakit dughan ko {time}{closer}",
         "{opener}ga pito dughan ko{closer}",
         "{opener}may kasakit sa dughan ko{closer}",
         "{opener}grabe sakit dughan ko{closer}",
         "{opener}{root} {intensity}{closer}"])

    # STOMACH PAIN
    add("stomach pain", "abdominal pain", "pain", "moderate", "gastrointestinal",
        ["sakit tiyan", "sakit tyan", "gabatyag ko sakit sa tiyan", "nagakalain tiyan ko",
         "masakit tiyan", "masakit sikmura", "sakit sikmura", "kirot tiyan", "hapdi tiyan",
         "ga sakit tiyan ko", "ginasakit tiyan", "stomach pain"],
        ["Gabatyag ko sakit sa tiyan", "Nagakalain tiyan ko gid"],
        ["{opener}sakit tiyan ko {time}{closer}",
         "{opener}gabatyag ko sakit sa tiyan{closer}",
         "{opener}nagakalain tiyan ko{closer}",
         "{opener}{root} {intensity}{closer}"])

    # FEVER
    add("fever", "fever", "symptom", "moderate", "general",
        ["ginalagnat ko", "may hilanat ko", "init lawas ko", "ginahilanat", "may lagnat",
         "ginainit", "nagahilanat", "ginapanghilanat", "ginapanginit", "ginafever",
         "may fever ko", "ginafever ko"],
        ["Ginalagnat ko subong", "Init lawas ko gid", "May hilanat ko kagapon"],
        ["{opener}may hilanat ko {time}{closer}",
         "{opener}init lawas ko{closer}",
         "{opener}ginalagnat ko{closer}",
         "{opener}{root} {time}{closer}"])

    # COUGH
    add("cough", "cough", "symptom", "mild", "respiratory",
        ["ubo", "ginauubo", "nagaubo", "ubuhan", "grabe ubo ko", "sige ubo ko",
         "ginakuhul", "kuhul", "ubuhon", "ginubo"],
        ["Grabe ubo ko subong", "Sige ko ubo"],
        ["{opener}grabe ubo ko {time}{closer}",
         "{opener}sige ubo ko{closer}",
         "{opener}{root} {intensity}{closer}"])

    # COLDS
    add("common cold", "rhinitis", "symptom", "mild", "respiratory",
        ["sip-on", "sipon", "grabe sip-on ko", "ginasipon", "nagasipon", "barado ilong",
         "ginabarado ilong", "sipon kag ubo", "sip-on gid ko"],
        ["Grabe sip-on ko", "Barado ilong ko"],
        ["{opener}grabe sip-on ko {time}{closer}",
         "{opener}barado ilong ko{closer}",
         "{opener}{root} {intensity}{closer}"])

    # DIARRHEA
    add("diarrhea", "diarrhea", "symptom", "moderate", "gastrointestinal",
        ["kalibanga", "sige kalibanga ko", "ginakalibanga", "nagakalibanga", "lbm",
         "ginatulo", "tulo-tulo", "ginakalibanga ko"],
        ["Sige ko kalibanga", "Kalibanga gid ko subong"],
        ["{opener}sige kalibanga ko {time}{closer}",
         "{opener}{root} {intensity}{closer}"])

    # VOMITING
    add("vomiting", "vomiting", "symptom", "moderate", "gastrointestinal",
        ["suka", "gasuka ko", "nagsuka", "ginasuka", "nagsusuka", "sumuka", "ginagsuka",
         "sige ko suka", "ginakasukaon"],
        ["Gasuka ko subong", "Sige ko suka kag kalibanga"],
        ["{opener}gasuka ko {time}{closer}",
         "{opener}sige ko suka{closer}",
         "{opener}{root} {intensity}{closer}"])

    # BREATHING
    add("shortness of breath", "dyspnea", "symptom", "severe", "respiratory",
        ["budlay ginhawa", "indi ko kaginhawa", "ginakapos ko ginhawa", "ginakapos ginhawa",
         "budlay magginhawa", "lisod magginhawa", "ginagutok", "ginahingal", "hingal",
         "ginakapos", "shortness of breath", "difficulty breathing"],
        ["Budlay gid akon ginhawa", "Indi ko kaginhawa subong"],
        ["{opener}budlay gid akon ginhawa{closer}",
         "{opener}indi ko kaginhawa{closer}",
         "{opener}ginakapos ko ginhawa{closer}",
         "{opener}{root} {time}{closer}"])

    # EMERGENCY
    add("severe bleeding", "hemorrhage", "emergency", "emergency", "cardiovascular",
        ["ga dugo gid", "grabe pagdugo", "nagadugo sing grabe", "dugo nga indi mag-untat",
         "ginabulos dugo", "grabe bleeding"],
        ["Ga dugo gid indi mag-untat", "Grabe pagdugo ko"],
        ["{opener}ga dugo gid{closer}",
         "{opener}grabe pagdugo{closer}",
         "{opener}{root} {intensity}{closer}"])

    add("loss of consciousness", "syncope", "emergency", "emergency", "neurological",
        ["nadulaan ko malay", "nag collapse ko", "nag-collapse ko", "nawala ko malay",
         "ginapunaw", "nagpunaw", "nagfaint ko", "nag collapse gid ko"],
        ["Nadulaan ko malay subong", "Nag collapse ko kagapon"],
        ["{opener}nadulaan ko malay{closer}",
         "{opener}nag collapse ko{closer}",
         "{opener}daw mapatay ko{closer}",
         "{opener}{root} {time}{closer}"])

    add("cannot breathe", "respiratory distress", "emergency", "emergency", "respiratory",
        ["indi ko kaginhawa", "indi ko makaginhawa", "ginakapos gid", "ginakapos ko ginhawa",
         "daw mapatay ko tungod ginhawa"],
        ["Indi ko kaginhawa gid", "Daw mapatay ko indi ko makaginhawa"],
        ["{opener}indi ko kaginhawa{closer}",
         "{opener}daw mapatay ko{closer}",
         "{opener}{root} {intensity}{closer}"])

    # EAR PAIN
    add("ear pain", "ear pain", "pain", "moderate", "ENT",
        ["sakit dalunggan", "masakit dalunggan", "kirot dalunggan", "hapdi dalunggan",
         "sakit dulunggan", "ginasakit dalunggan"],
        ["Masakit dalunggan ko gid"],
        ["{opener}{root} ko {time}{closer}",
         "{opener}{root} {intensity}{closer}"])

    # BACK PAIN
    add("back pain", "back pain", "pain", "moderate", "musculoskeletal",
        ["sakit likod", "masakit likod", "kirot likod", "ga sakit likod", "ginasakit likod"],
        ["Masakit likod ko gid"],
        ["{opener}{root} ko {time}{closer}",
         "{opener}{root} {intensity}{closer}"])

    # JOINT PAIN
    add("joint pain", "arthralgia", "pain", "moderate", "musculoskeletal",
        ["sakit lutahan", "masakit lutahan", "rayuma", "kirot lutahan", "arthritis pain"],
        ["Sakit lutahan ko gid"],
        ["{opener}{root} {time}{closer}"])

    # KNEE PAIN
    add("knee pain", "knee pain", "pain", "moderate", "musculoskeletal",
        ["sakit tuhod", "masakit tuhod", "kirot tuhod"],
        ["Masakit tuhod ko"],
        ["{opener}{root} ko {time}{closer}"])

    # ITCHING
    add("itching", "pruritus", "symptom", "mild", "dermatological",
        ["kakatol", "kakatul", "katol", "ga katol", "kumakati", "kinatol", "kakatol lawas",
         "kakatul lawas ko", "kinatulan"],
        ["Kakatul lawas ko", "Ga katol gid ko"],
        ["{opener}{root} {time}{closer}",
         "{opener}{root} {intensity}{closer}"])

    # RASH
    add("rash", "rash", "symptom", "mild", "dermatological",
        ["bugas", "butlig", "pamula", "pantal", "rashes", "nagapamula", "galis"],
        ["May bugas sa panit ko"],
        ["{opener}{root} sa panit ko{closer}"])

    # FATIGUE
    add("fatigue", "fatigue", "symptom", "mild", "general",
        ["kapoy", "ginakapoy", "luya", "maluya", "ginamaluya", "weakness", "body weakness",
         "kapoy gid lawas ko"],
        ["Kapoy gid lawas ko"],
        ["{opener}{root} {time}{closer}"])

    # NAUSEA
    add("nausea", "nausea", "symptom", "mild", "gastrointestinal",
        ["nahihilo tiyan", "ginabalik tiyan", "kasukaon", "ginakasukaon", "nausea"],
        ["Ginabalik tiyan ko"],
        ["{opener}{root} {time}{closer}"])

    # URINARY
    add("painful urination", "dysuria", "symptom", "moderate", "urinary",
        ["masakit mag-ihi", "sakit pag-ihi", "hapdi mag-ihi", "ginamasakit ihi"],
        ["Masakit mag-ihi ko"],
        ["{opener}{root}{closer}"])

    # DIABETES
    add("diabetes", "diabetes mellitus", "condition", "moderate", "endocrine",
        ["diyabetes", "diabetes", "may diyabetes", "mataas ang asukal", "high blood sugar"],
        ["May diyabetes ako"],
        ["{opener}{root}{closer}"])

    # HYPERTENSION
    add("hypertension", "hypertension", "condition", "moderate", "cardiovascular",
        ["altapresyon", "high blood", "mataas ang presyon", "hypertension"],
        ["May altapresyon ako"],
        ["{opener}{root}{closer}"])

    # ASTHMA
    add("asthma", "asthma", "disease", "moderate", "respiratory",
        ["asma", "hika", "may hika", "ginahika", "asthma attack"],
        ["May hika ako"],
        ["{opener}{root}{closer}"])

    # MENTAL HEALTH
    add("anxiety", "anxiety", "mental_health", "moderate", "mental_health",
        ["kaba", "ginakaba", "kulba", "ginakulbaan", "nervous", "anxious", "ginakulba"],
        ["Ginakulbaan gid ko"],
        ["{opener}{root} {time}{closer}"])

    add("depression", "depression", "mental_health", "moderate", "mental_health",
        ["kasubo", "ginakasubo", "maluya utok", "sad gid ko", "depressed"],
        ["Ginakasubo gid ko"],
        ["{opener}{root} {time}{closer}"])

    # PREGNANCY
    add("pregnancy complaint", "pregnancy symptom", "pregnancy", "moderate", "reproductive",
        ["buntis", "may bata sa tiyan", "ginabuntis", "masakit tiyan buntis",
         "morning sickness buntis", "ginahilo buntis"],
        ["Buntis ako kag masakit tiyan ko"],
        ["{opener}{root} {time}{closer}"])

    # PEDIATRIC
    add("child fever", "pediatric fever", "pediatric", "moderate", "general",
        ["ginahilanat ang bata", "may hilanat ang bata", "init lawas sang bata",
         "ginafever ang anak", "hilanat sang bata"],
        ["May hilanat ang bata ko"],
        ["{opener}{root} {time}{closer}"])

    add("child cough", "pediatric cough", "pediatric", "mild", "respiratory",
        ["ginauubo ang bata", "ubo sang bata", "nagaubo ang anak"],
        ["Nagaubo ang bata ko"],
        ["{opener}{root} {time}{closer}"])

    # MEDICATION
    add("need medication", "medication request", "medication", "mild", "general",
        ["bulong", "gusto ko bulong", "wala ko bulong", "prescription", "reseta",
         "pain reliever", "paracetamol", "biogesic", "antibiotic"],
        ["Wala ko bulong subong", "Gusto ko paracetamol"],
        ["{opener}{root}{closer}"])

    # BODY PARTS
    add("head", "head", "body_part", "mild", "neurological",
        ["ulo", "sang ulo", "utok", "kaulo"],
        [], ["{opener}{root}{closer}"])

    add("eye", "eye", "body_part", "mild", "ophthalmological",
        ["mata", "sang mata", "mata ko"],
        [], ["{opener}{root}{closer}"])

    add("chest", "chest", "body_part", "mild", "cardiovascular",
        ["dughan", "dibdib", "tagipusuon"],
        [], ["{opener}{root}{closer}"])

    add("stomach", "abdomen", "body_part", "mild", "gastrointestinal",
        ["tiyan", "tyan", "sikmura", "pus-on"],
        [], ["{opener}{root}{closer}"])

    # GENERAL COMPLAINTS
    add("general malaise", "malaise", "general_complaint", "mild", "general",
        ["dili maayo pamatyag", "ginamaluya lawas", "daw may balatian ko",
         "feel ko may ara ko", "dili ko maayo subong", "ginabalatian"],
        ["Dili maayo pamatyag ko subong"],
        ["{opener}{root} {time}{closer}",
         "{opener}dili ko maayo {time}{closer}"])

    # COMBINED CONSULTATION
    add("vomiting and diarrhea", "gastroenteritis", "symptom", "moderate", "gastrointestinal",
        ["sige ko suka kag kalibanga", "suka kag kalibanga", "ginasuka kag ginakalibanga"],
        ["Sige ko suka kag kalibanga."],
        ["{opener}sige ko suka kag kalibanga{closer}",
         "{opener}{root} {time}{closer}"])

    # MORE DISEASES
    add("pneumonia", "pneumonia", "disease", "severe", "respiratory",
        ["pulmonya", "may pulmonya", "ginapulmonya"],
        ["May pulmonya ang lola ko"],
        ["{opener}{root}{closer}"])

    add("urinary tract infection", "UTI", "disease", "moderate", "urinary",
        ["uti", "may uti", "masakit mag-ihi permi", "ginauti"],
        ["May UTI daw ko"],
        ["{opener}{root}{closer}"])

    add("migraine", "migraine", "disease", "severe", "neurological",
        ["migraine", "sakit ulo sing grabe", "grabe headache", "nagapulaw mata sakit ulo"],
        ["Migraine gid ko subong"],
        ["{opener}{root} {time}{closer}"])

    add("heart attack symptoms", "myocardial infarction", "emergency", "emergency", "cardiovascular",
        ["heart attack", "atake sa tagipusuon", "sakit dughan kag ginakapos",
         "masakit dughan kag ginahingal"],
        ["Daw heart attack ko"],
        ["{opener}{root}{closer}"])

    add("allergic reaction", "anaphylaxis", "emergency", "emergency", "dermatological",
        ["allergy attack", "hubag lawas", "ginahubag lawas", "dili makaginhawa allergy"],
        ["Hubag lawas ko kag indi makaginhawa"],
        ["{opener}{root}{closer}"])

    add("sore throat", "pharyngitis", "symptom", "mild", "ENT",
        ["sakit tutunlan", "hapdi tutunlan", "masakit tutunlan", "sore throat"],
        ["Sakit tutunlan ko"],
        ["{opener}{root} {time}{closer}"])

    add("toothache", "toothache", "pain", "moderate", "ENT",
        ["sakit ngipon", "masakit ngipon", "kirot ngipon", "toothache"],
        ["Sakit ngipon ko gid"],
        ["{opener}{root} {time}{closer}"])

    add("neck pain", "neck pain", "pain", "moderate", "musculoskeletal",
        ["sakit liog", "masakit liog", "gahi liog", "kirot liog"],
        ["Gahi liog ko"],
        ["{opener}{root} {time}{closer}"])

    add("muscle pain", "myalgia", "pain", "moderate", "musculoskeletal",
        ["sakit kalawasan", "masakit lawas", "masakit kalawasan", "kapoy lawas",
         "sakit lawas", "body aches"],
        ["Masakit lawas ko"],
        ["{opener}{root} {time}{closer}"])

    add("hair loss", "alopecia", "symptom", "mild", "dermatological",
        ["nagadula buhok", "galagas buhok", "ginadula buhok", "falling hair"],
        ["Galagas buhok ko"],
        ["{opener}{root} {time}{closer}"])

    add("palpitations", "palpitations", "symptom", "moderate", "cardiovascular",
        ["nagadugo sing dali ang tagipusuon", "ginakurot tagipusuon", "palpitations",
         "mabilis ang heartbeat"],
        ["Mabilis ang tagipusuon ko"],
        ["{opener}{root}{closer}"])

    add("constipation", "constipation", "symptom", "mild", "gastrointestinal",
        ["buot", "dili makalibang", "ginabuot", "constipation"],
        ["Dili ako makalibang"],
        ["{opener}{root} {time}{closer}"])

    add("loss of appetite", "anorexia", "symptom", "mild", "gastrointestinal",
        ["wala gana kaon", "wala ko gana", "dili gana kaon", "loss of appetite"],
        ["Wala ko gana magkaon"],
        ["{opener}{root}{closer}"])

    add("wheezing", "wheezing", "symptom", "moderate", "respiratory",
        ["hubak", "ginahubak", "singaw", "wheezing"],
        ["May hubak ko mag-ubo"],
        ["{opener}{root}{closer}"])

    add("influenza", "influenza", "disease", "moderate", "respiratory",
        ["trangkaso", "flu", "ginatrangkaso", "may trangkaso"],
        ["May trangkaso ko"],
        ["{opener}{root}{closer}"])

    add("skin infection", "cellulitis", "disease", "moderate", "dermatological",
        ["nagaimpluwensya panit", "hubag nga may dugo", "nagaimpeksyon panit"],
        ["Nagaimpeksyon ang sugat ko"],
        ["{opener}{root}{closer}"])

    add("menstrual pain", "dysmenorrhea", "symptom", "moderate", "reproductive",
        ["sakit regla", "masakit regla", "kirot regla", "dysmenorrhea"],
        ["Masakit regla ko subong"],
        ["{opener}{root} {time}{closer}"])

    add("burning urination", "dysuria", "symptom", "moderate", "urinary",
        ["mainit mag-ihi", "ginapaso mag-ihi", "burning urination"],
        ["Mainit mag-ihi ko"],
        ["{opener}{root}{closer}"])

    add("swollen legs", "edema", "symptom", "moderate", "cardiovascular",
        ["hubag tiil", "ginahubag tiil", "swollen legs", "hubag binti"],
        ["Hubag tiil ko"],
        ["{opener}{root}{closer}"])

    add("numbness", "paresthesia", "symptom", "moderate", "neurological",
        ["pamamanhid", "ginapamamanhid", "manhid", "numbness"],
        ["Ginapamamanhid kamot ko"],
        ["{opener}{root}{closer}"])

    add("tingling", "paresthesia", "symptom", "mild", "neurological",
        ["nagakati-kati", "ginakurog-kurog", "tingling", "pins and needles"],
        ["May kurog-kurog sa kamot ko"],
        ["{opener}{root}{closer}"])

    return c


def generate_phrases(concept: Concept) -> set[str]:
    phrases: set[str] = set()

    for seed in concept.seeds:
        phrases.add(seed)
        phrases.update(spelling_variants(seed))

    for root in concept.roots:
        phrases.add(root)
        phrases.update(spelling_variants(root))
        for sfx in ["", " gid", " ko", " ako", " man", " subong", " gid ko", " bah"]:
            phrases.add(root + sfx)
        for mix in ["", " po", " talaga", " na gid"]:
            if root:
                phrases.add(root + mix)

    for tpl in concept.templates:
        for root in concept.roots[:6] or [""]:
            for _ in range(12):
                phrases.add(expand_template(tpl, root, concept.english))

    # Mixed language variants
    if concept.category in ("symptom", "pain", "emergency"):
        en = concept.medical_keyword.replace("_", " ")
        for root in list(concept.roots)[:4]:
            phrases.add(f"{root} / {en}")
            phrases.add(f"may {en} ko")
            phrases.add(f"feel ko may {en}")

    return {p for p in phrases if 2 <= len(p) <= 120}


def build_rows(target: int) -> list[dict[str, str]]:
    seen: set[str] = set()
    rows: list[dict[str, str]] = []
    all_concepts = concepts()

    # Pass 1: all explicit phrases
    for concept in all_concepts:
        for phrase in generate_phrases(concept):
            key = norm(phrase)
            if not key or key in seen:
                continue
            seen.add(key)
            rows.append({
                "local_term": phrase.strip(),
                "english_translation": concept.english,
                "category": concept.category,
                "medical_keyword": concept.medical_keyword,
                "severity": concept.severity,
                "body_system": concept.body_system,
            })

    # Pass 2: combinatorial expansion until target
    combo_templates = [
        "{opener}{root} {time}{closer}",
        "{opener}{root} {intensity}{closer}",
        "{opener}dok, {root} {time}{closer}",
        "{opener}doc, {root} ko {time}{tag}{closer}",
        "{opener}feel ko nga {root} ko {time}{closer}",
        "{opener}may ara ko {root} {time}{closer}",
        "{opener}basin {root} ako {time}{closer}",
        "{opener}grabe {root} ko {time}{closer}",
        "{opener}sige ko {root}{closer}",
        "{opener}pirmi ko {root}{closer}",
        "{opener}halin sang aga {root} ko{closer}",
        "{opener}kagapon pa {root} ko{closer}",
        "{opener}daw {root} gid ko{closer}",
        "{opener}complain ko sang {root}{closer}",
        "{opener}{root} kag {root2}{closer}",
        "{opener}{en} {intensity} ko{closer}",
        "{opener}my {en} is bad{closer}",
        "{opener}grabe na {root}{closer}",
    ]

    roots_pool = [(c, r) for c in all_concepts for r in c.roots if r]
    attempt = 0
    max_attempts = target * 30

    while len(rows) < target and attempt < max_attempts:
        attempt += 1
        concept = RNG.choice(all_concepts)
        root = RNG.choice(concept.roots) if concept.roots else concept.medical_keyword
        root2 = RNG.choice(concept.roots) if len(concept.roots) > 1 else root
        tpl = RNG.choice(combo_templates)
        phrase = expand_template(tpl, root, concept.english)
        phrase = phrase.replace("{root2}", root2)
        phrase = phrase.replace("{en}", concept.english.lower())
        phrase = re.sub(r"\s+", " ", phrase).strip()
        for variant in spelling_variants(phrase):
            key = norm(variant)
            if not key or key in seen:
                continue
            seen.add(key)
            rows.append({
                "local_term": variant.strip(),
                "english_translation": concept.english,
                "category": concept.category,
                "medical_keyword": concept.medical_keyword,
                "severity": concept.severity,
                "body_system": concept.body_system,
            })
            if len(rows) >= target:
                break

    return rows[:target]


def next_batch_path() -> Path:
    existing = sorted(OUT_DIR.glob("hiligaynon_medical_training_batch_*.csv"))
    if not existing:
        return OUT_DIR / "hiligaynon_medical_training_batch_01.csv"
    last = existing[-1].stem
    num = int(last.split("_")[-1]) + 1
    return OUT_DIR / f"hiligaynon_medical_training_batch_{num:02d}.csv"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--target", type=int, default=DEFAULT_TARGET)
    parser.add_argument("--output", type=str, default="")
    args = parser.parse_args()

    out = Path(args.output) if args.output else next_batch_path()
    out.parent.mkdir(parents=True, exist_ok=True)

    rows = build_rows(args.target)
    fieldnames = [
        "local_term",
        "english_translation",
        "category",
        "medical_keyword",
        "severity",
        "body_system",
    ]
    with out.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

    print(f"Wrote {len(rows)} unique rows to {out}")


if __name__ == "__main__":
    main()
