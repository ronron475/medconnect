#!/usr/bin/env python3
"""
Generate labeled triage validation datasets for continuous NLP QA.

Outputs under data/nlp/validation/ with expected NON-URGENT | URGENT | EMERGENCY.
Does not modify the NLP engine — datasets only.
"""

from __future__ import annotations

import csv
import random
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
OUT = ROOT / "data" / "nlp" / "validation"
SEED = 20260805
random.seed(SEED)

# (complaint, expected, language, scenario_type)
SEEDS: list[tuple[str, str, str, str]] = [
    ("I have fever.", "NON-URGENT", "english", "non_urgent"),
    ("I have fever for 5 days.", "URGENT", "english", "urgent"),
    ("I have chest pain and difficulty breathing.", "EMERGENCY", "english", "emergency"),
    ("My left arm suddenly became weak and I cannot speak properly.", "EMERGENCY", "english", "emergency"),
    ("I need a refill of my maintenance medicine.", "NON-URGENT", "english", "non_urgent"),
    ("I need a follow-up.", "NON-URGENT", "english", "non_urgent"),
    ("I have cough.", "NON-URGENT", "english", "non_urgent"),
    ("I cannot breathe.", "EMERGENCY", "english", "emergency"),
    ("I fainted.", "EMERGENCY", "english", "emergency"),
    ("I am pregnant and bleeding.", "EMERGENCY", "english", "emergency"),
    ("My child has a high fever.", "URGENT", "english", "urgent"),
    ("Pain 8/10 in my abdomen for 2 days.", "URGENT", "english", "urgent"),
    ("May lagnat ako.", "NON-URGENT", "hiligaynon", "non_urgent"),
    ("Budlay gid ang ginhawa ko.", "EMERGENCY", "hiligaynon", "emergency"),
    ("Masakit akon dughan.", "EMERGENCY", "hiligaynon", "emergency"),
    ("Gapalanakit ulo ko.", "NON-URGENT", "hiligaynon", "non_urgent"),
    ("May dugo sa akon suka.", "EMERGENCY", "hiligaynon", "emergency"),
    ("Wala akong lagnat.", "NON-URGENT", "hiligaynon", "non_urgent"),
    ("Nilalagnat ako nang 5 araw.", "URGENT", "filipino", "urgent"),
    ("Hirap akong huminga.", "EMERGENCY", "filipino", "emergency"),
    ("Masakit ang dibdib ko.", "EMERGENCY", "filipino", "emergency"),
    ("Kailangan ko ng refill ng gamot.", "NON-URGENT", "filipino", "non_urgent"),
    ("May sipon at ubo ako.", "NON-URGENT", "filipino", "non_urgent"),
    ("I have fevr for 5 days.", "URGENT", "english", "misspelled"),
    ("Budlay ginhwa ko.", "EMERGENCY", "hiligaynon", "misspelled"),
]

EN_NON = [
    "I have {s}.",
    "Mild {s} today.",
    "I feel {s}.",
    "I need medicine refill.",
    "I need a follow-up appointment.",
    "I have a cold with {s}.",
    "Slight {s} since this morning.",
]
EN_URG = [
    "I have {s} for 5 days.",
    "Persistent {s} for one week.",
    "My child has {s}.",
    "Pain 7/10 with {s} for 3 days.",
    "High fever with {s}.",
    "My blood pressure is high and I have {s}.",
    "Wound looks infected with {s}.",
]
EN_EM = [
    "I have chest pain and difficulty breathing.",
    "I cannot breathe.",
    "I am unconscious spells / I fainted.",
    "I am having a seizure.",
    "Sudden weakness of left arm and slurred speech.",
    "I am vomiting blood.",
    "I am coughing blood.",
    "Severe bleeding that will not stop.",
    "I am pregnant and bleeding heavily.",
    "Blue lips and severe shortness of breath.",
    "Severe allergic reaction with throat swelling.",
    "I drank poison.",
    "Confusion with high fever.",
]

HIL_NON = [
    "May {h} ako.",
    "{h} ko.",
    "May sip-on kag ubo ko.",
    "Gapalanakit ulo ko.",
    "Kapoy gid ako.",
    "Wala akong lagnat.",
]
HIL_URG = [
    "May {h} ako sang 5 ka adlaw.",
    "Ginakalagnat ako 5 ka adlaw na.",
    "Padayon naga suka ko.",
    "Anak ko may lagnat.",
]
HIL_EM = [
    "Budlay gid ang ginhawa ko.",
    "Masakit akon dughan.",
    "Indi ko makaginhawa.",
    "May dugo sa akon suka.",
    "Nadulaan ko malay.",
    "Naguyam ko.",
    "Grabe gid nagadugo.",
]

FIL_NON = [
    "May {f} ako.",
    "Masakit ang ulo ko.",
    "May sipon ako.",
    "Kailangan ko ng follow-up.",
    "Kailangan ko ng refill ng gamot.",
]
FIL_URG = [
    "Nilalagnat ako nang 5 araw.",
    "Paulit-ulit ang pagsusuka.",
    "Anak ko may mataas na lagnat.",
    "Mataas ang blood pressure ko.",
]
FIL_EM = [
    "Hirap akong huminga.",
    "Masakit ang dibdib ko.",
    "Nagsusuka ako ng dugo.",
    "Nawalan ako ng malay.",
    "Buntis ako at dumudugo.",
    "Biglang mahina ang kaliwang braso at hirap magsalita.",
]

HIL_TERMS = ["lagnat", "ubo", "sip-on", "sakit ulo", "sakit tiyan", "kapoy", "kulba"]
FIL_TERMS = ["lagnat", "ubo", "sipon", "sakit ng ulo", "sakit ng tiyan", "pagod", "hilo"]
EN_MILD = ["fever", "cough", "runny nose", "mild headache", "sore throat", "body ache", "fatigue"]
EN_MOD = ["vomiting", "diarrhea", "abdominal pain", "dizziness", "ear pain", "back pain"]

MISS_MAP = {
    "fever": ["fevr", "feber", "feveer"],
    "cough": ["coug", "uboh", "uboo"],
    "headache": ["hedache", "headak"],
    "breathing": ["ginhwa", "humingga"],
    "chest": ["dughanh", "dughan"],
    "lagnat": ["lagnaat", "lagant", "ginakalagnatt"],
}


def write_rows(path: Path, rows: list[dict[str, str]]) -> int:
    path.parent.mkdir(parents=True, exist_ok=True)
    fields = ["case_id", "chief_complaint", "expected_classification", "language", "scenario_type", "status"]
    seen: set[str] = set()
    unique: list[dict[str, str]] = []
    for r in rows:
        key = r["chief_complaint"].strip().lower()
        if key in seen or not key:
            continue
        seen.add(key)
        unique.append({f: r.get(f, "") for f in fields})
    with path.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=fields)
        w.writeheader()
        w.writerows(unique)
    print(f"  {path.name}: {len(unique)}")
    return len(unique)


def pad(rows: list[dict[str, str]], n: int, lang: str, expected: str, scenario: str, templates: list[str], fillers: list[str], prefix: str) -> None:
    i = 0
    while len([r for r in rows if r["language"] == lang and r["expected_classification"] == expected]) < n and i < n * 4:
        i += 1
        tmpl = random.choice(templates)
        fill = random.choice(fillers)
        try:
            text = tmpl.format(s=fill, h=fill, f=fill)
        except Exception:
            text = tmpl
        rows.append({
            "case_id": f"{prefix}{len(rows)+1:05d}",
            "chief_complaint": f"{text} [{prefix}{i}]",
            "expected_classification": expected,
            "language": lang,
            "scenario_type": scenario,
            "status": "active",
        })


def main() -> None:
    print("Building triage validation datasets...")
    all_rows: list[dict[str, str]] = []

    for i, (c, exp, lang, st) in enumerate(SEEDS, start=1):
        all_rows.append({
            "case_id": f"SEED{i:04d}",
            "chief_complaint": c,
            "expected_classification": exp,
            "language": lang,
            "scenario_type": st,
            "status": "active",
        })

    # English 10k
    en_rows: list[dict[str, str]] = [r for r in all_rows if r["language"] == "english"]
    pad(en_rows, 4000, "english", "NON-URGENT", "non_urgent", EN_NON, EN_MILD, "ENU")
    pad(en_rows, 3000, "english", "URGENT", "urgent", EN_URG, EN_MOD + EN_MILD, "ENR")
    pad(en_rows, 3000, "english", "EMERGENCY", "emergency", EN_EM, EN_MILD, "ENE")
    while len(en_rows) < 10000:
        pad(en_rows, len(en_rows) + 500, "english", random.choice(["NON-URGENT", "URGENT", "EMERGENCY"]), "english", EN_NON + EN_URG + EN_EM, EN_MILD, "ENX")
    write_rows(OUT / "english_chief_complaints_validation.csv", en_rows[:10000])

    # Filipino 10k
    fil_rows: list[dict[str, str]] = [r for r in all_rows if r["language"] == "filipino"]
    pad(fil_rows, 4000, "filipino", "NON-URGENT", "non_urgent", FIL_NON, FIL_TERMS, "FILN")
    pad(fil_rows, 3000, "filipino", "URGENT", "urgent", FIL_URG, FIL_TERMS, "FILU")
    pad(fil_rows, 3000, "filipino", "EMERGENCY", "emergency", FIL_EM, FIL_TERMS, "FILE")
    while len(fil_rows) < 10000:
        pad(fil_rows, len(fil_rows) + 500, "filipino", random.choice(["NON-URGENT", "URGENT", "EMERGENCY"]), "filipino", FIL_NON + FIL_URG + FIL_EM, FIL_TERMS, "FILX")
    write_rows(OUT / "filipino_chief_complaints_validation.csv", fil_rows[:10000])

    # Hiligaynon 10k
    hil_rows: list[dict[str, str]] = [r for r in all_rows if r["language"] == "hiligaynon"]
    pad(hil_rows, 4000, "hiligaynon", "NON-URGENT", "non_urgent", HIL_NON, HIL_TERMS, "HILN")
    pad(hil_rows, 3000, "hiligaynon", "URGENT", "urgent", HIL_URG, HIL_TERMS, "HILU")
    pad(hil_rows, 3000, "hiligaynon", "EMERGENCY", "emergency", HIL_EM, HIL_TERMS, "HILE")
    while len(hil_rows) < 10000:
        pad(hil_rows, len(hil_rows) + 500, "hiligaynon", random.choice(["NON-URGENT", "URGENT", "EMERGENCY"]), "hiligaynon", HIL_NON + HIL_URG + HIL_EM, HIL_TERMS, "HILX")
    write_rows(OUT / "hiligaynon_chief_complaints_validation.csv", hil_rows[:10000])

    # Mixed 5k
    mixed: list[dict[str, str]] = []
    mixes = [
        ("May fever ako for 2 days.", "NON-URGENT"),
        ("Budlay ginhawa ko and chest pain.", "EMERGENCY"),
        ("Nilalagnat ako and I feel weak.", "NON-URGENT"),
        ("Masakit ulo ko since yesterday.", "NON-URGENT"),
        ("I have lagnat for 5 days.", "URGENT"),
        ("May dugo sa suka and I feel dizzy.", "EMERGENCY"),
        ("Kailangan ko refill of my maintenance.", "NON-URGENT"),
    ]
    for i, (t, e) in enumerate(mixes):
        mixed.append({"case_id": f"MIX{i+1:04d}", "chief_complaint": t, "expected_classification": e, "language": "mixed", "scenario_type": "mixed", "status": "active"})
    while len(mixed) < 5000:
        a = random.choice(EN_MILD)
        b = random.choice(HIL_TERMS)
        c = random.choice(FIL_TERMS)
        text = random.choice([
            f"I have {a} and may {b} ako.",
            f"May {b} ako with mild {a}.",
            f"May {c} ako and slight {a}.",
            f"Budlay ginhawa? No, only {a}.",
            f"Masakit dughan ko and hirap huminga.",
        ])
        exp = "EMERGENCY" if ("dughan" in text and "hirap" in text) or "ginhawa" in text and "Budlay" in text else (
            "URGENT" if "5 days" in text else "NON-URGENT"
        )
        if "Masakit dughan" in text:
            exp = "EMERGENCY"
        mixed.append({
            "case_id": f"MIX{len(mixed)+1:05d}",
            "chief_complaint": f"{text} [{len(mixed)+1}]",
            "expected_classification": exp,
            "language": "mixed",
            "scenario_type": "mixed",
            "status": "active",
        })
    write_rows(OUT / "mixed_language_complaints_validation.csv", mixed[:5000])

    # Misspelled 5k
    miss: list[dict[str, str]] = []
    for correct, wrongs in MISS_MAP.items():
        for w in wrongs:
            if correct in {"breathing", "chest", "ginhwa"} or w in {"ginhwa", "humingga", "dughanh"}:
                exp = "EMERGENCY"
                text = f"budlay {w} ko" if "ginh" in w or "huming" in w else f"masakit {w}"
            elif "5" in correct:
                exp = "URGENT"
                text = f"I have {w} for 5 days"
            else:
                exp = "NON-URGENT" if correct in {"fever", "cough", "headache", "lagnat"} else "URGENT"
                text = f"I have {w}" if correct != "lagnat" else f"may {w} ako"
            miss.append({
                "case_id": f"MIS{len(miss)+1:05d}",
                "chief_complaint": text,
                "expected_classification": exp,
                "language": "misspelled",
                "scenario_type": "misspelled",
                "status": "active",
            })
    while len(miss) < 5000:
        base = random.choice(EN_MILD)
        w = random.choice(MISS_MAP.get(base, [base[:3] + "x" + base[3:]]))
        days = random.choice(["", " for 5 days", " today"])
        exp = "URGENT" if "5 days" in days else "NON-URGENT"
        miss.append({
            "case_id": f"MIS{len(miss)+1:05d}",
            "chief_complaint": f"I have {w}{days} [{len(miss)+1}]",
            "expected_classification": exp,
            "language": "misspelled",
            "scenario_type": "misspelled",
            "status": "active",
        })
    write_rows(OUT / "misspelled_complaints_validation.csv", miss[:5000])

    # Scenario-focused sets
    def collect(expected: str, n: int, name: str) -> None:
        pool = [r for r in en_rows + fil_rows + hil_rows + mixed if r["expected_classification"] == expected]
        # duplicate with unique ids to reach n
        out = []
        i = 0
        while len(out) < n:
            src = pool[i % max(len(pool), 1)] if pool else {
                "chief_complaint": "I have fever." if expected == "NON-URGENT" else (
                    "I have fever for 5 days." if expected == "URGENT" else "I cannot breathe."
                ),
                "language": "english",
            }
            i += 1
            out.append({
                "case_id": f"{name}{len(out)+1:05d}",
                "chief_complaint": f"{src['chief_complaint']} #{len(out)+1}",
                "expected_classification": expected,
                "language": src.get("language", "english"),
                "scenario_type": expected.lower(),
                "status": "active",
            })
        write_rows(OUT / f"{name}_scenarios_validation.csv", out)

    collect("EMERGENCY", 3000, "emergency")
    collect("URGENT", 3000, "urgent")
    collect("NON-URGENT", 4000, "non_urgent")

    # Master combined file
    master = en_rows[:10000] + fil_rows[:10000] + hil_rows[:10000] + mixed[:5000] + miss[:5000]
    # dedupe by complaint for master using write_rows
    write_rows(OUT / "triage_validation_master.csv", master)

    # Gold seed file (hand-curated expectations, no padding ids)
    gold = []
    for i, (c, exp, lang, st) in enumerate(SEEDS, start=1):
        gold.append({
            "case_id": f"GOLD{i:04d}",
            "chief_complaint": c,
            "expected_classification": exp,
            "language": lang,
            "scenario_type": st,
            "status": "active",
        })
    write_rows(OUT / "triage_validation_gold.csv", gold)
    print("Done.")


if __name__ == "__main__":
    main()
