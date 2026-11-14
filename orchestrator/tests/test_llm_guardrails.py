"""LLM guardrail tests: a hallucinated figure must never reach the dashboard."""
import json

from app import llm


class _FakeResponse:
    def __init__(self, content):
        self.content = content


class _FakeClient:
    def __init__(self, content):
        self._content = content

    def invoke(self, _messages):
        return _FakeResponse(self._content)


BASE = {
    "headline": "1 experiment carries a decisive signal",
    "metrics": [{"label": "Best uplift", "value": "+54.5%"}],
    "findings": ["Pricing page: Value-first hero at 96% probability."],
    "recommendation": "Roll it out.",
    "tone": "good",
}


def _patch(monkeypatch, content):
    monkeypatch.setattr(llm, "_client", lambda: _FakeClient(content))


def test_accepts_a_clean_rewrite(monkeypatch):
    _patch(monkeypatch, json.dumps({"headline": "Hero rewrite is winning at 96%", "recommendation": "Ship the value-first hero."}))
    output, meta = llm.narrate("signal-analyst", "Signal Analyst", "Ship?", dict(BASE))
    assert meta["used"] is True
    assert output["headline"] == "Hero rewrite is winning at 96%"
    assert output["narrative"] == "groq"


def test_rejects_an_invented_number(monkeypatch):
    _patch(monkeypatch, json.dumps({"headline": "Hero rewrite lifts trials 312%", "recommendation": "Ship it."}))
    output, meta = llm.narrate("signal-analyst", "Signal Analyst", "Ship?", dict(BASE))
    assert meta["used"] is False
    assert meta["reason"] == "invented-numbers"
    assert output["headline"] == BASE["headline"]


def test_rejects_unparseable_output(monkeypatch):
    _patch(monkeypatch, "I think you should ship it.")
    output, meta = llm.narrate("signal-analyst", "Signal Analyst", "Ship?", dict(BASE))
    assert meta["used"] is False
    assert output["headline"] == BASE["headline"]


def test_strips_a_markdown_fence(monkeypatch):
    fenced = '```json\n{"headline": "Decisive signal at 96%", "recommendation": "Ship the challenger."}\n```'
    _patch(monkeypatch, fenced)
    output, meta = llm.narrate("signal-analyst", "Signal Analyst", "Ship?", dict(BASE))
    assert meta["used"] is True


def test_groq_failure_falls_back_to_deterministic_text(monkeypatch):
    class Boom:
        def invoke(self, _messages):
            raise TimeoutError("upstream timed out")

    monkeypatch.setattr(llm, "_client", lambda: Boom())
    output, meta = llm.narrate("signal-analyst", "Signal Analyst", "Ship?", dict(BASE))
    assert meta["used"] is False
    assert meta["reason"].startswith("groq-error")
    assert output == BASE
