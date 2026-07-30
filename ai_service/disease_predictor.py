"""Symptom-to-disease ML pipeline: XGBoost classifier + severity triage + precautions."""

from __future__ import annotations

import csv
import json
import re
from collections import Counter
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
    "toxic look": "toxic_look_(typhos)",
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
    "yellowish skin": "yellowish_skin",
    "yellow eyes": "yellowing_of_eyes",
    "yellowing of eyes": "yellowing_of_eyes",
    "yellowing of the eyes": "yellowing_of_eyes",
    "jaundice": ["yellowish_skin", "yellowing_of_eyes"],
    "loss of appetite": "loss_of_appetite",
    "no appetite": "loss_of_appetite",
    "poor appetite": "loss_of_appetite",
    "red itchy eyes": "redness_of_eyes",
    "red eye": "redness_of_eyes",
    "stomach ache": ["stomach_pain", "abdominal_pain"],
    "liver pain": "abdominal_pain",
    "itchiness": "itching",
    "itchy": "itching",
    "dizzy": "dizziness",
    "lightheaded": "dizziness",
    "dehydration": "dehydration",
    "constipation": "constipation",
    "anxiety": "anxiety",
    "depression": "depression",
    "heartburn": "acidity",
    "acid reflux": "acidity",
    "hyperacidity": "acidity",
    "hyper acidity": "acidity",
    "gastroesophageal reflux disease": ["acidity", "stomach_pain", "chest_pain"],
    "indigestion": "indigestion",
    "mouth ulcer": "ulcers_on_tongue",
    "mouth ulcers": "ulcers_on_tongue",
    "singaw": "ulcers_on_tongue",
    "passing gas": "passage_of_gases",
    "flatulence": "passage_of_gases",
    "bloated stomach": "passage_of_gases",
    "dark urine": "dark_urine",
    "yellow urine": "yellow_urine",
    "weight loss": "weight_loss",
    "lethargy": "lethargy",
    "malaise": "malaise",
    "muscle pain": "muscle_pain",
    "sunken eyes": "sunken_eyes",
    "internal itching": "internal_itching",
    "high fever": "high_fever",
    "peptic ulcer": ["abdominal_pain", "internal_itching", "passage_of_gases"],
    "phlegm": "phlegm",
    "mucoid sputum": "mucoid_sputum",
    "rusty sputum": "rusty_sputum",
    "blood in sputum": "blood_in_sputum",
    "chills": "chills",
    "shivering": "shivering",
    "palpitations": "palpitations",
    "burning urination": "burning_micturition",
    "nasal congestion": "congestion",
    "stiff neck": "stiff_neck",
    "neck pain": "stiff_neck",
    "foul smell of urine": "foul_smell_of_urine",
    "frequent urination": "polyuria",
    "blood in urine": "spotting_urination",
    "swollen joints": "swelling_joints",
    "swollen legs": "swollen_legs",
}

# Lexicon JSON keys / medical_term labels → archive dataset symptom columns.
LEXICON_KEY_TO_MODEL: dict[str, str | list[str]] = {
    "anorexia": "loss_of_appetite",
    "pruritus": "itching",
    "abdominal_pain": "abdominal_pain",
    "nausea": "nausea",
    "vomiting": "vomiting",
    "diarrhea": "diarrhoea",
    "headache": "headache",
    "dizziness": "dizziness",
    "cough": "cough",
    "fever": "mild_fever",
    "high_fever": "high_fever",
    "loss_of_appetite": "loss_of_appetite",
    "yellowing_of_eyes": "yellowing_of_eyes",
    "yellowish_skin": "yellowish_skin",
    "jaundice": ["yellowish_skin", "yellowing_of_eyes"],
    "itching": "itching",
    "fatigue": "fatigue",
    "weakness": "weakness_in_limbs",
    "breathlessness": "breathlessness",
    "chest_pain": "chest_pain",
    "skin_rash": "skin_rash",
    "back_pain": "back_pain",
    "joint_pain": "joint_pain",
    "runny_nose": "runny_nose",
    "throat_irritation": "throat_irritation",
    "chills": "chills",
    "acidity": "acidity",
    "indigestion": "indigestion",
    "ulcers_on_tongue": "ulcers_on_tongue",
    "passage_of_gases": "passage_of_gases",
    "internal_itching": "internal_itching",
    "dehydration": "dehydration",
    "sunken_eyes": "sunken_eyes",
    "dark_urine": "dark_urine",
    "yellow_urine": "yellow_urine",
    "weight_loss": "weight_loss",
    "lethargy": "lethargy",
    "malaise": "malaise",
    "muscle_pain": "muscle_pain",
    "heartburn": "acidity",
    "hyperacidity": "acidity",
    "bloating": "passage_of_gases",
    "phlegm": "phlegm",
    "mucoid_sputum": "mucoid_sputum",
    "rusty_sputum": "rusty_sputum",
    "blood_in_sputum": "blood_in_sputum",
    "burning_micturition": "burning_micturition",
    "congestion": "congestion",
    "stiff_neck": "stiff_neck",
    "shivering": "shivering",
    "palpitations": "palpitations",
    "foul_smell_of_urine": "foul_smell_of_urine",
    "polyuria": "polyuria",
    "spotting_urination": "spotting_urination",
    "swelling_joints": "swelling_joints",
    "swollen_legs": "swollen_legs",
    "bladder_discomfort": "bladder_discomfort",
    "continuous_feel_of_urine": "continuous_feel_of_urine",
    "toxic_look_(typhos)": "toxic_look_(typhos)",
    "loss_of_smell": "loss_of_smell",
    "continuous_sneezing": "continuous_sneezing",
    "redness_of_eyes": "redness_of_eyes",
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


def _map_lexicon_key(raw_key: str) -> list[str]:
    key = _normalize_symptom_key(raw_key)
    if not key:
        return []
    mapped = LEXICON_KEY_TO_MODEL.get(key)
    if mapped:
        if isinstance(mapped, list):
            return mapped
        return [mapped]
    spaced = key.replace("_", " ")
    for term in (key, spaced):
        for target in _alias_targets(term):
            if target:
                return [target]
    return [key]


_FUZZY_MIN_SCORE = 88
_FUZZY_MIN_WORD_SCORE = 82
_FUZZY_MIN_WEAK_WORD_SCORE = 55
_FUZZY_MIN_PHRASE_LEN = 7
_FUZZY_MAX_WINDOW = 8


@lru_cache(maxsize=1)
def _fuzzy_phrase_targets() -> tuple[tuple[str, tuple[str, ...]], ...]:
    """Symptom phrases worth fuzzy-matching against mistyped patient text."""
    meta = load_model_meta() or {}
    pairs: dict[str, tuple[str, ...]] = {}
    for key in meta.get("symptom_columns", []) or []:
        phrase = symptom_phrase(key)
        if len(phrase) >= _FUZZY_MIN_PHRASE_LEN:
            pairs[phrase] = (key,)
    for alias in SYMPTOM_ALIASES:
        if len(alias) >= _FUZZY_MIN_PHRASE_LEN:
            pairs.setdefault(alias, tuple(_alias_targets(alias)))
    # Hiligaynon phrasing too: a mistyped local phrase never reaches the English
    # translation, so "sakit sa kalanm" would otherwise lose muscle pain entirely.
    try:
        from analyzer import symptom_phrase_file_targets

        for phrase, key in symptom_phrase_file_targets().items():
            if len(phrase) >= _FUZZY_MIN_PHRASE_LEN:
                pairs.setdefault(phrase, (key,))
    except Exception:
        pass
    return tuple(pairs.items())


def _is_truncation(candidate: str, expected: str) -> bool:
    """True when the patient cut a word short ("sup" for "sputum", "app" for "appetite").

    Requires the same first letter and that every letter typed exists in the full
    word, which keeps abbreviations from matching an unrelated symptom.
    """
    if len(candidate) < 3 or len(candidate) >= len(expected):
        return False
    if candidate[0] != expected[0]:
        return False
    available = Counter(expected)
    return all(available[char] >= count for char, count in Counter(candidate).items())


@lru_cache(maxsize=1)
def _known_phrase_words() -> frozenset[str]:
    """Every word used by a symptom phrase, in either language."""
    words: set[str] = set()
    for phrase, _targets in _fuzzy_phrase_targets():
        words.update(re.findall(r"[a-z]+", phrase))
    return frozenset(words)


def _fuzzy_symptom_matches(text: str, already: set[str]) -> list[str]:
    """Recover symptom phrases from typos ("joint pia", "intenr itching")."""
    try:
        from rapidfuzz import fuzz
    except ImportError:
        return []

    words = re.findall(r"[a-z]+", text)
    if not words:
        return []

    windows: dict[int, list[str]] = {}
    for size in range(1, _FUZZY_MAX_WINDOW + 1):
        windows[size] = [" ".join(words[i : i + size]) for i in range(len(words) - size + 1)]

    def close_enough(candidate: str, phrase: str) -> bool:
        phrase_words = phrase.split()
        candidate_words = candidate.split()
        if len(phrase_words) == 1:
            if abs(len(candidate) - len(phrase)) > 3:
                return False
            return fuzz.ratio(candidate, phrase) >= _FUZZY_MIN_SCORE
        # Multi-word: every word must be close and at least one must match exactly,
        # so "back pain" never collapses into "belly pain".
        exact = 0
        weak = 0
        for cand_word, phrase_word in zip(candidate_words, phrase_words):
            if cand_word == phrase_word:
                exact += 1
                continue
            score = fuzz.ratio(cand_word, phrase_word)
            if score >= _FUZZY_MIN_WORD_SCORE:
                continue
            # Tolerate one badly mistyped token ("bol" for "blood", "eyse" for
            # "eyes"), but never a word that is itself a symptom term: "back" must
            # not pass for "belly", nor "high" for "mild".
            if not weak and cand_word not in _known_phrase_words() and cand_word[:2] == phrase_word[:2]:
                near_miss = (
                    score >= _FUZZY_MIN_WEAK_WORD_SCORE
                    and abs(len(cand_word) - len(phrase_word)) <= 3
                )
                if near_miss or _is_truncation(cand_word, phrase_word):
                    weak = 1
                    continue
            return False
        if weak:
            # A guessed token is only safe when every other word landed exactly.
            return exact == len(phrase_words) - 1
        # Whole-phrase length still guards the ordinary path, where several words
        # may each drift slightly.
        return exact > 0 and abs(len(candidate) - len(phrase)) <= 3

    present = set(words)
    matches: list[str] = []
    for phrase, targets in _fuzzy_phrase_targets():
        if all(target in already for target in targets):
            continue
        phrase_words = phrase.split()
        # Multi-word matching always requires at least one exact word, so a phrase
        # with no shared token cannot match and is skipped before scoring.
        if len(phrase_words) > 1 and not any(word in present for word in phrase_words):
            continue
        for candidate in windows.get(len(phrase_words), []):
            if close_enough(candidate, phrase):
                matches.extend(targets)
                break
    return matches


def extract_model_symptoms(
    english_text: str,
    extra_terms: list[str] | None = None,
    lexicon_keys: list[str] | None = None,
) -> list[str]:
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

    # Alias phrases spoken in the transcript itself (e.g. "weakness", "heartburn"),
    # not just the ones the lexicon reported.
    for alias in sorted(SYMPTOM_ALIASES, key=len, reverse=True):
        if len(alias) < 4:
            continue
        if re.search(r"(?<!\w)" + re.escape(alias) + r"(?!\w)", text):
            for target in _alias_targets(alias):
                add(target)

    for term in extra_terms or []:
        for target in _alias_targets(term):
            add(target)

    for raw_key in lexicon_keys or []:
        for target in _map_lexicon_key(raw_key):
            add(target)

    for target in _fuzzy_symptom_matches(text, seen):
        add(target)

    # Reported bleeding alongside abdominal pain and jaundice is a GI bleed, which is
    # what separates fulminant hepatitis from the milder hepatitis presentations.
    if "stomach_bleeding" not in seen and "bloody_stool" not in seen:
        bleeding_reported = re.search(r"(?<!\w)(?:bleeding|hemorrhage)(?!\w)", text)
        abdominal = seen & {"abdominal_pain", "stomach_pain", "belly_pain"}
        liver_signs = seen & {"yellowish_skin", "yellowing_of_eyes", "dark_urine"}
        if bleeding_reported and abdominal and liver_signs:
            add("stomach_bleeding")

    if "vomiting" in seen and "nausea" in vocabulary:
        add("nausea")

    if (
        "yellowish_skin" in seen
        and "dark_urine" in seen
        and ("itching" in seen or "mild_fever" in seen or "fatigue" in seen)
        and "high_fever" in vocabulary
    ):
        add("high_fever")

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


def refine_disease_predictions(
    model_symptoms: list[str],
    predictions: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """Resolve common liver/GI confusions when symptom sets overlap (decision support only)."""
    if len(predictions) < 2:
        return predictions

    sym = set(model_symptoms)
    has_vomiting = "vomiting" in sym
    has_itching = "itching" in sym
    has_fatigue = "fatigue" in sym or "lethargy" in sym or "malaise" in sym
    has_acidity = "acidity" in sym
    has_ulcers_tongue = "ulcers_on_tongue" in sym

    def bump(disease: str, amount: float) -> None:
        for item in predictions:
            if item.get("disease") == disease:
                item["confidence"] = min(99.9, float(item.get("confidence") or 0) + amount)

    def penalize(disease: str, amount: float) -> None:
        for item in predictions:
            if item.get("disease") == disease:
                item["confidence"] = max(0.0, float(item.get("confidence") or 0) - amount)

    descriptions = load_disease_descriptions()
    precautions = load_disease_precautions()

    def promote(disease: str, confidence: float) -> None:
        for item in predictions:
            if str(item.get("disease") or "") == disease:
                item["confidence"] = max(float(item.get("confidence") or 0), confidence)
                return
        predictions.append(
            {
                "disease": disease,
                "confidence": confidence,
                "description": descriptions.get(disease, ""),
                "precautions": precautions.get(disease, []),
            }
        )

    top_name = str(predictions[0].get("disease") or "")
    second_name = str(predictions[1].get("disease") or "")

    liver_like = {
        "Chronic cholestasis",
        "Hepatitis B",
        "hepatitis A",
        "Hepatitis C",
        "Hepatitis D",
        "Hepatitis E",
        "Jaundice",
        "Alcoholic hepatitis",
    }
    if top_name in liver_like and second_name in liver_like:
        if (
            "yellowish_skin" in sym
            and "yellowing_of_eyes" in sym
            and has_vomiting
            and "dark_urine" not in sym
        ):
            if "loss_of_appetite" in sym or "fatigue" in sym:
                bump("Hepatitis C", 22.0)
            else:
                bump("Chronic cholestasis", 14.0)
        elif "yellowish_skin" in sym and "dark_urine" in sym and has_itching:
            bump("Jaundice", 16.0)
        elif has_itching and has_fatigue and not has_vomiting:
            bump("Hepatitis B", 14.0)
            bump("hepatitis A", 8.0)
        elif "yellowish_skin" in sym and "dark_urine" in sym and not has_itching:
            bump("Hepatitis B", 10.0)

    if top_name == "Typhoid":
        if "yellowish_skin" in sym and "yellowing_of_eyes" in sym:
            bump("Chronic cholestasis", 24.0)

    if top_name == "hepatitis A":
        if "yellowish_skin" in sym and "dark_urine" in sym and has_itching:
            bump("Jaundice", 22.0)

    if top_name == "Chronic cholestasis":
        if (
            "yellowish_skin" in sym
            and "dark_urine" in sym
            and has_itching
            and "yellowing_of_eyes" not in sym
        ):
            bump("Jaundice", 28.0)
        if "internal_itching" in sym and "yellowish_skin" not in sym and "yellowing_of_eyes" not in sym:
            bump("Peptic ulcer diseae", 22.0)
            penalize("Chronic cholestasis", 18.0)

    if top_name == "Chronic cholestasis" and second_name == "Hepatitis C":
        if "yellowing_of_eyes" in sym and "yellowish_skin" in sym:
            promote("Hepatitis C", 94.0)
            penalize("Chronic cholestasis", 40.0)

    if top_name == "Chronic cholestasis":
        hep_c_overlap = sym & {
            "yellowing_of_eyes",
            "yellowish_skin",
            "fatigue",
            "nausea",
            "loss_of_appetite",
        }
        if len(hep_c_overlap) >= 4 and "yellowing_of_eyes" in sym and "dark_urine" not in sym:
            promote("Hepatitis C", 92.0)
            penalize("Chronic cholestasis", 35.0)

    jaundice_pattern = (
        "yellowish_skin" in sym
        and "dark_urine" in sym
        and has_itching
        and "yellowing_of_eyes" not in sym
        and has_vomiting
    )
    if jaundice_pattern:
        penalize("Chronic cholestasis", 40.0)
        promote("Jaundice", 92.0)

    if top_name == "Heart attack" and second_name == "GERD":
        if has_acidity or has_ulcers_tongue:
            bump("GERD", 15.0)

    if top_name == "GERD":
        if "yellowing_of_eyes" in sym or "yellowish_skin" in sym:
            bump("Chronic cholestasis", 18.0)
            penalize("GERD", 15.0)

    if top_name == "hepatitis A":
        cold_like = (
            ("runny_nose" in sym or "continuous_sneezing" in sym or "congestion" in sym)
            and "cough" in sym
            and "yellowish_skin" not in sym
            and "yellowing_of_eyes" not in sym
        )
        if cold_like:
            bump("Common Cold", 28.0)
            penalize("hepatitis A", 22.0)

    if top_name == "Peptic ulcer diseae" and second_name == "GERD":
        if has_acidity and has_ulcers_tongue:
            bump("GERD", 8.0)

    has_phlegm = "phlegm" in sym or "mucoid_sputum" in sym or "rusty_sputum" in sym
    has_cough = "cough" in sym
    if top_name == "Common Cold" and second_name == "Pneumonia":
        if has_phlegm and has_cough:
            bump("Pneumonia", 12.0)
    if top_name == "Bronchitis" and second_name == "Pneumonia":
        if "rusty_sputum" in sym or "blood_in_sputum" in sym:
            bump("Pneumonia", 10.0)

    if top_name == "Bronchial Asthma":
        pneumonia_like = (
            "breathlessness" in sym
            and "chills" in sym
            and "chest_pain" in sym
            and ("phlegm" in sym or "mucoid_sputum" in sym or "rusty_sputum" in sym)
        )
        if pneumonia_like:
            bump("Pneumonia", 24.0)

    if top_name == "Allergy":
        cold_like = sym & {
            "continuous_sneezing",
            "cough",
            "headache",
            "chills",
            "malaise",
            "phlegm",
            "loss_of_smell",
        }
        if len(cold_like) >= 5:
            bump("Common Cold", 28.0)

    if top_name == "Arthritis":
        thyroid_like = sym & {
            "excessive_hunger",
            "fast_heart_rate",
            "irritability",
            "restlessness",
            "sweating",
            "diarrhoea",
        }
        if len(thyroid_like) >= 4 and "excessive_hunger" in sym and "fast_heart_rate" in sym:
            bump("Hyperthyroidism", 30.0)

    if top_name == "Heart attack":
        hypo_like = sym & {
            "anxiety",
            "blurred_and_distorted_vision",
            "excessive_hunger",
            "slurred_speech",
            "sweating",
            "irritability",
        }
        if len(hypo_like) >= 4 and "excessive_hunger" in sym:
            bump("Hypoglycemia", 32.0)

    # BPPV needs vertigo/balance findings; without them, headache + vomiting +
    # focal weakness or altered sensorium points to a cerebrovascular cause.
    vertigo_signs = {
        "spinning_movements",
        "loss_of_balance",
        "unsteadiness",
        "dizziness",
        "lack_of_concentration",
    }
    if top_name == "(vertigo) Paroymsal  Positional Vertigo" and not (sym & vertigo_signs):
        stroke_signs = (
            "altered_sensorium" in sym
            or "weakness_of_one_body_side" in sym
            or ("headache" in sym and has_vomiting)
        )
        if stroke_signs:
            penalize("(vertigo) Paroymsal  Positional Vertigo", 45.0)
            promote("Paralysis (brain hemorrhage)", 88.0)

    # Focal weakness + vomiting without jaundice is stroke/paralysis, not hepatitis.
    if "weakness_of_one_body_side" in sym and has_vomiting:
        liver_signs = sym & {
            "yellowish_skin",
            "yellowing_of_eyes",
            "dark_urine",
            "stomach_bleeding",
        }
        if not liver_signs:
            penalize("Hepatitis E", 50.0)
            penalize("Hepatitis D", 30.0)
            promote("Paralysis (brain hemorrhage)", 90.0)

    if top_name == "Typhoid" and second_name == "Gastroenteritis":
        if "toxic_look_(typhos)" in sym or "high_fever" in sym:
            bump("Typhoid", 10.0)

    if top_name == "Urinary tract infection" and second_name == "Hepatitis E":
        if "burning_micturition" in sym or "bladder_discomfort" in sym:
            bump("Urinary tract infection", 12.0)

    predictions.sort(key=lambda item: float(item.get("confidence") or 0), reverse=True)
    return predictions


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
    lexicon_keys: list[str] | None = None,
) -> dict[str, Any]:
    """Run ML disease prediction + triage on translated transcript text."""
    model_symptoms = extract_model_symptoms(
        english_transcript,
        basic_symptoms,
        lexicon_keys=lexicon_keys,
    )
    predictions = predict_diseases(model_symptoms, top_k=max(top_k, 5))
    predictions = refine_disease_predictions(model_symptoms, predictions)
    predictions = predictions[:top_k]
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
