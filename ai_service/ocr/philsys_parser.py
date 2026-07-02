"""Philippine National ID (PhilSys) OCR field extraction."""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

CONFIDENCE_THRESHOLD = 0.62

MONTH_MAP = {
    "january": "01", "february": "02", "march": "03", "april": "04",
    "may": "05", "june": "06", "july": "07", "august": "08",
    "september": "09", "october": "10", "november": "11", "december": "12",
    "jan": "01", "feb": "02", "mar": "03", "apr": "04",
    "jun": "06", "jul": "07", "aug": "08", "sep": "09",
    "oct": "10", "nov": "11", "dec": "12",
}

LABEL_MAP = {
    "last": ["LAST NAME", "SURNAME", "FAMILY NAME", "APELYIDO"],
    "first": ["GIVEN NAMES", "GIVEN NAME", "FIRST NAME", "PANGALAN", "GIVEN NAMES / FIRST NAME"],
    "middle": ["MIDDLE NAME", "MIDDLE INITIAL", "GITNANG PANGALAN"],
}


def _field(value: str, confidence: float, source: str) -> dict[str, Any]:
    return {
        "value": value,
        "confidence": round(max(0.0, min(1.0, confidence)), 3),
        "source": source,
    }


def format_person_name(value: str) -> str:
    value = re.sub(r"[^A-Za-z\s\-']", " ", value)
    value = re.sub(r"\s+", " ", value.strip())
    if not value:
        return ""
    return " ".join(w.capitalize() for w in value.lower().split() if w)


def format_national_id(digits: str) -> str:
    digits = re.sub(r"[^0-9]", "", digits)
    if len(digits) != 16:
        return digits
    return f"{digits[0:4]}-{digits[4:8]}-{digits[8:12]}-{digits[12:16]}"


def format_address_line(value: str) -> str:
    value = re.sub(r"\s+", " ", value.strip())
    value = re.sub(r"[^\w\s,.\-#/]", "", value)
    return value.strip()


def sanitize_ocr_id(raw: str) -> str:
    mapping = {
        "O": "0", "o": "0", "D": "0", "Q": "0",
        "I": "1", "l": "1", "i": "1", "!": "1",
        "Z": "2", "z": "2",
        "S": "5", "s": "5",
        "G": "6",
        "B": "8", "&": "8",
        "g": "9", "q": "9",
    }
    return "".join(mapping.get(c, c) for c in raw)


def _is_label_line(line_up: str, label_map: dict[str, list[str]]) -> bool:
    labels = [lbl.upper() for labels in label_map.values() for lbl in labels]
    return any(lbl in line_up for lbl in labels)


def _is_address_label(line_up: str) -> bool:
    return any(lbl in line_up for lbl in [
        "LAST NAME", "GIVEN", "MIDDLE", "DATE OF BIRTH", "SEX", "PCN", "PHILSYS",
    ])


def _value_after_label(lines: list[str], line_index: int, label: str, label_map: dict) -> str:
    line_up = lines[line_index].upper().strip()
    label_up = label.upper()
    pos = line_up.find(label_up)
    if pos >= 0:
        after = line_up[pos + len(label_up):].strip().lstrip(":- ")
        if after and not _is_label_line(after, label_map):
            return after
    for j in range(line_index + 1, min(line_index + 4, len(lines))):
        nxt = lines[j].strip()
        if not nxt:
            continue
        nxt_up = nxt.upper()
        if _is_label_line(nxt_up, label_map):
            continue
        return nxt_up
    return ""


def _looks_like_name_token(value: str) -> bool:
    return bool(re.match(r"^[A-Za-z][A-Za-z\s\-']{1,}$", value)) and 2 <= len(value) <= 40


def parse_date_string(raw: str) -> str | None:
    raw = raw.strip()
    if not raw:
        return None
    numeric = raw.lower()
    for name, num in sorted(MONTH_MAP.items(), key=lambda x: len(x[0]), reverse=True):
        numeric = re.sub(rf"\b{re.escape(name)}\b", str(int(num)), numeric)
    parts = [int(x) for x in re.findall(r"\d+", numeric)]
    if len(parts) < 3:
        return None
    a, b, c = parts[0], parts[1], parts[2]
    for y, mo, d in [(a, b, c), (a, c, b), (c, a, b), (c, b, a), (b, a, c), (b, c, a)]:
        if y < 100:
            y += 1900 if y > 30 else 2000
        if y < 1900 or y > 2100 or mo < 1 or mo > 12 or d < 1 or d > 31:
            continue
        cand = f"{y:04d}-{mo:02d}-{d:02d}"
        try:
            if datetime.strptime(cand, "%Y-%m-%d").strftime("%Y-%m-%d") == cand:
                return cand
        except ValueError:
            continue
    return None


def extract_name_fields(raw_text: str) -> dict[str, Any]:
    result = {
        "first": "", "middle": "", "last": "",
        "first_confidence": 0.0, "middle_confidence": 0.0, "last_confidence": 0.0,
        "first_source": "none", "middle_source": "none", "last_source": "none",
    }
    lines = re.split(r"\r?\n", raw_text)
    for i, line in enumerate(lines):
        line_up = line.upper().strip()
        for field, labels in LABEL_MAP.items():
            if result[field]:
                continue
            for label in labels:
                if label not in line_up:
                    continue
                extracted = _value_after_label(lines, i, label, LABEL_MAP)
                if extracted:
                    result[field] = format_person_name(extracted)
                    result[f"{field}_confidence"] = 0.92
                    result[f"{field}_source"] = "label"
                break
    if not result["last"] and not result["first"]:
        name_lines = []
        for line in lines:
            clean = format_person_name(line)
            if clean and _looks_like_name_token(clean):
                name_lines.append(clean)
        name_lines = list(dict.fromkeys(name_lines))
        if len(name_lines) >= 2:
            result["last"], result["first"] = name_lines[0], name_lines[1]
            result["last_confidence"] = result["first_confidence"] = 0.55
            result["last_source"] = result["first_source"] = "sequence"
            if len(name_lines) >= 3:
                result["middle"] = name_lines[2]
                result["middle_confidence"] = 0.5
                result["middle_source"] = "sequence"
    return result


def extract_date_of_birth(raw_text: str) -> dict[str, Any]:
    empty = {"value": "", "confidence": 0.0, "source": "none"}
    labels = ["date of birth", "birth date", "birthdate", "petsa ng kapanganakan"]
    norm = re.sub(r"\s+", " ", raw_text.lower())
    lines = re.split(r"\r?\n", raw_text)
    for label in labels:
        pos = norm.find(label)
        if pos >= 0:
            after = norm[pos + len(label): pos + len(label) + 80].lstrip(":- \t\r\n")
            parsed = parse_date_string(after)
            if parsed:
                return {"value": parsed, "confidence": 0.9, "source": "label_inline"}
        for li, line in enumerate(lines):
            if label not in line.lower():
                continue
            for nxt in range(li + 1, min(li + 3, len(lines))):
                nl = lines[nxt].strip()
                if not nl:
                    continue
                parsed = parse_date_string(nl.lower())
                if parsed:
                    return {"value": parsed, "confidence": 0.88, "source": "label_nextline"}
                break
    patterns = [
        r"\b([A-Za-z]{3,9})\s+(\d{1,2}),?\s+(\d{4})\b",
        r"\b(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})\b",
        r"\b(\d{4})[/\-.](\d{1,2})[/\-.](\d{1,2})\b",
    ]
    for pattern in patterns:
        m = re.search(pattern, raw_text)
        if not m:
            continue
        candidate = parse_date_string(" ".join(m.groups()).lower())
        if candidate:
            return {"value": candidate, "confidence": 0.72, "source": "pattern"}
    return empty


def extract_field_by_label(raw_text: str, labels: list[str]) -> str:
    lines = re.split(r"\r?\n", raw_text)
    for i, line in enumerate(lines):
        ll = line.lower().strip()
        for label in labels:
            if label.lower() not in ll:
                continue
            val = _value_after_label(lines, i, label, {})
            if val:
                return val
    return ""


def extract_national_id(raw_text: str) -> dict[str, Any]:
    empty = {"value": "", "confidence": 0.0, "source": "none"}
    candidates: dict[str, dict[str, Any]] = {}
    sanitized = sanitize_ocr_id(raw_text)
    for src in (raw_text, sanitized):
        for m in re.finditer(r"(\d{4})[\s\-.](\d{4})[\s\-.](\d{4})[\s\-.](\d{4})", src):
            digits = "".join(m.groups())
            candidates.setdefault(digits, {"confidence": 0.95, "source": "grouped_4x4"})
        m16 = re.search(r"\d{16}", src)
        if m16:
            candidates.setdefault(m16.group(0), {"confidence": 0.85, "source": "continuous_16"})
    by_label = extract_field_by_label(raw_text, [
        "PCN", "PhilSys", "PHILSYS", "National ID", "NATIONAL ID", "ID No", "ID NO", "Card Number",
    ])
    if by_label:
        digits = re.sub(r"[^0-9]", "", by_label)
        if len(digits) == 16:
            candidates[digits] = {"confidence": 0.9, "source": "label"}
    if not candidates:
        all_digits = re.sub(r"[^0-9]", "", sanitized)
        for i in range(max(0, len(all_digits) - 15)):
            c = all_digits[i:i + 16]
            candidates.setdefault(c, {"confidence": 0.65, "source": "sliding_window"})
    if not candidates:
        return empty
    best_digits = max(candidates, key=lambda d: candidates[d]["confidence"])
    best = candidates[best_digits]
    return {
        "value": format_national_id(best_digits),
        "confidence": best["confidence"],
        "source": best["source"],
    }


def extract_address(raw_text: str) -> dict[str, Any]:
    empty = {"value": "", "confidence": 0.0, "source": "none"}
    lines = re.split(r"\r?\n", raw_text)
    for i, line in enumerate(lines):
        line_up = line.upper().strip()
        if line_up in ("ADDRESS", "TIRAHAN"):
            for j in range(i + 1, min(i + 3, len(lines))):
                nxt = lines[j].strip()
                if not nxt or _is_address_label(nxt.upper()):
                    continue
                return {"value": format_address_line(nxt), "confidence": 0.82, "source": "label"}
            break
    addr_lines = []
    for line in lines:
        if re.search(r"\b(barangay|purok|city|negros|street|st\.|bago)\b", line.lower()):
            clean = format_address_line(line)
            if len(clean) > 8:
                addr_lines.append(clean)
    if addr_lines:
        unique = list(dict.fromkeys(addr_lines))[:3]
        return {"value": format_address_line(", ".join(unique)), "confidence": 0.68, "source": "keyword"}
    return empty


def extract_all(raw_text: str) -> dict[str, Any]:
    raw_text = raw_text.strip()
    names = extract_name_fields(raw_text)
    dob = extract_date_of_birth(raw_text)
    nid = extract_national_id(raw_text)
    address = extract_address(raw_text)
    fields = {
        "first_name": _field(names["first"], names["first_confidence"], names["first_source"]),
        "middle_name": _field(names["middle"], names["middle_confidence"], names["middle_source"]),
        "last_name": _field(names["last"], names["last_confidence"], names["last_source"]),
        "date_of_birth": _field(dob["value"], dob["confidence"], dob["source"]),
        "national_id": _field(nid["value"], nid["confidence"], nid["source"]),
        "address": _field(address["value"], address["confidence"], address["source"]),
    }
    required = ["first_name", "last_name", "date_of_birth", "national_id"]
    scores = [fields[k]["confidence"] for k in required if fields[k]["value"]]
    overall = sum(scores) / len(scores) if scores else 0.0
    low = (
        overall < CONFIDENCE_THRESHOLD
        or not fields["first_name"]["value"]
        or not fields["last_name"]["value"]
        or not fields["date_of_birth"]["value"]
        or not fields["national_id"]["value"]
    )
    return {
        "fields": fields,
        "overall_confidence": round(overall, 3),
        "low_confidence": low,
        "raw_text": raw_text,
    }
