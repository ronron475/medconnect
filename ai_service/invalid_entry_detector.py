"""Invalid entry detection after dataset validation — reject unknown terms."""

from __future__ import annotations

from typing import Any

FAILURE_MESSAGES: dict[str, str] = {
    "rejected_at_fuzzy": 'No close match was found in the official %s list (below 85%% similarity).',
    "not_in_dataset": '"%s" is not listed in our official %s database.',
    "id_mismatch": '"%s" could not be verified against the official %s record.',
    "missing_standard_term": '"%s" could not be standardized for validation.',
    "unknown": '"%s" is not a recognized %s in our system.',
}

USER_HINTS: dict[str, str] = {
    "rejected_at_fuzzy": "Check spelling, use English if possible, or pick a condition/allergy from the approved list. We cannot add new medical terms.",
    "not_in_dataset": "Only conditions and allergies already in our hospital dataset can be saved. We do not create or guess new entries.",
    "id_mismatch": "Please re-enter the term or contact support if you believe this is an error.",
    "missing_standard_term": "Try rephrasing the entry using a known medical term.",
    "unknown": "This entry cannot be saved until it matches an official dataset record.",
}

REASON_MAP: dict[str, str] = {
    "rejected_at_fuzzy": "no_dataset_match_fuzzy",
    "not_in_dataset": "not_in_official_dataset",
    "id_mismatch": "dataset_record_mismatch",
    "missing_standard_term": "missing_standardized_term",
}


def _display_term(row: dict[str, Any]) -> str:
    for key in ("local_term", "english_term", "standardized_term"):
        val = (row.get(key) or "").strip()
        if val:
            return val
    return "Unknown term"


def _format_invalid(row: dict[str, Any], category: str) -> dict[str, Any]:
    display = _display_term(row)
    reason_code = row.get("validation_result") or "unknown"
    dataset_label = "allergy" if category == "allergy" else "medical condition"
    failure_reason = REASON_MAP.get(reason_code, "not_in_official_dataset")
    hint_key = reason_code if reason_code in USER_HINTS else "unknown"
    user_friendly = (
        f'"{display}" cannot be saved as a {dataset_label}. {USER_HINTS[hint_key]}'
    )
    technical = (row.get("validation_message") or "").strip()
    detail = technical or FAILURE_MESSAGES.get(hint_key, FAILURE_MESSAGES["unknown"]) % (
        display,
        dataset_label,
    )

    return {
        "local_term": row.get("local_term") or "",
        "english_term": row.get("english_term") or "",
        "display_term": display,
        "category": category,
        "dataset_table": row.get("dataset_table")
        or ("allergies" if category == "allergy" else "medical_conditions"),
        "dataset_source": row.get("dataset_source") or "",
        "failure_reason": failure_reason,
        "failure_reason_code": reason_code,
        "validation_status": "invalid",
        "blocked": True,
        "detail_message": detail,
        "user_friendly_error": user_friendly,
        "fuzzy_score": row.get("fuzzy_score"),
    }


def _collect_invalid(dataset_validation: dict[str, Any]) -> list[dict[str, Any]]:
    entries: list[dict[str, Any]] = []
    for field, category in (
        ("conditions", "condition"),
        ("symptoms", "symptom"),
        ("allergies", "allergy"),
    ):
        block = dataset_validation.get(field) or {}
        for row in block.get("results") or []:
            if row.get("final_status") == "valid":
                continue
            entries.append(_format_invalid(row, category))

    if not entries:
        for rej in (dataset_validation.get("registration") or {}).get("rejected") or []:
            entries.append(_format_invalid(rej, rej.get("category") or "condition"))
    return entries


def _build_user_message(
    invalid_entries: list[dict[str, Any]], rejected: bool, eligible: bool
) -> str:
    if not rejected and eligible:
        return "All medical terms were verified against the official datasets. You may proceed with registration."
    if not rejected:
        return "No medical terms were submitted for validation."
    if len(invalid_entries) == 1:
        return invalid_entries[0]["user_friendly_error"]
    terms = ", ".join(f'"{e["display_term"]}"' for e in invalid_entries[:3])
    extra = " and others" if len(invalid_entries) > 3 else ""
    n = len(invalid_entries)
    return (
        f"{n} entries could not be verified: {terms}{extra}. "
        "MedConnect does not create or save new conditions or allergies that are not in the official datasets. "
        "Please correct or remove invalid entries before submitting."
    )


def detect(dataset_validation: dict[str, Any]) -> dict[str, Any]:
    invalid_entries = _collect_invalid(dataset_validation)
    registration = dataset_validation.get("registration") or {}
    eligible = bool(
        dataset_validation.get("registration_eligible") or registration.get("eligible")
    )
    has_invalid = bool(invalid_entries)
    submission_rejected = has_invalid or (registration.get("rejected_count") or 0) > 0
    validation_status = (
        "rejected" if submission_rejected else ("approved" if eligible else "empty")
    )
    user_message = _build_user_message(invalid_entries, submission_rejected, eligible)
    invalid_count = len(invalid_entries)
    summary = (
        "Submission passed invalid-entry checks."
        if not submission_rejected
        else (
            "Submission rejected: 1 invalid entry detected."
            if invalid_count == 1
            else f"Submission rejected: {invalid_count} invalid entries detected."
        )
    )

    return {
        "validation_status": validation_status,
        "submission_rejected": submission_rejected,
        "submission_accepted": not submission_rejected and eligible,
        "save_allowed": not submission_rejected and eligible,
        "invalid_count": invalid_count,
        "invalid_entries": invalid_entries,
        "failure_reasons": list({e["failure_reason"] for e in invalid_entries}),
        "user_message": user_message,
        "summary_message": summary,
        "error_message": user_message if submission_rejected else None,
        "policy": {
            "create_new_records": False,
            "infer_new_terms": False,
            "dataset_only": True,
            "description": "MedConnect only stores conditions and allergies that exist in the official CSV datasets.",
        },
    }
