"""Step 2: Normalize Hiligaynon patient consultation text."""

from __future__ import annotations

import re

_SPELLING = {
    "saket": "sakit",
    "skit": "sakit",
    "masaket": "masakit",
    "linngin": "lingin",
    "lingin2": "lingin",
    "sip-on": "sipon",
    "tyan": "tiyan",
    "gahabok": "ga hubag",
    "gahubag": "ga hubag",
    "gasakit": "ga sakit",
    "gasuka": "ga suka",
}


def normalize(text: str) -> str:
    text = text.lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    text = re.sub(r"\bga-sakit\b", "ga sakit", text)
    text = re.sub(r"\bga-hubag\b", "ga hubag", text)
    for old, new in _SPELLING.items():
        text = re.sub(rf"\b{re.escape(old)}\b", new, text)
    text = re.sub(r"\bgina\s+", "ga ", text)
    text = re.sub(r"\bnaga\s+", "ga ", text)
    text = re.sub(r"\b(?:gid|man|bah|po)\b", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def phrase_variants(text: str) -> list[str]:
    base = normalize(text)
    variants = [base]
    no_ga = re.sub(r"\bga\s+", "", base).strip()
    if no_ga and no_ga != base:
        variants.append(no_ga)
    return list(dict.fromkeys(variants))
