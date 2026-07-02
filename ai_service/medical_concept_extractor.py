"""Extract medical concepts from English translations for dataset lookup."""

from __future__ import annotations

import re
from typing import Any


def normalize_category(category: str) -> str:
    key = (category or "").strip().lower()
    mapping = {
        "pain": "symptom",
        "ocular pain": "symptom",
        "neurological": "symptom",
        "cardiovascular": "symptom",
        "respiratory": "symptom",
        "gastrointestinal": "symptom",
        "dermatological": "symptom",
        "general": "symptom",
        "skin": "symptom",
    }
    return mapping.get(key, key or "symptom")


def split_phrases(english: str) -> list[str]:
    parts = re.split(r"\s*(?:,|;| and | kag | og | ug )\s*", english, flags=re.I)
    return [p.strip() for p in parts if p.strip()]


def extract_from_translation(translation: dict[str, Any]) -> list[dict[str, str]]:
    english = (translation.get("english") or "").strip()
    if not english:
        return []

    keyword = (translation.get("medical_keyword") or english).strip()
    category = normalize_category(str(translation.get("category") or "symptom"))
    body_part = str(translation.get("body_part") or "")

    concepts: list[dict[str, str]] = [{
        "english": english,
        "medical_keyword": keyword or english,
        "category": category,
        "body_part": body_part,
    }]

    for phrase in split_phrases(english):
        if phrase.lower() == english.lower():
            continue
        try:
            from body_part_pain_symptoms_loader import canonical_english
            phrase = canonical_english(phrase)
        except ImportError:
            pass
        concepts.append({
            "english": phrase,
            "medical_keyword": phrase,
            "category": "symptom",
            "body_part": "",
        })

    seen: set[str] = set()
    unique: list[dict[str, str]] = []
    for concept in concepts:
        key = concept["english"].lower()
        if not key or key in seen:
            continue
        seen.add(key)
        unique.append(concept)
    return unique


from medical_entity_extractor import entities_to_concepts, extract_entities


def enrich_from_translation(translation: dict[str, Any]) -> list[dict[str, str]]:
    concepts = [
        {
            **c,
            "symptom": c.get("medical_keyword") or c["english"],
            "classification": "symptom",
        }
        for c in extract_from_translation(translation)
    ]
    original = str(translation.get("original_text") or translation.get("source_text") or "")
    if original:
        for ent in entities_to_concepts(extract_entities(original)):
            key = ent["english"].lower()
            if not any(c["english"].lower() == key for c in concepts):
                concepts.append({**ent, "symptom": ent.get("symptom") or ent["english"], "classification": ent.get("category") or "symptom"})
    return concepts


def classify(concepts: list[dict[str, str]], phrase_translation: dict[str, Any]) -> dict[str, Any]:
    classifications = list({c.get("classification", "symptom") for c in concepts})
    category = classifications[0] if classifications else "symptom"
    if "emergency" in classifications:
        category = "emergency"
    return {"category": category, "classifications": classifications}
