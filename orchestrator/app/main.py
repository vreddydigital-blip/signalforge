"""HTTP API for the SignalForge orchestration service.

Endpoints (all under /v1):
    GET  /health          liveness + whether Groq is configured
    GET  /agents          the five agent definitions
    POST /runs            start a run  {objective, dataset}
    GET  /runs            recent runs
    GET  /runs/{id}       run status, per-agent results, merged summary, trace
    POST /runs/{id}/cancel

Auth: when SIGNALFORGE_API_TOKEN is set, every /v1 route except /health requires
`Authorization: Bearer <token>`. The PHP backend sends it.
"""
from __future__ import annotations

import logging
import os
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, status
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel, Field

from . import __version__
from .config import settings
from .graph import AGENT_KEYS
from .store import agent_catalog, cancel_run, create_run, list_runs, snapshot

logging.basicConfig(
    level=os.environ.get("LOG_LEVEL", "INFO").upper(),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)
log = logging.getLogger("signalforge.api")

app = FastAPI(
    title="SignalForge Orchestrator",
    version=__version__,
    description="LangGraph multi-agent orchestration for SignalForge experiments.",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_list(),
    allow_credentials=False,
    allow_methods=["GET", "POST", "OPTIONS"],
    allow_headers=["Authorization", "Content-Type"],
)


def require_token(authorization: str | None = Header(default=None)) -> None:
    if not settings.api_token:
        return
    expected = f"Bearer {settings.api_token}"
    if not authorization or authorization != expected:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Invalid or missing API token.")


class RunRequest(BaseModel):
    objective: str = Field(default="Full workspace sweep", max_length=200)
    dataset: dict[str, Any] = Field(default_factory=dict)


class CompleteRequest(BaseModel):
    prompt: str = Field(max_length=8000)
    system: str | None = Field(default=None, max_length=2000)
    max_tokens: int = Field(default=900, ge=64, le=4000)


@app.get("/")
def root() -> dict[str, Any]:
    return {
        "service": "signalforge-orchestrator",
        "version": __version__,
        "docs": "/docs",
        "health": "/health",
    }


@app.get("/health")
def health() -> dict[str, Any]:
    """Always 200 when the process is up, so Render's health check passes.

    ``degraded`` is true when Groq is not configured: the service still runs and
    still returns correct figures, it just writes the narrative deterministically.
    """
    return {
        "status": "ok",
        "version": __version__,
        "provider": settings.provider,
        "model": settings.groq_model if settings.use_groq else None,
        "groqConfigured": settings.groq_configured,
        "llmMode": settings.llm_mode,
        "degraded": not settings.use_groq,
        "authRequired": bool(settings.api_token),
        "agents": len(AGENT_KEYS),
    }


@app.get("/v1/agents", dependencies=[Depends(require_token)])
def agents() -> dict[str, Any]:
    return {"agents": agent_catalog()}


@app.post("/v1/runs", status_code=status.HTTP_202_ACCEPTED, dependencies=[Depends(require_token)])
def start_run(payload: RunRequest) -> dict[str, Any]:
    objective = (payload.objective or "").strip() or "Full workspace sweep"
    run = create_run(objective, payload.dataset)
    log.info("run %s started: %s", run["id"], objective)
    return run


@app.get("/v1/runs", dependencies=[Depends(require_token)])
def get_runs(limit: int = 20) -> dict[str, Any]:
    return {"runs": list_runs(max(1, min(limit, 100)))}


@app.get("/v1/runs/{run_id}", dependencies=[Depends(require_token)])
def get_run(run_id: str) -> dict[str, Any]:
    run = snapshot(run_id)
    if run is None:
        raise HTTPException(status_code=404, detail="Run not found. It may have expired or the service restarted.")
    return run


@app.post("/v1/runs/{run_id}/cancel", dependencies=[Depends(require_token)])
def cancel(run_id: str) -> dict[str, Any]:
    run = cancel_run(run_id)
    if run is None:
        raise HTTPException(status_code=404, detail="Run not found.")
    return run


@app.post("/v1/complete", dependencies=[Depends(require_token)])
def complete(payload: CompleteRequest) -> dict[str, Any]:
    """Single-shot completion used by the app's AI features (recommendations,
    variant copy). Returns ``text`` plus which provider actually answered, so the
    caller can label heuristic output honestly instead of passing it off as AI.
    """
    from .llm import complete_text

    text, meta = complete_text(payload.prompt, payload.system, payload.max_tokens)
    return {"text": text, "provider": meta.get("provider"), "model": meta.get("model"), "used": meta.get("used", False)}
