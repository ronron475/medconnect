"""
Build patient-style training cases from archive_source/dataset.csv.

Generates natural-language transcripts (English + Hiligaynon) mapped to diseases
and canonical symptom keys for ML evaluation and future NLP training.

Run:
    python scripts/data/build_patient_training_dataset.py

Output:
    data/nlp/training/patient_cases.csv
    data/nlp/training/patient_cases.jsonl
"""

from __future__ import annotations

import csv
import json
import random
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
ARCHIVE = ROOT / "data" / "nlp" / "archive_source"
DICTIONARY = ROOT / "data" / "nlp" / "medical_dictionary.csv"
SYMPTOM_PHRASE_FILE = ROOT / "data" / "nlp" / "hiligaynon_symptom_phrases.json"
OUT_DIR = ROOT / "data" / "nlp" / "training"
OUT_CSV = OUT_DIR / "patient_cases.csv"
OUT_JSONL = OUT_DIR / "patient_cases.jsonl"

# Extra Hiligaynon/Ilonggo patient phrases for archive symptom keys.
HILIGAYNON_SYMPTOM_PHRASES: dict[str, list[str]] = {
    "high_fever": ["hilanat", "ginahilanat", "mataas nga hilanat"],
    "mild_fever": ["hilanat", "gamay nga hilanat"],
    "cough": ["ubo"],
    "chest_pain": ["sakit dughan", "hapdi dughan"],
    "breathlessness": ["budlay ginhawa", "ginabudlayan ginhawa"],
    "headache": ["sakit ulo", "labad ulo"],
    "vomiting": ["suka", "nagsuka"],
    "nausea": ["nahilo", "nagasuka"],
    "diarrhoea": ["kalibanga"],
    "stomach_pain": ["sakit tiyan", "panakit tiyan"],
    "abdominal_pain": ["sakit tiyan"],
    "belly_pain": ["sakit tiyan"],
    "fatigue": ["kakapoy", "ginakapoy"],
    "dizziness": ["kalipong", "nagalipong"],
    "skin_rash": ["rashes", "hubag sa panit"],
    "itching": ["katol", "ginakatol"],
    "joint_pain": ["sakit lutahan"],
    "back_pain": ["sakit likod"],
    "runny_nose": ["sip-on", "sip on"],
    "throat_irritation": ["sakit tutunlan"],
    "chills": ["ginapanas-an", "mabinugnaw"],
    "phlegm": ["plema", "may plema", "madamo plema"],
    "mucoid_sputum": ["plema", "malagkit plema"],
    "rusty_sputum": ["plema may dugo", "rusty sputum"],
    "congestion": ["barado ilong", "sipaon"],
    "burning_micturition": ["hapdi mag-ihi", "hapdi mag ihi", "hapdi pag-ihi"],
    "bladder_discomfort": ["sakit sa bladder", "hapdi sa sulod pag-ihi"],
    "continuous_feel_of_urine": ["daw may ihi permi", "may ihi permi"],
    "foul_smell_of_urine": ["mait nga ihi", "foul smell urine"],
    "polyuria": ["dugay mag-ihi", "dugay mag ihi"],
    "spotting_urination": ["spotting urination", "may dugo sa ihi"],
    "stiff_neck": ["stiff neck", "rigido liog", "sakit liog"],
    "shivering": ["nagakurog", "ginakurog"],
    "palpitations": ["mabilis tagipusuon", "palpitations"],
    "swelling_joints": ["hubag lutahan", "hubag sa lutahan"],
    "swollen_legs": ["hubag tiil", "hubag paa"],
    "weakness_in_limbs": ["luya tiil", "mahina tiil"],
    "toxic_look_(typhos)": ["toxic look", "typhoid"],
    "blood_in_sputum": ["plema may dugo", "blood in sputum"],
    "loss_of_appetite": ["wala gana magkaon", "wala gana kaon", "wala gana", "gakadula gana kaon"],
    "yellowing_of_eyes": ["dulom mata", "dulom sang mata", "gapula mata kag dilaw", "dilaw mata"],
    "yellowish_skin": ["dilaw panit", "dilaw nga panit", "dilaw lawas", "yellowish skin"],
    "jaundice": ["dilaw panit kag dulom mata", "jaundice"],
    "acidity": ["kabog", "ginakabog", "asido", "heartburn", "hyperacidity"],
    "indigestion": ["indi ma digest", "ginabalda tiyan"],
    "ulcers_on_tongue": ["singaw", "singaw sa bibig", "singaw sa dila"],
    "passage_of_gases": ["gas tiyan", "ginapanuhot", "panuhot", "ginahutok tiyan"],
    "internal_itching": ["ginakati sulod", "kati sulod tiyan"],
    "dehydration": ["ginauhaw", "kulang tubig", "wala ko maka-inom tubig"],
    "sunken_eyes": ["natulon mata", "natulon ang mata"],
    "dark_urine": ["itom ihi", "itom ang ihi", "dulom ihi"],
    "yellow_urine": ["dilaw ihi", "dilaw ang ihi"],
    "weight_loss": ["nagapanipis lawas", "nagakunhod timbang"],
    "lethargy": ["luya gid", "ginatulog tulog"],
    "malaise": ["ginabalatian", "hindi maayo lawas"],
    "muscle_pain": ["sakit kalamnan", "sakit sa kalamnan"],
}

EN_TEMPLATES = [
    "I have been having {symptoms} for a few days.",
    "The patient complains of {symptoms}.",
    "My symptoms include {symptoms}.",
    "For the past week I have experienced {symptoms}.",
    "I came in because of {symptoms}.",
    "Lately I feel {symptoms}.",
]

HIL_TEMPLATES = [
    "May {symptoms} ako.",
    "Ang pasyente may {symptoms}.",
    "Nagabalati ako sang {symptoms}.",
    "Subong may {symptoms} ang pasyente.",
    "Kag {symptoms} ko sa sulod sang isa ka semana.",
    "Nagareklamo ang pasyente sang {symptoms}.",
    "Ginabatyag ko ang {symptoms} sa pila na ka adlaw.",
    "Ang akon reklamo subong amo ang {symptoms}.",
    "Halin kagabi may {symptoms} na ako.",
    "Indi na mabatas ang {symptoms} ko.",
]


def normalize_symptom(raw: str) -> str:
    cleaned = re.sub(r"\s+", "_", (raw or "").strip().lower())
    while "__" in cleaned:
        cleaned = cleaned.replace("__", "_")
    return cleaned.strip("_")


def symptom_to_english_phrase(key: str) -> str:
    return key.replace("_", " ")


def load_dictionary_hiligaynon() -> dict[str, list[str]]:
    mapping: dict[str, list[str]] = {}
    if not DICTIONARY.is_file():
        return mapping
    with DICTIONARY.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            english = (row.get("english_term") or "").strip().lower()
            local = (row.get("local_term") or "").strip()
            if not english or not local:
                continue
            mapping.setdefault(english, [])
            if local not in mapping[english]:
                mapping[english].append(local)
    return mapping


def load_curated_symptom_phrases() -> dict[str, list[str]]:
    """Shared Hiligaynon phrasing (see data/nlp/hiligaynon_symptom_phrases.json)."""
    if not SYMPTOM_PHRASE_FILE.is_file():
        return {}
    payload = json.loads(SYMPTOM_PHRASE_FILE.read_text(encoding="utf-8"))
    return {
        key: list(phrases)
        for key, phrases in (payload.get("phrases") or {}).items()
        if phrases
    }


CURATED_SYMPTOM_PHRASES = load_curated_symptom_phrases()


def hiligaynon_phrase_for_symptom(key: str, dictionary: dict[str, list[str]]) -> str:
    if key in CURATED_SYMPTOM_PHRASES:
        return random.choice(CURATED_SYMPTOM_PHRASES[key])
    if key in HILIGAYNON_SYMPTOM_PHRASES:
        return random.choice(HILIGAYNON_SYMPTOM_PHRASES[key])
    english = symptom_to_english_phrase(key)
    if english in dictionary:
        return random.choice(dictionary[english])
    # A substring search used to land here and return body-part-only terms
    # ("dalunggan" for fast_heart_rate, "glaucoma" for coma). Patients mixing in the
    # English term is realistic; naming the wrong organ is not.
    return english


def tidy_transcript(text: str) -> str:
    """Smooth seams where a template and a phrase repeat the same particle.

    "May {symptoms} ako." + "may rashes sa panit" reads as "May may rashes...".
    """
    cleaned = re.sub(r"(?i)\b(may|ang|sang|nga)\s+\1\b", r"\1", text or "")
    cleaned = re.sub(r"\s+", " ", cleaned)
    return re.sub(r"\s+([,.?!])", r"\1", cleaned).strip()


def join_phrases(phrases: list[str], language: str) -> str:
    phrases = [p.strip() for p in phrases if p.strip()]
    if not phrases:
        return ""
    if len(phrases) == 1:
        return phrases[0]
    if language == "hiligaynon":
        if len(phrases) == 2:
            return f"{phrases[0]} kag {phrases[1]}"
        return ", ".join(phrases[:-1]) + f", kag {phrases[-1]}"
    return ", ".join(phrases[:-1]) + f" and {phrases[-1]}"


def load_disease_symptom_rows() -> list[tuple[str, tuple[str, ...]]]:
    dataset = ARCHIVE / "dataset.csv"
    if not dataset.is_file():
        raise FileNotFoundError(f"Missing dataset: {dataset}")

    unique: dict[tuple[str, tuple[str, ...]], None] = {}
    with dataset.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            disease = (row.get("Disease") or "").strip()
            if not disease:
                continue
            symptoms: list[str] = []
            for key, value in row.items():
                if not key.startswith("Symptom_"):
                    continue
                symptom = normalize_symptom(value)
                if symptom:
                    symptoms.append(symptom)
            if not symptoms:
                continue
            signature = (disease, tuple(sorted(set(symptoms))))
            unique[signature] = None
    return list(unique.keys())


_SPLIT_COUNTERS: dict[tuple[str, str], int] = {}


def assign_split(disease: str, seed: int = 42, group: str = "archive") -> str:
    """Stratified per-case split: 70/15/15 within every disease.

    Keying the draw on the disease name alone put every case of a disease in the same
    split, so train/val/test held disjoint disease sets and the test split covered
    only 4 of 41 conditions. Rotating a per-disease counter keeps the ratio exact and
    guarantees each disease appears in all three splits.
    """
    counter_key = (group, disease)
    index = _SPLIT_COUNTERS.get(counter_key, 0)
    _SPLIT_COUNTERS[counter_key] = index + 1
    # Shuffle the 20-slot pattern per disease so splits aren't tied to template order.
    pattern = ["train"] * 14 + ["val"] * 3 + ["test"] * 3
    random.Random(f"{seed}:{group}:{disease}").shuffle(pattern)
    return pattern[index % len(pattern)]


def build_cases() -> list[dict[str, str | int]]:
    dictionary = load_dictionary_hiligaynon()
    rows = load_disease_symptom_rows()
    cases: list[dict[str, str | int]] = []
    case_id = 0

    for disease, symptom_keys in rows:
        english_phrases = [symptom_to_english_phrase(k) for k in symptom_keys]
        hil_phrases = [hiligaynon_phrase_for_symptom(k, dictionary) for k in symptom_keys]
        symptom_blob = ";".join(symptom_keys)

        for template_idx, template in enumerate(EN_TEMPLATES):
            case_id += 1
            cases.append(
                {
                    "case_id": f"PC-{case_id:05d}",
                    "disease": disease,
                    "language": "english",
                    "transcript": template.format(symptoms=join_phrases(english_phrases, "english")),
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"en_{template_idx + 1}",
                    "split": assign_split(disease),
                    "source": "archive_source/dataset.csv",
                }
            )

        for template_idx, template in enumerate(HIL_TEMPLATES):
            case_id += 1
            cases.append(
                {
                    "case_id": f"PC-{case_id:05d}",
                    "disease": disease,
                    "language": "hiligaynon",
                    "transcript": template.format(symptoms=join_phrases(hil_phrases, "hiligaynon")),
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"hil_{template_idx + 1}",
                    "split": assign_split(disease),
                    "source": "archive_source/dataset.csv",
                }
            )

        # Mixed teleconsultation-style line (Hiligaynon + English medicines optional)
        case_id += 1
        mixed = (
            f"May {join_phrases(hil_phrases[:2], 'hiligaynon')} ang pasyente. "
            f"Also reports {join_phrases(english_phrases[2:], 'english')}."
            if len(symptom_keys) > 2
            else f"May {join_phrases(hil_phrases, 'hiligaynon')} kag {join_phrases(english_phrases, 'english')}."
        )
        cases.append(
            {
                "case_id": f"PC-{case_id:05d}",
                "disease": disease,
                "language": "mixed",
                "transcript": mixed,
                "symptom_keys": symptom_blob,
                "symptom_count": len(symptom_keys),
                "template_id": "mixed_1",
                "split": assign_split(disease),
                "source": "archive_source/dataset.csv",
            }
        )

    return cases


def write_outputs(cases: list[dict[str, str | int]]) -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    fieldnames = [
        "case_id",
        "disease",
        "language",
        "transcript",
        "symptom_keys",
        "symptom_count",
        "template_id",
        "split",
        "source",
    ]

    with OUT_CSV.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(cases)

    with OUT_JSONL.open("w", encoding="utf-8") as handle:
        for row in cases:
            handle.write(json.dumps(row, ensure_ascii=False) + "\n")


def main() -> None:
    random.seed(42)
    cases = build_cases()
    write_outputs(cases)

    by_lang: dict[str, int] = {}
    by_split: dict[str, int] = {}
    diseases = set()
    for row in cases:
        by_lang[str(row["language"])] = by_lang.get(str(row["language"]), 0) + 1
        by_split[str(row["split"])] = by_split.get(str(row["split"]), 0) + 1
        diseases.add(str(row["disease"]))

    print("medConnect patient training dataset")
    print("===================================")
    print(f"Output CSV:   {OUT_CSV}")
    print(f"Output JSONL: {OUT_JSONL}")
    print(f"Total cases:  {len(cases)}")
    print(f"Diseases:     {len(diseases)}")
    print(f"By language:  {by_lang}")
    print(f"By split:     {by_split}")
    print()
    print("Evaluate ML pipeline:")
    print("  python scripts/dev/evaluate_patient_ml_cases.py")


if __name__ == "__main__":
    main()
