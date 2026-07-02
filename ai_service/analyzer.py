"""Transcript analysis: Hiligaynon translation and medical keyword extraction."""

from __future__ import annotations

import re
from typing import Any

HILIGAYNON_DICTIONARY = {
    "sakit ulo": "headache",
    "labad ulo": "headache",
    "ginahilanat": "fever",
    "hilanat": "fever",
    "ubo": "cough",
    "sip-on": "runny nose",
    "sip on": "runny nose",
    "sakit dughan": "chest pain",
    "hapdi dughan": "chest pain",
    "ginabudlayan ginhawa": "difficulty breathing",
    "budlay ginhawa": "difficulty breathing",
    "kalipong": "dizziness",
    "nagalipong": "dizziness",
    "suka": "vomiting",
    "nagsuka": "vomiting",
    "kalibanga": "diarrhea",
    "sakit tiyan": "stomach pain",
    "panakit tiyan": "stomach pain",
    "sakit tutunlan": "sore throat",
    "hubag": "swelling",
    "katol": "itching",
    "rashes": "rash",
    "kakapoy": "fatigue",
    "ginakapoy": "fatigue",
}

SYMPTOM_TERMS = [
    "fever", "cough", "headache", "chest pain", "difficulty breathing",
    "shortness of breath", "dizziness", "vomiting", "diarrhea",
    "stomach pain", "abdominal pain", "sore throat", "rash", "swelling",
    "itching", "fatigue", "body pain", "back pain", "nausea",
]

MEDICINE_TERMS = [
    "paracetamol", "biogesic", "amoxicillin", "ibuprofen", "mefenamic",
    "cetirizine", "loratadine", "salbutamol", "metformin", "amlodipine",
    "losartan", "omeprazole", "aspirin", "insulin", "atorvastatin",
    "simvastatin", "enalapril", "captopril", "hydrochlorothiazide",
    "prednisone", "dexamethasone", "azithromycin", "ciprofloxacin",
    "doxycycline", "vitamin c", "multivitamin",
]

ALLERGY_TERMS = [
    "penicillin", "amoxicillin", "augmentin", "sulfa", "sulfamethoxazole",
    "sulfonamide", "aspirin", "ibuprofen", "naproxen", "codeine", "morphine",
    "latex", "shellfish", "seafood", "shrimp", "crab", "fish", "peanut",
    "peanuts", "tree nut", "almond", "walnut", "cashew", "egg", "eggs",
    "milk", "dairy", "lactose", "soy", "wheat", "gluten", "corn", "banana",
    "kiwi", "celery", "mustard", "sesame", "pollen", "dust mite", "mold",
    "pet dander", "iodine", "contrast dye", "nickel", "coconut", "bee sting",
]

URGENT_TERMS = [
    "chest pain", "difficulty breathing", "shortness of breath",
    "severe bleeding", "unconscious", "seizure",
]


def replace_phrase(text: str, phrase: str, replacement: str) -> str:
    pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", re.IGNORECASE)
    return pattern.sub(replacement, text)


def translate_hiligaynon(text: str) -> str:
    try:
        from dictionary_loader import local_to_english_map

        csv_map = local_to_english_map()
    except Exception:
        csv_map = {}

    merged: dict[str, str] = {**HILIGAYNON_DICTIONARY, **csv_map}
    translated = text.lower()
    for phrase, replacement in sorted(merged.items(), key=lambda item: len(item[0]), reverse=True):
        translated = replace_phrase(translated, phrase, replacement)
    return translated


def find_terms(text: str, terms: list[str]) -> list[str]:
    found: list[str] = []
    for term in terms:
        if re.search(r"(?<!\w)" + re.escape(term) + r"(?!\w)", text, re.IGNORECASE):
            found.append(term)
    return sorted(set(found), key=found.index)


_SKIP_ITEMS = frozenset(
    {"none", "n/a", "na", "wala", "none known", "no known allergies", "walang allergy"}
)


def parse_freeform_items(text: str) -> list[str]:
    if not text.strip():
        return []
    normalized = re.sub(
        r"\s+(?:and|og|kag|ka|ug)\s+",
        ", ",
        text,
        flags=re.IGNORECASE,
    )
    items: list[str] = []
    for part in re.split(r"[,;\n]+", normalized):
        cleaned = re.sub(r"\s+", " ", part).strip(" .-")
        if not cleaned:
            continue
        if cleaned.lower() in _SKIP_ITEMS:
            continue
        items.append(cleaned)
    return items


def spacy_noun_phrases(text: str, limit: int = 10) -> list[str]:
    if not text.strip():
        return []
    try:
        from transcriber import get_spacy_nlp

        doc = get_spacy_nlp()(text)
        return [chunk.text.strip() for chunk in doc.noun_chunks if chunk.text.strip()][:limit]
    except Exception:
        return []


def analyze_transcript(transcript: str, model_status: dict[str, str] | None = None) -> dict[str, Any]:
    from hiligaynon_symptom_matcher import recognize_symptoms

    recognition = recognize_symptoms(transcript)
    english = translate_hiligaynon(transcript)
    lexicon_symptoms = recognition.get("english_symptoms") or []
    symptoms = find_terms(english, SYMPTOM_TERMS)
    merged: list[str] = []
    seen_sym: set[str] = set()
    for s in lexicon_symptoms + symptoms:
        key = (s or "").strip().lower()
        if key and key not in seen_sym:
            seen_sym.add(key)
            merged.append(s)
    symptoms = merged
    medicines = find_terms(english, MEDICINE_TERMS)
    urgent_flags = find_terms(english, URGENT_TERMS)

    try:
        from transcriber import get_spacy_nlp

        doc = get_spacy_nlp()(english)
        noun_phrases = [chunk.text for chunk in doc.noun_chunks][:12]
    except Exception:
        noun_phrases = []

    summary = "Possible symptoms: " + (", ".join(symptoms) if symptoms else "none detected") + "."
    if medicines:
        summary += " Mentioned medicines: " + ", ".join(medicines) + "."
    if urgent_flags:
        summary += " Urgent cues detected: " + ", ".join(urgent_flags) + "."

    ml: dict[str, Any] = {}
    try:
        from disease_predictor import enrich_transcript_analysis

        ml = enrich_transcript_analysis(english, symptoms, urgent_flags)
        if ml.get("ml_summary"):
            summary += " " + ml["ml_summary"]
    except Exception:
        ml = {"ml_available": False}

    engine = "python-hybrid-ml" if ml.get("ml_available") else "python-dictionary-nlp"

    result: dict[str, Any] = {
        "hiligaynon_transcript": transcript,
        "english_transcript": english,
        "symptoms": symptoms,
        "symptom_detections": recognition.get("detections") or [],
        "symptom_recognition": {
            "normalized_text": recognition.get("normalized_text"),
            "cleaned_text": recognition.get("cleaned_text"),
            "fuzzy_threshold": recognition.get("fuzzy_threshold"),
            "detection_count": recognition.get("detection_count"),
            "lexicon": recognition.get("lexicon"),
        },
        "medicines": medicines,
        "urgent_flags": urgent_flags,
        "summary": summary,
        "engine": engine,
        "noun_phrases": noun_phrases,
        **ml,
    }
    if model_status is not None:
        result["model_status"] = model_status
    return result


def analyze_medical_profile(
    allergies: str,
    medications: str,
    model_status: dict[str, str] | None = None,
) -> dict[str, Any]:
    from invalid_entry_detector import detect as detect_invalid_entries
    from preprocess import preprocess_profile, translate_keywords
    from profile_validation import run_profile_validation
    from validation_workflow import build_validation_summary

    allergies = allergies.strip()
    medications = medications.strip()

    profile = run_profile_validation(allergies, medications)
    preprocessing = profile["preprocessing"]
    translation = profile["translation"]
    fuzzy_matching = profile["fuzzy_matching"]
    dataset_validation = profile["dataset_validation"]
    invalid_entry_detection = detect_invalid_entries(dataset_validation)
    term_results = profile["term_results"]

    allergy_work = preprocessing["allergies"]["keywords_text"] or preprocessing["allergies"]["cleaned"]
    condition_work = preprocessing["conditions"]["keywords_text"] or preprocessing["conditions"]["cleaned"]

    english_allergies = (translation["allergies"].get("english_text") or "").strip()
    english_medications = (translation["conditions"].get("english_text") or "").strip()
    if not english_allergies and allergy_work:
        english_allergies = translate_hiligaynon(allergy_work)
    if not english_medications and condition_work:
        english_medications = translate_hiligaynon(condition_work)

    known_allergies = find_terms(english_allergies, ALLERGY_TERMS)
    known_medicines = find_terms(english_medications, MEDICINE_TERMS)

    parsed_allergies = parse_freeform_items(allergy_work or allergies)
    parsed_medications = parse_freeform_items(condition_work or medications)

    combined_english = " ".join(
        part for part in (english_allergies, english_medications) if part
    ).strip()
    noun_phrases: list[str] = []

    validation_summary = build_validation_summary(term_results, invalid_entry_detection)

    result: dict[str, Any] = {
        "allergies_text": allergies,
        "medications_text": medications,
        "english_allergies": english_allergies,
        "english_medications": english_medications,
        "known_allergies": known_allergies,
        "known_medicines": known_medicines,
        "parsed_allergies": parsed_allergies,
        "parsed_medications": parsed_medications,
        "noun_phrases": noun_phrases,
        "summary": validation_summary,
        "engine": "python-medical-profile-nlp",
        "preprocessing": preprocessing,
        "translation": translation,
        "fuzzy_matching": fuzzy_matching,
        "dataset_validation": dataset_validation,
        "invalid_entry_detection": invalid_entry_detection,
        "registration": dataset_validation.get("registration") or {},
        "registration_eligible": bool(dataset_validation.get("registration_eligible")),
        "submission_rejected": bool(invalid_entry_detection.get("submission_rejected")),
        "submission_accepted": bool(invalid_entry_detection.get("submission_accepted")),
        "save_allowed": bool(invalid_entry_detection.get("save_allowed")),
        "term_results": term_results,
        "matched_records": profile.get("matched_records") or [],
        "conditions_recognition": profile.get("conditions_recognition") or {},
        "allergies_recognition": profile.get("allergies_recognition") or {},
        "workflow": {
            "version": "1.1",
            "steps": [
                "preprocess",
                "translate_to_english",
                "extract_medical_terms",
                "fuzzy_match_datasets",
                "dataset_validate",
                "highlight_valid_terms",
            ],
            "policy": (
                "Hiligaynon/Ilonggo terms are translated via medical_dictionary.csv before matching. "
                "Only conditions, symptoms, and allergies found in official datasets are highlighted as valid."
            ),
        },
        "translated_keywords": {
            "allergies": translate_keywords(preprocessing["allergies"]["keywords"]),
            "conditions": translate_keywords(preprocessing["conditions"]["keywords"]),
        },
    }
    if model_status is not None:
        result["model_status"] = model_status
    return result
