"""Fast smoke test — KB + extractors + triage scoring only (no heavy NLP loaders)."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

from clinical_feature_extractors import extract_all_features  # noqa: E402
from clinical_triage_engine import assess  # noqa: E402
from symptom_knowledge_base import match_symptoms, scan_red_flags_library  # noqa: E402


def main() -> None:
    cases = [
        "I have fever",
        "May lagnat ako",
        "Budlay gid ang ginhawa ko",
        "Masakit akon dughan",
        "Naga suka ko",
        "May dugo sa akon suka",
        "Gapalanakit ulo ko",
        "May sip-on kag ubo ko",
        "Ginakulba ko",
        "I don't feel well",
        "Fever for 5 days",
        "Chest pain and I have heart disease",
        "Pain 8/10 in my abdomen for 2 days",
    ]

    for complaint in cases:
        features = extract_all_features(complaint)
        # Non-empty entities list skips heavy extract_entities() import/loaders.
        result = assess(
            complaint,
            complaint,
            entities=[{"english_term": ""}],
            validated_terms=[],
            confidence_score=80,
        )
        print(
            json.dumps(
                {
                    "input": complaint,
                    "kb_direct": [s["symptom_name"] for s in match_symptoms(complaint)],
                    "red_flags_lib": [f["flag_name"] for f in scan_red_flags_library(complaint)],
                    "features": {
                        "duration": (features.get("duration") or {}).get("label"),
                        "pain": (features.get("pain_scale") or {}).get("label"),
                        "risks": [r["label"] for r in (features.get("risk_factors") or [])],
                    },
                    "classification": result["triage_display"],
                    "score": result["severity_score"],
                    "confidence": result["confidence"],
                    "symptoms": result["detected_symptoms"],
                    "red_flags": result["red_flags"],
                    "recommendation": result["recommendation"],
                },
                ensure_ascii=False,
            )
        )


if __name__ == "__main__":
    main()
