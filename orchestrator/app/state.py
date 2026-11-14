"""Shared graph state.

LangGraph merges each node's returned partial state into the running state. The
reducers below decide how: agent results and attempt counters merge by key, while
trace entries and errors append in arrival order.
"""
from __future__ import annotations

import operator
from typing import Annotated, Any, TypedDict


def merge_dict(left: dict[str, Any] | None, right: dict[str, Any] | None) -> dict[str, Any]:
    merged: dict[str, Any] = dict(left or {})
    merged.update(right or {})
    return merged


class GraphState(TypedDict, total=False):
    """State threaded through every node of the orchestration graph."""

    run_id: str
    objective: str
    dataset: dict[str, Any]
    # agent key -> AgentOutput dict
    results: Annotated[dict[str, Any], merge_dict]
    # agent key -> how many times the node has been entered (retries included)
    attempts: Annotated[dict[str, Any], merge_dict]
    # append-only execution trace
    trace: Annotated[list[dict[str, Any]], operator.add]
    errors: Annotated[list[dict[str, Any]], operator.add]
