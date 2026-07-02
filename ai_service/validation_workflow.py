"""Build unified per-term results for the validation UI."""

from __future__ import annotations

from typing import Any


def _invalid_message(item: dict[str, Any], fuzzy: dict[str, Any] | None, dataset: dict[str, Any] | None) -> str:
    local = item.get("local_term") or ""
    english = item.get("english_term") or ""
    if item.get("was_translated") and english:
        base = f'Translated "{local}" → "{english}", but no matching official record was found.'
    else:
        base = f'"{english}" is not listed in the official medical dataset.'
    if fuzzy and fuzzy.get("validation_status") == "unrecognized":
        return base + " Please use a known medical term from the hospital list."
    if dataset and dataset.get("final_status") == "invalid":
        return dataset.get("validation_message") or base
    return base


def build_term_results(
    translation: dict[str, Any],
    fuzzy_matching: dict[str, Any],
    dataset_validation: dict[str, Any],
) -> list[dict[str, Any]]:
    terms: list[dict[str, Any]] = []
    for field, cat_label in (("conditions", "conditions"), ("allergies", "allergies")):
        trans_block = translation.get(field) or {}
        fuzzy_block = fuzzy_matching.get(field) or {}
        ds_block = dataset_validation.get(field) or {}

        fuzzy_by_en: dict[str, dict[str, Any]] = {}
        for row in fuzzy_block.get("results") or []:
            key = (row.get("match_term") or row.get("english_term") or "").lower()
            if key:
                fuzzy_by_en[key] = row

        ds_by_en: dict[str, dict[str, Any]] = {}
        for row in ds_block.get("results") or []:
            key = (row.get("english_term") or "").lower()
            if key:
                ds_by_en[key] = row

        for item in trans_block.get("items") or []:
            english = item.get("english_term") or ""
            key = english.lower()
            fuzzy = fuzzy_by_en.get(key)
            dataset = ds_by_en.get(key)
            dataset_valid = (dataset or {}).get("final_status") == "valid"
            fuzzy_accepted = (fuzzy or {}).get("validation_status") == "accepted"
            display_valid = dataset_valid and fuzzy_accepted
            terms.append(
                {
                    "field": field,
                    "original_local": item.get("local_term") or "",
                    "input_language": item.get("input_language") or "unknown",
                    "was_translated": bool(item.get("was_translated")),
                    "english_term": english,
                    "standardized_term": (
                        (dataset or {}).get("record", {}).get("name")
                        or (fuzzy or {}).get("standardized_term")
                        if display_valid
                        else None
                    ),
                    "dataset_record_id": (dataset or {}).get("record", {}).get("record_id") if dataset_valid else None,
                    "fuzzy_score": int((fuzzy or {}).get("similarity_score") or 0),
                    "translation_status": item.get("status") or "",
                    "match_language": "english",
                    "dataset_valid": dataset_valid,
                    "display_status": "valid" if display_valid else "invalid",
                    "highlight": "success" if display_valid else "error",
                    "user_message": (
                        f"Found in official {cat_label} dataset."
                        if display_valid
                        else _invalid_message(item, fuzzy, dataset)
                    ),
                }
            )
    return terms


def build_validation_summary(
    term_results: list[dict[str, Any]],
    invalid_detection: dict[str, Any],
) -> str:
    if not term_results:
        return "No medical terms were extracted from your input."

    parts: list[str] = []
    for term in term_results:
        label = "Allergy" if term.get("field") == "allergies" else "Condition"
        inp = term.get("original_local") or term.get("english_term") or ""
        if term.get("display_status") == "valid":
            std = term.get("standardized_term") or term.get("english_term") or ""
            parts.append(f"{label}: {inp} → {std} (verified)")
        else:
            parts.append(f"{label}: {inp} (not in official dataset)")

    summary = ". ".join(parts) + "."
    if invalid_detection.get("submission_rejected"):
        user_msg = (invalid_detection.get("user_message") or "").strip()
        if user_msg:
            return user_msg
    return summary
