#!/usr/bin/env python3
"""Generate data/nlp/medical_dictionary.csv — Hiligaynon/Ilonggo → English medical terms."""

from __future__ import annotations

import csv
from pathlib import Path

OUT = Path(__file__).resolve().parents[2] / "data" / "nlp" / "medical_dictionary.csv"

# (local_term, english_term, category)  category: condition | allergy
ENTRIES: list[tuple[str, str, str]] = []

def add(local: str, english: str, cat: str) -> None:
    ENTRIES.append((local.strip(), english.strip(), cat))

# ── CARDIOVASCULAR / HYPERTENSION ───────────────────────────────────────────
for t, e in [
    ("altapresyon", "hypertension"), ("alta presyon", "hypertension"),
    ("mataas nga presyon", "hypertension"), ("mataas na presyon", "hypertension"),
    ("high blood", "hypertension"), ("highblood", "hypertension"),
    ("high bp", "hypertension"), ("hbp", "hypertension"),
    ("altapresion", "hypertension"), ("altapresyon ko", "hypertension"),
    ("may alta presyon", "hypertension"), ("ginataas ang presyon", "hypertension"),
    ("sakit sa dugo", "hypertension"), ("sakit sa presyon", "hypertension"),
    ("hypertension", "hypertension"), ("hypertensive", "hypertension"),
    ("sakit sa puso", "heart disease"), ("sakit puso", "heart disease"),
    ("may sakit sa puso", "heart disease"), ("heart problem", "heart disease"),
    ("heart disease", "heart disease"), ("cardiac", "heart disease"),
    ("atake sa puso", "heart attack"), ("atake puso", "heart attack"),
    ("heart attack", "heart attack"), ("heartattack", "heart attack"),
    ("mi", "heart attack"), ("myocardial infarction", "heart attack"),
    ("sakit dughan", "chest pain"), ("hapdi dughan", "chest pain"),
    ("masakit dughan", "chest pain"), ("chest pain", "chest pain"),
    ("chestpain", "chest pain"), ("angina", "angina"),
    ("mabilis ang heartbeat", "arrhythmia"), ("palpitations", "arrhythmia"),
    ("irregular heartbeat", "arrhythmia"), ("stroke", "stroke"),
    ("stroke", "stroke"), ("atake sa utok", "stroke"),
    ("high cholesterol", "hyperlipidemia"), ("altakolesterol", "hyperlipidemia"),
]:
    add(t, e, "condition")

# ── DIABETES / ENDOCRINE ────────────────────────────────────────────────────
for t, e in [
    ("diyabetes", "diabetes"), ("diabetes", "diabetes"), ("diabetis", "diabetes"),
    ("diabetez", "diabetes"), ("diyabetis", "diabetes"), ("sugar", "diabetes"),
    ("may sugar", "diabetes"), ("high sugar", "diabetes"), ("mataas nga asukal", "diabetes"),
    ("mataas na asukal", "diabetes"), ("asukal", "diabetes"), ("type 2 diabetes", "type 2 diabetes"),
    ("type 1 diabetes", "type 1 diabetes"), ("dm", "diabetes"), ("t2dm", "type 2 diabetes"),
    ("insulin", "diabetes"), ("metformin user", "diabetes"),
    ("thyroid", "thyroid disorder"), ("thyroid problem", "thyroid disorder"),
    ("hypothyroid", "hypothyroidism"), ("hyperthyroid", "hyperthyroidism"),
    ("goiter", "goiter"), ("bukol sa liog", "goiter"),
    ("may bukol sa liog", "goiter"),
]:
    add(t, e, "condition")

# ── RESPIRATORY / ASTHMA / TB / FLU ──────────────────────────────────────────
for t, e in [
    ("asma", "asthma"), ("asthma", "asthma"), ("hika", "asthma"),
    ("may hika", "asthma"), ("ginahika", "asthma"), ("bronchial asthma", "asthma"),
    ("ubo", "cough"), ("ubuhan", "cough"), ("ginauubo", "cough"),
    ("cough", "cough"), ("cof", "cough"), ("kuhul", "cough"),
    ("sip-on", "runny nose"), ("sip on", "runny nose"), ("sipon", "runny nose"),
    ("sipon", "runny nose"), ("runny nose", "runny nose"),
    ("ginabudlayan ginhawa", "difficulty breathing"), ("budlay ginhawa", "difficulty breathing"),
    ("lisod magginhawa", "difficulty breathing"), ("shortness of breath", "shortness of breath"),
    ("sob", "shortness of breath"), ("copd", "chronic obstructive pulmonary disease"),
    ("bronkitis", "bronchitis"), ("bronchitis", "bronchitis"),
    ("pulmonya", "pneumonia"), ("pneumonia", "pneumonia"), ("pneumonia", "pneumonia"),
    ("trangkaso", "influenza"), ("flu", "influenza"), ("influenza", "influenza"),
    ("sipon ug hilanat", "common cold"), ("common cold", "common cold"),
    ("sipon ug ubo", "common cold"), ("cold", "common cold"),
    ("tb", "tuberculosis"), ("t.b.", "tuberculosis"), ("tuberculosis", "tuberculosis"),
    ("tbc", "tuberculosis"), ("t.b.c", "tuberculosis"),
    ("pulmonya nga tb", "tuberculosis"), ("pulmonary tb", "tuberculosis"),
    ("ptb", "tuberculosis"), ("kahawa sa tb", "tuberculosis"),
]:
    add(t, e, "condition")

# ── FEVER / INFECTION / DENGUE ────────────────────────────────────────────────
for t, e in [
    ("hilanat", "fever"), ("ginahilanat", "fever"), ("may hilanat", "fever"),
    ("lagnat", "fever"), ("may lagnat", "fever"), ("fever", "fever"),
    ("feber", "fever"), ("init lawas", "fever"), ("init nga lawas", "fever"),
    ("dengue", "dengue"), ("denggi", "dengue"), ("dengue fever", "dengue"),
    ("dengue hemorrhagic", "dengue"), ("dhf", "dengue"),
    ("malaria", "malaria"), ("typhoid", "typhoid fever"),
    ("impeksyon", "infection"), ("infection", "infection"), ("sepsis", "sepsis"),
    ("covid", "covid-19"), ("covid-19", "covid-19"), ("corona", "covid-19"),
    ("coronavirus", "covid-19"), ("sars cov2", "covid-19"),
]:
    add(t, e, "condition")

# ── GI / LIVER / KIDNEY ─────────────────────────────────────────────────────
for t, e in [
    ("sakit tiyan", "stomach pain"), ("panakit tiyan", "stomach pain"),
    ("masakit tiyan", "stomach pain"), ("stomach pain", "stomach pain"),
    ("stomachache", "stomach pain"), ("tummy pain", "abdominal pain"),
    ("abdominal pain", "abdominal pain"), ("sakit sikmura", "abdominal pain"),
    ("kalibanga", "diarrhea"), ("kalibang", "diarrhea"), ("diarrhea", "diarrhea"),
    ("diarrea", "diarrhea"), ("lBM", "diarrhea"), ("lbm", "diarrhea"),
    ("constipation", "constipation"), ("buot", "constipation"),
    ("suka", "vomiting"), ("nagsuka", "vomiting"), ("vomiting", "vomiting"),
    ("vomit", "vomiting"), ("nausea", "nausea"), ("nahihilo", "dizziness"),
    ("kalipong", "dizziness"), ("nagalipong", "dizziness"), ("dizziness", "dizziness"),
    ("vertigo", "vertigo"), ("ulser", "peptic ulcer"), ("ulcer", "peptic ulcer"),
    ("acid reflux", "gastroesophageal reflux disease"), ("gerd", "gastroesophageal reflux disease"),
    ("heartburn", "gastroesophageal reflux disease"), ("kabog", "gastroesophageal reflux disease"),
    ("hepatitis", "hepatitis"), ("hepatitis b", "hepatitis b"), ("hepatitis c", "hepatitis c"),
    ("yellow fever skin", "jaundice"), ("panuhot", "jaundice"), ("jaundice", "jaundice"),
    ("sakit sa atay", "liver disease"), ("liver problem", "liver disease"),
    ("cirrhosis", "liver cirrhosis"), ("fatty liver", "fatty liver disease"),
    ("sakit sa kidney", "kidney disease"), ("sakit sa batok", "kidney disease"),
    ("kidney problem", "kidney disease"), ("kidney disease", "kidney disease"),
    ("ckd", "chronic kidney disease"), ("renal failure", "kidney failure"),
    ("kidney stones", "kidney stones"), ("bato sa kidney", "kidney stones"),
    ("uti", "urinary tract infection"), ("urinary tract infection", "urinary tract infection"),
    ("sakit pag-ihi", "urinary tract infection"), ("masakit mag-ihi", "urinary tract infection"),
]:
    add(t, e, "condition")

# ── CANCER / BLOOD / AUTOIMMUNE ─────────────────────────────────────────────
for t, e in [
    ("cancer", "cancer"), ("kanser", "cancer"), ("tumor", "cancer"),
    ("may tumor", "cancer"), ("chemo", "cancer"), ("chemotherapy", "cancer"),
    ("breast cancer", "breast cancer"), ("lung cancer", "lung cancer"),
    ("colon cancer", "colorectal cancer"), ("leukemia", "leukemia"),
    ("anemia", "anemia"), ("kulang dugo", "anemia"), ("low blood", "anemia"),
    ("lupus", "systemic lupus erythematosus"), ("rayuma", "arthritis"),
    ("arthritis", "arthritis"), ("rayuma sa tuhod", "osteoarthritis"),
    ("arthritis sa tuhod", "osteoarthritis"), ("gout", "gout"), ("may gout", "gout"),
    ("hika ug rayuma", "asthma"), ("psoriasis", "psoriasis"), ("eczema", "eczema"),
    ("skin asthma", "eczema"), ("atopic dermatitis", "atopic dermatitis"),
]:
    add(t, e, "condition")

# ── NEURO / MENTAL HEALTH ────────────────────────────────────────────────────
for t, e in [
    ("sakit ulo", "headache"), ("labad ulo", "headache"), ("labad ang ulo", "headache"),
    ("headache", "headache"), ("migraine", "migraine"), ("epilepsy", "epilepsy"),
    ("seizure", "seizure"), ("convulsion", "seizure"), ("kombulsyon", "seizure"),
    ("stroke", "stroke"), ("parkinson", "parkinson disease"),
    ("alzheimer", "alzheimer disease"), ("dementia", "dementia"),
    ("depression", "depression"), ("depresyon", "depression"),
    ("anxiety", "anxiety disorder"), ("kabalis", "anxiety disorder"),
    ("stress", "anxiety disorder"), ("mental health", "mental health disorder"),
    ("bipolar", "bipolar disorder"), ("schizophrenia", "schizophrenia"),
]:
    add(t, e, "condition")

# ── SKIN / EYE / ENT / OTHER CONDITIONS ─────────────────────────────────────
for t, e in [
    ("hubag", "swelling"), ("namamaga", "swelling"), ("swelling", "swelling"),
    ("katol", "itching"), ("katol-katol", "itching"), ("itching", "itching"),
    ("itchy", "itching"), ("rashes", "rash"), ("rash", "rash"), ("bungang araw", "rash"),
    ("allergy sa panit", "contact dermatitis"), ("skin allergy", "contact dermatitis"),
    ("acne", "acne"), ("tagihawat", "acne"), ("pimples", "acne"),
    ("fungal infection", "fungal infection"), ("alipunga", "fungal infection"),
    ("athlete foot", "tinea pedis"), ("buni", "ringworm"),
    ("scabies", "scabies"), ("galis", "scabies"), ("kuto", "lice infestation"),
    ("sore eyes", "conjunctivitis"), ("pink eye", "conjunctivitis"),
    ("red eye", "conjunctivitis"), ("bulag", "vision impairment"),
    ("may problema sa mata", "eye disease"), ("sipon ug sakit ulo", "sinusitis"),
    ("sinusitis", "sinusitis"), ("tonsillitis", "tonsillitis"),
    ("sakit tutunlan", "sore throat"), ("sore throat", "sore throat"),
    ("tonsilitis", "tonsillitis"), ("toothache", "dental pain"),
    ("sakit ngipon", "dental pain"), ("pregnancy", "pregnancy"),
    ("buntis", "pregnancy"), ("may anak sa tiyan", "pregnancy"),
    ("hypertension sa buntis", "preeclampsia"), ("preeclampsia", "preeclampsia"),
    ("obesity", "obesity"), ("sobra sa timbang", "obesity"), ("overweight", "obesity"),
    ("malnourished", "malnutrition"), ("kulang kaon", "malnutrition"),
    ("vitamin deficiency", "vitamin deficiency"), ("anemic", "anemia"),
]:
    add(t, e, "condition")

# ── FOOD ALLERGIES (Hiligaynon / Taglish / colloquial) ───────────────────────
for t, e in [
    ("allergy sa pagkaon", "food allergy"), ("food allergy", "food allergy"),
    ("pagkaon allergy", "food allergy"), ("allergic sa pagkaon", "food allergy"),
    ("allergy sa mani", "peanut allergy"), ("mani allergy", "peanut allergy"),
    ("peanut allergy", "peanut allergy"), ("allergy sa peanuts", "peanut allergy"),
    ("allergy sa utanan", "tree nut allergy"), ("tree nut allergy", "tree nut allergy"),
    ("allergy sa gatas", "milk allergy"), ("allergy sa milk", "milk allergy"),
    ("milk allergy", "milk allergy"), ("dairy allergy", "milk allergy"),
    ("lactose intolerant", "lactose intolerance"), ("hindi tolerante sa gatas", "lactose intolerance"),
    ("allergy sa itlog", "egg allergy"), ("egg allergy", "egg allergy"),
    ("allergy sa egg", "egg allergy"), ("allergy sa wheat", "wheat allergy"),
    ("gluten allergy", "gluten allergy"), ("allergy sa gluten", "gluten allergy"),
    ("allergy sa soy", "soy allergy"), ("soy allergy", "soy allergy"),
    ("allergy sa isda", "fish allergy"), ("fish allergy", "fish allergy"),
    ("allergy sa seafood", "shellfish allergy"), ("seafood allergy", "shellfish allergy"),
    ("shellfish allergy", "shellfish allergy"), ("allergy sa uyap", "shrimp allergy"),
    ("allergy sa hipon", "shrimp allergy"), ("shrimp allergy", "shrimp allergy"),
    ("allergy sa alimango", "crab allergy"), ("crab allergy", "crab allergy"),
    ("allergy sa talaba", "shellfish allergy"), ("allergy sa tahong", "shellfish allergy"),
    ("allergy sa pusit", "shellfish allergy"), ("allergy sa squid", "shellfish allergy"),
    ("allergy sa lamian", "shellfish allergy"), ("lamian allergy", "shellfish allergy"),
    ("allergy sa karne", "meat allergy"), ("allergy sa manok", "poultry allergy"),
    ("allergy sa chicken", "poultry allergy"), ("allergy sa baboy", "pork allergy"),
    ("allergy sa beef", "beef allergy"), ("allergy sa prutas", "fruit allergy"),
    ("allergy sa saging", "banana allergy"), ("banana allergy", "banana allergy"),
    ("allergy sa mangga", "fruit allergy"), ("allergy sa strawberry", "strawberry allergy"),
    ("allergy sa peanut butter", "peanut allergy"), ("allergy sa chocolate", "food allergy"),
    ("allergy sa gabi", "taro allergy"), ("allergy sa coconut", "coconut allergy"),
    ("niyog allergy", "coconut allergy"), ("allergy sa sesame", "sesame allergy"),
    ("allergy sa mustard", "mustard allergy"), ("allergy sa corn", "corn allergy"),
    ("allergy sa rice", "rice allergy"), ("allergy sa MSG", "MSG sensitivity"),
    ("allergy sa seasoning", "food allergy"), ("allergy sa shrimp paste", "shellfish allergy"),
    ("allergy sa bagoong", "shellfish allergy"), ("bagoong allergy", "shellfish allergy"),
    ("allergy sa patis", "fish allergy"), ("patis allergy", "fish allergy"),
]:
    add(t, e, "allergy")

# ── DRUG / MEDICATION ALLERGIES ─────────────────────────────────────────────
for t, e in [
    ("drug allergy", "drug allergy"), ("allergy sa bulong", "drug allergy"),
    ("allergy sa tambal", "drug allergy"), ("tambal allergy", "drug allergy"),
    ("medicine allergy", "drug allergy"), ("allergy sa medicine", "drug allergy"),
    ("allergy sa penicillin", "penicillin allergy"), ("penicillin allergy", "penicillin allergy"),
    ("allergy sa amoxicillin", "amoxicillin allergy"), ("amoxicillin allergy", "amoxicillin allergy"),
    ("augmentin allergy", "amoxicillin allergy"), ("allergy sa antibiotic", "antibiotic allergy"),
    ("antibiotic allergy", "antibiotic allergy"), ("allergy sa sulfa", "sulfa allergy"),
    ("sulfa allergy", "sulfa allergy"), ("allergy sa aspirin", "aspirin allergy"),
    ("aspirin allergy", "aspirin allergy"), ("allergy sa painkiller", "NSAID allergy"),
    ("ibuprofen allergy", "ibuprofen allergy"), ("allergy sa biogesic", "acetaminophen sensitivity"),
    ("allergy sa paracetamol", "acetaminophen sensitivity"),
    ("allergy sa mefenamic", "NSAID allergy"), ("allergy sa NSAID", "NSAID allergy"),
    ("allergy sa codeine", "opioid allergy"), ("allergy sa morphine", "opioid allergy"),
    ("allergy sa anesthesia", "anesthesia allergy"), ("allergy sa lidocaine", "local anesthetic allergy"),
    ("allergy sa vaccine", "vaccine allergy"), ("allergy sa flu vaccine", "vaccine allergy"),
    ("allergy sa tetanus", "vaccine allergy"), ("allergy sa contrast", "contrast dye allergy"),
    ("contrast allergy", "contrast dye allergy"), ("iodine allergy", "iodine allergy"),
    ("allergy sa iodine", "iodine allergy"), ("allergy sa anesthesia", "anesthesia allergy"),
    ("allergy sa eye drops", "medication allergy"), ("allergy sa ointment", "medication allergy"),
    ("allergy sa neomycin", "neomycin allergy"), ("neomycin allergy", "neomycin allergy"),
    ("allergy sa topical", "medication allergy"),
]:
    add(t, e, "allergy")

# ── ENVIRONMENTAL / INSECT ALLERGIES ──────────────────────────────────────────
for t, e in [
    ("allergy sa pollen", "pollen allergy"), ("pollen allergy", "pollen allergy"),
    ("allergy sa hangin", "environmental allergy"), ("allergy sa dust", "dust mite allergy"),
    ("dust allergy", "dust mite allergy"), ("dust mite allergy", "dust mite allergy"),
    ("allergy sa alikabok", "dust mite allergy"), ("alikabok allergy", "dust mite allergy"),
    ("allergy sa mold", "mold allergy"), ("mold allergy", "mold allergy"),
    ("allergy sa uban", "mold allergy"), ("allergy sa amag", "mold allergy"),
    ("allergy sa cat", "cat dander allergy"), ("cat allergy", "cat dander allergy"),
    ("allergy sa iro", "dog dander allergy"), ("dog allergy", "dog dander allergy"),
    ("pet allergy", "pet dander allergy"), ("allergy sa hayop", "animal dander allergy"),
    ("allergy sa manok feather", "feather allergy"), ("feather allergy", "feather allergy"),
    ("allergy sa pollen grass", "grass pollen allergy"), ("ragweed allergy", "ragweed allergy"),
    ("allergy sa bulak", "pollen allergy"), ("seasonal allergy", "seasonal allergic rhinitis"),
    ("allergy sa panahon", "seasonal allergic rhinitis"), ("hay fever", "seasonal allergic rhinitis"),
    ("allergy sa usok", "smoke sensitivity"), ("smoke allergy", "smoke sensitivity"),
    ("allergy sa sigarilyo", "tobacco smoke sensitivity"),
    ("allergy sa perfume", "fragrance sensitivity"), ("perfume allergy", "fragrance sensitivity"),
    ("allergy sa chemical", "chemical allergy"), ("allergy sa sabon", "soap sensitivity"),
    ("allergy sa detergent", "detergent sensitivity"), ("allergy sa bleach", "chemical allergy"),
    ("allergy sa latex", "latex allergy"), ("latex allergy", "latex allergy"),
    ("allergy sa gloves", "latex allergy"), ("allergy sa rubber", "latex allergy"),
    ("allergy sa bee", "bee sting allergy"), ("bee sting allergy", "bee sting allergy"),
    ("allergy sa buyo-buyo", "bee sting allergy"), ("allergy sa wasp", "wasp sting allergy"),
    ("wasp allergy", "wasp sting allergy"), ("allergy sa hornet", "wasp sting allergy"),
    ("allergy sa ant", "fire ant sting allergy"), ("fire ant allergy", "fire ant sting allergy"),
    ("allergy sa mosquito", "mosquito bite allergy"), ("allergy sa lamok", "mosquito bite allergy"),
    ("insect bite allergy", "insect sting allergy"), ("allergy sa cockroach", "cockroach allergen"),
]:
    add(t, e, "allergy")

# ── ANAPHYLAXIS / GENERAL ALLERGY EXPRESSIONS ───────────────────────────────
for t, e in [
    ("allergy", "allergy"), ("allergies", "allergy"), ("may allergy", "allergy"),
    ("allergic", "allergy"), ("allergic reaction", "allergic reaction"),
    ("severe allergy", "severe allergy"), ("anaphylaxis", "anaphylaxis"),
    ("anaphylactic", "anaphylaxis"), ("shock allergy", "anaphylaxis"),
    ("allergy attack", "allergic reaction"), ("food intolerance", "food intolerance"),
    ("walang allergy", "no known allergies"), ("wala allergy", "no known allergies"),
    ("no allergy", "no known allergies"), ("none allergy", "no known allergies"),
    ("no known allergy", "no known allergies"), ("NKDA", "no known drug allergy"),
    ("walang kilalang allergy", "no known allergies"),
]:
    add(t, e, "allergy")

# ── MISSPELLINGS & INFORMAL (conditions) ──────────────────────────────────────
for t, e in [
    ("diabetis type 2", "type 2 diabetes"), ("diabetis type2", "type 2 diabetes"),
    ("hypertention", "hypertension"), ("hypertnsion", "hypertension"),
    ("asthma attack", "asthma"), ("asma attack", "asthma"),
    ("pneumuna", "pneumonia"), ("pnemonia", "pneumonia"),
    ("tuberculoses", "tuberculosis"), ("tuberclosis", "tuberculosis"),
    ("hepatites", "hepatitis"), ("hepatitus", "hepatitis"),
    ("kidny problem", "kidney disease"), ("kidneystone", "kidney stones"),
    ("canser", "cancer"), ("kansir", "cancer"), ("tumur", "cancer"),
    ("arthiritis", "arthritis"), ("arthitis", "arthritis"),
    ("depresson", "depression"), ("anxity", "anxiety disorder"),
    ("highblood pressure", "hypertension"), ("bloodsugar", "diabetes"),
    ("sugar diabetes", "diabetes"), ("sugur", "diabetes"),
    ("heart prob", "heart disease"), ("heartprob", "heart disease"),
    ("kidney prob", "kidney disease"), ("liver prob", "liver disease"),
    ("skin prob", "skin disease"), ("lung prob", "lung disease"),
    ("may tb", "tuberculosis"), ("may dengue", "dengue"),
    ("may asma", "asthma"), ("may ubo", "cough"),
    ("may hilanat", "fever"), ("may sipon", "runny nose"),
    ("may sakit", "illness"), ("ginasakit", "pain"), ("masakit", "pain"),
    ("sakit lawas", "body pain"), ("body pain", "body pain"),
    ("back pain", "back pain"), ("sakit likod", "back pain"),
    ("joint pain", "joint pain"), ("sakit sendi", "joint pain"),
    ("muscle pain", "muscle pain"), ("sakit kaunuran", "muscle pain"),
    ("fatigue", "fatigue"), ("kakapoy", "fatigue"), ("ginakapoy", "fatigue"),
    ("weakness", "fatigue"), ("kapoy", "fatigue"), ("weak", "fatigue"),
    ("insomnia", "insomnia"), ("dili makatulog", "insomnia"),
    ("high blood sugar", "diabetes"), ("low blood sugar", "hypoglycemia"),
    ("hypoglycemia", "hypoglycemia"), ("mababang asukal", "hypoglycemia"),
]:
    add(t, e, "condition")

# ── MISSPELLINGS (allergies) ──────────────────────────────────────────────────
for t, e in [
    ("alergy", "allergy"), ("alergies", "allergy"), ("alergic", "allergy"),
    ("alergy sa pagkaon", "food allergy"), ("alergy sa mani", "peanut allergy"),
    ("alergy sa seafood", "shellfish allergy"), ("alergy sa penicilin", "penicillin allergy"),
    ("penicilin allergy", "penicillin allergy"), ("alergy sa dust", "dust mite allergy"),
    ("seafood alergy", "shellfish allergy"), ("peanut alergy", "peanut allergy"),
    ("shelfish allergy", "shellfish allergy"), ("shrim allergy", "shrimp allergy"),
]:
    add(t, e, "allergy")

# ── TAGLISH / MIXED PHRASES (common in clinics) ───────────────────────────────
for t, e in [
    ("may diabetes ako", "diabetes"), ("may hypertension ako", "hypertension"),
    ("may asthma ako", "asthma"), ("may allergy ako sa", "allergy"),
    ("allergic ako sa", "allergy"), ("diagnosed with diabetes", "diabetes"),
    ("diagnosed with hypertension", "hypertension"), ("family history diabetes", "diabetes"),
    ("family history hypertension", "hypertension"), ("FH diabetes", "diabetes"),
    ("FH hypertension", "hypertension"), ("maintenance meds", "chronic disease"),
    ("maintenance medicine", "chronic disease"), ("maintenance", "chronic disease"),
    ("chronic illness", "chronic disease"), ("may chronic", "chronic disease"),
    ("comorbid", "comorbidity"), ("multiple conditions", "comorbidity"),
    ("bp problem", "hypertension"), ("sugar problem", "diabetes"),
    ("breathing problem", "respiratory disease"), ("lung problem", "lung disease"),
    ("skin problem", "skin disease"), ("kidney failure", "kidney failure"),
    ("on dialysis", "kidney failure"), ("dialysis", "kidney failure"),
    ("hepa b", "hepatitis b"), ("hepa c", "hepatitis c"), ("hepa", "hepatitis"),
    ("amoebiasis", "amoebiasis"), ("worms", "parasitic infection"),
    ("intestinal parasite", "parasitic infection"), ("ulcer sa tiyan", "peptic ulcer"),
    ("gallstone", "gallstones"), ("bato sa apdo", "gallstones"),
    ("appendicitis", "appendicitis"), ("appendix", "appendicitis"),
    ("hernia", "hernia"), ("bukol", "mass"), ("lump", "mass"), ("may bukol", "mass"),
    ("varicose", "varicose veins"), ("varicose veins", "varicose veins"),
    ("hemorrhoids", "hemorrhoids"), ("almoranas", "hemorrhoids"),
    ("thyroid cancer", "thyroid cancer"), ("goiter", "goiter"),
]:
    add(t, e, "condition")

for t, e in [
    ("allergic ako sa seafood", "shellfish allergy"),
    ("allergic ako sa penicillin", "penicillin allergy"),
    ("allergic ako sa dust", "dust mite allergy"),
    ("allergic ako sa milk", "milk allergy"),
    ("allergic ako sa peanuts", "peanut allergy"),
    ("allergic ako sa shrimp", "shrimp allergy"),
    ("allergic ako sa eggs", "egg allergy"),
    ("allergic ako sa sulfa drugs", "sulfa allergy"),
]:
    add(t, e, "allergy")

# ── EXPAND: Ilonggo/Hiligaynon disease names (research-based common terms) ──
ILONGGO_CONDITIONS = [
    ("balatian", "skin disease"), ("balatian sa panit", "skin disease"),
    ("sakit nga balat", "skin disease"), ("panit nagakati", "itching"),
    ("kati-kati", "itching"), ("ginakati", "itching"),
    ("hubag-hubag", "urticaria"), ("pantal", "rash"), ("bungang araw", "heat rash"),
    ("init", "fever"), ("ginainitan", "fever"),
    ("ginatulo", "diarrhea"), ("tulo-tulo", "diarrhea"),
    ("ginabalian", "fracture"), ("bali", "fracture"),
    ("pilay", "paralysis"), ("panghal", "stroke"),
    ("uyab", "hypertension"), ("init nga dugo", "hypertension"),
    ("tamad lawas", "fatigue"), ("ginatamad", "fatigue"),
    ("gahi ang liog", "cervical spondylosis"), ("rayuma sa liog", "cervical spondylosis"),
    ("sakit sa buko-buko", "back pain"), ("sakit buko-buko", "back pain"),
    ("sakit sa tuhod", "knee pain"), ("sakit tuhod", "osteoarthritis"),
    ("sakit sa buko", "joint pain"), ("rayuma sa kamot", "arthritis"),
    ("rayuma sa tiil", "arthritis"), ("rayuma sa lawas", "arthritis"),
    ("hubak", "fracture"), ("may hubak", "fracture"), ("bali", "fracture"),
    ("ginahubag ang tiyan", "abdominal swelling"), ("bilog tiyan", "abdominal distension"),
    ("sakit sa baga", "lung disease"), ("sakit baga", "lung disease"),
    ("ginauubo sing malala", "severe cough"), ("ubo sing dugo", "hemoptysis"),
    ("dugo sa ubo", "hemoptysis"), ("ginadugo ang ubo", "hemoptysis"),
    ("ginahilanat sing taas", "high fever"), ("hilanat sing taas", "high fever"),
    ("ginatulo sing dugo", "bloody diarrhea"), ("dugo sa tae", "bloody stool"),
    ("sakit sa lawas", "general illness"), ("ginamasakit ang lawas", "body pain"),
    ("huyang lawas", "weakness"), ("ginahuyang", "weakness"),
    ("dili maayo ang lawas", "illness"), ("masakit ang lawas", "body pain"),
    ("sakit sa ulo sing malala", "severe headache"), ("labad ulo sing malala", "severe headache"),
    ("nahihilo", "dizziness"), ("nahilo", "dizziness"), ("nahihilo ako", "dizziness"),
    ("dili makatulog", "insomnia"), ("insomnia", "insomnia"),
    ("sakit sa tiyan pagkaon", "gastritis"), ("masakit tiyan pagkaon", "gastritis"),
    ("ginasuka sing malala", "severe vomiting"), ("dili makaginhawa", "difficulty breathing"),
    ("ginapunaw", "shortness of breath"), ("punaw", "shortness of breath"),
    ("ginahingabot", "shortness of breath"), ("hingabot", "shortness of breath"),
    ("asma sa bata", "asthma"), ("childhood asthma", "asthma"),
    ("chickenpox", "chickenpox"), ("bulutong", "chickenpox"), ("bulutong-tubig", "chickenpox"),
    ("tigdas", "measles"), ("measles", "measles"), ("rubella", "rubella"),
    ("hepatitis a", "hepatitis a"), ("yellow jaundice", "jaundice"),
    ("sakit sa bato", "kidney stones"), ("kidney stone", "kidney stones"),
    ("sakit sa pantog", "bladder disease"), ("bladder infection", "urinary tract infection"),
    ("prostate", "prostate disease"), ("prostate problem", "prostate disease"),
    ("uric acid", "gout"), ("mataas uric acid", "gout"), ("high uric acid", "gout"),
]
for t, e in ILONGGO_CONDITIONS:
    add(t, e, "condition")

ILONGGO_ALLERGIES = [
    ("allergy sa lamian", "shellfish allergy"), ("allergy sa dagat", "seafood allergy"),
    ("allergy sa isda", "fish allergy"), ("allergy sa karagatan", "seafood allergy"),
    ("allergy sa baybay", "seafood allergy"), ("allergy sa uyap", "shrimp allergy"),
    ("allergy sa swaki", "shellfish allergy"), ("allergy sa talaba", "shellfish allergy"),
    ("allergy sa tahong", "shellfish allergy"), ("allergy sa alimango", "crab allergy"),
    ("allergy sa gatas sang baka", "milk allergy"), ("allergy sa itlog manok", "egg allergy"),
    ("allergy sa mani", "peanut allergy"), ("allergy sa mais", "corn allergy"),
    ("allergy sa bugas", "wheat allergy"), ("allergy sa harina", "wheat allergy"),
    ("allergy sa liso", "soy allergy"), ("allergy sa toyo", "soy allergy"),
    ("allergy sa patis", "fish allergy"), ("allergy sa bagoong", "shellfish allergy"),
    ("allergy sa ginamos", "shellfish allergy"), ("allergy sa bulong", "drug allergy"),
    ("allergy sa tambal nga antibiotic", "antibiotic allergy"),
    ("allergy sa tambal nga aspirin", "aspirin allergy"),
    ("allergy sa sulfa drug", "sulfa allergy"), ("allergy sa dust sa balay", "dust mite allergy"),
    ("allergy sa alikabok sa balay", "dust mite allergy"),
    ("allergy sa iro sa balay", "dog dander allergy"),
    ("allergy sa iring", "cat dander allergy"), ("allergy sa kanding", "animal dander allergy"),
    ("allergy sa buyo", "bee sting allergy"), ("allergy sa putyukan", "wasp sting allergy"),
    ("allergy sa lamok bite", "mosquito bite allergy"),
    ("ginakati tungod allergy", "allergic reaction"), ("ginahubag tungod allergy", "allergic reaction"),
    ("ginapantal", "rash"), ("allergy rash", "allergic reaction"),
]
for t, e in ILONGGO_ALLERGIES:
    add(t, e, "allergy")

# ── Synonym variants (same english, multiple local) ─────────────────────────
EXTRA_CONDITIONS = [
    ("pulmonary disease", "lung disease"), ("respiratory disease", "lung disease"),
    ("cardiac disease", "heart disease"), ("coronary disease", "coronary artery disease"),
    ("valve problem", "heart valve disease"), ("heart failure", "heart failure"),
    ("chf", "heart failure"), ("congestive heart failure", "heart failure"),
    ("afib", "atrial fibrillation"), ("atrial fibrillation", "atrial fibrillation"),
    ("blood clot", "thrombosis"), ("clot", "thrombosis"), ("dvt", "deep vein thrombosis"),
    ("embolism", "pulmonary embolism"), ("anemia", "anemia"), ("bleeding disorder", "bleeding disorder"),
    ("hemophilia", "hemophilia"), ("sickle cell", "sickle cell disease"),
    ("thalassemia", "thalassemia"), ("vitamin d low", "vitamin d deficiency"),
    ("b12 deficiency", "vitamin b12 deficiency"), ("iron low", "iron deficiency anemia"),
    ("osteoporosis", "osteoporosis"), ("weak bones", "osteoporosis"),
    ("fibromyalgia", "fibromyalgia"), ("chronic pain", "chronic pain syndrome"),
    ("migraine headache", "migraine"), ("cluster headache", "cluster headache"),
    ("tension headache", "tension headache"), ("sinus headache", "sinusitis"),
    ("glaucoma", "glaucoma"), ("cataract", "cataracts"), ("retina problem", "retinal disease"),
    ("ear infection", "otitis media"), ("ear pain", "otitis media"),
    ("nose bleed", "epistaxis"), ("bleeding nose", "epistaxis"),
    ("tonsils", "tonsillitis"), ("adenoid", "adenoid hypertrophy"),
    ("hiv", "hiv/aids"), ("aids", "hiv/aids"), ("h.i.v.", "hiv/aids"),
    ("std", "sexually transmitted infection"), ("sti", "sexually transmitted infection"),
    ("herpes", "herpes"), ("herpes simplex", "herpes"),
    ("shingles", "herpes zoster"), ("herpes zoster", "herpes zoster"),
    ("warts", "viral wart"), ("fungal", "fungal infection"), ("yeast infection", "candidiasis"),
    ("urinary infection", "urinary tract infection"), ("bladder infection", "urinary tract infection"),
    ("kidney infection", "pyelonephritis"), ("stones", "urolithiasis"),
    ("gallbladder", "gallbladder disease"), ("pancreas", "pancreatitis"),
    ("pancreatitis", "pancreatitis"), ("ibs", "irritable bowel syndrome"),
    ("irritable bowel", "irritable bowel syndrome"), ("crohn", "crohn disease"),
    ("colitis", "ulcerative colitis"), ("celiac", "celiac disease"),
    ("lactose", "lactose intolerance"), ("food poisoning", "food poisoning"),
    ("gastro", "gastroenteritis"), ("stomach flu", "gastroenteritis"),
    ("dehydration", "dehydration"), ("heat stroke", "heat stroke"),
    ("heat exhaustion", "heat exhaustion"), ("sunburn", "sunburn"),
    ("burn", "burn injury"), ("wound infection", "wound infection"),
    ("cellulitis", "cellulitis"), ("abscess", "abscess"), ("boil", "skin abscess"),
]
for t, e in EXTRA_CONDITIONS:
    add(t, e, "condition")

EXTRA_ALLERGIES = [
    ("nut allergy", "tree nut allergy"), ("almond allergy", "tree nut allergy"),
    ("walnut allergy", "tree nut allergy"), ("cashew allergy", "tree nut allergy"),
    ("hazelnut allergy", "tree nut allergy"), ("pistachio allergy", "tree nut allergy"),
    ("macadamia allergy", "tree nut allergy"), ("chestnut allergy", "tree nut allergy"),
    ("sesame allergy", "sesame allergy"), ("celery allergy", "celery allergy"),
    ("kiwi allergy", "kiwi allergy"), ("avocado allergy", "avocado allergy"),
    ("tomato allergy", "tomato allergy"), ("potato allergy", "potato allergy"),
    ("carrot allergy", "carrot allergy"), ("apple allergy", "apple allergy"),
    ("peach allergy", "peach allergy"), ("mango allergy", "mango allergy"),
    ("pineapple allergy", "pineapple allergy"), ("citrus allergy", "citrus allergy"),
    ("orange allergy", "citrus allergy"), ("chocolate allergy", "food allergy"),
    ("cocoa allergy", "food allergy"), ("coffee allergy", "food allergy"),
    ("tea allergy", "food allergy"), ("honey allergy", "food allergy"),
    ("yeast allergy", "yeast allergy"), ("wine allergy", "sulfite sensitivity"),
    ("beer allergy", "food allergy"), ("food additive allergy", "food allergy"),
    ("preservative allergy", "food allergy"), ("red dye allergy", "food coloring allergy"),
    ("yellow dye allergy", "food coloring allergy"), ("msg allergy", "MSG sensitivity"),
    ("sulfite allergy", "sulfite sensitivity"), ("histamine intolerance", "histamine intolerance"),
    ("oral allergy syndrome", "oral allergy syndrome"), ("pollen food syndrome", "oral allergy syndrome"),
    ("nickel allergy", "nickel allergy"), ("metal allergy", "metal allergy"),
    ("jewelry allergy", "nickel allergy"), ("belt buckle allergy", "nickel allergy"),
    ("hair dye allergy", "contact dermatitis"), ("ppd allergy", "contact dermatitis"),
    ("sunscreen allergy", "contact dermatitis"), ("adhesive allergy", "contact dermatitis"),
    ("bandaid allergy", "contact dermatitis"), ("tape allergy", "contact dermatitis"),
    ("chlorine allergy", "chemical sensitivity"), ("pool chlorine", "chemical sensitivity"),
    ("formaldehyde allergy", "formaldehyde sensitivity"),
]
for t, e in EXTRA_ALLERGIES:
    add(t, e, "allergy")

# ── BULK EXPANSION: archive disease names + local variants ─────────────────
ARCHIVE_DISEASES = [
    "Fungal infection", "Drug Reaction", "Malaria", "Allergy", "Hypothyroidism",
    "Psoriasis", "GERD", "Chronic cholestasis", "hepatitis A", "Osteoarthristis",
    "Hypoglycemia", "Acne", "Diabetes", "Impetigo", "Hypertension",
    "Peptic ulcer disease", "Pneumonia", "Dimorphic hemmorhoids(piles)", "Heart attack",
    "Varicose veins", "Hypothyroidism", "Hyperthyroidism", "Hypoglycemia",
    "Osteoporosis", "Arthritis", "Vertigo", "Jaundice", "Gastroenteritis",
]
for d in ARCHIVE_DISEASES:
    add(d.lower(), d, "condition")
    add(d.replace(" ", ""), d, "condition")

TAGALOG_CONDITIONS = [
    ("altapresyon ko", "hypertension"), ("mataas na presyon ko", "hypertension"),
    ("may alta presyon ako", "hypertension"), ("may diabetes ako", "diabetes"),
    ("may asma ako", "asthma"), ("may tb ako", "tuberculosis"),
    ("may sakit sa puso ako", "heart disease"), ("may cancer ako", "cancer"),
    ("may sakit sa bato ako", "kidney stones"), ("may ulcer ako", "peptic ulcer"),
    ("may goiter ako", "goiter"), ("may rayuma ako", "arthritis"),
    ("may hika ako", "asthma"), ("may sipon ako", "runny nose"),
    ("may ubo ako", "cough"), ("may lagnat ako", "fever"),
    ("may hilanat ako", "fever"), ("may dengue ako", "dengue"),
    ("may trangkaso ako", "influenza"), ("may pneumonia ako", "pneumonia"),
    ("may hepatitis ako", "hepatitis"), ("may anemia ako", "anemia"),
    ("may depression ako", "depression"), ("may anxiety ako", "anxiety disorder"),
    ("sakit sa puso ko", "heart disease"), ("sakit sa kidney ko", "kidney disease"),
    ("sakit sa liver ko", "liver disease"), ("sakit sa tiyan ko", "stomach pain"),
    ("sakit sa ulo ko", "headache"), ("masakit ang ulo ko", "headache"),
    ("masakit ang tiyan ko", "stomach pain"), ("masakit ang dibdib ko", "chest pain"),
    ("hirap huminga", "difficulty breathing"), ("nahihirapang huminga", "difficulty breathing"),
    ("hingal", "shortness of breath"), ("hingal na hingal", "shortness of breath"),
    ("binat", "relapse"), ("naulan", "rain exposure illness"),
    ("init ng katawan", "fever"), ("masakit katawan", "body pain"),
    ("banat", "muscle strain"), ("pulikat", "muscle cramp"),
    ("rayuma ng tuhod", "osteoarthritis"), ("rayuma ng kamay", "arthritis"),
    ("rayuma ng balikat", "arthritis"), ("rayuma ng leeg", "cervical spondylosis"),
    ("tulo", "diarrhea"), ("tatae ng madalas", "diarrhea"),
    ("sumuka", "vomiting"), ("nagsusuka", "vomiting"), ("nahihilo ako", "dizziness"),
    ("nahilo ako", "dizziness"), ("pangingilo", "tooth sensitivity"),
    ("singaw", "mouth ulcer"), ("singaw sa bibig", "mouth ulcer"),
    ("pigsa", "boil"), ("bukol sa katawan", "mass"), ("bukol sa suso", "breast mass"),
    ("bukol sa leeg", "neck mass"), ("bukol sa tiyan", "abdominal mass"),
    ("taghiyawat", "acne"), ("tigyawat", "acne"), ("butlig", "acne"),
    ("galis", "scabies"), ("kati sa balat", "itching"), ("pamamantal", "rash"),
    ("panu", "tinea versicolor"), ("an-an", "tinea versicolor"),
    ("buni sa balat", "ringworm"), ("alipunga sa paa", "tinea pedis"),
    ("sugat", "wound"), ("sugat sa paa", "leg wound"), ("di gumagaling sugat", "chronic wound"),
    ("namamaga paa", "leg swelling"), ("namamaga mukha", "facial swelling"),
    ("namamaga tiyan", "abdominal swelling"), ("manas", "edema"),
    ("manas sa paa", "leg edema"), ("manas sa kamay", "hand edema"),
    ("mataba", "obesity"), ("sobrang taba", "obesity"), ("kulang sa timbang", "underweight"),
    ("kulang sa tulog", "insomnia"), ("puyat", "sleep deprivation"),
    ("high blood ko", "hypertension"), ("sugar ko mataas", "diabetes"),
    ("maintenance ko", "chronic disease"), ("gamot sa alta presyon", "hypertension"),
    ("gamot sa diabetes", "diabetes"), ("gamot sa asma", "asthma"),
]
for t, e in TAGALOG_CONDITIONS:
    add(t, e, "condition")

TAGALOG_ALLERGIES = [
    ("allergic sa pagkain", "food allergy"), ("allergic sa mani", "peanut allergy"),
    ("allergic sa hipon", "shrimp allergy"), ("allergic sa alimango", "crab allergy"),
    ("allergic sa isda", "fish allergy"), ("allergic sa lamang-dagat", "seafood allergy"),
    ("allergic sa gatas", "milk allergy"), ("allergic sa itlog", "egg allergy"),
    ("allergic sa tinapay", "wheat allergy"), ("allergic sa harina", "wheat allergy"),
    ("allergic sa manok", "poultry allergy"), ("allergic sa baboy", "pork allergy"),
    ("allergic sa prutas", "fruit allergy"), ("allergic sa saging", "banana allergy"),
    ("allergic sa penicillin", "penicillin allergy"), ("allergic sa antibiotic", "antibiotic allergy"),
    ("allergic sa gamot", "drug allergy"), ("allergic sa biogesic", "acetaminophen sensitivity"),
    ("allergic sa mefenamic", "NSAID allergy"), ("allergic sa aspirin", "aspirin allergy"),
    ("allergic sa sulfa", "sulfa allergy"), ("allergic sa dust", "dust mite allergy"),
    ("allergic sa alikabok", "dust mite allergy"), ("allergic sa pollen", "pollen allergy"),
    ("allergic sa pusa", "cat dander allergy"), ("allergic sa aso", "dog dander allergy"),
    ("allergic sa latex", "latex allergy"), ("allergic sa rubber", "latex allergy"),
    ("allergic sa perfume", "fragrance sensitivity"), ("allergic sa sabon", "soap sensitivity"),
    ("allergic sa lotion", "contact dermatitis"), ("allergic sa detergent", "detergent sensitivity"),
    ("allergic sa putik", "contact dermatitis"), ("allergic sa grass", "grass pollen allergy"),
    ("allergic sa bulakla", "pollen allergy"), ("allergic sa amag", "mold allergy"),
    ("allergic sa usok", "smoke sensitivity"), ("allergic sa sigarilyo", "tobacco smoke sensitivity"),
    ("allergic sa lamok", "mosquito bite allergy"), ("allergic sa ipis", "cockroach allergen"),
    ("allergic sa bubuyog", "wasp sting allergy"), ("allergic sa bubuyog", "bee sting allergy"),
    ("allergy sa gamot na ito", "drug allergy"), ("hindi pwede penicillin", "penicillin allergy"),
    ("bawal penicillin", "penicillin allergy"), ("bawal sa seafood", "shellfish allergy"),
    ("bawal sa mani", "peanut allergy"), ("bawal sa gatas", "milk allergy"),
    ("bawal sa hipon", "shrimp allergy"), ("bawal sa alimango", "crab allergy"),
    ("bawal sa isda", "fish allergy"), ("bawal sa itlog", "egg allergy"),
    ("bawal sa sulfa", "sulfa allergy"), ("bawal sa aspirin", "aspirin allergy"),
]
for t, e in TAGALOG_ALLERGIES:
    add(t, e, "allergy")

# Hiligaynon particles / informal (gin-, nag-, ma-)
HILIGAYNON_PREFIX_VARIANTS = [
    ("ginadiabetes", "diabetes"), ("ginahypertension", "hypertension"),
    ("ginasthma", "asthma"), ("ginatb", "tuberculosis"), ("ginacancer", "cancer"),
    ("ginakidney", "kidney disease"), ("ginadengue", "dengue"), ("ginamalaria", "malaria"),
    ("ginapneumonia", "pneumonia"), ("ginafever", "fever"), ("ginacough", "cough"),
    ("nagadiabetes", "diabetes"), ("nagahypertension", "hypertension"),
    ("may ginahilanat", "fever"), ("may ginauubo", "cough"), ("may ginakalibanga", "diarrhea"),
    ("wala allergy", "no known allergies"), ("wala sakit", "healthy"),
    ("walay allergy", "no known allergies"), ("walay sakit", "healthy"),
]
for t, e in HILIGAYNON_PREFIX_VARIANTS:
    cat = "allergy" if "allergy" in e.lower() or "allergy" in t.lower() else "condition"
    add(t, e, cat)

# Common clinic chart abbreviations patients say aloud
ABBREVS = [
    ("dm2", "type 2 diabetes"), ("dm1", "type 1 diabetes"), ("htn", "hypertension"),
    ("cad", "coronary artery disease"), ("chf", "heart failure"), ("ckd stage 3", "chronic kidney disease"),
    ("ckd stage 4", "chronic kidney disease"), ("esrd", "kidney failure"),
    ("copd", "chronic obstructive pulmonary disease"), ("osa", "sleep apnea"),
    ("gerd", "gastroesophageal reflux disease"), ("ibs", "irritable bowel syndrome"),
    ("ibd", "inflammatory bowel disease"), ("sle", "systemic lupus erythematosus"),
    ("ra", "rheumatoid arthritis"), ("oa", "osteoarthritis"), ("gerd", "gastroesophageal reflux disease"),
    ("ptb+", "tuberculosis"), ("ptb -", "tuberculosis"), ("hba1c high", "diabetes"),
]
for t, e in ABBREVS:
    add(t, e, "condition")

# Hiligaynon symptom expansion (registration / teleconsultation demo)
HILIGAYNON_SYMPTOM_EXPANSION = [
    ("kakatol lawas", "body aches"),
    ("sakit lawas", "body pain"),
    ("sakit ulo", "headache"),
    ("ginalain tiyan", "stomach pain"),
    ("ginahilanat", "fever"),
    ("ginapanuhot", "bloated stomach"),
    ("ginaubo", "cough"),
    ("ginasip-on", "runny nose"),
    ("ginaginhawa budlay", "difficulty breathing"),
    ("kapoy gid", "fatigue"),
    ("nalipong", "dizziness"),
    ("nagsuka", "vomiting"),
    ("gakalibanga", "diarrhea"),
    ("masakit tutunlan", "sore throat"),
    ("ginatugnaw", "chills"),
    ("gakadula gana kaon", "loss of appetite"),
]
for t, e in HILIGAYNON_SYMPTOM_EXPANSION:
    add(t, e, "condition")


def dedupe(entries: list[tuple[str, str, str]]) -> list[tuple[str, str, str]]:
    seen: set[tuple[str, str, str]] = set()
    out: list[tuple[str, str, str]] = []
    for local, english, cat in entries:
        key = (local.lower(), english.lower(), cat)
        if not local or key in seen:
            continue
        seen.add(key)
        out.append((local, english, cat))
    return out


def main() -> None:
    rows = dedupe(ENTRIES)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    with OUT.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(["dictionary_id", "local_term", "english_term", "category"])
        for i, (local, english, cat) in enumerate(rows, start=1):
            w.writerow([i, local, english, cat])

    cond = sum(1 for _, _, c in rows if c == "condition")
    allergy = sum(1 for _, _, c in rows if c == "allergy")
    print(f"Wrote {len(rows)} entries to {OUT}")
    print(f"  condition: {cond}")
    print(f"  allergy: {allergy}")


if __name__ == "__main__":
    main()
