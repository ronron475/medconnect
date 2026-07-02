"""Step 1: Language detection for Hiligaynon patient messages."""

from __future__ import annotations

import re
from typing import Any

_HIL = frozenset({
    "sakit", "masakit", "hubag", "gahubag", "gahabok", "ubo", "sipon", "tiyan", "dughan",
    "ulo", "mata", "lawas", "budlay", "ginhawa", "kalibanga", "hilanat", "lingin", "unto",
    "pilas", "nanah", "kapoy", "kusog", "suka", "kag", "gid", "ako", "akon", "daw", "may",
})
_TAG = frozenset({"po", "naman", "talaga", "kasi", "yung", "hindi", "meron", "mayroon", "lang"})
_ENG = frozenset({"the", "and", "pain", "fever", "cough", "headache", "breathing", "doctor", "my"})


def detect(text: str) -> dict[str, Any]:
    normalized = text.lower().strip()
    if not normalized:
        return {"primary": "unknown", "tags": [], "is_local": False}

    def count(markers: frozenset[str]) -> int:
        return sum(1 for m in markers if re.search(rf"\b{re.escape(m)}\b", normalized))

    hil, tag, eng = count(_HIL), count(_TAG), count(_ENG)
    tags = []
    if hil:
        tags.append("hiligaynon")
    if tag:
        tags.append("tagalog")
    if eng:
        tags.append("english")

    if len(tags) >= 2:
        return {"primary": "mixed", "tags": tags, "is_local": True}
    if hil:
        return {"primary": "hiligaynon", "tags": tags, "is_local": True}
    if tag:
        return {"primary": "tagalog", "tags": tags, "is_local": True}
    return {"primary": "english", "tags": tags or ["english"], "is_local": False}
