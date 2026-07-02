"""CLI: read JSON translation from stdin, output fuzzy_matching JSON to stdout."""

from __future__ import annotations

import json
import sys

from medical_fuzzy_matcher import match_profile, match_text_queue


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError:
        print(json.dumps({"error": "invalid_json"}))
        return 1

    if "--text-analysis" in sys.argv:
        queue = payload.get("text_queue") or []
        result = match_text_queue(queue)
    else:
        translation = payload.get("translation") or {}
        result = match_profile(translation)
    print(json.dumps(result, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
