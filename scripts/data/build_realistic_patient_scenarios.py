"""
Generate human-style patient typing scenarios (chat, telehealth, registration).

These mimic what real users type — not only formal template sentences.

Run:
    python scripts/data/build_realistic_patient_scenarios.py
    python scripts/data/merge_patient_training_corpus.py

Output:
    data/nlp/training/patient_realistic_scenarios.csv
"""

from __future__ import annotations

import csv
import json
import random
import re
from pathlib import Path

from build_patient_training_dataset import (
    assign_split,
    hiligaynon_phrase_for_symptom,
    join_phrases,
    load_dictionary_hiligaynon,
    load_disease_symptom_rows,
    symptom_to_english_phrase,
)

ROOT = Path(__file__).resolve().parents[2]
OUT_DIR = ROOT / "data" / "nlp" / "training"
OUT_CSV = OUT_DIR / "patient_realistic_scenarios.csv"
OUT_JSONL = OUT_DIR / "patient_realistic_scenarios.jsonl"

# How many typing-style lines per disease–symptom signature (per language group).
VARIANTS_PER_SIGNATURE = 48

REALISTIC_EN = [
    "hi doc {symptoms}",
    "hello po doctor {symptoms}",
    "i have {symptoms} since yesterday",
    "im having {symptoms} for 3 days already",
    "my symptoms: {symptoms}",
    "typing my concern here {symptoms}",
    "not sure pero {symptoms} lang",
    "can you check {symptoms}?",
    "patient reports {symptoms}",
    "worried about {symptoms} please advise",
    "came online because of {symptoms}",
    "lately {symptoms} and its getting worse",
    "no other issues just {symptoms}",
    "help {symptoms} what should i do",
    "consult lang about {symptoms}",
    "for telehealth: {symptoms}",
    "registration symptom field: {symptoms}",
    "i feel {symptoms} today",
    "after lunch started {symptoms}",
    "good morning {symptoms} since last week",
    "sorry late reply {symptoms} gihapon",
    "idk if serious {symptoms}",
    "my child has {symptoms}",
    "spouse has {symptoms} too",
    "chief complaint {symptoms}",
    "cc {symptoms}",
    "reason for visit {symptoms}",
    "presenting problem {symptoms}",
    "main complaint {symptoms}",
    "patient cc {symptoms}",
    # Terser, messier registers real patients use in chat.
    "{symptoms}",
    "{symptoms} po",
    "doc {symptoms} ?",
    "pls help {symptoms}",
    "since kagabi {symptoms}",
    "2 weeks na ni {symptoms}",
    "grabe na {symptoms} doc",
    "ok lang ba ni? {symptoms}",
    "sorry disturb {symptoms}",
    "nanay ko may {symptoms}",
    "tatay ko nagareklamo {symptoms}",
    "bata ko {symptoms} kahapon pa",
    "follow up doc {symptoms} gihapon",
    "wala pa nag ayo {symptoms}",
    "ininom ko na bulong pero {symptoms} gihapon",
    "amo ni ginabatyag ko {symptoms}",
]

REALISTIC_HIL = [
    "doc may {symptoms} ko subong",
    "pasuyi po doc {symptoms} ako",
    "type ko lang {symptoms} kay basi ma check",
    "good morning doc {symptoms} gid",
    "wala ko kasiguruhan {symptoms} gihapon",
    "halong doc {symptoms} sang isa ka semana",
    "ginabalati ko {symptoms} permi",
    "concern ko is {symptoms}",
    "help doc {symptoms} ko",
    "telehealth complaint {symptoms}",
    "pasyente ko may {symptoms}",
    "nag chat ko kay {symptoms} lang problema",
    "wala iba nga sintomas {symptoms} lang",
    "kanina pa {symptoms} doc",
    "3 days na {symptoms}",
    "sorry late {symptoms} gihapon",
    "basi maayo {symptoms} ko",
    "register ako {symptoms}",
    "daw {symptoms} ang pasyente",
    "doc pasuyi {symptoms} gid",
    "nag message ko tungod {symptoms}",
    "online consult {symptoms}",
    "para sa form {symptoms}",
    "ginatype ko {symptoms} lang",
    "chief complaint ko {symptoms}",
    "cc ko {symptoms}",
    "reklamo ko {symptoms}",
    "rason consult {symptoms}",
    "unang reklamo {symptoms}",
    "form cc {symptoms}",
    # Everyday Ilonggo phrasing, including elderly and third-party reports.
    "{symptoms}",
    "{symptoms} gid doc",
    "maayong aga doc, {symptoms}",
    "maayong hapon doc, {symptoms}",
    "maayong gab-i doc, {symptoms}",
    "doc ano bala ni, {symptoms}",
    "ano ayo sini doc? {symptoms}",
    "ginabatyag ko {symptoms} halin kagabi",
    "duha na ka adlaw nga {symptoms}",
    "isa ka semana na nga {symptoms}",
    "indi na mabatas {symptoms}",
    "grabe na gid ang {symptoms}",
    "ang lola ko may {symptoms}",
    "ang bata ko {symptoms} kahapon pa",
    "asawa ko nagareklamo sang {symptoms}",
    "nag-inom na ako bulong pero {symptoms} gihapon",
    "wala gihapon nag-ayo ang {symptoms}",
    "kabalaka ko lang ni nga {symptoms}",
    "check bala doc kay {symptoms}",
    "puede bala magpa-check, {symptoms}",
    # More barangay / elderly / chat registers for broader Hiligaynon coverage.
    "maayong aga, doc. may {symptoms} ako",
    "doc pasensya na, {symptoms} gid subong",
    "ano owa ko kabalo, {symptoms} lang",
    "sa barangay clinic ko, {symptoms}",
    "ginapangayo ko advice kay {symptoms}",
    "hala doc {symptoms} na naman",
    "subong lang nag-umpisa ang {symptoms}",
    "halin sang Lunes may {symptoms} ako",
    "indi ko na kaya ang {symptoms}",
    "basia emergency ni? {symptoms}",
    "ang tatay ko {symptoms} kagabi pa",
    "ang nanay ko may {symptoms} gihapon",
    "ang apo ko {symptoms} sang isa ka semana",
    "ako ang nagatype para sa pasyente: {symptoms}",
    "sa teleconsult: {symptoms}",
    "sa registration form: {symptoms}",
    "chief complaint sa Hiligaynon: {symptoms}",
    "reklamo sang pasyente {symptoms}",
    "ginahambal niya {symptoms}",
    "daw grabe na ang {symptoms} niya",
    "pwede i-check ang {symptoms}?",
    "ano bulong sa {symptoms} doc?",
    "may ara bala antibiotics? {symptoms}",
    "indi ko gusto magpa-hospital pero {symptoms}",
    "sa balay lang kami, {symptoms}",
    "wala doktor diri amon lugar, {symptoms}",
    "message ko lang {symptoms}",
    "chat ko sa inyo tungod {symptoms}",
    "follow-up: {symptoms} gihapon wala ayo",
    "after meds, {symptoms} pa man",
    "bisan naginom na medicine, {symptoms}",
]

REALISTIC_MIXED = [
    "may {symptoms_hil} kag also {symptoms_en}",
    "doc {symptoms_hil} plus {symptoms_en}",
    "{symptoms_hil} then {symptoms_en} since yesterday",
    "pasyente: {symptoms_hil} and {symptoms_en}",
    "mixed complaint {symptoms_hil} / {symptoms_en}",
    "type ko {symptoms_hil} kag {symptoms_en}",
    "hiligaynon: {symptoms_hil} english: {symptoms_en}",
    "consult {symptoms_hil} also reports {symptoms_en}",
    "{symptoms_hil} plus {symptoms_en} 3 days",
    "online form {symptoms_hil} and {symptoms_en}",
    # Code-switching mid-sentence, which is how most Ilonggo patients actually type.
    "doc ang {symptoms_hil} kag may {symptoms_en} man",
    "may {symptoms_hil} ako tapos {symptoms_en}",
    "{symptoms_en} kag {symptoms_hil} halin kagabi",
    "sabi ni nanay {symptoms_hil}, sa akon {symptoms_en}",
    "una {symptoms_hil} dayon nagsunod ang {symptoms_en}",
    "aside sa {symptoms_hil} may {symptoms_en} pa gid",
]

CHAT_NOISE = [
    "",
    " ",
    "... ",
    " po ",
    " doc ",
    " pls ",
    " thanks ",
]


def _light_typo(text: str, rng: random.Random) -> str:
    if rng.random() > 0.12:
        return text
    words = text.split()
    if not words:
        return text
    i = rng.randint(0, len(words) - 1)
    w = words[i]
    if len(w) > 4 and rng.random() < 0.5:
        j = rng.randint(1, len(w) - 2)
        w = w[:j] + w[j + 1] + w[j]
    else:
        w = w.lower()
    words[i] = w
    return " ".join(words)


def _assign_split_realistic(disease: str) -> str:
    return assign_split(disease, seed=2026, group="realistic")


def build_realistic_cases() -> list[dict[str, str | int]]:
    dictionary = load_dictionary_hiligaynon()
    rows = load_disease_symptom_rows()
    rng = random.Random(2026)
    cases: list[dict[str, str | int]] = []
    case_id = 0

    en_pool = REALISTIC_EN[:VARIANTS_PER_SIGNATURE]
    hil_pool = REALISTIC_HIL[:VARIANTS_PER_SIGNATURE]

    for disease, symptom_keys in rows:
        english_phrases = [symptom_to_english_phrase(k) for k in symptom_keys]
        hil_phrases = [hiligaynon_phrase_for_symptom(k, dictionary) for k in symptom_keys]
        symptom_blob = ";".join(symptom_keys)
        en_joined = join_phrases(english_phrases, "english")
        hil_joined = join_phrases(hil_phrases, "hiligaynon")

        for template in en_pool:
            case_id += 1
            noise = rng.choice(CHAT_NOISE)
            transcript = noise + template.format(symptoms=en_joined)
            transcript = _light_typo(transcript.strip(), rng)
            cases.append(
                {
                    "case_id": f"RT-{case_id:06d}",
                    "disease": disease,
                    "language": "english",
                    "transcript": transcript,
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"realistic_en_{case_id}",
                    "split": _assign_split_realistic(disease),
                    "source": "realistic_patient_typing",
                }
            )

        for template in hil_pool:
            case_id += 1
            noise = rng.choice(CHAT_NOISE)
            transcript = noise + template.format(symptoms=hil_joined)
            transcript = _light_typo(transcript.strip(), rng)
            cases.append(
                {
                    "case_id": f"RT-{case_id:06d}",
                    "disease": disease,
                    "language": "hiligaynon",
                    "transcript": transcript,
                    "symptom_keys": symptom_blob,
                    "symptom_count": len(symptom_keys),
                    "template_id": f"realistic_hil_{case_id}",
                    "split": _assign_split_realistic(disease),
                    "source": "realistic_patient_typing",
                }
            )

        if len(symptom_keys) >= 2:
            mid = max(1, len(symptom_keys) // 2)
            hil_part = join_phrases(hil_phrases[:mid], "hiligaynon")
            en_part = join_phrases(english_phrases[mid:], "english")
            if len(symptom_keys) == 2:
                hil_part = join_phrases(hil_phrases[:1], "hiligaynon")
                en_part = join_phrases(english_phrases[1:], "english")
            for template in REALISTIC_MIXED:
                case_id += 1
                transcript = template.format(symptoms_hil=hil_part, symptoms_en=en_part)
                transcript = _light_typo(transcript.strip(), rng)
                cases.append(
                    {
                        "case_id": f"RT-{case_id:06d}",
                        "disease": disease,
                        "language": "mixed",
                        "transcript": transcript,
                        "symptom_keys": symptom_blob,
                        "symptom_count": len(symptom_keys),
                        "template_id": f"realistic_mix_{case_id}",
                        "split": _assign_split_realistic(disease),
                        "source": "realistic_patient_typing",
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
    cases = build_realistic_cases()
    write_outputs(cases)
    by_lang: dict[str, int] = {}
    by_split: dict[str, int] = {}
    for row in cases:
        by_lang[str(row["language"])] = by_lang.get(str(row["language"]), 0) + 1
        by_split[str(row["split"])] = by_split.get(str(row["split"]), 0) + 1
    print("medConnect realistic patient typing scenarios")
    print("===========================================")
    print(f"Output: {OUT_CSV}")
    print(f"Total:  {len(cases)}")
    print(f"Lang:   {by_lang}")
    print(f"Split:  {by_split}")
    print()
    print("Next: python scripts/data/merge_patient_training_corpus.py")


if __name__ == "__main__":
    main()
