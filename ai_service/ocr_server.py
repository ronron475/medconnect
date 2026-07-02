"""Deprecated: OCR now runs on the unified FastAPI app at /ocr/extract (port 8765).

This module redirects to the main server for backward compatibility.
"""

from __future__ import annotations

import os
import warnings

warnings.warn(
    "ocr_server.py is deprecated. Use server.py (unified FastAPI on port 8765).",
    DeprecationWarning,
    stacklevel=2,
)

if __name__ == "__main__":
    os.environ.setdefault("MEDCONNECT_AI_PORT", os.environ.get("MEDCONNECT_OCR_PORT", "8766"))
    from server import main

    main()
