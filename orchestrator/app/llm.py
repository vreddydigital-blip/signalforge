"""Groq access.

The model is used for one narrow job: turning already-computed figures into a
short, readable narrative. It is never asked to produce a number, and every
response is validated before it is allowed to replace the deterministic text.
If Groq is unreachable, rate-limited or returns something unusable, the caller
keeps the deterministic wording and the run records why.
"""
from __future__ import annotations

import json
import logging
import re
from typing import Any

from .config import settings

log = logging.getLogger("signalforge.llm")

_SYSTEM = (
    "You are a senior conversion-rate optimisation analyst writing inside a dashboard. "
    "You are given a JSON object of figures that were already computed from live experiment data. "
    "Rewrite ONLY the headline and the recommendation so they read like a sharp human analyst. "
    "Rules: never invent, change, round or add any number; never mention that you are an AI; "
    "keep the headline under 90 characters and the recommendation under 220 characters; "
    "be concrete and specific to the figures given. "
    'Reply with JSON only, exactly: {"headline": "...", "recommendation": "..."}'
)

_NUMBER = re.compile(r"\d+(?:\.\d+)?")


def _client():
    """Build a ChatGroq client, or return None when Groq is not configured."""
    if not settings.use_groq:
        return None
    try:
        from langchain_groq import ChatGroq
    except ImportError:  # pragma: no cover - dependency is pinned in requirements
        log.warning("langchain-groq is not installed; falling back to deterministic narrative")
        return None
    return ChatGroq(
        model=settings.groq_model,
        api_key=settings.groq_api_key,
        temperature=settings.groq_temperature,
        timeout=settings.groq_timeout,
        max_retries=0,  # retries are owned by the graph's RetryPolicy, not the client
    )


def _numbers(text: str) -> set[str]:
    return set(_NUMBER.findall(text or ""))


def _extract_json(raw: str) -> dict[str, Any] | None:
    raw = (raw or "").strip()
    if raw.startswith("```"):
        raw = re.sub(r"^```[a-zA-Z]*\s*", "", raw)
        raw = re.sub(r"\s*```$", "", raw)
    start, end = raw.find("{"), raw.rfind("}")
    if start == -1 or end == -1 or end <= start:
        return None
    try:
        parsed = json.loads(raw[start : end + 1])
    except json.JSONDecodeError:
        return None
    return parsed if isinstance(parsed, dict) else None


def narrate(agent_key: str, agent_name: str, objective: str, output: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
    """Return (output, meta).

    ``output`` is the agent result, with headline/recommendation replaced only when
    the model's version passes validation. ``meta`` records what happened so the run
    trace can show it.
    """
    meta: dict[str, Any] = {"provider": settings.provider, "model": None, "used": False}
    client = _client()
    if client is None:
        meta["reason"] = "groq-not-configured" if not settings.groq_configured else "heuristic-mode"
        return output, meta

    meta["model"] = settings.groq_model
    payload = {
        "agent": agent_name,
        "objective": objective,
        "headline": output.get("headline"),
        "metrics": output.get("metrics"),
        "findings": output.get("findings"),
        "recommendation": output.get("recommendation"),
        "tone": output.get("tone"),
    }

    try:
        response = client.invoke(
            [
                ("system", _SYSTEM),
                ("human", json.dumps(payload, ensure_ascii=False)),
            ]
        )
    except Exception as exc:  # network, auth, rate limit, timeout
        log.warning("groq call failed for %s: %s", agent_key, exc)
        meta["reason"] = f"groq-error: {type(exc).__name__}"
        return output, meta

    content = getattr(response, "content", "") or ""
    if isinstance(content, list):  # some providers return content blocks
        content = " ".join(str(part.get("text", "")) if isinstance(part, dict) else str(part) for part in content)
    parsed = _extract_json(str(content))
    if not parsed:
        meta["reason"] = "unparseable-response"
        return output, meta

    headline = str(parsed.get("headline") or "").strip()
    recommendation = str(parsed.get("recommendation") or "").strip()
    if not headline or not recommendation:
        meta["reason"] = "incomplete-response"
        return output, meta
    if len(headline) > 140 or len(recommendation) > 400:
        meta["reason"] = "response-too-long"
        return output, meta

    # Guard against hallucinated figures: every number the model writes must already
    # exist in the deterministic output it was given.
    allowed = _numbers(json.dumps(payload, ensure_ascii=False))
    invented = (_numbers(headline) | _numbers(recommendation)) - allowed
    if invented:
        log.warning("groq invented numbers for %s: %s", agent_key, sorted(invented))
        meta["reason"] = "invented-numbers"
        return output, meta

    enriched = dict(output)
    enriched["headline"] = headline
    enriched["recommendation"] = recommendation
    enriched["narrative"] = "groq"
    meta["used"] = True
    return enriched, meta
