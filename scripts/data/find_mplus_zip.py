import re
import urllib.request

page = urllib.request.urlopen("https://medlineplus.gov/xml.html", timeout=60).read().decode("utf-8", "replace")
for m in re.findall(r'href="([^"]+\.zip)"', page):
    print(m)
# groups
for m in re.findall(r"groups[^\"]*\.zip", page, re.I):
    print("pattern", m)
