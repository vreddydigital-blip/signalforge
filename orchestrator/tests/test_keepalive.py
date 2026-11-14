"""Tests for the self keep-alive thread.

These never touch the network: the ping function is monkeypatched, and the
module's own ``_enabled()`` guard already refuses to run under pytest.
"""
import os
import threading
import time

import pytest

from app import keepalive


def test_disabled_under_pytest():
    """The suite must never ping a real service."""
    assert keepalive._enabled() is False


def test_target_url_prefers_explicit(monkeypatch):
    monkeypatch.setenv("SIGNALFORGE_KEEPALIVE_URL", "https://example.test/health")
    monkeypatch.setenv("RENDER_EXTERNAL_URL", "https://ignored.test")
    assert keepalive.target_url() == "https://example.test/health"


def test_target_url_uses_render_external(monkeypatch):
    monkeypatch.delenv("SIGNALFORGE_KEEPALIVE_URL", raising=False)
    monkeypatch.setenv("RENDER_EXTERNAL_URL", "https://svc.test/")
    assert keepalive.target_url() == "https://svc.test/health"


def test_target_url_falls_back_to_default(monkeypatch):
    monkeypatch.delenv("SIGNALFORGE_KEEPALIVE_URL", raising=False)
    monkeypatch.delenv("RENDER_EXTERNAL_URL", raising=False)
    assert keepalive.target_url() == keepalive.DEFAULT_URL


def test_ping_swallows_connection_error():
    """A dead host must return (False, detail), never raise."""
    ok, detail = keepalive._ping("http://127.0.0.1:9/nope")
    assert ok is False
    assert detail


def test_start_is_idempotent(monkeypatch):
    """Calling start() twice must not spawn a second thread."""
    monkeypatch.setattr(keepalive, "_enabled", lambda: True)
    monkeypatch.setattr(keepalive, "_started", False)
    monkeypatch.setattr(keepalive, "_ping", lambda url: (True, "200 ok"))
    monkeypatch.setenv("SIGNALFORGE_KEEPALIVE_URL", "http://127.0.0.1:9/nope")
    monkeypatch.setenv("SIGNALFORGE_KEEPALIVE_INTERVAL", "60")

    try:
        assert keepalive.start() is True
        before = threading.active_count()
        assert keepalive.start() is True
        assert threading.active_count() == before
        names = [t.name for t in threading.enumerate()]
        assert names.count("signalforge-keepalive") == 1
    finally:
        keepalive._started = False


def test_status_shape():
    status = keepalive.status()
    assert set(status) == {"enabled", "active", "url", "intervalSeconds"}
    assert isinstance(status["intervalSeconds"], int)


def test_interval_floor(monkeypatch):
    """A tiny interval must be floored so we never hammer the service."""
    monkeypatch.setattr(keepalive, "_enabled", lambda: True)
    monkeypatch.setattr(keepalive, "_started", False)
    monkeypatch.setattr(keepalive, "_ping", lambda url: (True, "200 ok"))
    monkeypatch.setenv("SIGNALFORGE_KEEPALIVE_URL", "http://127.0.0.1:9/nope")
    monkeypatch.setenv("SIGNALFORGE_KEEPALIVE_INTERVAL", "1")

    try:
        keepalive.start()
        assert keepalive.status()["intervalSeconds"] >= keepalive.MIN_INTERVAL
    finally:
        keepalive._started = False
