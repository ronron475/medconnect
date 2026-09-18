"""Dataset-driven body-location extraction (mirrors PHP BodyLocationLexicon).

Priority:
  1) body_parts.csv
  2) body_part_pain_symptoms.csv Hiligaynon aliases
  3) hiligaynon_body_location_100000_compact.csv unique hil→en pairs
"""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_NLP = Path(__file__).resolve().parent.parent / "data" / "nlp"
_BODY_PARTS = _NLP / "body_parts.csv"
_PAIN = _NLP / "body_part_pain_symptoms.csv"
_TRAINING = _NLP / "hiligaynon_body_location_100000_compact.csv"

_EN_CANON = {
    "stomach": "abdomen",
    "belly": "abdomen",
    "tummy": "abdomen",
    "fingernail": "nail",
    "eyes": "eye",
    "ears": "ear",
    "feet": "foot",
    "hands": "hand",
    "legs": "leg",
}


def normalize(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def canonicalize_english(english: str) -> str:
    e = normalize(english)
    return _EN_CANON.get(e, e)


def _possessive_bases(norm_hil: str) -> list[str]:
    tokens = [t for t in norm_hil.split() if t]
    n = len(tokens)
    if n == 2 and tokens[1] == "ko":
        return [tokens[0]]
    if n == 2 and tokens[0] == "akon":
        return [tokens[1]]
    if n == 2 and tokens[0] == "sang":
        return [tokens[1]]
    if n == 3 and tokens[0] == "sa" and tokens[2] == "ko":
        return [tokens[1]]
    if n == 3 and tokens[0] == "ko" and tokens[1] == "sa":
        return [tokens[2]]
    return []


@lru_cache(maxsize=1)
def alias_index() -> dict[str, dict[str, Any]]:
    index: dict[str, dict[str, Any]] = {}

    def register(alias: str, canonical: str, region: str, source: str, confidence: float, override: bool) -> None:
        key = normalize(alias)
        canonical = canonicalize_english(canonical)
        if not key or not canonical:
            return
        if not override and key in index:
            return
        if override and key in index and index[key].get("source") == "body_parts":
            return
        index[key] = {
            "canonical": canonical,
            "region": region or canonical,
            "source": source,
            "confidence": confidence,
        }

    if _BODY_PARTS.is_file():
        with _BODY_PARTS.open(encoding="utf-8", newline="") as f:
            for raw in csv.DictReader(f):
                hil = (raw.get("hiligaynon_term") or "").strip()
                eng = canonicalize_english((raw.get("english_term") or "").strip())
                if not hil or not eng:
                    continue
                region = (raw.get("anatomy_category") or raw.get("body_system") or "general").strip()
                norm_hil = normalize(hil)
                register(norm_hil, eng, region, "body_parts", 0.97, True)
                register(eng, eng, region, "body_parts", 0.95, True)
                for base in _possessive_bases(norm_hil):
                    register(base, eng, region, "body_parts", 0.96, True)

    if _PAIN.is_file():
        with _PAIN.open(encoding="utf-8", newline="") as f:
            for raw in csv.DictReader(f):
                body_part = canonicalize_english((raw.get("body_part") or "").strip())
                if not body_part:
                    continue
                notes = raw.get("notes") or ""
                m = re.search(r"Hiligaynon part alias:\s*([a-z0-9\-]+)", notes, re.I)
                if m:
                    register(m.group(1), body_part, body_part, "body_part_pain_symptoms", 0.9, False)
                alias = normalize(raw.get("english_alias") or "")
                english_first = {
                    "head", "eye", "ear", "chest", "back", "neck", "arm", "hand", "foot", "leg",
                    "knee", "hip", "tooth", "throat", "stomach", "abdominal", "body", "muscle",
                    "joint", "facial", "jaw", "temple", "forehead", "genital", "vaginal", "vulvar",
                    "shoulder", "elbow", "wrist", "ankle", "upper", "lower", "forearm", "biceps",
                }
                if alias:
                    first = alias.split(" ", 1)[0]
                    if first in english_first or alias.endswith("ache"):
                        register(alias, body_part, body_part, "body_part_pain_symptoms", 0.88, False)
                    m2 = re.match(r"^([a-z0-9\-]+)\s+pain$", alias)
                    if m2 and m2.group(1) not in english_first:
                        register(m2.group(1), body_part, body_part, "body_part_pain_symptoms", 0.9, False)
                register(body_part, body_part, body_part, "body_part_pain_symptoms", 0.88, False)

    if _TRAINING.is_file():
        seen: set[str] = set()
        with _TRAINING.open(encoding="utf-8", newline="") as f:
            for raw in csv.DictReader(f):
                hil = normalize(raw.get("body_location_hiligaynon") or "")
                eng = canonicalize_english(raw.get("body_location_en") or "")
                if not hil or not eng or hil in seen:
                    continue
                seen.add(hil)
                register(hil, eng, eng, "hiligaynon_body_location_100000_compact", 0.86, False)

    return index


def extract_detailed(text: str, original_text: str = "") -> list[dict[str, Any]]:
    raw = (text or "").strip()
    if not raw:
        return []
    display = (original_text or raw).strip() or raw
    match_text = normalize(raw)
    # Apply common Hiligaynon spelling folds used by PHP normalizer for ear/head/chest.
    folds = {
        "dulunggan": "dalunggan",
        "olo": "ulo",
        "tyan": "tiyan",
        "tian": "tiyan",
        "duhan": "dughan",
    }
    for a, b in folds.items():
        match_text = re.sub(rf"\b{a}\b", b, match_text)
    if not match_text:
        return []

    index = alias_index()
    aliases = sorted(index.keys(), key=len, reverse=True)
    candidates: list[dict[str, Any]] = []
    for alias in aliases:
        if not re.search(rf"\b{re.escape(alias)}\b", match_text):
            continue
        meta = index[alias]
        canonical = meta["canonical"]
        if not canonical:
            continue
        candidates.append(
            {
                "original_term": display,
                "normalized_term": alias,
                "canonical_body_location": canonical,
                "anatomical_region": meta["region"],
                "confidence": float(meta["confidence"]),
                "source": meta["source"],
            }
        )

    priority = {
        "body_parts": 3,
        "body_part_pain_symptoms": 2,
        "hiligaynon_body_location_100000_compact": 1,
    }
    candidates.sort(
        key=lambda r: (
            -float(r["confidence"]),
            -priority.get(str(r["source"]), 0),
            -len(str(r["normalized_term"])),
        )
    )

    found: list[dict[str, Any]] = []
    seen: set[str] = set()
    for row in candidates:
        canonical = row["canonical_body_location"]
        if canonical in seen:
            continue
        token = str(row["normalized_term"])
        conflict = False
        for kept in found:
            if kept["normalized_term"] == token and kept["canonical_body_location"] != canonical:
                conflict = True
                break
            if (
                kept["source"] == "body_parts"
                and row["source"] != "body_parts"
                and re.search(rf"\b{re.escape(str(kept['normalized_term']))}\b", token)
                and kept["canonical_body_location"] != canonical
            ):
                conflict = True
                break
        if conflict:
            continue
        seen.add(canonical)
        found.append(row)
    return found


def extract_canonical(text: str) -> list[str]:
    return [row["canonical_body_location"] for row in extract_detailed(text)]


def clear_cache() -> None:
    alias_index.cache_clear()
