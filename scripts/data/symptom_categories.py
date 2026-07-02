"""Map MedlinePlus symptom names to clinical categories for NLP datasets."""

from __future__ import annotations

import re

# Order matters: first match wins
CATEGORY_RULES: list[tuple[str, list[str]]] = [
    ("pediatric", [
        r"\binfant\b", r"\bnewborn\b", r"\bneonatal\b", r"\bcolic\b", r"failure to thrive",
        r"developmental delay", r"\bcroup\b", r"febrile seizure", r"hand-foot-mouth",
        r"cradle cap", r"diaper rash", r"teething", r"failure to gain weight",
    ]),
    ("geriatric", [
        r"\bgeriatric\b", r"\belderly\b", r"\bfrailty\b", r"sundowning", r"polypharmacy",
        r"falls in older", r"age-related", r"delirium in older", r"urinary retention in older",
    ]),
    ("pain", [
        r"\bpain\b", r"\bache\b", r"headache", r"migraine", r"sciatica", r"neuralgia",
        r"myalgia", r"arthralgia", r"pelvic pain", r"chest pain", r"abdominal pain",
        r"chronic pain", r"back pain", r"neck pain", r"toothache", r"earache",
    ]),
    ("respiratory", [
        r"\bcough\b", r"\bwheeze", r"\bwheezing\b", r"shortness of breath", r"dyspnea",
        r"breathing problem", r"respiratory", r"\basthma\b", r"stridor", r"hemoptysis",
        r"nasal congestion", r"runny nose", r"rhinorrhea", r"sneezing", r"sputum",
        r"apnea", r"hyperventilation",
    ]),
    ("cardiovascular", [
        r"\bpalpitation", r"heart murmur", r"chest tightness", r"edema", r"swelling",
        r"hypertension", r"hypotension", r"raynaud", r"varicose", r"claudication",
        r"arrhythmia", r"tachycardia", r"bradycardia", r"cyanosis",
    ]),
    ("gastrointestinal", [
        r"\bnausea\b", r"\bvomit", r"diarrhea", r"constipation", r"indigestion",
        r"dyspepsia", r"heartburn", r"reflux", r"abdominal", r"stomach", r"bowel",
        r"rectal", r"gastrointestinal", r"gi bleeding", r"bloody stool", r"melena",
        r"hematochezia", r"flatulence", r"\bgas\b", r"belching", r"dysphagia",
        r"swallowing", r"appetite", r"jaundice", r"icterus", r"ascites",
    ]),
    ("neurological", [
        r"\bseizure", r"\bstroke\b", r"paralysis", r"weakness", r"numbness", r"tingling",
        r"tremor", r"ataxia", r"vertigo", r"dizziness", r"syncope", r"fainting",
        r"confusion", r"memory", r"head injury", r"neuropathy", r"facial droop",
        r"speech", r"stutter", r"communication disorder", r"migraine", r"coma",
    ]),
    ("mental_health", [
        r"\banxiety\b", r"\bdepress", r"suicidal", r"hallucination", r"delusion",
        r"insomnia", r"sleep", r"mood", r"panic", r"psychosis", r"stress",
        r"agitation", r"irritability",
    ]),
    ("dermatological", [
        r"\brash\b", r"\bhives\b", r"urticaria", r"\bitch", r"pruritus", r"bruise",
        r"contusion", r"blister", r"lesion", r"eczema", r"psoriasis", r"acne",
        r"hair loss", r"alopecia", r"dry skin", r"erythema", r"ulcer", r"wound",
        r"burn", r"frostbite", r"chilblain", r"dermatitis", r"cellulitis",
    ]),
    ("urinary", [
        r"\burinary\b", r"\burination\b", r"\bdysuria\b", r"hematuria", r"polyuria",
        r"oliguria", r"anuria", r"flank pain", r"kidney", r"renal", r"bladder",
        r"incontinence", r"nocturia", r"proteinuria", r"urgency",
    ]),
    ("reproductive", [
        r"vaginal", r"menstrual", r"uterine", r"ovarian", r"testicular", r"erectile",
        r"libido", r"amenorrhea", r"dyspareunia", r"pelvic", r"genital", r"discharge",
        r"mastitis", r"breast lump", r"gynecomastia", r"hot flash", r"menopause",
    ]),
    ("musculoskeletal", [
        r"joint", r"muscle", r"bone", r"fracture", r"stiffness", r"swollen joint",
        r"arthritis", r"back", r"neck", r"limb", r"cramp", r"spasm",
    ]),
    ("genitourinary", [
        r"prostate", r"genitourinary", r"urologic", r"gynecologic",
    ]),
    ("hematologic", [
        r"\banemia\b", r"petechiae", r"purpura", r"thrombocytopenia", r"leukopenia",
        r"lymphadenopathy", r"lymph node", r"coagulation", r"bruising", r"pallor",
    ]),
    ("endocrine_metabolic", [
        r"diabetes", r"thyroid", r"obesity", r"weight", r"thirst", r"polyuria",
        r"hypoglycemia", r"hyperglycemia", r"metabolic",
    ]),
    ("infectious", [
        r"\bfever\b", r"pyrexia", r"chills", r"infection", r"sepsis", r"lymph node",
        r"sore throat", r"tonsillitis",
    ]),
    ("sensory", [
        r"vision", r"hearing", r"ear pain", r"eye", r"blurred", r"blind", r"tinnitus",
        r"photophobia", r"loss of smell", r"loss of taste",
    ]),
    ("general", [
        r"\bfatigue\b", r"tiredness", r"weariness", r"weakness", r"malaise", r"chills",
        r"dehydration", r"thirst", r"weight loss", r"weight gain", r"night sweat",
        r"bleeding", r"hemorrhage", r"bruising", r"pallor",
    ]),
    ("environmental", [
        r"heat illness", r"hypothermia", r"motion sickness", r"altitude", r"decompression",
        r"sunburn", r"heat exhaustion",
    ]),
    ("oral", [
        r"bad breath", r"halitosis", r"tooth", r"mouth", r"gum", r"oral",
    ]),
]

DEFAULT_CATEGORY = "general"


def categorize(symptom_name: str, mesh_groups: list[str] | None = None) -> str:
    """Assign category from MedlinePlus group names or keyword rules."""
    if mesh_groups:
        for g in mesh_groups:
            gl = g.lower().strip()
            if gl in ("symptoms", "pain", "mental health and behavior"):
                if "pain" in gl:
                    return "pain"
                if "mental" in gl:
                    return "mental_health"
            if gl in ("lungs and breathing", "respiratory"):
                return "respiratory"
            if gl in ("heart and circulation", "cardiovascular"):
                return "cardiovascular"
            if gl in ("digestive system", "food and nutrition"):
                return "gastrointestinal"
            if gl in ("brain and nerves", "brain and nerves"):
                return "neurological"
            if gl in ("skin, hair, and nails",):
                return "dermatological"
            if gl in ("bones, joints and muscles", "sports fitness"):
                return "musculoskeletal"
            if gl in ("kidneys and urinary system", "sexual health"):
                return "genitourinary"
            if gl in ("endocrine system", "diabetes"):
                return "endocrine_metabolic"
            if gl in ("immune system", "infections"):
                return "infectious"
            if gl in ("mouth and teeth",):
                return "oral"
            if gl in ("eyes and vision", "ears, nose and throat"):
                return "sensory"

    text = symptom_name.lower()
    for category, patterns in CATEGORY_RULES:
        for pat in patterns:
            if re.search(pat, text):
                return category
    return DEFAULT_CATEGORY
