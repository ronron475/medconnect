#!/usr/bin/env python3
"""
Combinatorial Hiligaynon medical dataset generator.

Does NOT materialize millions of CSV rows. Instead:
  1. Maintains compact JSON rule tables in data/nlp/phrase_engine/
  2. Exports small reference CSVs (roots, synonyms, misspellings, triage rules, exemplars)
  3. Reports theoretical phrase coverage (1M+ combinations)

Runtime matching uses phrase_combinatorial_engine.py dynamically.
"""

from __future__ import annotations

import csv
import itertools
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"
ENGINE = NLP / "phrase_engine"
AI_SERVICE = ROOT / "ai_service"

sys.path.insert(0, str(AI_SERVICE))
from phrase_combinatorial_engine import (  # noqa: E402
    apply_misspelling_corrections,
    body_parts,
    estimate_combination_count,
    generate_canonical_phrase,
    match_phrases,
    symptom_roots,
)

BODY_FIELDS = ["hiligaynon_term", "english_term", "body_system", "anatomy_category", "status"]
MISSPELL_FIELDS = ["correct_term", "misspelling", "term_type", "status"]
SYNONYM_FIELDS = ["hiligaynon_term", "english_term", "synonym_group", "status"]
TRIAGE_FIELDS = ["hiligaynon_pattern", "english_pattern", "triage_level", "severity", "medical_category", "reason", "body_system", "status"]
PHRASE_FIELDS = ["hiligaynon_term", "english_term", "medical_category", "severity", "triage_level", "status"]
DICT_FIELDS = ["dictionary_id", "local_term", "english_term", "category"]
SYMPTOM_FIELDS = ["hiligaynon_term", "english_term", "medical_category", "severity", "triage_level", "status"]

COMBINATORIAL_PHRASE_LIMIT = 12_000
STEP6_PER_TRIAGE = 100
CONDITION_FIELDS = ["hiligaynon_term", "english_term", "medical_category", "severity", "triage_level", "status"]


def apply_word_order(
    template: str,
    symptom: str,
    body: str = "",
    possessive: str = "",
    intensifier: str = "",
) -> str:
    text = template.replace("{symptom}", symptom).replace("{body}", body)
    text = text.replace("{possessive}", possessive).replace("{intensifier}", intensifier)
    return " ".join(p for p in text.split() if p)


def iter_template_phrases():
    """Yield phrases from roots × body parts × templates (generator, not stored)."""
    templates_data = json.loads((ENGINE / "templates.json").read_text(encoding="utf-8"))
    possessives = templates_data.get("possessives", ["ko"])
    intensifiers = [""] + (templates_data.get("intensifiers") or [])[:5]
    word_orders = templates_data.get("word_orders", [])
    standalone = templates_data.get("standalone_orders", [])
    durations = (templates_data.get("duration_tokens") or [])[:4]

    for root in symptom_roots():
        terms = root.get("terms") or []
        for term in terms:
            if len(term) > 15 and term.count(" ") >= 2:
                yield term
                for dur in durations:
                    yield f"{dur} {term}"
                continue
            for part in body_parts():
                body = part.get("hil", "")
                if not body:
                    continue
                for order in word_orders:
                    for poss in possessives[:4]:
                        for intens in intensifiers[:4]:
                            phrase = apply_word_order(order, term, body, poss, intens)
                            if phrase and term in phrase:
                                yield phrase
            for order in standalone:
                for poss in possessives[:3]:
                    for intens in intensifiers[:3]:
                        phrase = apply_word_order(order, term, "", poss, intens)
                        if phrase and term in phrase:
                            yield phrase


def write_csv(path: Path, fields: list[str], rows: list[dict]) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=fields, extrasaction="ignore")
        w.writeheader()
        for row in rows:
            w.writerow(row)
    return len(rows)


def export_body_parts() -> int:
    rows = []
    seen: set[str] = set()
    for part in body_parts():
        hil = part.get("hil", "")
        variants = [
            hil,
            f"{hil} ko",
            f"akon {hil}",
            f"sa {hil} ko",
            f"sang {hil}",
            f"ko sa {hil}",
        ]
        for variant in variants:
            v = variant.strip()
            if not v or v in seen:
                continue
            seen.add(v)
            rows.append({
                "hiligaynon_term": v,
                "english_term": part.get("eng", ""),
                "body_system": part.get("body_system", "general"),
                "anatomy_category": part.get("category", "general"),
                "status": "active",
            })
    return write_csv(NLP / "body_parts.csv", BODY_FIELDS, rows)


def export_misspellings() -> int:
    rules = json.loads((ENGINE / "misspelling_rules.json").read_text(encoding="utf-8"))
    rows = []
    seen: set[tuple[str, str]] = set()

    def add_pair(correct: str, wrong: str, term_type: str = "symptom_root") -> None:
        c, w = correct.lower().strip(), wrong.lower().strip()
        if not c or not w or c == w or (c, w) in seen:
            return
        seen.add((c, w))
        rows.append({"correct_term": correct, "misspelling": wrong, "term_type": term_type, "status": "active"})

    for correct, variants in (rules.get("known_variants") or {}).items():
        for wrong in variants:
            add_pair(correct, wrong)

    # Algorithmic single-substitution variants per symptom root term
    all_terms: set[str] = set()
    for root in symptom_roots():
        for term in root.get("terms") or []:
            all_terms.add(term.split()[0] if " " in term else term)
    for part in body_parts():
        all_terms.add(part.get("hil", ""))

    for sub in rules.get("character_substitutions") or []:
        src = sub.get("from", "")
        for tgt in sub.get("to") or []:
            if not src:
                continue
            for term in sorted(all_terms):
                if len(term) < 4 or src not in term:
                    continue
                variant = term.replace(src, tgt, 1)
                if variant != term:
                    add_pair(term, variant, "algorithmic")

    return write_csv(NLP / "medical_misspellings.csv", MISSPELL_FIELDS, rows)


def export_synonyms() -> int:
    rows = []
    for root in symptom_roots():
        eng = root.get("english_symptom", "")
        group = root.get("id", eng)
        for term in root.get("terms", []):
            rows.append({
                "hiligaynon_term": term,
                "english_term": eng,
                "synonym_group": group,
                "status": "active",
            })
    path = NLP / "symptom_synonyms.csv"
    count = write_csv(path, SYNONYM_FIELDS, rows)
    write_csv(NLP / "medical_synonyms.csv", SYNONYM_FIELDS, rows)
    return count


def export_combinatorial_phrases(limit: int = COMBINATORIAL_PHRASE_LIMIT) -> int:
    """Bulk combinatorial phrases CSV (generated from rules, capped for file size)."""
    rows: list[dict] = []
    seen: set[str] = set()

    for hil in iter_template_phrases():
        key = hil.lower().strip()
        if not key or key in seen:
            continue
        seen.add(key)
        matches = match_phrases(hil)
        if not matches:
            continue
        m = matches[0]
        rows.append({
            "hiligaynon_term": hil,
            "english_term": m.get("english_term", ""),
            "medical_category": m.get("medical_category", "general"),
            "severity": m.get("severity", "Moderate"),
            "triage_level": m.get("triage_level", "routine"),
            "body_part": m.get("body_part", ""),
            "symptom": m.get("symptom", ""),
            "status": "active",
        })
        if len(rows) >= limit:
            break

    fields = PHRASE_FIELDS + ["body_part", "symptom"]
    path = NLP / "hiligaynon_combinatorial_phrases.csv"
    count = write_csv(path, fields, rows)
    print(f"  hiligaynon_combinatorial_phrases.csv: {count} phrases (limit {limit:,})")
    return count


def export_hiligaynon_conditions() -> int:
    """Condition rows from infection/trauma roots × body parts."""
    rows: list[dict] = []
    seen: set[str] = set()
    condition_roots = [r for r in symptom_roots() if r.get("is_condition") or r.get("id") in {
        "pus_infection", "amputation", "fracture", "hypertension", "vehicle_trauma",
        "electrical_injury", "dog_bite", "urinary_retention", "allergic_reaction",
        "suicidal_ideation", "head_injury", "seizure",
    }]
    for root in condition_roots:
        for term in root.get("terms") or []:
            for part in body_parts():
                body = part.get("hil", "")
                for poss in ["ko", "akon"]:
                    hil = f"{term} {body} {poss}".strip() if body and body not in term else f"{term} {poss}".strip()
                    if hil in seen:
                        continue
                    matches = match_phrases(hil)
                    if not matches:
                        continue
                    seen.add(hil)
                    m = matches[0]
                    rows.append({
                        "hiligaynon_term": hil,
                        "english_term": m.get("english_term", root.get("english_phrase", "")),
                        "medical_category": m.get("medical_category", root.get("category", "general")),
                        "severity": m.get("severity", "Moderate"),
                        "triage_level": m.get("triage_level", root.get("default_triage", "urgent")),
                        "status": "active",
                    })
            for term in root.get("terms") or []:
                if term in seen:
                    continue
                matches = match_phrases(term)
                if matches:
                    seen.add(term)
                    m = matches[0]
                    rows.append({
                        "hiligaynon_term": term,
                        "english_term": m.get("english_term", ""),
                        "medical_category": m.get("medical_category", "general"),
                        "severity": m.get("severity", "Moderate"),
                        "triage_level": m.get("triage_level", "urgent"),
                        "status": "active",
                    })

    path = NLP / "hiligaynon_conditions_combinatorial.csv"
    count = write_csv(path, CONDITION_FIELDS, rows)
    print(f"  hiligaynon_conditions_combinatorial.csv: {count} conditions")
    return count


def export_triage_rules() -> int:
    rules_data = json.loads((ENGINE / "classification_rules.json").read_text(encoding="utf-8"))
    rows = []
    for rule in rules_data.get("rules", []):
        sid = rule.get("symptom_id", "")
        body = rule.get("body_eng", "")
        root = next((r for r in symptom_roots() if r.get("id") == sid), None)
        part = next((p for p in body_parts() if p.get("eng") == body), None)
        if not root or not part:
            continue
        term = (root.get("terms") or [""])[0]
        hil = generate_canonical_phrase(term, part.get("hil", ""))
        eng = f"{body} {root.get('english_symptom', 'symptom')}"
        rows.append({
            "hiligaynon_pattern": hil,
            "english_pattern": eng,
            "triage_level": rule.get("triage", "urgent"),
            "severity": rule.get("severity", "moderate"),
            "medical_category": rule.get("category", "general"),
            "reason": f"Auto-rule: {sid} + {body}",
            "body_system": part.get("body_system", "general"),
            "status": "active",
        })
    for pr in rules_data.get("pattern_rules", []):
        rows.append({
            "hiligaynon_pattern": pr.get("pattern", ""),
            "english_pattern": pr.get("english", ""),
            "triage_level": pr.get("triage", "emergency"),
            "severity": pr.get("severity", "critical"),
            "medical_category": pr.get("category", "general"),
            "reason": "Pattern rule",
            "body_system": "general",
            "status": "active",
        })

    # Auto-expand: one rule per root × body from combinatorial sample
    auto_seen: set[str] = set()
    for hil in itertools.islice(iter_template_phrases(), 2500):
        matches = match_phrases(hil)
        if not matches:
            continue
        m = matches[0]
        tri = m.get("triage_level", "routine")
        key = hil.lower()
        if key in auto_seen:
            continue
        auto_seen.add(key)
        rows.append({
            "hiligaynon_pattern": hil,
            "english_pattern": m.get("english_term", ""),
            "triage_level": tri,
            "severity": str(m.get("severity", "moderate")).lower(),
            "medical_category": m.get("medical_category", "general"),
            "reason": "Combinatorial auto-rule",
            "body_system": m.get("body_part", "general") or "general",
            "status": "active",
        })

    existing: dict[str, dict] = {}
    triage_path = NLP / "triage_rules.csv"
    if triage_path.is_file():
        with triage_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                key = (row.get("hiligaynon_pattern") or "").lower()
                if key:
                    existing[key] = row
    for row in rows:
        key = (row.get("hiligaynon_pattern") or "").lower()
        if key:
            existing[key] = row
    return write_csv(triage_path, TRIAGE_FIELDS, list(existing.values()))


def export_step6_demo_exemplars() -> int:
    """Curated Step 6 triage demo phrases for nlp_step3_demo.php."""
    demo_phrases = [
        # NON-URGENT
        ("kakatol bilat ko", "non_urgent"),
        ("gapula mata ko kag gakatol", "non_urgent"),
        ("sakit ulo ko", "non_urgent"),
        ("galagas buhok ko", "non_urgent"),
        ("ubo ko", "non_urgent"),
        ("gakatol kamot ko", "non_urgent"),
        ("gapula mata ko", "non_urgent"),
        ("nahilo tiyan ko", "non_urgent"),
        ("nabun-og kamot ko", "non_urgent"),
        ("gahabok tudlo ko", "non_urgent"),
        # URGENT
        ("may nana sa bilat ko", "urgent"),
        ("may nana akon mata", "urgent"),
        ("gahabok itlog ko", "urgent"),
        ("gadugo bilat ko", "urgent"),
        ("masakit ari ko", "urgent"),
        ("ginahilanat ko kag gahika ko", "urgent"),
        ("ginahilanat ko 3 ka adlaw na", "urgent"),
        ("gahabok mata ko", "urgent"),
        ("gahubag kamot ko", "urgent"),
        ("masakit pag-ihi ko", "urgent"),
        ("ginabaldom gid ko", "urgent"),
        ("alta presyon ko", "urgent"),
        ("ginkagat sang ido ko", "urgent"),
        ("may pilas ko sa kamot kag nagdugo", "urgent"),
        ("nabali kamot ko", "urgent"),
        ("nasunog kamot ko sang mantika", "urgent"),
        ("gahubag tungod ko kag ginahilanat ko", "urgent"),
        ("namaga mata ko", "urgent"),
        ("gahabok dila ko", "urgent"),
        # EMERGENCY
        ("budlay magginhawa ko", "emergency"),
        ("masakit dughan ko kag dula ginhawa ko", "emergency"),
        ("grabe gid nagadugo bilat ko", "emergency"),
        ("nautod tudlo ko", "emergency"),
        ("nabunggo ko sa salakyan", "emergency"),
        ("nakuryente ko", "emergency"),
        ("daw indi ko makahambal", "emergency"),
        ("wala ko maka-ihi", "emergency"),
        ("gahubag lawas ko kag gakatol", "emergency"),
        ("ginsumbag ko kag nabun-og ulo ko", "emergency"),
        ("namaga gid dila ko", "emergency"),
        ("naguyam ko", "emergency"),
        ("gusto ko magpakamatay", "emergency"),
        ("nagdugo ulo ko", "emergency"),
        ("daw indi ko makabaton sang kamot ko", "emergency"),
        ("masakit dughan ko", "emergency"),
        ("gahubag ngabil ko", "emergency"),
    ]

    # Auto-sample more phrases per triage level from combinatorial engine
    buckets: dict[str, list[str]] = {"non_urgent": [], "urgent": [], "emergency": []}
    for hil in iter_template_phrases():
        matches = match_phrases(hil)
        if not matches:
            continue
        tri = str(matches[0].get("triage_level", "routine")).lower().replace("-", "_")
        if tri not in buckets:
            tri = "urgent" if tri in {"high", "routine"} else tri
        if tri in buckets and len(buckets[tri]) < STEP6_PER_TRIAGE:
            if hil not in buckets[tri]:
                buckets[tri].append(hil)
        if all(len(v) >= STEP6_PER_TRIAGE for v in buckets.values()):
            break

    for tri, phrases in buckets.items():
        for hil in phrases:
            demo_phrases.append((hil, tri))

    rows = []
    seen_hil: set[str] = set()
    for hil, expected in demo_phrases:
        if hil in seen_hil:
            continue
        seen_hil.add(hil)
        matches = match_phrases(hil)
        if not matches:
            continue
        m = matches[0]
        rows.append({
            "hiligaynon_term": hil,
            "english_term": m.get("english_term", ""),
            "medical_category": m.get("medical_category", "general"),
            "severity": m.get("severity", "Moderate"),
            "triage_level": m.get("triage_level", expected),
            "expected_triage": expected,
            "status": "active",
        })
    path = NLP / "step6_triage_exemplars.csv"
    fields = PHRASE_FIELDS + ["expected_triage"]
    count = write_csv(path, fields, rows)
    print(f"  step6_triage_exemplars.csv: {count} Step 6 demo phrases")
    return count


def export_exemplar_phrases() -> int:
    """Multi-root exemplar seed file across all symptom types."""
    templates = json.loads((ENGINE / "templates.json").read_text(encoding="utf-8"))
    possessives = templates.get("possessives", ["ko", "akon"])
    intensifiers = ["", "gid", "grabe"]
    rows: list[dict] = []
    seen: set[str] = set()

    priority_roots = ["swelling", "pain", "itching", "bleeding", "pus_infection", "fever", "cough", "burn"]
    roots = [r for r in symptom_roots() if r.get("id") in priority_roots]
    roots += [r for r in symptom_roots() if r.get("id") not in priority_roots]

    for root in roots:
        terms = (root.get("terms") or [])[:4]
        for term, part, poss, intens in itertools.product(
            terms, body_parts(), possessives[:3], intensifiers[:3]
        ):
            parts = [term]
            if intens:
                parts.append(intens)
            parts.append(part.get("hil", ""))
            if poss:
                parts.append(poss)
            hil = " ".join(p for p in parts if p).strip()
            if hil in seen:
                continue
            seen.add(hil)
            matches = match_phrases(hil)
            if not matches:
                continue
            m = matches[0]
            rows.append({
                "hiligaynon_term": hil,
                "english_term": m.get("english_term", ""),
                "medical_category": m.get("medical_category", "general"),
                "severity": m.get("severity", "Moderate"),
                "triage_level": m.get("triage_level", "urgent"),
                "status": "active",
            })
            if len(rows) >= 2500:
                break
        if len(rows) >= 2500:
            break

    seed_path = NLP / "symptom_phrases_seed.csv"
    count = write_csv(seed_path, PHRASE_FIELDS, rows)
    print(f"  symptom_phrases_seed.csv: {count} exemplars")
    return count


def export_dictionary_entries() -> int:
    """Append combinatorial roots to medical_dictionary (compact entries only)."""
    dict_path = NLP / "medical_dictionary.csv"
    existing: dict[str, dict] = {}
    next_id = 1
    if dict_path.is_file():
        with dict_path.open(encoding="utf-8", newline="") as f:
            for row in csv.DictReader(f):
                key = (row.get("local_term") or "").lower()
                if key:
                    existing[key] = row
                    try:
                        next_id = max(next_id, int(row.get("dictionary_id") or 0) + 1)
                    except ValueError:
                        pass

    for root in symptom_roots():
        for term in root.get("terms", []):
            key = term.lower()
            if key in existing:
                continue
            existing[key] = {
                "dictionary_id": str(next_id),
                "local_term": term,
                "english_term": root.get("english_symptom", ""),
                "category": "symptom",
            }
            next_id += 1

    for part in body_parts():
        for hil in [part.get("hil", ""), f"{part.get('hil', '')} ko"]:
            key = hil.lower()
            if key in existing:
                continue
            existing[key] = {
                "dictionary_id": str(next_id),
                "local_term": hil,
                "english_term": part.get("eng", ""),
                "category": "anatomy",
            }
            next_id += 1

    rows = list(existing.values())
    rows.sort(key=lambda r: int(r.get("dictionary_id") or 0))
    return write_csv(dict_path, DICT_FIELDS, rows)


def export_hiligaynon_symptoms() -> int:
    rows = []
    for root in symptom_roots():
        for term in root.get("terms", []):
            rows.append({
                "hiligaynon_term": f"{term} ko",
                "english_term": root.get("english_phrase", root.get("english_symptom", "")),
                "medical_category": root.get("category", "general"),
                "severity": root.get("default_severity", "moderate").capitalize(),
                "triage_level": root.get("default_triage", "routine"),
                "status": "active",
            })
    sym_path = NLP / "hiligaynon_symptoms_combinatorial.csv"
    return write_csv(sym_path, SYMPTOM_FIELDS, rows)


def verify_samples() -> None:
    samples = [
        "gahabok mata ko",
        "gahubag kamot ko",
        "gahbok mata ko",
        "may nana sa bilat ko",
        "namaga gid dila ko",
        "budlay magginhawa ko",
        "kakatol bilat ko",
        "gahabok tudlo ko",
    ]
    print("\nSample combinatorial matches:")
    for s in samples:
        corrected = apply_misspelling_corrections(s)
        matches = match_phrases(s)
        eng = matches[0]["english_term"] if matches else "—"
        tri = matches[0]["triage_level"] if matches else "—"
        print(f"  {s!r} -> {eng!r} [{tri}]" + (f" (corrected: {corrected})" if corrected != s.lower() else ""))


def main() -> None:
    print("Combinatorial Hiligaynon NLP dataset generator")
    print(f"Engine rules: {ENGINE}")
    stats = estimate_combination_count()
    print("\nTheoretical coverage:")
    for k, v in stats.items():
        print(f"  {k}: {v:,}" if isinstance(v, int) else f"  {k}: {v}")

    print("\nExporting compact reference CSVs...")
    print(f"  body_parts.csv: {export_body_parts()} rows")
    print(f"  medical_misspellings.csv: {export_misspellings()} rows")
    print(f"  symptom_synonyms.csv: {export_synonyms()} rows")
    print(f"  triage_rules.csv: {export_triage_rules()} rows")
    print(f"  medical_dictionary.csv: {export_dictionary_entries()} rows")
    print(f"  hiligaynon_symptoms_combinatorial.csv: {export_hiligaynon_symptoms()} rows")
    export_hiligaynon_conditions()
    export_combinatorial_phrases()
    export_step6_demo_exemplars()
    export_exemplar_phrases()
    verify_samples()
    print("\nDone. Runtime matching: ai_service/phrase_combinatorial_engine.py")


if __name__ == "__main__":
    main()
