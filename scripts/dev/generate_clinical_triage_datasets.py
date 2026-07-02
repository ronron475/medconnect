#!/usr/bin/env python3
"""Generate standards-based clinical triage datasets: emergency_flags.csv + triage_rules.csv."""

from __future__ import annotations

import csv
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"

EMERGENCY_FLAGS = [
    ("EF001", "Chest Pain", "masakit dughan ko", "chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Acute chest pain may indicate myocardial ischemia or pulmonary embolism"),
    ("EF002", "Chest Pain", "sakit dughan", "chest pain", "cardiovascular", "cardiac", "EMERGENCY", "critical", "Chest pain requires immediate emergency evaluation"),
    ("EF003", "Respiratory Distress", "budlay magginhawa ko", "difficulty breathing", "respiratory", "breathing", "EMERGENCY", "critical", "Airway or respiratory compromise"),
    ("EF004", "Respiratory Distress", "indi ko makaginhawa", "cannot breathe", "respiratory", "breathing", "EMERGENCY", "critical", "Severe respiratory distress"),
    ("EF005", "Respiratory Distress", "dula ginhawa ko", "shortness of breath", "respiratory", "breathing", "EMERGENCY", "critical", "Hypoxia risk — emergency assessment required"),
    ("EF006", "Severe Bleeding", "grabe gid nagadugo", "severe bleeding", "general", "bleeding", "EMERGENCY", "critical", "Uncontrolled hemorrhage"),
    ("EF007", "Severe Bleeding", "indi mapunggan ang dugo", "uncontrolled bleeding", "general", "bleeding", "EMERGENCY", "critical", "Hemorrhage not controlled"),
    ("EF008", "Severe Bleeding", "nagdugo ulo ko", "head bleeding", "neurological", "bleeding", "EMERGENCY", "critical", "Head trauma with bleeding"),
    ("EF009", "Loss of Consciousness", "nadulaan ko malay", "loss of consciousness", "neurological", "consciousness", "EMERGENCY", "critical", "Altered consciousness — ABCs priority"),
    ("EF010", "Loss of Consciousness", "nagpunaw ko", "loss of consciousness", "neurological", "consciousness", "EMERGENCY", "critical", "Syncope or collapse"),
    ("EF011", "Stroke Symptoms", "daw indi ko makahambal", "speech difficulty", "neurological", "neurological", "EMERGENCY", "critical", "Possible acute stroke — FAST criteria"),
    ("EF012", "Stroke Symptoms", "daw indi ko makabaton sang kamot ko", "arm weakness", "neurological", "neurological", "EMERGENCY", "critical", "Focal neurological deficit"),
    ("EF013", "Seizure", "naguyam ko", "seizure", "neurological", "neurological", "EMERGENCY", "critical", "Active or recent seizure activity"),
    ("EF014", "Severe Allergic Reaction", "gahubag lawas ko kag budlay magginhawa", "anaphylaxis", "allergy", "allergy", "EMERGENCY", "critical", "Anaphylaxis with airway involvement"),
    ("EF015", "Suicidal Ideation", "gusto ko magpakamatay", "suicidal ideation", "mental_health", "psychiatric", "EMERGENCY", "critical", "Suicide risk — immediate safety assessment"),
    ("EF016", "Major Trauma", "nabunggo ko sa salakyan", "vehicle collision injury", "trauma", "trauma", "EMERGENCY", "critical", "High-energy trauma mechanism"),
    ("EF017", "Amputation", "nautod ari ko", "penile amputation", "trauma", "trauma", "EMERGENCY", "critical", "Major tissue loss — surgical emergency"),
    ("EF018", "Amputation", "nautod tudlo ko", "amputated finger", "trauma", "trauma", "EMERGENCY", "critical", "Traumatic amputation"),
    ("EF019", "Electrical Injury", "nakuryente ko kag nadulaan ko malay", "electrical injury with altered consciousness", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury with neurological compromise"),
    ("EF020", "Electrical Injury", "nakuryente gid ko", "electrical injury", "trauma", "trauma", "EMERGENCY", "critical", "Electrical injury — cardiac arrhythmia risk"),
    ("EF021", "Severe Burns", "nasunog lawas ko", "body burn", "dermatological", "burn", "EMERGENCY", "critical", "Major burn — fluid resuscitation may be required"),
    ("EF022", "Urinary Retention", "wala ko maka-ihi", "urinary retention", "urinary", "urinary", "EMERGENCY", "severe", "Acute urinary retention"),
    ("EF023", "Severe Head Injury", "nagdugo ulo ko pagkatapos natumba", "head bleeding after fall", "neurological", "trauma", "EMERGENCY", "critical", "Head injury with bleeding post fall"),
    ("EF024", "Choking", "wala ko maka-ginhawa tungod sa pagkaon", "choking", "respiratory", "breathing", "EMERGENCY", "critical", "Airway obstruction"),
    ("EF025", "Poisoning", "naka-inom ko sang lason", "poisoning", "general", "toxicology", "EMERGENCY", "critical", "Toxic ingestion"),
]

TRIAGE_RULES_EXTRA = [
    ("kakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive", "Mild localized itching without systemic or red-flag features", "dermatological"),
    ("gakatol bilat ko", "vaginal itching", "non_urgent", "mild", "female_reproductive", "Mild pruritus — routine evaluation if persistent", "dermatological"),
    ("galagas buhok ko", "hair loss", "non_urgent", "mild", "dermatological", "Non-acute hair loss — routine consultation", "dermatological"),
    ("ubo ko", "cough", "non_urgent", "mild", "respiratory", "Mild isolated cough without red flags", "respiratory"),
    ("sakit ulo ko", "head pain", "non_urgent", "mild", "neurological", "Mild headache without neurological deficits", "neurological"),
    ("may nana sa bilat ko", "vaginal infection", "urgent", "moderate", "infection", "Purulent genital discharge suggests infection requiring timely assessment", "infection"),
    ("may nana sa ari ko", "penile infection", "urgent", "moderate", "infection", "Genital infection with discharge — urgent evaluation", "infection"),
    ("may nana akon mata", "eye infection", "urgent", "moderate", "infection", "Eye pain with purulent discharge suggests ocular infection", "infection"),
    ("gahabok itlog ko", "testicular swelling", "urgent", "moderate", "male_reproductive", "Testicular swelling requires urgent evaluation to exclude torsion", "male_reproductive"),
    ("gahubag itlog ko", "testicular swelling", "urgent", "moderate", "male_reproductive", "Scrotal swelling — urgent urological assessment", "male_reproductive"),
    ("gadugo bilat ko", "vaginal bleeding", "urgent", "moderate", "gynecologic", "Non-massive vaginal bleeding — urgent gynecologic assessment", "gynecologic"),
    ("gadugo ari ko", "penile bleeding", "urgent", "moderate", "male_reproductive", "Genital bleeding — urgent evaluation", "male_reproductive"),
    ("ginahilanat gid ko", "high fever", "urgent", "moderate", "general", "High fever — assess for systemic infection", "general"),
    ("ginahilanat ko kag gahika ko", "fever with cough", "urgent", "moderate", "respiratory", "Fever with respiratory symptoms — assess for systemic infection", "respiratory"),
    ("ginabaldom gid ko", "severe abdominal pain", "urgent", "moderate", "gastrointestinal", "Significant abdominal pain — urgent surgical/medical evaluation", "gastrointestinal"),
    ("alta presyon ko", "hypertension", "urgent", "moderate", "cardiovascular", "Elevated blood pressure symptoms — urgent monitoring", "cardiovascular"),
    ("masakit pag-ihi ko", "painful urination", "urgent", "moderate", "urinary", "Dysuria may indicate UTI — timely treatment needed", "urinary"),
    ("grabe gid nagadugo bilat ko", "severe vaginal bleeding", "emergency", "critical", "gynecologic", "Massive or uncontrolled bleeding — emergency care", "bleeding"),
    ("indi mapunggan ang dugo sa ari", "uncontrolled penile bleeding", "emergency", "critical", "bleeding", "Uncontrolled genital hemorrhage", "bleeding"),
    ("masakit dughan ko", "chest pain", "emergency", "critical", "cardiovascular", "Chest pain — rule out acute coronary syndrome", "cardiac"),
    ("budlay magginhawa ko", "difficulty breathing", "emergency", "critical", "respiratory", "Respiratory distress — emergency airway assessment", "breathing"),
    ("nagdugo ulo ko", "head bleeding", "emergency", "critical", "trauma", "Head trauma with bleeding", "trauma"),
    ("naguyam ko", "seizure", "emergency", "critical", "neurological", "Seizure activity — emergency neurological assessment", "neurological"),
    ("ginkagat sang ido ko", "dog bite", "urgent", "moderate", "infection", "Animal bite — wound care and rabies prophylaxis assessment", "infection"),
]

FLAG_FIELDS = ["flag_id", "flag_name", "hiligaynon_pattern", "english_pattern", "body_system", "category", "auto_triage", "severity", "clinical_rationale", "status"]
RULE_FIELDS = ["hiligaynon_pattern", "english_pattern", "triage_level", "severity", "medical_category", "reason", "body_system", "status"]


def main() -> None:
    NLP.mkdir(parents=True, exist_ok=True)

    with (NLP / "emergency_flags.csv").open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=FLAG_FIELDS)
        w.writeheader()
        for row in EMERGENCY_FLAGS:
            w.writerow({**dict(zip(FLAG_FIELDS[:9], row)), "status": "active"})

    existing_rules: dict[str, dict] = {}
    rules_path = NLP / "triage_rules.csv"
    if rules_path.is_file():
        with rules_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                key = (row.get("hiligaynon_pattern") or "").lower()
                if key:
                    existing_rules[key] = row

    for hil, eng, tri, sev, cat, reason, body_sys in TRIAGE_RULES_EXTRA:
        key = hil.lower()
        if key not in existing_rules:
            existing_rules[key] = {
                "hiligaynon_pattern": hil,
                "english_pattern": eng,
                "triage_level": tri,
                "severity": sev,
                "medical_category": cat,
                "reason": reason,
                "body_system": body_sys,
                "status": "active",
            }

    with rules_path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=RULE_FIELDS, extrasaction="ignore")
        w.writeheader()
        for row in sorted(existing_rules.values(), key=lambda r: (r.get("triage_level", ""), r.get("hiligaynon_pattern", ""))):
            if "body_system" not in row:
                row["body_system"] = row.get("medical_category", "general")
            if "status" not in row:
                row["status"] = "active"
            w.writerow(row)

    print(f"Wrote emergency_flags.csv ({len(EMERGENCY_FLAGS)} flags)")
    print(f"Wrote triage_rules.csv ({len(existing_rules)} rules)")


if __name__ == "__main__":
    main()
