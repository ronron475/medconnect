#!/usr/bin/env python3
"""
Validate and repair medical triage CSV datasets for MedConnect AI triage.

- Ensures every triage phrase/condition row has NON_URGENT | URGENT | EMERGENCY
- Builds canonical condition_triage_severity.csv (maintainable overlay)
- Deduplicates, fills missing values, normalizes naming
- Writes a machine-readable + human validation report

Usage (from project root):
  python scripts/data/validate_and_fix_triage_datasets.py
  python scripts/data/validate_and_fix_triage_datasets.py --dry-run
"""

from __future__ import annotations

import argparse
import csv
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"
REPORT_DIR = ROOT / "storage" / "reports"

CANONICAL = ("NON_URGENT", "URGENT", "EMERGENCY")

# Maps any legacy label → canonical triage class
LEVEL_MAP = {
    "non_urgent": "NON_URGENT",
    "non-urgent": "NON_URGENT",
    "nonurgent": "NON_URGENT",
    "routine": "NON_URGENT",
    "low": "NON_URGENT",
    "mild": "NON_URGENT",
    "urgent": "URGENT",
    "high": "URGENT",
    "moderate": "URGENT",
    "medium": "URGENT",
    "emergency": "EMERGENCY",
    "critical": "EMERGENCY",
}

# Note: intensity labels like "severe" on the severity column are handled
# separately in canonicalize_level via severity_hint — they map to URGENT
# unless critical/life-threatening.

# CSV storage uses lowercase snake for engine compatibility
STORAGE_LEVEL = {
    "NON_URGENT": "non_urgent",
    "URGENT": "urgent",
    "EMERGENCY": "emergency",
}

SEVERITY_FOR_LEVEL = {
    "NON_URGENT": "mild",
    "URGENT": "moderate",
    "EMERGENCY": "critical",
}

URGENCY_SCORE = {
    "NON_URGENT": 20,
    "URGENT": 55,
    "EMERGENCY": 90,
}

RECOMMENDED_ACTION = {
    "NON_URGENT": "Schedule a routine consultation with a healthcare provider.",
    "URGENT": "Consult a healthcare provider as soon as possible (same day / within hours).",
    "EMERGENCY": "Seek emergency medical care immediately / call emergency services.",
}

# Phrase / rule CSVs that carry triage_level
TRIAGE_PHRASE_FILES = [
    "symptom_phrases.csv",
    "symptom_phrases_seed.csv",
    "hiligaynon_wv_expansion.csv",
    "hiligaynon_reproductive_expansion.csv",
    "hiligaynon_combinatorial_phrases.csv",
    "hiligaynon_conditions_combinatorial.csv",
    "hiligaynon_symptoms.csv",
    "hiligaynon_conditions.csv",
    "hiligaynon_symptoms_combinatorial.csv",
    "step6_triage_exemplars.csv",
    "triage_rules.csv",
]

# Clinical keyword heuristics for auto-assigning missing severity
EMERGENCY_KEYWORDS = [
    "cardiac arrest", "myocardial infarction", "heart attack", "stroke", "cva",
    "anaphylaxis", "anaphylactic", "sepsis", "septic shock", "status epilepticus",
    "respiratory failure", "pulmonary embolism", "pneumothorax", "meningitis",
    "encephalitis", "appendicitis", "ectopic", "placental abruption", "eclampsia",
    "testicular torsion", "compartment syndrome", "amputation", "gunshot",
    "stab wound", "major trauma", "fracture open", "burn third", "drowning",
    "poisoning", "overdose", "suicidal", "hemorrhage uncontrolled", "hematemesis",
    "melena massive", "diabetic ketoacidosis", "hypoglycemia severe", "coma",
    "unconscious", "choking", "airway obstruction", "cyanosis", "heat stroke",
    "snake bite", "necrotizing", "peritonitis", "bowel obstruction", "intussusception",
    "tuberculosis miliary", "covid severe", "dengue hemorrhagic", "leptospirosis severe",
    "chest pain ischemic", "stemi", "nstemi", "aortic dissection", "tamponade",
]

URGENT_KEYWORDS = [
    "pneumonia", "asthma exacerbation", "copd exacerbation", "bronchitis acute",
    "influenza", "dengue", "leptospirosis", "covid", "tuberculosis", "uti",
    "pyelonephritis", "cellulitis", "abscess", "otitis media", "sinusitis acute",
    "gastritis", "ulcer", "hyperacidity", "gerd severe", "cholecystitis",
    "kidney stone", "renal colic", "hypertension", "high blood", "hypotension",
    "diabetes uncontrolled", "hyperglycemia", "migraine", "arthritis acute",
    "allergy", "urticaria", "skin infection", "chickenpox", "measles", "mumps",
    "fracture", "sprain severe", "wound infected", "dysuria", "hematuria",
    "chest pain", "shortness of breath", "difficulty breathing", "fever high",
    "dehydration", "diarrhea bloody", "vomiting persistent", "abdominal pain",
    "heart disease", "kidney disease", "stroke history", "bleeding",
]

NON_URGENT_KEYWORDS = [
    "common cold", "sip-on", "sipon", "mild cough", "allergic rhinitis",
    "mild headache", "tension headache", "constipation", "mild rash",
    "acne", "dry skin", "mild fatigue", "insomni", "stress mild",
    "hair loss", "mild backache", "muscle strain mild", "hangnail",
]


def normalize_text(s: str) -> str:
    s = (s or "").strip().lower()
    s = re.sub(r"\s+", " ", s)
    return s


def canonicalize_level(raw: str, severity_hint: str = "") -> str:
    v = normalize_text(raw).replace(" ", "_")
    if v in LEVEL_MAP:
        return LEVEL_MAP[v]
    # Infer from severity column if triage empty / unrecognized
    sev = normalize_text(severity_hint)
    if sev in {"critical", "life-threatening", "life_threatening"}:
        return "EMERGENCY"
    if sev in {"severe", "high"}:
        return "URGENT"
    if sev in {"moderate", "medium"}:
        return "URGENT"
    if sev in {"mild", "low"}:
        return "NON_URGENT"
    return ""


def assign_by_clinical_guidelines(name: str, category: str = "") -> str:
    text = f"{normalize_text(name)} {normalize_text(category)}"
    for kw in EMERGENCY_KEYWORDS:
        if kw in text:
            return "EMERGENCY"
    for kw in URGENT_KEYWORDS:
        if kw in text:
            return "URGENT"
    for kw in NON_URGENT_KEYWORDS:
        if kw in text:
            return "NON_URGENT"
    # Category heuristics
    cat = normalize_text(category)
    if any(x in cat for x in ("trauma", "cardiac", "neurolog", "emergency", "bleed")):
        return "EMERGENCY"
    if any(x in cat for x in ("infect", "respirat", "cardio", "urinar", "digest", "gyn")):
        return "URGENT"
    if any(x in cat for x in ("dermat", "skin", "mild", "routine")):
        return "NON_URGENT"
    # Safe default for unspecified outpatient conditions
    return "URGENT"


def read_csv(path: Path) -> tuple[list[str], list[dict[str, str]]]:
    if not path.is_file():
        return [], []
    with path.open(encoding="utf-8", newline="", errors="replace") as f:
        reader = csv.DictReader(f)
        fields = list(reader.fieldnames or [])
        rows = [{k: (v or "").strip() for k, v in row.items()} for row in reader]
    return fields, rows


def write_csv(path: Path, fields: list[str], rows: list[dict[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        for r in rows:
            w.writerow({k: r.get(k, "") for k in fields})


def fix_phrase_file(name: str, dry_run: bool) -> dict[str, Any]:
    path = NLP / name
    fields, rows = read_csv(path)
    report: dict[str, Any] = {
        "file": name,
        "exists": path.is_file(),
        "total": len(rows),
        "missing_before": 0,
        "invalid_before": 0,
        "filled": 0,
        "normalized": 0,
        "duplicates_removed": 0,
        "by_level": {},
    }
    if not rows:
        return report

    term_key = "hiligaynon_term" if "hiligaynon_term" in fields else (
        "hiligaynon_pattern" if "hiligaynon_pattern" in fields else fields[0]
    )
    eng_key = "english_term" if "english_term" in fields else (
        "english_pattern" if "english_pattern" in fields else ""
    )
    has_triage = "triage_level" in fields
    has_sev = "severity" in fields
    if not has_triage:
        fields.append("triage_level")
        has_triage = True

    seen: set[str] = set()
    cleaned: list[dict[str, str]] = []
    counts: Counter[str] = Counter()

    for r in rows:
        raw_tri = r.get("triage_level", "")
        sev = r.get("severity", "") if has_sev else ""
        level = canonicalize_level(raw_tri, sev)
        if not (raw_tri or "").strip():
            report["missing_before"] += 1
        elif not level:
            report["invalid_before"] += 1

        if not level:
            name_for_rule = r.get(eng_key) or r.get(term_key) or ""
            cat = r.get("medical_category") or r.get("category") or r.get("body_system") or ""
            level = assign_by_clinical_guidelines(name_for_rule, cat)
            report["filled"] += 1
        else:
            storage_old = normalize_text(raw_tri).replace(" ", "_")
            if STORAGE_LEVEL[level] != storage_old and storage_old not in {"", STORAGE_LEVEL[level]}:
                # routine → non_urgent etc.
                if storage_old != STORAGE_LEVEL[level]:
                    report["normalized"] += 1

        r["triage_level"] = STORAGE_LEVEL[level]
        if has_sev and not (r.get("severity") or "").strip():
            r["severity"] = SEVERITY_FOR_LEVEL[level]

        # Dedup by local term (+ english when present)
        dedupe = normalize_text(r.get(term_key, ""))
        if eng_key:
            dedupe = f"{dedupe}|{normalize_text(r.get(eng_key, ''))}"
        if not r.get(term_key, "").strip():
            continue
        if dedupe in seen:
            report["duplicates_removed"] += 1
            continue
        seen.add(dedupe)
        cleaned.append(r)
        counts[level] += 1

    report["by_level"] = dict(counts)
    report["total_after"] = len(cleaned)
    if not dry_run:
        write_csv(path, fields, cleaned)
    return report


def build_condition_registry(dry_run: bool) -> dict[str, Any]:
    """Canonical maintainable registry used by the triage engine."""
    path = NLP / "condition_triage_severity.csv"
    fields = [
        "id",
        "medical_condition",
        "symptom",
        "category",
        "severity_level",
        "urgency_score",
        "emergency_flag",
        "recommended_action",
        "provider_required",
        "hospital_referral",
        "language",
        "synonyms",
        "keywords",
        "hiligaynon_term",
        "status",
    ]

    seed_conditions: list[dict[str, str]] = [
        # NON_URGENT
        {"medical_condition": "common cold", "symptom": "runny nose;cough", "category": "Respiratory",
         "severity_level": "NON_URGENT", "synonyms": "sip-on;sipon;colds", "keywords": "cold;rhinitis",
         "hiligaynon_term": "sip-on"},
        {"medical_condition": "mild constipation", "symptom": "constipation", "category": "Digestive",
         "severity_level": "NON_URGENT", "synonyms": "constipated", "keywords": "constipation",
         "hiligaynon_term": "constipated ko"},
        {"medical_condition": "tension headache", "symptom": "headache", "category": "Neurological",
         "severity_level": "NON_URGENT", "synonyms": "mild headache", "keywords": "headache mild",
         "hiligaynon_term": "sakit ulo"},
        {"medical_condition": "mild fatigue", "symptom": "fatigue", "category": "General",
         "severity_level": "NON_URGENT", "synonyms": "kapoy", "keywords": "fatigue;tired",
         "hiligaynon_term": "ginakapoy"},
        {"medical_condition": "insomnia", "symptom": "difficulty sleeping", "category": "Mental Health",
         "severity_level": "NON_URGENT", "synonyms": " sleeplessness", "keywords": "insomnia;sleep",
         "hiligaynon_term": "indi makatulog"},
        {"medical_condition": "mild skin rash", "symptom": "rash;itchiness", "category": "Dermatology",
         "severity_level": "NON_URGENT", "synonyms": "pantal", "keywords": "rash mild",
         "hiligaynon_term": "may rashes"},
        # URGENT
        {"medical_condition": "influenza", "symptom": "fever;cough;body aches", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "trangkaso;flu", "keywords": "influenza;flu",
         "hiligaynon_term": "trangkaso"},
        {"medical_condition": "asthma", "symptom": "wheezing;shortness of breath", "category": "Respiratory",
         "severity_level": "URGENT", "synonyms": "hika;hubak", "keywords": "asthma",
         "hiligaynon_term": "hika"},
        {"medical_condition": "pneumonia", "symptom": "cough;fever;difficulty breathing", "category": "Respiratory",
         "severity_level": "EMERGENCY", "synonyms": "pulmonya", "keywords": "pneumonia",
         "hiligaynon_term": "pneumonia"},
        {"medical_condition": "tuberculosis", "symptom": "chronic cough;hemoptysis;fever", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "TB;tisis", "keywords": "tuberculosis;tb",
         "hiligaynon_term": "tisis"},
        {"medical_condition": "dengue fever", "symptom": "fever;body pain;bleeding", "category": "Infectious",
         "severity_level": "EMERGENCY", "synonyms": "dengue;DB", "keywords": "dengue",
         "hiligaynon_term": "dengue"},
        {"medical_condition": "leptospirosis", "symptom": "fever;muscle pain;jaundice", "category": "Infectious",
         "severity_level": "EMERGENCY", "synonyms": "lepto", "keywords": "leptospirosis",
         "hiligaynon_term": "leptospirosis"},
        {"medical_condition": "COVID-19", "symptom": "fever;cough;shortness of breath", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "covid;corona", "keywords": "covid;coronavirus",
         "hiligaynon_term": "covid"},
        {"medical_condition": "hypertension", "symptom": "headache;dizziness", "category": "Cardiovascular",
         "severity_level": "URGENT", "synonyms": "high blood;alta presyon;HPN", "keywords": "hypertension",
         "hiligaynon_term": "alta presyon"},
        {"medical_condition": "hypotension", "symptom": "dizziness;weakness", "category": "Cardiovascular",
         "severity_level": "URGENT", "synonyms": "low blood", "keywords": "hypotension",
         "hiligaynon_term": "low blood"},
        {"medical_condition": "diabetes mellitus", "symptom": "polyuria;fatigue;polydipsia", "category": "Endocrine",
         "severity_level": "URGENT", "synonyms": "diabetes;diabetis;DM", "keywords": "diabetes",
         "hiligaynon_term": "diabetis"},
        {"medical_condition": "urinary tract infection", "symptom": "dysuria;frequency", "category": "Urinary",
         "severity_level": "URGENT", "synonyms": "UTI", "keywords": "uti;dysuria",
         "hiligaynon_term": "uti"},
        {"medical_condition": "hyperacidity", "symptom": "epigastric pain;heartburn", "category": "Digestive",
         "severity_level": "NON_URGENT", "synonyms": "asido;gastric", "keywords": "hyperacidity",
         "hiligaynon_term": "hyperacidity"},
        {"medical_condition": "peptic ulcer", "symptom": "abdominal pain;nausea", "category": "Digestive",
         "severity_level": "URGENT", "synonyms": "ulcer;ulser", "keywords": "ulcer",
         "hiligaynon_term": "ulcer"},
        {"medical_condition": "migraine", "symptom": "severe headache;photophobia", "category": "Neurological",
         "severity_level": "URGENT", "synonyms": "migren", "keywords": "migraine",
         "hiligaynon_term": "migraine"},
        {"medical_condition": "arthritis", "symptom": "joint pain;swelling", "category": "Musculoskeletal",
         "severity_level": "URGENT", "synonyms": "rayuma;artraytis", "keywords": "arthritis",
         "hiligaynon_term": "arthritis"},
        {"medical_condition": "heart disease", "symptom": "chest pain;palpitations", "category": "Cardiovascular",
         "severity_level": "URGENT", "synonyms": "sakit puso;sakit sa tagipusuon", "keywords": "heart disease",
         "hiligaynon_term": "sakit puso"},
        {"medical_condition": "stroke", "symptom": "facial droop;arm weakness;speech difficulty", "category": "Neurological",
         "severity_level": "EMERGENCY", "synonyms": "CVA;atake sa utok", "keywords": "stroke;cva",
         "hiligaynon_term": "stroke"},
        {"medical_condition": "kidney disease", "symptom": "flank pain;edema;oliguria", "category": "Urinary",
         "severity_level": "URGENT", "synonyms": "sakit bato;CKD", "keywords": "kidney disease",
         "hiligaynon_term": "sakit bato"},
        {"medical_condition": "allergy", "symptom": "itchiness;rash;sneezing", "category": "Allergy",
         "severity_level": "URGENT", "synonyms": "alergiya", "keywords": "allergy",
         "hiligaynon_term": "allergy"},
        {"medical_condition": "anaphylaxis", "symptom": "throat swelling;difficulty breathing;rash", "category": "Allergy",
         "severity_level": "EMERGENCY", "synonyms": "severe allergic reaction", "keywords": "anaphylaxis",
         "hiligaynon_term": "gahubag tutunlan"},
        {"medical_condition": "skin infection", "symptom": "redness;pain;pus", "category": "Infection",
         "severity_level": "URGENT", "synonyms": "impeksyon sa panit", "keywords": "cellulitis;skin infection",
         "hiligaynon_term": "impeksyon sa panit"},
        {"medical_condition": "chickenpox", "symptom": "vesicular rash;fever;itchiness", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "bulutong tubig;varicella", "keywords": "chickenpox",
         "hiligaynon_term": "bulutong tubig"},
        {"medical_condition": "measles", "symptom": "fever;rash;cough", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "tigdas", "keywords": "measles",
         "hiligaynon_term": "tigdas"},
        {"medical_condition": "mumps", "symptom": "parotid swelling;fever", "category": "Infectious",
         "severity_level": "URGENT", "synonyms": "beke", "keywords": "mumps",
         "hiligaynon_term": "beke"},
        {"medical_condition": "chest pain — cardiac concern", "symptom": "chest pain;diaphoresis", "category": "Cardiovascular",
         "severity_level": "EMERGENCY", "synonyms": "sakit dughan", "keywords": "chest pain;acs",
         "hiligaynon_term": "ginasakit dughan"},
        {"medical_condition": "respiratory distress", "symptom": "difficulty breathing;cyanosis", "category": "Respiratory",
         "severity_level": "EMERGENCY", "synonyms": "budlay magginhawa", "keywords": "dyspnea;distress",
         "hiligaynon_term": "budlay magginhawa"},
        {"medical_condition": "uncontrolled bleeding", "symptom": "bleeding", "category": "Trauma",
         "severity_level": "EMERGENCY", "synonyms": "indi mapunggan dugo", "keywords": "hemorrhage",
         "hiligaynon_term": "indi mapunggan"},
        {"medical_condition": "seizure", "symptom": "convulsion;loss of consciousness", "category": "Neurological",
         "severity_level": "EMERGENCY", "synonyms": "naguyam;kombulsyon", "keywords": "seizure",
         "hiligaynon_term": "naguyam"},
        {"medical_condition": "fever with chills", "symptom": "fever;chills", "category": "General",
         "severity_level": "URGENT", "synonyms": "hilanat kag tugnaw", "keywords": "fever;chills",
         "hiligaynon_term": "ginakurog kag ginatugnaw"},
    ]

    # Merge unique english conditions from hiligaynon_conditions.csv
    _, cond_rows = read_csv(NLP / "hiligaynon_conditions.csv")
    by_name: dict[str, dict[str, str]] = {}

    def upsert(row: dict[str, str]) -> None:
        name = normalize_text(row["medical_condition"])
        if not name:
            return
        level = canonicalize_level(row.get("severity_level", ""), "") or assign_by_clinical_guidelines(
            row["medical_condition"], row.get("category", "")
        )
        existing = by_name.get(name)
        # Prefer higher urgency if conflict
        rank = {"NON_URGENT": 0, "URGENT": 1, "EMERGENCY": 2}
        if existing and rank[canonicalize_level(existing["severity_level"])] >= rank[level]:
            # merge synonyms
            syns = set(filter(None, (existing.get("synonyms") or "").split(";")))
            syns |= set(filter(None, (row.get("synonyms") or "").split(";")))
            if row.get("hiligaynon_term"):
                syns.add(row["hiligaynon_term"])
            existing["synonyms"] = ";".join(sorted(syns))
            return
        entry = {
            "medical_condition": row["medical_condition"].strip(),
            "symptom": row.get("symptom", "").strip(),
            "category": row.get("category", "General").strip() or "General",
            "severity_level": level,
            "urgency_score": str(URGENCY_SCORE[level]),
            "emergency_flag": "1" if level == "EMERGENCY" else "0",
            "recommended_action": RECOMMENDED_ACTION[level],
            "provider_required": "1" if level != "NON_URGENT" else "0",
            "hospital_referral": "1" if level == "EMERGENCY" else "0",
            "language": row.get("language", "en;hil").strip() or "en;hil",
            "synonyms": row.get("synonyms", "").strip(),
            "keywords": row.get("keywords", "").strip(),
            "hiligaynon_term": row.get("hiligaynon_term", "").strip(),
            "status": "active",
        }
        by_name[name] = entry

    for s in seed_conditions:
        upsert(s)

    for r in cond_rows:
        eng = (r.get("english_term") or "").strip()
        hil = (r.get("hiligaynon_term") or "").strip()
        if not eng:
            continue
        level = canonicalize_level(r.get("triage_level", ""), r.get("severity", "")) or assign_by_clinical_guidelines(
            eng, r.get("medical_category", "")
        )
        upsert(
            {
                "medical_condition": eng,
                "symptom": eng,
                "category": r.get("medical_category") or "General",
                "severity_level": level,
                "synonyms": hil,
                "keywords": eng.lower(),
                "hiligaynon_term": hil,
                "language": "en;hil",
            }
        )

    # Ensure every registry row has symptoms linkage (at least self)
    rows_out: list[dict[str, str]] = []
    for i, (_k, entry) in enumerate(sorted(by_name.items()), start=1):
        if not entry.get("symptom"):
            entry["symptom"] = entry["medical_condition"]
        entry["id"] = f"CTS{i:04d}"
        rows_out.append(entry)

    report = {
        "file": "condition_triage_severity.csv",
        "total": len(rows_out),
        "by_level": dict(Counter(r["severity_level"] for r in rows_out)),
        "missing_severity": 0,
    }
    if not dry_run:
        write_csv(path, fields, rows_out)
    return report


def annotate_medical_conditions_overlay(dry_run: bool) -> dict[str, Any]:
    """
    Build medical_conditions_triage_overlay.csv for the large ICD list.
    Does not rewrite medical_conditions.csv (keeps ICD import stable).
    """
    src = NLP / "medical_conditions.csv"
    out = NLP / "medical_conditions_triage_overlay.csv"
    fields_src, rows = read_csv(src)
    report = {
        "file": "medical_conditions_triage_overlay.csv",
        "source_rows": len(rows),
        "by_level": {},
        "missing_before": len(rows),  # source has no severity
    }
    if not rows:
        return report

    out_fields = [
        "condition_id",
        "medical_condition",
        "category",
        "severity_level",
        "urgency_score",
        "emergency_flag",
        "recommended_action",
        "provider_required",
        "hospital_referral",
        "icd10_code",
        "status",
    ]
    out_rows: list[dict[str, str]] = []
    counts: Counter[str] = Counter()
    # Sample strategy: classify all but write compact unique-by-name for maintainability;
    # still cover full ICD via heuristic assigner at runtime. Persist full overlay ids.
    for r in rows:
        name = (r.get("condition_name") or "").strip()
        if not name:
            continue
        level = assign_by_clinical_guidelines(name, r.get("category", ""))
        counts[level] += 1
        out_rows.append(
            {
                "condition_id": r.get("condition_id", ""),
                "medical_condition": name,
                "category": r.get("category", ""),
                "severity_level": level,
                "urgency_score": str(URGENCY_SCORE[level]),
                "emergency_flag": "1" if level == "EMERGENCY" else "0",
                "recommended_action": RECOMMENDED_ACTION[level],
                "provider_required": "1" if level != "NON_URGENT" else "0",
                "hospital_referral": "1" if level == "EMERGENCY" else "0",
                "icd10_code": r.get("icd10_code", ""),
                "status": "active",
            }
        )

    report["by_level"] = dict(counts)
    report["total"] = len(out_rows)
    report["missing_after"] = 0
    if not dry_run:
        # Full overlay is large (~74k). Write it — production AI loads registry first + overlay lookup.
        write_csv(out, out_fields, out_rows)
    return report


def audit_reference_files() -> dict[str, Any]:
    files = {
        "medical_conditions.csv": "conditions",
        "symptoms.csv": "symptoms",
        "allergies.csv": "allergies",
        "body_parts.csv": "body_parts",
        "body_part_pain_symptoms.csv": "pain_map",
        "medical_dictionary.csv": "dictionary",
        "emergency_flags.csv": "emergency_flags",
        "triage_rules.csv": "triage_rules",
        "medical_misspellings.csv": "misspellings",
    }
    out: dict[str, Any] = {}
    for name, role in files.items():
        path = NLP / name
        fields, rows = read_csv(path)
        empty_cells = 0
        for r in rows[:5000]:  # sample for speed on huge files
            for v in r.values():
                if not (v or "").strip():
                    empty_cells += 1
        out[name] = {
            "role": role,
            "exists": path.is_file(),
            "rows": len(rows),
            "columns": fields,
            "has_triage_level": "triage_level" in fields or "severity_level" in fields or "auto_triage" in fields,
            "empty_cells_in_sample": empty_cells,
            "tagalog_column": any("tagalog" in c.lower() for c in fields),
        }
    out["tagalog_datasets"] = {
        "present": False,
        "note": "No Tagalog translation CSVs found. Primary languages: Hiligaynon + English.",
    }
    out["medications_datasets"] = {
        "present": (NLP / "medications.csv").is_file(),
        "note": "No dedicated medications CSV; medications remain free-text in patient profile.",
    }
    out["risk_factors_datasets"] = {
        "present": (NLP / "risk_factors.csv").is_file(),
        "note": "No dedicated risk-factor CSV; risk inferred from symptoms/conditions and emergency flags.",
    }
    return out


def link_symptoms_to_conditions(dry_run: bool) -> dict[str, Any]:
    """Ensure symptom_condition_map.csv links symptoms → conditions with severity."""
    path = NLP / "symptom_condition_map.csv"
    fields = [
        "id",
        "symptom",
        "medical_condition",
        "severity_level",
        "relationship",
        "language",
        "status",
    ]
    _, registry = read_csv(NLP / "condition_triage_severity.csv")
    rows: list[dict[str, str]] = []
    seen: set[str] = set()
    for i, r in enumerate(registry, start=1):
        cond = r.get("medical_condition", "")
        level = r.get("severity_level") or "URGENT"
        for sym in (r.get("symptom") or cond).split(";"):
            sym = sym.strip()
            if not sym:
                continue
            key = f"{normalize_text(sym)}|{normalize_text(cond)}"
            if key in seen:
                continue
            seen.add(key)
            rows.append(
                {
                    "id": f"SCM{len(rows)+1:05d}",
                    "symptom": sym,
                    "medical_condition": cond,
                    "severity_level": level,
                    "relationship": "associated",
                    "language": "en",
                    "status": "active",
                }
            )
    report = {
        "file": "symptom_condition_map.csv",
        "total": len(rows),
        "by_level": dict(Counter(r["severity_level"] for r in rows)),
        "missing_relationships": 0,
    }
    if not dry_run:
        write_csv(path, fields, rows)
    return report


def normalize_emergency_flags(dry_run: bool) -> dict[str, Any]:
    path = NLP / "emergency_flags.csv"
    fields, rows = read_csv(path)
    fixed = 0
    for r in rows:
        if normalize_text(r.get("auto_triage", "")) != "emergency":
            r["auto_triage"] = "EMERGENCY"
            fixed += 1
        if not (r.get("severity") or "").strip():
            r["severity"] = "critical"
            fixed += 1
    if not dry_run and rows:
        write_csv(path, fields, rows)
    return {"file": "emergency_flags.csv", "total": len(rows), "corrections": fixed, "all_emergency": True}


def main() -> None:
    parser = argparse.ArgumentParser(description="Validate & fix triage medical CSVs")
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--skip-overlay", action="store_true", help="Skip 74k ICD overlay (faster)")
    args = parser.parse_args()

    phrase_reports = []
    for name in TRIAGE_PHRASE_FILES:
        phrase_reports.append(fix_phrase_file(name, args.dry_run))

    registry_report = build_condition_registry(args.dry_run)
    map_report = link_symptoms_to_conditions(args.dry_run)
    flags_report = normalize_emergency_flags(args.dry_run)
    audit = audit_reference_files()

    overlay_report: dict[str, Any]
    if args.skip_overlay:
        overlay_report = {"skipped": True}
    else:
        overlay_report = annotate_medical_conditions_overlay(args.dry_run)

    # Aggregate
    totals = Counter()
    missing = 0
    duplicates = 0
    filled = 0
    normalized = 0
    for pr in phrase_reports:
        for k, v in (pr.get("by_level") or {}).items():
            totals[k] += v
        missing += pr.get("missing_before", 0) + pr.get("invalid_before", 0)
        duplicates += pr.get("duplicates_removed", 0)
        filled += pr.get("filled", 0)
        normalized += pr.get("normalized", 0)

    for k, v in (registry_report.get("by_level") or {}).items():
        totals[f"registry_{k}"] = v

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "dry_run": args.dry_run,
        "engine_note": (
            "ClinicalTriageEngine is rule-based (PHP/Python). Final triage uses "
            "emergency_flags.csv, triage_rules.csv, phrase severity/triage_level, "
            "and condition_triage_severity.csv — not the LLM."
        ),
        "summary": {
            "phrase_rows_classified": sum(pr.get("total_after", pr.get("total", 0)) for pr in phrase_reports),
            "non_urgent": totals.get("NON_URGENT", 0),
            "urgent": totals.get("URGENT", 0),
            "emergency": totals.get("EMERGENCY", 0),
            "missing_severity_before_fix": missing,
            "missing_severity_after_fix": 0,
            "duplicates_removed": duplicates,
            "auto_filled": filled,
            "normalized_labels": normalized,
            "registry_conditions": registry_report.get("total", 0),
            "registry_by_level": registry_report.get("by_level", {}),
            "symptom_condition_links": map_report.get("total", 0),
            "icd_overlay_rows": overlay_report.get("total", overlay_report.get("skipped")),
            "icd_overlay_by_level": overlay_report.get("by_level", {}),
        },
        "phrase_files": phrase_reports,
        "condition_registry": registry_report,
        "symptom_condition_map": map_report,
        "emergency_flags": flags_report,
        "icd_overlay": overlay_report,
        "reference_audit": audit,
        "corrections_made": [
            "Normalized triage_level values to non_urgent|urgent|emergency across phrase/rule CSVs",
            "Mapped legacy 'routine' → non_urgent",
            "Filled missing triage_level using clinical guideline heuristics",
            "Removed duplicate phrase rows (same hiligaynon+english)",
            "Created/updated condition_triage_severity.csv canonical registry",
            "Created symptom_condition_map.csv linking symptoms to conditions",
            "Ensured emergency_flags.auto_triage=EMERGENCY",
            "Created medical_conditions_triage_overlay.csv for ICD severity coverage"
            if not args.skip_overlay
            else "Skipped ICD overlay (--skip-overlay)",
        ],
    }

    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    json_path = REPORT_DIR / "triage_dataset_validation_report.json"
    md_path = REPORT_DIR / "triage_dataset_validation_report.md"
    if not args.dry_run:
        json_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
        s = report["summary"]
        md = f"""# Triage Dataset Validation Report

Generated: `{report['generated_at']}`

## Engine confirmation
{report['engine_note']}

## Summary
| Metric | Count |
|--------|------:|
| Phrase rows classified | {s['phrase_rows_classified']} |
| Non-Urgent (phrase) | {s['non_urgent']} |
| Urgent (phrase) | {s['urgent']} |
| Emergency (phrase) | {s['emergency']} |
| Missing severity (before) | {s['missing_severity_before_fix']} |
| Missing severity (after) | {s['missing_severity_after_fix']} |
| Duplicates removed | {s['duplicates_removed']} |
| Auto-filled levels | {s['auto_filled']} |
| Labels normalized | {s['normalized_labels']} |
| Registry conditions | {s['registry_conditions']} |
| Symptom↔condition links | {s['symptom_condition_links']} |
| ICD overlay rows | {s['icd_overlay_rows']} |

### Registry by level
```
{json.dumps(s['registry_by_level'], indent=2)}
```

### ICD overlay by level
```
{json.dumps(s['icd_overlay_by_level'], indent=2)}
```

## Corrections made
{chr(10).join('- ' + c for c in report['corrections_made'])}

## Reference audit notes
- Tagalog datasets: {audit['tagalog_datasets']['note']}
- Medications: {audit['medications_datasets']['note']}
- Risk factors: {audit['risk_factors_datasets']['note']}

## Maintainability
Add new conditions to `data/nlp/condition_triage_severity.csv` (or Hiligaynon phrase CSVs with `triage_level`).
Re-run: `python scripts/data/validate_and_fix_triage_datasets.py`
No triage engine code changes required for new rows.
"""
        md_path.write_text(md, encoding="utf-8")

    print(json.dumps(report["summary"], indent=2))
    print(f"Report: {json_path if not args.dry_run else '(dry-run — not written)'}")


if __name__ == "__main__":
    main()
