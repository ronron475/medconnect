"""RapidFuzz matching of translated terms against official condition and allergy datasets."""

from __future__ import annotations

import csv
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

from rapidfuzz import fuzz, process

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"
_ACCEPT_THRESHOLD = 85


def _normalize_match_key(text: str) -> str:
    return re.sub(r"\s+", " ", (text or "").lower().strip())


def _tokenize_name(text: str) -> list[str]:
    return [part for part in re.split(r"[\s\-]+", _normalize_match_key(text)) if part]


def _contains_whole_token(text: str, token: str) -> bool:
    token_key = _normalize_match_key(token)
    if not token_key:
        return False
    return token_key in _tokenize_name(text)


def _is_anatomy_only_term(term: str) -> bool:
    try:
        from body_parts_loader import is_body_part

        return is_body_part(term)
    except ImportError:
        return False


def _reject_spurious_short_match(term: str, matched_name: str | None, score: int) -> bool:
    """Reject fuzzy hits where a short token only appears inside unrelated compound names."""
    if not matched_name:
        return True
    term_key = _normalize_match_key(term)
    name_key = _normalize_match_key(matched_name)
    if not term_key or term_key == name_key:
        return False

    term_tokens = term_key.split()
    name_tokens = _tokenize_name(matched_name)
    if len(term_tokens) != 1:
        return False
    if len(name_tokens) <= 1:
        return False
    if term_key not in name_tokens:
        return False
    if score >= 98:
        return False
    # Single-word query embedded in a longer unrelated label (e.g. head → Small-head Sperm).
    return len(name_tokens) >= 2 and len(term_key) <= 8


@lru_cache(maxsize=1)
def _condition_records() -> list[dict[str, Any]]:
    return _load_records(_DATA_DIR / "medical_conditions.csv", "condition_id", "condition_name", "condition")


@lru_cache(maxsize=1)
def _allergy_records() -> list[dict[str, Any]]:
    return _load_records(_DATA_DIR / "allergies.csv", "allergy_id", "allergy_name", "allergy")


@lru_cache(maxsize=1)
def _symptom_records() -> list[dict[str, Any]]:
    return _load_records(_DATA_DIR / "symptoms.csv", "symptom_id", "symptom_name", "symptom")


def _load_records(path: Path, id_col: str, name_col: str, category: str) -> list[dict[str, Any]]:
    if not path.is_file():
        return []
    records: list[dict[str, Any]] = []
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            name = (row.get(name_col) or "").strip()
            if not name:
                continue
            records.append(
                {
                    "record_id": int(row.get(id_col) or 0),
                    "name": name,
                    "category": category,
                }
            )
    return records


def _confidence_level(score: int) -> str:
    if score >= 95:
        return "high"
    if score >= _ACCEPT_THRESHOLD:
        return "medium"
    if score >= 70:
        return "low"
    return "none"


def _match_term(term: str, records: list[dict[str, Any]]) -> dict[str, Any]:
    try:
        from body_part_pain_symptoms_loader import canonical_english, official_symptom_name

        term = canonical_english(term.strip())
    except ImportError:
        term = term.strip()

    if not term or not records:
        return {
            "input_term": term,
            "matched_term": None,
            "similarity_score": 0,
            "confidence_level": "none",
            "validation_status": "unrecognized",
            "standardized_term": None,
            "record_id": None,
            "scorer": "WRatio",
            "threshold": _ACCEPT_THRESHOLD,
        }

    term_key = _normalize_match_key(term)
    exact = {r["name"].lower(): r for r in records}
    if term_key in exact:
        record = exact[term_key]
        return {
            "input_term": term,
            "matched_term": record["name"],
            "similarity_score": 100,
            "confidence_level": _confidence_level(100),
            "validation_status": "accepted",
            "standardized_term": record["name"],
            "record_id": record["record_id"],
            "scorer": "WRatio",
            "threshold": _ACCEPT_THRESHOLD,
        }

    candidates = [r for r in records if _contains_whole_token(r["name"], term_key)]
    if not candidates:
        candidates = [r for r in records if term_key in r["name"].lower()]
    if not candidates:
        candidates = records

    is_specific_pain_query = " pain" in f" {term_key} " and " " in term_key
    if is_specific_pain_query:
        candidates = [r for r in candidates if r["name"].lower() != "pain"] or candidates

    names = [r["name"] for r in candidates]
    hit = process.extractOne(term, names, scorer=fuzz.WRatio)
    if hit is None:
        score = 0
        best_name = None
        idx = -1
    else:
        best_name, score, idx = hit
        score = int(round(score))

    accepted = score >= _ACCEPT_THRESHOLD
    if accepted and _reject_spurious_short_match(term, best_name, score):
        accepted = False
    record = candidates[idx] if idx >= 0 else None

    return {
        "input_term": term,
        "matched_term": best_name,
        "similarity_score": score,
        "confidence_level": _confidence_level(score),
        "validation_status": "accepted" if accepted else "unrecognized",
        "standardized_term": record["name"] if accepted and record else None,
        "record_id": record["record_id"] if accepted and record else None,
        "scorer": "WRatio",
        "threshold": _ACCEPT_THRESHOLD,
    }


def _match_queue(queue: list[dict[str, Any]], category: str) -> dict[str, Any]:
    if not queue:
        return {
            "status": "empty",
            "status_label": "Nothing to fuzzy match",
            "accepted_count": 0,
            "rejected_count": 0,
            "total_count": 0,
            "threshold": _ACCEPT_THRESHOLD,
            "results": [],
        }

    if category == "allergy":
        records = _allergy_records()
    elif category == "symptom":
        records = _symptom_records()
    else:
        records = _condition_records()
    results: list[dict[str, Any]] = []
    accepted = 0
    rejected = 0

    for item in queue:
        english = (item.get("match_term") or item.get("english_term") or "").strip()
        if not english:
            continue
        match = _match_term(english, records)
        row = {
            "local_term": item.get("local_term") or "",
            "english_term": item.get("english_term") or english,
            "match_term": english,
            "category": category,
            "matched_language": "english",
            "input_language": item.get("input_language") or "unknown",
            **match,
        }
        results.append(row)
        if row["validation_status"] == "accepted":
            accepted += 1
        else:
            rejected += 1

    total = len(results)
    if total == 0:
        status = "empty"
        label = "Nothing to fuzzy match"
    elif accepted == total:
        status = "complete"
        label = f"All terms accepted (≥{_ACCEPT_THRESHOLD}% RapidFuzz) ({accepted}/{total})"
    elif accepted > 0:
        status = "partial"
        label = f"Partial acceptance ({accepted}/{total} at ≥{_ACCEPT_THRESHOLD}%)"
    else:
        status = "none"
        label = f"No terms met the {_ACCEPT_THRESHOLD}% similarity threshold"

    return {
        "status": status,
        "status_label": label,
        "accepted_count": accepted,
        "rejected_count": rejected,
        "total_count": total,
        "threshold": _ACCEPT_THRESHOLD,
        "results": results,
    }


def _normalize_hint(category: str | None) -> str | None:
    if not category:
        return None
    key = category.strip().lower()
    if key in ("allergy", "allergies"):
        return "allergy"
    if key in ("condition", "conditions"):
        return "condition"
    if key in ("symptom", "symptoms"):
        return "symptom"
    return None


_SYMPTOM_HINT_TERMS = frozenset({
    "headache", "fever", "cough", "pain", "nausea", "vomiting", "diarrhea",
    "dizziness", "fatigue", "rash", "itching", "itchiness", "shortness of breath", "dyspnea",
    "chest pain", "abdominal pain", "back pain", "sore throat", "chills",
    "hair loss", "body weakness", "painful urination", "weakness", "alopecia",
})


def _category_order(hint: str | None, english: str = "") -> list[str]:
    if hint == "allergy":
        return ["allergy"]
    if hint == "condition":
        return ["symptom", "condition"]
    if hint == "symptom":
        return ["symptom", "condition"]
    if english.strip().lower() in _SYMPTOM_HINT_TERMS:
        return ["symptom", "condition", "allergy"]
    return ["symptom", "condition", "allergy"]


def _records_for_category(category: str) -> list[dict[str, Any]]:
    if category == "allergy":
        return _allergy_records()
    if category == "symptom":
        return _symptom_records()
    return _condition_records()


def match_term_best(term: str, hint_category: str | None = None) -> dict[str, Any]:
    try:
        from body_part_pain_symptoms_loader import canonical_english, official_symptom_name

        term = canonical_english(term.strip())
        official = official_symptom_name(term)
        if official:
            exact = {r["name"].lower(): r for r in _symptom_records()}
            record = exact.get(official.lower())
            if record:
                return {
                    "input_term": term,
                    "matched_term": record["name"],
                    "similarity_score": 100,
                    "confidence_level": _confidence_level(100),
                    "validation_status": "accepted",
                    "standardized_term": record["name"],
                    "record_id": record["record_id"],
                    "scorer": "body_part_pain_symptoms",
                    "threshold": _ACCEPT_THRESHOLD,
                    "dataset_category": "symptom",
                }
    except ImportError:
        term = term.strip()

    if _is_anatomy_only_term(term):
        return {
            **_match_term(term, []),
            "validation_status": "anatomy_only",
            "validation_message": "Anatomy-only body part — not validated as a symptom or condition.",
            "dataset_category": "body_part",
        }

    hint = _normalize_hint(hint_category)
    best_match: dict[str, Any] | None = None
    best_score = 0
    best_category: str | None = None

    for category in _category_order(hint, term):
        records = _records_for_category(category)
        match = _match_term(term, records)
        score = int(match.get("similarity_score") or 0)
        if match.get("validation_status") == "accepted" and score > best_score:
            best_match = match
            best_score = score
            best_category = category
            if score == 100:
                break

    if best_match is None:
        return {**_match_term(term, []), "dataset_category": hint or "unknown"}

    return {**best_match, "dataset_category": best_category}


def match_queue(queue: list[dict[str, Any]], category: str) -> dict[str, Any]:
    return _match_queue(queue, category)


def match_text_queue(queue: list[dict[str, Any]]) -> dict[str, Any]:
    if not queue:
        return {
            "status": "empty",
            "status_label": "Nothing to fuzzy match",
            "accepted_count": 0,
            "rejected_count": 0,
            "total_count": 0,
            "threshold": _ACCEPT_THRESHOLD,
            "results": [],
            "engine": "rapidfuzz",
        }

    results: list[dict[str, Any]] = []
    accepted = 0
    rejected = 0

    for item in queue:
        english = (item.get("match_term") or item.get("english_term") or "").strip()
        if not english:
            continue
        hint = _normalize_hint(item.get("category"))
        match = match_term_best(english, hint)
        row = {
            "local_term": item.get("local_term") or "",
            "english_term": item.get("english_term") or english,
            "match_term": english,
            "category": match.get("dataset_category") or hint or "unknown",
            "matched_language": "english",
            "input_language": item.get("input_language") or "unknown",
            "was_translated": bool(item.get("was_translated")),
            **match,
        }
        results.append(row)
        if row.get("validation_status") == "accepted":
            accepted += 1
        else:
            rejected += 1

    total = len(results)
    if total == 0:
        status = "empty"
        label = "Nothing to fuzzy match"
    elif accepted == total:
        status = "complete"
        label = f"All terms accepted (≥{_ACCEPT_THRESHOLD}% RapidFuzz) ({accepted}/{total})"
    elif accepted > 0:
        status = "partial"
        label = f"Partial acceptance ({accepted}/{total} at ≥{_ACCEPT_THRESHOLD}%)"
    else:
        status = "none"
        label = f"No terms met the {_ACCEPT_THRESHOLD}% similarity threshold"

    return {
        "status": status,
        "status_label": label,
        "accepted_count": accepted,
        "rejected_count": rejected,
        "total_count": total,
        "threshold": _ACCEPT_THRESHOLD,
        "results": results,
        "engine": "rapidfuzz",
    }


def match_profile(translation: dict[str, Any]) -> dict[str, Any]:
    allergies = _match_queue(
        (translation.get("allergies") or {}).get("validation_queue") or [],
        "allergy",
    )
    conditions = _match_queue(
        (translation.get("conditions") or {}).get("validation_queue") or [],
        "condition",
    )

    accepted = allergies["accepted_count"] + conditions["accepted_count"]
    rejected = allergies["rejected_count"] + conditions["rejected_count"]
    total = allergies["total_count"] + conditions["total_count"]

    if total == 0:
        overall = "empty"
        overall_label = "No terms to fuzzy match"
    elif accepted == total:
        overall = "complete"
        overall_label = f"RapidFuzz: {accepted}/{total} terms accepted (≥{_ACCEPT_THRESHOLD}%)"
    elif accepted > 0:
        overall = "partial"
        overall_label = f"RapidFuzz: {accepted}/{total} accepted, {rejected} unrecognized"
    else:
        overall = "none"
        overall_label = f"RapidFuzz: 0/{total} terms met {_ACCEPT_THRESHOLD}% threshold"

    return {
        "allergies": allergies,
        "conditions": conditions,
        "overall_status": overall,
        "overall_status_label": overall_label,
        "threshold": _ACCEPT_THRESHOLD,
        "engine": "rapidfuzz",
    }
