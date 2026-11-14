"""Runtime configuration, read from the environment only.

Nothing here has a default that could leak a credential: the Groq key has no
fallback value, and the service reports itself as degraded when it is missing.
"""
from __future__ import annotations

import os
from dataclasses import dataclass, field


def _env(name: str, default: str | None = None) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return default
    value = value.strip()
    return value or default


def _env_int(name: str, default: int) -> int:
    raw = _env(name)
    if raw is None:
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _env_float(name: str, default: float) -> float:
    raw = _env(name)
    if raw is None:
        return default
    try:
        return float(raw)
    except ValueError:
        return default


@dataclass(frozen=True)
class Settings:
    """Immutable view of the process environment."""

    groq_api_key: str | None = field(default_factory=lambda: _env("GROQ_API_KEY"))
    groq_model: str = field(default_factory=lambda: _env("GROQ_MODEL", "llama-3.3-70b-versatile") or "llama-3.3-70b-versatile")
    groq_temperature: float = field(default_factory=lambda: _env_float("GROQ_TEMPERATURE", 0.2))
    groq_timeout: float = field(default_factory=lambda: _env_float("GROQ_TIMEOUT_SECONDS", 30.0))

    # "auto" uses Groq when a key is present and falls back to deterministic
    # narrative when it is not. "heuristic" never calls the network.
    llm_mode: str = field(default_factory=lambda: (_env("SIGNALFORGE_LLM_MODE", "auto") or "auto").lower())

    # Shared secret the PHP backend sends as a bearer token. Empty disables auth.
    api_token: str | None = field(default_factory=lambda: _env("SIGNALFORGE_API_TOKEN"))

    max_attempts: int = field(default_factory=lambda: _env_int("SIGNALFORGE_MAX_ATTEMPTS", 3))
    run_ttl_seconds: int = field(default_factory=lambda: _env_int("SIGNALFORGE_RUN_TTL_SECONDS", 3600))
    max_runs: int = field(default_factory=lambda: _env_int("SIGNALFORGE_MAX_RUNS", 200))
    cors_origins: str = field(default_factory=lambda: _env("SIGNALFORGE_CORS_ORIGINS", "*") or "*")

    @property
    def groq_configured(self) -> bool:
        return bool(self.groq_api_key)

    @property
    def use_groq(self) -> bool:
        if self.llm_mode == "heuristic":
            return False
        return self.groq_configured

    @property
    def provider(self) -> str:
        return "groq" if self.use_groq else "heuristic"

    def cors_list(self) -> list[str]:
        raw = self.cors_origins.strip()
        if raw == "*":
            return ["*"]
        return [item.strip() for item in raw.split(",") if item.strip()]


settings = Settings()
