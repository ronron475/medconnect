"""Hiligaynon Medical NLP Pipeline v2 — mirrors PHP 10-step architecture."""

from __future__ import annotations

from typing import Any

from hiligaynon_language_detector import detect as detect_language
from hiligaynon_text_normalizer import normalize, phrase_variants
from hiligaynon_phrase_translator import is_hiligaynon_input, translate_full_phrase
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


def analyze(text: str) -> dict[str, Any]:
    text = text.strip()
    if not text:
        return {"nlp_result": {}, "summary": "No input provided."}

    language = detect_language(text)
    normalized = normalize(text)
    phrase_translation = None
    for variant in phrase_variants(text):
        phrase_translation = translate_full_phrase(variant)
        if phrase_translation:
            break
    if phrase_translation is None and language.get("is_local"):
        phrase_translation = translate_full_phrase(normalized)

    concepts = enrich_from_translation(phrase_translation) if phrase_translation else []
    preprocessing = preprocess_medical_text(text)
    preprocessing["normalized"] = normalized
    preprocessing["language_detection"] = language

    translation = translate_text_block(preprocessing)
    if phrase_translation:
        translation["english_text"] = phrase_translation.get("english", "")
        translation["phrase_translation"] = phrase_translation

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
    confidence = round(min(1.0, sum(scores) / (len(scores) * 100)), 2) if scores else (0.65 if phrase_translation else 0.0)
    confidence_pct = int(round(confidence * 100))
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
        "triage_reason": triage.get("reason", ""),
        "matched_dataset_terms": matched,
        "confidence_score": confidence,
        "phrase_source": (phrase_translation or {}).get("source"),
    }

    highlight = build_highlight(english, term_results)
    valid_count = int((dataset or {}).get("valid_count") or 0)
    total_count = int((dataset or {}).get("total_count") or 0)

    return {
        "workflow": {
            "version": "2.0",
            "steps": [
                "language_identification",
                "text_normalization",
                "phrase_level_understanding",
                "hiligaynon_to_english_translation",
                "medical_concept_extraction",
                "dataset_matching",
                "fuzzy_matching",
                "medical_classification",
                "triage_detection",
                "structured_response",
            ],
        },
        "nlp_result": nlp_result,
        "original_input": text,
        "normalized_input": normalized,
        "detected_language": language.get("primary", "unknown"),
        "translation": translation,
        "translated_english": english,
        "highlighted_english": highlight.get("html", ""),
        "term_results": term_results,
        "valid_count": valid_count,
        "total_count": total_count,
        "summary": f'Translated: "{english}". Triage: {nlp_result["triage_level"]}.',
        "engine": "python-hiligaynon-nlp-v2",
    }
