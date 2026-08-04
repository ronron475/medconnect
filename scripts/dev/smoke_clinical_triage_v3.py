"""Smoke test for clinical triage CDS v3."""

from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "ai_service"))

from clinical_triage_engine import assess  # noqa: E402
from hiligaynon_medical_nlp_pipeline import analyze  # noqa: E402


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

    print("=== Direct ClinicalTriageEngine ===")
    for complaint in cases:
        result = assess(complaint, complaint, confidence_score=80)
        print(
            json.dumps(
                {
                    "input": complaint,
                    "classification": result["triage_display"],
                    "score": result["severity_score"],
                    "confidence": result["confidence"],
                    "symptoms": result["detected_symptoms"],
                    "red_flags": result["red_flags"],
                    "duration": result["duration"],
                    "risk_factors": result["risk_factors"],
                    "recommendation": result["recommendation"],
                    "reason": (result["reason"] or "")[:160],
                },
                ensure_ascii=False,
            )
        )
        print("---")

    print("\n=== Full NLP Pipeline samples ===")
    for complaint in [
        "Budlay gid ang ginhawa ko",
        "May lagnat ako",
        "May dugo sa akon suka",
        "I don't feel well",
    ]:
        out = analyze(complaint)
        rec = out.get("clinical_recommendation") or {}
        print(
            json.dumps(
                {
                    "input": complaint,
                    "engine": out.get("engine"),
                    "classification": rec.get("classification")
                    or out["nlp_result"].get("classification"),
                    "score": rec.get("severity_score"),
                    "confidence": rec.get("confidence"),
                    "symptoms": rec.get("detected_symptoms"),
                    "recommendation": rec.get("recommendation"),
                },
                ensure_ascii=False,
            )
        )
        print("---")


if __name__ == "__main__":
    main()
