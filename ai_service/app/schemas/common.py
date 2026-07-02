"""Shared response schemas."""

from __future__ import annotations

from typing import Any, Generic, TypeVar

from pydantic import BaseModel, Field

T = TypeVar("T")


class SuccessResponse(BaseModel):
    success: bool = True
    message: str | None = None


class DataResponse(BaseModel, Generic[T]):
    success: bool = True
    data: T
    message: str | None = None


class ErrorResponse(BaseModel):
    success: bool = False
    message: str


class HealthResponse(BaseModel):
    success: bool = True
    status: str = "online"
    service: str
    engine: str = "fastapi"
    port: int
    extra: dict[str, Any] = Field(default_factory=dict)
