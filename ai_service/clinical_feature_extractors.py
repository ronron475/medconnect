"""Structured clinical feature extractors for triage CDS (duration, pain, temp, risks)."""

from __future__ import annotations

import re
from typing import Any, Callable

_WORD_NUM = {
    "one": 1, "two": 2, "three": 3, "four": 4, "five": 5,
    "six": 6, "seven": 7, "eight": 8, "nine": 9, "ten": 10,
    "isa": 1, "duha": 2, "tatlo": 3, "apat": 4, "lima": 5,
    "uno": 1, "dos": 2, "tres": 3,
}

_VAGUE_PATTERNS = [
    r"\bi don't feel well\b",
    r"\bnot feeling well\b",
    r"\bindi maayo pamatyag\b",
    r"\bhindi maganda (ang )?pakiramdam\b",
    r"\bsakit tanan\b",
    r"\bmasakit tanan\b",
    r"\bsomething is wrong\b",
    r"\bdi ako okay\b",
]


def _to_int(token: str) -> int | None:
    t = (token or "").strip().lower()
    if t.isdigit():
        return int(t)
    return _WORD_NUM.get(t)


def extract_duration(text: str) -> dict[str, Any]:
    """Extract symptom duration and a scoring bucket."""
    raw = (text or "").strip()
    low = raw.lower()
    if not low:
        return {"raw": "", "label": "", "bucket": "unknown", "days": None, "hours": None}

    patterns: list[tuple[str, str, Callable[..., tuple]]] = [
        (r"(?:for|since|over|about|around)?\s*(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s*(hours?|hrs?)", "hours",
         lambda n, _u: ("acute_hours", None, n, f"{n} hour{'s' if n != 1 else ''}")),
        (r"(?:for|since|over|nang|durante)?\s*(\d+|one|two|three|four|five|six|seven|eight|nine|ten)\s*(days?|adlaw|araw)", "days",
         lambda n, _u: (
             "same_day" if n <= 0 else "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days",
             n, None, f"{n} day{'s' if n != 1 else ''}",
         )),
        (r"(\d+|one|two|three|four|five)\s*ka\s*adlaw", "days",
         lambda n, _u: (
             "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days",
             n, None, f"{n} days",
         )),
        (r"(\d+)\s*araw", "days",
         lambda n, _u: (
             "1_to_2_days" if n <= 2 else "3_to_4_days" if n <= 4 else "5_plus_days",
             n, None, f"{n} days",
         )),
        (r"(?:for|since|over)?\s*(\d+|one|two)\s*(weeks?|semana)", "weeks",
         lambda n, _u: ("chronic_weeks", n * 7, None, f"{n} week{'s' if n != 1 else ''}")),
        (r"\b(today|kanan|subong|ngayon)\b", "today",
         lambda _n, _u: ("same_day", 0, None, "Today")),
        (r"\b(yesterday|gahapon|kahapon)\b", "yesterday",
         lambda _n, _u: ("1_to_2_days", 1, None, "Since yesterday")),
        (r"\b(since yesterday)\b", "yesterday",
         lambda _n, _u: ("1_to_2_days", 1, None, "Since yesterday")),
        (r"\b(this morning|kanina|kanina sang aga)\b", "hours",
         lambda _n, _u: ("acute_hours", None, 6, "This morning")),
        (r"\b(dugay na|matagal na|for a long time)\b", "chronic",
         lambda _n, _u: ("chronic_weeks", 14, None, "For a long time")),
        (r"\b(bag-o lang|kakaunti|just now|just started)\b", "acute",
         lambda _n, _u: ("acute_hours", None, 1, "Just started")),
        (r"\b(one week|1 week|isa ka semana|isang linggo)\b", "weeks",
         lambda _n, _u: ("chronic_weeks", 7, None, "One week")),
    ]

    for pat, _kind, builder in patterns:
        m = re.search(pat, low)
        if not m:
            continue
        groups = m.groups()
        n = _to_int(groups[0]) if groups and re.match(r"^(\d+|one|two|three|four|five|six|seven|eight|nine|ten|isa|duha|tatlo)$", groups[0], re.I) else None
        if n is None and groups and groups[0].isdigit():
            n = int(groups[0])
        if n is None:
            n = 0
        bucket, days, hours, label = builder(n, groups[1] if len(groups) > 1 else "")
        return {
            "raw": m.group(0),
            "label": label,
            "bucket": bucket,
            "days": days,
            "hours": hours,
        }

    return {"raw": "", "label": "", "bucket": "unknown", "days": None, "hours": None}


def extract_pain_scale(text: str) -> dict[str, Any]:
    """Detect numeric pain scale 1–10 and severity band."""
    low = (text or "").lower()
    score: int | None = None

    patterns = [
        r"pain\s*(?:scale|level|score)?\s*(?:of|is|=|:)?\s*(\d{1,2})\s*(?:/\s*10)?",
        r"(?:rate|rated|rating)\s*(?:my\s*)?pain\s*(?:at|as|=|:)?\s*(\d{1,2})",
        r"\b(\d{1,2})\s*/\s*10\b",
        r"\bpain\s+(\d{1,2})\b",
        r"\b(\d{1,2})\s*out of\s*10\b",
    ]
    for pat in patterns:
        m = re.search(pat, low)
        if m:
            val = int(m.group(1))
            if 0 <= val <= 10:
                score = val
                break

    # Qualitative fallback
    if score is None:
        if re.search(r"\b(grabe|severe|worst|unbearable|7/10|8/10|9/10|10/10)\b", low) or " gid" in f" {low}":
            # "gid" alone is too common in Hiligaynon; require pain context
            if re.search(r"\b(pain|sakit|hapdi|masakit|grabe|severe)\b", low):
                if re.search(r"\b(grabe|severe|unbearable|worst)\b", low) or re.search(r"\b(sakit|hapdi|masakit).{0,12}\bgid\b", low):
                    score = 8
        if score is None and re.search(r"\b(moderate|medyo|tunga-tunga)\b", low) and re.search(r"\b(pain|sakit|hapdi|masakit)\b", low):
            score = 5
        if score is None and re.search(r"\b(mild|gamay|slight)\b", low) and re.search(r"\b(pain|sakit|hapdi|masakit)\b", low):
            score = 2

    if score is None:
        return {"score": None, "band": "", "label": "", "modifier_key": ""}

    if score <= 3:
        band, key, label = "mild", "mild_1_3", f"Pain {score}/10 (Mild)"
    elif score <= 6:
        band, key, label = "moderate", "moderate_4_6", f"Pain {score}/10 (Moderate)"
    else:
        band, key, label = "severe", "severe_7_10", f"Pain {score}/10 (Severe)"

    return {"score": score, "band": band, "label": label, "modifier_key": key}


def extract_temperature(text: str) -> dict[str, Any]:
    """Extract temperature reading and fever band."""
    low = (text or "").lower()
    celsius: float | None = None

    m = re.search(r"(\d{2}(?:\.\d)?)\s*°?\s*c\b", low)
    if m:
        celsius = float(m.group(1))
    else:
        m = re.search(r"(\d{2}(?:\.\d)?)\s*degrees?", low)
        if m:
            val = float(m.group(1))
            # Heuristic: >= 90 treated as Fahrenheit
            celsius = round((val - 32) * 5 / 9, 1) if val >= 90 else val
        else:
            m = re.search(r"temp(?:erature)?\s*(?:of|is|=|:)?\s*(\d{2}(?:\.\d)?)", low)
            if m:
                celsius = float(m.group(1))

    has_fever_word = bool(re.search(r"\b(fever|lagnat|hilanat|nilalagnat|ginahilanat|pyrexia)\b", low))

    if celsius is None:
        if has_fever_word:
            if re.search(r"\b(high fever|grabe.*lagnat|mataas.*lagnat|very high)\b", low):
                return {"celsius": None, "band": "high_fever", "label": "High fever (reported)", "modifier_key": "high_fever"}
            return {"celsius": None, "band": "fever", "label": "Fever (reported)", "modifier_key": "fever"}
        return {"celsius": None, "band": "", "label": "", "modifier_key": ""}

    if celsius >= 39.0:
        band, key, label = "high_fever", "high_fever", f"Temperature {celsius}°C (High fever)"
    elif celsius >= 38.0:
        band, key, label = "fever", "fever", f"Temperature {celsius}°C (Fever)"
    elif celsius >= 37.5:
        band, key, label = "low_grade", "low_grade", f"Temperature {celsius}°C (Low-grade)"
    else:
        band, key, label = "normal", "", f"Temperature {celsius}°C"

    return {"celsius": celsius, "band": band, "label": label, "modifier_key": key}


def extract_risk_factors(text: str, english_text: str = "") -> list[dict[str, Any]]:
    """Detect clinical risk factors that escalate urgency."""
    hay = f"{(text or '').lower()} {(english_text or '').lower()}"
    catalog = [
        ("pregnant", "Pregnant", [r"\bpregnant\b", r"\bpregnancy\b", r"\bbuntis\b", r"\bnagabusong\b"]),
        ("infant", "Infant", [r"\binfant\b", r"\bbaby\b", r"\bnewborn\b", r"\bsanggol\b", r"\bbata pa gid\b.*\b(month|bulan|week)"]),
        ("child", "Child", [r"\bchild\b", r"\bkid\b", r"\bpediatric\b", r"\bbata\b", r"\byears? old\b"]),
        ("senior", "Senior Citizen", [r"\bsenior\b", r"\belderly\b", r"\bold age\b", r"\btigulang\b", r"\bmatanda\b", r"\b\d{2,3}\s*years? old\b"]),
        ("diabetes", "Diabetes", [r"\bdiabetes\b", r"\bdiabetic\b", r"\bdm\b", r"\bhigh blood sugar\b"]),
        ("hypertension", "Hypertension", [r"\bhypertension\b", r"\bhigh blood\b", r"\bhta\b", r"\btaas blood\b"]),
        ("asthma", "Asthma", [r"\basthma\b", r"\bhika\b"]),
        ("cancer", "Cancer", [r"\bcancer\b", r"\bchemotherapy\b", r"\boncology\b"]),
        ("heart_disease", "Heart Disease", [r"\bheart disease\b", r"\bcardiac\b", r"\bheart failure\b", r"\bcoronary\b", r"\bami\b", r"\bheart attack history\b"]),
        ("kidney_disease", "Kidney Disease", [r"\bkidney disease\b", r"\brenal\b", r"\bdialysis\b", r"\bckd\b"]),
        ("immunocompromised", "Immunocompromised", [r"\bimmunocompromised\b", r"\bimmunosuppressed\b", r"\bhi[vw]\b", r"\btransplant\b"]),
    ]
    found: list[dict[str, Any]] = []
    for key, label, patterns in catalog:
        for pat in patterns:
            if re.search(pat, hay):
                # Avoid classifying every "bata" mention without age context as child when adult complaint
                if key == "child" and not re.search(r"\b(my child|anak|years? old|age\s*\d+)\b", hay):
                    if not re.search(r"\b(for my|sa akon)\s+bata\b", hay):
                        continue
                if key == "senior":
                    m_age = re.search(r"\b(\d{2,3})\s*years? old\b", hay)
                    if m_age and int(m_age.group(1)) < 60 and "senior" not in hay and "tigulang" not in hay:
                        continue
                found.append({"id": key, "label": label})
                break
    return found


def extract_age_group(text: str, risk_factors: list[dict[str, Any]] | None = None) -> str:
    ids = {r.get("id") for r in (risk_factors or [])}
    if "infant" in ids:
        return "Infant"
    if "child" in ids:
        return "Child"
    if "senior" in ids:
        return "Senior Citizen"
    if "pregnant" in ids:
        return "Pregnant Adult"
    hay = (text or "").lower()
    m = re.search(r"\b(\d{1,3})\s*(?:years?|yrs?)\s*old\b", hay)
    if m:
        age = int(m.group(1))
        if age < 1:
            return "Infant"
        if age < 18:
            return "Child"
        if age >= 60:
            return "Senior Citizen"
        return "Adult"
    return "Unknown"


def is_vague_complaint(text: str) -> bool:
    low = (text or "").lower().strip()
    if not low:
        return True
    # Explicit vague templates always count
    if any(re.search(p, low) for p in _VAGUE_PATTERNS):
        return True
    # Short complaints that already contain clinical anchors are NOT vague
    clinical_anchor = re.search(
        r"(fever|lagnat|hilanat|pain|sakit|hapdi|gapalanakit|masakit|cough|ubo|sip-?on|"
        r"chest|dughan|dibdib|breath|ginhawa|blood|dugo|suka|vomit|kulba|ginakulba|"
        r"headache|ulo|tiyan|abdomen|nause)",
        low,
    )
    if clinical_anchor:
        return False
    if len(low.split()) <= 3:
        return True
    return False


def extract_all_features(
    original: str,
    english: str = "",
    negated_concepts: list[str] | None = None,
) -> dict[str, Any]:
    combined = f"{original or ''} {english or ''}".strip()
    duration = extract_duration(combined)
    pain = extract_pain_scale(combined)
    temperature = extract_temperature(combined)
    risks = extract_risk_factors(original, english)
    age_group = extract_age_group(combined, risks)
    neg = [n.lower() for n in (negated_concepts or [])]
    # Do not score fever/temperature when fever is explicitly negated
    if any(n in {"fever", "lagnat", "hilanat"} for n in neg):
        temperature = {"celsius": None, "band": "", "label": "", "modifier_key": ""}
    return {
        "duration": duration,
        "pain_scale": pain,
        "temperature": temperature,
        "risk_factors": risks,
        "age_group": age_group,
        "vague_complaint": is_vague_complaint(original or english),
        "negated_concepts": neg,
    }
