"""Health payload for /health — clarifies what is required for each feature."""

from __future__ import annotations

import logging
import os
from pathlib import Path
from typing import Any

from disease_predictor import load_model_meta, model_available
from transcriber import WHISPER_MODEL_NAME, dependency_status


def build_health_payload() -> dict[str, Any]:
    data_dir = Path(__file__).resolve().parent.parent / "data" / "nlp"
    conditions_csv = data_dir / "medical_conditions.csv"
    allergies_csv = data_dir / "allergies.csv"

    rapidfuzz = "not_installed"
    try:
        import rapidfuzz  # noqa: F401

        rapidfuzz = "available"
    except Exception:
        rapidfuzz = "not_installed"

    nlp_ready = (
        rapidfuzz == "available"
        and conditions_csv.is_file()
        and allergies_csv.is_file()
    )

    tele = dependency_status()
    ml_meta = load_model_meta() or {}

    lexicon_stats: dict[str, Any] = {"path": str(data_dir / "hiligaynon_symptom_lexicon.json"), "loaded": False}
    try:
        from symptom_lexicon_loader import load_lexicon, variant_index

        lex = load_lexicon()
        lexicon_stats = {
            "version": lex.get("version"),
            "path": str(data_dir / "hiligaynon_symptom_lexicon.json"),
            "symptom_count": len(lex.get("symptoms") or {}),
            "loaded": True,
        }
        if variant_index.cache_info().currsize > 0:
            from symptom_lexicon_loader import lexicon_stats as _lex_stats

            lexicon_stats = _lex_stats()
        else:
            lexicon_stats["warming"] = True
    except Exception:
        lexicon_stats = {"path": str(data_dir / "hiligaynon_symptom_lexicon.json"), "loaded": False}

    groq_status = "missing"
    groq_model = "llama-3.3-70b-versatile"
    groq_error: str | None = None
    try:
        from ai_interpreter_config import provider_status, GROQ_API_KEY, GROQ_MODEL
        from groq_client import _startup_health

        ai_interpreter = provider_status()
        groq_model = GROQ_MODEL
        cached = _startup_health
        if cached is not None:
            groq_status = "connected" if cached.get("groq") else "failed"
            groq_error = cached.get("error")
        elif GROQ_API_KEY:
            groq_status = "configured"
        else:
            groq_status = "missing"
    except Exception:
        ai_interpreter = {"enabled": False, "note": "ai_interpreter_config not loaded"}

    port = int(os.environ.get("MEDCONNECT_AI_PORT", "8765"))

    return {
        "success": True,
        "status": "online",
        "service": "medconnect-fastapi",
        "port": port,
        "groq": groq_status,
        "groq_error": groq_error,
        "model": groq_model,
        "legacy_status": "ok",
        "ready_for": {
            "registration_nlp_validation": nlp_ready,
            "teleconsultation_transcription": tele.get("faster_whisper") == "available",
            "teleconsultation_transcript_nlp": tele.get("spacy") == "available",
            "disease_prediction_ml": model_available(),
        },
        "disease_prediction": {
            "model_loaded": model_available(),
            "test_accuracy_percent": ml_meta.get("test_accuracy"),
            "disease_count": ml_meta.get("disease_count"),
            "symptom_count": len(ml_meta.get("symptom_columns", [])),
            "note": "Train with ai_service/train_disease_classifier.py if model_loaded is false.",
        },
        "nlp_validation": {
            "rapidfuzz": rapidfuzz,
            "medical_conditions_csv": conditions_csv.is_file(),
            "allergies_csv": allergies_csv.is_file(),
            "hiligaynon_symptom_lexicon": lexicon_stats,
            "ai_interpreter": ai_interpreter,
            "note": "Step 3 validation only needs rapidfuzz + CSV datasets. Whisper/spaCy are optional.",
        },
        "teleconsultation": {
            **tele,
            "whisper_model": WHISPER_MODEL_NAME,
            "note": "Install with ai_service/install_ai_dependencies.bat for live transcription.",
        },
    }
