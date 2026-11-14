import time

import pytest
from fastapi.testclient import TestClient

from app.main import app


@pytest.fixture(scope="module")
def client():
    return TestClient(app)


def _wait_for(client, run_id, timeout=30.0):
    deadline = time.time() + timeout
    payload = {}
    while time.time() < deadline:
        response = client.get(f"/v1/runs/{run_id}")
        assert response.status_code == 200
        payload = response.json()
        if payload["status"] != "running":
            return payload
        time.sleep(0.05)
    raise AssertionError(f"run did not finish in {timeout}s: {payload.get('status')}")


def test_health_reports_config_without_leaking_the_key(client):
    body = client.get("/health").json()
    assert body["status"] == "ok"
    assert body["agents"] == 5
    assert body["groqConfigured"] is False
    assert body["degraded"] is True
    assert "GROQ_API_KEY" not in str(body)


def test_agent_catalog_lists_all_five(client):
    agents = client.get("/v1/agents").json()["agents"]
    assert [a["key"] for a in agents] == [
        "signal-analyst", "segment-scout", "tracking-auditor", "sample-planner", "brief-writer",
    ]


def test_run_completes_with_all_five_agents(client, dataset):
    response = client.post("/v1/runs", json={"objective": "Ship the leader?", "dataset": dataset})
    assert response.status_code == 202
    created = response.json()
    assert created["status"] == "running"

    final = _wait_for(client, created["id"])
    assert final["status"] == "completed"
    assert final["progress"] == 100
    assert [t["status"] for t in final["tasks"]] == ["done"] * 5
    assert final["summary"]["agentsDone"] == 5
    assert final["summary"]["agentsFailed"] == 0
    for task in final["tasks"]:
        assert task["output"]["headline"]
        assert task["output"]["metrics"]
    # five agent entries plus the merge entry
    assert len(final["trace"]) == 6


def test_trace_records_provider_and_attempt(client, dataset):
    created = client.post("/v1/runs", json={"objective": "Trace check", "dataset": dataset}).json()
    final = _wait_for(client, created["id"])
    agent_entries = [e for e in final["trace"] if e["agent"] != "merge"]
    assert len(agent_entries) == 5
    for entry in agent_entries:
        assert entry["provider"] == "heuristic"
        assert entry["attempt"] == 1
        assert entry["durationMs"] >= 0


def test_empty_workspace_degrades_honestly(client, empty_dataset):
    created = client.post("/v1/runs", json={"objective": "Fresh workspace", "dataset": empty_dataset}).json()
    final = _wait_for(client, created["id"])
    assert final["status"] == "completed"
    by_key = {t["agentKey"]: t for t in final["tasks"]}
    assert by_key["signal-analyst"]["output"]["headline"] == "No experiment has enough traffic to score yet"
    assert by_key["sample-planner"]["output"]["headline"] == "No active experiment to plan a sample for"
    assert by_key["tracking-auditor"]["output"]["metrics"][0]["value"] == "30/100"
    # No agent may invent a figure when there is no data.
    for task in final["tasks"]:
        assert task["output"]["metrics"], "every agent still reports its metric labels"
    assert "\u2014" in by_key["signal-analyst"]["output"]["metrics"][2]["value"]


def test_signal_analyst_finds_the_decisive_uplift(client, dataset):
    created = client.post("/v1/runs", json={"objective": "Score", "dataset": dataset}).json()
    final = _wait_for(client, created["id"])
    output = {t["agentKey"]: t["output"] for t in final["tasks"]}["signal-analyst"]
    assert "decisive signal" in output["headline"]
    assert output["metrics"][2]["value"] == "+54.5%"


def test_segment_scout_reports_mobile_as_strongest(client, dataset):
    created = client.post("/v1/runs", json={"objective": "Segments", "dataset": dataset}).json()
    final = _wait_for(client, created["id"])
    output = {t["agentKey"]: t["output"] for t in final["tasks"]}["segment-scout"]
    assert output["metrics"][1]["value"] == "Mobile visitors"
    assert "Mobile visitors shows the strongest response" in output["headline"]


def test_unknown_run_returns_404(client):
    assert client.get("/v1/runs/does-not-exist").status_code == 404


def test_runs_list_includes_started_run(client, dataset):
    created = client.post("/v1/runs", json={"objective": "Listed run", "dataset": dataset}).json()
    _wait_for(client, created["id"])
    runs = client.get("/v1/runs").json()["runs"]
    assert any(run["id"] == created["id"] for run in runs)


def test_cancel_marks_run_cancelled(client, dataset):
    created = client.post("/v1/runs", json={"objective": "Cancel me", "dataset": dataset}).json()
    response = client.post(f"/v1/runs/{created['id']}/cancel")
    assert response.status_code == 200
    assert response.json()["status"] in ("cancelled", "completed")
