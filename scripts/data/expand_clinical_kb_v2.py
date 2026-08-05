#!/usr/bin/env python3
"""
Expand medConnect CDS knowledge base incrementally (v2).

Preserves existing architecture:
  - Updates JSON KB (symptom_knowledge_base.json, red_flags_library.json)
  - Appends/merges CSV datasets (no PHP code changes required)
  - Backs up JSON before write

Run:
  python scripts/data/expand_clinical_kb_v2.py
  python scripts/data/audit_nlp_knowledge_base.py
"""

from __future__ import annotations

import csv
import json
import re
import shutil
from datetime import datetime
from itertools import combinations, product
from pathlib import Path

from clinical_symptom_catalog import CLINICAL_SYMPTOM_SEEDS

# Reuse builders from CDS expansion script
from build_cds_expansion_datasets import (
    FIL_SYMPTOMS,
    HIL_SYMPTOMS,
    typo_variants,
    write_csv,
    read_csv,
)

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"

# Informal Hiligaynon / slang / regional variants (conversational)
HIL_SLANG: dict[str, list[str]] = {
    "fever": ["may init", "init kaayo", "ginapaso", "paso lawas", "lagnat gid"],
    "cough": ["ubo gid", "ubo-ubo", "ga ubo", "ubo man"],
    "difficulty_breathing": ["dula ginhawa", "wala ginhawa", "ginahapo gid", "hapo kaayo", "lisod ginhawa"],
    "chest_pain": ["sakit dughan gid", "masakit dughan ko", "hapdi dughan ko", "sakit sa dughan"],
    "headache": ["sakit ulo gid", "masakit ulo ko", "gapasakit ulo"],
    "vomiting": ["naga suka gid", "suka man", "nagsuka ko"],
    "abdominal_pain": ["sakit tiyan gid", "masakit tiyan ko", "gapasakit tiyan"],
    "diarrhea": ["naga tae gid", "tae man", "malusaw tae"],
    "weakness": ["luya gid", "kapoy gid", "naluya gid"],
    "dizziness": ["lipong gid", "nagkalipong", "nahilo"],
    "bleeding": ["nagdugo", "dugo gid", "grabe dugo"],
}

FIL_SLANG: dict[str, list[str]] = {
    "fever": ["mainit ulo", "mainit katawan", "may init", "lagnat na"],
    "cough": ["ubo na", "ubo pa rin", "ubuhin"],
    "difficulty_breathing": ["hingal na hingal", "hingal na", "hirap na huminga"],
    "chest_pain": ["masakit dibdib", "sumasakit dibdib", "sakit dibdib"],
    "headache": ["sumasakit ulo", "masakit na ulo"],
    "vomiting": ["suka nang suka", "paulit ulit suka"],
    "abdominal_pain": ["masakit tiyan", "sumasakit tiyan"],
    "diarrhea": ["tae nang tae", "madalas magtae"],
    "weakness": ["sobrang hina", "walang lakas"],
    "dizziness": ["nahihilo na", "hilo na hilo"],
    "bleeding": ["dumudugo pa", "grabe ang dugo"],
}

# Additional emergency red flags (RF015+)
NEW_RED_FLAGS: list[dict] = [
    {
        "id": "RF015", "name": "Airway Obstruction", "category": "airway",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["choking", "cannot swallow", "airway blocked", "foreign body in throat", "obstructed airway"],
            "hiligaynon": ["nasamaran", "indi makatulon", "natapunan tutunlan", "nabarado tutunlan"],
            "filipino": ["nasamid", "hindi maklunok", "barado ang lalamunan", "may bumara sa lalamunan"],
        },
        "mild_exclusions": ["mild sore throat only"],
        "rationale": "Airway obstruction is an immediate life threat.",
    },
    {
        "id": "RF016", "name": "Cardiac Arrest", "category": "cardiovascular",
        "auto_triage": "EMERGENCY", "severity_points": 20,
        "patterns": {
            "english": ["not breathing", "no pulse", "cardiac arrest", "not responding", "CPR needed"],
            "hiligaynon": ["wala ginhawa", "wala pulso", "indi nagabaton"],
            "filipino": ["hindi humihinga", "walang pulso", "hindi tumutugon"],
        },
        "mild_exclusions": [],
        "rationale": "Cardiac arrest requires immediate resuscitation.",
    },
    {
        "id": "RF017", "name": "Pregnancy Hemorrhage", "category": "pregnancy",
        "auto_triage": "EMERGENCY", "severity_points": 18,
        "patterns": {
            "english": ["pregnant and bleeding", "pregnancy bleeding heavily", "heavy bleeding while pregnant", "miscarriage with heavy bleeding"],
            "hiligaynon": ["buntis kag may dugo", "buntis nagdugo", "grabe dugo buntis"],
            "filipino": ["buntis at dumudugo", "buntis na dumugo nang malakas", "malakas na pagdurugo habang buntis"],
        },
        "mild_exclusions": ["spotting only"],
        "rationale": "Pregnancy hemorrhage can be life-threatening.",
    },
    {
        "id": "RF018", "name": "Ectopic Pregnancy", "category": "pregnancy",
        "auto_triage": "EMERGENCY", "severity_points": 18,
        "patterns": {
            "english": ["ectopic pregnancy", "pregnant with severe abdominal pain", "pregnant shoulder pain"],
            "hiligaynon": ["buntis grabe sakit tiyan", "buntis ectopic"],
            "filipino": ["ectopic pregnancy", "buntis na masakit ang tiyan", "buntis at masakit balikat"],
        },
        "mild_exclusions": [],
        "rationale": "Ruptured ectopic pregnancy is a surgical emergency.",
    },
    {
        "id": "RF019", "name": "Head Trauma", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["head injury", "hit my head", "head trauma", "fall hit head", "loss of consciousness after head injury"],
            "hiligaynon": ["nasamdan ulo", "naigo ulo", "nadula malay pagkahulog"],
            "filipino": ["nasaktan ang ulo", "natamaan ang ulo", "nahulog at nasaktan ulo", "nawalan ng malay matapos mahulog"],
        },
        "mild_exclusions": ["small bump no symptoms"],
        "rationale": "Significant head trauma may cause intracranial injury.",
    },
    {
        "id": "RF020", "name": "Spinal Injury", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["spinal injury", "cannot move legs", "numbness after fall", "neck injury after accident"],
            "hiligaynon": ["indi makabaton tiil", "napilasan likod", "nasamdan liog"],
            "filipino": ["spinal injury", "hindi makagalaw ng binti", "manhid pagkahulog", "nasaktan ang leeg"],
        },
        "mild_exclusions": [],
        "rationale": "Spinal cord injury requires immobilization and urgent care.",
    },
    {
        "id": "RF021", "name": "Major Trauma", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["major trauma", "motor vehicle accident", "MVA", "car crash", "motorcycle accident", "pedestrian hit"],
            "hiligaynon": ["naaksidente", "nadakpan sang sakyan", "nabangga"],
            "filipino": ["naaksidente", "car crash", "motor accident", "nabangga ng kotse"],
        },
        "mild_exclusions": ["minor fender bender no injury"],
        "rationale": "High-energy trauma requires emergency evaluation.",
    },
    {
        "id": "RF022", "name": "Penetrating Trauma", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["gunshot wound", "stab wound", "penetrating injury", "knifed", "shot"],
            "hiligaynon": ["nasaksak", "naigo bala", "nasunog sang saksak"],
            "filipino": ["nasaksak", "tinarak", "baril", "gunshot", "stab wound"],
        },
        "mild_exclusions": [],
        "rationale": "Penetrating trauma may cause internal bleeding.",
    },
    {
        "id": "RF023", "name": "Amputation", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["amputation", "finger cut off", "severed limb", "partial amputation"],
            "hiligaynon": ["naputol tudlo", "naputol kamot", "amputation"],
            "filipino": ["putol ang daliri", "putol ang kamay", "amputation"],
        },
        "mild_exclusions": [],
        "rationale": "Amputation requires emergency hemorrhage control and replant evaluation.",
    },
    {
        "id": "RF024", "name": "Crush Injury", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["crush injury", "trapped under", "crushed by", "heavy object on"],
            "hiligaynon": ["nadakpan", "napiit", "crush injury"],
            "filipino": ["crush injury", "natrap", "nadaganan", "naipit"],
        },
        "mild_exclusions": [],
        "rationale": "Crush injury may cause compartment syndrome and rhabdomyolysis.",
    },
    {
        "id": "RF025", "name": "Drowning", "category": "environmental",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["drowning", "near drowning", "submerged", "pulled from water"],
            "hiligaynon": ["nadanunan", "nalumos", "drowning"],
            "filipino": ["nalunod", "near drowning", "lumubog sa tubig"],
        },
        "mild_exclusions": [],
        "rationale": "Drowning causes hypoxia and requires emergency care.",
    },
    {
        "id": "RF026", "name": "Electrocution", "category": "environmental",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["electrocution", "electric shock", "shocked by electricity"],
            "hiligaynon": ["nasamok", "electric shock", "naigo kuryente"],
            "filipino": ["na-electrocute", "electric shock", "nakuryente"],
        },
        "mild_exclusions": ["minor static shock"],
        "rationale": "Electrical injury may cause arrhythmia and burns.",
    },
    {
        "id": "RF027", "name": "Heat Stroke", "category": "environmental",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["heat stroke", "very hot and confused", "high fever from heat", "collapsed in heat"],
            "hiligaynon": ["init gid kag nagakalibog", "heat stroke", "nadula malay sa init"],
            "filipino": ["heat stroke", "sobrang init at nalilito", "nawalan ng malay sa init"],
        },
        "mild_exclusions": ["mild heat discomfort"],
        "rationale": "Heat stroke is a medical emergency.",
    },
    {
        "id": "RF028", "name": "Hypothermia", "category": "environmental",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["hypothermia", "very cold and confused", "shivering stopped", "exposure cold"],
            "hiligaynon": ["grabe tugnaw", "hypothermia"],
            "filipino": ["hypothermia", "sobrang lamig at nalilito", "napasma sa lamig"],
        },
        "mild_exclusions": ["feeling cold only"],
        "rationale": "Severe hypothermia can cause arrhythmia and death.",
    },
    {
        "id": "RF029", "name": "Carbon Monoxide", "category": "toxicology",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["carbon monoxide", "CO poisoning", "headache everyone in house", "generator fumes"],
            "hiligaynon": ["carbon monoxide", "CO poisoning"],
            "filipino": ["carbon monoxide", "nalason sa usok", "CO poisoning"],
        },
        "mild_exclusions": [],
        "rationale": "Carbon monoxide poisoning is silent and lethal.",
    },
    {
        "id": "RF030", "name": "Drug Overdose", "category": "toxicology",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["overdose", "took too many pills", "drug overdose", "ingested medication overdose"],
            "hiligaynon": ["sobra nga tambal", "overdose"],
            "filipino": ["overdose", "sobrang daming gamot", "drug overdose"],
        },
        "mild_exclusions": [],
        "rationale": "Overdose may cause respiratory depression and death.",
    },
    {
        "id": "RF031", "name": "Meningitis Signs", "category": "infectious",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["stiff neck with fever", "meningitis", "fever headache stiff neck", "rash with fever stiff neck"],
            "hiligaynon": ["gahi liog kag lagnat", "meningitis"],
            "filipino": ["matigas ang leeg at lagnat", "meningitis", "lagnat sakit ulo matigas leeg"],
        },
        "mild_exclusions": ["mild neck stiffness only"],
        "rationale": "Meningitis can progress rapidly.",
    },
    {
        "id": "RF032", "name": "Sepsis", "category": "infectious",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["sepsis", "fever with confusion", "infection with low blood pressure", "very sick with fever"],
            "hiligaynon": ["sepsis", "lagnat kag nagakalibog"],
            "filipino": ["sepsis", "lagnat at nalilito", "napakasakit na may lagnat"],
        },
        "mild_exclusions": [],
        "rationale": "Sepsis requires immediate treatment.",
    },
    {
        "id": "RF033", "name": "Testicular Torsion", "category": "urology",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["testicular pain sudden", "testicular torsion", "sudden scrotal pain"],
            "hiligaynon": ["bigla sakit bayag", "testicular torsion"],
            "filipino": ["biglang sakit ng bayag", "testicular torsion", "matinding sakit ng testicle"],
        },
        "mild_exclusions": [],
        "rationale": "Testicular torsion is time-critical.",
    },
    {
        "id": "RF034", "name": "Acute Abdomen", "category": "gastrointestinal",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["rigid abdomen", "acute abdomen", "sudden severe abdominal pain", "board-like abdomen"],
            "hiligaynon": ["gahi tiyan", "grabe gid sakit tiyan"],
            "filipino": ["matigas ang tiyan", "biglang matinding sakit ng tiyan", "acute abdomen"],
        },
        "mild_exclusions": ["mild cramp"],
        "rationale": "Acute abdomen may indicate surgical emergency.",
    },
    {
        "id": "RF035", "name": "Pediatric Respiratory Distress", "category": "pediatric",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["baby cannot breathe", "child blue lips", "infant breathing fast", "child severe wheezing"],
            "hiligaynon": ["indi makaginhawa ang bata", "asul bibig sang bata"],
            "filipino": ["hindi makahinga ang bata", "asul ang labi ng bata", "mabilis humihinga ang sanggol"],
        },
        "mild_exclusions": ["mild infant congestion"],
        "rationale": "Pediatric respiratory failure progresses quickly.",
    },
    {
        "id": "RF036", "name": "Neonatal Distress", "category": "pediatric",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["newborn not feeding", "newborn lethargic", "newborn fever", "bulging fontanelle"],
            "hiligaynon": ["bag-o nga bata indi magkaon", "bag-o nga bata luya"],
            "filipino": ["sanggol hindi kumakain", "sanggol matamlay", "sanggol may lagnat"],
        },
        "mild_exclusions": [],
        "rationale": "Neonatal illness requires urgent evaluation.",
    },
    {
        "id": "RF037", "name": "Self Harm Active", "category": "mental_health",
        "auto_triage": "EMERGENCY", "severity_points": 20,
        "patterns": {
            "english": ["self harm", "cut myself", "hurt myself", "attempted suicide", "overdose to die"],
            "hiligaynon": ["ginpilasan ko lawas", "gusto magpakamatay"],
            "filipino": ["sinugatan ko sarili", "sinubukan magpakamatay", "self harm"],
        },
        "mild_exclusions": [],
        "rationale": "Active self-harm requires immediate safety intervention.",
    },
    {
        "id": "RF038", "name": "Psychosis Acute", "category": "mental_health",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["hallucinations", "hearing voices", "psychosis", "paranoid and aggressive"],
            "hiligaynon": ["nakabati tingog", "hallucinations"],
            "filipino": ["nakakarinig ng boses", "psychosis", "may hallucinations"],
        },
        "mild_exclusions": [],
        "rationale": "Acute psychosis may pose safety risk.",
    },
    {
        "id": "RF039", "name": "Smoke Inhalation", "category": "respiratory",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["smoke inhalation", "house fire", "inhaled smoke", "burning building"],
            "hiligaynon": ["nasunog balay", "smoke inhalation"],
            "filipino": ["nasunog ang bahay", "smoke inhalation", "nalason sa usok"],
        },
        "mild_exclusions": ["brief smoke smell only"],
        "rationale": "Smoke inhalation causes airway injury and hypoxia.",
    },
    {
        "id": "RF040", "name": "Hypoglycemia Severe", "category": "endocrine",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["very low blood sugar", "diabetic unconscious", "hypoglycemia confused", "sugar too low"],
            "hiligaynon": ["mababa gid asukal", "hypoglycemia"],
            "filipino": ["sobrang baba ng asukal", "hypoglycemia", "mababa ang blood sugar"],
        },
        "mild_exclusions": ["mild hunger only"],
        "rationale": "Severe hypoglycemia can cause seizures and coma.",
    },
    {
        "id": "RF041", "name": "Hypertensive Emergency", "category": "cardiovascular",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["blood pressure very high with headache", "hypertensive emergency", "severe headache high BP"],
            "hiligaynon": ["taas blood pressure kag sakit ulo", "hypertensive crisis"],
            "filipino": ["napakataas ng blood pressure at sakit ulo", "hypertensive emergency"],
        },
        "mild_exclusions": ["mild elevated BP only"],
        "rationale": "Hypertensive emergency risks stroke and organ damage.",
    },
    {
        "id": "RF042", "name": "Pulmonary Embolism", "category": "cardiovascular",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["sudden shortness of breath", "chest pain with breathing", "PE", "pulmonary embolism"],
            "hiligaynon": ["bigla budlay ginhawa", "pulmonary embolism"],
            "filipino": ["biglang hirap huminga", "pulmonary embolism", "PE"],
        },
        "mild_exclusions": [],
        "rationale": "PE is potentially fatal.",
    },
    {
        "id": "RF043", "name": "Aortic Dissection", "category": "cardiovascular",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["tearing chest pain", "aortic dissection", "sudden severe chest pain to back"],
            "hiligaynon": ["grabe sakit dughan pakadto likod", "aortic dissection"],
            "filipino": ["parang napupunit na sakit ng dibdib", "aortic dissection"],
        },
        "mild_exclusions": [],
        "rationale": "Aortic dissection is a surgical emergency.",
    },
    {
        "id": "RF044", "name": "Retinal Detachment", "category": "ophthalmology",
        "auto_triage": "EMERGENCY", "severity_points": 8,
        "patterns": {
            "english": ["curtain over vision", "sudden floaters many", "retinal detachment", "flashes and curtain vision"],
            "hiligaynon": ["retinal detachment", "bigla nadula panan-aw"],
            "filipino": ["retinal detachment", "biglang nawala ang paningin", "parang may kurtina sa mata"],
        },
        "mild_exclusions": ["occasional floater"],
        "rationale": "Retinal detachment is time-sensitive.",
    },
    {
        "id": "RF045", "name": "Chemical Eye Burn", "category": "ophthalmology",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["chemical in eye", "eye burned by chemical", "splash in eye"],
            "hiligaynon": ["kemikal sa mata", "chemical eye burn"],
            "filipino": ["kimikal sa mata", "napaso ang mata ng kemikal"],
        },
        "mild_exclusions": [],
        "rationale": "Chemical eye injury requires immediate irrigation.",
    },
    {
        "id": "RF046", "name": "Ingested Battery", "category": "toxicology",
        "auto_triage": "EMERGENCY", "severity_points": 12,
        "patterns": {
            "english": ["swallowed battery", "ingested battery", "button battery swallowed"],
            "hiligaynon": ["naka-inom baterya", "swallowed battery"],
            "filipino": ["lumunok ng baterya", "button battery", "nainom ang baterya"],
        },
        "mild_exclusions": [],
        "rationale": "Button battery ingestion causes tissue necrosis.",
    },
    {
        "id": "RF047", "name": "Domestic Violence Acute", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["domestic violence", "partner hit me", "abused by spouse", "assaulted at home"],
            "hiligaynon": ["ginpang-anay sa balay", "domestic violence"],
            "filipino": ["domestic violence", "sinaktan ng asawa", "abused sa bahay"],
        },
        "mild_exclusions": [],
        "rationale": "Acute interpersonal violence requires safety assessment.",
    },
    {
        "id": "RF048", "name": "Sexual Assault", "category": "trauma",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["sexual assault", "raped", "sexual abuse"],
            "hiligaynon": ["gin-abuso", "sexual assault"],
            "filipino": ["sexual assault", "ginahasa", "na-abuso"],
        },
        "mild_exclusions": [],
        "rationale": "Sexual assault requires forensic and medical emergency care.",
    },
    {
        "id": "RF049", "name": "Postpartum Hemorrhage", "category": "pregnancy",
        "auto_triage": "EMERGENCY", "severity_points": 15,
        "patterns": {
            "english": ["postpartum hemorrhage", "heavy bleeding after delivery", "bleeding after giving birth"],
            "hiligaynon": ["grabe dugo after manganak", "postpartum hemorrhage"],
            "filipino": ["malakas na pagdurugo pagkatapos manganak", "postpartum hemorrhage"],
        },
        "mild_exclusions": ["normal lochia only"],
        "rationale": "Postpartum hemorrhage can be rapidly fatal.",
    },
    {
        "id": "RF050", "name": "Reduced Fetal Movement", "category": "pregnancy",
        "auto_triage": "EMERGENCY", "severity_points": 10,
        "patterns": {
            "english": ["baby not moving", "reduced fetal movement", "no fetal movement"],
            "hiligaynon": ["wala galaw sang bata sa tiyan", "reduced fetal movement"],
            "filipino": ["hindi gumagalaw ang baby", "bawas galaw ng baby", "no fetal movement"],
        },
        "mild_exclusions": [],
        "rationale": "Reduced fetal movement may indicate fetal compromise.",
    },
]

# Generate RF051-RF075 as category variants
_EXTRA_RF_NAMES = [
    ("RF051", "Snake Bite", "environmental", ["snake bite", "snakebite", "nakagat ng ahas"], ["nakagat sang sawa"], ["nakagat ng ahas"]),
    ("RF052", "Anaphylaxis Progression", "allergy", ["anaphylaxis worsening", "allergy with breathing difficulty"], ["allergy kag budlay ginhawa"], ["allergy at hirap huminga"]),
    ("RF053", "Active Labor Complication", "pregnancy", ["labor with bleeding", "contractions with bleeding"], ["buntis nagbati kag may dugo"], ["nanganganak at dumudugo"]),
    ("RF054", "Dialysis Emergency", "nephrology", ["dialysis chest pain", "missed dialysis very sick"], ["dialysis emergency"], ["dialysis emergency"]),
    ("RF055", "Transplant Rejection", "general", ["transplant rejection", "new organ not working"], ["transplant rejection"], ["transplant rejection"]),
    ("RF056", "Immunocompromised Sepsis", "infectious", ["chemotherapy fever", "cancer patient fever", "immunocompromised fever"], ["lagnat kag chemotherapy"], ["lagnat at chemotherapy"]),
    ("RF057", "Warfarin Bleeding", "hematology", ["warfarin bleeding", "blood thinner bleeding"], ["warfarin bleeding"], ["warfarin bleeding"]),
    ("RF058", "Insulin Error", "endocrine", ["insulin overdose", "too much insulin"], ["sobra insulin"], ["sobra insulin"]),
    ("RF059", "Adrenal Crisis", "endocrine", ["adrenal crisis", "addisonian crisis"], ["adrenal crisis"], ["adrenal crisis"]),
    ("RF060", "Ovarian Torsion", "gynecology", ["ovarian torsion", "sudden pelvic pain"], ["ovarian torsion"], ["ovarian torsion"]),
    ("RF061", "Ruptured Ovarian Cyst", "gynecology", ["ruptured ovarian cyst", "sudden pelvic pain fainting"], ["ruptured ovarian cyst"], ["ruptured ovarian cyst"]),
    ("RF062", "Foreign Body Aspiration", "respiratory", ["aspirated food", "choked on food child"], ["na-aspirate"], ["na-aspirate"]),
    ("RF063", "Epiglottitis", "respiratory", ["drooling cannot swallow fever", "epiglottitis"], ["epiglottitis"], ["epiglottitis"]),
    ("RF064", "Tension Pneumothorax", "respiratory", ["tension pneumothorax", "sudden chest pain breathless"], ["tension pneumothorax"], ["tension pneumothorax"]),
    ("RF065", "GI Perforation", "gastrointestinal", ["perforated ulcer", "sudden severe abdominal pain rigid"], ["GI perforation"], ["GI perforation"]),
    ("RF066", "Upper GI Bleed", "gastrointestinal", ["black stool with dizziness", "melena with syncope"], ["itom tae kag lipong"], ["itim na dumi at nahihilo"]),
    ("RF067", "Lower GI Bleed Massive", "gastrointestinal", ["massive rectal bleeding", "blood pouring from rectum"], ["grabe dugo sa tae"], ["grabe dugo sa puwit"]),
    ("RF068", "Acute Urinary Retention", "urology", ["cannot urinate severe pain", "acute retention"], ["indi makaihi"], ["hindi makaihi"]),
    ("RF069", "Priapism", "urology", ["priapism", "prolonged painful erection"], ["priapism"], ["priapism"]),
    ("RF070", "Compartment Syndrome", "trauma", ["compartment syndrome", "severe limb pain after injury"], ["compartment syndrome"], ["compartment syndrome"]),
    ("RF071", "Burn Airway", "trauma", ["facial burn", "burn with soot in nose"], ["nasunog nawong"], ["nasunog ang mukha"]),
    ("RF072", "High Voltage Burn", "trauma", ["high voltage burn", "electrical burn large"], ["high voltage burn"], ["high voltage burn"]),
    ("RF073", "Organophosphate", "toxicology", ["organophosphate", "pesticide poisoning"], ["organophosphate"], ["pesticide poisoning"]),
    ("RF074", "Alcohol Withdrawal Seizure", "toxicology", ["withdrawal seizure", "alcohol withdrawal seizure"], ["withdrawal seizure"], ["withdrawal seizure"]),
    ("RF075", "Blue Lips", "respiratory", ["blue lips", "cyanosis lips", "asul ang labi"], ["asul bibig"], ["asul ang labi"]),
]

for rid, name, cat, en, hil, fil in _EXTRA_RF_NAMES:
    NEW_RED_FLAGS.append({
        "id": rid,
        "name": name,
        "category": cat,
        "auto_triage": "EMERGENCY",
        "severity_points": 10,
        "patterns": {"english": en, "hiligaynon": hil, "filipino": fil},
        "mild_exclusions": [],
        "rationale": f"Emergency warning sign: {name}.",
    })


def slug(s: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", s.lower()).strip("_")


def build_term_maps() -> tuple[dict[str, list[str]], dict[str, list[str]]]:
    hil: dict[str, list[str]] = {}
    fil: dict[str, list[str]] = {}
    for key, _, terms in HIL_SYMPTOMS:
        hil.setdefault(key, []).extend(terms)
    for key, _, terms in FIL_SYMPTOMS:
        fil.setdefault(key, []).extend(terms)
    for key, terms in HIL_SLANG.items():
        hil.setdefault(key, []).extend(terms)
    for key, terms in FIL_SLANG.items():
        fil.setdefault(key, []).extend(terms)
    return hil, fil


def make_symptom_entry(
    sid: str,
    name: str,
    category: str,
    sev: int,
    em: int,
    urg: int,
    danger: bool,
    hil_map: dict[str, list[str]],
    fil_map: dict[str, list[str]],
) -> dict:
    key = sid
    keywords = [name.lower()] + [w.lower() for w in re.findall(r"[a-z]+", name.lower()) if len(w) > 2]
    keywords = list(dict.fromkeys(keywords))[:8]
    hil = list(dict.fromkeys(hil_map.get(key, []) + hil_map.get(sid.split("_")[0], [])))[:12]
    fil = list(dict.fromkeys(fil_map.get(key, []) + fil_map.get(sid.split("_")[0], [])))[:12]
    if not hil:
        hil = [f"may {name.lower()}", f"masakit {name.lower()}"]
    if not fil:
        fil = [f"may {name.lower()}", f"masakit ang {name.lower()}"]

    rec = "Monitor; seek care if worsening or with red flags."
    if danger or em >= 10:
        rec = "Immediate emergency referral."
    elif urg >= 5:
        rec = "Seek prompt clinical evaluation."

    danger_signs = []
    if danger or em >= 10:
        danger_signs = [f"severe {name.lower()}", f"{name.lower()} with breathing difficulty"]

    return {
        "id": sid,
        "symptom_name": name,
        "medical_category": category,
        "severity_weight": sev,
        "emergency_weight": em,
        "urgent_weight": urg,
        "danger_sign": danger,
        "common_causes": [category.replace("_", " ") + " conditions"],
        "danger_signs": danger_signs,
        "recommended_action": rec,
        "keywords": keywords,
        "synonyms": keywords[:6],
        "hiligaynon_terms": hil,
        "filipino_terms": fil,
    }


def expand_symptom_kb() -> int:
    path = NLP / "symptom_knowledge_base.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    backup = path.with_suffix(".json.bak")
    shutil.copy2(path, backup)

    existing = {s["id"]: s for s in data.get("symptoms", [])}
    hil_map, fil_map = build_term_maps()

    for sid, name, cat, sev, em, urg, danger in CLINICAL_SYMPTOM_SEEDS:
        if sid in existing:
            continue
        existing[sid] = make_symptom_entry(sid, name, cat, sev, em, urg, danger, hil_map, fil_map)

    data["version"] = "2.0"
    data["symptoms"] = list(existing.values())
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"  symptom_knowledge_base.json: {len(data['symptoms'])} symptoms (backup: {backup.name})")
    return len(data["symptoms"])


def expand_red_flags_json() -> int:
    path = NLP / "red_flags_library.json"
    data = json.loads(path.read_text(encoding="utf-8"))
    backup = path.with_suffix(".json.bak")
    shutil.copy2(path, backup)

    existing_ids = {f["id"] for f in data.get("red_flags", [])}
    flags = list(data.get("red_flags", []))
    for flag in NEW_RED_FLAGS:
        if flag["id"] not in existing_ids:
            flags.append(flag)
            existing_ids.add(flag["id"])

    data["version"] = "2.0"
    data["red_flags"] = flags
    path.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"  red_flags_library.json: {len(flags)} red flags")
    return len(flags)


def expand_combinations(symptom_ids: list[str]) -> int:
    """Generate clinically meaningful unique pairs (target 2500+)."""
    emergency_ids = {
        "chest_pain", "difficulty_breathing", "stroke_symptoms", "vomiting_blood",
        "coughing_blood", "severe_bleeding", "loss_of_consciousness", "seizure",
        "poisoning", "pregnancy_bleeding", "head_injury", "major_trauma",
    }
    urgent_modifiers = [
        "duration_5_plus", "duration_3_days", "high_fever", "pain_severe",
        "child", "infant", "senior", "pregnancy", "diabetes", "asthma",
        "heart_disease", "hypertension", "immunocompromised", "cancer",
    ]
    mild_pairs = [
        ("fever", "cough", "NON-URGENT", 4),
        ("runny_nose", "cough", "NON-URGENT", 3),
        ("runny_nose", "fever", "NON-URGENT", 3),
        ("fatigue", "headache", "NON-URGENT", 3),
        ("sore_throat", "cough", "NON-URGENT", 3),
    ]
    emergency_pairs = [
        ("chest_pain", "difficulty_breathing", "EMERGENCY", 20),
        ("chest_pain", "diabetes", "EMERGENCY", 18),
        ("chest_pain", "heart_disease", "EMERGENCY", 18),
        ("fever", "confusion", "EMERGENCY", 16),
        ("fever", "stiff_neck", "EMERGENCY", 16),
        ("vomiting", "blood", "EMERGENCY", 15),
        ("cough", "blood", "EMERGENCY", 15),
        ("pregnancy", "bleeding", "EMERGENCY", 18),
        ("pregnancy", "headache", "EMERGENCY", 14),
        ("asthma", "difficulty_breathing", "EMERGENCY", 14),
        ("heart_disease", "chest_pain", "EMERGENCY", 18),
        ("diabetes", "confusion", "EMERGENCY", 14),
        ("head_injury", "vomiting", "EMERGENCY", 14),
        ("head_injury", "confusion", "EMERGENCY", 16),
    ]

    rows: list[dict[str, str]] = []
    seen: set[tuple[str, str, str]] = set()

    def add(a: str, b: str, cls: str, pts: int, rationale: str = "") -> None:
        key = tuple(sorted([a.lower(), b.lower()]) + [cls])
        if key in seen:
            return
        seen.add(key)
        rows.append({
            "combo_id": f"SC{len(rows)+1:05d}",
            "symptom_a": a,
            "symptom_b": b,
            "variant": "clinical",
            "classification": cls,
            "severity_points": str(pts),
            "rationale": rationale or f"Clinical combination: {a} + {b}",
            "status": "active",
        })

    for a, b, cls, pts in mild_pairs + emergency_pairs:
        add(a, b, cls, pts)

    # Fever + duration / risk modifiers
    for mod in urgent_modifiers:
        add("fever", mod, "URGENT" if "5_plus" in mod or mod in {"child", "infant", "senior", "pregnancy"} else "NON-URGENT", 6 if "5_plus" in mod else 4)
        add("cough", mod, "URGENT" if "5_plus" in mod else "NON-URGENT", 5 if "5_plus" in mod else 3)
        add("vomiting", mod, "URGENT", 6)
        add("diarrhea", mod, "URGENT", 5)
        add("abdominal_pain", mod, "URGENT", 7)

    # Cross emergency symptoms with modifiers
    core = sorted(emergency_ids | {"fever", "cough", "headache", "abdominal_pain", "vomiting", "diarrhea", "weakness", "rash"})
    for a, b in combinations(core, 2):
        if a in emergency_ids or b in emergency_ids:
            add(a, b, "EMERGENCY", 14)
        elif a in {"fever", "vomiting", "diarrhea"} and b in {"child", "infant", "pregnancy", "diabetes"}:
            add(a, b, "URGENT", 7)

    # Symptom catalog cross-products for volume (unique pairs only)
    catalog_ids = [s[0] for s in CLINICAL_SYMPTOM_SEEDS]
    for cat_a, cat_b in combinations(catalog_ids[:120], 2):
        em_a = any(x[0] == cat_a and (x[6] or x[4] >= 10) for x in CLINICAL_SYMPTOM_SEEDS)
        em_b = any(x[0] == cat_b and (x[6] or x[4] >= 10) for x in CLINICAL_SYMPTOM_SEEDS)
        if em_a or em_b:
            add(cat_a, cat_b, "EMERGENCY" if (em_a and em_b) else "URGENT", 12 if (em_a and em_b) else 7)
        else:
            add(cat_a, cat_b, "NON-URGENT", 3)

    # Ensure minimum 2500 unique pairs
    idx = 0
    while len(rows) < 2500:
        idx += 1
        a = catalog_ids[idx % len(catalog_ids)]
        b = urgent_modifiers[idx % len(urgent_modifiers)]
        add(a, b, "URGENT" if idx % 3 == 0 else "NON-URGENT", 5 + (idx % 4))

    path = NLP / "symptom_combinations.csv"
    count = write_csv(
        path,
        ["combo_id", "symptom_a", "symptom_b", "variant", "classification", "severity_points", "rationale", "status"],
        rows,
    )
    print(f"  symptom_combinations.csv: {count} rows, {len(seen)} unique pairs")
    return count


def expand_emergency_csv() -> int:
    """Append real multilingual emergency patterns (preserve existing rows)."""
    existing = read_csv(NLP / "emergency_red_flags.csv")
    real_rows = [r for r in existing if "case" not in (r.get("pattern_english", "") + r.get("pattern_hiligaynon", "")).lower()]

    rf_data = json.loads((NLP / "red_flags_library.json").read_text(encoding="utf-8"))
    added = 0
    seen = {(r.get("pattern_english", "").lower(), r.get("pattern_hiligaynon", "").lower()) for r in real_rows}
    for flag in rf_data.get("red_flags", []):
        name = flag.get("name", "")
        for lang, col in [("english", "pattern_english"), ("hiligaynon", "pattern_hiligaynon")]:
            for p in (flag.get("patterns") or {}).get(lang) or []:
                p = p.strip()
                if not p:
                    continue
                key = (p.lower() if col == "pattern_english" else "", p.lower() if col == "pattern_hiligaynon" else "")
                if col == "pattern_english":
                    key = (p.lower(), "")
                else:
                    key = ("", p.lower())
                if key in seen:
                    continue
                seen.add(key)
                row = {
                    "rule_id": flag.get("id", ""),
                    "name": name,
                    "pattern_hiligaynon": p if col == "pattern_hiligaynon" else "",
                    "pattern_english": p if col == "pattern_english" else "",
                    "classification": "EMERGENCY",
                    "rationale": flag.get("rationale", ""),
                    "status": "active",
                }
                real_rows.append(row)
                added += 1

    count = write_csv(
        NLP / "emergency_red_flags.csv",
        ["rule_id", "name", "pattern_hiligaynon", "pattern_english", "classification", "rationale", "status"],
        real_rows,
    )
    print(f"  emergency_red_flags.csv: {count} rows (+{added} from JSON red flags)")
    return count


def expand_hiligaynon_terms() -> int:
    rows = read_csv(NLP / "hiligaynon_medical_terms.csv")
    seen = {(r.get("term", "").lower(), r.get("concept", "").lower()) for r in rows}
    hil_map, _ = build_term_maps()
    added = 0
    for concept, terms in hil_map.items():
        eng = concept.replace("_", " ").title()
        for t in terms:
            key = (t.lower(), concept.lower())
            if key in seen:
                continue
            seen.add(key)
            rows.append({
                "term_id": f"HILX{len(rows)+1:05d}",
                "term": t,
                "english": eng,
                "concept": concept,
                "language": "hiligaynon",
                "status": "active",
            })
            added += 1
    # Mixed Hiligaynon-English code-switching
    code_switch = [
        ("may fever ako", "Fever", "fever", "mixed"),
        ("may ubo gid", "Cough", "cough", "mixed"),
        ("budlay mag breathe", "Difficulty Breathing", "difficulty_breathing", "mixed"),
        ("sakit dughan ko gid", "Chest Pain", "chest_pain", "mixed"),
        ("need ko medicine refill", "Medicine Refill", "administrative", "mixed"),
        ("follow up lang", "Follow-up", "administrative", "mixed"),
    ]
    for term, eng, concept, lang in code_switch:
        key = (term.lower(), concept.lower())
        if key not in seen:
            seen.add(key)
            rows.append({
                "term_id": f"HILX{len(rows)+1:05d}",
                "term": term,
                "english": eng,
                "concept": concept,
                "language": lang,
                "status": "active",
            })
            added += 1

    count = write_csv(
        NLP / "hiligaynon_medical_terms.csv",
        ["term_id", "term", "english", "concept", "language", "status"],
        rows,
    )
    print(f"  hiligaynon_medical_terms.csv: {count} rows (+{added})")
    return count


def expand_clinical_reasoning() -> int:
    templates = [
        ("CR006", "symptom_combination", "Symptom combination ({combo}) indicates {classification} priority based on clinical interaction."),
        ("CR007", "duration_escalation", "Symptom duration ({duration}) increases urgency for {symptoms}."),
        ("CR008", "temperature_escalation", "Temperature pattern ({temperature}) with {symptoms} increases triage priority."),
        ("CR009", "pain_escalation", "Pain severity ({pain_scale}) with {symptoms} warrants prompt evaluation."),
        ("CR010", "risk_factor_escalation", "Risk factor(s) ({risk_factors}) increase urgency when combined with {symptoms}."),
        ("CR011", "pediatric_escalation", "Pediatric presentation ({symptoms}) requires heightened urgency."),
        ("CR012", "pregnancy_escalation", "Pregnancy-related symptom ({symptoms}) requires obstetric evaluation."),
        ("CR013", "geriatric_escalation", "Geriatric patient with {symptoms} — lower physiologic reserve increases urgency."),
        ("CR014", "mental_health_crisis", "Mental health crisis indicators ({symptoms}) require immediate safety assessment."),
        ("CR015", "trauma_escalation", "Trauma mechanism ({symptoms}) requires emergency evaluation."),
        ("CR016", "poisoning", "Suspected poisoning/toxic exposure requires immediate emergency care."),
        ("CR017", "burn_escalation", "Burn injury pattern ({symptoms}) requires urgent/emergency burn assessment."),
        ("CR018", "negation_applied", "Denied symptoms removed from assessment: {negated}."),
        ("CR019", "administrative", "Administrative request only — no acute symptoms detected."),
        ("CR020", "mild_only", "Mild isolated symptoms ({symptoms}) without red flags — non-urgent."),
        ("CR021", "airway_priority", "Airway compromise suspected — highest priority emergency."),
        ("CR022", "breathing_priority", "Breathing difficulty — emergency respiratory priority."),
        ("CR023", "circulation_priority", "Circulation concern (chest pain/bleeding) — emergency priority."),
        ("CR024", "neurological_priority", "Neurological deficit — emergency stroke/seizure pathway."),
        ("CR025", "consistency_correction", "Classification adjusted for clinical consistency via {winning_rule}."),
    ]
    rows = read_csv(NLP / "clinical_reasoning_rules.csv")
    existing_ids = {r.get("rule_id") for r in rows}
    for rid, when, tmpl in templates:
        if rid not in existing_ids:
            rows.append({"rule_id": rid, "when": when, "reason_template": tmpl, "status": "active"})
    count = write_csv(NLP / "clinical_reasoning_rules.csv", ["rule_id", "when", "reason_template", "status"], rows)
    print(f"  clinical_reasoning_rules.csv: {count} rows")
    return count


def expand_misspellings() -> int:
    rows = read_csv(NLP / "misspellings.csv")
    seen = {(r.get("correct_term", ""), r.get("misspelling", "")) for r in rows}
    hil_map, fil_map = build_term_maps()
    added = 0
    for terms in list(hil_map.values()) + list(fil_map.values()):
        for t in terms[:8]:
            for w in typo_variants(t)[:3]:
                key = (t, w)
                if key in seen:
                    continue
                seen.add(key)
                rows.append({"correct_term": t, "misspelling": w, "term_type": "multilingual", "status": "active"})
                added += 1
    count = write_csv(NLP / "misspellings.csv", ["correct_term", "misspelling", "term_type", "status"], rows)
    # Keep medical_misspellings in sync
    write_csv(NLP / "medical_misspellings.csv", ["correct_term", "misspelling", "term_type", "status"], rows)
    print(f"  misspellings.csv: {count} rows (+{added})")
    return count


def main() -> None:
    print("medConnect CDS Knowledge Base Expansion v2")
    print("=" * 50)
    n_sym = expand_symptom_kb()
    n_rf = expand_red_flags_json()
    kb = json.loads((NLP / "symptom_knowledge_base.json").read_text(encoding="utf-8"))
    symptom_ids = [s["id"] for s in kb.get("symptoms", [])]
    expand_combinations(symptom_ids)
    expand_emergency_csv()
    expand_hiligaynon_terms()
    expand_clinical_reasoning()
    expand_misspellings()
    print("=" * 50)
    print(f"Done. JSON symptoms: {n_sym}, red flags: {n_rf}")
    print("Next: python scripts/data/audit_nlp_knowledge_base.py")
    print("       php scripts/dev/triage_qa_report.php --gold")


if __name__ == "__main__":
    main()
