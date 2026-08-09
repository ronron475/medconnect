#!/usr/bin/env python3
"""
Import emotions.csv (6-class text,label dataset) into medConnect chatbot phrase CSVs.
Extracts short feeling phrases, maps to medConnect emotions, translates to Hiligaynon + Filipino.

Usage:
  python scripts/data/import_emotions_csv.py [path_to_emotions.csv]

Default source: data/nlp/_import_emotions/emotions.csv (extract emotions.csv.zip there first)
Output: data/nlp/emotion_dataset_import.csv
"""
import csv
import re
import sys
from collections import defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
DEFAULT_SRC = ROOT / 'data' / 'nlp' / '_import_emotions' / 'emotions.csv'
OUT_PATH = ROOT / 'data' / 'nlp' / 'emotion_dataset_import.csv'

# Source labels: 0=sadness 1=joy 2=love 3=anger 4=fear 5=surprise
LABEL_BASE = {
    '0': 'sad',
    '1': 'happy',
    '2': 'affectionate',
    '3': 'angry',
    '4': 'anxious',
    '5': 'surprised',
}

# Keyword overrides (checked on full text + extracted phrase)
REFINE_RULES = [
    ('hopeless', ['hopeless', 'helpless', 'no reason to live', 'give up', 'wala paglaum']),
    ('lonely', ['lonely', 'alone', 'isolated', 'no one', 'by myself', 'miss ']),
    ('tired', ['tired', 'weary', 'exhausted', 'drained', 'burnout', 'kapoy', 'pagod']),
    ('stressed', ['stressed', 'overwhelmed', 'too much', 'pressure']),
    ('frustrated', ['frustrated', 'annoyed', 'irritated', 'grouchy', 'bitchy', 'badtrip']),
    ('worried', ['worried', 'concern', 'apprehensive', 'uneasy', 'nabalaka']),
    ('afraid', ['afraid', 'scared', 'terrified', 'fearful', 'nahadlok', 'takot']),
    ('anxious', ['anxious', 'anxiety', 'nervous', 'panic', 'kulba', 'kinabahan']),
    ('disappointed', ['disappointed', 'let down', 'disheartened', 'nadismaya']),
    ('confused', ['confused', 'lost', 'bewildered', 'libog', 'nalibog']),
    ('guilty', ['guilty', 'my fault', 'kasalanan']),
    ('ashamed', ['ashamed', 'embarrassed', 'nahuya', 'nahihiya']),
    ('grief', ['grief', 'mourning', 'passed away', 'died', 'namatay']),
    ('thankful', ['grateful', 'thankful', 'blessed', 'appreciate']),
    ('relieved', ['relieved', 'relief', 'ginhawa']),
    ('excited', ['excited', 'thrilled', 'eager', 'cant wait', "can't wait"]),
    ('curious', ['curious', 'intrigued', 'wondering', 'interested']),
    ('irritated', ['irritated', 'irritable', 'nainis']),
    ('bored', ['bored', 'listless', 'wala gana']),
    ('proud', ['proud', 'accomplished', 'confident']),
    ('hopeful', ['hopeful', 'optimist', 'paglaum']),
    ('calm', ['calm', 'peaceful', 'relaxed', 'malinong']),
    ('jealous', ['jealous', 'envious', 'inggit', 'selos']),
    ('happy', ['happy', 'joy', 'ecstatic', 'wonderful', 'great', 'masadya', 'lipay']),
    ('sad', ['sad', 'heartbroken', 'depressed', 'miserable', 'kasubo', 'subo']),
    ('angry', ['angry', 'mad', 'furious', 'akig', 'galit']),
    ('surprised', ['surprised', 'shocked', 'stunned', 'wow', 'nabigla']),
    ('affectionate', ['loved', 'loving', 'cherish', 'higugma', 'pinangga']),
]

EXTRACT_RES = [
    re.compile(r'\bi\s+feel\s+([^,.!?]{3,58})', re.I),
    re.compile(r'\bim\s+feeling\s+([^,.!?]{3,58})', re.I),
    re.compile(r'\bfeeling\s+([^,.!?]{3,58})', re.I),
    re.compile(r'\bi\s+am\s+([^,.!?]{3,48})', re.I),
    re.compile(r'\bi\s+was\s+feeling\s+([^,.!?]{3,48})', re.I),
    re.compile(r'\bi\s+feel\s+like\s+([^,.!?]{3,48})', re.I),
]

SKIP_PHRASE = re.compile(
    r'^(that|it|this|you|he|she|they|we|my|the|a|an|so|very|really|just|like|when|'
    r'i feel that|i feel like it|i am going|i am trying|i am not|im not)\b',
    re.I,
)
JUNK = re.compile(r'https?://|www\.|@\w+|\.com\b|tumblr|lol\b|rofl', re.I)

# Hiligaynon: multi-word first (longest match)
HIL_PHRASE_MAP = [
    ('i feel hopeless', 'wala ko paglaum'),
    ('feeling hopeless', 'ginabatyag ko nga wala paglaum'),
    ('i feel helpless', 'wala ko mabulig'),
    ('feeling helpless', 'ginabatyag ko nga wala mabulig'),
    ('i feel lonely', 'isa lang ko'),
    ('feeling lonely', 'ginabatyag ko nga lonely'),
    ('i feel sad', 'kasubo ko'),
    ('feeling sad', 'ginabatyag ko nga kasubo'),
    ('i feel tired', 'kapoy ko'),
    ('feeling tired', 'ginakapoy ko'),
    ('i feel exhausted', 'ginakapoy gid ko'),
    ('i feel anxious', 'ginakulbaan ko'),
    ('feeling anxious', 'ginakulbaan ko'),
    ('i feel nervous', 'kinabahan ko'),
    ('i feel worried', 'nabalaka ko'),
    ('feeling worried', 'ginakabalaka ko'),
    ('i feel afraid', 'nahadlok ko'),
    ('feeling afraid', 'nahadlok ko'),
    ('i feel scared', 'nahadlok ko'),
    ('i feel angry', 'akig ko'),
    ('feeling angry', 'akig ko'),
    ('i feel mad', 'akig ko'),
    ('i feel frustrated', 'frustrated ko'),
    ('feeling frustrated', 'frustrated gid ko'),
    ('i feel stressed', 'stressed ko'),
    ('feeling stressed', 'sobra stress ko'),
    ('i feel overwhelmed', 'overwhelmed ko'),
    ('i feel confused', 'nalibog ko'),
    ('feeling confused', 'naglibog ko'),
    ('i feel lost', 'nalibog ko'),
    ('i feel disappointed', 'nadismaya ko'),
    ('i feel guilty', 'may guilt ko'),
    ('i feel ashamed', 'nahuya ko'),
    ('i feel embarrassed', 'nahuya ko'),
    ('i feel happy', 'masadya ko'),
    ('feeling happy', 'malipayon ko'),
    ('i feel good', 'maayo pamatyag ko'),
    ('i feel great', 'maayo gid pamatyag ko'),
    ('i feel relieved', 'ginhawa na ko'),
    ('i feel grateful', 'mapasalamaton ko'),
    ('i feel thankful', 'salamat gid'),
    ('i feel loved', 'ginahigugma ko'),
    ('feeling loved', 'ginahigugma ko'),
    ('i feel excited', 'excited gid ko'),
    ('i feel curious', 'curious ko'),
    ('i feel surprised', 'nabigla ko'),
    ('i feel proud', 'proud ko'),
    ('i feel calm', 'malinong ko'),
    ('i feel hopeful', 'may paglaum ko'),
    ('i feel jealous', 'naiinggit ko'),
    ('i feel bored', 'wala ko gana'),
    ('i feel sick', 'masakit lawas ko'),
    ('i feel weird', 'lain pamatyag ko'),
    ('i feel empty', 'wala laman ko'),
    ('i feel stupid', 'tonto ko daw'),
    ('i feel stupid', 'buang ko daw'),
    ('i feel better', 'maayo na pamatyag ko'),
    ('i feel stronger', 'mas kusog na ko'),
    ('i feel confident', 'confident ko'),
    ('i feel safe', 'safe ko'),
    ('i feel unsafe', 'indi ko safe'),
    ('i feel weak', 'maluya ko'),
    ('i feel heavy', 'mabug-at pamatyag ko'),
    ('i feel light', 'magaan pamatyag ko'),
    ('i feel numb', 'wala ko mabatyag'),
    ('i feel trapped', 'daw na trap ko'),
    ('i feel stuck', 'daw stuck ko'),
    ('i feel broken', 'broken ko'),
    ('i feel damaged', 'daw damaged ko'),
    ('i feel hurt', 'nasakit-an ko'),
    ('i feel betrayed', 'nasakit-an ko'),
    ('i feel ignored', 'wala ko gina tagad'),
    ('i feel unimportant', 'wala importante ko'),
    ('i feel useless', 'wala pulos ko'),
    ('i feel worthless', 'wala value ko'),
    ('i feel anxious about', 'ginakulbaan ko parte sa'),
    ('i feel nervous about', 'kinabahan ko parte sa'),
    ('i feel worried about', 'nabalaka ko parte sa'),
    ('cannot sleep', 'indi ko katulog'),
    ("can't sleep", 'indi ko katulog'),
    ('cant sleep', 'indi ko katulog'),
]
HIL_PHRASE_MAP.sort(key=lambda x: -len(x[0]))

HIL_WORD = {
    'helpless': 'wala mabulig', 'hopeless': 'wala paglaum', 'lonely': 'lonely',
    'sad': 'kasubo', 'unhappy': 'malain pamatyag', 'depressed': 'depressed',
    'miserable': 'miserable', 'heartbroken': 'broken heart', 'empty': 'wala laman',
    'lost': 'nalibog', 'tired': 'kapoy', 'weary': 'kapoy', 'exhausted': 'ginakapoy',
    'drained': 'wala kusog', 'anxious': 'ginakulbaan', 'nervous': 'kinabahan',
    'worried': 'nabalaka', 'afraid': 'nahadlok', 'scared': 'nahadlok',
    'terrified': 'nahadlok gid', 'fearful': 'nahadlok', 'fear': 'kahadlok',
    'angry': 'akig', 'mad': 'akig', 'furious': 'sobra akig', 'frustrated': 'frustrated',
    'annoyed': 'nainis', 'irritated': 'nainis', 'grouchy': 'mainiton ulo',
    'happy': 'masadya', 'joyful': 'malipayon', 'glad': 'lipay', 'good': 'maayo',
    'great': 'maayo gid', 'wonderful': 'nami gid', 'ecstatic': 'sobra lipay',
    'relieved': 'ginhawa', 'relief': 'ginhawa', 'grateful': 'mapasalamaton',
    'thankful': 'salamat', 'blessed': 'blessed', 'loved': 'ginahigugma',
    'loving': 'mapinangga', 'affectionate': 'malambing', 'excited': 'excited',
    'thrilled': 'sobra excited', 'eager': 'sabik', 'curious': 'curious',
    'intrigued': 'curious', 'surprised': 'nabigla', 'shocked': 'nabigla',
    'stunned': 'nabigla', 'stressed': 'stressed', 'overwhelmed': 'overwhelmed',
    'confused': 'nalibog', 'bewildered': 'nalibog', 'disappointed': 'nadismaya',
    'disheartened': 'nadismaya', 'guilty': 'guilty', 'ashamed': 'nahuya',
    'embarrassed': 'nahuya', 'jealous': 'naiinggit', 'envious': 'naiinggit',
    'bored': 'wala gana', 'listless': 'wala gana', 'proud': 'proud',
    'hopeful': 'may paglaum', 'optimistic': 'may paglaum', 'calm': 'malinong',
    'peaceful': 'malinong', 'relaxed': 'relaxed', 'weak': 'maluya',
    'strong': 'kusog', 'confident': 'confident', 'safe': 'safe',
    'unsafe': 'indi safe', 'hurt': 'nasakit-an', 'broken': 'broken',
    'stupid': 'tonto', 'weird': 'lain', 'sick': 'masakit', 'ill': 'masakit',
    'distraught': 'distraught', 'distress': 'distress', 'panic': 'panic',
    'dread': 'kahadlok', 'apprehensive': 'kinabahan', 'uneasy': 'lain pamatyag',
    'grief': 'kaguol', 'mourning': 'naglubong', 'nostalgic': 'nostalgic',
    'need': 'kinahanglan', 'needy': 'needy', 'abused': 'abused',
    'hated': 'ginahigadlok', 'offended': 'nasakit-an', 'respected': 'respetado',
    'energetic': 'may kusog', 'peaceful': 'malinong', 'delicious': 'nami',
    'stronger': 'mas kusog', 'better': 'maayo na', 'fine': 'okay',
    'okay': 'okay', 'well': 'maayo', 'bad': 'malain', 'terrible': 'grabeng malain',
    'awful': 'grabeng malain', 'horrible': 'grabeng malain', 'amazing': 'nami gid',
    'awesome': 'nami gid', 'fantastic': 'nami gid', 'pretty': 'gwapa',
    'dangerous': 'delikado', 'suspicious': 'suspicious', 'hesitant': 'duha-duha',
    'apprehensive': 'kinabahan', 'terrified': 'nahadlok gid',
}

FIL_WORD = {
    'helpless': 'walang makatulong', 'hopeless': 'walang pag-asa', 'lonely': 'malungkot at alone',
    'sad': 'malungkot', 'unhappy': 'hindi masaya', 'depressed': 'depressed',
    'tired': 'pagod', 'weary': 'pagod', 'exhausted': 'sobrang pagod',
    'anxious': 'kinakabahan', 'nervous': 'kinakabahan', 'worried': 'nababahala',
    'afraid': 'takot', 'scared': 'takot', 'angry': 'galit', 'mad': 'galit',
    'frustrated': 'frustrated', 'annoyed': 'inis', 'irritated': 'inis',
    'happy': 'masaya', 'glad': 'masaya', 'good': 'mabuti', 'great': 'napakaganda',
    'relieved': 'ginhawa', 'grateful': 'mapagpasalamat', 'thankful': 'salamat',
    'loved': 'minamahal', 'excited': 'excited', 'curious': 'curious',
    'surprised': 'nagulat', 'stressed': 'stressed', 'overwhelmed': 'overwhelmed',
    'confused': 'nalilito', 'disappointed': 'nadismaya', 'guilty': 'guilty',
    'ashamed': 'nahihiya', 'embarrassed': 'nahihiya', 'jealous': 'naiinggit',
    'bored': 'walang gana', 'proud': 'proud', 'hopeful': 'may pag-asa',
    'calm': 'kalmado', 'sick': 'masama ang pakiramdam', 'hurt': 'nasaktan',
    'empty': 'walang laman', 'lost': 'naliligaw', 'confident': 'confident',
    'weak': 'mahina', 'strong': 'malakas', 'better': 'mas mabuti',
}

SKIP_WORDS = frozenset({
    'a', 'an', 'the', 'so', 'very', 'really', 'just', 'quite', 'somewhat', 'bit',
    'little', 'much', 'more', 'most', 'less', 'too', 'also', 'still', 'even', 'only',
    'that', 'this', 'it', 'its', 'my', 'your', 'his', 'her', 'their', 'our', 'me',
    'you', 'he', 'she', 'they', 'we', 'im', 'i', 'am', 'is', 'are', 'was', 'been',
    'being', 'have', 'had', 'do', 'did', 'will', 'would', 'could', 'should', 'may',
    'might', 'must', 'can', 'cant', "can't", 'about', 'with', 'for', 'from', 'into',
    'like', 'when', 'because', 'though', 'although', 'while', 'after', 'before',
    'again', 'always', 'never', 'ever', 'today', 'now', 'then', 'here', 'there',
    'gid', 'ya', 'man', 'bala', 'subong', 'sang', 'kag', 'nga', 'kon', 'pa',
})

EMOTION_HIL_DEFAULT = {
    'sad': 'kasubo ko', 'lonely': 'isa lang ko', 'hopeless': 'wala ko paglaum',
    'tired': 'kapoy ko', 'stressed': 'stressed ko', 'worried': 'nabalaka ko',
    'anxious': 'ginakulbaan ko', 'afraid': 'nahadlok ko', 'angry': 'akig ko',
    'frustrated': 'frustrated ko', 'irritated': 'nainis ko', 'disappointed': 'nadismaya ko',
    'confused': 'nalibog ko', 'guilty': 'may guilt ko', 'ashamed': 'nahuya ko',
    'embarrassed': 'nahuya ko', 'grief': 'naguol ko', 'jealous': 'naiinggit ko',
    'bored': 'wala ko gana', 'happy': 'masadya ko', 'thankful': 'salamat gid',
    'relieved': 'ginhawa na ko', 'excited': 'excited gid ko', 'curious': 'curious ko',
    'surprised': 'nabigla ko', 'affectionate': 'ginahigugma ko', 'proud': 'proud ko',
    'hopeful': 'may paglaum ko', 'calm': 'malinong ko', 'mixed': 'mixed feelings ko',
    'panic': 'buligi ko', 'pain': 'masakit lawas ko', 'sick': 'masakit lawas ko',
    'overwhelmed': 'overwhelmed ko', 'uncertain': 'indi ko sure',
}

EMOTION_FIL_DEFAULT = {
    'sad': 'malungkot ako', 'lonely': 'malungkot at alone ako', 'hopeless': 'walang pag-asa ako',
    'tired': 'pagod ako', 'stressed': 'stressed ako', 'worried': 'nababahala ako',
    'anxious': 'kinakabahan ako', 'afraid': 'takot ako', 'angry': 'galit ako',
    'frustrated': 'frustrated ako', 'happy': 'masaya ako', 'thankful': 'salamat',
    'relieved': 'ginhawa na ako', 'excited': 'excited ako', 'curious': 'curious ako',
    'surprised': 'nagulat ako', 'affectionate': 'minamahal ako', 'confused': 'nalilito ako',
}

PERSONAL_SUFFIX_EMOTIONS = frozenset(EMOTION_HIL_DEFAULT.keys())


def clean_phrase(s: str) -> str:
    s = re.sub(r'\s+', ' ', s.strip().lower())
    s = re.sub(r'[^\w\s\'-]', ' ', s)
    s = re.sub(r'\s+', ' ', s).strip()
    return s


def refine_emotion(base: str, text: str) -> str:
    blob = text.lower()
    for emotion, keys in REFINE_RULES:
        if any(k in blob for k in keys):
            return emotion
    return base


def extract_candidates(text: str) -> list[str]:
    text = text.strip()
    out = []
    if len(text) <= 58 and not JUNK.search(text):
        c = clean_phrase(text)
        if 4 <= len(c) <= 58 and not SKIP_PHRASE.match(c):
            out.append(c)
    for rx in EXTRACT_RES:
        for m in rx.finditer(text):
            seg = clean_phrase(m.group(1))
            if 4 <= len(seg) <= 58 and not SKIP_PHRASE.match(seg):
                out.append(seg)
    return out


def translate_hil(en: str, emotion: str) -> str:
    low = en.lower().strip()
    for src, dst in HIL_PHRASE_MAP:
        if low == src or low.startswith(src):
            return dst
    # strip leading feel prefixes
    core = re.sub(r'^(i\s+feel\s+|im\s+feeling\s+|feeling\s+|i\s+am\s+)', '', low).strip()
    words = core.split()
    hil_parts = []
    for w in words:
        w = re.sub(r'[^a-z\']', '', w.lower())
        if not w or w in SKIP_WORDS:
            continue
        if w in HIL_WORD:
            hil_parts.append(HIL_WORD[w])
        elif len(w) > 2:
            hil_parts.append(w)
    if hil_parts:
        result = ' '.join(hil_parts)
        if emotion in PERSONAL_SUFFIX_EMOTIONS and not re.search(r'\bko\b', result):
            result = f'{result} ko'
        return result[:80]
    return EMOTION_HIL_DEFAULT.get(emotion, f'{emotion} ko')


def translate_fil(en: str, emotion: str) -> str:
    low = en.lower().strip()
    core = re.sub(r'^(i\s+feel\s+|im\s+feeling\s+|feeling\s+|i\s+am\s+)', '', low).strip()
    words = core.split()
    fil_parts = []
    for w in words:
        w = re.sub(r'[^a-z\']', '', w.lower())
        if not w or w in SKIP_WORDS:
            continue
        if w in FIL_WORD:
            fil_parts.append(FIL_WORD[w])
        elif len(w) > 2:
            fil_parts.append(w)
    if fil_parts:
        result = ' '.join(fil_parts)
        if not re.search(r'\bako\b', result):
            result = f'{result} ako'
        return result[:80]
    return EMOTION_FIL_DEFAULT.get(emotion, f'{emotion} ako')


def main():
    src = Path(sys.argv[1]) if len(sys.argv) > 1 else DEFAULT_SRC
    if not src.exists():
        print(f'Source not found: {src}')
        print('Extract emotions.csv.zip to data/nlp/_import_emotions/emotions.csv')
        sys.exit(1)

    max_per_emotion = 700
    buckets: dict[str, set[str]] = defaultdict(set)

    with src.open(encoding='utf-8', errors='replace') as f:
        reader = csv.DictReader(f)
        for row in reader:
            label = str(row.get('label', '')).strip()
            text = (row.get('text') or '').strip()
            if not text or label not in LABEL_BASE:
                continue
            base = LABEL_BASE[label]
            emotion = refine_emotion(base, text)
            if len(buckets[emotion]) >= max_per_emotion:
                continue
            for cand in extract_candidates(text):
                if JUNK.search(cand):
                    continue
                norm = clean_phrase(cand)
                if norm in buckets[emotion]:
                    continue
                buckets[emotion].add(norm)

    rows = []
    seen = set()
    for emotion in sorted(buckets.keys()):
        for en_phrase in sorted(buckets[emotion]):
            for lang, phrase in [
                ('en', en_phrase),
                ('hil', translate_hil(en_phrase, emotion)),
                ('fil', translate_fil(en_phrase, emotion)),
            ]:
                key = (emotion, phrase.lower(), lang)
                if key in seen:
                    continue
                seen.add(key)
                rows.append({'emotion': emotion, 'phrase': phrase, 'language': lang})

    OUT_PATH.parent.mkdir(parents=True, exist_ok=True)
    with OUT_PATH.open('w', encoding='utf-8', newline='') as f:
        w = csv.DictWriter(f, fieldnames=['emotion', 'phrase', 'language'])
        w.writeheader()
        w.writerows(rows)

    by_lang = defaultdict(int)
    by_emo = defaultdict(int)
    for r in rows:
        by_lang[r['language']] += 1
        if r['language'] == 'hil':
            by_emo[r['emotion']] += 1

    print(f'Wrote {len(rows)} rows to {OUT_PATH}')
    print('By language:', dict(by_lang))
    print('Hiligaynon emotions:', len(by_emo), 'top:', sorted(by_emo.items(), key=lambda x: -x[1])[:12])


if __name__ == '__main__':
    main()
