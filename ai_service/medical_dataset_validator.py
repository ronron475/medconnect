"""Dataset validation after fuzzy matching — official CSV record verification."""

from __future__ import annotations

import csv
from functools import lru_cache
from pathlib import Path
from typing import Any

_DATA_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp"

DATASET_ALLERGIES = "data/nlp/allergies.csv"
DATASET_CONDITIONS = "data/nlp/medical_conditions.csv"
DATASET_SYMPTOMS = "data/nlp/symptoms.csv"
TABLE_ALLERGIES = "allergies"
TABLE_CONDITIONS = "medical_conditions"
TABLE_SYMPTOMS = "symptoms"


def _dataset_meta(category: str) -> dict[str, str]:
    if category == "allergy":
        return {
            "dataset_source": DATASET_ALLERGIES,
            "dataset_table": TABLE_ALLERGIES,
            "dataset_path": str(_DATA_DIR / "allergies.csv"),
        }
    if category == "symptom":
        return {
            "dataset_source": DATASET_SYMPTOMS,
            "dataset_table": TABLE_SYMPTOMS,
            "dataset_path": str(_DATA_DIR / "symptoms.csv"),
        }
    return {
        "dataset_source": DATASET_CONDITIONS,
        "dataset_table": TABLE_CONDITIONS,
        "dataset_path": str(_DATA_DIR / "medical_conditions.csv"),
    }


@lru_cache(maxsize=1)
def _allergy_indexes() -> tuple[dict[str, dict[str, Any]], dict[int, dict[str, Any]]]:
    return _build_indexes(_DATA_DIR / "allergies.csv", "allergy_id", "allergy_name", "category", "allergy", False)


@lru_cache(maxsize=1)
def _condition_indexes() -> tuple[dict[str, dict[str, Any]], dict[int, dict[str, Any]]]:
    return _build_indexes(
        _DATA_DIR / "medical_conditions.csv",
        "condition_id",
        "condition_name",
        "category",
        "condition",
        True,
    )


@lru_cache(maxsize=1)
def _symptom_indexes() -> tuple[dict[str, dict[str, Any]], dict[int, dict[str, Any]]]:
    return _build_indexes(
        _DATA_DIR / "symptoms.csv",
        "symptom_id",
        "symptom_name",
        "category",
        "symptom",
        True,
        body_col="related_body_system",
    )


def _build_indexes(
    path: Path,
    id_col: str,
    name_col: str,
    cat_col: str,
    record_category: str,
    extended: bool,
    body_col: str | None = None,
) -> tuple[dict[str, dict[str, Any]], dict[int, dict[str, Any]]]:
    by_name: dict[str, dict[str, Any]] = {}
    by_id: dict[int, dict[str, Any]] = {}
    if not path.is_file():
        return by_name, by_id

    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            name = (row.get(name_col) or "").strip()
            if not name:
                continue
            rid = int(row.get(id_col) or 0)
            icd10 = (row.get("icd10_code") or "").strip() if extended else ""
            body = (row.get(body_col) or "").strip() if body_col else ""
            entry: dict[str, Any] = {
                "record_id": rid,
                "name": name,
                "dataset_category": (row.get(cat_col) or "").strip() or None,
                "record_category": record_category,
                "description": (row.get("description") or "").strip() or None if extended else None,
                "source": (row.get("source") or "").strip() or None if extended else None,
                "icd10_code": icd10 or None,
                "related_body_system": body or None,
            }
            key = name.lower()
            if key not in by_name:
                by_name[key] = entry
            if rid > 0:
                by_id[rid] = entry
    return by_name, by_id


def _public_record(record: dict[str, Any]) -> dict[str, Any]:
    return {
        "record_id": record["record_id"],
        "name": record["name"],
        "dataset_category": record.get("dataset_category"),
        "record_category": record.get("record_category", ""),
        "description": record.get("description"),
        "source": record.get("source"),
        "icd10_code": record.get("icd10_code"),
        "related_body_system": record.get("related_body_system"),
    }


def _resolve_record(standard: str, category: str, expected_id: int = 0) -> dict[str, Any] | None:
    if category == "allergy":
        by_name, by_id = _allergy_indexes()
    elif category == "symptom":
        by_name, by_id = _symptom_indexes()
    else:
        by_name, by_id = _condition_indexes()
    key = standard.lower()
    record = by_name.get(key)
    if record is None and expected_id > 0:
        record = by_id.get(expected_id)
        if record and record["name"].lower() != key:
            return None
    return record


def _validate_single(row: dict[str, Any], category: str, meta: dict[str, str]) -> dict[str, Any]:
    local = row.get("local_term") or ""
    english = (row.get("english_term") or row.get("input_term") or "").strip()
    standard = (row.get("standardized_term") or "").strip()
    fuzzy_status = row.get("validation_status") or ""
    fuzzy_score = int(row.get("similarity_score") or 0)
    record_id = int(row.get("record_id") or 0)

    base: dict[str, Any] = {
        "local_term": local,
        "english_term": english,
        "standardized_term": standard,
        "category": category,
        "fuzzy_score": fuzzy_score,
        "fuzzy_status": fuzzy_status,
        "dataset_source": meta["dataset_source"],
        "dataset_table": meta["dataset_table"],
    }

    if fuzzy_status != "accepted":
        return {
            **base,
            "validation_result": "rejected_at_fuzzy",
            "validation_message": "Term did not meet the 85% similarity threshold — not sent to registration.",
            "matched_record": None,
            "record": None,
            "final_status": "invalid",
            "blocked": True,
            "registration_ready": False,
        }

    if not standard:
        return {
            **base,
            "validation_result": "missing_standard_term",
            "validation_message": "No standardized term to validate against the dataset.",
            "matched_record": None,
            "record": None,
            "final_status": "invalid",
            "blocked": True,
            "registration_ready": False,
        }

    record = _resolve_record(standard, category, record_id)
    if record is None:
        return {
            **base,
            "validation_result": "not_in_dataset",
            "validation_message": f"No matching record in {meta['dataset_table']}.",
            "matched_record": None,
            "record": None,
            "final_status": "invalid",
            "blocked": True,
            "registration_ready": False,
        }

    if record_id > 0 and record["record_id"] != record_id:
        return {
            **base,
            "validation_result": "id_mismatch",
            "validation_message": "Fuzzy record ID does not match the official dataset entry.",
            "matched_record": _public_record(record),
            "record": None,
            "final_status": "invalid",
            "blocked": True,
            "registration_ready": False,
        }

    pub = _public_record(record)
    return {
        **base,
        "validation_result": "found",
        "validation_message": "Official dataset record verified.",
        "matched_record": pub,
        "record": pub,
        "final_status": "valid",
        "blocked": False,
        "registration_ready": True,
    }


def _field_name(category: str) -> str:
    if category == "allergy":
        return "allergies"
    if category == "symptom":
        return "symptoms"
    return "conditions"


def validate_field_results(fuzzy_results: list[dict[str, Any]], category: str) -> dict[str, Any]:
    meta = _dataset_meta(category)
    field = _field_name(category)
    if not fuzzy_results:
        return {
            "field": field,
            "category": category,
            "dataset_source": meta["dataset_source"],
            "dataset_table": meta["dataset_table"],
            "status": "empty",
            "status_label": "No terms reached dataset validation",
            "valid_count": 0,
            "invalid_count": 0,
            "total_count": 0,
            "results": [],
        }

    results = [_validate_single(row, category, meta) for row in fuzzy_results]
    valid = sum(1 for r in results if r["final_status"] == "valid")
    invalid = len(results) - valid
    total = len(results)

    if invalid == 0:
        status = "complete"
        label = f"All {total} term(s) verified in dataset"
    elif valid > 0:
        status = "partial"
        label = f"{valid} valid, {invalid} invalid (blocked)"
    else:
        status = "failed"
        label = f"All {total} term(s) invalid — not in official dataset"

    field = "allergies" if category == "allergy" else "symptoms" if category == "symptom" else "conditions"
    return {
        "field": field,
        "category": category,
        "dataset_source": meta["dataset_source"],
        "dataset_table": meta["dataset_table"],
        "status": status,
        "status_label": label,
        "valid_count": valid,
        "invalid_count": invalid,
        "total_count": total,
        "results": results,
    }


def _registration_items(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for row in results:
        if row.get("final_status") != "valid" or not row.get("record"):
            continue
        rec = row["record"]
        items.append(
            {
                "local_term": row.get("local_term") or "",
                "standardized_term": rec["name"],
                "record_id": rec["record_id"],
                "category": rec.get("record_category") or row.get("category"),
                "dataset_category": rec.get("dataset_category"),
                "dataset_source": row.get("dataset_source"),
                "dataset_table": row.get("dataset_table"),
            }
        )
    return items


def _rejected_items(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for row in results:
        if row.get("final_status") == "valid":
            continue
        items.append(
            {
                "local_term": row.get("local_term") or "",
                "english_term": row.get("english_term") or "",
                "standardized_term": row.get("standardized_term") or "",
                "category": row.get("category") or "",
                "final_status": "invalid",
                "blocked": True,
                "validation_result": row.get("validation_result") or "unknown",
                "validation_message": row.get("validation_message") or "",
            }
        )
    return items


def _build_registration_gate(allergies: dict[str, Any], conditions: dict[str, Any]) -> dict[str, Any]:
    accepted_allergies = _registration_items(allergies.get("results") or [])
    accepted_conditions = _registration_items(conditions.get("results") or [])
    rejected = _rejected_items(allergies.get("results") or []) + _rejected_items(
        conditions.get("results") or []
    )
    total = (allergies.get("total_count") or 0) + (conditions.get("total_count") or 0)
    invalid = (allergies.get("invalid_count") or 0) + (conditions.get("invalid_count") or 0)
    eligible = total > 0 and invalid == 0

    return {
        "eligible": eligible,
        "eligible_label": (
            "All terms verified — safe to save for registration"
            if eligible
            else (
                "Registration blocked — one or more terms are not in the official datasets"
                if total > 0
                else "No medical terms to register"
            )
        ),
        "conditions": accepted_conditions,
        "allergies": accepted_allergies,
        "rejected": rejected,
        "accepted_count": len(accepted_conditions) + len(accepted_allergies),
        "rejected_count": len(rejected),
    }


def _normalize_category(category: str) -> str | None:
    key = (category or "").strip().lower()
    if key in ("allergy", "allergies"):
        return "allergy"
    if key in ("condition", "conditions"):
        return "condition"
    if key in ("symptom", "symptoms"):
        return "symptom"
    return None


def _collect_matched_records(
    allergies: dict[str, Any],
    conditions: dict[str, Any],
    symptoms: dict[str, Any],
) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for block in (allergies, conditions, symptoms):
        for row in block.get("results") or []:
            if row.get("final_status") != "valid" or not row.get("record"):
                continue
            rec = row["record"]
            records.append(
                {
                    "term_type": row.get("category") or "",
                    "local_term": row.get("local_term") or "",
                    "english_term": row.get("english_term") or "",
                    "standardized_term": rec.get("name") or "",
                    "record_id": rec.get("record_id"),
                    "dataset_table": row.get("dataset_table") or "",
                    "dataset_source": row.get("dataset_source") or "",
                    "dataset_category": rec.get("dataset_category"),
                    "description": rec.get("description"),
                    "icd10_code": rec.get("icd10_code"),
                    "related_body_system": rec.get("related_body_system"),
                    "validation_status": "valid",
                }
            )
    return records


def validate_text_analysis(fuzzy_matching: dict[str, Any]) -> dict[str, Any]:
    grouped: dict[str, list[dict[str, Any]]] = {
        "allergy": [],
        "condition": [],
        "symptom": [],
    }
    for row in fuzzy_matching.get("results") or []:
        category = _normalize_category(str(row.get("category") or row.get("dataset_category") or ""))
        if category:
            grouped[category].append(row)

    allergies = validate_field_results(grouped["allergy"], "allergy")
    conditions = validate_field_results(grouped["condition"], "condition")
    symptoms = validate_field_results(grouped["symptom"], "symptom")

    valid = allergies["valid_count"] + conditions["valid_count"] + symptoms["valid_count"]
    invalid = allergies["invalid_count"] + conditions["invalid_count"] + symptoms["invalid_count"]
    total = valid + invalid

    if total == 0:
        overall = "empty"
        overall_label = "No terms to validate against datasets"
    elif invalid == 0:
        overall = "complete"
        overall_label = f"All {total} term(s) valid in official datasets"
    elif valid > 0:
        overall = "partial"
        overall_label = f"{valid}/{total} valid, {invalid} not in official datasets"
    else:
        overall = "failed"
        overall_label = f"0/{total} valid — no official dataset matches"

    return {
        "allergies": allergies,
        "conditions": conditions,
        "symptoms": symptoms,
        "matched_records": _collect_matched_records(allergies, conditions, symptoms),
        "overall_status": overall,
        "overall_status_label": overall_label,
        "validation_eligible": total > 0 and invalid == 0,
        "valid_count": valid,
        "invalid_count": invalid,
        "total_count": total,
    }


def validate_from_fuzzy_matching(fuzzy_matching: dict[str, Any]) -> dict[str, Any]:
    allergies = validate_field_results(
        (fuzzy_matching.get("allergies") or {}).get("results") or [],
        "allergy",
    )
    conditions = validate_field_results(
        (fuzzy_matching.get("conditions") or {}).get("results") or [],
        "condition",
    )
    registration = _build_registration_gate(allergies, conditions)

    valid = allergies["valid_count"] + conditions["valid_count"]
    invalid = allergies["invalid_count"] + conditions["invalid_count"]
    total = valid + invalid

    if total == 0:
        overall = "empty"
        overall_label = "No terms to validate against datasets"
    elif invalid == 0:
        overall = "complete"
        overall_label = f"All {total} term(s) valid in official datasets — registration allowed"
    elif valid > 0:
        overall = "partial"
        overall_label = f"{valid}/{total} valid, {invalid} blocked from registration"
    else:
        overall = "failed"
        overall_label = f"0/{total} valid — registration blocked"

    return {
        "allergies": allergies,
        "conditions": conditions,
        "registration": registration,
        "overall_status": overall,
        "overall_status_label": overall_label,
        "registration_eligible": registration["eligible"],
    }


def validate_profile(
    allergy_queue: list[dict[str, Any]],
    condition_queue: list[dict[str, Any]],
) -> dict[str, Any]:
    from medical_fuzzy_matcher import match_profile

    translation = {
        "allergies": {"validation_queue": allergy_queue},
        "conditions": {"validation_queue": condition_queue},
    }
    fuzzy = match_profile(translation)
    return validate_from_fuzzy_matching(fuzzy)
