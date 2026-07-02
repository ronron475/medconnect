#!/usr/bin/env python3
"""
Build a comprehensive healthcare symptoms database from multiple official sources.

Sources:
  - MedlinePlus Symptoms Index + health topic XML (NLM)
  - ICD-10-CM Chapter XVIII symptom/sign codes (R00-R94) from CMS tabular order
  - Human Phenotype Ontology (HPO) phenotype terms
  - Curated clinical and patient-friendly symptom terms

Output:
  - data/nlp/symptoms/symptoms_part_XX.csv
  - data/nlp/symptoms.csv (symptom_id, symptom_name, category, description, related_body_system)

Attribution:
  - National Library of Medicine — MedlinePlus.gov
  - CMS/NCHS ICD-10-CM
  - Human Phenotype Ontology (HPO)
"""

from __future__ import annotations

import csv
import io
import re
import sys
import zipfile
from pathlib import Path
from urllib.request import urlopen

from curated_symptoms import CLINICAL_ALIASES, CURATED_SYMPTOMS
from symptom_body_systems import related_body_system
from symptom_categories import categorize
from symptom_common import clean_description, merge_symptom_rows, normalize_key, normalize_name

ROOT = Path(__file__).resolve().parents[2]
SOURCE_DIR = ROOT / "data" / "nlp" / "source"
OUT_DIR = ROOT / "data" / "nlp" / "symptoms"
NLP_EXPORT = ROOT / "data" / "nlp" / "symptoms.csv"
CONDITIONS_CSV = ROOT / "data" / "nlp" / "medical_conditions.csv"

ROWS_PER_PART = 2000
HPO_OBO_URL = "https://github.com/obophenotype/human-phenotype-ontology/releases/latest/download/hp.obo"
HPO_CACHED = SOURCE_DIR / "hp.obo"

# Import MedlinePlus builder functions from sibling module
sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_medlineplus_symptoms import (  # noqa: E402
    download_healthtopics_xml,
    download_topic_groups_xml,
    load_symptom_slugs_from_index,
    load_symptom_topic_ids,
    load_symptoms_index,
    parse_healthtopics_xml,
    supplement_canonical_index,
)


def fetch_url(url: str, timeout: int = 180) -> bytes:
    with urlopen(url, timeout=timeout) as resp:
        return resp.read()


def download_hpo_obo() -> Path | None:
    SOURCE_DIR.mkdir(parents=True, exist_ok=True)
    if HPO_CACHED.is_file() and HPO_CACHED.stat().st_size > 100_000:
        return HPO_CACHED
    try:
        print(f"Downloading HPO ontology {HPO_OBO_URL} ...")
        data = fetch_url(HPO_OBO_URL, timeout=180)
        HPO_CACHED.write_bytes(data)
        print(f"Saved {HPO_CACHED}")
        return HPO_CACHED
    except Exception as exc:
        print(f"  HPO download failed: {exc}")
        if HPO_CACHED.is_file():
            print(f"  Using cached {HPO_CACHED}")
            return HPO_CACHED
        return None


def load_medlineplus_entries() -> list[dict[str, str]]:
    """Collect MedlinePlus Symptoms Index + matched health topic entries."""
    index_entries = load_symptoms_index()
    xml_entries: list[dict[str, str]] = []
    symptom_slugs = load_symptom_slugs_from_index()
    groups_xml = download_topic_groups_xml()
    symptom_ids = load_symptom_topic_ids(groups_xml)

    xml_path = download_healthtopics_xml()
    if xml_path and xml_path.is_file():
        try:
            xml_entries = parse_healthtopics_xml(xml_path, symptom_ids, symptom_slugs)
        except Exception as exc:
            print(f"  MedlinePlus XML parse error: {exc}")

    merged = supplement_canonical_index(index_entries + xml_entries)
    # Deduplicate index/xml overlap
    by_key: dict[str, dict[str, str]] = {}
    for row in merged:
        key = normalize_key(row["symptom_name"])
        if key not in by_key or len(row.get("description", "")) > len(by_key[key].get("description", "")):
            by_key[key] = row

    out = []
    for row in by_key.values():
        out.append(
            {
                "symptom_name": row["symptom_name"],
                "description": row.get("description", ""),
                "source": row.get("source", "MedlinePlus_Symptoms_Index"),
                "groups": row.get("groups") or [],
            }
        )
    print(f"MedlinePlus entries: {len(out)}")
    return out


def load_icd10_symptom_codes() -> list[dict[str, str]]:
    """Load ICD-10-CM R00-R94 symptom/sign codes from medical_conditions.csv."""
    if not CONDITIONS_CSV.is_file():
        print(f"  Missing {CONDITIONS_CSV} — run build_icd10_conditions.py first.")
        return []

    rows: list[dict[str, str]] = []
    with CONDITIONS_CSV.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            code = (row.get("icd10_code") or "").strip().upper()
            if not code.startswith("R"):
                continue
            name = normalize_name(row.get("condition_name") or "")
            if not name:
                continue
            desc = clean_description(row.get("description") or name)
            if desc == name:
                desc = f"ICD-10-CM symptom/sign code {code}: {name}."
            else:
                desc = f"ICD-10-CM {code}: {desc}"
            rows.append(
                {
                    "symptom_name": name,
                    "description": desc,
                    "source": "ICD-10-CM",
                    "icd10_code": code,
                }
            )
    print(f"ICD-10-CM R-code symptoms: {len(rows)}")
    return rows


def parse_hpo_obo(obo_path: Path) -> list[dict[str, str]]:
    """Parse HPO OBO file into symptom entries (primary names + exact synonyms)."""
    entries: list[dict[str, str]] = []
    current: dict[str, str] = {}
    synonyms: list[str] = []

    def flush_term() -> None:
        nonlocal current, synonyms
        if not current or current.get("is_obsolete"):
            current = {}
            synonyms = []
            return
        name = normalize_name(current.get("name", ""))
        hp_id = current.get("id", "")
        definition = clean_description(current.get("def", ""))
        if not name:
            current = {}
            synonyms = []
            return

        base_desc = definition or f"Human Phenotype Ontology term {hp_id}: {name}."
        if hp_id:
            base_desc = f"HPO {hp_id}: {base_desc}"

        entries.append(
            {
                "symptom_name": name,
                "description": base_desc,
                "source": "HPO",
                "hpo_id": hp_id,
            }
        )
        for syn in synonyms:
            syn_name = normalize_name(syn)
            if not syn_name or normalize_key(syn_name) == normalize_key(name):
                continue
            entries.append(
                {
                    "symptom_name": syn_name,
                    "description": f"HPO exact synonym for {name}. {base_desc[:250]}",
                    "source": "HPO",
                    "hpo_id": hp_id,
                    "clinical_term": name,
                }
            )
        current = {}
        synonyms = []

    text = obo_path.read_text(encoding="utf-8", errors="replace")
    for line in text.splitlines():
        line = line.strip()
        if line == "[Term]":
            flush_term()
            current = {}
            synonyms = []
        elif line == "[Typedef]":
            flush_term()
            current = {}
            synonyms = []
        elif line.startswith("id: "):
            current["id"] = line[4:].strip()
        elif line.startswith("name: "):
            current["name"] = line[6:].strip()
        elif line.startswith("def: "):
            m = re.match(r'def: "([^"]*)"', line)
            if m:
                current["def"] = m.group(1)
        elif line.startswith("synonym: "):
            m = re.match(r'synonym: "([^"]*)"\s+EXACT', line)
            if m:
                synonyms.append(m.group(1))
        elif line == "is_obsolete: true":
            current["is_obsolete"] = "true"
    flush_term()

    print(f"HPO phenotype entries (names + exact synonyms): {len(entries)}")
    return entries


def load_hpo_entries() -> list[dict[str, str]]:
    obo_path = download_hpo_obo()
    if not obo_path:
        return []
    try:
        return parse_hpo_obo(obo_path)
    except Exception as exc:
        print(f"  HPO parse error: {exc}")
        return []


def load_curated_entries() -> list[dict[str, str]]:
    rows: list[dict[str, str]] = []
    for name, category, description in CURATED_SYMPTOMS:
        rows.append(
            {
                "symptom_name": name,
                "category": category,
                "description": description,
                "source": "Clinical_Curated",
            }
        )
    for alias, _canonical, category, description in CLINICAL_ALIASES:
        rows.append(
            {
                "symptom_name": alias,
                "category": category,
                "description": description,
                "source": "Clinical_Curated",
            }
        )
    print(f"Curated symptom entries: {len(rows)}")
    return rows


def assign_metadata(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    out: list[dict[str, str]] = []
    for i, row in enumerate(rows, start=1):
        name = row["symptom_name"]
        groups = row.get("groups") or []
        cat = row.get("category") or categorize(name, groups)
        # Normalize legacy category name
        if cat == "skin":
            cat = "dermatological"
        body = related_body_system(name, cat)
        out.append(
            {
                "symptom_id": i,
                "symptom_name": name,
                "category": cat,
                "description": row["description"],
                "related_body_system": body,
                "search_name": normalize_key(name),
                "source": row.get("source", "Clinical_Curated"),
            }
        )
    return out


def write_csv_parts(rows: list[dict[str, str]]) -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    part_fields = [
        "symptom_id", "symptom_name", "category", "description",
        "related_body_system", "search_name", "source",
    ]
    export_fields = [
        "symptom_id", "symptom_name", "category", "description", "related_body_system",
    ]

    parts = 0
    for start in range(0, len(rows), ROWS_PER_PART):
        chunk = rows[start : start + ROWS_PER_PART]
        parts += 1
        part_path = OUT_DIR / f"symptoms_part_{parts:02d}.csv"
        with part_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=part_fields, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(chunk)
        print(f"Wrote {len(chunk)} rows -> {part_path.name}")

    with NLP_EXPORT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=export_fields, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({k: row[k] for k in export_fields})
    print(f"NLP export: {NLP_EXPORT} ({len(rows)} rows)")


def print_summary(rows: list[dict[str, str]]) -> None:
    from collections import Counter

    cats = Counter(r["category"] for r in rows)
    systems = Counter(r["related_body_system"] for r in rows)
    sources = Counter(r["source"] for r in rows)
    print("\n--- Dataset summary ---")
    print(f"Total unique symptoms: {len(rows)}")
    print("By source:", dict(sources.most_common()))
    print("Top categories:", dict(cats.most_common(12)))
    print("Top body systems:", dict(systems.most_common(12)))


def main() -> None:
    all_rows: list[dict[str, str]] = []
    all_rows.extend(load_medlineplus_entries())
    all_rows.extend(load_icd10_symptom_codes())
    all_rows.extend(load_hpo_entries())
    all_rows.extend(load_curated_entries())

    merged = merge_symptom_rows(all_rows)
    rows = assign_metadata(merged)
    print_summary(rows)
    write_csv_parts(rows)


if __name__ == "__main__":
    main()
