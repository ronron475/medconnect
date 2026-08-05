"""Data-driven symptom knowledge base for clinical triage CDS."""

from __future__ import annotations

import json
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_KB_PATH = Path(__file__).resolve().parent.parent / "data" / "nlp" / "symptom_knowledge_base.json"
_RF_PATH = Path(__file__).resolve().parent.parent / "data" / "nlp" / "red_flags_library.json"


@lru_cache(maxsize=1)
def load_knowledge_base() -> dict[str, Any]:
    if not _KB_PATH.is_file():
        return {"symptoms": [], "scoring": {}}
    with _KB_PATH.open(encoding="utf-8") as handle:
        data = json.load(handle)
    return data if isinstance(data, dict) else {"symptoms": [], "scoring": {}}


@lru_cache(maxsize=1)
def load_red_flags_library() -> dict[str, Any]:
    if not _RF_PATH.is_file():
        return {"red_flags": [], "policy": {}}
    with _RF_PATH.open(encoding="utf-8") as handle:
        data = json.load(handle)
    return data if isinstance(data, dict) else {"red_flags": [], "policy": {}}


def _term_list(symptom: dict[str, Any]) -> list[str]:
    terms: list[str] = []
    for key in ("keywords", "synonyms", "hiligaynon_terms", "filipino_terms"):
        values = symptom.get(key) or []
        if isinstance(values, list):
            terms.extend(str(v).strip().lower() for v in values if str(v).strip())
    name = str(symptom.get("symptom_name") or "").strip().lower()
    if name:
        terms.append(name)
    # Longest first for phrase preference
    return sorted(set(terms), key=len, reverse=True)


@lru_cache(maxsize=1)
def _symptom_index() -> tuple[dict[str, Any], ...]:
    kb = load_knowledge_base()
    indexed: list[dict[str, Any]] = []
    for raw in kb.get("symptoms") or []:
        if not isinstance(raw, dict):
            continue
        terms = _term_list(raw)
        if not terms:
            continue
        indexed.append({**raw, "_match_terms": terms})
    return tuple(indexed)


def _flexible_phrase_hit(hay: str, term: str) -> bool:
    """Match multi-word phrases allowing short function words between tokens."""
    if not term:
        return False
    if term in hay:
        return True
    parts = [p for p in re.split(r"\s+", term) if p]
    if len(parts) < 2:
        return bool(re.search(rf"(?<!\w){re.escape(term)}(?!\w)", hay))
    # Allow up to 2 intervening tokens (e.g. "masakit akon dughan")
    pattern = r"(?<!\w)" + r"(?:\W+\w+){0,2}\W+".join(re.escape(p) for p in parts) + r"(?!\w)"
    return bool(re.search(pattern, hay))


def _term_matches_with_context(hay: str, term: str, symptom: dict[str, Any]) -> bool:
    if " " in term or "-" in term:
        hit = _flexible_phrase_hit(hay, term)
    else:
        hit = bool(re.search(rf"(?<!\w){re.escape(term)}(?!\w)", hay))
    if not hit:
        return False

    name = str(symptom.get("symptom_name") or "").strip().lower()
    if name and name in hay:
        return True

    if name == "acute abdomen":
        return bool(re.search(r"\b(acute|rigid|peritonitis|sudden severe)\b", hay))

    explicit: list[str] = []
    for key in ("keywords", "synonyms", "hiligaynon_terms", "filipino_terms"):
        for t in symptom.get(key) or []:
            t = str(t).strip().lower()
            if t:
                explicit.append(t)
    explicit = list(dict.fromkeys(explicit))

    generic = {"fever", "pain", "cough", "ache", "bleeding", "weakness", "fatigue", "rash", "swelling"}
    if term in explicit and term not in generic and len(term) >= 6:
        return True
    if " " in term and term in explicit:
        return True

    high_acuity = {"appendicitis pain", "pancreatitis pain", "testicular torsion", "sepsis symptoms", "meningitis symptoms"}
    if name in high_acuity and not re.search(r"\b(severe|acute|sudden|worst|rigid|unbearable|grabe)\b", hay):
        return False

    if re.match(r"^(child|infant|pediatric)\s+", name):
        specific = name.split(" ", 1)[1] if " " in name else ""
        if specific and specific not in hay:
            return False

    if name.endswith(" pain"):
        location = name.replace(" pain", "")
        body_map = {
            "abdominal": r"\b(abdomen|abdominal|stomach|belly|tiyan)\b",
            "back": r"\b(back|likod)\b",
            "chest": r"\b(chest|dughan|dibdib)\b",
            "head": r"\b(head|ulo)\b",
            "neck": r"\b(neck|liog|leeg)\b",
        }
        if location in body_map:
            return bool(re.search(body_map[location], hay))
        if location != "chronic" and location not in hay:
            return False

    name_words = name.split()
    term_words = term.split()
    qual_generic = {"fever", "pain", "cough", "bleeding", "ache", "symptoms", "symptom", "severe", "acute", "chronic", "mild", "high", "low", "with"}

    if len(name_words) <= len(term_words):
        return True

    qualifiers = [w for w in set(name_words) - set(term_words) if len(w) >= 4 and w not in qual_generic]
    if not qualifiers:
        return True

    for q in qualifiers:
        if q in hay:
            return True

    if set(qualifiers) & {"infant", "neonatal", "newborn"}:
        if re.search(r"\b(infant|baby|newborn|sanggol)\b", hay):
            return True
    if set(qualifiers) & {"child", "pediatric"}:
        if re.search(r"\b(child|anak|bata)\b", hay):
            return True

    return False


def match_symptoms(text: str, english_text: str = "", extra_terms: list[str] | None = None) -> list[dict[str, Any]]:
    """Match standardized symptoms from free text using KB synonyms/local terms."""
    haystacks = [
        (text or "").lower(),
        (english_text or "").lower(),
        " ".join(extra_terms or []).lower(),
    ]
    hay = " | ".join(h for h in haystacks if h)
    if not hay.strip("| ").strip():
        return []

    matched: list[dict[str, Any]] = []
    seen: set[str] = set()
    for symptom in _symptom_index():
        sid = str(symptom.get("id") or symptom.get("symptom_name") or "")
        if sid in seen:
            continue
        for term in symptom.get("_match_terms") or []:
            if not term or (len(term) < 5 and " " not in term):
                allow_short = {"ubo", "sipon", "lagnat", "hilo", "tae", "dugo", "hapdi", "kapoy", "luya", "ulon", "mata", "dughan"}
                if term not in allow_short:
                    continue
            if not _term_matches_with_context(hay, term, symptom):
                continue
            matched.append(
                    {
                        "id": symptom.get("id"),
                        "symptom_name": symptom.get("symptom_name"),
                        "medical_category": symptom.get("medical_category"),
                        "severity_weight": int(symptom.get("severity_weight") or 0),
                        "emergency_weight": int(symptom.get("emergency_weight") or 0),
                        "urgent_weight": int(symptom.get("urgent_weight") or 0),
                        "danger_sign": bool(symptom.get("danger_sign")),
                        "recommended_action": symptom.get("recommended_action") or "",
                        "matched_term": term,
                        "common_causes": symptom.get("common_causes") or [],
                        "danger_signs": symptom.get("danger_signs") or [],
                    }
                )
                seen.add(sid)
                break
    # Highest severity first
    matched.sort(key=lambda s: int(s.get("severity_weight") or 0), reverse=True)
    return matched[:8]


def scan_red_flags_library(original: str, english: str = "") -> list[dict[str, Any]]:
    """Scan JSON red-flag library with optional mild-exclusion override."""
    lib = load_red_flags_library()
    policy = lib.get("policy") or {}
    allow_mild = bool(policy.get("allow_mild_override", True))
    hay = f"{(original or '').lower()} {(english or '').lower()}".strip()
    if not hay:
        return []

    matched: list[dict[str, Any]] = []
    seen: set[str] = set()
    for flag in lib.get("red_flags") or []:
        fid = str(flag.get("id") or flag.get("name") or "")
        if not fid or fid in seen:
            continue
        patterns = flag.get("patterns") or {}
        all_patterns: list[tuple[str, str]] = []
        for lang in ("english", "hiligaynon", "filipino"):
            for pat in patterns.get(lang) or []:
                p = str(pat).strip().lower()
                if p:
                    all_patterns.append((lang, p))
        all_patterns.sort(key=lambda x: len(x[1]), reverse=True)

        hit_lang = ""
        hit_pat = ""
        for lang, pat in all_patterns:
            if pat in hay:
                hit_lang, hit_pat = lang, pat
                break
        if not hit_pat:
            continue

        if allow_mild:
            mild_hit = False
            for excl in flag.get("mild_exclusions") or []:
                if str(excl).strip().lower() in hay:
                    mild_hit = True
                    break
            # Explicit mild qualifiers near non-critical wording
            if mild_hit or re.search(r"\bmild\b.{0,40}\b(only|muscle|sore)\b", hay):
                # Still keep truly dangerous phrases
                if not any(x in hay for x in ("cannot breathe", "indi makaginhawa", "unconscious", "vomiting blood", "suicidal")):
                    continue

        matched.append(
            {
                "flag_id": fid,
                "flag_name": flag.get("name") or fid,
                "category": flag.get("category") or "",
                "auto_triage": (flag.get("auto_triage") or "EMERGENCY").upper(),
                "severity_points": int(flag.get("severity_points") or 12),
                "clinical_rationale": flag.get("rationale") or "",
                "matched_on": hit_lang,
                "matched_pattern": hit_pat,
                "english_pattern": hit_pat,
                "source": "red_flags_library.json",
            }
        )
        seen.add(fid)
    return matched


def scoring_config() -> dict[str, Any]:
    return dict(load_knowledge_base().get("scoring") or {})


def clear_cache() -> None:
    load_knowledge_base.cache_clear()
    load_red_flags_library.cache_clear()
    _symptom_index.cache_clear()
