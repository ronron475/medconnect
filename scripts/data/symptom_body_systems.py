"""Map symptom categories and names to standardized body systems."""

from __future__ import annotations

import re

# Primary mapping from NLP category to related_body_system
CATEGORY_BODY_SYSTEM: dict[str, str] = {
    "respiratory": "respiratory",
    "cardiovascular": "cardiovascular",
    "gastrointestinal": "gastrointestinal",
    "neurological": "nervous",
    "mental_health": "mental_behavioral",
    "dermatological": "integumentary",
    "skin": "integumentary",
    "musculoskeletal": "musculoskeletal",
    "urinary": "urinary",
    "reproductive": "reproductive",
    "genitourinary": "genitourinary",
    "endocrine_metabolic": "endocrine",
    "infectious": "immune",
    "hematologic": "hematologic",
    "immunologic": "immune",
    "sensory": "special_senses",
    "pain": "general",
    "general": "general",
    "environmental": "general",
    "oral": "oral",
    "pediatric": "general",
    "geriatric": "general",
}

# Refine genitourinary and multisystem cases by symptom name
NAME_BODY_OVERRIDES: list[tuple[str, str]] = [
    (r"\b(urin|bladder|kidney|renal|hematuria|dysuria|polyuria|oliguria|anuria|flank)\b", "urinary"),
    (r"\b(vaginal|uterine|menstrual|ovarian|testicular|erectile|libido|pregnancy|amenorrhea|dyspareunia|pelvic)\b", "reproductive"),
    (r"\b(fever|chills|malaise|fatigue|weight loss|night sweat|lymph node)\b", "general"),
    (r"\b(sepsis|infection|cellulitis|abscess)\b", "immune"),
    (r"\b(anemia|bleeding|bruising|pallor|petechiae|thrombocytopenia)\b", "hematologic"),
    (r"\b(vision|hearing|tinnitus|vertigo|anosmia|ageusia|photophobia)\b", "special_senses"),
    (r"\b(anxiety|depression|psychosis|hallucination|insomnia|mood)\b", "mental_behavioral"),
    (r"\b(cough|wheeze|dyspnea|hemoptysis|stridor)\b", "respiratory"),
    (r"\b(chest pain|palpitation|edema|syncope|claudication)\b", "cardiovascular"),
    (r"\b(nausea|vomiting|diarrhea|constipation|jaundice|dysphagia)\b", "gastrointestinal"),
    (r"\b(seizure|numbness|weakness|tremor|ataxia|headache)\b", "nervous"),
    (r"\b(rash|pruritus|urticaria|erythema|alopecia)\b", "integumentary"),
    (r"\b(joint|muscle|bone|fracture|arthritis|myalgia)\b", "musculoskeletal"),
    (r"\b(diabetes|thyroid|polyuria|polydipsia|obesity)\b", "endocrine"),
    (r"\b(tooth|gum|halitosis|oral)\b", "oral"),
    (r"\b(infant|newborn|neonatal|colic|failure to thrive)\b", "general"),
    (r"\b(fall|frailty|delirium|sundowning|dementia)\b", "nervous"),
]

DEFAULT_BODY_SYSTEM = "general"


def related_body_system(symptom_name: str, category: str) -> str:
    """Return standardized related_body_system for a symptom."""
    text = symptom_name.lower()
    for pattern, system in NAME_BODY_OVERRIDES:
        if re.search(pattern, text, re.I):
            return system
    return CATEGORY_BODY_SYSTEM.get(category, DEFAULT_BODY_SYSTEM)
