#!/usr/bin/env python3
"""Replace common UTF-8 mojibake sequences with the intended Unicode characters."""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
FILES = [
    ROOT / "resources/views/provider/consultation_session.php",
    ROOT / "resources/views/consultation/video_room.php",
]

# Left side is the mojibake as currently stored in UTF-8 files.
PAIRS = [
    ("â€¦", "…"),
    ("â€”", "—"),
    ("â€“", "–"),
    ("Â·", "·"),
    ("â—", "●"),
    ("â—Œ", "○"),
    ("â€™", "’"),
    ("ðŸŽ¤", "🎤"),
    ("ðŸ“·", "📷"),
]


def main() -> None:
    for path in FILES:
        raw = path.read_bytes()
        text = raw.decode("utf-8")
        orig = text
        counts = []
        for src, dst in PAIRS:
            n = text.count(src)
            if n:
                counts.append((src, dst, n))
                text = text.replace(src, dst)
        if text != orig:
            path.write_text(text, encoding="utf-8", newline="\n")
            print("UPDATED", path.name, [(n, src.encode('unicode_escape')) for src, dst, n in counts])
        else:
            print(f"unchanged {path.relative_to(ROOT)}")
            for needle in ("Re-assessing", "Saving", "Connecting to secure"):
                idx = text.find(needle)
                if idx >= 0:
                    chunk = text[idx : idx + 28]
                    print(" ", needle, repr(chunk), list(chunk.encode("utf-8")[:24]))


if __name__ == "__main__":
    main()
