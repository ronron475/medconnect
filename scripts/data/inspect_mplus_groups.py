import xml.etree.ElementTree as ET
from pathlib import Path

for pattern in ["mplus_groups*.xml", "*group*.xml"]:
    files = list(Path("data/nlp/source").glob(pattern))
    if files:
        path = files[0]
        break
else:
    path = None
    print("no groups file")
    import urllib.request, zipfile, io, re
    page = urllib.request.urlopen("https://medlineplus.gov/xml.html").read().decode()
    m = re.search(r'href="([^"]*mplus_groups[^"]*\.zip)"', page)
    if m:
        url = m.group(1)
        data = urllib.request.urlopen(url).read()
        with zipfile.ZipFile(io.BytesIO(data)) as zf:
            name = [n for n in zf.namelist() if n.endswith(".xml")][0]
            path = Path("data/nlp/source") / name
            path.write_bytes(zf.read(name))
            print("saved", path)

if path:
    root = ET.parse(path).getroot()
    print("root", root.tag, "children", len(list(root)))
    for g in list(root)[:3]:
        print(" group", g.tag, g.attrib, (g.text or "")[:40])
        for c in list(g)[:5]:
            print("  ", c.tag, c.text, c.attrib)
