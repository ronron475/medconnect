"""Fast phrase scanning against large Hiligaynon indexes."""

from __future__ import annotations

import re
from typing import Any, Callable


def phrase_candidates_from_text(
    text: str,
    index: dict[str, Any],
    *,
    max_phrase_words: int = 8,
) -> list[str]:
    words = (text or "").split()
    if not words:
        return []
    found: set[str] = set()
    word_count = len(words)
    for i in range(word_count):
        for j in range(i + 1, min(word_count, i + max_phrase_words) + 1):
            phrase = " ".join(words[i:j])
            if phrase in index:
                found.add(phrase)
    return sorted(found, key=len, reverse=True)


def scan_indexed_phrases(
    working: str,
    index: dict[str, Any],
    *,
    max_phrase_words: int = 8,
) -> list[tuple[int, int, str, dict[str, Any]]]:
    """Return non-overlapping (start, end, term, entry) matches."""
    if not working:
        return []
    occupied = [False] * len(working)
    hits: list[tuple[int, int, str, dict[str, Any]]] = []
    for term in phrase_candidates_from_text(working, index, max_phrase_words=max_phrase_words):
        pattern = re.compile(r"(?<!\w)" + re.escape(term) + r"(?!\w)", re.I)
        for match in pattern.finditer(working):
            start, end = match.start(), match.end()
            if any(occupied[start:end]):
                continue
            entry = index.get(term)
            if not entry:
                continue
            for i in range(start, end):
                occupied[i] = True
            hits.append((start, end, term, entry))
    hits.sort(key=lambda item: item[0])
    return hits


def translate_with_index(
    text: str,
    index: dict[str, Any],
    normalize: Callable[[str], str],
    lookup_exact: Callable[[str], str],
    *,
    max_phrase_words: int = 8,
) -> str:
    if not text or not text.strip():
        return ""
    working = normalize(text)
    hits = scan_indexed_phrases(working, index, max_phrase_words=max_phrase_words)
    if not hits:
        return lookup_exact(text) or text
    parts: list[str] = []
    seen: set[str] = set()
    for _, _, _, entry in hits:
        english = str(entry.get("english") or entry.get("english_term") or "").strip()
        low = english.lower()
        if english and low not in seen:
            seen.add(low)
            parts.append(english)
    if not parts:
        return lookup_exact(text) or text
    return ", ".join(parts)
