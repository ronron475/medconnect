#!/usr/bin/env python3
"""
Audit medConnect NLP/CDS knowledge base for duplicates, conflicts, and gaps.

Read-only analysis — does not modify datasets.
Output: data/nlp/reports/kb_audit_report.json + kb_audit_report.md
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


def read_csv(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    with path.open(encoding="utf-8", newline="") as f:
        return [{k: (v or "").strip() for k, v in row.items()} for row in csv.DictReader(f)]


def load_json(path: Path) -> dict:
    if not path.is_file():
        return {}
    return json.loads(path.read_text(encoding="utf-8"))


def norm_pattern(s: str) -> str:
    return re.sub(r"\s+", " ", (s or "").lower().strip())


def audit() -> dict:
    kb = load_json(NLP / "symptom_knowledge_base.json")
    rf = load_json(NLP / "red_flags_library.json")
    symptoms = kb.get("symptoms") or []
    red_flags = rf.get("red_flags") or []

    kb_ids = {s.get("id", "") for s in symptoms}
    kb_names = [norm_pattern(s.get("symptom_name", "")) for s in symptoms]

    # Duplicate symptom IDs / names in JSON KB
    dup_ids = [k for k, v in Counter(s.get("id", "") for s in symptoms).items() if v > 1 and k]
    dup_names = [k for k, v in Counter(kb_names).items() if v > 1 and k]

    # Weight inconsistencies: fever should not be EMERGENCY alone
    weight_issues = []
    for s in symptoms:
        sid = s.get("id", "")
        em = int(s.get("emergency_weight") or 0)
        sev = int(s.get("severity_weight") or 0)
        danger = bool(s.get("danger_sign"))
        if sid in {"fever", "cough", "runny_nose", "fatigue"} and (em >= 8 or danger):
            weight_issues.append(f"{sid}: emergency_weight={em} danger_sign={danger} (mild symptom)")
        if sid in {"chest_pain", "difficulty_breathing", "stroke_symptoms"} and em < 8:
            weight_issues.append(f"{sid}: emergency_weight={em} too low for red-flag symptom")

    # Pattern overlap JSON red flags vs CSV emergency_red_flags
    json_patterns: set[str] = set()
    for flag in red_flags:
        for lang in ("english", "hiligaynon", "filipino"):
            for p in (flag.get("patterns") or {}).get(lang) or []:
                p = norm_pattern(p)
                if p:
                    json_patterns.add(p)

    csv_patterns: set[str] = set()
    csv_conflicts: list[str] = []
    em_csv = read_csv(NLP / "emergency_red_flags.csv")
    for row in em_csv:
        for col in ("pattern_english", "pattern_hiligaynon"):
            p = norm_pattern(row.get(col, ""))
            if not p or " case" in p:
                continue
            csv_patterns.add(p)
        cls = (row.get("classification") or "").upper()
        if cls and cls not in {"EMERGENCY", "URGENT", "NON-URGENT"}:
            csv_conflicts.append(f"Invalid classification in emergency_red_flags.csv: {cls}")

    overlap = sorted(json_patterns & csv_patterns)
    json_only = len(json_patterns - csv_patterns)
    csv_only = len(csv_patterns - json_patterns)

    # Duplicate patterns within CSV (excluding generator padding)
    pat_counts = Counter()
    for row in em_csv:
        for col in ("pattern_english", "pattern_hiligaynon"):
            p = norm_pattern(row.get(col, ""))
            if p and "case" not in p:
                pat_counts[p] += 1
    dup_csv_patterns = [p for p, c in pat_counts.items() if c > 3]

    # Symptom combination diversity
    combos = read_csv(NLP / "symptom_combinations.csv")
    pair_keys = set()
    combo_class_conflicts: list[str] = []
    pair_classes: dict[str, set[str]] = defaultdict(set)
    for row in combos:
        a = norm_pattern(row.get("symptom_a", ""))
        b = norm_pattern(row.get("symptom_b", ""))
        if not a or not b:
            continue
        key = tuple(sorted([a, b]))
        pair_keys.add(key)
        cls = (row.get("classification") or "").upper()
        pair_classes["|".join(key)].add(cls)
    for pair, classes in pair_classes.items():
        if len(classes) > 1:
            combo_class_conflicts.append(f"{pair}: {sorted(classes)}")

    # Missing multilingual coverage in JSON KB
    missing_hil = [s.get("id") for s in symptoms if not (s.get("hiligaynon_terms") or [])]
    missing_fil = [s.get("id") for s in symptoms if not (s.get("filipino_terms") or [])]

    # Target gaps
    targets = {
        "symptoms_json": {"current": len(symptoms), "target_min": 250, "target_max": 500},
        "red_flags_json": {"current": len(red_flags), "target_min": 50, "target_max": 100},
        "symptom_combinations_unique_pairs": {"current": len(pair_keys), "target_min": 2000, "target_max": 5000},
        "combination_rows": {"current": len(combos), "target_min": 2000, "target_max": 5000},
        "emergency_red_flags_csv_real_patterns": {"current": len(csv_patterns), "target_min": 200},
    }

    gaps = []
    for name, t in targets.items():
        cur = t["current"]
        tmin = t.get("target_min", 0)
        if cur < tmin:
            gaps.append(f"{name}: {cur} (need +{tmin - cur} to reach {tmin})")

    # Outdated / low-value generator padding in emergency CSV
    padding_rows = sum(
        1 for row in em_csv
        if "case" in norm_pattern(row.get("pattern_english", "") + row.get("pattern_hiligaynon", ""))
    )

    # Translation dictionary completeness
    trans = read_csv(NLP / "translation_dictionary.csv")
    trans_langs = Counter((row.get("language") or row.get("source_language") or "unknown").lower() for row in trans)

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "summary": {
            "json_symptoms": len(symptoms),
            "json_red_flags": len(red_flags),
            "combination_rows": len(combos),
            "combination_unique_pairs": len(pair_keys),
            "emergency_csv_rows": len(em_csv),
            "emergency_csv_real_patterns": len(csv_patterns),
            "padding_rows_emergency_csv": padding_rows,
            "targets_met": len(gaps) == 0,
        },
        "duplicates": {
            "json_symptom_ids": dup_ids,
            "json_symptom_names": dup_names[:20],
            "csv_emergency_patterns_over_repeated": dup_csv_patterns[:30],
        },
        "conflicts": {
            "weight_inconsistencies": weight_issues,
            "combination_classification_conflicts": combo_class_conflicts[:30],
            "invalid_emergency_csv_classes": csv_conflicts[:20],
        },
        "coverage": {
            "json_red_flag_patterns": len(json_patterns),
            "csv_emergency_patterns": len(csv_patterns),
            "pattern_overlap_count": len(overlap),
            "json_only_patterns": json_only,
            "csv_only_patterns": csv_only,
            "symptoms_missing_hiligaynon": missing_hil[:50],
            "symptoms_missing_filipino": missing_fil[:50],
            "translation_by_language": dict(trans_langs),
        },
        "gaps_vs_targets": gaps,
        "recommendations": [
            "Expand symptom_knowledge_base.json to 250+ weighted symptoms (runtime scoring source).",
            "Expand red_flags_library.json to 50+ structured red flags with mild_exclusions.",
            "Replace generator padding (caseN) in emergency_red_flags.csv with real multilingual phrases.",
            "Add clinically distinct symptom combination pairs aligned to JSON symptom ids.",
            "Ensure every JSON symptom has Hiligaynon and Filipino terms for equal multilingual coverage.",
            "Resolve combination pairs with conflicting classifications before deployment.",
        ],
    }
    return report


def write_markdown(report: dict, path: Path) -> None:
    s = report["summary"]
    lines = [
        "# NLP Knowledge Base Audit Report",
        "",
        f"Generated: {report['generated_at']}",
        "",
        "## Summary",
        "",
        f"| Metric | Count |",
        f"|--------|------:|",
        f"| JSON symptoms | {s['json_symptoms']} |",
        f"| JSON red flags | {s['json_red_flags']} |",
        f"| Combination rows | {s['combination_rows']} |",
        f"| Unique combination pairs | {s['combination_unique_pairs']} |",
        f"| Emergency CSV real patterns | {s['emergency_csv_real_patterns']} |",
        f"| Emergency CSV padding rows | {s['padding_rows_emergency_csv']} |",
        "",
        "## Gaps vs Targets",
        "",
    ]
    for g in report["gaps_vs_targets"]:
        lines.append(f"- {g}")
    if not report["gaps_vs_targets"]:
        lines.append("- All primary targets met.")

    lines.extend(["", "## Conflicts", ""])
    for w in report["conflicts"]["weight_inconsistencies"][:15]:
        lines.append(f"- Weight: {w}")
    for c in report["conflicts"]["combination_classification_conflicts"][:10]:
        lines.append(f"- Combo: {c}")

    lines.extend(["", "## Recommendations", ""])
    for r in report["recommendations"]:
        lines.append(f"- {r}")

    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def main() -> None:
    REPORT_DIR.mkdir(parents=True, exist_ok=True)
    report = audit()
    json_path = REPORT_DIR / "kb_audit_report.json"
    md_path = REPORT_DIR / "kb_audit_report.md"
    json_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    write_markdown(report, md_path)
    print(f"Audit written to {json_path}")
    print(f"Markdown: {md_path}")
    print(json.dumps(report["summary"], indent=2))


if __name__ == "__main__":
    main()
