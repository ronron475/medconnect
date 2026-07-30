"""Transcript analysis: Hiligaynon translation and medical keyword extraction."""

from __future__ import annotations

import json
import os
import re
from collections import Counter
from pathlib import Path
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
    "ginakatol": "itching",
    "ginahilo": "dizziness",
    "nahilo": "dizziness",
    "wala gana magkaon": "loss of appetite",
    "wala gana kaon": "loss of appetite",
    "wala gana": "loss of appetite",
    "dulom mata": "yellowing of eyes",
    "dulom sang mata": "yellowing of eyes",
    "gapula mata": "yellowing of eyes",
    "dilaw panit": "yellowish skin",
    "dilaw nga panit": "yellowish skin",
    "dilaw lawas": "yellowish skin",
    "yellowish skin": "yellowish skin",
    "yellowing of eyes": "yellowing of eyes",
    "loss of appetite": "loss of appetite",
    "nagasuka": "vomiting",
    "ginabaldom": "stomach pain",
    "kabog": "heartburn",
    "ginakabog": "heartburn",
    "asido": "hyperacidity",
    "singaw": "mouth ulcer",
    "singaw sa bibig": "mouth ulcer",
    "gas tiyan": "passing gas",
    "ginapanuhot": "bloating",
    "itom ihi": "dark urine",
    "dulom ihi": "dark urine",
    "dulom nga ihi": "dark urine",
    "dilaw ihi": "yellow urine",
    # Canonical dataset symptom wording the bulk CSV would otherwise degrade
    # ("high fever" -> "fever", "weakness in limbs" -> "fatigue in limbs").
    "high fever": "high fever",
    "mild fever": "mild fever",
    "muscle weakness": "muscle weakness",
    "irregular sugar level": "irregular sugar level",
    "palpitations": "palpitations",
    "pus filled pimples": "pus filled pimples",
    "weakness in limbs": "weakness in limbs",
    "weakness of one body side": "weakness of one body side",
    "stomach bleeding": "stomach bleeding",
    "nagdugo tiyan": "stomach bleeding",
    "nagadugo tiyan": "stomach bleeding",
    "laab ulo": "headache",
    "laab sa ulo": "headache",
    "sakit lutahan": "joint pain",
    "sakit sa lutahan": "joint pain",
    "hapdi lutahan": "joint pain",
    "ginasakit mga lutahan": "joint pain",
    "nagapanipis lawas": "weight loss",
    "natulon mata": "sunken eyes",
    "grabeng hilanat": "high fever",
    "mataas nga hilanat": "high fever",
    "sakit kalamnan": "muscle pain",
    "hyperacidity": "hyperacidity",
    "heartburn": "heartburn",
    "indigestion": "indigestion",
    "plema": "phlegm",
    "may plema": "phlegm",
    "madamo plema": "phlegm",
    "hapdi mag-ihi": "burning urination",
    "hapdi mag ihi": "burning urination",
    "hapdi pag-ihi": "burning urination",
    "barado ilong": "nasal congestion",
    "sipaon": "nasal congestion",
    "ginapanas-an": "chills",
    "mabinugnaw": "chills",
    "nagakurog": "shivering",
    "ginakurog": "shivering",
    "mabilis tagipusuon": "palpitations",
    "hubag lutahan": "swollen joints",
    "hubag tiil": "swollen legs",
    "hubag paa": "swollen legs",
    "wala gana magkaon": "loss of appetite",
    "gakadula gana kaon": "loss of appetite",
    "ginauhaw": "dehydration",
    "kulang tubig": "dehydration",
    "ginabalatian": "malaise",
    "hindi maayo lawas": "malaise",
    "luya gid": "lethargy",
    "ginatulog tulog": "lethargy",
    "ginakati sulod": "internal itching",
    "kati sulod tiyan": "internal itching",
    "panuhot": "passing gas",
    "ginahutok tiyan": "bloating",
    "indi ma digest": "indigestion",
    "ginabalda tiyan": "indigestion",
    "rigido liog": "stiff neck",
    "sakit liog": "neck pain",
    "mait nga ihi": "foul smell of urine",
    "dugay mag-ihi": "frequent urination",
    "dugay mag ihi": "frequent urination",
    "may dugo sa ihi": "blood in urine",
    "plema may dugo": "blood in sputum",
    "malagkit plema": "mucoid sputum",
}

SYMPTOM_TERMS = [
    "fever", "cough", "headache", "chest pain", "difficulty breathing",
    "shortness of breath", "dizziness", "vomiting", "diarrhea",
    "stomach pain", "abdominal pain", "sore throat", "rash", "swelling",
    "itching", "fatigue", "body pain", "back pain",     "nausea",
    "loss of appetite",
    "yellowish skin",
    "yellowing of eyes",
    "jaundice",
    "itchiness",
    "heartburn",
    "hyperacidity",
    "indigestion",
    "dark urine",
    "yellow urine",
    "weight loss",
    "sunken eyes",
    "dehydration",
    "muscle pain",
    "lethargy",
    "malaise",
    "passing gas",
    "mouth ulcer",
    "phlegm",
    "chills",
    "shivering",
    "palpitations",
    "burning urination",
    "nasal congestion",
    "blood in sputum",
    "blood in urine",
    "frequent urination",
    "neck pain",
    "stiff neck",
    "foul smell of urine",
    "mucoid sputum",
    "internal itching",
    "bloating",
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

# Registration / chief-complaint field labels (strip before symptom matching).
_INTAKE_LABEL_PATTERNS: list[re.Pattern[str]] = [
    re.compile(r"chief complaint\s*:?\s*", re.I),
    re.compile(r"(?<!\w)cc\s*:?\s*", re.I),
    re.compile(r"reason for (?:visit|consult)\s*:?\s*", re.I),
    re.compile(r"presenting complaint\s*:?\s*", re.I),
    re.compile(r"main concern\s*:?\s*", re.I),
    re.compile(r"primary complaint\s*:?\s*", re.I),
    re.compile(r"health concern\s*:?\s*", re.I),
    re.compile(r"intake form\s*:?\s*", re.I),
    re.compile(r"telehealth\s*:?\s*", re.I),
    re.compile(r"registration\s*:?\s*", re.I),
    re.compile(r"medconnect\s*:?\s*", re.I),
    re.compile(r"online (?:intake|consult|form)\s*:?\s*", re.I),
    re.compile(r"virtual consult\s*:?\s*", re.I),
    re.compile(r"e-consult\s*:?\s*", re.I),
    re.compile(r"patient portal\s*:?\s*", re.I),
    re.compile(r"symptom checker\s*:?\s*", re.I),
    re.compile(r"barangay health\s*:?\s*", re.I),
    re.compile(r"rhu\s*:?\s*", re.I),
    re.compile(r"health center\s*:?\s*", re.I),
    re.compile(r"subjective\s*:?\s*", re.I),
    re.compile(r"hpi\s*:?\s*", re.I),
    re.compile(r"patient states\s+", re.I),
    re.compile(r"reports\s+", re.I),
    re.compile(r"patient typed\s+", re.I),
    re.compile(r"please enter chief complaint\s*:?\s*", re.I),
    re.compile(r"describe concern\s*:?\s*", re.I),
    re.compile(r"unang reklamo\s*:?\s*", re.I),
    re.compile(r"reklamo ko\s*:?\s*", re.I),
    re.compile(r"rason sang pag consult\s*:?\s*", re.I),
    re.compile(r"rason ko nga nag consult\s*:?\s*", re.I),
    re.compile(r"chief complaint ko\s*:?\s*", re.I),
    re.compile(r"cc ko\s*:?\s*", re.I),
    re.compile(r"form chief complaint\s*:?\s*", re.I),
    re.compile(r"field chief complaint\s*:?\s*", re.I),
    re.compile(r"ginatype cc\s*:?\s*", re.I),
    re.compile(r"type ko cc\s*:?\s*", re.I),
    re.compile(r"nagsugid ang pasyente\s*:?\s*", re.I),
    re.compile(r"pasyente nagsugid\s*:?\s*", re.I),
]


def normalize_patient_input(text: str) -> str:
    """Remove intake labels so chief-complaint forms still match symptoms."""
    cleaned = (text or "").strip()
    if not cleaned:
        return ""
    for pattern in _INTAKE_LABEL_PATTERNS:
        cleaned = pattern.sub("", cleaned)
    cleaned = re.sub(r"\s+", " ", cleaned).strip(" .:-")
    return cleaned or (text or "").strip()


def replace_phrase(text: str, phrase: str, replacement: str) -> str:
    pattern = re.compile(r"(?<!\w)" + re.escape(phrase) + r"(?!\w)", re.IGNORECASE)
    return pattern.sub(replacement, text)


_TRANSLATION_PAIRS: tuple[tuple[str, str], ...] | None = None
_LOCAL_PHRASE_TOKENS: tuple[tuple[tuple[str, ...], str], ...] | None = None
_LOCAL_PHRASE_WORDS: frozenset[str] | None = None

SYMPTOM_PHRASE_FILE = (
    Path(__file__).resolve().parents[1] / "data" / "nlp" / "hiligaynon_symptom_phrases.json"
)
SYMPTOM_VARIANT_FILE = (
    Path(__file__).resolve().parents[1] / "data" / "nlp" / "hiligaynon_symptom_variants.json"
)


def _load_phrase_map() -> dict[str, list[str]]:
    """Curated phrases plus generated spelling/prefix variants, keyed by symptom."""
    combined: dict[str, list[str]] = {}
    for path, field in (
        (SYMPTOM_PHRASE_FILE, "phrases"),
        (SYMPTOM_VARIANT_FILE, "variants"),
    ):
        if not path.is_file():
            continue
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            continue
        for key, phrases in (payload.get(field) or {}).items():
            combined.setdefault(key, []).extend(phrases)
    return combined


def symptom_phrase_translations() -> dict[str, str]:
    """Hiligaynon symptom phrasing shared with the corpus generator.

    Maps each curated phrase to the dataset's own wording for that symptom, so a
    rendered transcript translates back to the exact key it was generated from.
    """
    mapping: dict[str, str] = {}
    for key, phrases in _load_phrase_map().items():
        # "toxic_look_(typhos)" carries punctuation the alias table resolves instead.
        english = key.replace("_", " ").split(" (")[0]
        for phrase in phrases:
            mapping[phrase.strip().lower()] = english
    return mapping


def symptom_phrase_file_targets() -> dict[str, str]:
    """Curated Hiligaynon phrase -> dataset symptom key (for fuzzy typo recovery)."""
    targets: dict[str, str] = {}
    for key, phrases in _load_phrase_map().items():
        for phrase in phrases:
            targets[phrase.strip().lower()] = key
    return targets


_LOCAL_FUZZY_MIN_WORD_SCORE = 70
_LOCAL_FUZZY_MIN_WEAK_SCORE = 45


def _is_local_truncation(candidate: str, expected: str) -> bool:
    """"kla" for "kalamnan", "mna" for "manipis" — same first letter, letters available."""
    if len(candidate) < 3 or len(candidate) >= len(expected):
        return False
    if candidate[0] != expected[0]:
        return False
    available = Counter(expected)
    return all(available[char] >= count for char, count in Counter(candidate).items())


def _local_phrase_tokens() -> tuple[tuple[tuple[str, ...], str], ...]:
    """Tokenised curated Hiligaynon phrases paired with their symptom key."""
    global _LOCAL_PHRASE_TOKENS
    if _LOCAL_PHRASE_TOKENS is not None:
        return _LOCAL_PHRASE_TOKENS
    entries: list[tuple[tuple[str, ...], str]] = []
    for phrase, key in symptom_phrase_file_targets().items():
        tokens = tuple(re.findall(r"[a-z]+", phrase))
        if len(tokens) >= 2:
            entries.append((tokens, key))
    _LOCAL_PHRASE_TOKENS = tuple(entries)
    return _LOCAL_PHRASE_TOKENS


def _local_phrase_words() -> frozenset[str]:
    global _LOCAL_PHRASE_WORDS
    if _LOCAL_PHRASE_WORDS is None:
        words: set[str] = set()
        for tokens, _key in _local_phrase_tokens():
            words.update(tokens)
        _LOCAL_PHRASE_WORDS = frozenset(words)
    return _LOCAL_PHRASE_WORDS


def local_phrase_symptom_matches(raw_text: str) -> list[str]:
    """Symptom keys recoverable from a mistyped Hiligaynon phrase.

    Requires every token but one to match exactly, and the odd token must not itself
    be a symptom word, so a real phrase is never rewritten into a different symptom.
    """
    try:
        from rapidfuzz import fuzz
    except ImportError:
        return []

    words = re.findall(r"[a-z]+", (raw_text or "").lower())
    if not words:
        return []

    # Both match paths below need at least one exactly-matching token, so phrases
    # sharing nothing with the text can be dropped before the window scan. With a
    # few thousand phrases this prune is what keeps the pass cheap.
    present = set(words)
    found: list[str] = []
    for tokens, key in _local_phrase_tokens():
        size = len(tokens)
        if size > len(words) or key in found:
            continue
        # At most one token may deviate, so a phrase needs size-1 exact hits to
        # stand a chance. With a few thousand phrases this both prunes the scan and
        # stops loose partial matches from inventing symptoms.
        exact_possible = sum(1 for token in tokens if token in present)
        if exact_possible < size - 1:
            continue
        for start in range(len(words) - size + 1):
            window = words[start : start + size]
            exact = 0
            weak = 0
            ok = True
            for candidate, expected in zip(window, tokens):
                if candidate == expected:
                    exact += 1
                    continue
                if weak or candidate in _local_phrase_words() or candidate[:1] != expected[:1]:
                    ok = False
                    break
                score = fuzz.ratio(candidate, expected)
                near_miss = score >= _LOCAL_FUZZY_MIN_WORD_SCORE or (
                    score >= _LOCAL_FUZZY_MIN_WEAK_SCORE
                    and abs(len(candidate) - len(expected)) <= 3
                )
                if near_miss or _is_local_truncation(candidate, expected):
                    weak = 1
                    continue
                ok = False
                break
            # Every token but one must land exactly; anything looser starts
            # inventing symptoms once the phrase table has thousands of entries.
            if ok and exact >= size - 1:
                found.append(key)
                break
    return found


def _translation_pairs() -> tuple[tuple[str, str], ...]:
    """Longest-first Hiligaynon/local → English phrase replacements (cached)."""
    global _TRANSLATION_PAIRS
    if _TRANSLATION_PAIRS is not None:
        return _TRANSLATION_PAIRS
    try:
        from dictionary_loader import local_to_english_map

        csv_map = local_to_english_map()
    except Exception:
        csv_map = {}
    # Curated phrases win over the bulk CSV, which contains conflicting senses
    # (e.g. "gapula mata" as conjunctivitis rather than jaundice).
    merged: dict[str, str] = {
        **csv_map,
        **HILIGAYNON_DICTIONARY,
        **symptom_phrase_translations(),
    }
    ordered = sorted(merged.items(), key=lambda item: len(item[0]), reverse=True)
    _TRANSLATION_PAIRS = tuple(ordered)
    return _TRANSLATION_PAIRS


def translate_hiligaynon(text: str) -> str:
    """Longest-match phrase translation.

    Each match is parked behind a placeholder so a later, shorter rule cannot rewrite
    inside it — otherwise "muscle weakness" becomes "muscle fatigue" via the bare
    "weakness" rule, losing the symptom that identifies the condition.
    """
    translated = text.lower()
    parked: list[str] = []
    for phrase, replacement in _translation_pairs():
        if phrase not in translated:
            continue
        token = f"\x00{len(parked)}\x00"
        replaced = replace_phrase(translated, phrase, token)
        if replaced != translated:
            parked.append(replacement)
            translated = replaced
    for index, replacement in enumerate(parked):
        translated = translated.replace(f"\x00{index}\x00", replacement)
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


def prepare_transcript_ml_inputs(
    transcript: str,
    recognition: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """Stable symptom keys for disease ML (lexicon JSON only, no fuzzy CSV expansion)."""
    from hiligaynon_symptom_matcher import recognize_symptoms

    if recognition is None:
        working = normalize_patient_input(transcript)
        recognition = recognize_symptoms(working, include_fuzzy=False, lexicon_only=True)
    working = normalize_patient_input(transcript)
    english = translate_hiligaynon(working)
    lexicon_symptoms = recognition.get("english_symptoms") or []
    symptoms = find_terms(english, SYMPTOM_TERMS)
    merged: list[str] = []
    seen_sym: set[str] = set()
    for s in lexicon_symptoms + symptoms:
        key = (s or "").strip().lower()
        if key and key not in seen_sym:
            seen_sym.add(key)
            merged.append(s)

    lexicon_keys: list[str] = []
    for det in recognition.get("detections") or []:
        sk = (det.get("symptom_key") or "").strip()
        if sk:
            lexicon_keys.append(sk)
        mt = (det.get("medical_term") or "").strip()
        if mt:
            lexicon_keys.append(mt)
        en = (det.get("english_translation") or "").strip()
        if en:
            key = en.lower()
            if key and key not in seen_sym:
                seen_sym.add(key)
                merged.append(en)

    urgent_flags = find_terms(english, URGENT_TERMS)
    hil = (transcript or "").lower()
    if "mataas nga hilanat" in hil or "grabeng hilanat" in hil:
        lexicon_keys.append("high_fever")
    if "barado ilong" in hil or "sipaon" in hil:
        lexicon_keys.append("congestion")
    if "wala ko maka-amim" in hil or "wala ko maka-inom" in hil:
        lexicon_keys.append("loss_of_smell")
    if "continuous sneezing" in english.lower() or "continuous_sneezing" in hil:
        lexicon_keys.append("continuous_sneezing")
    # Patients abbreviate/mistype "altered sensorium" (altered sne, alteer sensorium, atl sensorium).
    if re.search(r"\b(?:alt\w*|atl)\s+(?:sens\w*|sne\w*|sensoir\w*)", hil):
        lexicon_keys.append("altered_sensorium")
    # Truncated "panghuna-huna" ("panghnu") still signals altered sensorium.
    if re.search(r"\bindi\s+maathag\s+ang\s+pangh\w*", hil):
        lexicon_keys.append("altered_sensorium")
    if re.search(r"\bdaw\s+indi\s+maathag\s+ang\s+pangh\w*", hil):
        lexicon_keys.append("altered_sensorium")
    if re.search(r"\bcongse\b", hil) or re.search(r"\bcongse\b", english):
        lexicon_keys.append("congestion")
    if re.search(r"\bpalip\b", english) or re.search(r"\bpalip\b", hil):
        lexicon_keys.append("palpitations")
    if re.search(r"\bcoam\b", english):
        lexicon_keys.append("coma")
    elif re.search(r"\bcmo\b", english) and not re.search(
        r"\b(?:current|patient\s+chief|field\s+chief|chief)\s+cmo\b",
        english,
        re.I,
    ):
        lexicon_keys.append("coma")
    if re.search(r"\banb\s+menstruation\b", english) or re.search(r"\babnormal\s+mne\b", english):
        lexicon_keys.append("abnormal_menstruation")
    if re.search(r"daw\s+ka\w{2,4}\s+nga\s+plema", hil):
        lexicon_keys.append("rusty_sputum")
    # A mistyped local phrase never survives translation ("sakit sa kalanm" for
    # kalamnan), so recover it from the raw text before it is torn into words.
    lexicon_keys.extend(local_phrase_symptom_matches(hil))

    return {
        "hiligaynon_transcript": transcript,
        "english_transcript": english,
        "merged_symptoms": merged,
        "lexicon_keys": lexicon_keys,
        "urgent_flags": urgent_flags,
        "recognition_ml": recognition,
    }


def analyze_transcript_for_ml(transcript: str) -> dict[str, Any]:
    """Fast ML path: lexicon + translation + disease model (no spaCy noun chunks)."""
    from disease_predictor import enrich_transcript_analysis

    inputs = prepare_transcript_ml_inputs(transcript)
    ml = enrich_transcript_analysis(
        inputs["english_transcript"],
        inputs["merged_symptoms"],
        inputs["urgent_flags"],
        lexicon_keys=inputs["lexicon_keys"],
    )
    recognition = inputs["recognition_ml"]
    return {
        "hiligaynon_transcript": inputs["hiligaynon_transcript"],
        "english_transcript": inputs["english_transcript"],
        "symptoms": inputs["merged_symptoms"],
        "symptom_detections": recognition.get("detections") or [],
        "urgent_flags": inputs["urgent_flags"],
        **ml,
    }


def analyze_transcript(transcript: str, model_status: dict[str, str] | None = None) -> dict[str, Any]:
    from hiligaynon_symptom_matcher import recognize_symptoms

    # One lexicon recognition pass (fuzzy for UI typos); strip CC/form labels first.
    working = normalize_patient_input(transcript)
    recognition = recognize_symptoms(working, include_fuzzy=True, lexicon_only=True)
    ml_inputs = prepare_transcript_ml_inputs(transcript, recognition=recognition)
    english = ml_inputs["english_transcript"]
    symptoms = ml_inputs["merged_symptoms"]
    lexicon_keys = ml_inputs["lexicon_keys"]
    urgent_flags = ml_inputs["urgent_flags"]
    medicines = find_terms(english, MEDICINE_TERMS)
    # Optional: spaCy noun chunks (slow; requires en_core_web_sm). ML does not depend on this.
    noun_phrases: list[str] = []
    if os.environ.get("MEDCONNECT_USE_SPACY_NOUN_CHUNKS", "").lower() in ("1", "true", "yes"):
        noun_phrases = spacy_noun_phrases(english, limit=12)

    summary = "Possible symptoms: " + (", ".join(symptoms) if symptoms else "none detected") + "."
    if medicines:
        summary += " Mentioned medicines: " + ", ".join(medicines) + "."
    if urgent_flags:
        summary += " Urgent cues detected: " + ", ".join(urgent_flags) + "."

    ml: dict[str, Any] = {}
    try:
        from disease_predictor import enrich_transcript_analysis

        ml = enrich_transcript_analysis(
            english,
            symptoms,
            urgent_flags,
            lexicon_keys=lexicon_keys,
        )
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
