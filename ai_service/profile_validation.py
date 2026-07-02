"""Step 3 profile validation with conditions/symptoms/allergies recognition."""

from __future__ import annotations

from typing import Any

from medical_dataset_validator import validate_field_results, validate_text_analysis
from medical_fuzzy_matcher import match_queue, match_text_queue
from medical_recognition import build_field_recognition, detect_field_language, term_type_label
from preprocess import preprocess_profile
from medical_translation_pipeline import run_medical_translation


def _build_fuzzy_matching(conditions_fuzzy: dict[str, Any], allergies_fuzzy: dict[str, Any]) -> dict[str, Any]:
    accepted = conditions_fuzzy.get("accepted_count", 0) + allergies_fuzzy.get("accepted_count", 0)
    rejected = conditions_fuzzy.get("rejected_count", 0) + allergies_fuzzy.get("rejected_count", 0)
    total = conditions_fuzzy.get("total_count", 0) + allergies_fuzzy.get("total_count", 0)
    if total == 0:
        overall, label = "empty", "No terms to fuzzy match"
    elif accepted == total:
        overall, label = "complete", f"RapidFuzz: {accepted}/{total} terms accepted (≥85%)"
    elif accepted > 0:
        overall, label = "partial", f"RapidFuzz: {accepted}/{total} accepted, {rejected} unrecognized"
    else:
        overall, label = "none", f"RapidFuzz: 0/{total} terms met 85% threshold"
    return {
        "conditions": conditions_fuzzy,
        "allergies": allergies_fuzzy,
        "overall_status": overall,
        "overall_status_label": label,
        "threshold": 85,
        "engine": conditions_fuzzy.get("engine") or allergies_fuzzy.get("engine") or "rapidfuzz",
    }


def _matched_records_from_field(field_validation: dict[str, Any]) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    for row in field_validation.get("results") or []:
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


def _registration_items(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    for row in results:
        if row.get("final_status") != "valid" or not row.get("record"):
            continue
        rec = row["record"]
        items.append(
            {
                "local_term": row.get("local_term") or "",
                "standardized_term": rec.get("name"),
                "record_id": rec.get("record_id"),
                "term_type": row.get("category") or "",
                "category": rec.get("record_category") or row.get("category") or "",
                "dataset_category": rec.get("dataset_category"),
                "dataset_source": row.get("dataset_source") or "",
                "dataset_table": row.get("dataset_table") or "",
                "icd10_code": rec.get("icd10_code"),
            }
        )
    return items


def _rejected_items(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    return [
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
        for row in results
        if row.get("final_status") != "valid"
    ]


def _build_dataset_validation(conditions_fuzzy: dict[str, Any], allergies_fuzzy: dict[str, Any]) -> dict[str, Any]:
    conditions_analysis = validate_text_analysis(conditions_fuzzy)
    allergies = validate_field_results(allergies_fuzzy.get("results") or [], "allergy")

    valid = conditions_analysis.get("valid_count", 0) + allergies.get("valid_count", 0)
    invalid = conditions_analysis.get("invalid_count", 0) + allergies.get("invalid_count", 0)
    total = valid + invalid

    if total == 0:
        overall, overall_label = "empty", "No terms to validate against datasets"
    elif invalid == 0:
        overall, overall_label = "complete", f"All {total} term(s) valid in official datasets — registration allowed"
    elif valid > 0:
        overall, overall_label = "partial", f"{valid}/{total} valid, {invalid} blocked from registration"
    else:
        overall, overall_label = "failed", f"0/{total} valid — registration blocked"

    registration = {
        "eligible": total > 0 and invalid == 0,
        "eligible_label": (
            "All terms verified — safe to save for registration"
            if total > 0 and invalid == 0
            else (
                "Registration blocked — one or more terms are not in the official datasets"
                if total > 0
                else "No medical terms to register"
            )
        ),
        "conditions": _registration_items((conditions_analysis.get("conditions") or {}).get("results") or []),
        "symptoms": _registration_items((conditions_analysis.get("symptoms") or {}).get("results") or []),
        "allergies": _registration_items(allergies.get("results") or []),
        "rejected": _rejected_items((conditions_analysis.get("conditions") or {}).get("results") or [])
        + _rejected_items((conditions_analysis.get("symptoms") or {}).get("results") or [])
        + _rejected_items(allergies.get("results") or []),
    }
    registration["accepted_count"] = (
        len(registration["conditions"]) + len(registration["symptoms"]) + len(registration["allergies"])
    )
    registration["rejected_count"] = len(registration["rejected"])

    matched_records = list(conditions_analysis.get("matched_records") or []) + _matched_records_from_field(allergies)

    return {
        "conditions": conditions_analysis.get("conditions") or {},
        "symptoms": conditions_analysis.get("symptoms") or {},
        "allergies": allergies,
        "matched_records": matched_records,
        "registration": registration,
        "overall_status": overall,
        "overall_status_label": overall_label,
        "registration_eligible": registration["eligible"],
        "valid_count": valid,
        "invalid_count": invalid,
        "total_count": total,
    }


def _build_term_results(
    translation: dict[str, Any],
    conditions_fuzzy: dict[str, Any],
    allergies_fuzzy: dict[str, Any],
    dataset_validation: dict[str, Any],
) -> list[dict[str, Any]]:
    terms: list[dict[str, Any]] = []
    terms.extend(
        _field_term_results(
            "conditions",
            translation.get("conditions") or {},
            conditions_fuzzy,
            dataset_validation,
            allergy_only=False,
        )
    )
    terms.extend(
        _field_term_results(
            "allergies",
            translation.get("allergies") or {},
            allergies_fuzzy,
            dataset_validation,
            allergy_only=True,
        )
    )
    return terms


def _field_term_results(
    field: str,
    translation_field: dict[str, Any],
    fuzzy_field: dict[str, Any],
    dataset_validation: dict[str, Any],
    allergy_only: bool = False,
) -> list[dict[str, Any]]:
    fuzzy_by_en: dict[str, dict[str, Any]] = {}
    for row in fuzzy_field.get("results") or []:
        key = (row.get("english_term") or row.get("match_term") or row.get("input_term") or "").lower()
        if key:
            fuzzy_by_en[key] = row

    dataset_by_en: dict[str, dict[str, Any]] = {}
    blocks = ["allergies"] if allergy_only else ["conditions", "symptoms"]
    for block_name in blocks:
        for row in (dataset_validation.get(block_name) or {}).get("results") or []:
            key = (row.get("english_term") or "").lower()
            if key:
                dataset_by_en[key] = row

    items_by_en = {
        (item.get("english_term") or "").lower(): item for item in translation_field.get("items") or []
    }
    queue = translation_field.get("validation_queue") or translation_field.get("items") or []

    terms: list[dict[str, Any]] = []
    for queue_item in queue:
        english = queue_item.get("english_term") or queue_item.get("match_term") or ""
        key = english.lower()
        item = items_by_en.get(key) or queue_item
        fuzzy = fuzzy_by_en.get(key)
        dataset = dataset_by_en.get(key)
        dataset_valid = (dataset or {}).get("final_status") == "valid"
        fuzzy_accepted = (fuzzy or {}).get("validation_status") == "accepted"
        display_valid = dataset_valid and fuzzy_accepted
        term_type = term_type_label(
            field,
            str((dataset or {}).get("category") or (fuzzy or {}).get("category") or (fuzzy or {}).get("dataset_category") or ""),
        )
        dataset_label = {"allergy": "allergies", "symptom": "symptoms"}.get(term_type, "medical conditions")
        terms.append(
            {
                "field": field,
                "term_type": term_type,
                "original_local": item.get("local_term") or queue_item.get("local_term") or "",
                "input_language": item.get("input_language") or queue_item.get("input_language") or "unknown",
                "was_translated": bool(item.get("was_translated") or queue_item.get("was_translated")),
                "english_term": english,
                "standardized_term": (
                    (dataset or {}).get("record", {}).get("name")
                    or (fuzzy or {}).get("standardized_term")
                    if display_valid
                    else None
                ),
                "dataset_record_id": (dataset or {}).get("record", {}).get("record_id") if display_valid else None,
                "dataset_table": (dataset or {}).get("dataset_table") if display_valid else None,
                "matched_record": (dataset or {}).get("record") if display_valid else None,
                "fuzzy_score": int((fuzzy or {}).get("similarity_score") or 0),
                "translation_status": item.get("status") or "",
                "match_language": "english",
                "dataset_valid": dataset_valid,
                "display_status": "valid" if display_valid else "invalid",
                "highlight": display_valid,
                "user_message": (
                    f"Found in official {dataset_label} dataset."
                    if display_valid
                    else f'"{english}" is not listed in the official {term_type} dataset.'
                ),
            }
        )
    return terms


def run_profile_validation(allergies: str, conditions: str) -> dict[str, Any]:
    preprocessing = preprocess_profile(allergies, conditions)
    translation = run_medical_translation(preprocessing, conditions, allergies)
    conditions_fuzzy = match_text_queue((translation.get("conditions") or {}).get("validation_queue") or [])
    allergies_fuzzy = match_queue((translation.get("allergies") or {}).get("validation_queue") or [], "allergy")
    fuzzy_matching = _build_fuzzy_matching(conditions_fuzzy, allergies_fuzzy)
    dataset_validation = _build_dataset_validation(conditions_fuzzy, allergies_fuzzy)
    term_results = _build_term_results(translation, conditions_fuzzy, allergies_fuzzy, dataset_validation)

    conditions_terms = [t for t in term_results if t.get("field") == "conditions"]
    allergies_terms = [t for t in term_results if t.get("field") == "allergies"]

    return {
        "preprocessing": preprocessing,
        "translation": translation,
        "fuzzy_matching": fuzzy_matching,
        "dataset_validation": dataset_validation,
        "term_results": term_results,
        "matched_records": dataset_validation.get("matched_records") or [],
        "conditions_recognition": {
            **build_field_recognition(
                conditions,
                preprocessing.get("conditions") or {},
                translation.get("conditions") or {},
                conditions_terms,
            ),
            "detected_language": detect_field_language(
                preprocessing.get("conditions") or {},
                translation.get("conditions") or {},
            ),
        },
        "allergies_recognition": {
            **build_field_recognition(
                allergies,
                preprocessing.get("allergies") or {},
                translation.get("allergies") or {},
                allergies_terms,
            ),
            "detected_language": detect_field_language(
                preprocessing.get("allergies") or {},
                translation.get("allergies") or {},
            ),
        },
    }
