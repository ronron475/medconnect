#!/usr/bin/env python3
"""
Build symptoms.csv from MedlinePlus Symptoms Index + health topic XML (Symptoms group).

Source:
  - https://medlineplus.gov/symptoms.html (Symptoms Index)
  - MedlinePlus Compressed Health Topic XML (English topics in Symptoms group)

Output:
  - data/nlp/symptoms/symptoms_part_XX.csv
  - data/nlp/symptoms.csv (consolidated export with symptom_id)

Attribution: National Library of Medicine — MedlinePlus.gov
"""

from __future__ import annotations

import csv
import html
import io
import re
import zipfile
import xml.etree.ElementTree as ET
from pathlib import Path
from urllib.request import urlopen

from symptom_categories import categorize

ROOT = Path(__file__).resolve().parents[2]
SOURCE_DIR = ROOT / "data" / "nlp" / "source"
OUT_DIR = ROOT / "data" / "nlp" / "symptoms"
NLP_EXPORT = ROOT / "data" / "nlp" / "symptoms.csv"

SYMPTOMS_INDEX_URL = "https://medlineplus.gov/symptoms.html"
XML_PAGE_URL = "https://medlineplus.gov/xml.html"
ROWS_PER_PART = 2000

EXCLUDED_SLUGS = frozenset({
    "symptoms", "healthtopics", "about", "spanish", "accessibility",
    "copyright", "privacy", "sitemap", "citation", "subscribe",
})

EXCLUDED_NAMES = frozenset({
    "accessibility", "copyright", "privacy policy", "site map",
})

# Fallback if download fails: cached index HTML
CACHED_INDEX = SOURCE_DIR / "medlineplus_symptoms_index.html"


def fetch_url(url: str, timeout: int = 120) -> bytes:
    with urlopen(url, timeout=timeout) as resp:
        return resp.read()


def find_zip_url(pattern: str) -> str | None:
    try:
        page = fetch_url(XML_PAGE_URL, timeout=60).decode("utf-8", errors="replace")
    except Exception as exc:
        print(f"  Could not load XML page: {exc}")
        return None
    matches = re.findall(rf'href="([^"]*{pattern}[^"]*\.zip)"', page, re.I)
    if matches:
        href = matches[0]
        return href if href.startswith("http") else "https://medlineplus.gov" + href
    return None


def find_healthtopics_zip_url() -> str | None:
    return find_zip_url("mplus_topics_compressed")


def download_healthtopics_xml() -> Path | None:
    SOURCE_DIR.mkdir(parents=True, exist_ok=True)
    cached = list(SOURCE_DIR.glob("mplus_topics_*.xml")) + list(
        SOURCE_DIR.glob("medlineplus_healthtopics_*.xml")
    )
    if cached:
        return max(cached, key=lambda p: p.stat().st_mtime)

    zip_url = find_healthtopics_zip_url()
    if not zip_url:
        print("  Health topics ZIP URL not found — using Symptoms Index only.")
        return None

    zip_path = SOURCE_DIR / "medlineplus_healthtopics.zip"
    try:
        print(f"Downloading {zip_url} ...")
        data = fetch_url(zip_url, timeout=180)
        zip_path.write_bytes(data)
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            xml_names = [n for n in zf.namelist() if n.lower().endswith(".xml")]
            if not xml_names:
                return None
            name = xml_names[0]
            out = SOURCE_DIR / Path(name).name
            out.write_bytes(zf.read(name))
            print(f"Saved {out}")
            return out
    except Exception as exc:
        print(f"  Download failed: {exc}")
        return None


def normalize_name(name: str) -> str:
    name = html.unescape(name).strip()
    name = re.sub(r"\s+", " ", name)
    # Title case medical terms consistently (keep common particles lowercase)
    lower_words = {"and", "or", "of", "the", "in", "with", "without", "to", "for"}
    parts = name.split()
    out = []
    for i, w in enumerate(parts):
        if i > 0 and w.lower() in lower_words:
            out.append(w.lower())
        else:
            out.append(w[:1].upper() + w[1:] if w else w)
    return " ".join(out)


def clean_summary(text: str, max_len: int = 500) -> str:
    text = html.unescape(text or "")
    text = re.sub(r"<[^>]+>", " ", text)
    text = re.sub(r"\s+", " ", text).strip()
    if len(text) > max_len:
        text = text[: max_len - 3].rsplit(" ", 1)[0] + "..."
    return text


def parse_symptoms_index_html(html_text: str) -> list[dict[str, str]]:
    """Parse symptoms.html: primary topics and 'see' cross-references."""
    entries: list[dict[str, str]] = []
    seen: set[str] = set()

    def add(name: str, canonical: str, is_alias: bool) -> None:
        name = normalize_name(name)
        canonical = normalize_name(canonical)
        if not name or len(name) < 2:
            return
        if name.lower() in EXCLUDED_NAMES:
            return
        key = name.lower()
        if key in seen:
            return
        seen.add(key)
        desc = (
            f"MedlinePlus Symptoms Index synonym for {canonical}."
            if is_alias and name.lower() != canonical.lower()
            else "MedlinePlus Symptoms Index entry."
        )
        entries.append(
            {
                "symptom_name": name,
                "canonical_name": canonical,
                "description": desc,
                "source": "MedlinePlus_Symptoms_Index",
            }
        )

    # Limit to main index list (avoid footer links)
    main_match = re.search(
        r'<h1[^>]*>\s*Symptoms\s*</h1>(.*?)<div[^>]+class="[^"]*footer',
        html_text,
        re.S | re.I,
    )
    scope = main_match.group(1) if main_match else html_text

    for block in re.findall(r'<li class="item">(.*?)</li>', scope, re.S | re.I):
        block = html.unescape(re.sub(r"\s+", " ", block))
        see_link = re.search(
            r'<em>\s*see\s*</em>\s*<a[^>]+href="[^"]+\.html"[^>]*>([^<]+)</a>',
            block,
            re.I,
        )
        if see_link:
            canonical = see_link.group(1).strip()
            alias = re.sub(r"<[^>]+>", "", block.split("<em")[0]).strip()
            alias = re.sub(r"^\*+|\*+$", "", alias).strip()
            if alias:
                add(alias, canonical, True)
            continue
        primary = re.search(
            r'<a[^>]+href="https://medlineplus\.gov/([a-z0-9]+)\.html"[^>]*>([^<]+)</a>',
            block,
            re.I,
        )
        if primary:
            slug, title = primary.group(1), primary.group(2).strip()
            if slug not in EXCLUDED_SLUGS:
                add(title, title, False)

    return entries


def load_symptoms_index() -> list[dict[str, str]]:
    html_bytes: bytes | None = None
    try:
        print(f"Fetching {SYMPTOMS_INDEX_URL} ...")
        html_bytes = fetch_url(SYMPTOMS_INDEX_URL, timeout=60)
        CACHED_INDEX.parent.mkdir(parents=True, exist_ok=True)
        CACHED_INDEX.write_bytes(html_bytes)
    except Exception as exc:
        print(f"  Fetch failed: {exc}")
        if CACHED_INDEX.is_file():
            html_bytes = CACHED_INDEX.read_bytes()
            print(f"  Using cached {CACHED_INDEX}")

    if not html_bytes:
        return []

    text = html_bytes.decode("utf-8", errors="replace")
    entries = parse_symptoms_index_html(text)
    print(f"Symptoms Index parsed: {len(entries)} entries")
    return entries


def download_topic_groups_xml() -> Path | None:
    SOURCE_DIR.mkdir(parents=True, exist_ok=True)
    cached = list(SOURCE_DIR.glob("mplus_groups_*.xml"))
    if cached:
        return max(cached, key=lambda p: p.stat().st_mtime)

    zip_url = find_zip_url("mplus_groups")
    if not zip_url:
        return None
    try:
        print(f"Downloading topic groups {zip_url} ...")
        data = fetch_url(zip_url, timeout=60)
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            xml_names = [n for n in zf.namelist() if n.lower().endswith(".xml")]
            if not xml_names:
                return None
            out = SOURCE_DIR / Path(xml_names[0]).name.replace("mplus_", "mplus_groups_")
            out.write_bytes(zf.read(xml_names[0]))
            print(f"Saved {out}")
            return out
    except Exception as exc:
        print(f"  Topic groups download failed: {exc}")
        return None


def load_symptom_topic_ids(groups_xml: Path | None) -> set[str]:
    """Topic IDs listed under the MedlinePlus 'Symptoms' health topic group."""
    ids: set[str] = set()
    if groups_xml is None or not groups_xml.is_file():
        return ids
    try:
        root = ET.parse(groups_xml).getroot()
    except Exception:
        return ids
    for grp in root.iter():
        if grp.tag != "group" and not grp.tag.endswith("group"):
            continue
        name = (grp.get("name") or grp.findtext("name") or grp.text or "").strip().lower()
        if name != "symptoms":
            continue
        for tid in grp.findall(".//health-topic-id"):
            val = (tid.text or "").strip()
            if val:
                ids.add(val)
        for tid in grp.findall(".//topic-id"):
            val = (tid.text or "").strip()
            if val:
                ids.add(val)
    print(f"Symptoms topic group IDs: {len(ids)}")
    return ids


def load_symptom_slugs_from_index() -> set[str]:
    slugs: set[str] = set()
    html_path = CACHED_INDEX if CACHED_INDEX.is_file() else None
    if not html_path:
        return slugs
    text = html_path.read_text(encoding="utf-8", errors="replace")
    for m in re.finditer(r"medlineplus\.gov/([a-z0-9]+)\.html", text, re.I):
        slug = m.group(1).lower()
        if slug not in EXCLUDED_SLUGS:
            slugs.add(slug)
    return slugs


def parse_healthtopics_xml(
    xml_path: Path,
    symptom_ids: set[str],
    symptom_slugs: set[str],
) -> list[dict[str, str]]:
    """Match health topics by Symptoms group ID or Symptoms Index URL slug."""
    entries: list[dict[str, str]] = []
    seen: set[str] = set()

    print(f"Parsing health topics XML: {xml_path.name} ...")
    for _event, elem in ET.iterparse(str(xml_path), events=("end",)):
        if elem.tag != "health-topic":
            continue

        lang = (elem.get("language") or elem.findtext("language") or "").strip().lower()
        if lang and lang not in ("en", "english"):
            elem.clear()
            continue

        title = normalize_name(elem.get("title") or elem.findtext("title") or "")
        topic_id = (elem.get("id") or "").strip()
        url = (elem.get("url") or "").lower()
        slug = ""
        if url:
            m = re.search(r"medlineplus\.gov/([a-z0-9]+)\.html", url)
            if m:
                slug = m.group(1)

        in_symptoms = (topic_id and topic_id in symptom_ids) or (slug and slug in symptom_slugs)
        if not in_symptoms:
            elem.clear()
            continue

        if not title:
            elem.clear()
            continue

        key = title.lower()
        if key in seen:
            elem.clear()
            continue
        seen.add(key)

        groups = [(g.text or "").strip() for g in elem.findall("group") if (g.text or "").strip()]

        summary = ""
        node = elem.find("full-summary")
        if node is not None:
            summary = clean_summary("".join(node.itertext()))
        if not summary:
            meta = (elem.get("meta-desc") or "").strip()
            summary = clean_summary(meta) if meta else f"MedlinePlus health topic: {title}."

        entries.append(
            {
                "symptom_name": title,
                "canonical_name": title,
                "description": summary,
                "source": "MedlinePlus_Health_Topics_XML",
                "groups": groups,
            }
        )
        for also in elem.findall("also-called"):
            alias = normalize_name((also.text or "").strip())
            if not alias or alias.lower() == title.lower():
                continue
            akey = alias.lower()
            if akey in seen:
                continue
            seen.add(akey)
            entries.append(
                {
                    "symptom_name": alias,
                    "canonical_name": title,
                    "description": f"MedlinePlus also-called term for {title}. {summary[:200]}",
                    "source": "MedlinePlus_Health_Topics_XML",
                    "groups": groups,
                }
            )
        elem.clear()

    print(f"Matched health topics for Symptoms: {len(entries)} entries")
    return entries


def merge_entries(
    index_entries: list[dict[str, str]],
    xml_entries: list[dict[str, str]],
) -> list[dict[str, str]]:
    """Deduplicate by symptom_name (case-insensitive); prefer XML descriptions."""
    by_key: dict[str, dict[str, str]] = {}

    for row in index_entries:
        key = row["symptom_name"].lower()
        by_key[key] = row

    for row in xml_entries:
        key = row["symptom_name"].lower()
        if key in by_key:
            existing = by_key[key]
            if len(row.get("description", "")) > len(existing.get("description", "")):
                existing["description"] = row["description"]
                existing["source"] = row["source"]
            if row.get("groups"):
                existing["groups"] = row["groups"]
        else:
            by_key[key] = row

    merged = list(by_key.values())
    merged.sort(key=lambda r: r["symptom_name"].lower())
    return merged


def assign_ids_and_categories(rows: list[dict[str, str]]) -> list[dict[str, str]]:
    out = []
    for i, row in enumerate(rows, start=1):
        name = row["symptom_name"]
        groups = row.get("groups") or []
        cat = categorize(name, groups)
        out.append(
            {
                "symptom_id": i,
                "symptom_name": name,
                "category": cat,
                "description": row["description"],
                "search_name": name.lower(),
                "source": row.get("source", "MedlinePlus"),
            }
        )
    return out


def write_csv_parts(rows: list[dict[str, str]]) -> None:
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    fieldnames = ["symptom_id", "symptom_name", "category", "description", "search_name", "source"]
    parts = 0
    for start in range(0, len(rows), ROWS_PER_PART):
        chunk = rows[start : start + ROWS_PER_PART]
        parts += 1
        part_path = OUT_DIR / f"symptoms_part_{parts:02d}.csv"
        with part_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
            writer.writeheader()
            writer.writerows(chunk)
        print(f"Wrote {len(chunk)} rows -> {part_path.name}")

    export_fields = ["symptom_id", "symptom_name", "category", "description"]
    with NLP_EXPORT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=export_fields, extrasaction="ignore")
        writer.writeheader()
        for row in rows:
            writer.writerow({k: row[k] for k in export_fields})
    print(f"NLP export: {NLP_EXPORT} ({len(rows)} rows)")


def main() -> None:
    index_entries = load_symptoms_index()
    xml_entries: list[dict[str, str]] = []
    symptom_slugs = load_symptom_slugs_from_index()
    groups_xml = download_topic_groups_xml()
    symptom_ids = load_symptom_topic_ids(groups_xml)

    xml_path = download_healthtopics_xml()
    if xml_path and xml_path.is_file():
        try:
            xml_entries = parse_healthtopics_xml(xml_path, symptom_ids, symptom_slugs)
        except Exception as exc:
            print(f"  XML parse error: {exc}")

    merged = merge_entries(index_entries, xml_entries)

    # Always merge official canonical + synonym list from Symptoms Index page
    print("Merging official Symptoms Index canonical and synonym entries ...")
    merged = supplement_canonical_index(merged)

    rows = assign_ids_and_categories(merged)
    print(f"Total unique symptoms: {len(rows)}")
    write_csv_parts(rows)


def supplement_canonical_index(existing: list[dict[str, str]]) -> list[dict[str, str]]:
    """Canonical MedlinePlus Symptoms Index primary topics (official page)."""
    canonical = [
        "Abdominal Pain", "Bad Breath", "Bleeding", "Breathing Problems", "Bruises",
        "Chest Pain", "Choking", "Chronic Pain", "Constipation", "Cough",
        "Dehydration", "Diarrhea", "Dizziness and Vertigo", "Edema", "Fainting",
        "Fatigue", "Fever", "Frostbite", "Gas", "Gastrointestinal Bleeding",
        "Headache", "Heartburn", "Heat Illness", "Hives", "Hypothermia",
        "Indigestion", "Itching", "Jaundice", "Motion Sickness",
        "Nausea and Vomiting", "Pain", "Pelvic Pain", "Rare Diseases",
        "Raynaud Phenomenon", "Sciatica", "Speech and Communication Disorders",
        "Stuttering", "Vaginal Bleeding",
    ]
    aliases = [
        ("Acid Reflux", "Heartburn"),
        ("Airsickness", "Motion Sickness"),
        ("Belching", "Gas"),
        ("Bellyache", "Abdominal Pain"),
        ("Bleeding, Gastrointestinal", "Gastrointestinal Bleeding"),
        ("Breath Odor", "Bad Breath"),
        ("Burping", "Gas"),
        ("Carsickness", "Motion Sickness"),
        ("Chilblains", "Frostbite"),
        ("Cluster Headache", "Headache"),
        ("Cold (Temperature)", "Frostbite"),
        ("Communication Disorders", "Speech and Communication Disorders"),
        ("Contusions", "Bruises"),
        ("Dropsy", "Edema"),
        ("Dysentery", "Diarrhea"),
        ("Dysfunctional Uterine Bleeding", "Vaginal Bleeding"),
        ("Dyspepsia", "Indigestion"),
        ("Dyspnea", "Breathing Problems"),
        ("Flatulence", "Gas"),
        ("Frostnip", "Frostbite"),
        ("GI Bleeding", "Gastrointestinal Bleeding"),
        ("Halitosis", "Bad Breath"),
        ("Heat Exhaustion", "Heat Illness"),
        ("Heimlich Maneuver", "Choking"),
        ("Hematoma", "Bleeding"),
        ("Hemorrhage", "Bleeding"),
        ("Hot (Temperature)", "Fever"),
        ("Icterus", "Jaundice"),
        ("Kernicterus", "Jaundice"),
        ("Language Problems", "Speech and Communication Disorders"),
        ("Pain, Abdominal", "Abdominal Pain"),
        ("Pain, Chest", "Chest Pain"),
        ("Pain, Chronic", "Chronic Pain"),
        ("Pruritus", "Itching"),
        ("Pyrexia", "Fever"),
        ("Rectal Bleeding", "Gastrointestinal Bleeding"),
        ("Seasickness", "Motion Sickness"),
        ("Shortness of Breath", "Breathing Problems"),
        ("Stammering", "Stuttering"),
        ("Stomach Ache", "Abdominal Pain"),
        ("Sunstroke", "Heat Illness"),
        ("Swelling", "Edema"),
        ("Syncope", "Fainting"),
        ("Tachypnea", "Breathing Problems"),
        ("Tension Headache", "Headache"),
        ("Thirst", "Dehydration"),
        ("Tiredness", "Fatigue"),
        ("Upset Stomach", "Indigestion"),
        ("Urticaria", "Hives"),
        ("Uterine Bleeding", "Vaginal Bleeding"),
        ("Vascular Headache", "Headache"),
        ("Vasovagal Syncope", "Fainting"),
        ("Vertigo", "Dizziness and Vertigo"),
        ("Vestibular Diseases", "Dizziness and Vertigo"),
        ("Vomiting", "Nausea and Vomiting"),
        ("Weariness", "Fatigue"),
    ]
    by_key = {r["symptom_name"].lower(): r for r in existing}
    for name in canonical:
        key = name.lower()
        if key not in by_key:
            by_key[key] = {
                "symptom_name": name,
                "canonical_name": name,
                "description": f"MedlinePlus Symptoms Index: {name}.",
                "source": "MedlinePlus_Symptoms_Index",
            }
    for alias, canon in aliases:
        key = alias.lower()
        if key not in by_key:
            by_key[key] = {
                "symptom_name": alias,
                "canonical_name": canon,
                "description": f"MedlinePlus Symptoms Index synonym for {canon}.",
                "source": "MedlinePlus_Symptoms_Index",
            }
    return sorted(by_key.values(), key=lambda r: r["symptom_name"].lower())


if __name__ == "__main__":
    main()
