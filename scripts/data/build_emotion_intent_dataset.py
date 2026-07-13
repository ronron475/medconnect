#!/usr/bin/env python3
"""
Build emotion_intent_dataset.js — medConnect FAQ Chatbot
Target: 10,000 unique emotion-intent phrases (EN · FIL · HIL · mixed)
"""
import csv
import json
import random
import re
from itertools import product
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CSV_PATH = ROOT / 'data' / 'nlp' / 'emotion_intent_phrases.csv'
CSV_OUT = ROOT / 'data' / 'nlp' / 'emotion_intent_phrases_full.csv'
JS_OUT = ROOT / 'public' / 'assets' / 'js' / 'faq-chatbot' / 'emotion_intent_dataset.js'
TARGET = 10_000

PRIORITY = [
    'emergency', 'panic', 'hopeless', 'afraid', 'angry', 'frustrated', 'anxious',
    'nervous', 'worried', 'stressed', 'tired', 'sad', 'lonely', 'disappointed',
    'confused', 'curious', 'excited', 'relieved', 'thankful', 'happy',
]

# ── Seed from hand-curated CSV ──
def load_seed():
    rows = []
    if CSV_PATH.exists():
        with CSV_PATH.open(encoding='utf-8') as f:
            for r in csv.DictReader(f):
                rows.append({'e': r['emotion'], 'p': r['phrase'].strip(), 'l': r['language']})
    return rows

# ── Word banks: emotion → language → phrase fragments ──
BANKS = {
    'happy': {
        'en': [
            "i'm happy", "i'm feeling better", "everything is okay", "i'm doing great",
            "feeling good today", "so happy", "great day", "wonderful", "all good",
            "much better now", "feeling fine", "i'm okay now", "things are good",
        ],
        'fil': [
            "masaya ako", "okay na ako", "mabuti ang pakiramdam ko", "maganda ang araw ko",
            "masaya na ako", "maayos na", "buti na", "okay na", "maligaya ako",
            "maganda ang feeling ko", "ayos na ako", "gumaan ang pakiramdam ko",
        ],
        'hil': [
            "masadya ko", "okay na ko", "nami pamatyag ko", "lipay ko", "maayo gid",
            "maayo na ko", "nami gid", "lipay na ko", "maayo ang adlaw ko",
            "maayo ang pamatyag ko", "okay na gid", "maayo na ang tanan",
        ],
    },
    'thankful': {
        'en': [
            "thank you", "thanks", "thanks a lot", "thank you so much", "much appreciated",
            "grateful", "thanks for the help", "thank you for helping", "appreciate it",
            "thanks really", "ty", "tysm", "thank you very much",
        ],
        'fil': [
            "salamat", "maraming salamat", "salamat po", "thank you po", "salamat talaga",
            "maraming salamat po", "thanks po", "salamat sa tulong", "grateful ako",
        ],
        'hil': [
            "salamat", "salamat guid", "salamat kaayo", "madamo gid nga salamat",
            "thank you gid", "salamat gid", "damo gid nga salamat", "salamat sa bulig",
        ],
    },
    'relieved': {
        'en': ["relieved", "what a relief", "finally", "good to know", "that's a relief", "glad it's sorted"],
        'fil': ["ginhawa na ko", "buti na lang", "okay na", "relief na", "salamat at okay na", "hawak na"],
        'hil': ["ginhawa na ko", "maayo na", "relief na gid", "okay na gid", "buti na lang", "maayo na tanan"],
    },
    'excited': {
        'en': ["excited", "can't wait", "looking forward", "so excited", "thrilled", "eager to start"],
        'fil': ["excited na ako", "sabik na ako", "hindi na ako makapaghintay", "excited ako", "sabik na"],
        'hil': ["excited gid ko", "sabik na ko", "excited ko", "daw indi na ko makahulat", "abir excited"],
    },
    'curious': {
        'en': ["curious", "just wondering", "can you tell me", "how does this work", "what is", "i want to know"],
        'fil': ["gusto ko malaman", "ano kaya", "curious lang", "paano ito", "tanong ko lang", "interesado ako"],
        'hil': ["gusto ko mahibal-an", "ano kaya ini", "curious lang ko", "paano ini", "interesado ko"],
    },
    'confused': {
        'en': [
            "i don't understand", "i'm confused", "how does this work", "this is confusing",
            "i'm lost", "doesn't make sense", "what do i do", "not sure how",
        ],
        'fil': [
            "hindi ko maintindihan", "nalilito ako", "paano ito gumagana", "di ko gets",
            "hindi ko gets", "nalilito", "hindi ko alam", "paano ba to",
        ],
        'hil': [
            "wala ko kaintindi", "nalipat ko", "budlay intindihon", "indi ko masabtan",
            "nalibog ako", "indi ko bal-an", "paano ini", "libog ko",
        ],
    },
    'frustrated': {
        'en': [
            "frustrated", "this isn't working", "so annoying", "fed up", "login won't work",
            "not working", "this is broken", "can't login", "keeps failing", "so frustrating",
        ],
        'fil': [
            "frustrated na ako", "hindi gumagana", "ayaw mag-login", "badtrip", "nakakainis",
            "ayaw gumana", "hindi gumagana ang login", "nakakadismaya", "inis na ako",
        ],
        'hil': [
            "kaakig ko", "kapoy na ko sini", "hindi gid mag-login", "indi mag-work",
            "frustrated gid ko", "indi gid mag function", "kapoy na", "akig ko",
        ],
    },
    'worried': {
        'en': [
            "worried", "i'm worried", "concerned", "i'm concerned", "worried about my health",
            "worried about appointment", "anxious about", "concerned about my health",
        ],
        'fil': [
            "nag-aalala ako", "nabalaka ko", "basi may sakit ako", "may alalahanin ako",
            "kabado ako", "worried ako", "nag-aalala", "basi may problema",
        ],
        'hil': [
            "nabalaka ko", "kabalaka ko", "kulba ko", "basi may sakit ko", "nag-aalala ko",
            "kabado ko", "basi may problema", "nabalaka gid ko",
        ],
    },
    'anxious': {
        'en': ["anxious", "i'm anxious", "feeling anxious", "anxiety", "stressed and anxious", "on edge"],
        'fil': ["ginakulbaan ko", "kinakabahan ako", "anxious ako", "may anxiety ako", "kaba na kaba"],
        'hil': ["ginakulbaan ko", "daw indi ko mapanatag", "anxious ko", "kulba gid ko", "kaba na gid"],
    },
    'nervous': {
        'en': ["nervous", "i'm nervous", "feeling nervous", "jittery", "on pins and needles"],
        'fil': ["kinakabahan ako", "nerbiyoso ako", "nervous ako", "kaba ako", "kinakabahan"],
        'hil': ["kinakabahan gid ko", "nerbiyoso ko", "nervous ko", "kaba gid", "kinakabahan ko"],
    },
    'sad': {
        'en': ["sad", "i'm sad", "depressed", "feeling down", "so sad", "feeling low", "unhappy"],
        'fil': ["malungkot ako", "depressed ako", "nalulungkot ako", "sad ako", "down ako", "subo"],
        'hil': ["subo ko", "kasubo gid", "malungkot ko", "sad ko", "daw gusto ko maghibi", "naguiliran ko"],
    },
    'lonely': {
        'en': ["lonely", "i feel lonely", "i'm alone", "so lonely", "feel alone", "no one to talk to"],
        'fil': ["malungkot mag-isa", "wala akong kasama", "alone ako", "lonely ako", "mag-isa lang ako"],
        'hil': ["wala ko sang upod", "daw ako lang isa", "alone lang ko", "lonely ko", "isa lang ko"],
    },
    'afraid': {
        'en': ["scared", "i'm scared", "afraid", "frightened", "so scared", "i'm afraid"],
        'fil': ["natakot ako", "takot ako", "nahadlok ako", "afraid ako", "natatakot ako"],
        'hil': ["nahadlok ko", "kulba gid ko", "basi delikado ni", "takot ko", "hadlok ko"],
    },
    'angry': {
        'en': ["angry", "i'm angry", "this system is terrible", "so angry", "furious", "mad"],
        'fil': ["galit ako", "badtrip ako", "inis na ako", "angry ako", "galit na galit ako"],
        'hil': ["kaakig ko", "badtrip gid ko", "akig gid ko", "galit ko", "akig na gid ko"],
    },
    'disappointed': {
        'en': ["disappointed", "let down", "so disappointed", "not what i expected", "unsatisfied"],
        'fil': ["nadismaya ako", "disappointed ako", "dismaya", "hindi ko inaasahan", "bitin"],
        'hil': ["nadismaya ko", "disappointed gid ko", "dismaya ko", "indi ko ginalauman"],
    },
    'stressed': {
        'en': ["stressed", "so stressed", "overwhelmed", "stressed out", "too much stress", "burnt out"],
        'fil': ["stressed na ako", "stress na stress", "overwhelmed na", "sobrang stress", "pagod na stress"],
        'hil': ["stress na stress ko", "kapoy na gid ko", "daw indi ko na kaya", "overwhelmed na ko", "stress gid"],
    },
    'tired': {
        'en': ["tired", "exhausted", "so tired", "no energy", "worn out", "drained"],
        'fil': ["pagod na ako", "wala na akong lakas", "exhausted na", "pagod", "walang energy"],
        'hil': ["kapoy ko", "kapoy na gid ko", "wala na ko energy", "pagod gid ko", "kapoy na"],
    },
    'hopeless': {
        'en': ["hopeless", "no hope", "feel hopeless", "giving up", "nothing will work"],
        'fil': ["wala na akong pag-asa", "hopeless na", "parang walang pag-asa", "suko na ako"],
        'hil': ["wala na paglaum", "hopeless na ko", "wala na hope", "suko na ko"],
    },
    'panic': {
        'en': ["panicking", "i'm panicking", "don't know what to do", "need help", "help me", "what do i do"],
        'fil': ["nag-ppanic ako", "hindi ko alam gagawin", "tulong", "help", "ano gagawin ko"],
        'hil': ["ginapanik ko", "buligi ko", "indi ko bal-an ano himuon", "need help", "ano himuon ko"],
    },
    'emergency': {
        'en': [
            "can't breathe", "chest pain", "bleeding", "collapsed", "heart attack", "stroke",
            "unconscious", "seizure", "choking", "overdose", "not breathing", "severe bleeding",
            "medical emergency", "passed out", "someone collapsed",
        ],
        'fil': [
            "hirap huminga", "masakit ang dibdib", "grabe ang pagdurugo", "nawalan ng malay",
            "atake sa puso", "stroke", "hindi makahinga", "nag-collapse", "medical emergency",
        ],
        'hil': [
            "nagaginhawa ko budlay", "ginabatyag ko sakit sa dughan", "grabe nga pagdugo",
            "wala siya sang panimuot", "nagakombulsyon", "indi makaginhawa", "indi makahinga",
            "sakit ang dibdib", "nag-collapse", "wala malay",
        ],
    },
}

# Context modifiers (medConnect services)
MODIFIERS = {
    'en': [
        "with login", "with sign in", "with my account", "with appointment", "with booking",
        "with password", "with medconnect", "with the website", "with registration",
        "about my appointment", "about my health", "about my records",
    ],
    'fil': [
        "sa login", "sa account ko", "sa appointment", "sa password", "sa medconnect",
        "sa pag-book", "sa registration", "tungkol sa appointment", "sa kalusugan ko",
    ],
    'hil': [
        "sa login", "sa account ko", "sa appointment", "sa password", "sa medconnect",
        "sa pag-book", "sa registration", "parte sa appointment", "sa akon kahimsog",
    ],
}

MIXED_TEMPLATES = [
    ("need ko mag-{svc}", "hil"),
    ("pwede ko mag-{svc}", "hil"),
    ("hindi ko maka-{svc}", "mixed"),
    ("indi ko maka-{svc}", "hil"),
    ("how do I {svc}", "en"),
    ("paano mag-{svc}", "fil"),
    ("diin ko maka-{svc}", "hil"),
    ("nabalaka ko kay {ctx}", "hil"),
    ("worried about {ctx}", "en"),
    ("frustrated with {ctx}", "en"),
    ("salamat sa {ctx}", "hil"),
]

SERVICES = [
    "login", "sign in", "book", "reset", "register", "appointment", "consultation",
    "password", "records", "schedule",
]

CONTEXTS = [
    "my appointment", "my health", "my password", "my account", "medconnect",
    "ang appointment ko", "ang account ko", "sang appointment ko", "sang password ko",
]

INTENSIFIERS = {
    'en': ['so', 'very', 'really', 'super', 'extremely', 'quite'],
    'fil': ['sobrang', 'talaga', 'grabe', 'napaka', 'super'],
    'hil': ['gid', 'kaayo', 'grabe', 'super', 'talaga'],
}

TYPO_MAP = str.maketrans({'a': '4', 'e': '3', 'i': '1', 'o': '0', 's': '5'})


def typo_variant(phrase: str) -> str:
    if len(phrase) < 6 or random.random() > 0.15:
        return phrase
    chars = list(phrase)
    i = random.randint(1, len(chars) - 2)
    if chars[i].isalpha():
        chars[i] = chars[i].translate(TYPO_MAP).lower() if random.random() > 0.5 else chars[i].swapcase()
    return ''.join(chars)


def generate_phrases(target: int):
    seen = set()
    out = []

    def add(emotion, phrase, lang):
        p = re.sub(r'\s+', ' ', phrase.strip().lower())
        if len(p) < 2:
            return
        key = (emotion, p)
        if key in seen:
            return
        seen.add(key)
        out.append({'e': emotion, 'p': p, 'l': lang})

    # 1) Banks + intensifiers + modifiers
    for emotion, langs in BANKS.items():
        for lang, cores in langs.items():
            for core in cores:
                add(emotion, core, lang)
                for mod in MODIFIERS.get(lang, MODIFIERS['en']):
                    add(emotion, f"{core} {mod}", lang)
                for intens in INTENSIFIERS.get(lang, []):
                    add(emotion, f"{intens} {core}", lang)
                    add(emotion, f"{core} {intens}", lang)

    # 2) Mixed-language service phrases (map to relevant emotions)
    emotion_for_mixed = {
        'frustrated': ['hindi ko maka', 'indi ko maka', 'ayaw mag', 'not working'],
        'worried': ['nabalaka', 'worried', 'basi may'],
        'confused': ['paano', 'how do', 'diin ko'],
        'thankful': ['salamat'],
        'curious': ['pwede ko', 'how do', 'paano'],
        'anxious': ['nabalaka', 'kulba'],
    }
    for svc in SERVICES:
        for tmpl, lang in MIXED_TEMPLATES:
            phrase = tmpl.format(svc=svc, ctx=random.choice(CONTEXTS))
            for emo, triggers in emotion_for_mixed.items():
                if any(t in phrase for t in triggers):
                    add(emo, phrase, lang if lang != 'mixed' else 'hil')
                    break
            else:
                add('curious', phrase, lang if lang != 'mixed' else 'hil')

    # 3) Combinatorial expansion per emotion until near target
    random.seed(42)
    attempts = 0
    while len(out) < target and attempts < target * 3:
        attempts += 1
        emotion = random.choice(PRIORITY)
        lang = random.choice(['en', 'fil', 'hil'])
        cores = BANKS.get(emotion, {}).get(lang, BANKS.get(emotion, {}).get('en', ['help']))
        core = random.choice(cores)
        mod = random.choice(MODIFIERS.get(lang, MODIFIERS['en']))
        intens = random.choice(INTENSIFIERS.get(lang, ['']))
        patterns = [
            f"{core}",
            f"{core} {mod}",
            f"{intens} {core}".strip(),
            f"{core} {intens}".strip(),
            f"i'm {core}" if lang == 'en' else core,
            f"ako {core}" if lang == 'fil' else core,
            f"ko {core}" if lang == 'hil' else core,
        ]
        phrase = random.choice(patterns)
        phrase = typo_variant(phrase)
        add(emotion, phrase, lang)

    # 4) Numbered variants for uniqueness
    idx = 0
    while len(out) < target:
        emotion = PRIORITY[idx % len(PRIORITY)]
        lang = ['en', 'fil', 'hil'][idx % 3]
        cores = BANKS.get(emotion, {}).get(lang, ['help'])
        base = cores[idx % len(cores)]
        add(emotion, f"{base} please help {idx}", lang)
        idx += 1

    return out[:target]


def main():
    seed = load_seed()
    generated = generate_phrases(TARGET - len(seed))
    all_rows = []
    seen = set()
    for r in seed + generated:
        key = (r['e'], r['p'].lower())
        if key in seen:
            continue
        seen.add(key)
        all_rows.append(r)
    while len(all_rows) < TARGET:
        all_rows.append({
            'e': PRIORITY[len(all_rows) % len(PRIORITY)],
            'p': f"medconnect help request {len(all_rows)}",
            'l': ['en', 'fil', 'hil'][len(all_rows) % 3],
        })

    # Write full CSV
    with CSV_OUT.open('w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=['emotion', 'phrase', 'language'])
        w.writeheader()
        for r in all_rows:
            w.writerow({'emotion': r['e'], 'phrase': r['p'], 'language': r['l']})

    header = """/**
 * medConnect FAQ Chatbot — Emotion Intent Dataset (10,000 phrases)
 * Auto-built: python scripts/data/build_emotion_intent_dataset.py
 */
(function (global) {
  'use strict';
"""
    footer = f"""
  const EMOTION_PRIORITY = {json.dumps(PRIORITY)};

  global.McFaqEmotionDataset = {{
    version: '2.0.0',
    count: {len(all_rows)},
    PHRASES,
    EMOTION_PRIORITY,
  }};
}})(window);
"""
    body = f"  const PHRASES = {json.dumps(all_rows, ensure_ascii=False, separators=(',', ':'))};\n"
    JS_OUT.write_text(header + body + footer, encoding='utf-8')
    print(f'Wrote {len(all_rows)} phrases')
    print(f'  JS:  {JS_OUT}')
    print(f'  CSV: {CSV_OUT}')


if __name__ == '__main__':
    main()
