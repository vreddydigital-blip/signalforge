"""In-process run store.

Runs execute on a small thread pool so POST /v1/runs returns immediately and the
caller polls GET /v1/runs/{id}. State lives in memory: this service is a stateless
worker, and the PHP backend already persists the durable copy of every run in
MySQL. A restart therefore loses in-flight runs only, which the PHP side reports
as an unreachable service rather than as a completed run.
"""
from __future__ import annotations

import logging
import threading
import time
import uuid
from concurrent.futures import ThreadPoolExecutor
from typing import Any

from .config import settings
from .graph import AGENTS, AGENT_BY_KEY, run_graph

log = logging.getLogger("signalforge.store")

_lock = threading.Lock()
_runs: dict[str, dict[str, Any]] = {}
_pool = ThreadPoolExecutor(max_workers=4, thread_name_prefix="sf-orch")


def _now() -> float:
    return time.time()


def _prune() -> None:
    """Drop finished runs past their TTL and cap the total number kept."""
    cutoff = _now() - settings.run_ttl_seconds
    stale = [
        run_id
        for run_id, run in _runs.items()
        if run["status"] != "running" and run.get("finishedAt", 0) < cutoff
    ]
    for run_id in stale:
        _runs.pop(run_id, None)
    if len(_runs) > settings.max_runs:
        ordered = sorted(_runs.items(), key=lambda item: item[1].get("createdAt", 0))
        for run_id, run in ordered[: len(_runs) - settings.max_runs]:
            if run["status"] != "running":
                _runs.pop(run_id, None)


def _task_skeleton(agent: dict[str, str]) -> dict[str, Any]:
    return {
        "agentKey": agent["key"],
        "agentName": agent["name"],
        "agentRole": agent["role"],
        "icon": agent["icon"],
        "accent": agent["accent"],
        "title": agent["title"],
        "status": "queued",
        "durationMs": None,
        "output": None,
        "error": None,
        "attempts": 0,
        "llmUsed": False,
    }


def create_run(objective: str, dataset: dict[str, Any]) -> dict[str, Any]:
    """Register a run and start it on the pool. Returns the initial snapshot."""
    run_id = uuid.uuid4().hex[:16]
    run = {
        "id": run_id,
        "objective": objective,
        "status": "running",
        "createdAt": _now(),
        "finishedAt": 0,
        "progress": 0,
        "provider": settings.provider,
        "model": settings.groq_model if settings.use_groq else None,
        "tasks": [_task_skeleton(agent) for agent in AGENTS],
        "summary": None,
        "trace": [],
        "error": None,
    }
    with _lock:
        _prune()
        _runs[run_id] = run
    _pool.submit(_execute, run_id, objective, dataset)
    return snapshot(run_id) or run


def _execute(run_id: str, objective: str, dataset: dict[str, Any]) -> None:
    try:
        final = run_graph(run_id, objective, dataset)
    except Exception as exc:  # a graph-level failure, not an agent-level one
        log.exception("run %s failed", run_id)
        with _lock:
            run = _runs.get(run_id)
            if run:
                run["status"] = "failed"
                run["error"] = f"{type(exc).__name__}: {exc}"
                run["finishedAt"] = _now()
                for task in run["tasks"]:
                    if task["status"] == "queued":
                        task["status"] = "failed"
                        task["error"] = "The orchestration graph stopped before this agent ran."
        return

    results = final.get("results") or {}
    trace = final.get("trace") or []
    summary = results.get("__summary__")
    trace_by_agent: dict[str, dict[str, Any]] = {}
    for entry in trace:
        if entry.get("agent") and entry.get("agent") != "merge":
            trace_by_agent[entry["agent"]] = entry

    with _lock:
        run = _runs.get(run_id)
        if not run:
            return
        for task in run["tasks"]:
            key = task["agentKey"]
            output = results.get(key)
            entry = trace_by_agent.get(key) or {}
            if output:
                task["status"] = "done"
                task["output"] = output
                task["durationMs"] = entry.get("durationMs")
                task["attempts"] = entry.get("attempt", 1)
                task["llmUsed"] = bool(entry.get("llmUsed"))
            else:
                task["status"] = "failed"
                task["error"] = "This agent produced no result."
        run["summary"] = summary
        run["trace"] = trace
        run["progress"] = 100
        run["status"] = "completed" if summary and summary.get("agentsFailed", 0) == 0 else "failed"
        run["finishedAt"] = _now()


def snapshot(run_id: str) -> dict[str, Any] | None:
    with _lock:
        run = _runs.get(run_id)
        if not run:
            return None
        return {
            "id": run["id"],
            "objective": run["objective"],
            "status": run["status"],
            "createdAt": run["createdAt"],
            "finishedAt": run["finishedAt"] or None,
            "progress": run["progress"],
            "provider": run["provider"],
            "model": run["model"],
            "tasks": [dict(task) for task in run["tasks"]],
            "summary": run["summary"],
            "trace": list(run["trace"]),
            "error": run["error"],
        }


def list_runs(limit: int = 20) -> list[dict[str, Any]]:
    with _lock:
        ordered = sorted(_runs.values(), key=lambda run: run.get("createdAt", 0), reverse=True)
        return [
            {
                "id": run["id"],
                "objective": run["objective"],
                "status": run["status"],
                "createdAt": run["createdAt"],
                "progress": run["progress"],
            }
            for run in ordered[:limit]
        ]


def cancel_run(run_id: str) -> dict[str, Any] | None:
    """Mark a run cancelled. In-flight agent nodes finish; the result is discarded."""
    with _lock:
        run = _runs.get(run_id)
        if not run:
            return None
        if run["status"] == "running":
            run["status"] = "cancelled"
            run["finishedAt"] = _now()
            for task in run["tasks"]:
                if task["status"] in ("queued", "running"):
                    task["status"] = "failed"
                    task["error"] = "Cancelled by user"
    return snapshot(run_id)


def agent_catalog() -> list[dict[str, str]]:
    return [
        {
            "key": agent["key"],
            "name": agent["name"],
            "role": agent["role"],
            "icon": agent["icon"],
            "accent": agent["accent"],
        }
        for agent in AGENTS
    ]


def agent_meta(key: str) -> dict[str, str] | None:
    return AGENT_BY_KEY.get(key)
