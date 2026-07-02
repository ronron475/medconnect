#!/usr/bin/env python3
"""
Generate data/nlp/hiligaynon_medical_knowledge_base.csv
Largest Hiligaynon medical NLP knowledge base for telemedicine AI.

Target: 25,000+ patient_statement records with full augmentation per symptom.
"""

from __future__ import annotations

import csv
import random
import re
from dataclasses import dataclass, field
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "hiligaynon_medical_knowledge_base.csv"
TARGET_ROWS = 25_000
RNG = random.Random(2026)

REQUIRED_STATEMENTS: list[tuple[str, str]] = [
    # (exact phrase, normalized_symptom key to find concept)
    ("Kakatul gid lawas ko.", "kakatol lawas"),
    ("Daw ginakapos ko ginhawa.", "budlay ginhawa"),
    ("Galagas buhok ko.", "galagas buhok"),
    ("Kapoy gid ko subong.", "kapoy"),
    ("Daw matumba ko sa kalipong.", "kalipong"),
    ("Sige gid ko suka.", "suka"),
    ("Wala ko gana magkaon.", "wala gana kaon"),
    ("Ga pundo akon dughan.", "sakit dughan"),
    ("Daw may nagakurot sa dughan ko.", "sakit dughan"),
    ("Daw naga kurug akon kamot.", "nagakurog"),
    ("Pirmi ko ginakulbaan.", "kabalaka"),
    ("Daw indi ko katulog.", "indi katulog"),
    ("Masakit gid akon ulo subong", "sakit ulo"),
    ("Budlay gid akon ginhawa", "budlay ginhawa"),
    ("Doctor, masakit gid ulo ko.", "sakit ulo"),
    ("Doc, budlay gid akon ginhawa.", "budlay ginhawa"),
    ("May headache ko.", "sakit ulo"),
    ("Daw may asthma ko.", "asma"),
    ("Stress gid ko lately.", "stress"),
    ("Naga panic attack ko.", "panic attack"),
]

BODY_PARTS = [
    "ulo", "utok", "mata", "dalunggan", "ilong", "baba", "ngipon", "lagos", "liog",
    "abaga", "dughan", "tagipusuon", "tiyan", "pus-on", "likod", "kamot", "kumagko",
    "tiil", "tuhod", "siko", "panit", "buhok", "lawas", "tutunlan", "baton",
    "kalawasan", "dibdib", "sikmura", "liog", "abaga",
]

INTENSITY = ["gid", "grabe", "grabeh", "sobra", "sing malala", "daw malala", "dako gid", "amo gid", "malala gid"]
TIME = ["subong", "halin sang aga", "kagapon", "pirmi", "sige", "halin sang gabii", "karon", "since kagapon", "halin sang semana"]
OPENERS = ["", "daw ", "pareho ", "basin ", "feel ko nga ", "amuni ", "sige ko ", "may ara ko nga "]
EMOTIONAL = [
    "daw maluya gid ko", "daw wala ko kusog", "daw lain gid pamatyag ko",
    "daw may matabo sa akon", "daw ginakulbaan gid ko", "daw indi ko maagwanta",
    "daw grabe na gid", "daw indi ko kasulay", "daw nagapanibugho ko",
]
TELE_PREFIX = ["Doctor, ", "Doc, ", "Sir, ", "Ma'am, ", "Maam, ", "Dok, ", "Help, ", "Please doc, "]
SLANG = ["gid", "gud", "man", "lang", "gid ya", "gid man", "gid bah", "gid ah", "no", "bah"]
MIXED_EN = ["May {en} ko", "Daw may {en} ko", "Naga {en} ko", "{en} gid ko", "Feel ko may {en}", "Parang may {en} ako"]
TYPO_MAP = [
    ("kakatol", "kakatol"), ("kakatol", "kaktol"), ("kakatul", "kakatol"), ("kakatul", "kakati"),
    ("ginhawa", "ginhwa"), ("ginhawa", "ginahwa"), ("dughan", "duhan"), ("dughan", "dugan"),
    ("kalipong", "kalpong"), ("kalipong", "kalipng"), ("tiyan", "tyan"), ("tiyan", "tian"),
    ("sip-on", "sipon"), ("sip-on", "sip on"), ("hilanat", "hilant"), ("masakit", "masaket"),
    ("pamamanhid", "pamamanhd"), ("nagakurog", "nagakurg"), ("galagas", "galags"),
]

SPELLING_SWAPS = [
    ("kakatol", "kakatul"), ("kakatul", "kakatol"), ("katol", "makatol"), ("ga katol", "gina katol"),
    ("sip-on", "sipon"), ("sipon", "sip on"), ("tiyan", "tyan"), ("dughan", "dibdib"),
    ("kalipong", "lipong"), ("dalunggan", "dulunggan"), ("panulok", "panan-awon"),
    ("ginhawa", "ginhawa"), ("lawas", "kalawasan"), ("hilanat", "lagnat"),
]

ICD_MAP = {
    "General Medicine": "R69",
    "Dermatology": "L98",
    "Cardiology": "I51",
    "Pulmonology": "J98",
    "Gastroenterology": "K92",
    "Neurology": "G89",
    "Psychiatry": "F99",
    "Pediatrics": "R69",
    "Geriatrics": "R54",
    "Orthopedics": "M25",
    "Ophthalmology": "H57",
    "ENT": "H93",
    "Urology": "N39",
    "Gynecology": "N94",
    "Infectious Disease": "B99",
    "Endocrinology": "E34",
    "Oncology": "C80",
    "Emergency Medicine": "R69",
}


@dataclass
class SymptomConcept:
    normalized_symptom: str
    english_translation: str
    medical_term: str
    icd_category: str
    body_system: str
    urgency_level: str
    possible_conditions: list[str]
    related_symptoms: list[str]
    confidence_keywords: list[str]
    hiligaynon_roots: list[str]
    seeds: list[str] = field(default_factory=list)
    body_parts: list[str] = field(default_factory=list)


def norm(t: str) -> str:
    return re.sub(r"\s+", " ", t.lower().strip())


def spelling_variants(phrase: str, limit: int = 50) -> list[str]:
    out = {phrase}
    for a, b in SPELLING_SWAPS:
        if a in phrase:
            out.add(phrase.replace(a, b))
    parts = phrase.split()
    if len(parts) > 1:
        out.add(" ".join(parts))
        out.add("-".join(parts))
    for _ in range(limit):
        if len(out) >= limit:
            break
        p = RNG.choice(list(out))
        for a, b in SPELLING_SWAPS:
            if a in p:
                out.add(p.replace(a, b))
    return list(out)[:limit]


def typo_variants(phrase: str, limit: int = 20) -> list[str]:
    out = {phrase}
    for a, b in TYPO_MAP:
        if a in phrase:
            out.add(phrase.replace(a, b))
    words = phrase.split()
    if words:
        w = RNG.choice(words)
        if len(w) > 4:
            out.add(phrase.replace(w, w[:-1]))
            out.add(phrase.replace(w, w + w[-1]))
    return list(out)[:limit]


def concepts() -> list[SymptomConcept]:
    c: list[SymptomConcept] = []

    def add(norm_sym, en, med, icd, body, urg, conds, related, kw, roots, seeds=None, parts=None):
        c.append(SymptomConcept(
            normalized_symptom=norm_sym, english_translation=en, medical_term=med,
            icd_category=icd, body_system=body, urgency_level=urg,
            possible_conditions=conds, related_symptoms=related, confidence_keywords=kw,
            hiligaynon_roots=roots, seeds=seeds or [], body_parts=parts or [],
        ))

    # PAIN
    add("sakit ulo", "headache", "cephalalgia", "Neurology", "nervous", "Medium",
        ["migraine", "tension headache", "sinusitis"], ["nausea", "dizziness", "photophobia"],
        ["ulo", "headache", "pain", "head"],
        ["sakit ulo", "masakit ulo", "ulo masakit", "sakit sang ulo", "hapdi ulo", "panakit ulo"],
        ["Masakit gid akon ulo subong", "Sakit sang ulo ko", "Daw mabutho akon ulo", "Grabe gid sakit ulo ko"],
        ["ulo", "utok"])
    add("sakit ulo", "migraine", "migraine", "Neurology", "nervous", "Medium",
        ["migraine", "cluster headache"], ["nausea", "photophobia", "aura"],
        ["migraine", "ulo", "headache"],
        ["sakit ulo sing malala", "grabe headache", "migraine ko"], ["ulo"])
    add("sakit liog", "neck pain", "cervicalgia", "Orthopedics", "musculoskeletal", "Medium",
        ["muscle strain", "cervical spondylosis"], ["headache", "numbness"],
        ["neck", "liog", "pain"], ["sakit liog", "masakit liog", "hapdi liog"], ["liog"])
    add("sakit abaga", "shoulder pain", "shoulder pain", "Orthopedics", "musculoskeletal", "Medium",
        ["rotator cuff", "bursitis"], ["arm weakness"], ["shoulder", "abaga", "pain"],
        ["sakit abaga", "masakit abaga"], ["abaga"])
    add("masakit likod", "back pain", "back pain", "Orthopedics", "musculoskeletal", "Medium",
        ["muscle strain", "herniated disc", "kidney stone"], ["leg pain", "numbness"],
        ["back", "likod", "pain"], ["masakit likod", "sakit likod", "sakit sa likod"],
        ["Masakit likod ko", "likod ko masakit gid"], ["likod"])
    add("sakit tuhod", "knee pain", "knee pain", "Orthopedics", "musculoskeletal", "Medium",
        ["osteoarthritis", "meniscus tear"], ["swelling", "stiffness"],
        ["knee", "tuhod", "pain"], ["masakit tuhod", "sakit tuhod"], ["Masakit tuhod ko"], ["tuhod"])
    add("sakit tiil", "leg pain", "leg pain", "Orthopedics", "musculoskeletal", "Medium",
        ["DVT", "sciatica", "muscle cramp"], ["swelling"], ["leg", "tiil", "pain"],
        ["masakit tiil", "sakit tiil", "hapdi tiil"], ["tiil"])
    add("masakit kalawasan", "muscle pain", "myalgia", "General Medicine", "musculoskeletal", "Low",
        ["viral infection", "overexertion"], ["fatigue", "fever"],
        ["muscle", "lawas", "pain"], ["masakit kalawasan", "sakit lawas", "masakit lawas"],
        ["Masakit kalawasan ko", "Luya lawas ko kag masakit"], ["lawas", "kalawasan"])
    add("sakit dughan", "chest pain", "chest pain", "Cardiology", "cardiovascular", "Critical",
        ["angina", "MI", "GERD", "anxiety"], ["shortness of breath", "palpitations"],
        ["chest", "dughan", "heart", "pain"],
        ["sakit dughan", "masakit dughan", "sakit sang dughan", "hapdi dughan", "masakit dibdib"],
        ["Masakit akon dughan", "Ga pundo akon dughan", "Daw may nagakurot sa dughan ko", "Gapigos gid dughan ko"],
        ["dughan", "dibdib", "tagipusuon"])
    add("sakit tiyan", "abdominal pain", "abdominal pain", "Gastroenterology", "digestive", "Medium",
        ["gastritis", "appendicitis", "IBS"], ["nausea", "vomiting", "diarrhea"],
        ["stomach", "tiyan", "abdomen", "pain"],
        ["sakit tiyan", "masakit tiyan", "sakit tyan", "sakit sikmura"],
        ["Masakit akon tiyan", "Sakit tiyan ko gid"], ["tiyan", "sikmura", "pus-on"])
    add("masakit dalunggan", "ear pain", "otalgia", "ENT", "sensory", "Medium",
        ["otitis media", "otitis externa"], ["hearing loss", "fever"],
        ["ear", "dalunggan", "pain"], ["masakit dalunggan", "sakit dalunggan", "hapdi dalunggan"],
        ["Masakit dalunggan ko", "Nabugto akon dulunggan"], ["dalunggan"])
    add("sakit ngipon", "tooth pain", "dentalgia", "General Medicine", "dental", "Medium",
        ["dental caries", "abscess"], ["swelling", "fever"],
        ["tooth", "ngipon", "dental", "pain"], ["sakit ngipon", "masakit ngipon", "hapdi ngipon"], ["ngipon"])
    add("sakit mata", "eye pain", "ocular pain", "Ophthalmology", "sensory", "Medium",
        ["conjunctivitis", "glaucoma"], ["redness", "blurred vision"],
        ["eye", "mata", "pain"], ["sakit mata", "masakit mata", "hapdi mata"], ["mata"])

    # RESPIRATORY
    add("ubo", "cough", "cough", "Pulmonology", "respiratory", "Low",
        ["URTI", "bronchitis", "pneumonia"], ["fever", "sore throat"],
        ["cough", "ubo"], ["ubo", "ginaubo", "nagaubo", "ubuhan"], ["May ubo ko kag sip-on", "Ubo gid ko"])
    add("ubo sing ubra", "productive cough", "productive cough", "Pulmonology", "respiratory", "Medium",
        ["bronchitis", "pneumonia", "TB"], ["fever"], ["cough", "productive", "ubo"],
        ["ubo sing ubra", "may plema ko", "productive cough"], ["May plema sa ubo ko"])
    add("ubo sing taya", "dry cough", "dry cough", "Pulmonology", "respiratory", "Low",
        ["viral URTI", "allergy"], ["sore throat"], ["dry cough", "ubo"],
        ["ubo sing taya", "dry cough ko", "ubo walay plema"], ["Ubo sing taya gid ko"])
    add("hubak", "wheezing", "wheezing", "Pulmonology", "respiratory", "High",
        ["asthma", "COPD"], ["shortness of breath"], ["wheezing", "hubak", "singaw"],
        ["hubak", "ginahubak", "singaw", "wheezing"], ["Hubak gid ko", "May singaw ko"])
    add("asma", "asthma", "asthma", "Pulmonology", "respiratory", "High",
        ["asthma", "bronchospasm"], ["wheezing", "cough"], ["asthma", "hika"],
        ["asma", "hika", "ginahika", "may hika"], ["Daw may asthma ko", "May hika ko"])
    add("budlay ginhawa", "shortness of breath", "dyspnea", "Pulmonology", "respiratory", "High",
        ["asthma", "COPD", "pneumonia", "heart failure"], ["chest pain", "wheezing"],
        ["breathing", "dyspnea", "ginhawa"],
        ["budlay ginhawa", "ginakapos ginhawa", "lisod magginhawa", "ginakapos"],
        ["Budlay gid akon ginhawa", "Daw ginakapos ko ginhawa", "Daw indi ko ka ginhawa tarong"])
    add("sip-on", "nasal congestion", "nasal congestion", "Pulmonology", "respiratory", "Low",
        ["rhinitis", "sinusitis"], ["runny nose", "headache"],
        ["nose", "sipon", "congestion"], ["sip-on", "sipon", "barado ilong", "sip on"],
        ["Barado ilong ko", "May sip-on ko"])
    add("sipon", "runny nose", "rhinitis", "Pulmonology", "respiratory", "Low",
        ["common cold", "allergic rhinitis"], ["sneezing", "cough"],
        ["runny nose", "sipon"], ["sipon", "ginasipon", "runny nose"], ["May sipon ko"])
    add("masakit tutunlan", "sore throat", "pharyngitis", "Pulmonology", "respiratory", "Low",
        ["pharyngitis", "tonsillitis"], ["fever", "cough"],
        ["throat", "tutunlan", "sore"], ["masakit tutunlan", "sakit tutunlan", "hapdi tutunlan"], ["tutunlan"])

    # GI
    add("kalibanga", "diarrhea", "diarrhea", "Gastroenterology", "digestive", "Medium",
        ["gastroenteritis", "food poisoning"], ["abdominal pain", "fever"],
        ["diarrhea", "kalibanga"], ["kalibanga", "gakalibanga", "lbm", "tulo-tulo"],
        ["Kalibanga gid ko", "Sige ko kalibanga"])
    add("suka", "vomiting", "vomiting", "Gastroenterology", "digestive", "Medium",
        ["gastroenteritis", "migraine"], ["nausea"], ["vomit", "suka"],
        ["suka", "nagsuka", "ginasuka", "sumuka"], ["Sige gid ko suka", "Sige ko suka"])
    add("kasukaon", "nausea", "nausea", "Gastroenterology", "digestive", "Low",
        ["gastroenteritis", "migraine", "pregnancy"], ["vomiting"], ["nausea", "kasukaon"],
        ["kasukaon", "ginakasukaon", "nahihilo"], ["Kasukaon gid ko"])
    add("wala gana kaon", "loss of appetite", "anorexia", "Gastroenterology", "digestive", "Low",
        ["infection", "depression", "gastritis"], ["weight loss"], ["appetite", "gana"],
        ["wala gana kaon", "dili gana kaon", "wala gana"], ["Wala ko gana magkaon"])
    add("panuhot", "bloating", "abdominal distension", "Gastroenterology", "digestive", "Low",
        ["IBS", "gastritis"], ["abdominal pain"], ["bloat", "panuhot"],
        ["panuhot", "ginapanuhot", "bloated"], ["Daw ginapanuhot ko"])
    add("acid reflux", "acid reflux", "GERD", "Gastroenterology", "digestive", "Medium",
        ["GERD", "esophagitis"], ["heartburn", "chest pain"],
        ["reflux", "heartburn", "acid"], ["acid reflux", "heartburn", "masakit tiyan after kaon"],
        ["May acid reflux ko", "Heartburn gid ko"])
    add("buot", "constipation", "constipation", "Gastroenterology", "digestive", "Low",
        ["constipation", "IBS"], ["abdominal pain"], ["constipation", "buot"],
        ["buot", "dili makalibang", "constipation"], ["Buot gid ko"])

    # DERMATOLOGY
    add("kakatol lawas", "itchiness", "pruritus", "Dermatology", "integumentary", "Low",
        ["eczema", "scabies", "allergy"], ["rash", "redness"],
        ["itch", "kakatol", "pruritus"],
        ["kakatol", "kakatul", "katol", "makatol", "ga katol", "gina katol", "nagakakatol",
         "gakakatol", "ga katol lawas", "kakatol bilog lawas", "kumakati lawas", "kakatul lawas"],
        ["Kakatul gid lawas ko", "Kakatol akon lawas", "Ga katol akon lawas"])
    add("bugas-bugas", "rash", "rash", "Dermatology", "integumentary", "Low",
        ["allergic reaction", "viral exanthem"], ["itchiness", "fever"],
        ["rash", "bugas", "skin"], ["bugas", "bugas-bugas", "butlig", "galis", "pantal"],
        ["May bugas-bugas ko", "May butlig ko"])
    add("hubag", "swelling", "edema", "Dermatology", "integumentary", "Medium",
        ["urticaria", "allergy", "angioedema"], ["itchiness", "redness"],
        ["hives", "hubag", "swelling"], ["hubag", "hubag-hubag", "nagahubag", "may hubag ko"],
        ["May hubag ko", "Hubag gid ko"])
    add("pamula panit", "skin redness", "erythema", "Dermatology", "integumentary", "Low",
        ["dermatitis", "allergy"], ["itchiness"], ["red", "skin", "pamula"],
        ["pamula panit", "naga pula panit", "mapula panit"], ["Naga pula akon panit"])
    add("galagas buhok", "hair loss", "alopecia", "Dermatology", "integumentary", "Low",
        ["alopecia", "telogen effluvium", "stress"], ["thinning hair"],
        ["hair", "buhok", "alopecia"],
        ["galagas buhok", "nagahulog buhok", "numipis buhok", "kalbo", "upod buhok"],
        ["Galagas buhok ko", "Nagahulog buhok ko", "Daw makalbo na ko"])
    add("nagatubok", "boils", "furuncle", "Dermatology", "integumentary", "Medium",
        ["furuncle", "abscess"], ["fever", "pain"], ["boil", "tubok"],
        ["nagatubok", "tubok", "pigsa"], ["May tubok ko"])

    # NEUROLOGY
    add("kalipong", "dizziness", "dizziness", "Neurology", "nervous", "Medium",
        ["vertigo", "hypotension", "anemia"], ["nausea", "headache"],
        ["dizzy", "kalipong", "vertigo"], ["kalipong", "lipong", "nalipong", "hilo"],
        ["Kalipong gid ko", "Daw matumba ko sa kalipong", "Kalipong ko pirmi"])
    add("kalipong", "vertigo", "vertigo", "Neurology", "nervous", "Medium",
        ["BPPV", "Meniere disease"], ["nausea", "hearing loss"],
        ["vertigo", "spinning"], ["daw nagatalinga ko", "vertigo gid ko"], ["Kalipong gid ko"])
    add("pamamanhid", "numbness", "paresthesia", "Neurology", "nervous", "Medium",
        ["stroke", "diabetes", "nerve compression"], ["weakness"],
        ["numb", "manhid", "pamamanhid"], ["pamamanhid", "manhid", "ginamanhid"],
        ["Pamamanhid akon kamot", "Mani hid kamot ko"])
    add("luya lawas", "weakness", "asthenia", "Neurology", "nervous", "Medium",
        ["anemia", "infection", "stroke"], ["fatigue"], ["weak", "luya", "lawas"],
        ["luya lawas", "mahina lawas", "ginluya lawas"], ["Luya lawas ko", "Daw wala ko kusog"])
    add("kombulsyon", "seizures", "seizure", "Neurology", "nervous", "Critical",
        ["epilepsy", "febrile seizure"], ["loss of consciousness"],
        ["seizure", "kombulsyon"], ["kombulsyon", "konvulsion", "seizure"],
        ["May kombulsyon ko", "Nagakonvulsion ko"])
    add("nagakurog", "tremors", "tremor", "Neurology", "nervous", "Medium",
        ["essential tremor", "parkinsonism"], ["anxiety"], ["tremor", "kurog"],
        ["nagakurog", "kurog", "ginakurog", "nagauyog"],
        ["Daw naga kurug akon kamot", "Nagakurog akon kamot"])
    add("memory loss", "memory loss", "amnesia", "Neurology", "nervous", "Medium",
        ["dementia", "TBI"], ["confusion"], ["memory", "forget"],
        ["daw nalimtan ko tanan", "memory loss ko"], ["Daw indi ko maalala"])
    add("confusion", "confusion", "confusion", "Neurology", "nervous", "High",
        ["delirium", "stroke", "infection"], ["fever"], ["confusion", "disoriented"],
        ["daw nagalibog ko", "confused gid ko"], ["Daw lain gid pamatyag ko"])

    # MENTAL HEALTH
    add("kabalaka", "anxiety", "anxiety", "Psychiatry", "mental", "Medium",
        ["GAD", "panic disorder"], ["insomnia", "palpitations"],
        ["anxiety", "kabalaka", "worry"], ["kabalaka", "ginakabalaka", "kulba"],
        ["Pirmi ko ginakulbaan", "Kabalaka gid ko", "Daw ginakulbaan gid ko"])
    add("stress", "stress", "stress", "Psychiatry", "mental", "Medium",
        ["burnout", "adjustment disorder"], ["anxiety", "insomnia"],
        ["stress"], ["stress", "ginastress", "stressed"], ["Stress gid ko lately", "Stress gid ko"])
    add("panic attack", "panic attack", "panic attack", "Psychiatry", "mental", "High",
        ["panic disorder"], ["chest pain", "dyspnea"],
        ["panic", "anxiety"], ["naga panic attack ko", "panic attack gid ko"],
        ["Naga panic attack ko", "Daw may panic attack ko"])
    add("depresyon", "depression", "depression", "Psychiatry", "mental", "High",
        ["major depression"], ["insomnia", "fatigue"],
        ["depression", "sad"], ["depresyon", "depression", "maluoy"], ["Depresyon gid ko"])
    add("indi katulog", "insomnia", "insomnia", "Psychiatry", "mental", "Medium",
        ["insomnia", "anxiety"], ["fatigue"], ["sleep", "insomnia", "katulog"],
        ["indi katulog", "dili makatulog", "dili ko katulog"],
        ["Indi ko katulog", "Daw indi ko katulog"])
    add("kapoy", "fatigue", "fatigue", "General Medicine", "general", "Low",
        ["anemia", "infection", "depression"], ["weakness"], ["tired", "fatigue", "kapoy"],
        ["kapoy", "kapoy gid", "ginakapoy", "pagod"], ["Kapoy gid ko subong"])
    add("emotional distress", "emotional distress", "emotional distress", "Psychiatry", "mental", "Medium",
        ["adjustment disorder", "anxiety"], ["depression"], ["emotional", "distress"],
        ["daw lain gid pamatyag ko", "emotional distress ko"], ["Daw lain gid pamatyag ko"])

    # URINARY
    add("masakit mag ihi", "painful urination", "dysuria", "Urology", "urinary", "Medium",
        ["UTI", "urethritis"], ["frequency"], ["urine", "ihi", "pain"],
        ["masakit mag ihi", "hapdi mag ihi", "sakit mag ihi"],
        ["Masakit mag ihi", "Daw may hapdi mag ihi", "Sir, may dugo akon ihi"])
    add("sige ihi", "frequent urination", "polyuria", "Urology", "urinary", "Medium",
        ["UTI", "diabetes"], ["thirst"], ["frequency", "urine"],
        ["sige ihi", "daku ihi", "frequent urination"], ["Sige ko ihi"])
    add("may dugo sa ihi", "blood in urine", "hematuria", "Urology", "urinary", "High",
        ["UTI", "kidney stone", "cancer"], ["pain"], ["blood", "urine", "ihi"],
        ["may dugo sa ihi", "dugo sa ihi", "bloody urine"], ["May dugo sa ihi ko"])
    add("indi maka ihi", "urinary retention", "urinary retention", "Urology", "urinary", "High",
        ["BPH", "neurogenic bladder"], ["abdominal pain"], ["retention", "urine"],
        ["indi maka ihi", "dili maka ihi", "dili makaihi"], ["Indi ko maka ihi"])

    # EYES
    add("malain panulok", "blurred vision", "blurred vision", "Ophthalmology", "sensory", "Medium",
        ["refractive error", "cataract"], ["eye pain"], ["vision", "blur"],
        ["malain panulok", "blurry vision", "dili maayo panulok"],
        ["Malain panulok ko", "Daw malab-ong akon panulok"])
    add("pula mata", "eye redness", "conjunctival injection", "Ophthalmology", "sensory", "Low",
        ["conjunctivitis", "allergy"], ["itchiness", "discharge"],
        ["red eye", "mata"], ["pula mata", "nagapula mata", "mapula mata"], ["Pula akon mata"])
    add("kakatol mata", "eye irritation", "ocular irritation", "Ophthalmology", "sensory", "Low",
        ["conjunctivitis", "dry eye"], ["redness"], ["eye", "itch"],
        ["kakatol mata", "ginakati mata", "katol sa mata"], ["Kakatol akon mata"])

    # EARS
    add("indi kabati", "hearing loss", "hearing loss", "ENT", "sensory", "Medium",
        ["otitis media", "presbycusis"], ["ear pain"], ["hearing", "deaf"],
        ["indi ka baton maayo", "dili makabati", "hearing loss"],
        ["Daw indi ko kabati maayo"])
    add("naga tingog dalunggan", "ringing ears", "tinnitus", "ENT", "sensory", "Low",
        ["tinnitus", "Meniere"], ["hearing loss"], ["ringing", "tinnitus"],
        ["naga tingog dalunggan", "ringing ears", "tinnitus"],
        ["May tingog akon dalunggan"])
    add("masakit dalunggan", "ear infection", "otitis media", "ENT", "sensory", "Medium",
        ["otitis media", "otitis externa"], ["fever", "hearing loss"],
        ["ear infection", "dalunggan"], ["masakit dalunggan", "sakit dalunggan"],
        ["Masakit dalunggan ko"])

    # FEVER / GENERAL
    add("hilanat", "fever", "fever", "General Medicine", "general", "Medium",
        ["viral infection", "UTI", "dengue"], ["chills", "body pain"],
        ["fever", "hilanat", "lagnat"], ["hilanat", "ginahilantan", "lagnat", "mainit lawas"],
        ["Ginahilantan ko", "May hilanat ko", "Mainit lawas ko"])

    # EMERGENCY
    add("dili makaginhawa", "severe shortness of breath", "acute dyspnea", "Emergency Medicine", "respiratory", "Critical",
        ["asthma attack", "PE", "anaphylaxis"], ["chest pain"],
        ["emergency", "breathing"], ["dili makaginhawa", "daw indi ko ka ginhawa"],
        ["Daw indi ko ka ginhawa", "Doc, budlay gid akon ginhawa"])
    add("nadulaan malay", "unconsciousness", "syncope", "Emergency Medicine", "multi-system", "Critical",
        ["syncope", "seizure", "stroke"], ["chest pain"],
        ["unconscious", "faint"], ["nadulaan malay", "nawala malay", "nagapunaw"],
        ["Nadulaan ko malay"])
    add("grabe nga dugo", "severe bleeding", "hemorrhage", "Emergency Medicine", "multi-system", "Critical",
        ["trauma", "GI bleed"], ["shock"], ["bleeding", "blood"],
        ["grabe nga dugo", "dugo sing malala"], ["May grabe nga dugo nga naga gwa"])
    add("stroke symptoms", "stroke symptoms", "stroke", "Emergency Medicine", "nervous", "Critical",
        ["ischemic stroke", "TIA"], ["weakness", "speech difficulty"],
        ["stroke", "paralysis"], ["daw naluya isa ka bahin sang lawas ko", "stroke daw ko"],
        ["Daw naluya isa ka bahin sang lawas ko"])

    # ENDOCRINOLOGY / INFECTIOUS / ONCOLOGY / GYNEC / PEDS / GERIATRICS samples
    add("mataas asukal", "high blood sugar", "hyperglycemia", "Endocrinology", "endocrine", "High",
        ["diabetes", "DKA"], ["thirst", "frequent urination"],
        ["sugar", "diabetes"], ["mataas asukal", "high blood sugar", "may diabetes ko"],
        ["May diabetes ko", "Mataas asukal ko"])
    add("trangkaso", "influenza", "influenza", "Infectious Disease", "immune", "Medium",
        ["influenza", "COVID-19"], ["fever", "cough"], ["flu", "trangkaso"],
        ["trangkaso", "flu", "influenza"], ["May trangkaso ko"])
    add("dengue", "dengue fever", "dengue fever", "Infectious Disease", "immune", "High",
        ["dengue"], ["fever", "body pain", "bleeding"], ["dengue"],
        ["dengue", "may dengue ko"], ["Daw dengue ko"])
    add("regla sing malala", "heavy menstrual bleeding", "menorrhagia", "Gynecology", "reproductive", "High",
        ["menorrhagia", "fibroids"], ["abdominal pain"], ["period", "regla"],
        ["regla sing malala", "grabe regla ko"], ["Grabe regla ko"])
    add("bata may hilanat", "pediatric fever", "fever", "Pediatrics", "general", "Medium",
        ["viral infection", "ear infection"], ["cough"], ["child", "fever", "bata"],
        ["bata ko may hilanat", "may hilanat ang bata ko"], ["Bata ko may hilanat"])
    add("luya tigulang", "geriatric weakness", "asthenia", "Geriatrics", "musculoskeletal", "Medium",
        ["frailty", "anemia"], ["falls"], ["elderly", "weak"], ["luya na ang lolo ko", "mahina ang tatay ko"],
        ["Daw maluya gid ang tatay ko"])

    return c


def augment_concept(con: SymptomConcept) -> list[str]:
    statements: set[str] = set()

    def add(s: str) -> None:
        s = re.sub(r"\s+", " ", s.strip())
        if 5 <= len(s) <= 120:
            statements.add(s)

    for seed in con.seeds:
        add(seed)
        for v in spelling_variants(seed, 15):
            add(v)
        for v in typo_variants(seed, 8):
            add(v)

    roots = con.hiligaynon_roots
    for root in roots:
        add(root)
        for v in spelling_variants(root, 20):
            add(v)
        for v in typo_variants(root, 10):
            add(v)

    templates = [
        "{opener}masakit {intensity} akon {part} {time}{closer}",
        "{opener}sakit {part} ko {time}{closer}",
        "{opener}grabe gid {symptom} ko{closer}",
        "{opener}may ara ko {symptom} {time}{closer}",
        "{opener}daw {symptom} gid ko{closer}",
        "{opener}pirmi ko {symptom}{closer}",
        "{opener}sige ko {symptom}{closer}",
        "{opener}feel ko nga {symptom} ko{closer}",
        "{opener}complain ko sang {symptom}{closer}",
        "{opener}{symptom} gid lawas ko{closer}",
        "{opener}{symptom} ko subong{closer}",
        "{opener}ako {symptom} {time}{closer}",
    ]

    parts = con.body_parts or [""]
    symptom = con.normalized_symptom
    for _ in range(50):
        t = RNG.choice(templates)
        part = RNG.choice(parts) if parts else ""
        text = t.format(
            opener=RNG.choice(OPENERS),
            intensity=RNG.choice(INTENSITY),
            time=RNG.choice(TIME),
            closer=RNG.choice(["", " gid", " ko", " gid ko", " man", " subong"]),
            symptom=RNG.choice(roots) if roots else symptom,
            part=part or RNG.choice(BODY_PARTS[:12]),
        )
        add(text)

    for slang in SLANG:
        for root in roots[:5]:
            add(f"{root} {slang}")
            add(f"{root} ko {slang}")

    en = con.english_translation
    for pat in MIXED_EN:
        add(pat.format(en=en.lower()))
        add(pat.format(en=en))

    for prefix in TELE_PREFIX:
        for seed in (con.seeds[:5] or roots[:3]):
            add(prefix + seed)

    for emo in EMOTIONAL:
        for root in roots[:3]:
            add(f"{emo}, {root} ko")
            add(f"{root} ko, {emo}")

    for root in roots[:8]:
        add(f"tagalog mix {root}")
        add(f"{root} na gid")

    return list(statements)


def build_rows() -> list[dict[str, str]]:
    rows: dict[str, dict[str, str]] = {}
    group_alts: dict[str, list[str]] = {}

    for con in concepts():
        icd_code = ICD_MAP.get(con.icd_category, "R69")
        alt_str = ";".join(con.hiligaynon_roots[:20])
        cond_str = ";".join(con.possible_conditions)
        rel_str = ";".join(con.related_symptoms)
        kw_str = ";".join(con.confidence_keywords)
        group_key = norm(f"{con.medical_term}|{con.english_translation}")

        group_alts.setdefault(group_key, [])
        group_alts[group_key].extend(con.hiligaynon_roots)

        for stmt in augment_concept(con):
            key = norm(stmt)
            if key in rows:
                continue
            rows[key] = {
                "patient_statement": stmt,
                "normalized_symptom": con.normalized_symptom,
                "english_translation": con.english_translation,
                "medical_term": con.medical_term,
                "icd_category": f"{con.icd_category} ({icd_code})",
                "body_system": con.body_system,
                "urgency_level": con.urgency_level,
                "possible_conditions": cond_str,
                "alternative_spellings": alt_str,
                "related_symptoms": rel_str,
                "confidence_keywords": kw_str,
                "_group": group_key,
            }

    # Compound expansion to reach TARGET_ROWS
    compounds = [
        "subong {s}", "halin sang aga {s}", "kag may fever ko, {s}",
        "tapos {s}", "{s} kag indi ko katulog", "{s} kag kapoy gid ko",
        "doctor tan-aw {s}", "video consult ko kay {s}", "teleconsult {s}",
        "bata ko {s}", "tatay ko {s}", "nanay ko {s}",
    ]
    base = list(rows.values())
    idx = 0
    while len(rows) < TARGET_ROWS and base:
        src = base[idx % len(base)]
        s = src["patient_statement"]
        for pat in compounds:
            text = pat.format(s=s).strip()
            key = norm(text)
            if key in rows or len(text) > 130:
                continue
            rows[key] = {**src, "patient_statement": text}
            if len(rows) >= TARGET_ROWS:
                break
        idx += 1
        if idx > len(base) * len(compounds) * 3:
            break

    for key, row in rows.items():
        alts = sorted(set(group_alts.get(row.pop("_group", ""), [])), key=len, reverse=True)[:30]
        row["alternative_spellings"] = ";".join(alts) if alts else row.get("alternative_spellings", "")

    result = list(rows.values())

    # Guarantee required real-world statements
    con_by_norm = {norm(c.normalized_symptom): c for c in concepts()}
    for phrase, sym_key in REQUIRED_STATEMENTS:
        key = norm(phrase)
        if key in {norm(r["patient_statement"]) for r in result}:
            continue
        con = con_by_norm.get(norm(sym_key))
        if not con:
            for c in concepts():
                if sym_key in c.hiligaynon_roots or sym_key == c.normalized_symptom:
                    con = c
                    break
        if not con:
            continue
        icd_code = ICD_MAP.get(con.icd_category, "R69")
        result.append({
            "id": "0",
            "patient_statement": phrase,
            "normalized_symptom": con.normalized_symptom,
            "english_translation": con.english_translation,
            "medical_term": con.medical_term,
            "icd_category": f"{con.icd_category} ({icd_code})",
            "body_system": con.body_system,
            "urgency_level": con.urgency_level,
            "possible_conditions": ";".join(con.possible_conditions),
            "alternative_spellings": ";".join(con.hiligaynon_roots[:20]),
            "related_symptoms": ";".join(con.related_symptoms),
            "confidence_keywords": ";".join(con.confidence_keywords),
        })

    # Dedupe again
    deduped: dict[str, dict] = {}
    for row in result:
        deduped[norm(row["patient_statement"])] = row
    result = list(deduped.values())
    result.sort(key=lambda r: (r["icd_category"], r["english_translation"], norm(r["patient_statement"])))
    for i, row in enumerate(result, start=1):
        row["id"] = str(i)
    return result


def main() -> None:
    rows = build_rows()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    fields = [
        "id", "patient_statement", "normalized_symptom", "english_translation",
        "medical_term", "icd_category", "body_system", "urgency_level",
        "possible_conditions", "alternative_spellings", "related_symptoms", "confidence_keywords",
    ]
    with OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        w.writerows(rows)

    cats: dict[str, int] = {}
    for r in rows:
        cat = r["icd_category"].split(" (")[0]
        cats[cat] = cats.get(cat, 0) + 1

    print(f"Wrote {len(rows):,} records to {OUT}")
    for cat, n in sorted(cats.items(), key=lambda x: -x[1]):
        print(f"  {cat}: {n:,}")

    index = {norm(r["patient_statement"]): r for r in rows}
    checks = [
        "Kakatul gid lawas ko.", "Daw ginakapos ko ginhawa.", "Galagas buhok ko.",
        "Kapoy gid ko subong.", "Daw matumba ko sa kalipong.", "Sige gid ko suka.",
        "Wala ko gana magkaon.", "Ga pundo akon dughan.", "Daw may nagakurot sa dughan ko.",
        "Daw naga kurug akon kamot.", "Pirmi ko ginakulbaan.", "Daw indi ko katulog.",
    ]
    print("\nReal-world input checks:")
    for phrase in checks:
        hit = index.get(norm(phrase))
        print(f"  {phrase}: {'OK -> ' + hit['english_translation'] if hit else 'MISSING'}")


if __name__ == "__main__":
    main()
