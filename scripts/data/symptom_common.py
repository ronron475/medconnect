"""Shared normalization and merge helpers for symptom datasets."""

from __future__ import annotations

import html
import re

SOURCE_PRIORITY: dict[str, int] = {
    "MedlinePlus_Health_Topics_XML": 5,
    "MedlinePlus_Symptoms_Index": 4,
    "ICD-10-CM": 3,
    "Clinical_Curated": 3,
    "HPO": 2,
}


def normalize_name(name: str) -> str:
    """Title-case symptom names with consistent particle handling."""
    name = html.unescape(name or "").strip()
    name = re.sub(r"\s+", " ", name)
    lower_words = {"and", "or", "of", "the", "in", "with", "without", "to", "for", "a", "an"}
    parts = name.split()
    out: list[str] = []
    for i, w in enumerate(parts):
        if i > 0 and w.lower() in lower_words:
            out.append(w.lower())
        else:
            out.append(w[:1].upper() + w[1:] if w else w)
    return " ".join(out)


def normalize_key(name: str) -> str:
    """Dedup key: lowercase, strip punctuation, collapse whitespace."""
    text = html.unescape(name or "").lower().strip()
    text = re.sub(r"[^\w\s]", " ", text)
    text = re.sub(r"\s+", " ", text)
    return text.strip()


def clean_description(text: str, max_len: int = 600) -> str:
    text = html.unescape(text or "")
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    if len(text) > max_len:
        text = text[: max_len - 3].rsplit(" ", 1)[0] + "..."
    return text


def merge_symptom_rows(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    """Deduplicate by normalized name; prefer higher-priority, longer descriptions."""
    by_key: dict[str, dict[str, str]] = {}

    for row in rows:
        name = normalize_name(row["symptom_name"])
        if not name or len(name) < 2:
            continue
        key = normalize_key(name)
        if not key:
            continue

        row = {
            **row,
            "symptom_name": name,
            "description": clean_description(row.get("description", "")),
            "source": row.get("source", "Clinical_Curated"),
        }

        if key not in by_key:
            by_key[key] = row
            continue

        existing = by_key[key]
        new_pri = SOURCE_PRIORITY.get(row["source"], 1)
        old_pri = SOURCE_PRIORITY.get(existing["source"], 1)

        if new_pri > old_pri:
            existing["source"] = row["source"]
            existing["description"] = row["description"]
            if row.get("category"):
                existing["category"] = row["category"]
        elif new_pri == old_pri and len(row["description"]) > len(existing["description"]):
            existing["description"] = row["description"]

        if row.get("clinical_term") and not existing.get("clinical_term"):
            existing["clinical_term"] = row["clinical_term"]
        if row.get("groups") and not existing.get("groups"):
            existing["groups"] = row["groups"]

    merged = list(by_key.values())
    merged.sort(key=lambda r: r["symptom_name"].lower())
    return merged
