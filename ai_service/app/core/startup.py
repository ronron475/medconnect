"""Application startup tasks — cache warming, Groq health probe."""

from __future__ import annotations

import logging
import threading

logger = logging.getLogger("medconnect.api")


def warm_nlp_caches() -> None:
    logger.info("Warming NLP caches...")
    try:
        from dictionary_loader import dictionary_stats, load_dictionary_rows

        load_dictionary_rows()
        logger.info("Dictionary loaded: %s entries", dictionary_stats().get("loaded", 0))
    except Exception as exc:
        logger.warning("Dictionary warm-up failed: %s", exc)

    try:
        from symptom_lexicon_loader import lexicon_stats, variant_index

        variant_index()
        stats = lexicon_stats()
        logger.info(
            "Lexicon loaded: %s symptoms, %s variants",
            stats.get("symptom_count", 0),
            stats.get("variant_count", 0),
        )
    except Exception as exc:
        logger.warning("Lexicon warm-up failed: %s", exc)

    try:
        from hiligaynon_nlp_dataset_loader import dataset_stats, load_rows

        load_rows()
        stats = dataset_stats()
        logger.info("Hiligaynon dataset loaded: %s rows", stats.get("row_count", 0))
    except Exception as exc:
        logger.warning("Hiligaynon dataset warm-up failed: %s", exc)

    try:
        from symptom_phrases_loader import phrase_index

        logger.info("Symptom phrases loaded: %s entries", len(phrase_index()))
    except Exception as exc:
        logger.warning("Symptom phrase warm-up failed: %s", exc)


def run_startup_tasks() -> None:
    logger.info("Running startup tasks")
    try:
        from ai_interpreter_config import GROQ_API_KEY, GROQ_MODEL
        from groq_client import log_startup_config, test_groq_health

        log_startup_config()
        logger.info("Groq configured=%s model=%s", bool(GROQ_API_KEY), GROQ_MODEL)
        threading.Thread(
            target=test_groq_health, kwargs={"force": True}, daemon=True, name="groq-health"
        ).start()
    except Exception as exc:
        logger.warning("AI interpreter config not loaded: %s", exc)

    threading.Thread(target=warm_nlp_caches, daemon=True, name="nlp-cache-warm").start()
