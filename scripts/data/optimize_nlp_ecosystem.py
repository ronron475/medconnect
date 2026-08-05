#!/usr/bin/env python3
"""
Safe NLP ecosystem optimizations (preserves PHP architecture).

Actions:
  1. Sync Hiligaynon/Filipino terms from CSV banks into symptom_knowledge_base.json
  2. Deduplicate redundant generic keywords in facial_swelling entries
  3. Regenerate ecosystem audit report

Run: python scripts/data/optimize_nlp_ecosystem.py
"""

from __future__ import annotations

import csv
import json
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"
KB_PATH = NLP / "symptom_knowledge_base.json"


def read_csv(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    with path.open(encoding="utf-8", newline="") as f:
        return [{k: (v or "").strip() for k, v in row.items()} for row in csv.DictReader(f)]


def load_kb() -> dict:
    return json.loads(KB_PATH.read_text(encoding="utf-8"))


def save_kb(kb: dict) -> None:
    KB_PATH.write_text(json.dumps(kb, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")


def build_term_map() -> tuple[dict[str, list[str]], dict[str, list[str]]]:
    """Map english concept / symptom id → local terms."""
    hil: dict[str, list[str]] = {}
    fil: dict[str, list[str]] = {}

    for row in read_csv(NLP / "hiligaynon_medical_terms.csv"):
        eng = (row.get("english_term") or row.get("english") or row.get("standard_english") or "").strip().lower()
        local = (row.get("hiligaynon_term") or row.get("local_term") or row.get("term") or "").strip()
        sid = (row.get("symptom_id") or row.get("concept_id") or "").strip().lower()
        if local:
            if eng:
                hil.setdefault(eng, []).append(local)
            if sid:
                hil.setdefault(sid, []).append(local)

    for row in read_csv(NLP / "filipino_medical_terms.csv"):
        eng = (row.get("english_term") or row.get("english") or row.get("standard_english") or "").strip().lower()
        local = (row.get("filipino_term") or row.get("local_term") or row.get("term") or "").strip()
        sid = (row.get("symptom_id") or row.get("concept_id") or "").strip().lower()
        if local:
            if eng:
                fil.setdefault(eng, []).append(local)
            if sid:
                fil.setdefault(sid, []).append(local)

    return hil, fil


def sync_multilingual_terms(kb: dict) -> dict[str, int]:
    hil_map, fil_map = build_term_map()
    stats = {"hil_added": 0, "fil_added": 0}

    for sym in kb.get("symptoms") or []:
        sid = str(sym.get("id") or "").lower()
        name = str(sym.get("symptom_name") or "").lower()
        name_key = name.replace(" ", "_")

        for key in {sid, name, name_key}:
            if not key:
                continue
            for term in hil_map.get(key, []):
                terms = sym.setdefault("hiligaynon_terms", [])
                if term not in terms:
                    terms.append(term)
                    stats["hil_added"] += 1
            for term in fil_map.get(key, []):
                terms = sym.setdefault("filipino_terms", [])
                if term not in terms:
                    terms.append(term)
                    stats["fil_added"] += 1

    return stats


def prune_weak_keywords(kb: dict) -> int:
    """Remove overly generic single-word keywords that cause false positives."""
    generic = {"facial", "swelling", "pain", "symptom"}
    removed = 0
    for sym in kb.get("symptoms") or []:
        sid = str(sym.get("id") or "")
        if sid not in {"facial_swelling", "swelling_face"}:
            continue
        kw = sym.get("keywords") or []
        new_kw = [k for k in kw if k.lower() not in generic or " " in k]
        removed += len(kw) - len(new_kw)
        sym["keywords"] = new_kw
    return removed


def main() -> None:
    if not KB_PATH.is_file():
        print("symptom_knowledge_base.json not found", file=sys.stderr)
        sys.exit(1)

    kb = load_kb()
    sync_stats = sync_multilingual_terms(kb)
    pruned = prune_weak_keywords(kb)
    save_kb(kb)

    print("Optimization complete:")
    print(f"  Hiligaynon terms added: {sync_stats['hil_added']}")
    print(f"  Filipino terms added: {sync_stats['fil_added']}")
    print(f"  Weak keywords pruned: {pruned}")

    audit = ROOT / "scripts" / "data" / "audit_nlp_knowledge_base.py"
    eco = ROOT / "scripts" / "data" / "audit_nlp_ecosystem.py"
    for script in (audit, eco):
        if script.is_file():
            subprocess.run([sys.executable, str(script)], check=False)


if __name__ == "__main__":
    main()
