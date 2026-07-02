"""Official ICD-10-CM chapter ranges (FY2024/FY2025 structure)."""

from __future__ import annotations

# (chapter_code, chapter_title, category_slug, start, end) — inclusive letter+digit bounds
ICD10_CHAPTERS: list[tuple[str, str, str, str, str]] = [
    ("I", "Certain infectious and parasitic diseases", "infectious_parasitic", "A00", "B99"),
    ("II", "Neoplasms", "neoplasm", "C00", "D49"),
    ("III", "Diseases of the blood and blood-forming organs", "blood_immune", "D50", "D89"),
    ("IV", "Endocrine, nutritional and metabolic diseases", "endocrine_metabolic", "E00", "E89"),
    ("V", "Mental, behavioral and neurodevelopmental disorders", "mental_behavioral", "F01", "F99"),
    ("VI", "Diseases of the nervous system", "nervous_system", "G00", "G99"),
    ("VII", "Diseases of the eye and adnexa", "eye_adnexa", "H00", "H59"),
    ("VIII", "Diseases of the ear and mastoid process", "ear_mastoid", "H60", "H95"),
    ("IX", "Diseases of the circulatory system", "circulatory", "I00", "I99"),
    ("X", "Diseases of the respiratory system", "respiratory", "J00", "J99"),
    ("XI", "Diseases of the digestive system", "digestive", "K00", "K95"),
    ("XII", "Diseases of the skin and subcutaneous tissue", "skin", "L00", "L99"),
    ("XIII", "Diseases of the musculoskeletal system", "musculoskeletal", "M00", "M99"),
    ("XIV", "Diseases of the genitourinary system", "genitourinary", "N00", "N99"),
    ("XV", "Pregnancy, childbirth and the puerperium", "pregnancy", "O00", "O9A"),
    ("XVI", "Certain conditions originating in the perinatal period", "perinatal", "P00", "P96"),
    ("XVII", "Congenital malformations and chromosomal abnormalities", "congenital", "Q00", "Q99"),
    ("XVIII", "Symptoms, signs and abnormal clinical findings", "symptoms_signs", "R00", "R94"),
    ("XIX", "Injury, poisoning and external causes", "injury_poisoning", "S00", "T88"),
    ("XX", "External causes of morbidity", "external_causes", "V00", "Y99"),
    ("XXI", "Factors influencing health status", "health_factors", "Z00", "Z99"),
    ("XXII", "Codes for special purposes", "special_purposes", "U00", "U85"),
]


def _code_key(code: str) -> tuple[int, int]:
    """Sortable key for ICD-10-CM codes without dots."""
    c = code.upper().replace(".", "")
    letter = ord(c[0]) if c else 0
    num = 0
    if len(c) > 1:
        try:
            num = int(c[1:].replace("A", "").replace("D", "")[:3] or "0")
        except ValueError:
            num = 0
    return letter, num


def chapter_for_code(code: str) -> tuple[str, str, str]:
    """Return (chapter_code, chapter_title, category_slug)."""
    key = _code_key(code)
    for ch_code, title, slug, start, end in ICD10_CHAPTERS:
        if _code_key(start) <= key <= _code_key(end):
            return ch_code, title, slug
    return "XXI", "Factors influencing health status", "health_factors"


def format_icd_code(raw: str) -> str:
    """A0001 -> A00.01 style when length > 3."""
    raw = raw.strip().upper()
    if len(raw) <= 3:
        return raw
    return f"{raw[:3]}.{raw[3:]}"
