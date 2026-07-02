"""Symptom-to-disease ML pipeline: XGBoost classifier + severity triage + precautions."""

from __future__ import annotations

import csv
import json
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_ARCHIVE_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp" / "archive_source"
_MODEL_DIR = Path(__file__).resolve().parent / "models"
_MODEL_FILE = _MODEL_DIR / "disease_classifier.joblib"
_META_FILE = _MODEL_DIR / "disease_classifier_meta.json"

DISCLAIMER = (
    "AI-generated suggestions only — not a diagnosis. "
    "A licensed doctor must verify before clinical use."
)

# Map common English phrases (from analyzer / Hiligaynon translation) to dataset symptom keys.
SYMPTOM_ALIASES: dict[str, str | list[str]] = {
    "fever": "mild_fever",
    "high fever": "high_fever",
    "mild fever": "mild_fever",
    "cough": "cough",
    "headache": "headache",
    "chest pain": "chest_pain",
    "difficulty breathing": "breathlessness",
    "shortness of breath": "breathlessness",
    "breathlessness": "breathlessness",
    "vomiting": "vomiting",
    "nausea": "nausea",
    "diarrhea": "diarrhoea",
    "diarrhoea": "diarrhoea",
    "stomach pain": ["stomach_pain", "abdominal_pain", "belly_pain"],
    "abdominal pain": ["abdominal_pain", "stomach_pain", "belly_pain"],
    "belly pain": "belly_pain",
    "sore throat": "throat_irritation",
    "rash": "skin_rash",
    "skin rash": "skin_rash",
    "itching": "itching",
    "fatigue": "fatigue",
    "dizziness": "dizziness",
    "swelling": "swelling_of_stomach",
    "body pain": "joint_pain",
    "back pain": "back_pain",
    "runny nose": "runny_nose",
    "joint pain": "joint_pain",
    "weakness": "weakness_in_limbs",
    "yellow skin": "yellowish_skin",
    "yellow eyes": "yellowing_of_eyes",
    "dehydration": "dehydration",
    "constipation": "constipation",
    "anxiety": "anxiety",
    "depression": "depression",
}

CRITICAL_SYMPTOMS = frozenset(
    {
        "chest_pain",
        "breathlessness",
        "high_fever",
        "coma",
        "swelling_of_stomach",
        "weakness_in_limbs",
        "acute_liver_failure",
        "blood_in_sputum",
        "stomach_bleeding",
    }
)


def _normalize_symptom_key(raw: str) -> str:
    cleaned = re.sub(r"\s+", "_", (raw or "").strip().lower())
    cleaned = cleaned.replace("__", "_").strip("_")
    return cleaned


@lru_cache(maxsize=1)
def load_severity_weights() -> dict[str, int]:
    path = _ARCHIVE_DIR / "Symptom-severity.csv"
    weights: dict[str, int] = {}
    if not path.is_file():
        return weights
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            key = _normalize_symptom_key(row.get("Symptom", ""))
            if not key:
                continue
            try:
                weights[key] = int(float(row.get("weight") or 0))
            except ValueError:
                continue
    return weights


@lru_cache(maxsize=1)
def load_disease_descriptions() -> dict[str, str]:
    path = _ARCHIVE_DIR / "symptom_Description.csv"
    descriptions: dict[str, str] = {}
    if not path.is_file():
        return descriptions
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            disease = (row.get("Disease") or "").strip()
            desc = (row.get("Description") or "").strip()
            if disease:
                descriptions[disease] = desc
    return descriptions


@lru_cache(maxsize=1)
def load_disease_precautions() -> dict[str, list[str]]:
    path = _ARCHIVE_DIR / "symptom_precaution.csv"
    precautions: dict[str, list[str]] = {}
    if not path.is_file():
        return precautions
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            disease = (row.get("Disease") or "").strip()
            if not disease:
                continue
            items = []
            for idx in range(1, 5):
                value = (row.get(f"Precaution_{idx}") or "").strip()
                if value:
                    items.append(value)
            precautions[disease] = items
    return precautions


@lru_cache(maxsize=1)
def load_model_meta() -> dict[str, Any] | None:
    if not _META_FILE.is_file():
        return None
    try:
        return json.loads(_META_FILE.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return None


@lru_cache(maxsize=1)
def load_classifier():
    if not _MODEL_FILE.is_file():
        return None
    try:
        import joblib

        artifact = joblib.load(_MODEL_FILE)
        if isinstance(artifact, dict) and "model" in artifact:
            return artifact
        return {"model": artifact, "label_encoder": None}
    except Exception:
        return None


def model_available() -> bool:
    return load_classifier() is not None and load_model_meta() is not None


def symptom_phrase(symptom_key: str) -> str:
    return symptom_key.replace("_", " ")


def _alias_targets(term: str) -> list[str]:
    term = term.strip().lower()
    if not term:
        return []
    mapped = SYMPTOM_ALIASES.get(term)
    if mapped is None:
        return [_normalize_symptom_key(term)]
    if isinstance(mapped, list):
        return mapped
    return [mapped]


def extract_model_symptoms(english_text: str, extra_terms: list[str] | None = None) -> list[str]:
    """Return canonical dataset symptom keys found in English text."""
    meta = load_model_meta()
    vocabulary: set[str] = set(meta.get("symptom_columns", [])) if meta else set()
    if not vocabulary:
        return []

    text = (english_text or "").lower()
    found: list[str] = []
    seen: set[str] = set()

    def add(key: str) -> None:
        key = _normalize_symptom_key(key)
        if key in vocabulary and key not in seen:
            seen.add(key)
            found.append(key)

    for key in sorted(vocabulary, key=len, reverse=True):
        phrase = symptom_phrase(key)
        if len(phrase) < 3:
            continue
        if re.search(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", text):
            add(key)

    for term in extra_terms or []:
        for target in _alias_targets(term):
            add(target)

    return found


def calculate_triage(
    model_symptoms: list[str],
    urgent_flags: list[str] | None = None,
) -> dict[str, Any]:
    weights = load_severity_weights()
    score = sum(weights.get(symptom, 2) for symptom in model_symptoms)
    urgent = list(urgent_flags or [])

    if urgent or any(symptom in CRITICAL_SYMPTOMS for symptom in model_symptoms):
        level = "critical"
        label = "Seek urgent medical care"
    elif score >= 22:
        level = "high"
        label = "High priority — evaluate soon"
    elif score >= 12:
        level = "moderate"
        label = "Moderate — schedule consultation"
    elif model_symptoms:
        level = "low"
        label = "Low urgency — monitor symptoms"
    else:
        level = "unknown"
        label = "Insufficient symptoms for triage"

    return {
        "level": level,
        "score": score,
        "label": label,
        "symptoms_used": len(model_symptoms),
    }


def predict_diseases(model_symptoms: list[str], top_k: int = 3) -> list[dict[str, Any]]:
    artifact = load_classifier()
    meta = load_model_meta()
    if artifact is None or meta is None or not model_symptoms:
        return []

    model = artifact.get("model")
    label_encoder = artifact.get("label_encoder")
    if model is None:
        return []

    symptom_columns: list[str] = meta.get("symptom_columns", [])
    if not symptom_columns:
        return []

    try:
        import numpy as np

        row = np.array([[1 if col in model_symptoms else 0 for col in symptom_columns]])
        probabilities = model.predict_proba(row)[0]
        if label_encoder is not None:
            classes = list(label_encoder.classes_)
        else:
            classes = list(getattr(model, "classes_", []))
    except Exception:
        return []

    descriptions = load_disease_descriptions()
    precautions = load_disease_precautions()

    ranked = sorted(
        zip(classes, probabilities),
        key=lambda item: item[1],
        reverse=True,
    )[:top_k]

    results: list[dict[str, Any]] = []
    for disease, confidence in ranked:
        if confidence < 0.05:
            continue
        results.append(
            {
                "disease": disease,
                "confidence": round(float(confidence) * 100, 1),
                "description": descriptions.get(disease, ""),
                "precautions": precautions.get(disease, []),
            }
        )
    return results


def build_prediction_summary(
    predictions: list[dict[str, Any]],
    triage: dict[str, Any],
) -> str:
    parts: list[str] = []
    if triage.get("level") not in (None, "unknown"):
        parts.append(f"Triage: {triage.get('label')} (score {triage.get('score', 0)}).")
    if predictions:
        top = predictions[0]
        parts.append(
            f"Possible condition: {top['disease']} ({top['confidence']}% confidence)."
        )
        if len(predictions) > 1:
            alts = ", ".join(
                f"{item['disease']} ({item['confidence']}%)" for item in predictions[1:]
            )
            parts.append(f"Alternatives: {alts}.")
    parts.append(DISCLAIMER)
    return " ".join(parts)


def enrich_transcript_analysis(
    english_transcript: str,
    basic_symptoms: list[str] | None = None,
    urgent_flags: list[str] | None = None,
    top_k: int = 3,
) -> dict[str, Any]:
    """Run ML disease prediction + triage on translated transcript text."""
    model_symptoms = extract_model_symptoms(english_transcript, basic_symptoms)
    predictions = predict_diseases(model_symptoms, top_k=top_k)
    triage = calculate_triage(model_symptoms, urgent_flags)
    ml_summary = build_prediction_summary(predictions, triage)

    return {
        "model_symptoms": [symptom_phrase(s) for s in model_symptoms],
        "model_symptom_keys": model_symptoms,
        "disease_predictions": predictions,
        "triage": triage,
        "ml_summary": ml_summary,
        "ml_available": model_available(),
        "ml_disclaimer": DISCLAIMER,
    }
