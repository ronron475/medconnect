#!/usr/bin/env python3
"""
Download and parse official ICD-10-CM Code Descriptions (Tabular Order) from CMS.
Produces import-ready CSV parts + consolidated NLP export.

Source: CMS ICD-10-CM Files (NCHS/CDC) — Code Descriptions in Tabular Order.
https://www.cms.gov/medicare/coding-billing/icd-10-codes
"""

from __future__ import annotations

import csv
import io
import re
import zipfile
from pathlib import Path
from urllib.request import urlopen

from icd10_chapters import chapter_for_code, format_icd_code

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "data" / "nlp" / "icd10"
SOURCE_DIR = ROOT / "data" / "nlp" / "source"
NLP_EXPORT = ROOT / "data" / "nlp" / "medical_conditions.csv"

CMS_ZIP_URLS = [
    "https://www.cms.gov/files/zip/2025-code-descriptions-tabular-order.zip",
    "https://www.cms.gov/files/zip/2024-code-descriptions-tabular-order.zip",
]

ROWS_PER_PART = 10000


def download_order_file() -> Path:
    SOURCE_DIR.mkdir(parents=True, exist_ok=True)
    cached = list(SOURCE_DIR.glob("icd10cm_order_*.txt"))
    if cached:
        return max(cached, key=lambda p: p.stat().st_mtime)

    last_err: Exception | None = None
    for url in CMS_ZIP_URLS:
        try:
            print(f"Downloading {url} ...")
            with urlopen(url, timeout=120) as resp:
                data = resp.read()
            with zipfile.ZipFile(io.BytesIO(data)) as zf:
                names = [n for n in zf.namelist() if n.lower().endswith(".txt") and "order" in n.lower()]
                if not names:
                    names = [n for n in zf.namelist() if n.lower().endswith(".txt")]
                name = names[0]
                out = SOURCE_DIR / Path(name).name
                out.write_bytes(zf.read(name))
                print(f"Saved {out}")
                return out
        except Exception as exc:
            last_err = exc
            print(f"  failed: {exc}")
    raise RuntimeError(f"Could not download ICD-10-CM order file: {last_err}")


def parse_order_line(line: str) -> dict[str, str] | None:
    line = line.rstrip("\n\r")
    if len(line) < 77:
        return None
    order_num = line[0:5].strip()
    code_raw = line[6:13].strip()
    header_flag = line[14:15].strip()
    short_desc = line[16:76].strip()
    long_desc = line[77:].strip() if len(line) > 77 else short_desc
    if not code_raw or header_flag != "1":
        return None
    code = format_icd_code(code_raw)
    ch_code, ch_title, category = chapter_for_code(code)
    name = short_desc or long_desc
    if not name:
        return None
    return {
        "order_number": order_num,
        "icd10_code": code,
        "condition_name": name,
        "icd10_category": category,
        "chapter_code": ch_code,
        "chapter_title": ch_title,
        "long_description": long_desc,
        "is_billable": "1",
        "source": "CMS_ICD-10-CM_tabular_order",
    }


def normalize_rows(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    seen_codes: set[str] = set()
    seen_names: set[str] = set()
    clean: list[dict[str, str]] = []
    for row in rows:
        code = row["icd10_code"].upper()
        name = re.sub(r"\s+", " ", row["condition_name"]).strip()
        if not name or len(name) < 3:
            continue
        if code in seen_codes:
            continue
        name_key = name.lower()
        if name_key in seen_names:
            continue
        seen_codes.add(code)
        seen_names.add(name_key)
        row["condition_name"] = name
        row["search_name"] = name.lower()
        clean.append(row)
    for i, row in enumerate(clean, start=1):
        row["condition_id"] = str(i)
    return clean


def write_parts(rows: list[dict[str, str]]) -> list[Path]:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    fields = [
        "condition_id",
        "icd10_code",
        "condition_name",
        "icd10_category",
        "chapter_code",
        "chapter_title",
        "long_description",
        "is_billable",
        "search_name",
        "source",
    ]
    paths: list[Path] = []
    part = 1
    for offset in range(0, len(rows), ROWS_PER_PART):
        chunk = rows[offset : offset + ROWS_PER_PART]
        path = OUT_DIR / f"medical_conditions_part_{part:02d}.csv"
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fields, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(chunk)
        paths.append(path)
        part += 1
    return paths


def write_nlp_export(rows: list[dict[str, str]]) -> None:
    """Backward-compatible slim CSV for existing PHP validators."""
    fields = ["condition_id", "condition_name", "category", "description", "icd10_code", "source"]
    with NLP_EXPORT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fields)
        writer.writeheader()
        for row in rows:
            writer.writerow(
                {
                    "condition_id": row["condition_id"],
                    "condition_name": row["condition_name"],
                    "category": row["icd10_category"],
                    "description": row["long_description"],
                    "icd10_code": row["icd10_code"],
                    "source": row["source"],
                }
            )


def main() -> None:
    order_path = download_order_file()
    parsed: list[dict[str, str]] = []
    with order_path.open(encoding="utf-8", errors="replace") as handle:
        for line in handle:
            row = parse_order_line(line)
            if row:
                parsed.append(row)
    rows = normalize_rows(parsed)
    print(f"Parsed {len(rows)} billable ICD-10-CM codes")
    parts = write_parts(rows)
    write_nlp_export(rows)
    print(f"Wrote {len(parts)} part file(s) to {OUT_DIR}")
    print(f"NLP export: {NLP_EXPORT}")


if __name__ == "__main__":
    main()
