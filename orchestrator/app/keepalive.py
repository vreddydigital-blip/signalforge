"""Self keep-alive for the Render free tier.

Render suspends a free web service after roughly 15 minutes without inbound
traffic, which costs 30-60 seconds on the next request. This module starts one
daemon thread that requests the service's own ``/health`` endpoint on a fixed
interval, so the process keeps seeing inbound traffic and stays warm.

Design constraints:

* **Never blocks the web server.** It is a daemon thread started after the app
  is constructed. It never touches the event loop and never holds a lock the
  request path needs.
* **Never crashes the app.** Every failure is caught and logged. The loop backs
  off instead of spinning, and a failure to start the thread is not fatal.
* **Never required.** If the URL cannot be resolved, or the thread cannot be
  created, the service runs exactly as it did before.

Limitation worth knowing: a self-ping can only *prevent* sleep. Once Render has
suspended the service, this thread is suspended with it, so it cannot wake
itself. An external pinger is what recovers from an already-cold service - see
``docs/ci/keepalive-ping.yml``.

Configuration (all optional, all environment variables):

===============================  ===========================================
``SIGNALFORGE_KEEPALIVE``        ``on`` (default) or ``off`` to disable.
``SIGNALFORGE_KEEPALIVE_URL``    Full URL to ping. Defaults to
                                 ``$RENDER_EXTERNAL_URL/health``, then to the
                                 known production URL.
``SIGNALFORGE_KEEPALIVE_INTERVAL`` Seconds between pings. Default 600 (10 min),
                                 floored at 60.
===============================  ===========================================
"""
from __future__ import annotations

import logging
import os
import sys
import threading
import time
import urllib.error
import urllib.request

log = logging.getLogger("signalforge.keepalive")

DEFAULT_URL = "https://signalforge-orchestrator.onrender.com/health"
DEFAULT_INTERVAL = 600.0
MIN_INTERVAL = 60.0
FIRST_DELAY = 60.0
MAX_BACKOFF = 1800.0
TIMEOUT = 20.0

_started = False
_url: str | None = None
_interval: float = DEFAULT_INTERVAL
_lock = threading.Lock()


def _env_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if not raw:
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def _enabled() -> bool:
    raw = (os.environ.get("SIGNALFORGE_KEEPALIVE") or "on").strip().lower()
    if raw in {"off", "0", "false", "no", "disabled"}:
        return False
    # Never ping from a test run: the suite must not touch the network.
    if "pytest" in sys.modules:
        return False
    return True


def target_url() -> str:
    """Resolve the URL to ping, preferring explicit config over convention."""
    explicit = (os.environ.get("SIGNALFORGE_KEEPALIVE_URL") or "").strip()
    if explicit:
        return explicit
    external = (os.environ.get("RENDER_EXTERNAL_URL") or "").strip().rstrip("/")
    if external:
        return f"{external}/health"
    return DEFAULT_URL


def _ping(url: str) -> tuple[bool, str]:
    """One GET. Returns (ok, human-readable detail). Never raises."""
    request = urllib.request.Request(
        url,
        method="GET",
        headers={
            "User-Agent": "signalforge-keepalive/1.0",
            "Accept": "application/json",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=TIMEOUT) as response:
            body = response.read(200).decode("utf-8", "replace")
            return True, f"{response.status} {body[:120]}"
    except urllib.error.HTTPError as exc:
        return False, f"HTTP {exc.code}"
    except Exception as exc:  # noqa: BLE001 - a pinger must never propagate
        return False, f"{type(exc).__name__}: {exc}"


def _loop(url: str, interval: float) -> None:
    delay = FIRST_DELAY
    failures = 0
    while True:
        time.sleep(delay)
        ok, detail = _ping(url)
        if ok:
            if failures:
                log.info("keepalive recovered after %d failure(s): %s", failures, detail)
            else:
                log.debug("keepalive ok: %s", detail)
            failures = 0
            delay = interval
        else:
            failures += 1
            delay = min(interval * (2 ** min(failures, 4)), MAX_BACKOFF)
            log.warning(
                "keepalive ping failed (%d in a row): %s - retrying in %.0fs",
                failures,
                detail,
                delay,
            )


def start() -> bool:
    """Start the keep-alive thread. Idempotent. Returns True if it is running."""
    global _started, _url, _interval

    if not _enabled():
        log.info("keepalive disabled")
        return False

    with _lock:
        if _started:
            return True
        url = target_url()
        interval = max(MIN_INTERVAL, _env_float("SIGNALFORGE_KEEPALIVE_INTERVAL", DEFAULT_INTERVAL))
        try:
            thread = threading.Thread(
                target=_loop,
                args=(url, interval),
                name="signalforge-keepalive",
                daemon=True,
            )
            thread.start()
        except Exception as exc:  # noqa: BLE001 - startup must not fail
            log.warning("keepalive could not start: %s", exc)
            return False
        _started = True
        _url = url
        _interval = interval
        log.info("keepalive started: %s every %.0fs", url, interval)
        return True


def status() -> dict[str, object]:
    """Small, JSON-safe view for the /health payload."""
    return {
        "enabled": _enabled(),
        "active": _started,
        "url": _url,
        "intervalSeconds": int(_interval),
    }
