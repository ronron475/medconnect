"""Philippine National ID (PhilSys) OCR field extraction.

Single source of truth for all National ID paths: FastAPI /ocr/extract and PHP PhilSysOcrParser mirror.
"""

from __future__ import annotations

import re
from datetime import datetime
from typing import Any

CONFIDENCE_THRESHOLD = 0.78
FIELD_MIN_CONFIDENCE = 0.82

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
    "first": [
        "GIVEN NAMES / FIRST NAME", "GIVEN NAMES", "GIVEN NAME", "FIRST NAME", "PANGALAN",
    ],
    "middle": ["MIDDLE NAME", "MIDDLE INITIAL", "GITNANG PANGALAN"],
}

RESERVED_NAME_LABELS = {
    "GIVEN NAMES", "GIVEN NAME", "FIRST NAME", "LAST NAME", "MIDDLE NAME",
    "MIDDLE INITIAL", "SURNAME", "FAMILY NAME", "APELYIDO", "PANGALAN",
    "GITNANG PANGALAN", "GIVEN NAMES FIRST NAME", "NAME",
    "DATE OF BIRTH", "BIRTH DATE", "SEX", "ADDRESS", "TIRAHAN",
    "PHILIPPINE IDENTIFICATION CARD", "DIGITAL ID NUMBER",
}

NAME_NOISE = (
    "REPUBLIKA", "PILIPINAS", "PHILIPPINE", "IDENTIFICATION", "CARD", "PHILSYS",
    "REPUBLIC", "GOVERNMENT", "DIGITAL", "NUMBER", "PERSONAL", "NATIONAL",
)


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


def _normalize_label_text(value: str) -> str:
    norm = re.sub(r"\s+", " ", value.upper().strip())
    norm = norm.replace("/", " ").replace("-", " ")
    return re.sub(r"\s+", " ", norm).strip()


def _is_reserved_name_label(value: str) -> bool:
    norm = _normalize_label_text(value)
    if norm in RESERVED_NAME_LABELS:
        return True
    if re.match(r"^(GIVEN|LAST|MIDDLE|FIRST)\s+NAME(S)?$", norm):
        return True
    return False


def _line_contains_name_label(line_up: str, label: str) -> bool:
    label = label.upper().strip()
    if line_up == label:
        return True
    if line_up.startswith(label):
        return True
    return label in line_up


def _looks_like_name_token(value: str) -> bool:
    return bool(re.match(r"^[A-Za-z][A-Za-z\s\-']{1,}$", value)) and 2 <= len(value) <= 40


def _next_name_value_after(
    lines: list[str],
    start: int,
    label_map: dict,
    stop_before: list[str] | None = None,
) -> str:
    stop_before = stop_before or []
    for j in range(start, min(start + 8, len(lines))):
        nxt = lines[j].strip()
        if not nxt:
            continue
        nxt_up = nxt.upper()
        for stop in stop_before:
            if stop.upper() in nxt_up:
                return ""
        if _is_label_line(nxt_up, label_map) or _is_reserved_name_label(nxt):
            continue
        name = format_person_name(nxt)
        if name and _looks_like_name_token(name):
            return name
    return ""


def _extract_philsys_name_block(lines: list[str], label_map: dict) -> dict[str, str]:
    out = {"last": "", "first": "", "middle": ""}
    stops = {
        "last": ["GIVEN NAMES", "GIVEN NAME", "FIRST NAME", "PANGALAN"],
        "first": ["MIDDLE NAME", "MIDDLE INITIAL", "GITNANG PANGALAN", "DATE OF BIRTH"],
        "middle": ["DATE OF BIRTH", "BIRTH DATE", "SEX", "ADDRESS", "TIRAHAN"],
    }
    for field, labels in label_map.items():
        idx = _find_name_label_line_index(lines, labels)
        if idx is None:
            continue
        val = _next_name_value_after(lines, idx + 1, label_map, stops.get(field, []))
        if val:
            out[field] = val
    return out


def _find_name_label_line_index(lines: list[str], labels: list[str]) -> int | None:
    sorted_labels = sorted(labels, key=len, reverse=True)
    for i, line in enumerate(lines):
        line_up = line.upper().strip()
        for label in sorted_labels:
            if _line_contains_name_label(line_up, label):
                return i
    return None


def _extract_name_sequence_after_last(lines: list[str], label_map: dict) -> dict[str, str]:
    out = {"last": "", "first": "", "middle": ""}
    idx = _find_name_label_line_index(lines, label_map["last"])
    if idx is None:
        return out
    values: list[str] = []
    for j in range(idx + 1, min(idx + 12, len(lines))):
        if len(values) >= 3:
            break
        nxt = lines[j].strip()
        if not nxt:
            continue
        nxt_up = nxt.upper()
        if _is_label_line(nxt_up, label_map) or _is_reserved_name_label(nxt):
            continue
        if re.search(r"\b(date of birth|birthdate|sex|address|tirahan|petsa)\b", nxt, re.I):
            break
        name = format_person_name(nxt)
        if name and _looks_like_name_token(name):
            values.append(name)
    if len(values) >= 1:
        out["last"] = values[0]
    if len(values) >= 2:
        out["first"] = values[1]
    if len(values) >= 3:
        out["middle"] = values[2]
    return out


def _extract_uppercase_name_candidates(lines: list[str], label_map: dict) -> list[str]:
    candidates: list[str] = []
    for line in lines:
        trim = line.strip()
        if not trim or re.search(r"\d", trim):
            continue
        letters_only = re.sub(r"[^A-Z]", "", trim.upper())
        if any(noise in letters_only for noise in NAME_NOISE):
            continue
        if _is_reserved_name_label(trim) or _is_label_line(trim.upper(), label_map):
            continue
        clean = re.sub(r"[^A-Za-z\s\-']", "", trim)
        if not re.match(r"^[A-Z][A-Z\s\-']{0,38}$", clean.upper()):
            continue
        name = format_person_name(trim)
        if name and _looks_like_name_token(name):
            candidates.append(name)
    deduped: list[str] = []
    for c in candidates:
        if c not in deduped:
            deduped.append(c)
    return deduped


def _value_after_label(
    lines: list[str],
    line_index: int,
    label: str,
    label_map: dict,
    stop_before: list[str] | None = None,
) -> str:
    stop_before = stop_before or []
    line_up = lines[line_index].upper().strip()
    label_up = label.upper()
    pos = line_up.find(label_up)
    if pos >= 0:
        after = line_up[pos + len(label_up):].strip().lstrip(":- /")
        if after and not _is_reserved_name_label(after) and not _is_label_line(after, label_map):
            name = format_person_name(after)
            if name and _looks_like_name_token(name):
                return after
    for j in range(line_index + 1, min(line_index + 6, len(lines))):
        nxt = lines[j].strip()
        if not nxt:
            continue
        nxt_up = nxt.upper()
        for stop in stop_before:
            if stop.upper() in nxt_up:
                return ""
        if _is_label_line(nxt_up, label_map) or _is_reserved_name_label(nxt):
            continue
        name = format_person_name(nxt)
        if name and _looks_like_name_token(name):
            return nxt_up
    return ""


def extract_name_fields(raw_text: str) -> dict[str, Any]:
    result = {
        "first": "", "middle": "", "last": "",
        "first_confidence": 0.0, "middle_confidence": 0.0, "last_confidence": 0.0,
        "first_source": "none", "middle_source": "none", "last_source": "none",
    }
    lines = re.split(r"\r?\n", raw_text)

    block = _extract_philsys_name_block(lines, LABEL_MAP)
    for field in ("last", "first", "middle"):
        if block.get(field):
            result[field] = block[field]
            result[f"{field}_confidence"] = 0.94
            result[f"{field}_source"] = "philsys_block"

    stops = {
        "last": ["GIVEN NAMES", "GIVEN NAME", "FIRST NAME", "PANGALAN"],
        "first": ["MIDDLE NAME", "MIDDLE INITIAL", "GITNANG PANGALAN", "DATE OF BIRTH"],
        "middle": ["DATE OF BIRTH", "BIRTH DATE", "SEX", "ADDRESS", "TIRAHAN"],
    }
    for i, line in enumerate(lines):
        line_up = line.upper().strip()
        for field, labels in LABEL_MAP.items():
            if result[field]:
                continue
            for label in sorted(labels, key=len, reverse=True):
                if not _line_contains_name_label(line_up, label):
                    continue
                extracted = _value_after_label(lines, i, label, LABEL_MAP, stops.get(field, []))
                if extracted:
                    name = format_person_name(extracted)
                    if name and _looks_like_name_token(name) and not _is_reserved_name_label(name):
                        result[field] = name
                        result[f"{field}_confidence"] = 0.92
                        result[f"{field}_source"] = "label"
                break

    block_gap = _extract_philsys_name_block(lines, LABEL_MAP)
    for field in ("last", "first", "middle"):
        if not result[field] and block_gap.get(field):
            result[field] = block_gap[field]
            result[f"{field}_confidence"] = 0.88
            result[f"{field}_source"] = "philsys_block_gap"

    if not result["last"] and not result["first"]:
        name_lines = _extract_uppercase_name_candidates(lines, LABEL_MAP)
        if len(name_lines) >= 2:
            result["last"], result["first"] = name_lines[0], name_lines[1]
            result["last_confidence"] = result["first_confidence"] = 0.55
            result["last_source"] = result["first_source"] = "sequence"
            if len(name_lines) >= 3 and not result["middle"]:
                result["middle"] = name_lines[2]
                result["middle_confidence"] = 0.5
                result["middle_source"] = "sequence"

    if (
        not result["middle"]
        and result["last"]
        and result["first"]
    ):
        seq = _extract_name_sequence_after_last(lines, LABEL_MAP)
        candidate = (seq.get("middle") or "").strip()
        if (
            candidate
            and candidate.lower() != result["first"].lower()
            and candidate.lower() != result["last"].lower()
            and not _is_reserved_name_label(candidate)
        ):
            result["middle"] = candidate
            result["middle_confidence"] = 0.78
            result["middle_source"] = "sequence_after_last"

    if not result["middle"] and result["first"]:
        parts = result["first"].split()
        if len(parts) >= 2:
            first_only = format_person_name(parts[0])
            rest = format_person_name(" ".join(parts[1:]))
            if first_only and rest and _looks_like_name_token(rest):
                result["first"] = first_only
                result["middle"] = rest
                result["middle_confidence"] = max(result["middle_confidence"], 0.76)
                result["middle_source"] = "first_name_split"
                if result["first_confidence"] < 0.9:
                    result["first_confidence"] = 0.9

    for field in ("last", "first", "middle"):
        if result[field] and _is_reserved_name_label(result[field]):
            result[field] = ""
            result[f"{field}_confidence"] = 0.0
            result[f"{field}_source"] = "none"

    return result


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
            val = _value_after_label(lines, i, label, LABEL_MAP)
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
    result = {
        "fields": fields,
        "overall_confidence": round(overall, 3),
        "low_confidence": low,
        "raw_text": raw_text,
    }
    return finalize_extraction(result)


def finalize_extraction(result: dict[str, Any]) -> dict[str, Any]:
    fields = result.get("fields") or {}
    for key in ("first_name", "middle_name", "last_name"):
        v = str((fields.get(key) or {}).get("value") or "").strip()
        if not v or _is_reserved_name_label(v):
            fields[key] = _field("", 0.0, "none")
            continue
        name = format_person_name(v)
        if not name or not _looks_like_name_token(name):
            fields[key] = _field("", 0.0, "none")
            continue
        fields[key]["value"] = name

    fn = (fields.get("first_name") or {}).get("value", "").lower()
    ln = (fields.get("last_name") or {}).get("value", "").lower()
    mn = (fields.get("middle_name") or {}).get("value", "").lower()
    if fn and (fn == ln or fn == mn):
        fields["first_name"] = _field("", 0.0, "none")
    if mn and mn == ln:
        fields["middle_name"] = _field("", 0.0, "none")

    nid = re.sub(r"[^0-9]", "", str((fields.get("national_id") or {}).get("value") or ""))
    if len(nid) != 16:
        fields["national_id"] = _field("", 0.0, "none")
    else:
        fields["national_id"]["value"] = format_national_id(nid)

    dob = str((fields.get("date_of_birth") or {}).get("value") or "").strip()
    parsed = parse_date_string(dob) or (dob if re.match(r"^\d{4}-\d{2}-\d{2}$", dob) else None)
    if not parsed:
        fields["date_of_birth"] = _field("", 0.0, "none")
    else:
        fields["date_of_birth"]["value"] = parsed

    required = ["first_name", "last_name", "date_of_birth", "national_id"]
    scores: list[float] = []
    low_field = False
    for key in required:
        val = str((fields.get(key) or {}).get("value") or "").strip()
        conf = float((fields.get(key) or {}).get("confidence") or 0.0)
        if not val:
            low_field = True
            continue
        if conf < FIELD_MIN_CONFIDENCE:
            fields[key] = _field("", 0.0, "invalidated")
            low_field = True
            continue
        scores.append(conf)

    overall = sum(scores) / len(scores) if scores else 0.0
    low = (
        low_field
        or overall < CONFIDENCE_THRESHOLD
        or not (fields.get("first_name") or {}).get("value")
        or not (fields.get("last_name") or {}).get("value")
        or not (fields.get("date_of_birth") or {}).get("value")
        or not (fields.get("national_id") or {}).get("value")
    )
    result["fields"] = fields
    result["overall_confidence"] = round(overall, 3)
    result["low_confidence"] = low
    return result


def merge_raw_ocr_texts(texts: list[str]) -> str:
    seen: dict[str, int] = {}
    ordered: list[str] = []
    for raw in texts:
        for line in re.split(r"\r?\n", raw):
            line = line.strip()
            if not line:
                continue
            key = re.sub(r"\s+", " ", line.lower())
            if key in seen:
                seen[key] += 1
                continue
            seen[key] = 1
            ordered.append(line)
    return "\n".join(ordered)


def _normalize_field_value_for_vote(key: str, value: str) -> str:
    value = value.strip()
    if not value:
        return ""
    if key in ("first_name", "middle_name", "last_name"):
        name = format_person_name(value)
        if not name or _is_reserved_name_label(name) or not _looks_like_name_token(name):
            return ""
        return name.lower()
    if key == "national_id":
        digits = re.sub(r"[^0-9]", "", value)
        return digits if len(digits) == 16 else ""
    if key == "date_of_birth":
        return parse_date_string(value) or ""
    return re.sub(r"\s+", " ", value.lower()).strip()


def _format_consensus_field_value(key: str, value: str) -> str:
    value = value.strip()
    if not value:
        return ""
    if key in ("first_name", "middle_name", "last_name"):
        return format_person_name(value)
    if key == "national_id":
        digits = re.sub(r"[^0-9]", "", value)
        return format_national_id(digits) if len(digits) == 16 else ""
    if key == "date_of_birth":
        return parse_date_string(value) or value
    return value


def _extraction_quality_score(extraction: dict[str, Any]) -> float:
    fields = extraction.get("fields") or {}
    required = ["first_name", "last_name", "date_of_birth", "national_id"]
    score = 0.0
    for key in required:
        val = str((fields.get(key) or {}).get("value") or "").strip()
        if not val:
            continue
        score += float((fields.get(key) or {}).get("confidence") or 0.0)
    if extraction.get("low_confidence"):
        score *= 0.45
    return score


def consensus_from_extractions(extractions: list[dict[str, Any]]) -> dict[str, Any]:
    field_keys = ["first_name", "middle_name", "last_name", "date_of_birth", "national_id", "address"]
    fields: dict[str, Any] = {}
    for key in field_keys:
        votes: dict[str, dict[str, Any]] = {}
        for ext in extractions:
            f = (ext.get("fields") or {}).get(key)
            if not isinstance(f, dict):
                continue
            val = str(f.get("value") or "").strip()
            if not val:
                continue
            norm = _normalize_field_value_for_vote(key, val)
            if not norm:
                continue
            if norm not in votes:
                votes[norm] = {
                    "value": val,
                    "count": 0,
                    "conf": 0.0,
                    "source": str(f.get("source") or "consensus"),
                }
            votes[norm]["count"] += 1
            votes[norm]["conf"] += float(f.get("confidence") or 0.0)
        if not votes:
            fields[key] = _field("", 0.0, "none")
            continue
        winner = max(
            votes.values(),
            key=lambda v: (v["count"], v["conf"]),
        )
        avg_conf = winner["conf"] / max(1, winner["count"])
        boost = min(0.06, (winner["count"] - 1) * 0.03)
        display = _format_consensus_field_value(key, winner["value"])
        fields[key] = _field(display, min(0.99, avg_conf + boost), "consensus")

    raw = merge_raw_ocr_texts([str(e.get("raw_text") or "") for e in extractions])
    draft = {
        "fields": fields,
        "overall_confidence": 0.0,
        "low_confidence": True,
        "raw_text": raw,
    }
    return finalize_extraction(draft)


def _pick_better_extraction(a: dict[str, Any], b: dict[str, Any]) -> dict[str, Any]:
    return a if _extraction_quality_score(a) >= _extraction_quality_score(b) else b


def extract_all_from_passes(raw_texts: list[str]) -> dict[str, Any]:
    texts = [t.strip() for t in raw_texts if t and t.strip()]
    if not texts:
        return extract_all("")
    extractions = [extract_all(t) for t in texts]
    if len(extractions) == 1:
        return extractions[0]
    consensus = consensus_from_extractions(extractions)
    merged = merge_raw_ocr_texts(texts)
    from_merged = extract_all(merged)
    return _pick_better_extraction(consensus, from_merged)
