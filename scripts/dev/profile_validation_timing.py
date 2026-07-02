"""Profile validation timing breakdown."""
from __future__ import annotations

import time

from preprocess import preprocess_profile
from medical_translation_pipeline import prepare_field_translation, enrich_profile_fields_with_ai, _attach_field_pipeline_stages
from medical_fuzzy_matcher import match_queue, match_text_queue
from profile_validation import _build_fuzzy_matching, _build_dataset_validation, _build_term_results


def tick(label: str, start: float) -> float:
    now = time.perf_counter()
    print(f"{label}: {now - start:.2f}s")
    return now


allergies = "walay"
conditions = "masakit ulo ko"
t0 = time.perf_counter()
t = t0

preprocessing = preprocess_profile(allergies, conditions)
t = tick("preprocess", t)

c_block, c_ctx = prepare_field_translation(preprocessing["conditions"], conditions, "condition")
a_block, a_ctx = prepare_field_translation(preprocessing["allergies"], allergies, "allergy")
t = tick("prepare_fields", t)

c_block, a_block = enrich_profile_fields_with_ai(
    c_block, a_block, conditions, allergies, c_ctx, a_ctx
)
t = tick("groq_enrich", t)

c_block = _attach_field_pipeline_stages(c_block, c_ctx)
a_block = _attach_field_pipeline_stages(a_block, a_ctx)
t = tick("attach_stages", t)

translation = {"conditions": c_block, "allergies": a_block}
conditions_fuzzy = match_text_queue((c_block.get("validation_queue") or []))
allergies_fuzzy = match_queue((a_block.get("validation_queue") or []), "allergy")
t = tick("fuzzy_match", t)

dataset_validation = _build_dataset_validation(conditions_fuzzy, allergies_fuzzy)
t = tick("dataset_validation", t)

print(f"TOTAL: {time.perf_counter() - t0:.2f}s")
print("c_ai", c_block.get("ai_interpretation", {}).get("provider"), c_block.get("ai_interpretation", {}).get("groq_skipped"))
print("a_ai", a_block.get("ai_interpretation", {}).get("provider"), a_block.get("ai_interpretation", {}).get("groq_skipped"))
