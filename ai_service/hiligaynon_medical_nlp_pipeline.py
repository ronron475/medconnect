"""Hiligaynon Medical NLP Pipeline v3 — evidence-based clinical triage CDS."""

from __future__ import annotations

from typing import Any

from hiligaynon_language_detector import detect as detect_language
from hiligaynon_text_normalizer import normalize, phrase_variants
from hiligaynon_phrase_translator import translate_full_phrase
from medical_concept_extractor import classify, enrich_from_translation
from medical_triage_detector import detect as detect_triage
from medical_text_analysis import (
    build_highlight,
    build_term_results,
    translate_text_block,
)
from medical_dataset_validator import validate_text_analysis
from medical_fuzzy_matcher import match_text_queue
from preprocess import preprocess_medical_text

PIPELINE_STEPS = [
    "detect_language",
    "normalize_text",
    "correct_misspellings",
    "translate_hiligaynon_to_english",
    "tokenize",
    "remove_unnecessary_words",
    "medical_entity_recognition",
    "body_part_recognition",
    "extract_symptoms",
    "negation_detection",
    "extract_duration",
    "extract_temperature",
    "extract_pain_scale",
    "extract_age_group",
    "pregnancy_detection",
    "extract_risk_factors",
    "detect_emergency_red_flags",
    "symptom_combination_analysis",
    "calculate_severity_score",
    "clinical_reasoning",
    "confidence_calculation",
    "priority_classification",
    "return_recommendation",
]


def analyze(text: str) -> dict[str, Any]:
    text = text.strip()
    if not text:
        return {"nlp_result": {}, "summary": "No input provided."}

    # 1–2. Normalize + misspellings (preprocess handles misspelling corrections)
    language = detect_language(text)
    normalized = normalize(text)
    preprocessing = preprocess_medical_text(text)
    preprocessing["normalized"] = normalized
    preprocessing["language_detection"] = language

    # 3–4. Language detect + translate Hiligaynon/Filipino → English
    phrase_translation = None
    for variant in phrase_variants(text):
        phrase_translation = translate_full_phrase(variant)
        if phrase_translation:
            break
    if phrase_translation is None and language.get("is_local"):
        phrase_translation = translate_full_phrase(normalized)

    concepts = enrich_from_translation(phrase_translation) if phrase_translation else []
    translation = translate_text_block(preprocessing)
    if phrase_translation:
        translation["english_text"] = phrase_translation.get("english", "")
        translation["phrase_translation"] = phrase_translation

    # 5–7. Tokenize / stopword cleanup / fuzzy + dataset symptom extraction
    fuzzy = match_text_queue(translation.get("validation_queue") or [])
    dataset = validate_text_analysis(fuzzy)
    term_results = build_term_results(translation, fuzzy, dataset)
    english = translation.get("english_text") or (phrase_translation or {}).get("english", "")
    matched = [
        str(t["standardized_term"])
        for t in term_results
        if t.get("validation_status") == "valid" and t.get("standardized_term")
    ]
    scores = [int(t.get("fuzzy_score") or 0) for t in term_results if t.get("validation_status") == "valid"]
    confidence = round(min(1.0, sum(scores) / (len(scores) * 100)), 2) if scores else (0.70 if phrase_translation else 0.0)
    confidence_pct = int(round(confidence * 100))

    # 8–17. Clinical feature extraction + red flags + severity + explanation
    triage = detect_triage(
        text,
        english,
        phrase_translation or {},
        concepts,
        validated_terms=matched,
        confidence_score=confidence_pct,
    )
    classification = classify(concepts, phrase_translation or {})
    body_parts = list({c.get("body_part") for c in concepts if c.get("body_part")})
    recommendation_payload = triage.get("recommendation_payload") or {}

    nlp_result = {
        "original_text": text,
        "detected_language": language.get("primary", "unknown"),
        "language_tags": language.get("tags", []),
        "normalized_text": normalized,
        "english_translation": english,
        "medical_concepts": concepts,
        "body_parts": body_parts,
        "category": classification.get("category", "symptom"),
        "severity": triage.get("severity", "mild"),
        "triage_level": triage.get("triage_level", "LOW"),
        "triage_display": triage.get("triage_display", "NON-URGENT"),
        "triage_reason": triage.get("reason", ""),
        "matched_dataset_terms": matched,
        "confidence_score": confidence,
        "phrase_source": (phrase_translation or {}).get("source"),
        "detected_symptoms": triage.get("detected_symptoms") or [],
        "duration": triage.get("duration") or "",
        "pain_scale": triage.get("pain_scale") or {},
        "temperature": triage.get("temperature") or {},
        "risk_factors": triage.get("risk_factors") or [],
        "age_group": triage.get("age_group") or "Unknown",
        "red_flags": triage.get("red_flags") or [],
        "severity_score": triage.get("severity_score", 0),
        "classification": triage.get("triage_display", "NON-URGENT"),
        "priority": triage.get("priority", "Normal"),
        "confidence": triage.get("confidence_score", confidence_pct),
        "reason": triage.get("reason", ""),
        "recommendation": triage.get("recommendation", ""),
        "needs_provider_review": bool(triage.get("needs_provider_review")),
    }

    highlight = build_highlight(english, term_results)
    valid_count = int((dataset or {}).get("valid_count") or 0)
    total_count = int((dataset or {}).get("total_count") or 0)

    return {
        "workflow": {
            "version": "3.0",
            "steps": PIPELINE_STEPS,
            "purpose": "clinical_triage_decision_support",
            "does_not": ["diagnose_disease", "prescribe_medication"],
        },
        "nlp_result": nlp_result,
        "clinical_recommendation": recommendation_payload,
        "original_input": text,
        "normalized_input": normalized,
        "detected_language": language.get("primary", "unknown"),
        "translation": translation,
        "translated_english": english,
        "highlighted_english": highlight.get("html", ""),
        "term_results": term_results,
        "valid_count": valid_count,
        "total_count": total_count,
        "triage": triage,
        "summary": (
            f'Triage: {nlp_result["triage_display"]} '
            f'(score={nlp_result["severity_score"]}, confidence={nlp_result["confidence"]}%).'
        ),
        "engine": "python-hiligaynon-nlp-v3",
    }
