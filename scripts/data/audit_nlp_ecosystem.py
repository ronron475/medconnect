#!/usr/bin/env python3
"""
Full medConnect NLP ecosystem audit — datasets, engines, wiring, gaps, conflicts.

Read-only analysis. Output:
  data/nlp/reports/ecosystem_audit.json
  data/nlp/reports/ecosystem_audit.md

Run: python scripts/data/audit_nlp_ecosystem.py
"""

from __future__ import annotations

import csv
import json
import re
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"
REPORT_DIR = NLP / "reports"
CORE = ROOT / "app" / "core"
AI = ROOT / "ai_service"

# Expected runtime wiring (dataset path fragment → consumer)
RUNTIME_WIRING: dict[str, list[str]] = {
    "symptom_knowledge_base.json": ["SymptomKnowledgeBase.php", "symptom_knowledge_base.py"],
    "red_flags_library.json": ["SymptomKnowledgeBase.php", "symptom_knowledge_base.py"],
    "clinical_context_rules.json": ["ClinicalContextReasoningEngine.php", "clinical_context_reasoning.py"],
    "symptom_combinations.csv": ["ClinicalTriageEngine.php", "clinical_triage_engine.py"],
    "emergency_red_flags.csv": ["ClinicalTriageEngine.php"],
    "emergency_flags.csv": ["EmergencyFlagsLoader.php"],
    "negation_words.csv": ["NegationDetector.php", "negation_detector.py"],
    "misspellings.csv": ["MedicalMisspellingsLoader.php"],
    "medical_misspellings.csv": ["MedicalMisspellingsLoader.php"],
    "medical_abbreviations.csv": ["MedicalMisspellingsLoader.php"],
    "hiligaynon_medical_terms.csv": ["SymptomKnowledgeBase.php"],
    "filipino_medical_terms.csv": ["SymptomKnowledgeBase.php"],
    "medical_phrases.csv": ["SymptomKnowledgeBase.php"],
    "duration_patterns.csv": ["ClinicalFeatureExtractors.php (inline + CSV expand)"],
    "pain_scale.csv": ["ClinicalFeatureExtractors.php"],
    "temperature_patterns.csv": ["ClinicalFeatureExtractors.php"],
    "risk_factors.csv": ["ClinicalFeatureExtractors.php"],
    "chronic_conditions.csv": ["ClinicalFeatureExtractors.php"],
    "triage_rules.csv": ["TriageRulesLoader.php (inventory/admin only — not ClinicalTriageEngine v3)"],
    "clinical_reasoning_rules.csv": ["ClinicalReasoningRulesLoader.php (reason templates)"],
    "confidence_rules.csv": ["ClinicalTriageEngine.php (logic mirrored, CSV is documentation)"],
    "chief_complaint_examples.csv": ["QA / validation reference"],
    "translation_dictionary.csv": ["MedicalDictionary.php", "MedicalTranslator"],
    "medical_symptoms.csv": ["SymptomKnowledgeBase CSV boosts / expansion"],
    "medical_entities.csv": ["MedicalEntityExtractor.php"],
    "urgent_conditions.csv": ["Reference — contextual engine + combinations"],
    "non_urgent_conditions.csv": ["Reference — contextual engine + combinations"],
    "symptom_weights.csv": ["Merged into symptom_knowledge_base.json scoring"],
    "severity_scores.csv": ["Merged into symptom_knowledge_base.json scoring"],
    "medical_stopwords.csv": ["MedicalTextAnalysisWorkflow / preprocessing"],
    "validation/": ["scripts/dev/evaluate_triage_validation.php", "triage_qa_report.php"],
}

ORPHANED_FROM_V3 = [
    "triage_rules.csv",
    "triage_rules_cds.csv",
    "hiligaynon_symptoms_combinatorial.csv",
    "condition_triage_severity.csv",
    "step6_triage_exemplars.csv",
]

ENGINE_FILES = [
    "ClinicalTriageEngine.php",
    "ClinicalContextReasoningEngine.php",
    "TriageSelfValidationEngine.php",
    "SymptomKnowledgeBase.php",
    "ClinicalFeatureExtractors.php",
    "NegationDetector.php",
    "MedicalEntityExtractor.php",
    "MedicalAssessmentEngine.php",
    "HiligaynonMedicalNlpPipeline.php",
    "MedicalTextAnalysisWorkflow.php",
]


def read_csv(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    with path.open(encoding="utf-8", newline="") as f:
        return [{k: (v or "").strip() for k, v in row.items()} for row in csv.DictReader(f)]


def load_json(path: Path) -> dict | list:
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def norm(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "").lower().strip())


def scan_data_files() -> list[dict]:
    files: list[dict] = []
    for path in sorted(NLP.rglob("*")):
        if not path.is_file():
            continue
        if path.suffix.lower() not in {".csv", ".json", ".jsonl", ".txt"}:
            continue
        if ".bak" in path.name or path.name.endswith(".prev"):
            continue
        rel = path.relative_to(NLP).as_posix()
        rows = 0
        if path.suffix.lower() == ".csv":
            rows = max(0, len(read_csv(path)) )
        elif path.suffix.lower() == ".json":
            data = load_json(path)
            if isinstance(data, list):
                rows = len(data)
            elif isinstance(data, dict):
                rows = sum(len(v) for v in data.values() if isinstance(v, list))
        files.append({"path": rel, "rows": rows, "size_kb": round(path.stat().st_size / 1024, 1)})
    return files


def wiring_status(rel_path: str) -> dict:
    wired = []
    for fragment, consumers in RUNTIME_WIRING.items():
        if fragment in rel_path or rel_path.startswith(fragment.rstrip("/")):
            wired.extend(consumers)
    status = "loaded" if wired else ("orphaned" if any(o in rel_path for o in ORPHANED_FROM_V3) else "reference")
    return {"wired_to": wired, "status": status}


def audit_kb() -> dict:
    kb = load_json(NLP / "symptom_knowledge_base.json")
    rf = load_json(NLP / "red_flags_library.json")
    ctx = load_json(NLP / "clinical_context_rules.json")
    symptoms = kb.get("symptoms") or [] if isinstance(kb, dict) else []
    red_flags = rf.get("red_flags") or [] if isinstance(rf, dict) else []
    ctx_rules = ctx.get("rules") or [] if isinstance(ctx, dict) else []

    dup_ids = [k for k, v in Counter(s.get("id", "") for s in symptoms).items() if v > 1 and k]
    missing_hil = [s.get("id") for s in symptoms if not (s.get("hiligaynon_terms") or [])]
    missing_fil = [s.get("id") for s in symptoms if not (s.get("filipino_terms") or [])]

    ctx_primary = set()
    for rule in ctx_rules:
        for p in rule.get("primary_symptoms") or []:
            ctx_primary.add(str(p).lower())

    kb_ids = {str(s.get("id", "")).lower() for s in symptoms}
    high_weight_no_context = [
        s.get("id")
        for s in symptoms
        if int(s.get("severity_weight") or 0) >= 5
        and str(s.get("id", "")).lower() not in ctx_primary
        and str(s.get("id", "")).lower() not in {"chest_pain", "fever", "cough", "headache"}
    ][:30]

    return {
        "json_symptoms": len(symptoms),
        "json_red_flags": len(red_flags),
        "context_rules": len(ctx_rules),
        "context_primary_symptoms": len(ctx_primary),
        "duplicate_symptom_ids": dup_ids,
        "missing_hiligaynon_count": len(missing_hil),
        "missing_filipino_count": len(missing_fil),
        "missing_hiligaynon_sample": missing_hil[:25],
        "missing_filipino_sample": missing_fil[:25],
        "high_weight_symptoms_without_context_rule": high_weight_no_context,
        "kb_ids_not_in_context_rules": sorted(kb_ids - ctx_primary - {""})[:40],
    }


def audit_csv_banks() -> dict:
    banks = {
        "english_medical_terms.csv": read_csv(NLP / "english_medical_terms.csv"),
        "filipino_medical_terms.csv": read_csv(NLP / "filipino_medical_terms.csv"),
        "hiligaynon_medical_terms.csv": read_csv(NLP / "hiligaynon_medical_terms.csv"),
        "medical_synonyms.csv": read_csv(NLP / "medical_synonyms.csv"),
        "symptom_synonyms.csv": read_csv(NLP / "symptom_synonyms.csv"),
        "translation_dictionary.csv": read_csv(NLP / "translation_dictionary.csv"),
        "chief_complaint_examples.csv": read_csv(NLP / "chief_complaint_examples.csv"),
        "medical_entities.csv": read_csv(NLP / "medical_entities.csv"),
    }
    out = {}
    for name, rows in banks.items():
        out[name] = {
            "rows": len(rows),
            "missing": not (NLP / name).is_file(),
        }
    # Duplicate english concepts across term banks
    eng_terms: dict[str, list[str]] = defaultdict(list)
    for src, rows in banks.items():
        if "terms" not in src and src != "translation_dictionary.csv":
            continue
        for row in rows:
            eng = norm(row.get("english_term") or row.get("english") or row.get("standard_term") or "")
            if eng:
                eng_terms[eng].append(src)
    dup_concepts = [f"{k}: {v}" for k, v in eng_terms.items() if len(v) > 2][:20]
    out["duplicate_concepts_across_banks"] = dup_concepts
    return out


def audit_combinations() -> dict:
    combos = read_csv(NLP / "symptom_combinations.csv")
    pair_classes: dict[str, set[str]] = defaultdict(set)
    for row in combos:
        a, b = norm(row.get("symptom_a", "")), norm(row.get("symptom_b", ""))
        if not a or not b:
            continue
        key = "|".join(sorted([a, b]))
        pair_classes[key].add((row.get("classification") or "").upper())
    conflicts = [f"{k}: {sorted(v)}" for k, v in pair_classes.items() if len(v) > 1][:25]
    return {"rows": len(combos), "unique_pairs": len(pair_classes), "classification_conflicts": conflicts}


def audit_validation() -> dict:
    val_dir = NLP / "validation"
    sets = {}
    for path in sorted(val_dir.glob("*.csv")) if val_dir.is_dir() else []:
        rows = read_csv(path)
        classes = Counter(norm(r.get("expected_classification") or r.get("classification") or "") for r in rows)
        sets[path.name] = {"rows": len(rows), "classes": dict(classes)}
    return sets


def audit_engines() -> dict:
    present = {}
    for name in ENGINE_FILES:
        present[name] = (CORE / name).is_file()
    py_mirror = {
        "clinical_triage_engine.py": (AI / "clinical_triage_engine.py").is_file(),
        "clinical_context_reasoning.py": (AI / "clinical_context_reasoning.py").is_file(),
        "triage_self_validation.py": (AI / "triage_self_validation.py").is_file(),
        "symptom_knowledge_base.py": (AI / "symptom_knowledge_base.py").is_file(),
        "negation_detector.py": (AI / "negation_detector.py").is_file(),
    }
    return {"php_engines": present, "python_mirrors": py_mirror}


def build_report() -> dict:
    data_files = scan_data_files()
    wired_summary = Counter()
    for f in data_files:
        w = wiring_status(f["path"])
        wired_summary[w["status"]] += 1
        f["wiring"] = w

    kb = audit_kb()
    csv_banks = audit_csv_banks()
    combos = audit_combinations()
    validation = audit_validation()
    engines = audit_engines()

    recommendations = []
    if kb["missing_hiligaynon_count"] > 0:
        recommendations.append(
            f"Sync Hiligaynon terms for {kb['missing_hiligaynon_count']} JSON symptoms from hiligaynon_medical_terms.csv"
        )
    if kb["missing_filipino_count"] > 0:
        recommendations.append(
            f"Sync Filipino terms for {kb['missing_filipino_count']} JSON symptoms from filipino_medical_terms.csv"
        )
    if kb["high_weight_symptoms_without_context_rule"]:
        recommendations.append(
            "Add clinical_context_rules.json entries for high-weight symptoms lacking contextual reasoning"
        )
    if combos["classification_conflicts"]:
        recommendations.append("Resolve symptom_combinations.csv pairs with conflicting classifications")
    if wired_summary["orphaned"] > 0:
        recommendations.append(
            f"Review {wired_summary['orphaned']} orphaned legacy datasets — bridge or document as reference-only"
        )
    recommendations.extend([
        "Keep contextual reasoning as primary classifier — never single-keyword triage",
        "Run evaluate_triage_validation.php --gold after every KB change",
        "Use chief_complaint_examples.csv + validation sets for continuous QA",
    ])

    return {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "mission": "Audit + optimize NLP ecosystem preserving PHP rule-based architecture",
        "summary": {
            "total_data_files": len(data_files),
            "total_csv_json_rows_estimate": sum(f["rows"] for f in data_files),
            "wiring_status_counts": dict(wired_summary),
            "json_symptoms": kb["json_symptoms"],
            "json_red_flags": kb["json_red_flags"],
            "context_rules": kb["context_rules"],
            "combination_rows": combos["rows"],
            "validation_sets": len(validation),
            "php_engines_present": sum(engines["php_engines"].values()),
            "python_mirrors_present": sum(engines["python_mirrors"].values()),
        },
        "knowledge_base": kb,
        "csv_term_banks": csv_banks,
        "symptom_combinations": combos,
        "validation_datasets": validation,
        "engines": engines,
        "dataset_catalog": data_files[:80],
        "orphaned_legacy_datasets": ORPHANED_FROM_V3,
        "decision_priority_order": [
            "emergency_red_flags", "airway", "breathing", "circulation", "neurological",
            "severe_bleeding", "pregnancy_emergency", "poisoning", "burns", "trauma",
            "high_risk_patient", "symptom_combination", "clinical_context", "duration",
            "temperature", "pain_scale", "risk_factors", "individual_symptoms",
            "administrative_request", "confidence_score",
        ],
        "recommendations": recommendations,
    }


def write_md(report: dict, path: Path) -> None:
    s = report["summary"]
    lines = [
        "# medConnect NLP Ecosystem Audit",
        "",
        f"Generated: {report['generated_at']}",
        "",
        "## Executive Summary",
        "",
        f"- **Data files scanned:** {s['total_data_files']}",
        f"- **JSON symptoms (runtime KB):** {s['json_symptoms']}",
        f"- **JSON red flags:** {s['json_red_flags']}",
        f"- **Contextual reasoning rules:** {s['context_rules']}",
        f"- **Symptom combination rows:** {s['combination_rows']}",
        f"- **Validation sets:** {s['validation_sets']}",
        f"- **PHP engines:** {s['php_engines_present']} core files",
        f"- **Python mirrors:** {s['python_mirrors_present']} files",
        "",
        "### Dataset Wiring",
        "",
        "| Status | Count |",
        "|--------|------:|",
    ]
    for status, count in sorted(s["wiring_status_counts"].items()):
        lines.append(f"| {status} | {count} |")

    kb = report["knowledge_base"]
    lines.extend([
        "",
        "## Knowledge Base Gaps",
        "",
        f"- Symptoms missing Hiligaynon terms: **{kb['missing_hiligaynon_count']}**",
        f"- Symptoms missing Filipino terms: **{kb['missing_filipino_count']}**",
        f"- Duplicate symptom IDs: **{len(kb['duplicate_symptom_ids'])}**",
        f"- High-weight symptoms without context rule: **{len(kb['high_weight_symptoms_without_context_rule'])}**",
        "",
        "## Combination Conflicts",
        "",
    ])
    for c in report["symptom_combinations"]["classification_conflicts"][:10]:
        lines.append(f"- {c}")
    if not report["symptom_combinations"]["classification_conflicts"]:
        lines.append("- None detected in sample")

    lines.extend(["", "## Recommendations", ""])
    for r in report["recommendations"]:
        lines.append(f"- {r}")

    lines.extend([
        "",
        "## Clinical Decision Priority (Runtime)",
        "",
    ])
    for i, rule in enumerate(report["decision_priority_order"], 1):
        lines.append(f"{i}. {rule}")

    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    report = build_report()
    json_path = REPORT_DIR / "ecosystem_audit.json"
    md_path = REPORT_DIR / "ecosystem_audit.md"
    json_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    write_md(report, md_path)
    print(f"Ecosystem audit: {json_path}")
    print(f"Markdown: {md_path}")
    print(json.dumps(report["summary"], indent=2))


if __name__ == "__main__":
    main()
