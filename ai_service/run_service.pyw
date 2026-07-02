from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
LOG_DIR = ROOT / "storage" / "logs"
LOG_DIR.mkdir(parents=True, exist_ok=True)

sys.stdout = (LOG_DIR / "ai_service.out.log").open("a", encoding="utf-8", buffering=1)
sys.stderr = (LOG_DIR / "ai_service.err.log").open("a", encoding="utf-8", buffering=1)

sys.path.insert(0, str(ROOT / "ai_service"))

import server

server.main()
