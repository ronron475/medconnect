"""Phrase-level medical entity extraction for Hiligaynon telemedicine text."""

from __future__ import annotations

import re
from typing import Any

from medical_misspellings_loader import apply_misspelling_corrections


def normalize_text(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def extract_entities(text: str) -> list[dict[str, Any]]:
    """
    Phrase-first entity extraction.
    Example: 'may nana sa bilat ko' -> vaginal infection (not tokenized may/nana/bilat).
    """
    if not text or not text.strip():
        return []

    corrected = apply_misspelling_corrections(text)
    normalized = normalize_text(corrected)

    entities: list[dict[str, Any]] = []
    seen: set[str] = set()

    try:
        from symptom_phrases_loader import scan_phrases

        for match in scan_phrases(normalized):
            key = (match.get("matched_phrase") or "").lower()
            if not key or key in seen:
                continue
            seen.add(key)
            entities.append(_phrase_to_entity(match, text))
    except ImportError:
        pass

    if not entities:
        try:
            from hiligaynon_nlp_dataset_loader import lookup, terms_by_length, term_index

            working = normalized
            occupied = [False] * max(len(working), 1)
            for term in terms_by_length():
                pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)")
                for m in pattern.finditer(working):
                    start, end = m.start(), m.end()
                    if any(occupied[start:end]):
                        continue
                    entry = term_index().get(term)
                    if not entry:
                        continue
                    for i in range(start, end):
                        occupied[i] = True
                    key = term
                    if key in seen:
                        continue
                    seen.add(key)
                    entities.append(
                        {
                            "hiligaynon_term": entry.get("canonical_variant") or term,
                            "english_term": entry.get("english") or "",
                            "symptom": _symptom_from_english(entry.get("english") or ""),
                            "condition": entry.get("english") or "",
                            "body_part": _body_part_from_text(term, entry.get("english") or ""),
                            "severity": _normalize_severity(entry.get("severity") or ""),
                            "duration": _extract_duration(text),
                            "type": "condition" if "infection" in (entry.get("english") or "").lower() else "symptom",
                            "category": entry.get("category") or "symptom",
                            "confidence": 92,
                            "source": "hiligaynon_nlp_dataset",
                        }
                    )
        except ImportError:
            pass

    return entities


def extract_primary_entity(text: str) -> dict[str, Any] | None:
    entities = extract_entities(text)
    return entities[0] if entities else None


def entities_to_concepts(entities: list[dict[str, Any]]) -> list[dict[str, Any]]:
    concepts: list[dict[str, Any]] = []
    for ent in entities:
        english = ent.get("english_term") or ent.get("condition") or ""
        if not english:
            continue
        concepts.append(
            {
                "english": english,
                "medical_keyword": ent.get("condition") or ent.get("symptom") or english,
                "category": ent.get("type") or "symptom",
                "body_part": ent.get("body_part") or "",
                "severity": ent.get("severity") or "",
                "duration": ent.get("duration") or "",
                "classification": ent.get("type") or "symptom",
                "symptom": ent.get("symptom") or "",
            }
        )
    return concepts


def _phrase_to_entity(match: dict[str, Any], original: str) -> dict[str, Any]:
    english = match.get("english_term") or ""
    cat = (match.get("medical_category") or "").lower()
    is_infection = "infection" in english.lower() or cat == "infection"
    return {
        "hiligaynon_term": match.get("hiligaynon_term") or match.get("matched_phrase") or "",
        "english_term": english,
        "symptom": match.get("symptom") or _symptom_from_english(english),
        "condition": english if is_infection or cat in {"injury", "trauma", "gynecologic_symptom"} else "",
        "body_part": match.get("body_part") or _body_part_from_text(match.get("hiligaynon_term") or "", english),
        "severity": _normalize_severity(match.get("severity") or ""),
        "duration": _extract_duration(original),
        "type": "condition" if is_infection else "symptom",
        "category": match.get("medical_category") or "symptom",
        "triage_level": match.get("triage_level") or "",
        "confidence": 95,
        "source": match.get("source") or "symptom_phrases",
    }


def _symptom_from_english(english: str) -> str:
    low = english.lower()
    for token in ("infection", "bleeding", "swelling", "pain", "itching", "lump", "wound", "redness", "retention"):
        if token in low:
            return token
    return "symptom"


def _body_part_from_text(hil: str, english: str) -> str:
    mapping = {
        "itlog": "testicle", "itlug": "testicle", "bilat": "vagina", "bilad": "vagina",
        "ari": "penis", "bayag": "scrotum", "kipay": "vulva", "singit": "groin",
    }
    low = hil.lower()
    for k, v in mapping.items():
        if re.search(rf"\b{k}\b", low):
            return v
    eng = english.lower()
    for part in ("vagina", "penis", "testicle", "scrotum", "vulva", "groin"):
        if part in eng:
            return part
    return ""


def _normalize_severity(sev: str) -> str:
    s = (sev or "").lower()
    if s in {"critical", "high", "severe"}:
        return "severe"
    if s in {"medium", "moderate"}:
        return "moderate"
    if s in {"low", "mild"}:
        return "mild"
    return s or "moderate"


def _extract_duration(text: str) -> str:
    low = (text or "").lower()
    patterns = [
        r"\d+\s*ka\s*adlaw",
        r"dugay\s+na",
        r"bag-o\s+lang",
        r"gahapon",
        r"kagapon",
        r"semana\s+na",
    ]
    for pat in patterns:
        m = re.search(pat, low)
        if m:
            return m.group(0)
    return ""
