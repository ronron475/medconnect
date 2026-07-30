"""
Expand Hiligaynon NLP coverage for all archive symptom keys.

Adds colloquial patient phrasing (chat, barangay, elderly, third-person) into:
  - data/nlp/hiligaynon_symptom_phrases.json   (generator + translator)
  - data/nlp/hiligaynon_symptom_lexicon.json   (fuzzy matcher)
  - data/nlp/medical_dictionary.csv           (exact local→English)
  - data/nlp/hiligaynon_nlp_expansion_2026.csv (audit trail)

Every new phrase must round-trip to its symptom key after registration.
Then run: expand_hiligaynon_variants.py → rebuild corpus → retrain.

Run:
    python scripts/data/expand_hiligaynon_nlp_coverage.py
    python scripts/data/expand_hiligaynon_nlp_coverage.py --dry-run
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

PHRASE_FILE = ROOT / "data" / "nlp" / "hiligaynon_symptom_phrases.json"
LEXICON = ROOT / "data" / "nlp" / "hiligaynon_symptom_lexicon.json"
DICTIONARY = ROOT / "data" / "nlp" / "medical_dictionary.csv"
AUDIT = ROOT / "data" / "nlp" / "hiligaynon_nlp_expansion_2026.csv"
META = ROOT / "ai_service" / "models" / "disease_classifier_meta.json"

# Extra colloquial / chat / elderly / barangay forms. Keys must be archive symptom keys.
# Keep phrases multi-word where possible so they do not collide with body-part-only terms.
EXTRA: dict[str, list[str]] = {
    "abdominal_pain": [
        "masakit ang tiyan ko",
        "ginabaldom ang tiyan",
        "sakit tiyan gid",
        "may sakit sa busong",
        "nagabalati sang sakit tiyan",
    ],
    "abnormal_menstruation": [
        "indi regular ang pamulanon",
        "nagabag-o ang regla",
        "may problema sa binulan",
        "late ang pamulanon",
        "sobra kadugo ang regla",
    ],
    "acidity": [
        "may asido ako",
        "nagakabog ang tiyan ko",
        "maaslom ang tiyan",
        "gasido ang dughan",
    ],
    "acute_liver_failure": [
        "grabe na ang atay",
        "indi na maayo ang atay",
        "naguba ang atay",
    ],
    "altered_sensorium": [
        "daw nalipong ang panumduman",
        "indi klaro ang huna-huna",
        "daw wala sa husto ang panumduman",
        "confused ang pamatyagan",
    ],
    "anxiety": [
        "ginakulbaan gid ako",
        "permi balisa",
        "may anxiety ako",
        "ginakabalaka ako",
        "wala ako kahilwayan",
    ],
    "back_pain": [
        "masakit ang likod ko",
        "sakit likod gid",
        "ginasakit ang baywang",
        "may sakit sa likod",
    ],
    "belly_pain": [
        "sakit ang busong ko",
        "ginasakit ang busong",
        "may sakit sa tiyan",
    ],
    "blackheads": [
        "may blackheads sa guya",
        "itom nga tuldok sa nawong",
        "may itom nga heads sa panit",
    ],
    "bladder_discomfort": [
        "sakit ang pantog",
        "indi komportable kon mag-ihi",
        "may discomfort sa pantog",
    ],
    "blister": [
        "may paltos ako",
        "nagapaltos",
        "may lintog",
        "may blister sa panit",
    ],
    "blood_in_sputum": [
        "may dugo sa plema",
        "nagadugo kon magubo",
        "pula ang plema",
    ],
    "bloody_stool": [
        "may dugo sa tai",
        "nagadugo ang tai",
        "pula ang tai",
        "bloody stool",
    ],
    "blurred_and_distorted_vision": [
        "malubog ang mata",
        "indi maathag magtan-aw",
        "daw double vision",
        "blurred ang panulok",
    ],
    "breathlessness": [
        "kapos ginhawa",
        "budlay ginhawa",
        "ginahapo ako",
        "short of breath",
        "indi makaginhawa maayo",
    ],
    "brittle_nails": [
        "madali mabali ang kuko",
        "mahuyang ang kuko",
        "naga-break ang kuko",
    ],
    "bruising": [
        "madali magpasa",
        "may pasa",
        "naga-bruise ang panit",
        "asul ang panit",
    ],
    "burning_micturition": [
        "hapdi mag-ihi",
        "nagahapdi ang ihi",
        "sakit kon mag-ihi",
        "burning urination",
    ],
    "chest_pain": [
        "sakit dughan",
        "hapdi dughan",
        "ginasakit ang dughan ko",
        "may sakit sa dughan",
        "chest pain",
    ],
    "chills": [
        "ginapanas-an",
        "nagapalamig",
        "ginatugnaw",
        "may chills",
        "ginakaligtan",
    ],
    "cold_hands_and_feets": [
        "malamig kamot kag tiil",
        "ginalamigan ang kamot",
        "malamig ang tiil",
        "cold hands and feet",
    ],
    "coma": [
        "wala sang kalibutan",
        "indi magmata gid",
        "nadulaan kalibutan",
        "unconscious patient",
    ],
    "congestion": [
        "barado ilong",
        "napun-an ilong",
        "barado ang ilong ko",
        "nasal congestion",
        "indi makaginhawa sa ilong",
    ],
    "constipation": [
        "indi makalibang",
        "matig-a tai",
        "budlay maglibang",
        "constipation",
    ],
    "continuous_feel_of_urine": [
        "permi gusto mag-ihi",
        "daw may ihi permi",
        "wala untat ang gana mag-ihi",
        "frequent urge to urinate",
    ],
    "continuous_sneezing": [
        "permi nagabahin",
        "wala untat bahin",
        "continuous sneezing",
        "madamo nga bahin",
    ],
    "cough": [
        "nagaubo",
        "ginaubo",
        "may ubo",
        "permi nagaubo",
        "ubo ako",
        "dry cough",
    ],
    "cramps": [
        "ginapulikat",
        "may pulikat",
        "naga-cramp",
        "pulikat sa tiyan",
    ],
    "dark_urine": [
        "itom ihi",
        "dulom ihi",
        "madulom ang ihi",
        "dark urine",
    ],
    "dehydration": [
        "kulang tubig",
        "permi ginauhaw",
        "dehydrated",
        "uga ang baba",
    ],
    "depression": [
        "permi masubo",
        "wala gana sa kabuhi",
        "depressed",
        "ginakasubo",
    ],
    "diarrhoea": [
        "nagakalibanga",
        "kalibanga",
        "malapsi ang tai",
        "diarrhea",
        "permi nagalibang",
    ],
    "dischromic_patches": [
        "may tapal sa panit",
        "nagbag-o ang kolor sang panit",
        "may puti ukon itom nga tapal",
    ],
    "distention_of_abdomen": [
        "nagabusdik tiyan",
        "nagdako tiyan",
        "busdik ang tiyan",
        "bloated belly",
    ],
    "dizziness": [
        "nagalipong",
        "ginalipong",
        "dizzy",
        "lipong ulo",
        "daw magkalipong",
    ],
    "drying_and_tingling_lips": [
        "mala ang bibig",
        "nagamala ang ngabil",
        "tingling ang bibig",
        "uga kag tingling ang lips",
    ],
    "enlarged_thyroid": [
        "nagdako ang bosyo",
        "may bosyo",
        "hubag ang thyroid",
        "goiter",
    ],
    "excessive_hunger": [
        "permi ginagutom",
        "sobra gutom",
        "wala kabusog",
        "excessive hunger",
    ],
    "extra_marital_contacts": [
        "may iban nga kapareha",
        "may relasyon sa gwa",
        "extra marital",
    ],
    "family_history": [
        "may kaso sa pamilya",
        "namana sa pamilya",
        "family history",
        "may ara sa parentes",
    ],
    "fast_heart_rate": [
        "kusog pitik sang tagipusuon",
        "madasig ang heart beat",
        "nagakubakuba ang puso",
        "fast heart rate",
        "tachycardia",
    ],
    "fatigue": [
        "ginakapoy",
        "kapoy lawas",
        "kakapoy",
        "pagod",
        "fatigue",
    ],
    "fluid_overload": [
        "sobra tubig sa lawas",
        "fluid overload",
        "nagahubag tungod tubig",
    ],
    "foul_smell_of_urine": [
        "mabaho ihi",
        "may baho ang ihi",
        "foul smelling urine",
    ],
    "headache": [
        "sakit ulo",
        "labad ulo",
        "ginasakit ang ulo",
        "headache",
        "may sakit ulo",
    ],
    "high_fever": [
        "mataas hilanat",
        "grabe hilanat",
        "taas nga hilanat",
        "high fever",
        "mainit gid ang lawas",
    ],
    "hip_joint_pain": [
        "sakit balikawang",
        "sakit sa hip",
        "ginasakit ang hip joint",
    ],
    "history_of_alcohol_consumption": [
        "nagainom alak",
        "hilig mag-inom",
        "drinker",
        "may history sa inom",
    ],
    "increased_appetite": [
        "nagdugang gana magkaon",
        "mas gutom kaysa sang una",
        "increased appetite",
    ],
    "indigestion": [
        "indi matunaw",
        "nagabalda tiyan",
        "indigestion",
        "mabug-at ang tiyan",
    ],
    "inflammatory_nails": [
        "hubag ang kuko",
        "pula ang palibot sang kuko",
        "infected nails",
    ],
    "internal_itching": [
        "katol sa sulod",
        "ginakatol sa sulod",
        "internal itching",
    ],
    "irregular_sugar_level": [
        "indi stable ang asukar",
        "nagataas ang blood sugar",
        "irregular sugar",
        "high sugar",
    ],
    "irritability": [
        "madali maakig",
        "mainiton ulo",
        "irritable",
        "permi naakig",
    ],
    "irritation_in_anus": [
        "katol sa lubot",
        "may irritation sa anus",
        "ginakatol ang lubot",
    ],
    "itching": [
        "ginakatol",
        "makatol",
        "katol panit",
        "itching",
        "permi ginakatol",
    ],
    "joint_pain": [
        "sakit lutahan",
        "ginasakit ang lutahan",
        "joint pain",
        "sakit sa joints",
    ],
    "knee_pain": [
        "sakit tuhod",
        "ginasakit ang tuhod",
        "knee pain",
    ],
    "lack_of_concentration": [
        "indi makapokus",
        "wala konsentrasyon",
        "cannot concentrate",
        "daw nagalayag ang huna-huna",
    ],
    "lethargy": [
        "luya lawas",
        "wala gana maghulag",
        "lethargy",
        "mahuya maglihok",
    ],
    "loss_of_appetite": [
        "wala gana magkaon",
        "gakadula gana kaon",
        "loss of appetite",
        "indi gusto magkaon",
    ],
    "loss_of_balance": [
        "indi makabalanse",
        "wala balanse",
        "loss of balance",
        "daw matumba",
    ],
    "loss_of_smell": [
        "indi makapanimaho",
        "wala ko maka-amim",
        "loss of smell",
        "indi makasimhot",
    ],
    "malaise": [
        "indi maayo lawas",
        "hindi maayo lawas",
        "daw masakiton",
        "malaise",
        "indi maayo pamatyagan",
    ],
    "mild_fever": [
        "gamay hilanat",
        "diutay hilanat",
        "mild fever",
        "may gamay nga init",
    ],
    "mood_swings": [
        "nagabag-o ang buot",
        "mood swings",
        "kis-a malipay kis-a masubo",
    ],
    "movement_stiffness": [
        "matig-a maghulag",
        "stiff movements",
        "budlay maglihok",
    ],
    "mucoid_sputum": [
        "makapal plema",
        "malagkit plema",
        "thick phlegm",
    ],
    "muscle_pain": [
        "sakit kalamnan",
        "sakit sa kalanm",
        "muscle pain",
        "sakit ang muscle",
    ],
    "muscle_wasting": [
        "naganiwang ang muscle",
        "nagagamay ang kalamnan",
        "muscle wasting",
    ],
    "muscle_weakness": [
        "maluya muscle",
        "wala kusog ang muscle",
        "muscle weakness",
        "mahuyang ang kalamnan",
    ],
    "nausea": [
        "daw masuka",
        "nahilo",
        "nauseous",
        "ginasuka-suka",
        "daw magkasuka",
    ],
    "neck_pain": [
        "sakit liog",
        "ginasakit ang liog",
        "neck pain",
        "sakti liog",
    ],
    "nodal_skin_eruptions": [
        "may bukol sa panit",
        "nagabuswang nga bukol",
        "skin nodules",
    ],
    "obesity": [
        "sobra timbang",
        "matambok",
        "obese",
        "sobra ka tambok",
    ],
    "pain_behind_the_eyes": [
        "sakit sa likod sang mata",
        "pain behind the eyes",
        "ginasakit ang likod mata",
    ],
    "pain_during_bowel_movements": [
        "sakit kon magalibang",
        "pain during bowel movement",
        "hapdi maglibang",
    ],
    "pain_in_anal_region": [
        "sakit sa lubot",
        "pain in anus",
        "hapdi ang lubot",
    ],
    "painful_walking": [
        "sakit kon magalakat",
        "painful walking",
        "budlay maglakat tungod sakit",
    ],
    "palpitations": [
        "nagakubakuba",
        "daw nagalukso ang puso",
        "palpitations",
        "kubakuba dughan",
    ],
    "passage_of_gases": [
        "nagapanuhot",
        "permi panuhot",
        "gas",
        "ginapanuhot",
    ],
    "patches_in_throat": [
        "may puti sa tutunlan",
        "patches in throat",
        "may tapal sa throat",
    ],
    "phlegm": [
        "may plema",
        "madamo plema",
        "phlegm",
        "may uhog",
    ],
    "polyuria": [
        "permi nagaihi",
        "madamo ihi",
        "frequent urination",
        "permi mag-ihi",
    ],
    "prominent_veins_on_calf": [
        "makita ang ugat sa batiis",
        "nagabukol ugat sa paa",
        "varicose sa batiis",
    ],
    "puffy_face_and_eyes": [
        "hubag guya kag mata",
        "puffy face",
        "nagahubag ang nawong",
    ],
    "pus_filled_pimples": [
        "tagulhod may nana",
        "pimples with pus",
        "may nana nga tagulhod",
        "may acne nga may nana",
    ],
    "receiving_blood_transfusion": [
        "nakabaton dugo",
        "blood transfusion",
        "gintapnaan sang dugo",
    ],
    "receiving_unsterile_injections": [
        "indi sterile nga iniksyon",
        "unsterile injection",
        "ginturukan sang indi limpyo",
    ],
    "red_sore_around_nose": [
        "pula sugat sa ilong",
        "sore around nose",
        "pula sa palibot ilong",
    ],
    "red_spots_over_body": [
        "pula tuldok sa lawas",
        "red spots",
        "may pula nga spots",
        "pula nga tulod sa lawas",
    ],
    "redness_of_eyes": [
        "pula mata",
        "nagapula mata",
        "red eyes",
        "gapula mata",
    ],
    "restlessness": [
        "indi mapahamtang",
        "restless",
        "balisa",
        "wala kapahuway",
    ],
    "runny_nose": [
        "may sip-on",
        "nagatulo sip-on",
        "runny nose",
        "ginasip-on",
        "sipon",
    ],
    "rusty_sputum": [
        "kolor kalawang plema",
        "rusty sputum",
        "daw kaal nga plema",
        "brownish plema",
    ],
    "scurring": [
        "may peklat",
        "scarring",
        "nagakalas peklat",
    ],
    "shivering": [
        "nagakurog",
        "ginakurog",
        "shivering",
        "naga-tremble",
    ],
    "silver_like_dusting": [
        "pilak nga abo sa panit",
        "silver like dusting",
        "puti nga pulbos sa panit",
    ],
    "sinus_pressure": [
        "pressure sa sinus",
        "bug-at ang guya",
        "sinus pressure",
        "sakit sa sinus",
    ],
    "skin_peeling": [
        "nagakalas panit",
        "skin peeling",
        "nagapanit",
    ],
    "skin_rash": [
        "may rashes",
        "skin rash",
        "nagagwa rashes",
        "may allergy sa panit",
    ],
    "slurred_speech": [
        "indi klaro maghambal",
        "slurred speech",
        "nagakulinggat",
    ],
    "small_dents_in_nails": [
        "may lugum sa kuko",
        "dents in nails",
        "gagmay buho sa kuko",
    ],
    "spinning_movements": [
        "daw nagatuyok",
        "spinning",
        "tuyok ang palibot",
        "vertigo",
    ],
    "spotting_urination": [
        "may dugo sa ihi",
        "spotting urine",
        "may tulo dugo kon mag-ihi",
    ],
    "stiff_neck": [
        "matig-a liog",
        "stiff neck",
        "sakit kag matig-a liog",
    ],
    "stomach_bleeding": [
        "nagadugo tiyan",
        "stomach bleeding",
        "may pagdugo sa tiyan",
        "nagdugo tiyan",
    ],
    "stomach_pain": [
        "masakit tiyan",
        "sakit tiyan",
        "stomach pain",
        "ginasakit tiyan",
    ],
    "sunken_eyes": [
        "nagtulon mata",
        "sunken eyes",
        "nagalubog ang mata",
    ],
    "sweating": [
        "nagabalhas",
        "madamo balhas",
        "sweating",
        "permi ginabalhas",
    ],
    "swelled_lymph_nodes": [
        "hubag kulani",
        "swelled lymph nodes",
        "may bukol sa liog",
    ],
    "swelling_joints": [
        "hubag lutahan",
        "swelling joints",
        "nagahubag ang joints",
    ],
    "swelling_of_stomach": [
        "hubag tiyan",
        "swelling of stomach",
        "nagahubag ang tiyan",
    ],
    "swollen_blood_vessels": [
        "hubag ugat",
        "swollen veins",
        "nagahubag ang ugat",
    ],
    "swollen_extremeties": [
        "hubag kamot kag tiil",
        "swollen hands and feet",
        "nagahubag extremities",
    ],
    "swollen_legs": [
        "hubag tiil",
        "swollen legs",
        "nagahubag ang paa",
    ],
    "throat_irritation": [
        "katol tutunlan",
        "sakit tutunlan",
        "throat irritation",
        "hapdi ang throat",
    ],
    "toxic_look_(typhos)": [
        "masakiton ang hitsura",
        "toxic look",
        "daw grabe ka luya hitsura",
    ],
    "ulcers_on_tongue": [
        "may singaw",
        "ulcers on tongue",
        "may singaw sa dila",
    ],
    "unsteadiness": [
        "indi steady maglakat",
        "unsteady",
        "nagakiday-kiday",
    ],
    "visual_disturbances": [
        "problema sa panulok",
        "visual disturbance",
        "indi maayo magtan-aw",
    ],
    "vomiting": [
        "nagasuka",
        "nagsuka",
        "vomiting",
        "permi nagasuka",
        "ginsuka",
    ],
    "watering_from_eyes": [
        "nagatubig mata",
        "watery eyes",
        "permi nagaluha",
    ],
    "weakness_in_limbs": [
        "maluya bukton kag tiil",
        "weakness in limbs",
        "wala kusog ang tiil",
    ],
    "weakness_of_one_body_side": [
        "maluya isa ka bahin",
        "weakness one side",
        "wala kusog ang isa ka side",
        "hemiparesis",
    ],
    "weight_gain": [
        "nagdugang timbang",
        "weight gain",
        "nagtambok",
    ],
    "weight_loss": [
        "nagakunhod timbang",
        "weight loss",
        "nagniwang",
        "wie loss",
    ],
    "yellow_crust_ooze": [
        "dilaw nana",
        "yellow crust",
        "nagagwa dilaw likido",
    ],
    "yellow_urine": [
        "dilaw ihi",
        "yellow urine",
        "nagadilaw ihi",
    ],
    "yellowing_of_eyes": [
        "dilaw mata",
        "yellowing of eyes",
        "gapula mata kag dilaw",
        "nagadilaw ang mata",
    ],
    "yellowish_skin": [
        "dilaw panit",
        "yellowish skin",
        "nagadilaw panit",
        "dial panit",
    ],
}


CATEGORY_HINTS = {
    "pain": "musculoskeletal",
    "fever": "general",
    "urine": "urinary",
    "skin": "skin",
    "cough": "respiratory",
    "heart": "cardiovascular",
    "eye": "eye_ear",
    "liver": "hepatic",
    "throat": "respiratory",
}


def category_for(key: str) -> str:
    for token, cat in CATEGORY_HINTS.items():
        if token in key:
            return cat
    return "general"


def english_for(key: str) -> str:
    return key.replace("_", " ").split(" (")[0]


def main() -> int:
    parser = argparse.ArgumentParser(description="Expand Hiligaynon NLP coverage")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    meta = json.loads(META.read_text(encoding="utf-8"))
    columns = list(meta.get("symptom_columns") or [])
    if not columns:
        print("No model symptom columns; train classifier first.")
        return 1

    payload = json.loads(PHRASE_FILE.read_text(encoding="utf-8"))
    phrases: dict[str, list[str]] = payload.setdefault("phrases", {})

    added_phrases = 0
    for key in columns:
        existing = {p.strip().lower() for p in phrases.get(key, [])}
        merged = list(phrases.get(key, []))
        for phrase in EXTRA.get(key, []):
            p = phrase.strip()
            if not p or p.lower() in existing:
                continue
            merged.append(p)
            existing.add(p.lower())
            added_phrases += 1
        if key not in phrases and not merged:
            # Ensure every model key has at least the English wording as a last resort.
            merged = [english_for(key)]
            added_phrases += 1
        phrases[key] = merged

    if args.dry_run:
        print(f"Would add {added_phrases} phrases across {len(columns)} symptoms")
        return 0

    PHRASE_FILE.write_text(
        json.dumps(payload, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    print(f"Updated {PHRASE_FILE} (+{added_phrases} phrases)")

    # Round-trip gate using the freshly written phrases (analyzer caches, so reload).
    import importlib
    import analyzer
    import disease_predictor

    importlib.reload(analyzer)
    importlib.reload(disease_predictor)

    failures: list[tuple[str, str, list[str]]] = []
    for key, variants in phrases.items():
        if key not in columns:
            continue
        for phrase in variants:
            found = disease_predictor.extract_model_symptoms(
                analyzer.translate_hiligaynon(phrase)
            )
            if key not in found:
                failures.append((key, phrase, found))

    if failures:
        print(f"WARNING: {len(failures)} phrases do not round-trip yet (kept; variants may fix):")
        for key, phrase, found in failures[:12]:
            print(f"  {key}: {phrase!r} -> {found}")

    # Lexicon sync for all curated phrases.
    lex = json.loads(LEXICON.read_text(encoding="utf-8"))
    symptoms = lex.setdefault("symptoms", {})
    lex_added = 0
    for key, variants in phrases.items():
        if key not in columns:
            continue
        entry = symptoms.get(key)
        if not isinstance(entry, dict):
            entry = {
                "english": english_for(key),
                "medical_term": key,
                "category": category_for(key),
                "hiligaynon": [],
            }
            symptoms[key] = entry
        hil = list(entry.get("hiligaynon") or [])
        existing = {p.lower().strip() for p in hil}
        for phrase in variants:
            p = phrase.strip()
            if p and p.lower() not in existing:
                hil.append(p)
                existing.add(p.lower())
                lex_added += 1
        entry["hiligaynon"] = hil
    LEXICON.write_text(json.dumps(lex, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Updated {LEXICON} (+{lex_added} lexicon variants)")

    # Dictionary: local_term → english (skip rows that already exist).
    fieldnames = ["id", "local_term", "english_term", "category"]
    existing_pairs: set[tuple[str, str]] = set()
    rows: list[dict[str, str]] = []
    next_id = 1
    if DICTIONARY.is_file():
        with DICTIONARY.open(encoding="utf-8", newline="") as handle:
            reader = csv.DictReader(handle)
            fieldnames = list(reader.fieldnames or fieldnames)
            for row in reader:
                rows.append(row)
                local = (row.get("local_term") or "").strip().lower()
                eng = (row.get("english_term") or "").strip().lower()
                if local and eng:
                    existing_pairs.add((local, eng))
                try:
                    next_id = max(next_id, int(row.get("id") or 0) + 1)
                except ValueError:
                    pass

    dict_added = 0
    audit_rows: list[dict[str, str]] = []
    for key, variants in phrases.items():
        if key not in columns:
            continue
        eng = english_for(key)
        for phrase in variants:
            local = phrase.strip().lower()
            if not local or (local, eng.lower()) in existing_pairs:
                continue
            # Avoid overwriting English symptom wording with itself when already present.
            if local == eng.lower():
                continue
            rows.append(
                {
                    "id": str(next_id),
                    "local_term": phrase.strip(),
                    "english_term": eng,
                    "category": "symptom",
                }
            )
            existing_pairs.add((local, eng.lower()))
            next_id += 1
            dict_added += 1
            audit_rows.append(
                {
                    "symptom_key": key,
                    "local_term": phrase.strip(),
                    "english_term": eng,
                    "source": "expand_hiligaynon_nlp_coverage",
                }
            )

    with DICTIONARY.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)
    print(f"Updated {DICTIONARY} (+{dict_added} dictionary rows)")

    with AUDIT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(
            handle,
            fieldnames=["symptom_key", "local_term", "english_term", "source"],
        )
        writer.writeheader()
        writer.writerows(audit_rows)
    print(f"Wrote audit {AUDIT} ({len(audit_rows)} rows)")

    print()
    print("Next:")
    print("  python scripts/data/expand_hiligaynon_variants.py")
    print("  python scripts/data/build_patient_training_dataset.py")
    print("  python scripts/data/build_realistic_patient_scenarios.py")
    print("  python scripts/data/build_chief_complaint_scenarios.py")
    print("  python scripts/data/merge_patient_training_corpus.py")
    print("  python scripts/dev/check_symptom_roundtrip.py")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
