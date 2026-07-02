"""Request/response schemas for analysis endpoints."""

from __future__ import annotations

from pydantic import BaseModel, Field, field_validator


class TranscriptRequest(BaseModel):
    transcript: str = Field(..., min_length=1, description="Consultation transcript text")

    @field_validator("transcript")
    @classmethod
    def strip_transcript(cls, v: str) -> str:
        return v.strip()


class PredictDiseaseRequest(BaseModel):
    text: str = ""
    transcript: str = ""
    symptoms: list[str] = Field(default_factory=list)
    urgent_flags: list[str] = Field(default_factory=list)

    def resolved_text(self) -> str:
        return (self.text or self.transcript or "").strip()

    @field_validator("symptoms", "urgent_flags", mode="before")
    @classmethod
    def coerce_list(cls, v):
        if isinstance(v, str):
            return [s.strip() for s in v.split(",") if s.strip()]
        return v or []


class MedicalProfileRequest(BaseModel):
    allergies: str = ""
    current_medications: str = ""

    @field_validator("allergies", "current_medications")
    @classmethod
    def strip_fields(cls, v: str) -> str:
        return (v or "").strip()


class MedicalTextRequest(BaseModel):
    text: str = Field(..., min_length=1)

    @field_validator("text")
    @classmethod
    def strip_text(cls, v: str) -> str:
        v = v.strip()
        if not v:
            raise ValueError("Enter medical text in Hiligaynon, Ilonggo, or English.")
        return v


class RecognizeSymptomsRequest(BaseModel):
    text: str = ""
    transcript: str = ""
    fuzzy_threshold: int | None = None

    def resolved_text(self) -> str:
        return (self.text or self.transcript or "").strip()
