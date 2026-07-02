import xml.etree.ElementTree as ET
from pathlib import Path

path = Path("data/nlp/source/mplus_topics_2026-06-04.xml")
root = ET.parse(path).getroot()
print("root tag:", root.tag)
children = list(root)[:5]
for c in children:
    print(" child:", c.tag, c.attrib)
    for sub in list(c)[:8]:
        print("   ", sub.tag, (sub.text or "")[:60] if sub.text else sub.attrib)
    groups = c.findall(".//group")
    if groups:
        print("   groups:", [g.findtext("name") or g.text or g.attrib for g in groups[:3]])
    break

# count topics with symptom in any group text
count = 0
sample = []
for elem in root.iter():
    if "topic" in elem.tag.lower() and elem.tag.count("}") == 0:
        pass
for elem in root:
    title = elem.findtext("title") or elem.findtext("{*}title")
    if not title:
        continue
    gtext = " ".join(
        (g.findtext("name") or g.findtext("{*}name") or g.text or "")
        for g in elem.findall(".//group")
    ).lower()
    if "symptom" in gtext:
        count += 1
        if len(sample) < 5:
            sample.append((title, gtext))
print("symptom group count (direct child):", count, sample)

# try all elements named health-topic variant
for tag in ["health-topic", "{http://www.nlm.nih.gov/medlineplus}health-topic"]:
    n = 0
    for elem in root.iter(tag):
        n += 1
    print(f"iter {tag}: {n}")
