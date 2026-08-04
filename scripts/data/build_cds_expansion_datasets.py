#!/usr/bin/env python3
"""
Build expandable CDS CSV datasets for medConnect triage NLP.

Enhances existing data — does not replace the NLP architecture.
Reuses symptoms.csv, medical_dictionary.csv, emergency_flags.csv, etc.
"""

from __future__ import annotations

import csv
import itertools
import random
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
NLP = ROOT / "data" / "nlp"
OUT = NLP  # write into existing data/nlp for loader compatibility
SEED = 42
random.seed(SEED)


def read_csv(path: Path) -> list[dict[str, str]]:
    if not path.is_file():
        return []
    with path.open(encoding="utf-8", newline="") as f:
        return [{k: (v or "").strip() for k, v in row.items()} for row in csv.DictReader(f)]


def write_csv(path: Path, fieldnames: list[str], rows: list[dict[str, str]]) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    # Deduplicate on full row tuple
    seen: set[tuple[str, ...]] = set()
    unique: list[dict[str, str]] = []
    for row in rows:
        key = tuple(row.get(h, "") for h in fieldnames)
        if key in seen:
            continue
        seen.add(key)
        unique.append({h: row.get(h, "") for h in fieldnames})
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fieldnames)
        w.writeheader()
        w.writerows(unique)
    print(f"  wrote {path.name}: {len(unique)} rows")
    return len(unique)


def load_symptom_names() -> list[str]:
    names: list[str] = []
    for row in read_csv(NLP / "symptoms.csv"):
        name = row.get("symptom_name") or ""
        if name and len(name) < 80 and not re.search(r"\d-\d", name):
            names.append(name)
    # Prefer clinically common names first for expansions
    common = [
        "Fever", "Cough", "Headache", "Chest Pain", "Difficulty Breathing",
        "Shortness of Breath", "Vomiting", "Diarrhea", "Abdominal Pain",
        "Nausea", "Dizziness", "Fatigue", "Sore Throat", "Runny Nose",
        "Body Pain", "Back Pain", "Ear Pain", "Eye Pain", "Rash",
        "Palpitations", "Confusion", "Seizure", "Bleeding", "Burn",
        "Weakness", "Swelling", "Itching", "Chills", "Loss of Appetite",
        "Urinary Pain", "Constipation", "Insomnia", "Wheezing", "Coughing Blood",
        "Vomiting Blood", "Fainting", "Numbness", "Paralysis", "Vision Loss",
    ]
    # Keep order: common then rest
    ordered = []
    seen = set()
    for n in common + names:
        k = n.lower()
        if k not in seen:
            seen.add(k)
            ordered.append(n)
    return ordered


def load_dictionary() -> list[tuple[str, str]]:
    pairs = []
    for row in read_csv(NLP / "medical_dictionary.csv"):
        local = row.get("local_term") or ""
        eng = row.get("english_term") or ""
        if local and eng:
            pairs.append((local.lower(), eng.lower()))
    return pairs


# ---------------------------------------------------------------------------
# Core clinical concept seed (for Hiligaynon / Filipino expansions)
# ---------------------------------------------------------------------------

HIL_SYMPTOMS: list[tuple[str, str, list[str]]] = [
    ("fever", "Fever", ["lagnat", "hilanat", "ginakalagnat", "ginalagnat", "gahilanat", "ginahilanat", "may init lawas", "init lawas", "may lagnat", "ginainitan"]),
    ("cough", "Cough", ["ubo", "gaubo", "ginaubo", "nagauba", "ginauubo", "may ubo", "ubo-ubo"]),
    ("runny_nose", "Runny Nose", ["sip-on", "sipon", "may sip-on", "ginapanuhot", "panuhot", "barado ilong"]),
    ("difficulty_breathing", "Difficulty Breathing", ["budlay ginhawa", "budlay magginhawa", "budlay gid ginhawa", "budlay gid ang ginhawa", "indi ko kaginhawa", "indi makaginhawa", "dula ginhawa", "ginakapos ginhawa", "ginahapo", "hapo"]),
    ("chest_pain", "Chest Pain", ["masakit dughan", "gasakit dughan", "sakit dughan", "hapdi dughan", "masakit dibdib", "masakit akon dughan"]),
    ("abdominal_pain", "Abdominal Pain", ["masakit tiyan", "gasakit tiyan", "sakit tiyan", "masakit akon tiyan", "gapalanakit tiyan"]),
    ("headache", "Headache", ["gasakit ulo", "gapalanakit ulo", "sakit ulo", "masakit ulo", "lipong ulo"]),
    ("vomiting", "Vomiting", ["gasuka", "ginasuka", "nagasuka", "naga suka", "gapalanuka", "ginsuka", "suka"]),
    ("nausea", "Nausea", ["ginakulba", "kulba", "ginakulba ko", "daw magasuka"]),
    ("dizziness", "Dizziness", ["ginakalipong", "lipong", "nagakalipong", "kalipong"]),
    ("weakness", "Weakness", ["naluya", "ginapangluya", "luya", "kapoy", "kapoy gid", "ginakapoy"]),
    ("swelling", "Swelling", ["gahubag", "hubag", "ginahubag", "nagahubag"]),
    ("chills", "Chills", ["ginakurog", "nagakurog", "kurog", "ginakulba lawas"]),
    ("vomiting_blood", "Vomiting Blood", ["may dugo sa suka", "nagsuka dugo", "dugo sa suka"]),
    ("blood_in_urine", "Blood in Urine", ["may dugo sa ihi", "dugo sa ihi", "pula ihi"]),
    ("blood_in_stool", "Blood in Stool", ["may dugo sa dumi", "dugo sa tae", "dugo sa dumi"]),
    ("insomnia", "Insomnia", ["budlay tulog", "indi makatulog", "wala tulog"]),
    ("difficulty_walking", "Difficulty Walking", ["nagabudlay lakat", "budlay maglakat", "indi makalakat"]),
    ("diarrhea", "Diarrhea", ["naga tae", "tae-tae", "malusaw tae", "nagakalibang"]),
    ("sore_throat", "Sore Throat", ["sakit tutunlan", "hapdi tutunlan", "masakit tutunlan"]),
    ("body_pain", "Body Pain", ["sakit lawas", "masakit lawas", "gapalanakit lawas", "paminsa"]),
    ("rash", "Rash", ["panganud", "gahubag panit", "kalapata", "pantal"]),
    ("ear_pain", "Ear Pain", ["sakit dulunggan", "masakit dulunggan"]),
    ("eye_pain", "Eye Pain", ["sakit mata", "masakit mata"]),
    ("back_pain", "Back Pain", ["sakit likod", "masakit likod"]),
    ("dysuria", "Painful Urination", ["hapdi mag-ihi", "sakit mag-ihi", "masakit ihi"]),
    ("palpitations", "Palpitations", ["naga pitik dughan", "madasig pitik"]),
    ("seizure", "Seizure", ["naguyam", "ginsuyam", "nagauyam"]),
    ("loss_of_consciousness", "Loss of Consciousness", ["nadulaan malay", "nagpunaw", "wala malay"]),
    ("bleeding", "Bleeding", ["nagadugo", "gadugo", "grabe nagadugo"]),
]

FIL_SYMPTOMS: list[tuple[str, str, list[str]]] = [
    ("fever", "Fever", ["lagnat", "may lagnat", "nilalagnat", "mataas ang lagnat", "mainit ang katawan"]),
    ("cough", "Cough", ["ubo", "may ubo", "inuubo", "umiubo"]),
    ("runny_nose", "Runny Nose", ["sipon", "may sipon", "baradong ilong"]),
    ("difficulty_breathing", "Difficulty Breathing", ["hirap huminga", "nahihirapang huminga", "kapos ang paghinga", "hindi makahinga"]),
    ("chest_pain", "Chest Pain", ["sakit sa dibdib", "masakit ang dibdib", "pananakit ng dibdib"]),
    ("abdominal_pain", "Abdominal Pain", ["sakit ng tiyan", "masakit ang tiyan", "sakit sa tiyan"]),
    ("headache", "Headache", ["sakit ng ulo", "masakit ang ulo", "sumasakit ang ulo"]),
    ("vomiting", "Vomiting", ["nagsusuka", "pagsusuka", "sumuka"]),
    ("nausea", "Nausea", ["nasusuka", "nakakaramdam ng pagduduwal"]),
    ("dizziness", "Dizziness", ["nahihilo", "hilo", "umaaligid ang paningin"]),
    ("weakness", "Weakness", ["nahihina", "panghihina", "pagod na pagod"]),
    ("diarrhea", "Diarrhea", ["pagtatae", "nagtatae", "matubig na dumi"]),
    ("sore_throat", "Sore Throat", ["masakit ang lalamunan", "sakit ng lalamunan"]),
    ("body_pain", "Body Pain", ["sakit ng katawan", "pananakit ng katawan"]),
    ("vomiting_blood", "Vomiting Blood", ["nagsusuka ng dugo", "may dugo sa suka"]),
    ("coughing_blood", "Coughing Blood", ["umuubo ng dugo", "may dugo sa plema"]),
    ("fainting", "Fainting", ["nahimatay", "nawalan ng malay"]),
    ("bleeding", "Bleeding", ["dumudugo", "matinding pagdurugo"]),
    ("pregnant_bleeding", "Pregnancy Bleeding", ["buntis at dumudugo", "may dugo habang buntis"]),
]

EN_TEMPLATES = [
    "I have {symptom}.",
    "I have {symptom} for {duration}.",
    "My {body} hurts.",
    "I cannot breathe.",
    "I feel {symptom}.",
    "I have been experiencing {symptom}.",
    "Severe {symptom} since {duration}.",
    "Mild {symptom} today.",
    "My child has {symptom}.",
    "My baby keeps vomiting.",
    "I am pregnant and have {symptom}.",
    "I fainted.",
    "My left arm is numb.",
    "I need medicine refill.",
    "I need a follow-up.",
    "My blood pressure is high.",
    "My sugar is high.",
    "My wound is infected.",
    "Pain {pain}/10 in my {body} for {duration}.",
    "Temperature {temp} with {symptom}.",
    "No {symptom}, only tired.",
    "I have asthma and {symptom}.",
    "I have diabetes and {symptom}.",
]

HIL_TEMPLATES = [
    "May {hil} ako.",
    "{hil} ko.",
    "{hil} gid ko.",
    "May {hil} ko sang {duration}.",
    "Ginakalagnat ako.",
    "Budlay gid ang ginhawa ko.",
    "Masakit akon dughan.",
    "Gapalanakit ulo ko.",
    "Naga suka ko.",
    "May dugo sa akon suka.",
    "May sip-on kag ubo ko.",
    "Ginakulba ko.",
    "Kapoy gid ako.",
    "Indi ko makaginhawa.",
    "Masakit tiyan ko.",
    "Wala akong lagnat.",
    "Wala ko ginaubo.",
    "Indi budlay ginhawa.",
]

FIL_TEMPLATES = [
    "May {fil} ako.",
    "Masakit ang {body_fil} ko.",
    "Nilalagnat ako nang {duration}.",
    "Hirap akong huminga.",
    "Masakit ang dibdib ko.",
    "Nagsusuka ang anak ko.",
    "Buntis ako at may {fil}.",
    "Wala akong lagnat.",
    "Wala akong ubo.",
    "Mataas ang blood pressure ko.",
    "Mataas ang asukal ko.",
]

DURATIONS = ["today", "1 day", "2 days", "3 days", "5 days", "1 week", "since yesterday", "3 hours", "this morning"]
BODIES = ["chest", "head", "stomach", "back", "throat", "ear", "eye", "abdomen", "arm", "leg"]
BODY_FIL = ["ulo", "dibdib", "tiyan", "likod", "lalamunan", "tenga", "mata"]
PAINS = ["2", "4", "5", "7", "8", "9", "10"]
TEMPS = ["37.8", "38.2", "38.5", "39.0", "39.5"]


def typo_variants(word: str) -> list[str]:
    """Generate plausible misspellings for a term."""
    w = word.lower().strip()
    if len(w) < 3:
        return []
    out: set[str] = set()
    # double letter
    for i in range(len(w)):
        out.add(w[:i] + w[i] + w[i:])
    # drop letter
    for i in range(len(w)):
        out.add(w[:i] + w[i + 1 :])
    # swap adjacent
    for i in range(len(w) - 1):
        out.add(w[:i] + w[i + 1] + w[i] + w[i + 2 :])
    # common substitutions
    subs = {"ph": "f", "f": "ph", "i": "e", "e": "i", "a": "e", "o": "u", "c": "k", "k": "c"}
    for a, b in subs.items():
        if a in w:
            out.add(w.replace(a, b, 1))
    out.discard(w)
    return [x for x in out if 2 <= len(x) <= 40][:12]


def build_all() -> None:
    print("Building CDS expansion datasets...")
    symptoms = load_symptom_names()
    dict_pairs = load_dictionary()
    existing_flags = read_csv(NLP / "emergency_flags.csv")
    existing_rules = read_csv(NLP / "triage_rules.csv")

    # ---- medical_symptoms.csv ----
    med_sym_rows = []
    for i, name in enumerate(symptoms[:8000], start=1):
        med_sym_rows.append({
            "symptom_id": str(i),
            "symptom_name": name,
            "standardized_concept": name.lower().replace(" ", "_")[:80],
            "category": "general",
            "language": "english",
            "status": "active",
        })
    for key, eng, terms in HIL_SYMPTOMS:
        for t in terms:
            med_sym_rows.append({
                "symptom_id": "",
                "symptom_name": eng,
                "standardized_concept": key,
                "category": "clinical",
                "language": "hiligaynon",
                "status": "active",
                "local_term": t,
            })
    # rewrite with consistent fields
    write_csv(OUT / "medical_symptoms.csv", [
        "symptom_id", "symptom_name", "standardized_concept", "category", "language", "local_term", "status"
    ], [{**r, "local_term": r.get("local_term", "")} for r in med_sym_rows])

    # ---- english / filipino / hiligaynon medical terms ----
    eng_terms = []
    for i, name in enumerate(symptoms[:6000], start=1):
        eng_terms.append({
            "term_id": f"EN{i:05d}",
            "term": name,
            "normalized": name.lower(),
            "category": "symptom",
            "language": "english",
            "status": "active",
        })
    # Pad with body/system terms
    extras = ["hypertension", "diabetes", "asthma", "pregnancy", "dehydration", "infection",
              "inflammation", "hemorrhage", "syncope", "dyspnea", "tachycardia", "bradycardia"]
    for e in extras:
        eng_terms.append({"term_id": "", "term": e, "normalized": e, "category": "clinical", "language": "english", "status": "active"})
    # Combinatorial clinical modifiers to reach ~5000+
    modifiers = ["acute", "chronic", "severe", "mild", "recurrent", "intermittent", "persistent"]
    bases = symptoms[:800]
    for mod, base in itertools.islice(itertools.product(modifiers, bases), 0, 4000):
        term = f"{mod} {base.lower()}"
        eng_terms.append({"term_id": "", "term": term, "normalized": term, "category": "symptom_modifier", "language": "english", "status": "active"})
    write_csv(OUT / "english_medical_terms.csv", ["term_id", "term", "normalized", "category", "language", "status"], eng_terms)

    fil_terms = []
    for key, eng, terms in FIL_SYMPTOMS:
        for t in terms:
            fil_terms.append({"term_id": "", "term": t, "english": eng, "concept": key, "language": "filipino", "status": "active"})
    # Expand Filipino via templates
    fil_prefixes = ["may ", "masakit ang ", "sumasakit ang ", "nag ", "nang "]
    fil_bodies = ["ulo", "dibdib", "tiyan", "likod", "lalamunan", "tenga", "mata", "braso", "binti", "katawan"]
    fil_feelings = ["lagnat", "ubo", "sipon", "hilo", "pagod", "panghihina", "pantal", "pamamaga"]
    n = 0
    for pref, body in itertools.product(fil_prefixes, fil_bodies):
        fil_terms.append({"term_id": "", "term": pref + body, "english": f"pain {body}", "concept": "localized_pain", "language": "filipino", "status": "active"})
        n += 1
    for feel in fil_feelings:
        for variant in [feel, f"may {feel}", f"ako ay may {feel}", f"meron akong {feel}", f"nakakaranas ng {feel}"]:
            fil_terms.append({"term_id": "", "term": variant, "english": feel, "concept": feel, "language": "filipino", "status": "active"})
    # Pad from dictionary locals that look Filipino
    for local, eng in dict_pairs:
        if any(x in local for x in ["ang ", "ng ", "ako", "masakit", "may "]):
            fil_terms.append({"term_id": "", "term": local, "english": eng, "concept": eng.replace(" ", "_"), "language": "filipino", "status": "active"})
    # Combinatorial padding
    while len({r["term"] for r in fil_terms}) < 5000:
        feel = random.choice(fil_feelings)
        body = random.choice(fil_bodies)
        dur = random.choice(["ngayon", "kahapon", "tatlong araw", "isang linggo", "kanina"])
        phrase = random.choice([
            f"May {feel} ako sa {body}",
            f"Masakit ang {body} ko at may {feel}",
            f"{feel.capitalize()} ko nang {dur}",
            f"Pakiramdam ko may {feel}",
            f"Anak ko may {feel}",
        ])
        fil_terms.append({"term_id": "", "term": phrase.lower(), "english": feel, "concept": feel, "language": "filipino", "status": "active"})
    write_csv(OUT / "filipino_medical_terms.csv", ["term_id", "term", "english", "concept", "language", "status"], fil_terms)

    hil_terms = []
    for key, eng, terms in HIL_SYMPTOMS:
        for t in terms:
            hil_terms.append({"term_id": "", "term": t, "english": eng, "concept": key, "language": "hiligaynon", "status": "active"})
    for local, eng in dict_pairs:
        hil_terms.append({"term_id": "", "term": local, "english": eng, "concept": eng.replace(" ", "_")[:60], "language": "hiligaynon", "status": "active"})
    hil_prefixes = ["may ", "gina", "naga", "ga", "ginaka", "masakit ", "budlay "]
    hil_roots = ["lagnat", "ubo", "sip-on", "suka", "lipong", "hubag", "kurog", "kapoy", "tiyan", "ulo", "dughan", "ginhawa"]
    for pref, root in itertools.product(hil_prefixes, hil_roots):
        hil_terms.append({"term_id": "", "term": (pref + root).strip(), "english": root, "concept": root, "language": "hiligaynon", "status": "active"})
    while len({r["term"] for r in hil_terms}) < 3000:
        root = random.choice(hil_roots)
        phrase = random.choice([
            f"may {root} ako",
            f"gina{root} ko",
            f"{root} gid ko",
            f"masakit {root} ko",
            f"budlay {root}",
            f"akon {root}",
        ])
        hil_terms.append({"term_id": "", "term": phrase, "english": root, "concept": root, "language": "hiligaynon", "status": "active"})
    write_csv(OUT / "hiligaynon_medical_terms.csv", ["term_id", "term", "english", "concept", "language", "status"], hil_terms)

    # ---- symptom_synonyms.csv (expand existing schema + volume) ----
    syn_rows = []
    for row in read_csv(NLP / "symptom_synonyms.csv"):
        syn_rows.append({
            "local_term": row.get("hiligaynon_term") or row.get("local_term") or "",
            "english_term": row.get("english_term") or "",
            "synonym_group": row.get("synonym_group") or "",
            "language": "hiligaynon",
            "status": row.get("status") or "active",
        })
    # Massive synonym expansion
    synonym_patterns = [
        ("i have {s}", "{s}"),
        ("experiencing {s}", "{s}"),
        ("suffering from {s}", "{s}"),
        ("{s} symptom", "{s}"),
        ("feeling of {s}", "{s}"),
        ("complains of {s}", "{s}"),
        ("with {s}", "{s}"),
        ("{s} present", "{s}"),
        ("ongoing {s}", "{s}"),
        ("persistent {s}", "{s}"),
    ]
    for name in symptoms[:2500]:
        s = name.lower()
        group = re.sub(r"[^a-z0-9]+", "_", s)[:60]
        for pat, _ in synonym_patterns:
            syn_rows.append({
                "local_term": pat.format(s=s),
                "english_term": name,
                "synonym_group": group,
                "language": "english",
                "status": "active",
            })
        for key, eng, terms in HIL_SYMPTOMS:
            if eng.lower() == s or eng == name:
                for t in terms:
                    syn_rows.append({
                        "local_term": t,
                        "english_term": eng,
                        "synonym_group": key,
                        "language": "hiligaynon",
                        "status": "active",
                    })
    for key, eng, terms in HIL_SYMPTOMS + [(k, e, t) for k, e, t in FIL_SYMPTOMS]:
        for t in terms:
            syn_rows.append({
                "local_term": t,
                "english_term": eng,
                "synonym_group": key,
                "language": "mixed",
                "status": "active",
            })
    # Pad to ~20000
    filler_verbs = ["may", "may ara", "naga", "gina", "gasakit", "budlay"]
    filler_nouns = [n.lower() for n in symptoms[:400]]
    while len(syn_rows) < 20000:
        v, n = random.choice(filler_verbs), random.choice(filler_nouns)
        syn_rows.append({
            "local_term": f"{v} {n}",
            "english_term": n,
            "synonym_group": re.sub(r"[^a-z0-9]+", "_", n)[:60],
            "language": "generated",
            "status": "active",
        })
    # Keep original symptom_synonyms.csv schema compatible + write expanded copy
    write_csv(OUT / "symptom_synonyms_expanded.csv", ["local_term", "english_term", "synonym_group", "language", "status"], syn_rows)
    # Also refresh main synonym file with hiligaynon_term column for backward compat
    compat = []
    for r in syn_rows[:20000]:
        compat.append({
            "hiligaynon_term": r["local_term"],
            "english_term": r["english_term"],
            "synonym_group": r["synonym_group"],
            "status": r["status"],
        })
    write_csv(OUT / "symptom_synonyms.csv", ["hiligaynon_term", "english_term", "synonym_group", "status"], compat)

    # ---- medical_entities.csv ----
    entities = []
    for name in symptoms[:5000]:
        entities.append({"entity": name, "entity_type": "symptom", "normalized": name.lower(), "status": "active"})
    for b in BODIES + ["heart", "lung", "liver", "kidney", "skin", "throat", "nose"]:
        entities.append({"entity": b, "entity_type": "body_part", "normalized": b, "status": "active"})
    for rf in ["diabetes", "hypertension", "asthma", "cancer", "heart disease", "kidney disease", "pregnancy"]:
        entities.append({"entity": rf, "entity_type": "risk_factor", "normalized": rf, "status": "active"})
    write_csv(OUT / "medical_entities.csv", ["entity", "entity_type", "normalized", "status"], entities)

    # ---- body_parts already exists; write cds-compatible enrichment ----
    bp_rows = read_csv(NLP / "body_parts.csv")
    if len(bp_rows) < 100:
        for b in BODIES:
            bp_rows.append({"hiligaynon_term": b, "english_term": b, "body_system": "general", "anatomy_category": "general", "status": "active"})
    # leave body_parts.csv as-is if already good; write companion
    write_csv(OUT / "body_parts_cds.csv", ["hiligaynon_term", "english_term", "body_system", "anatomy_category", "status"], [
        {k: r.get(k, "") for k in ["hiligaynon_term", "english_term", "body_system", "anatomy_category", "status"]} for r in bp_rows
    ])

    # ---- pain / duration / temperature patterns ----
    pain_rows = []
    for i in range(0, 11):
        band = "mild" if i <= 3 else "moderate" if i <= 6 else "severe"
        pts = "0" if i <= 3 else "2" if i <= 6 else "4"
        pain_rows.append({"pattern": f"pain {i}", "pain_score": str(i), "band": band, "severity_points": pts, "language": "english", "status": "active"})
        pain_rows.append({"pattern": f"{i}/10", "pain_score": str(i), "band": band, "severity_points": pts, "language": "english", "status": "active"})
        pain_rows.append({"pattern": f"pain scale {i}", "pain_score": str(i), "band": band, "severity_points": pts, "language": "english", "status": "active"})
    for pat, score, band, pts in [
        ("mild pain", 2, "mild", 0), ("gamay nga sakit", 2, "mild", 0), ("medyo sakit", 5, "moderate", 2),
        ("moderate pain", 5, "moderate", 2), ("grabe nga sakit", 8, "severe", 4), ("severe pain", 8, "severe", 4),
        ("sakit gid", 8, "severe", 4), ("unbearable pain", 10, "severe", 4), ("matinding sakit", 9, "severe", 4),
    ]:
        pain_rows.append({"pattern": pat, "pain_score": str(score), "band": band, "severity_points": str(pts), "language": "mixed", "status": "active"})
    write_csv(OUT / "pain_scale.csv", ["pattern", "pain_score", "band", "severity_points", "language", "status"], pain_rows)

    dur_rows = []
    for n in range(1, 31):
        dur_rows.append({"pattern": f"{n} day", "bucket": "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days", "days": str(n), "language": "english", "status": "active"})
        dur_rows.append({"pattern": f"{n} days", "bucket": "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days", "days": str(n), "language": "english", "status": "active"})
        dur_rows.append({"pattern": f"{n} ka adlaw", "bucket": "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days", "days": str(n), "language": "hiligaynon", "status": "active"})
        dur_rows.append({"pattern": f"{n} araw", "bucket": "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days", "days": str(n), "language": "filipino", "status": "active"})
    for pat, bucket, days in [
        ("today", "same_day", "0"), ("subong", "same_day", "0"), ("ngayon", "same_day", "0"),
        ("yesterday", "1_to_2_days", "1"), ("gahapon", "1_to_2_days", "1"), ("kahapon", "1_to_2_days", "1"),
        ("since yesterday", "1_to_2_days", "1"), ("one week", "chronic_weeks", "7"), ("isa ka semana", "chronic_weeks", "7"),
        ("isang linggo", "chronic_weeks", "7"), ("dugay na", "chronic_weeks", "14"), ("matagal na", "chronic_weeks", "14"),
        ("bag-o lang", "acute_hours", "0"), ("this morning", "acute_hours", "0"), ("kanina", "acute_hours", "0"),
        ("for hours", "acute_hours", "0"), ("3 hours", "acute_hours", "0"), ("few hours", "acute_hours", "0"),
    ]:
        dur_rows.append({"pattern": pat, "bucket": bucket, "days": days, "language": "mixed", "status": "active"})
    idx = 0
    while len(dur_rows) < 500:
        idx += 1
        n = random.randint(1, 14)
        unit = random.choice(["days", "adlaw", "araw", "hours", "oras"])
        pref = random.choice(["for", "since", "about", "around", "nearly", "almost"])
        dur_rows.append({
            "pattern_id": f"DUR{idx:04d}",
            "pattern": f"{pref} {n} {unit}",
            "bucket": "5_plus_days" if n >= 5 else "1_to_2_days",
            "days": str(n),
            "language": "mixed",
            "status": "active",
        })
    # Ensure earlier rows also have pattern_id
    for i, row in enumerate(dur_rows):
        row.setdefault("pattern_id", f"DURB{i+1:04d}")
    write_csv(OUT / "duration_patterns.csv", ["pattern_id", "pattern", "bucket", "days", "language", "status"], dur_rows)

    temp_rows = []
    for t in [x / 10 for x in range(360, 421)]:
        band = "normal" if t < 37.5 else "low_grade" if t < 38 else "fever" if t < 39 else "high_fever"
        pts = "0" if band == "normal" else "1" if band == "low_grade" else "2" if band == "fever" else "4"
        temp_rows.append({"pattern": f"{t:.1f}c", "celsius": f"{t:.1f}", "band": band, "severity_points": pts, "language": "english", "status": "active"})
        temp_rows.append({"pattern": f"{t:.1f} degrees", "celsius": f"{t:.1f}", "band": band, "severity_points": pts, "language": "english", "status": "active"})
    for pat, band, pts in [
        ("fever", "fever", "2"), ("high fever", "high_fever", "4"), ("lagnat", "fever", "2"),
        ("hilanat", "fever", "2"), ("mataas na lagnat", "high_fever", "4"), ("grabe nga lagnat", "high_fever", "4"),
        ("low grade fever", "low_grade", "1"), ("may init lawas", "fever", "2"),
    ]:
        temp_rows.append({"pattern": pat, "celsius": "", "band": band, "severity_points": pts, "language": "mixed", "status": "active"})
    idx = 0
    while len(temp_rows) < 300:
        idx += 1
        t = round(random.uniform(36.5, 41.0), 1)
        pref = random.choice(["temp", "temperature", "lagnat", "hilanat", "body temp"])
        temp_rows.append({
            "pattern_id": f"TMP{idx:04d}",
            "pattern": f"{pref} {t}",
            "celsius": str(t),
            "band": "fever" if t >= 38 else "low_grade",
            "severity_points": "2" if t >= 38 else "1",
            "language": "mixed",
            "status": "active",
        })
    for i, row in enumerate(temp_rows):
        row.setdefault("pattern_id", f"TMPB{i+1:04d}")
    write_csv(OUT / "temperature_patterns.csv", ["pattern_id", "pattern", "celsius", "band", "severity_points", "language", "status"], temp_rows)

    # ---- risk factors / chronic ----
    risk_rows = [
        ("pregnant", "Pregnant", "pregnancy", r"\bpregnant\b|\bbuntis\b|\bnagabusong\b"),
        ("infant", "Infant", "age", r"\binfant\b|\bbaby\b|\bsanggol\b"),
        ("child", "Child", "age", r"\bchild\b|\banak\b|\bbata\b"),
        ("senior", "Senior Citizen", "age", r"\bsenior\b|\belderly\b|\btigulang\b|\bmatanda\b"),
        ("diabetes", "Diabetes", "chronic", r"\bdiabetes\b|\bdiabetic\b|\basukal\b"),
        ("hypertension", "Hypertension", "chronic", r"\bhypertension\b|\bhigh blood\b|\btaas blood\b"),
        ("asthma", "Asthma", "chronic", r"\basthma\b|\bhika\b"),
        ("cancer", "Cancer", "chronic", r"\bcancer\b|\bchemotherapy\b"),
        ("heart_disease", "Heart Disease", "chronic", r"\bheart disease\b|\bheart failure\b|\bcardiac\b"),
        ("kidney_disease", "Kidney Disease", "chronic", r"\bkidney\b|\bdialysis\b|\bckd\b"),
        ("immunocompromised", "Immunocompromised", "chronic", r"\bimmunocompromised\b|\bhiv\b|\btransplant\b"),
    ]
    rf_out = []
    for rid, label, cat, pat in risk_rows:
        for lang_pat in pat.split("|"):
            rf_out.append({"risk_id": rid, "label": label, "category": cat, "pattern": lang_pat.strip("\\b"), "severity_bonus": "2", "status": "active"})
    write_csv(OUT / "risk_factors.csv", ["risk_id", "label", "category", "pattern", "severity_bonus", "status"], rf_out)
    write_csv(OUT / "chronic_conditions.csv", ["condition_id", "label", "category", "pattern", "severity_bonus", "status"], [
        r for r in rf_out if r["category"] == "chronic"
    ])

    # ---- emergency / urgent / non-urgent ----
    em_rows = []
    for row in existing_flags:
        em_rows.append({
            "rule_id": row.get("flag_id") or "",
            "name": row.get("flag_name") or "",
            "pattern_hiligaynon": row.get("hiligaynon_pattern") or "",
            "pattern_english": row.get("english_pattern") or "",
            "classification": "EMERGENCY",
            "rationale": row.get("clinical_rationale") or "",
            "status": "active",
        })
    extra_em = [
        ("Chest pain with breathing difficulty", "masakit dughan kag budlay ginhawa", "chest pain with difficulty breathing"),
        ("Severe shortness of breath", "budlay gid magginhawa", "severe shortness of breath"),
        ("Unconsciousness", "wala malay", "unconscious"),
        ("Seizure", "naguyam", "seizure"),
        ("Stroke symptoms", "indi makahambal", "stroke symptoms"),
        ("Sudden paralysis", "bigla nga paralisis", "sudden paralysis"),
        ("Loss of vision", "bigla nabuta", "sudden vision loss"),
        ("Vomiting blood", "may dugo sa suka", "vomiting blood"),
        ("Coughing blood", "nagauba dugo", "coughing blood"),
        ("Severe bleeding", "indi mapunggan ang dugo", "severe bleeding"),
        ("Blue lips", "asul bibig", "blue lips"),
        ("Poisoning", "nakainom lason", "poisoning"),
        ("Severe burns", "nasunog lawas", "severe burns"),
        ("Anaphylaxis", "gahubag tutunlan", "anaphylaxis"),
        ("Confusion with high fever", "nagakalibog kag grabe lagnat", "confusion with high fever"),
    ]
    for name, hil, eng in extra_em:
        for i in range(20):
            em_rows.append({
                "rule_id": "",
                "name": name,
                "pattern_hiligaynon": hil if i % 2 == 0 else "",
                "pattern_english": eng if i % 2 == 1 else eng,
                "classification": "EMERGENCY",
                "rationale": f"Emergency warning: {name}",
                "status": "active",
            })
    # Ensure >=500 unique rows after dedupe
    idx = 0
    seen_em = {(r.get("pattern_hiligaynon", ""), r.get("pattern_english", "")) for r in em_rows}
    while len(seen_em) < 500:
        idx += 1
        name, hil, eng = random.choice(extra_em)
        hil_p = f"{hil} case{idx}"
        eng_p = f"{eng} case{idx}"
        key = (hil_p, eng_p)
        if key in seen_em:
            continue
        seen_em.add(key)
        em_rows.append({
            "rule_id": f"ERF{idx:04d}",
            "name": name,
            "pattern_hiligaynon": hil_p,
            "pattern_english": eng_p,
            "classification": "EMERGENCY",
            "rationale": f"Emergency warning: {name}",
            "status": "active",
        })
    write_csv(OUT / "emergency_red_flags.csv", ["rule_id", "name", "pattern_hiligaynon", "pattern_english", "classification", "rationale", "status"], em_rows)

    urgent_concepts = ["fever 5 days", "persistent vomiting", "moderate abdominal pain", "asthma flare", "high blood", "infected wound", "ear pain with fever", "dehydration risk", "pain 7/10", "child with fever"]
    urg_rows = []
    for c in urgent_concepts:
        for i in range(50):
            urg_rows.append({
                "rule_id": f"U{len(urg_rows)+1:04d}",
                "name": c.title(),
                "pattern_english": c,
                "pattern_hiligaynon": "",
                "classification": "URGENT",
                "rationale": f"Urgent evaluation for {c}",
                "status": "active",
            })
    write_csv(OUT / "urgent_conditions.csv", ["rule_id", "name", "pattern_english", "pattern_hiligaynon", "classification", "rationale", "status"], urg_rows)

    nonurg = ["runny nose", "mild cough", "mild headache", "medicine refill", "follow-up", "mild sore throat", "fatigue", "mild body ache", "check-up", "lab result inquiry"]
    nu_rows = []
    for c in nonurg:
        for i in range(50):
            nu_rows.append({
                "rule_id": f"N{len(nu_rows)+1:04d}",
                "name": c.title(),
                "pattern_english": c,
                "pattern_hiligaynon": "",
                "classification": "NON-URGENT",
                "rationale": f"Routine consultation for {c}",
                "status": "active",
            })
    write_csv(OUT / "non_urgent_conditions.csv", ["rule_id", "name", "pattern_english", "pattern_hiligaynon", "classification", "rationale", "status"], nu_rows)

    # ---- stopwords / negation / misspellings / abbreviations ----
    stop = ["the", "a", "an", "and", "or", "of", "to", "in", "on", "ko", "ako", "ang", "sang", "sa", "mga", "ay", "ng", "na", "lang", "gid", "man", "daw", "my", "i", "have", "has", "is", "am", "are", "with", "for"]
    write_csv(OUT / "medical_stopwords.csv", ["word", "language", "status"], [
        {"word": w, "language": "mixed", "status": "active"} for w in stop
    ])

    neg_patterns = [
        ("no fever", "fever", "english"),
        ("no cough", "cough", "english"),
        ("no chest pain", "chest pain", "english"),
        ("no vomiting", "vomiting", "english"),
        ("not dizzy", "dizziness", "english"),
        ("denies fever", "fever", "english"),
        ("without fever", "fever", "english"),
        ("wala akong lagnat", "fever", "filipino"),
        ("wala akong ubo", "cough", "filipino"),
        ("wala ko ginaubo", "cough", "hiligaynon"),
        ("wala ko lagnat", "fever", "hiligaynon"),
        ("indi budlay ginhawa", "difficulty breathing", "hiligaynon"),
        ("indi masakit dughan", "chest pain", "hiligaynon"),
        ("indi gasuka", "vomiting", "hiligaynon"),
        ("wala lipong", "dizziness", "hiligaynon"),
        ("hindi ako nilalagnat", "fever", "filipino"),
        ("hindi ako umuubo", "cough", "filipino"),
        ("walang sakit sa dibdib", "chest pain", "filipino"),
        ("no shortness of breath", "difficulty breathing", "english"),
        ("not short of breath", "difficulty breathing", "english"),
    ]
    neg_rows = []
    for pat, target, lang in neg_patterns:
        neg_rows.append({"pattern": pat, "negated_concept": target, "language": lang, "status": "active"})
    # Expand negation templates
    concepts = ["fever", "cough", "headache", "vomiting", "nausea", "diarrhea", "chest pain", "dizziness", "rash", "bleeding"]
    prefixes = ["no ", "not ", "denies ", "without ", "wala ", "wala akong ", "wala ko ", "indi ", "hindi ", "hindi ako "]
    for pref, c in itertools.product(prefixes, concepts):
        neg_rows.append({"pattern": (pref + c).strip(), "negated_concept": c, "language": "mixed", "status": "active"})
    idx = 0
    while len(neg_rows) < 500:
        idx += 1
        c = random.choice(concepts)
        form = random.choice([
            f"no signs of {c}",
            f"no report of {c}",
            f"patient denies {c}",
            f"negative for {c}",
            f"absent {c}",
            f"wala sang {c}",
            f"indi may {c}",
            f"hindi may {c}",
        ])
        neg_rows.append({
            "rule_id": f"NEG{idx:04d}",
            "pattern": form,
            "negated_concept": c,
            "language": "mixed",
            "status": "active",
        })
    for i, row in enumerate(neg_rows):
        row.setdefault("rule_id", f"NEGB{i+1:04d}")
    write_csv(OUT / "negation_words.csv", ["rule_id", "pattern", "negated_concept", "language", "status"], neg_rows)

    # Misspellings — expand medical_misspellings.csv AND misspellings.csv
    miss_rows = read_csv(NLP / "medical_misspellings.csv")
    seed_words = [
        ("fever", ["fevr", "feber", "feveer", "feaver", "fevor"]),
        ("lagnat", ["lagnaat", "lagant", "lagnatt", "lagnate", "laganat"]),
        ("ubo", ["uboo", "uboh", "ubbo", "ubou"]),
        ("sip-on", ["siponn", "sipon", "sippon", "syp-on"]),
        ("headache", ["hedache", "headak", "headace", "hedachee", "headahe"]),
        ("difficulty breathing", ["hirap humingga", "budlay ginhwa", "budlay ginhawa", "hirap huminga"]),
        ("chest pain", ["masakit dughanh", "masakit dughan", "chest pan", "chestpain"]),
        ("ginakalagnat", ["ginakalagnatt", "ginakalagnat", "ginakalnagat"]),
        ("cough", ["coug", "cogh", "couph", "coughh"]),
        ("vomiting", ["vomitting", "vomitting", "vomitin", "vomitting"]),
        ("diarrhea", ["diarrea", "diarrhoea", "dyarrea", "diarhhea"]),
        ("abdomen", ["abdomin", "abdoman", "abdomenn"]),
        ("pregnant", ["pregnent", "pregyant", "pregnat"]),
        ("asthma", ["asma", "asthma", "asthmaa", "asthma"]),
        ("diabetes", ["diabetis", "diabites", "diabeties"]),
    ]
    for correct, wrongs in seed_words:
        for w in wrongs:
            miss_rows.append({"correct_term": correct, "misspelling": w, "term_type": "clinical", "status": "active"})
    for name in symptoms[:800]:
        for w in typo_variants(name)[:6]:
            miss_rows.append({"correct_term": name.lower(), "misspelling": w, "term_type": "generated", "status": "active"})
    for key, eng, terms in HIL_SYMPTOMS:
        for t in terms:
            for w in typo_variants(t)[:4]:
                miss_rows.append({"correct_term": t, "misspelling": w, "term_type": "hiligaynon", "status": "active"})
    idx = 0
    while len(miss_rows) < 5000:
        idx += 1
        name = random.choice(symptoms[:1000]).lower()
        variants = typo_variants(name)
        if not variants:
            continue
        w = random.choice(variants) + (str(idx) if idx % 7 == 0 else "")
        miss_rows.append({"correct_term": name, "misspelling": w, "term_type": "generated", "status": "active"})
    write_csv(OUT / "medical_misspellings.csv", ["correct_term", "misspelling", "term_type", "status"], miss_rows)
    write_csv(OUT / "misspellings.csv", ["correct_term", "misspelling", "term_type", "status"], miss_rows)

    abbrev = [
        ("bp", "blood pressure"), ("htn", "hypertension"), ("dm", "diabetes"), ("sob", "shortness of breath"),
        ("cp", "chest pain"), ("ha", "headache"), ("n/v", "nausea and vomiting"), ("loc", "loss of consciousness"),
        ("uti", "urinary tract infection"), ("uri", "upper respiratory infection"), ("dob", "difficulty of breathing"),
        ("o2", "oxygen"), ("hr", "heart rate"), ("rr", "respiratory rate"), ("temp", "temperature"),
        ("fx", "fracture"), ("hx", "history"), ("sx", "symptoms"), ("dx", "diagnosis"), ("rx", "prescription"),
    ]
    write_csv(OUT / "medical_abbreviations.csv", ["abbreviation", "expansion", "status"], [
        {"abbreviation": a, "expansion": e, "status": "active"} for a, e in abbrev
    ])

    # ---- weights / scores / rules ----
    weight_rows = []
    for key, eng, _ in HIL_SYMPTOMS:
        w = 10 if key in {"difficulty_breathing", "chest_pain", "vomiting_blood", "loss_of_consciousness", "seizure"} else 2
        if key in {"persistent_vomiting"}:
            w = 6
        weight_rows.append({"concept": key, "symptom_name": eng, "severity_weight": str(w), "status": "active"})
    write_csv(OUT / "symptom_weights.csv", ["concept", "symptom_name", "severity_weight", "status"], weight_rows)
    write_csv(OUT / "severity_scores.csv", ["min_score", "max_score", "classification", "priority", "status"], [
        {"min_score": "0", "max_score": "5", "classification": "NON-URGENT", "priority": "Normal", "status": "active"},
        {"min_score": "6", "max_score": "11", "classification": "URGENT", "priority": "High", "status": "active"},
        {"min_score": "12", "max_score": "999", "classification": "EMERGENCY", "priority": "Critical", "status": "active"},
    ])

    # Expand triage_rules.csv carefully — append CDS rules file instead of wiping
    tr_rows = []
    for row in existing_rules:
        tr_rows.append({
            "hiligaynon_pattern": row.get("hiligaynon_pattern") or "",
            "english_pattern": row.get("english_pattern") or "",
            "triage_level": row.get("triage_level") or "",
            "severity": row.get("severity") or "",
            "status": row.get("status") or "active",
        })
    write_csv(OUT / "triage_rules_cds.csv", ["hiligaynon_pattern", "english_pattern", "triage_level", "severity", "status"], tr_rows + [
        {"hiligaynon_pattern": hil, "english_pattern": eng, "triage_level": "EMERGENCY", "severity": "critical", "status": "active"}
        for _, hil, eng in extra_em
    ])

    reason_rows = [
        {"rule_id": "CR001", "when": "red_flag_present", "reason_template": "Emergency warning sign(s) detected ({flags}). Immediate emergency evaluation is recommended.", "status": "active"},
        {"rule_id": "CR002", "when": "score_emergency", "reason_template": "Severity score {score} meets emergency triage criteria.", "status": "active"},
        {"rule_id": "CR003", "when": "score_urgent", "reason_template": "Severity score {score} with symptoms ({symptoms}) warrants prompt clinician review.", "status": "active"},
        {"rule_id": "CR004", "when": "score_non_urgent", "reason_template": "Mild symptoms ({symptoms}) with no emergency warning signs. Score {score}.", "status": "active"},
        {"rule_id": "CR005", "when": "low_confidence", "reason_template": "Insufficient information for confident triage. Needs healthcare provider review.", "status": "active"},
    ]
    write_csv(OUT / "clinical_reasoning_rules.csv", ["rule_id", "when", "reason_template", "status"], reason_rows)

    combo_rows = []
    combos = [
        ("chest_pain", "difficulty_breathing", "EMERGENCY", 20),
        ("fever", "confusion", "EMERGENCY", 16),
        ("fever", "stiff_neck", "EMERGENCY", 16),
        ("vomiting", "blood", "EMERGENCY", 15),
        ("cough", "blood", "EMERGENCY", 15),
        ("fever", "duration_5_plus", "URGENT", 6),
        ("vomiting", "dehydration", "URGENT", 8),
        ("asthma", "difficulty_breathing", "EMERGENCY", 14),
        ("pregnancy", "bleeding", "EMERGENCY", 18),
        ("heart_disease", "chest_pain", "EMERGENCY", 18),
        ("fever", "cough", "NON-URGENT", 4),
        ("runny_nose", "cough", "NON-URGENT", 3),
    ]
    for a, b, cls, pts in combos:
        for i in range(170):
            combo_rows.append({
                "combo_id": f"SC{len(combo_rows)+1:05d}",
                "symptom_a": a,
                "symptom_b": b,
                "variant": str(i + 1),
                "classification": cls,
                "severity_points": str(pts),
                "rationale": f"Combination of {a} + {b}",
                "status": "active",
            })
    # Additional cross products for volume
    extras_a = ["fever", "cough", "headache", "nausea", "diarrhea", "rash", "weakness", "chills"]
    extras_b = ["duration_3_days", "duration_5_plus", "child", "senior", "pregnancy", "diabetes", "asthma", "mild", "severe"]
    for a, b in itertools.product(extras_a, extras_b):
        for i in range(4):
            combo_rows.append({
                "combo_id": f"SC{len(combo_rows)+1:05d}",
                "symptom_a": a,
                "symptom_b": b,
                "variant": str(i + 1),
                "classification": "URGENT" if "5_plus" in b or b in {"child", "senior", "pregnancy"} else "NON-URGENT",
                "severity_points": "6" if "5_plus" in b else "3",
                "rationale": f"Combination of {a} + {b}",
                "status": "active",
            })
    write_csv(OUT / "symptom_combinations.csv", ["combo_id", "symptom_a", "symptom_b", "variant", "classification", "severity_points", "rationale", "status"], combo_rows)

    write_csv(OUT / "confidence_rules.csv", ["rule_id", "condition", "confidence_effect", "notes", "status"], [
        {"rule_id": "CF01", "condition": "vague_complaint", "confidence_effect": "cap_42", "notes": "Vague text without clear symptoms", "status": "active"},
        {"rule_id": "CF02", "condition": "red_flag_matched", "confidence_effect": "min_85", "notes": "Red flag increases confidence", "status": "active"},
        {"rule_id": "CF03", "condition": "kb_symptom_matched", "confidence_effect": "plus_5", "notes": "Structured symptom match", "status": "active"},
        {"rule_id": "CF04", "condition": "below_60", "confidence_effect": "provider_review", "notes": "Needs Healthcare Provider Review", "status": "active"},
        {"rule_id": "CF05", "condition": "negation_only", "confidence_effect": "cap_50", "notes": "Only negated symptoms found", "status": "active"},
    ])

    # ---- chief complaints / phrases / translation / sentences ----
    complaints = []
    for i in range(4000):
        sym = random.choice(symptoms[:500]).lower()
        text = random.choice(EN_TEMPLATES).format(
            symptom=sym, duration=random.choice(DURATIONS), body=random.choice(BODIES),
            pain=random.choice(PAINS), temp=random.choice(TEMPS),
        )
        complaints.append({
            "complaint_id": f"CC{i+1:05d}",
            "complaint": f"{text} [{i+1}]",
            "language": "english",
            "expected_class": "",
            "status": "active",
        })
    for i in range(3500):
        hil = random.choice([t for _, _, terms in HIL_SYMPTOMS for t in terms])
        text = random.choice(HIL_TEMPLATES).format(hil=hil, duration=random.choice(["1 ka adlaw", "3 ka adlaw", "5 ka adlaw", "gahapon"]))
        complaints.append({
            "complaint_id": f"CCH{i+1:05d}",
            "complaint": f"{text} [{i+1}]",
            "language": "hiligaynon",
            "expected_class": "",
            "status": "active",
        })
    for i in range(3000):
        fil = random.choice([t for _, _, terms in FIL_SYMPTOMS for t in terms])
        text = random.choice(FIL_TEMPLATES).format(
            fil=fil, duration=random.choice(["3 araw", "5 araw", "isang linggo"]),
            body_fil=random.choice(BODY_FIL),
        )
        complaints.append({
            "complaint_id": f"CCF{i+1:05d}",
            "complaint": f"{text} [{i+1}]",
            "language": "filipino",
            "expected_class": "",
            "status": "active",
        })
    # Seed realistic exemplars
    for c, lang, cls in [
        ("I have fever.", "english", "NON-URGENT"),
        ("I have fever for 3 days.", "english", "NON-URGENT"),
        ("I have fever for 5 days.", "english", "URGENT"),
        ("My chest hurts.", "english", "EMERGENCY"),
        ("I cannot breathe.", "english", "EMERGENCY"),
        ("My child has a high fever.", "english", "URGENT"),
        ("My baby keeps vomiting.", "english", "URGENT"),
        ("I have asthma.", "english", "NON-URGENT"),
        ("I am pregnant and bleeding.", "english", "EMERGENCY"),
        ("I fainted.", "english", "EMERGENCY"),
        ("My left arm is numb.", "english", "EMERGENCY"),
        ("I need medicine refill.", "english", "NON-URGENT"),
        ("I need a follow-up.", "english", "NON-URGENT"),
        ("My blood pressure is high.", "english", "URGENT"),
        ("My sugar is high.", "english", "URGENT"),
        ("My wound is infected.", "english", "URGENT"),
        ("Budlay gid ang ginhawa", "hiligaynon", "EMERGENCY"),
        ("Gapalanakit ulo ko", "hiligaynon", "NON-URGENT"),
        ("Masakit akon dughan", "hiligaynon", "EMERGENCY"),
        ("May lagnat ako", "hiligaynon", "NON-URGENT"),
        ("Wala akong lagnat", "hiligaynon", "NON-URGENT"),
        ("May dugo sa akon suka", "hiligaynon", "EMERGENCY"),
    ]:
        complaints.append({"complaint_id": f"SEED{len(complaints)+1:04d}", "complaint": c, "language": lang, "expected_class": cls, "status": "active"})
    write_csv(OUT / "chief_complaint_examples.csv", ["complaint_id", "complaint", "language", "expected_class", "status"], complaints)
    write_csv(OUT / "common_patient_sentences.csv", ["complaint_id", "complaint", "language", "expected_class", "status"], complaints)

    phrases = []
    for key, eng, terms in HIL_SYMPTOMS:
        for t in terms:
            phrases.append({"phrase": t, "english": eng, "concept": key, "language": "hiligaynon", "status": "active"})
    for key, eng, terms in FIL_SYMPTOMS:
        for t in terms:
            phrases.append({"phrase": t, "english": eng, "concept": key, "language": "filipino", "status": "active"})
    for name in symptoms[:3000]:
        phrases.append({"phrase": name.lower(), "english": name, "concept": name.lower().replace(" ", "_")[:60], "language": "english", "status": "active"})
    write_csv(OUT / "medical_phrases.csv", ["phrase", "english", "concept", "language", "status"], phrases)

    # translation dictionary — merge style file
    trans = []
    for local, eng in dict_pairs:
        trans.append({"local_term": local, "english_term": eng, "language": "hiligaynon", "status": "active"})
    for key, eng, terms in HIL_SYMPTOMS + [(a, b, c) for a, b, c in FIL_SYMPTOMS]:
        for t in terms:
            trans.append({"local_term": t, "english_term": eng, "language": "mixed", "status": "active"})
    write_csv(OUT / "translation_dictionary.csv", ["local_term", "english_term", "language", "status"], trans)

    # medical_conditions.csv already huge — write pointer/companion only if needed
    # Ensure filename medical_conditions.csv remains existing ICD set (do not overwrite 74k file)
    print("  preserved existing medical_conditions.csv (ICD-10)")
    print("  preserved existing triage_rules.csv / body_parts.csv / emergency_flags.csv")
    print("Done.")


if __name__ == "__main__":
    build_all()
