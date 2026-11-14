# Deploying the orchestration service to Render

The `orchestrator/` directory is a small Python service: LangGraph runs the five SignalForge agents in parallel,
Groq writes the narrative, and FastAPI exposes an HTTP API the PHP backend calls.

You need two things before you start:

- A **Groq API key** — free at <https://console.groq.com/keys>.
- A **shared secret** for the PHP backend to authenticate with. Generate one:

  ```bash
  openssl rand -hex 32
  ```

  Keep the output; you will paste it into Render and into `api/config.php`.

---

## 1. Create the service

### Option A — Blueprint (fastest, uses `render.yaml`)

1. Push this repository to GitHub (already done if you are reading this from the repo).
2. In the Render dashboard: **New → Blueprint**.
3. Pick the `signalforge` repository and click **Connect**.
4. Render reads `render.yaml`, shows one service (`signalforge-orchestrator`), and asks for the two secrets marked
   `sync: false`:
   - `GROQ_API_KEY` → your Groq key
   - `SIGNALFORGE_API_TOKEN` → the secret you generated
5. Click **Apply**. The first build takes a few minutes (it installs Python dependencies).

### Option B — Manual web service

1. **New → Web Service**, connect the repository.
2. Set:
   - **Language / Runtime:** `Docker`
   - **Dockerfile Path:** `./orchestrator/Dockerfile`
   - **Docker Build Context Directory:** `.` (the repository root — the Dockerfile copies `orchestrator/…`)
   - **Health Check Path:** `/health`
   - **Instance Type:** `Free`
3. Add the environment variables from the table below.
4. **Create Web Service**.

> The build context **must** be the repository root. If you set it to `orchestrator/`, the `COPY orchestrator/…`
> lines in the Dockerfile will not resolve and the build fails.

---

## 2. Environment variables

| Variable | Required | Value |
|---|---|---|
| `GROQ_API_KEY` | **yes** | Your Groq key (`gsk_…`). Never commit it. |
| `GROQ_MODEL` | yes | `llama-3.3-70b-versatile` (or any Groq chat model) |
| `SIGNALFORGE_API_TOKEN` | **yes** | The secret from `openssl rand -hex 32`. Must match `api/config.php`. |
| `SIGNALFORGE_LLM_MODE` | no | `auto` (default). `heuristic` disables Groq entirely. |
| `SIGNALFORGE_MAX_ATTEMPTS` | no | `3` — retries per agent |
| `SIGNALFORGE_RUN_TTL_SECONDS` | no | `3600` — how long a finished run stays in memory |
| `SIGNALFORGE_CORS_ORIGINS` | no | `*`, or your site origin |
| `LOG_LEVEL` | no | `INFO` |

Render injects `PORT` itself; the container reads it. Do not set it manually.

**If you leave `GROQ_API_KEY` unset** the service still starts and still returns correct figures — it just writes the
narrative deterministically. `/health` reports `"degraded": true` so you can see this at a glance.

---

## 3. Verify the service is up

Render gives you a URL like `https://signalforge-orchestrator.onrender.com`.

```bash
# 1. Liveness + configuration. Should be 200 with "status": "ok".
curl https://signalforge-orchestrator.onrender.com/health
```

Expected:

```json
{
  "status": "ok",
  "version": "1.0.0",
  "provider": "groq",
  "model": "llama-3.3-70b-versatile",
  "groqConfigured": true,
  "degraded": false,
  "authRequired": true,
  "agents": 5
}
```

If `"degraded": true` and `"groqConfigured": false`, the key is missing or misspelled — fix it in
**Environment** and redeploy.

```bash
# 2. Auth is enforced. Without the token this must be 401.
curl -i https://signalforge-orchestrator.onrender.com/v1/agents

# 3. With the token it must be 200 and list five agents.
curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://signalforge-orchestrator.onrender.com/v1/agents
```

```bash
# 4. End-to-end: start a run and poll it.
curl -X POST https://signalforge-orchestrator.onrender.com/v1/runs \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"objective":"Smoke test","dataset":{"experiments":[],"variants":{},"segments":[]}}'
# -> {"id":"…","status":"running", …}

curl -H "Authorization: Bearer YOUR_TOKEN" \
     https://signalforge-orchestrator.onrender.com/v1/runs/THE_ID
# -> "status":"completed", five tasks with "status":"done"
```

An empty dataset is a valid test: every agent should answer honestly ("No experiment has enough traffic to score
yet") rather than inventing numbers.

Interactive API docs are at `/docs`.

---

## 4. Point SignalForge at the service

Edit `api/config.php` on your InfinityFree host:

```php
'orchestrator_url'   => 'https://signalforge-orchestrator.onrender.com',
'orchestrator_token' => 'the same secret you set on Render',
'orchestrator_mode'  => 'auto',   // or 'remote' to require the service
```

Then open **Agent mesh** in the dashboard. The engine strip at the top should read **LangGraph service** with the
Groq model name. If it reads **Orchestration service unreachable**, the strip shows the exact error.

`orchestrator_mode`:

- `auto` — use the service, fall back to the PHP engine if it is down (recommended to start).
- `remote` — require the service; a run fails with a clear message when it is unreachable.
- `local` — never call the service.

---

## 5. The free-tier sleep caveat

**Render's free instance spins down after ~15 minutes of inactivity.** The next request has to wake it, which takes
**30–60 seconds**. Consequences:

- The first run after a quiet period is slow. The dashboard's engine strip may briefly show the service as
  unreachable while it wakes.
- The PHP client's timeout is `orchestrator_timeout` (default 25s). If a cold start exceeds it, the request fails
  even though the service is coming up. Either raise it to `60`, or keep `orchestrator_mode` on `auto` so the run
  falls back to the PHP engine instead of failing.
- **In-memory runs are lost on restart.** A run in flight when the instance sleeps is gone; the PHP side records it
  as failed with "the orchestration service no longer has this run". Completed runs are already mirrored into MySQL,
  so history is unaffected.

To avoid cold starts, either upgrade to a paid instance, or add a free uptime pinger (e.g. cron-job.org) hitting
`/health` every 10 minutes. Note that a pinger keeps the instance awake and consumes your free hours.

---

## 6. Local development

```bash
cd orchestrator
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt -r requirements-dev.txt

cp .env.example .env          # then fill in GROQ_API_KEY
export $(grep -v '^#' .env | xargs)

uvicorn app.main:app --reload --port 8000
```

Run the tests (no network or API key needed — they force `SIGNALFORGE_LLM_MODE=heuristic`):

```bash
pytest
```

Build the image exactly as Render does:

```bash
docker build -f orchestrator/Dockerfile -t signalforge-orchestrator .
docker run -p 8000:8000 -e GROQ_API_KEY=... -e SIGNALFORGE_API_TOKEN=... signalforge-orchestrator
```

---

## 7. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| Build fails: `COPY failed: … orchestrator/requirements.txt` | Build context set to `orchestrator/` | Set the context to the repository root |
| `/health` returns `degraded: true` | `GROQ_API_KEY` missing or wrong | Fix the env var, redeploy |
| `/v1/agents` returns 401 | Token mismatch | `SIGNALFORGE_API_TOKEN` must equal `orchestrator_token` in `config.php` |
| Engine strip says unreachable | Service asleep, or wrong URL | Wait ~60s and reload; check the URL has no trailing slash |
| Run fails with "no longer has this run" | Instance restarted mid-run | Re-run; completed runs are safe in MySQL |
| Groq errors in the log | Rate limit or bad model name | Check `GROQ_MODEL`; the run still completes with deterministic text |
| `invented-numbers` in the trace | Model wrote a figure not in the data | Working as intended — the deterministic text is kept |

The run trace (`GET /v1/runs/{id}` → `trace`) records, per agent, the attempt count, duration, provider, model and
whether the Groq narrative was accepted. That is the first place to look when something seems off.
