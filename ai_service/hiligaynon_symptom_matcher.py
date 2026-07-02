"""Flexible Hiligaynon symptom recognition with fuzzy matching and phrase extraction."""

from __future__ import annotations

import re
from typing import Any

from preprocess import FILLER_WORDS, remove_fillers
from rapidfuzz import fuzz, process
from symptom_lexicon_loader import fuzzy_threshold, variant_index, variants_by_length


def collapse_repeated_characters(text: str, max_repeat: int = 2) -> str:
    """grabeeeeee -> grabee"""
    if not text:
        return ""
    return re.sub(r"(.)\1{2,}", lambda m: m.group(1) * max_repeat, text)


def normalize_symptom_text(text: str) -> str:
    """Case-insensitive normalization: punctuation, spacing, repeated chars."""
    if not text or not text.strip():
        return ""
    lowered = text.lower().strip()
    lowered = collapse_repeated_characters(lowered)
    cleaned = re.sub(r"[^a-z0-9\s\-]", " ", lowered)
    cleaned = re.sub(r"\s+", " ", cleaned).strip()
    return cleaned


def _phrase_spans(text: str, phrase: str) -> list[tuple[int, int]]:
    if not phrase or not text:
        return []
    pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", re.I)
    return [(m.start(), m.end()) for m in pattern.finditer(text)]


def _fuzzy_match_variant(candidate: str, threshold: int) -> tuple[dict[str, Any] | None, int]:
    index = variant_index()
    if not candidate or not index:
        return None, 0
    key = candidate.lower().strip()
    if key in index:
        return index[key], 100
    choices = list(index.keys())
    result = process.extractOne(key, choices, scorer=fuzz.WRatio)
    if not result:
        return None, 0
    match, score, _ = result
    if score < threshold:
        return None, int(score)
    return index[match], int(score)


def _candidate_phrases(text: str, max_words: int = 4) -> list[str]:
    tokens = [t for t in text.split() if t and t not in FILLER_WORDS]
    if not tokens:
        return []
    candidates: list[str] = []
    for size in range(max_words, 0, -1):
        for i in range(0, len(tokens) - size + 1):
            phrase = " ".join(tokens[i : i + size])
            if len(phrase) >= 3:
                candidates.append(phrase)
    return candidates


def recognize_symptoms(
    text: str,
    *,
    threshold: int | None = None,
    include_fuzzy: bool = True,
) -> dict[str, Any]:
    """
    Detect Hiligaynon symptoms in free text or short phrases.

    Returns structured detections plus English symptom list for downstream NLP.
    """
    original = text or ""
    normalized = normalize_symptom_text(original)
    cleaned = remove_fillers(normalized)
    working = cleaned or normalized
    thresh = threshold if threshold is not None else fuzzy_threshold()

    detections: list[dict[str, Any]] = []
    occupied = [False] * max(len(working), 1)
    seen_keys: set[str] = set()

    def add_detection(
        detected: str,
        canonical: str,
        meta: dict[str, Any],
        confidence: int,
        method: str,
        span: tuple[int, int] | None = None,
    ) -> None:
        key = meta["symptom_key"]
        if key in seen_keys:
            return
        seen_keys.add(key)
        detections.append(
            {
                "detected_symptom": detected,
                "normalized_symptom": canonical,
                "english_translation": meta.get("english") or "",
                "medical_term": meta.get("medical_term") or key,
                "category": meta.get("category") or "general",
                "symptom_key": key,
                "confidence": confidence,
                "match_method": method,
                "span": {"start": span[0], "end": span[1]} if span else None,
            }
        )

    # 1) Exact phrase match (longest first)
    for variant in variants_by_length():
        for start, end in _phrase_spans(working, variant):
            if any(occupied[start:end]):
                continue
            meta = variant_index().get(variant)
            if not meta:
                continue
            for i in range(start, min(end, len(occupied))):
                occupied[i] = True
            snippet = working[start:end]
            add_detection(snippet, variant, meta, 100, "exact_phrase", (start, end))

    # 2) Fuzzy phrase/token match on remaining text
    if include_fuzzy:
        fuzzy_candidates = _candidate_phrases(working)
        for candidate in fuzzy_candidates:
            if any(occupied[i] for i in range(len(working)) if working[max(0, i - 1) : i + len(candidate)]):
                pass
            meta, score = _fuzzy_match_variant(candidate, thresh)
            if meta and meta["symptom_key"] not in seen_keys:
                add_detection(candidate, meta.get("canonical_variant") or candidate, meta, score, "fuzzy")

    # 3) Single-token fuzzy for short typos (katol, kakatul)
    if include_fuzzy:
        for token in re.findall(r"[a-z0-9\-]+", working):
            if token in FILLER_WORDS or len(token) < 3:
                continue
            meta, score = _fuzzy_match_variant(token, thresh)
            if meta and meta["symptom_key"] not in seen_keys and score >= thresh:
                add_detection(token, meta.get("canonical_variant") or token, meta, score, "fuzzy_token")

    detections.sort(key=lambda d: (-d["confidence"], d["english_translation"]))
    english_symptoms = []
    seen_en: set[str] = set()
    for d in detections:
        en = (d.get("english_translation") or "").lower()
        if en and en not in seen_en:
            seen_en.add(en)
            english_symptoms.append(d["english_translation"])

    return {
        "original_text": original,
        "normalized_text": normalized,
        "cleaned_text": cleaned,
        "fuzzy_threshold": thresh,
        "detections": detections,
        "detection_count": len(detections),
        "english_symptoms": english_symptoms,
        "lexicon": __import__("symptom_lexicon_loader").lexicon_stats(),
    }


def detect_symptom_output(text: str) -> list[dict[str, Any]]:
    """Return only the detection objects (spec format)."""
    return recognize_symptoms(text).get("detections") or []
