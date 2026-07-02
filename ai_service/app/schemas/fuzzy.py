"""Fuzzy matching request schemas."""

from __future__ import annotations

from typing import Any

from pydantic import BaseModel, Field


class FuzzyTextQueueItem(BaseModel):
    english_term: str = ""
    local_term: str = ""
    category: str = ""
    match_term: str = ""
    input_language: str = "unknown"
    was_translated: bool = False


class FuzzyProfileRequest(BaseModel):
    translation: dict[str, Any] = Field(default_factory=dict)


class FuzzyTextQueueRequest(BaseModel):
    text_queue: list[FuzzyTextQueueItem | dict[str, Any]] = Field(default_factory=list)
