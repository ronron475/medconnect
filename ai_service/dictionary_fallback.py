"""Dictionary-first fallback when Groq AI is unavailable."""

from __future__ import annotations

from typing import Any


def _classify_term(english: str, symptoms: list[str], body_parts: list[str]) -> None:
    low = english.lower()
    if low in {"eye", "ear", "nose", "throat", "chest", "head", "stomach", "skin", "foot", "hand"}:
        if english not in body_parts:
            body_parts.append(english)
        return
    if "eye" in low and "eye" not in body_parts:
        body_parts.append("eye")
    if english not in symptoms:
        symptoms.append(english)


def build_dictionary_fallback_interpretation(
    original_text: str,
    dict_matches: list[dict[str, Any]],
    dataset_matches: list[dict[str, Any]],
    keywords: list[str],
    groq_error: str | None = None,
) -> dict[str, Any]:
    english_terms: list[str] = []
    symptoms: list[str] = []
    body_parts: list[str] = []

    for row in dict_matches:
        en = str(row.get("english_term") or "").strip()
        local = str(row.get("local_term") or "").strip()
        if not en:
            continue
        if len(local) <= 12:
            english_terms.append(en)
        _classify_term(en, symptoms, body_parts)

    for row in dataset_matches:
        en = str(row.get("english_term") or "").strip()
        if en and en not in english_terms:
            english_terms.append(en)
            _classify_term(en, symptoms, body_parts)

    for kw in keywords:
        try:
            from dictionary_loader import lookup as dict_lookup
            entry = dict_lookup(kw)
            if entry:
                en = str(entry.get("english_term") or "")
                if en and en not in english_terms:
                    english_terms.append(en)
                    _classify_term(en, symptoms, body_parts)
        except ImportError:
            pass

    english_terms = list(dict.fromkeys(english_terms))
    has_pus = any("pus" in t.lower() or "discharge" in t.lower() for t in english_terms)
    has_eye = any(t.lower() == "eye" or "eye" in t.lower() for t in english_terms + body_parts)

    if has_pus and has_eye:
        interpretation = "There is pus in my eye."
        concepts = [
            {"term": "pus", "type": "symptom", "body_part": "eye", "severity": None, "duration": None, "confidence": 95},
            {"term": "eye discharge", "type": "symptom", "body_part": "eye", "severity": None, "duration": None, "confidence": 95},
            {"term": "purulent eye discharge", "type": "symptom", "body_part": "eye", "severity": None, "duration": None, "confidence": 95},
            {"term": "eye", "type": "body_part", "body_part": "eye", "severity": None, "duration": None, "confidence": 95},
        ]
    elif english_terms:
        interpretation = "Patient reports: " + ", ".join(english_terms) + "."
        concepts = []
        for term in english_terms:
            concepts.append(
                {"term": term, "type": "symptom", "body_part": body_parts[0] if body_parts else None, "severity": None, "duration": None, "confidence": 90}
            )
        for part in body_parts:
            concepts.append(
                {"term": part, "type": "body_part", "body_part": part, "severity": None, "duration": None, "confidence": 90}
            )
    else:
        interpretation = original_text.strip()
        concepts = []

    if groq_error:
        notes = f"Groq failed: {groq_error}. Using dictionary and Hiligaynon dataset matches."
    else:
        notes = "Groq unavailable — built from medical dictionary and Hiligaynon dataset matches."

    return {
        "status": "fallback",
        "provider": "dictionary_fallback",
        "model": None,
        "english_interpretation": interpretation,
        "confidence_score": 92 if english_terms else 0,
        "concepts": concepts,
        "notes": notes,
        "groq_error": groq_error,
        "groq_attempted": bool(groq_error),
        "detected_symptoms": symptoms,
        "detected_body_parts": body_parts,
    }
