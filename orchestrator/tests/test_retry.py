"""Retry behaviour: a failing agent must retry without re-running the others."""
from app import graph as graph_module


CALLS = {"signal-analyst": 0, "segment-scout": 0, "tracking-auditor": 0, "sample-planner": 0, "brief-writer": 0}


def test_agent_retries_and_others_run_once(monkeypatch, dataset):
    """signal-analyst raises twice then succeeds; nothing else is retried."""
    for key in CALLS:
        CALLS[key] = 0

    real_analyse = graph_module.analysis.analyse

    def flaky(agent_key, data):
        CALLS[agent_key] += 1
        if agent_key == "signal-analyst" and CALLS[agent_key] < 3:
            raise RuntimeError("transient groq failure")
        return real_analyse(agent_key, data)

    monkeypatch.setattr(graph_module.analysis, "analyse", flaky)
    monkeypatch.setattr(
        graph_module,
        "narrate",
        lambda *args, **kwargs: (args[-1], {"provider": "heuristic", "model": None, "used": False}),
    )

    built = graph_module.build_graph()
    final = built.invoke(
        {
            "run_id": "retry-test",
            "objective": "Retry check",
            "dataset": dataset,
            "results": {},
            "attempts": {},
            "trace": [],
            "errors": [],
        },
        config={"recursion_limit": 50},
    )

    assert final["results"]["signal-analyst"]["headline"]
    assert CALLS["signal-analyst"] == 3
    for key in ("segment-scout", "tracking-auditor", "sample-planner", "brief-writer"):
        assert CALLS[key] == 1, f"{key} should not have been retried"
    assert final["results"]["__summary__"]["agentsDone"] == 5
