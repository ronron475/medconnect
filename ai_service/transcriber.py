"""Audio/video transcription via Faster-Whisper."""

from __future__ import annotations

import os
from typing import Any

from analyzer import analyze_transcript

WHISPER_MODEL_NAME = os.environ.get("MEDCONNECT_WHISPER_MODEL", "small")
WHISPER_MODEL: Any = None
SPACY_NLP: Any = None


def dependency_status() -> dict[str, str]:
    status: dict[str, str] = {}
    try:
        import faster_whisper  # noqa: F401

        status["faster_whisper"] = "available"
    except Exception:
        status["faster_whisper"] = "not_loaded"

    try:
        import spacy  # noqa: F401

        status["spacy"] = "available"
    except Exception:
        status["spacy"] = "not_loaded"

    return status


def get_whisper_model() -> Any:
    global WHISPER_MODEL
    if WHISPER_MODEL is None:
        from faster_whisper import WhisperModel

        WHISPER_MODEL = WhisperModel(WHISPER_MODEL_NAME, device="cpu", compute_type="int8")
    return WHISPER_MODEL


def get_spacy_nlp() -> Any:
    global SPACY_NLP
    if SPACY_NLP is None:
        import spacy

        SPACY_NLP = spacy.load("en_core_web_sm")
    return SPACY_NLP


def transcribe_file(path: str) -> dict[str, Any]:
    model = get_whisper_model()
    segments, info = model.transcribe(path, language=None, beam_size=5, vad_filter=True)
    transcript = " ".join(
        segment.text.strip() for segment in segments if segment.text.strip()
    ).strip()

    status = dependency_status() | {"whisper_model": WHISPER_MODEL_NAME}

    if transcript:
        analysis = analyze_transcript(transcript, model_status=status)
    else:
        analysis = {
            "hiligaynon_transcript": "",
            "english_transcript": "",
            "symptoms": [],
            "medicines": [],
            "urgent_flags": [],
            "summary": "No speech detected in the uploaded recording.",
            "engine": "faster-whisper-empty-transcript",
            "noun_phrases": [],
            "model_status": status,
        }

    analysis["transcription"] = {
        "language": getattr(info, "language", None),
        "language_probability": getattr(info, "language_probability", None),
        "duration": getattr(info, "duration", None),
        "model": WHISPER_MODEL_NAME,
    }
    return analysis
