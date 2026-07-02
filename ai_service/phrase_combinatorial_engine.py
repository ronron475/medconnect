"""
Rule-based combinatorial Hiligaynon medical phrase engine.

Generates and matches millions of symptom expressions dynamically from:
  symptom roots + body parts + templates + misspelling rules + classification rules

Does NOT require millions of static CSV rows.
"""

from __future__ import annotations

import json
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

_ENGINE_DIR = Path(__file__).resolve().parent.parent / "data" / "nlp" / "phrase_engine"


def normalize(text: str) -> str:
    text = (text or "").lower().strip()
    text = re.sub(r"[^a-z0-9\s\-]", " ", text)
    return re.sub(r"\s+", " ", text).strip()


@lru_cache(maxsize=1)
def _load_json(name: str) -> dict[str, Any]:
    path = _ENGINE_DIR / name
    if not path.is_file():
        return {}
    with path.open(encoding="utf-8") as f:
        return json.load(f)


@lru_cache(maxsize=1)
def symptom_roots() -> tuple[dict[str, Any], ...]:
    data = _load_json("symptom_roots.json")
    roots: list[dict[str, Any]] = []
    for root in data.get("roots", []):
        terms = sorted((root.get("terms") or []), key=len, reverse=True)
        roots.append({**root, "terms_sorted": terms})
    roots.sort(key=lambda r: max((len(t) for t in r.get("terms_sorted", [])), default=0), reverse=True)
    return tuple(roots)


@lru_cache(maxsize=1)
def body_parts() -> tuple[dict[str, Any], ...]:
    data = _load_json("body_parts.json")
    parts = list(data.get("parts", []))
    parts.sort(key=lambda p: len(p.get("hil", "")), reverse=True)
    return tuple(parts)


@lru_cache(maxsize=1)
def misspelling_map() -> dict[str, str]:
    """wrong -> correct term (longest keys first at apply time)."""
    rules = _load_json("misspelling_rules.json")
    mapping: dict[str, str] = {}
    for correct, variants in (rules.get("known_variants") or {}).items():
        c = normalize(correct)
        for v in variants:
            w = normalize(v)
            if w and w != c and w not in mapping:
                mapping[w] = c
    return mapping


@lru_cache(maxsize=1)
def classification_index() -> tuple[dict[tuple[str, str], dict[str, Any]], dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    data = _load_json("classification_rules.json")
    pair_rules: dict[tuple[str, str], dict[str, Any]] = {}
    for rule in data.get("rules", []):
        key = (rule.get("symptom_id", ""), rule.get("body_eng", ""))
        pair_rules[key] = rule
    defaults = data.get("defaults_by_symptom") or {}
    patterns = {r["pattern"]: r for r in data.get("pattern_rules", []) if r.get("pattern")}
    return pair_rules, defaults, patterns


def apply_misspelling_corrections(text: str) -> str:
    working = normalize(text)
    if not working:
        return ""
    mp = misspelling_map()
    for wrong in sorted(mp.keys(), key=len, reverse=True):
        correct = mp[wrong]
        working = re.sub(r"(?<!\w)" + re.escape(wrong) + r"(?!\w)", correct, working)
    return working.strip()


def _find_symptom_matches(text: str) -> list[dict[str, Any]]:
    matches: list[dict[str, Any]] = []
    occupied = [False] * max(len(text), 1)
    for root in symptom_roots():
        for term in root.get("terms_sorted", []):
            term_n = normalize(term)
            if not term_n:
                continue
            pattern = re.compile(r"(?<!\w)" + re.escape(term_n) + r"(?!\w)")
            for m in pattern.finditer(text):
                start, end = m.start(), m.end()
                if any(occupied[start:end]):
                    continue
                for i in range(start, end):
                    occupied[i] = True
                matches.append({
                    "root": root,
                    "term": term_n,
                    "span": [start, end],
                })
    matches.sort(key=lambda x: x["span"][0])
    return matches


def _find_body_matches(text: str) -> list[dict[str, Any]]:
    matches: list[dict[str, Any]] = []
    occupied = [False] * max(len(text), 1)
    for part in body_parts():
        hil = normalize(part.get("hil", ""))
        if not hil:
            continue
        pattern = re.compile(r"(?<!\w)" + re.escape(hil) + r"(?!\w)")
        for m in pattern.finditer(text):
            start, end = m.start(), m.end()
            if any(occupied[start:end]):
                continue
            for i in range(start, end):
                occupied[i] = True
            matches.append({
                "part": part,
                "term": hil,
                "span": [start, end],
            })
    matches.sort(key=lambda x: x["span"][0])
    return matches


def _pair_symptom_body(
    symptom_matches: list[dict[str, Any]],
    body_matches: list[dict[str, Any]],
) -> list[tuple[dict[str, Any] | None, dict[str, Any] | None]]:
    if not symptom_matches and not body_matches:
        return []
    if not symptom_matches:
        return [(None, b) for b in body_matches]
    if not body_matches:
        return [(s, None) for s in symptom_matches]

    pairs: list[tuple[dict[str, Any] | None, dict[str, Any] | None]] = []
    used_bodies: set[int] = set()
    for s in symptom_matches:
        best_body = None
        best_dist = 999
        for idx, b in enumerate(body_matches):
            if idx in used_bodies:
                continue
            dist = abs((s["span"][0] + s["span"][1]) // 2 - (b["span"][0] + b["span"][1]) // 2)
            if dist < best_dist:
                best_dist = dist
                best_body = (idx, b)
        if best_body and best_dist <= 40:
            used_bodies.add(best_body[0])
            pairs.append((s, best_body[1]))
        else:
            pairs.append((s, None))
    for idx, b in enumerate(body_matches):
        if idx not in used_bodies:
            pairs.append((None, b))
    return pairs


def _classify(
    root: dict[str, Any] | None,
    part: dict[str, Any] | None,
    text: str,
) -> dict[str, str]:
    pair_rules, defaults, patterns = classification_index()
    for pat, rule in patterns.items():
        if pat in text:
            return {
                "triage_level": rule.get("triage", "emergency"),
                "severity": rule.get("severity", "critical"),
                "medical_category": rule.get("category", "general"),
                "english_override": rule.get("english", ""),
            }

    symptom_id = (root or {}).get("id", "")
    body_eng = (part or {}).get("eng", "")

    if symptom_id and body_eng:
        rule = pair_rules.get((symptom_id, body_eng))
        if rule:
            return {
                "triage_level": rule.get("triage", "urgent"),
                "severity": rule.get("severity", "moderate"),
                "medical_category": rule.get("category", "general"),
                "english_override": "",
            }

    if symptom_id in defaults:
        d = defaults[symptom_id]
        return {
            "triage_level": d.get("triage", "urgent"),
            "severity": d.get("severity", "moderate"),
            "medical_category": (root or {}).get("category", "general"),
            "english_override": "",
        }

    if root:
        return {
            "triage_level": root.get("default_triage", "routine"),
            "severity": root.get("default_severity", "moderate"),
            "medical_category": root.get("category", "general"),
            "english_override": "",
        }

    return {
        "triage_level": "routine",
        "severity": "mild",
        "medical_category": (part or {}).get("category", "general"),
        "english_override": "",
    }


def _compose_english(
    root: dict[str, Any] | None,
    part: dict[str, Any] | None,
    classification: dict[str, str],
) -> str:
    if classification.get("english_override"):
        return classification["english_override"]

    if root and part:
        eng_sym = root.get("english_symptom", "symptom")
        eng_body = part.get("eng", "")
        if root.get("id") == "pus_infection":
            return f"{eng_body} infection"
        if eng_sym in ("pain", "swelling", "itching", "redness", "bleeding"):
            return f"{eng_body} {eng_sym}"
        return f"{eng_body} {root.get('english_phrase', eng_sym)}"

    if root:
        return str(root.get("english_phrase") or root.get("english_symptom", "symptom"))

    if part:
        return str(part.get("eng", "body part"))

    return "symptom"


def _extract_matched_substring(text: str, symptom: dict[str, Any] | None, body: dict[str, Any] | None) -> str:
    spans: list[tuple[int, int]] = []
    if symptom:
        spans.append(tuple(symptom["span"]))
    if body:
        spans.append(tuple(body["span"]))
    if not spans:
        return text
    start = min(s[0] for s in spans)
    end = max(s[1] for s in spans)
    return text[start:end].strip()


def match_phrases(text: str) -> list[dict[str, Any]]:
    """Dynamically match combinatorial symptom phrases in patient text."""
    corrected = apply_misspelling_corrections(text)
    working = normalize(corrected)
    if not working:
        return []

    symptom_matches = _find_symptom_matches(working)
    body_matches = _find_body_matches(working)
    pairs = _pair_symptom_body(symptom_matches, body_matches)

    results: list[dict[str, Any]] = []
    seen: set[str] = set()

    for s_match, b_match in pairs:
        root = s_match["root"] if s_match else None
        part = b_match["part"] if b_match else None
        classification = _classify(root, part, working)
        english = _compose_english(root, part, classification)
        matched_sub = _extract_matched_substring(working, s_match, b_match)
        key = f"{matched_sub}|{english}"
        if key in seen:
            continue
        seen.add(key)

        body_eng = (part or {}).get("eng", "")
        sym_eng = (root or {}).get("english_symptom", "")

        results.append({
            "hiligaynon_term": matched_sub or working,
            "english_term": english,
            "medical_category": classification["medical_category"],
            "severity": classification["severity"].capitalize() if classification["severity"] else "Moderate",
            "triage_level": classification["triage_level"],
            "body_part": body_eng,
            "symptom": sym_eng,
            "condition": english if (root or {}).get("is_condition") or "infection" in english.lower() else "",
            "source": "phrase_combinatorial_engine",
            "matched_phrase": matched_sub or working,
            "span": [
                min(
                    s_match["span"][0] if s_match else len(working),
                    b_match["span"][0] if b_match else len(working),
                ),
                max(
                    s_match["span"][1] if s_match else 0,
                    b_match["span"][1] if b_match else 0,
                ),
            ],
            "confidence": 94,
            "engine": "combinatorial_v1",
        })

    return results


def estimate_combination_count() -> dict[str, int]:
    """Theoretical coverage from rule tables (not stored rows)."""
    roots = symptom_roots()
    parts = body_parts()
    templates = _load_json("templates.json")
    misspellings = _load_json("misspelling_rules.json")

    n_symptom_terms = sum(len(r.get("terms", [])) for r in roots)
    n_body = len(parts)
    n_orders = len(templates.get("word_orders", [])) + len(templates.get("standalone_orders", []))
    n_poss = max(len(templates.get("possessives", [])), 1)
    n_intens = max(len(templates.get("intensifiers", [])), 1)
    n_misspell = sum(len(v) for v in (misspellings.get("known_variants") or {}).values())

    symptom_body = n_symptom_terms * n_body * n_orders * n_poss * n_intens
    standalone = n_symptom_terms * len(templates.get("standalone_orders", [1])) * n_poss
    with_misspell = (symptom_body + standalone) * max(n_misspell // max(n_symptom_terms, 1), 1)

    return {
        "symptom_roots": len(roots),
        "symptom_terms": n_symptom_terms,
        "body_parts": n_body,
        "templates": n_orders,
        "theoretical_symptom_body_phrases": symptom_body,
        "theoretical_with_variants": with_misspell,
        "target_met": with_misspell >= 1_000_000,
    }


def generate_canonical_phrase(symptom_term: str, body_hil: str, possessive: str = "ko") -> str:
    """Generate one canonical phrase (for exemplar export, not bulk storage)."""
    return normalize(f"{symptom_term} {body_hil} {possessive}")


def clear_cache() -> None:
    _load_json.cache_clear()
    symptom_roots.cache_clear()
    body_parts.cache_clear()
    misspelling_map.cache_clear()
    classification_index.cache_clear()
