"""The LangGraph orchestration graph.

Shape:

    START ─┬─ signal-analyst   ─┐
           ├─ segment-scout    ─┤
           ├─ tracking-auditor ─┼─ merge ─ END
           ├─ sample-planner   ─┤
           └─ brief-writer     ─┘

All five agent nodes are fanned out from START, so LangGraph runs them in the same
superstep -- genuinely in parallel, not one after another. Each node has its own
RetryPolicy, so a transient Groq failure retries that agent alone without
re-running the others. Every node appends to the shared trace, which is what the
API returns as the run trace.
"""
from __future__ import annotations

import logging
import time
from typing import Any

from langgraph.graph import END, START, StateGraph
from langgraph.types import RetryPolicy

from . import analysis
from .config import settings
from .llm import narrate
from .state import GraphState

log = logging.getLogger("signalforge.graph")

AGENTS: list[dict[str, str]] = [
    {
        "key": "signal-analyst",
        "name": "Signal Analyst",
        "role": "Scores every experiment for the probability that a challenger beats control.",
        "icon": "activity",
        "accent": "coral",
        "title": "Score every experiment for a decisive signal",
    },
    {
        "key": "segment-scout",
        "name": "Segment Scout",
        "role": "Splits exposures by device, paid traffic and repeat visits to find who benefits.",
        "icon": "users",
        "accent": "blue",
        "title": "Find which audience segment responds best",
    },
    {
        "key": "tracking-auditor",
        "name": "Tracking Auditor",
        "role": "Checks event freshness, conversion coverage and traffic split integrity.",
        "icon": "shield",
        "accent": "green",
        "title": "Audit event flow and traffic split integrity",
    },
    {
        "key": "sample-planner",
        "name": "Sample Planner",
        "role": "Estimates the visitors and days each active test still needs before a decision.",
        "icon": "gauge",
        "accent": "amber",
        "title": "Estimate remaining sample and days to decision",
    },
    {
        "key": "brief-writer",
        "name": "Brief Writer",
        "role": "Turns the weakest result into a framework-grounded brief for the next test.",
        "icon": "sparkles",
        "accent": "purple",
        "title": "Draft the next test brief from the weakest result",
    },
]

AGENT_KEYS = [agent["key"] for agent in AGENTS]
AGENT_BY_KEY = {agent["key"]: agent for agent in AGENTS}


def _make_agent_node(agent: dict[str, str]):
    """Build the node function for one agent."""

    key = agent["key"]

    def node(state: GraphState) -> dict[str, Any]:
        started = time.perf_counter()
        attempt = int((state.get("attempts") or {}).get(key, 0)) + 1
        dataset = state.get("dataset") or {}
        objective = state.get("objective") or "Full workspace sweep"

        # Deterministic figures first. This is the part that must never be wrong.
        output = analysis.analyse(key, dataset)
        output, meta = narrate(key, agent["name"], objective, output)

        duration_ms = int((time.perf_counter() - started) * 1000)
        entry = {
            "agent": key,
            "agentName": agent["name"],
            "event": "completed",
            "attempt": attempt,
            "durationMs": duration_ms,
            "provider": meta.get("provider"),
            "model": meta.get("model"),
            "llmUsed": bool(meta.get("used")),
            "llmNote": meta.get("reason"),
            "tone": output.get("tone"),
            "at": time.time(),
        }
        log.info("agent %s done in %sms (llm=%s)", key, duration_ms, meta.get("used"))
        return {
            "results": {key: output},
            "attempts": {key: attempt},
            "trace": [entry],
        }

    node.__name__ = f"agent_{key.replace('-', '_')}"
    return node


def _merge_node(state: GraphState) -> dict[str, Any]:
    """Fold the five agent outputs into one verdict."""
    results = state.get("results") or {}
    metrics: list[dict[str, Any]] = []
    findings: list[dict[str, Any]] = []
    recommendations: list[dict[str, Any]] = []
    tone = "info"
    done = 0

    for agent in AGENTS:
        output = results.get(agent["key"])
        if not output:
            continue
        done += 1
        for metric in output.get("metrics") or []:
            metrics.append({"label": metric.get("label"), "value": metric.get("value"), "agent": agent["name"]})
        for finding in output.get("findings") or []:
            findings.append({"text": finding, "agent": agent["name"], "accent": agent["accent"]})
        if output.get("recommendation"):
            recommendations.append(
                {"text": output["recommendation"], "agent": agent["name"], "tone": output.get("tone", "info")}
            )
        agent_tone = output.get("tone")
        if agent_tone == "bad":
            tone = "bad"
        elif agent_tone == "warn" and tone != "bad":
            tone = "warn"
        elif agent_tone == "good" and tone == "info":
            tone = "good"

    rank = {"bad": 3, "warn": 2, "good": 1, "info": 0}
    lead = None
    for item in recommendations:
        if lead is None or rank.get(item["tone"], 0) > rank.get(lead["tone"], 0):
            lead = item

    summary = {
        "agentsDone": done,
        "agentsFailed": len(AGENT_KEYS) - done,
        "metrics": metrics,
        "findings": findings,
        "recommendations": recommendations,
        "lead": lead,
        "tone": tone,
    }
    return {
        "results": {"__summary__": summary},
        "trace": [
            {
                "agent": "merge",
                "agentName": "Mesh merge",
                "event": "merged",
                "attempt": 1,
                "durationMs": 0,
                "provider": settings.provider,
                "model": None,
                "llmUsed": False,
                "llmNote": None,
                "tone": tone,
                "at": time.time(),
            }
        ],
    }


def is_transient(exc: Exception) -> bool:
    """Decide whether an agent failure is worth retrying.

    LangGraph's default predicate refuses to retry RuntimeError, which is exactly
    what an upstream SDK raises on a transient failure. We retry anything that
    looks like a network/upstream problem and refuse to retry programming errors,
    so a genuine bug fails fast instead of burning three attempts.
    """
    if isinstance(exc, (ValueError, TypeError, KeyError, IndexError, AttributeError, NameError, SyntaxError, ImportError)):
        return False
    if isinstance(exc, (ConnectionError, TimeoutError, OSError)):
        return True
    try:  # httpx / requests status errors
        import httpx

        if isinstance(exc, httpx.HTTPStatusError):
            return 500 <= exc.response.status_code < 600 or exc.response.status_code == 429
    except ImportError:  # pragma: no cover
        pass
    name = type(exc).__name__.lower()
    if "ratelimit" in name or "timeout" in name or "connection" in name or "apistatus" in name:
        return True
    return isinstance(exc, RuntimeError)


def build_graph():
    """Compile the orchestration graph."""
    graph = StateGraph(GraphState)
    retry = RetryPolicy(
        max_attempts=max(1, settings.max_attempts),
        initial_interval=0.6,
        backoff_factor=2.0,
        retry_on=is_transient,
    )

    for agent in AGENTS:
        graph.add_node(agent["key"], _make_agent_node(agent), retry_policy=retry)
        graph.add_edge(START, agent["key"])
        graph.add_edge(agent["key"], "merge")

    graph.add_node("merge", _merge_node)
    graph.add_edge("merge", END)
    return graph.compile()


_compiled = None


def get_graph():
    global _compiled
    if _compiled is None:
        _compiled = build_graph()
    return _compiled


def run_graph(run_id: str, objective: str, dataset: dict[str, Any]) -> dict[str, Any]:
    """Execute the graph once and return the final state."""
    graph = get_graph()
    initial: GraphState = {
        "run_id": run_id,
        "objective": objective,
        "dataset": dataset,
        "results": {},
        "attempts": {},
        "trace": [],
        "errors": [],
    }
    return graph.invoke(initial, config={"recursion_limit": 50})
