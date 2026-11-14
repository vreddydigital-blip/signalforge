import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  Activity,
  ArrowUpRight,
  Check,
  CircleHelp,
  Clock3,
  Gauge,
  Layers3,
  Loader2,
  Network,
  Play,
  RotateCcw,
  ShieldCheck,
  Sparkles,
  Square,
  Users,
  X,
  Zap,
} from "lucide-react";
import { trpc, type AgentRun, type AgentTask } from "@/lib/trpc";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";

const ICONS: Record<string, typeof Activity> = {
  activity: Activity,
  users: Users,
  shield: ShieldCheck,
  gauge: Gauge,
  sparkles: Sparkles,
};

const OBJECTIVES = [
  "Full workspace sweep",
  "Find the next test to run",
  "Audit tracking before launch",
  "Decide whether to ship the leader",
];

function statusLabel(status: AgentTask["status"]) {
  if (status === "queued") return "Queued";
  if (status === "running") return "Working";
  if (status === "done") return "Complete";
  return "Failed";
}

function duration(ms: number | null) {
  if (ms === null) return "";
  if (ms < 1000) return `${ms} ms`;
  return `${(ms / 1000).toFixed(1)} s`;
}

function AgentCard({ task, index }: { task: AgentTask; index: number }) {
  const Icon = ICONS[task.icon] ?? Activity;
  const output = task.output;
  return (
    <article className={`agent-card agent-${task.accent} agent-${task.status}`} style={{ animationDelay: `${index * 60}ms` }}>
      <div className="agent-card-head">
        <span className="agent-avatar">
          <Icon size={16} />
          {task.status === "running" && <i className="agent-pulse" />}
        </span>
        <div className="agent-identity">
          <strong>{task.agentName}</strong>
          <span>{task.agentRole}</span>
        </div>
        <span className={`agent-state state-${task.status}`}>
          {task.status === "running" ? <Loader2 size={11} className="spin" /> : null}
          {task.status === "done" ? <Check size={11} /> : null}
          {task.status === "queued" ? <Clock3 size={11} /> : null}
          {task.status === "failed" ? <X size={11} /> : null}
          {statusLabel(task.status)}
        </span>
      </div>

      <p className="agent-task-title">{task.title}</p>

      {task.status === "running" && (
        <div className="agent-working">
          <span className="agent-scan" />
          <span className="agent-working-copy">Reading live experiment data…</span>
        </div>
      )}

      {task.status === "queued" && (
        <div className="agent-placeholder">
          <span />
          <span />
          <span className="short" />
        </div>
      )}

      {task.status === "failed" && (
        <div className="agent-error">
          <X size={12} />
          <span>{task.error ?? "This agent could not finish."}</span>
        </div>
      )}

      {output && (
        <div className="agent-output">
          <p className={`agent-headline tone-${output.tone}`}>{output.headline}</p>
          <div className="agent-metrics">
            {output.metrics.map(metric => (
              <div key={metric.label}>
                <span>{metric.label}</span>
                <strong>{metric.value}</strong>
              </div>
            ))}
          </div>
          <ul className="agent-findings">
            {output.findings.slice(0, 3).map((finding, findingIndex) => (
              <li key={findingIndex}>{finding}</li>
            ))}
          </ul>
          <div className="agent-recommendation">
            <Sparkles size={11} />
            <span>{output.recommendation}</span>
          </div>
        </div>
      )}

      {task.status === "done" && <span className="agent-duration">{duration(task.durationMs)}</span>}
    </article>
  );
}

export default function Orchestration({ notify }: { notify: (message: string) => void }) {
  const utils = trpc.useUtils();
  const agentsQuery = trpc.orchestrator.agents.useQuery();
  const runsQuery = trpc.orchestrator.runs.useQuery();
  const startMutation = trpc.orchestrator.start.useMutation();
  const tickMutation = trpc.orchestrator.tick.useMutation();
  const cancelMutation = trpc.orchestrator.cancel.useMutation();

  const [objective, setObjective] = useState(OBJECTIVES[0]);
  const [run, setRun] = useState<AgentRun | null>(null);
  const [runId, setRunId] = useState<number | null>(null);
  const ticking = useRef(false);

  const stateQuery = trpc.orchestrator.state.useQuery({ id: runId ?? 0 }, { enabled: Boolean(runId) });

  // Adopt the server's view of the run whenever it changes.
  useEffect(() => {
    if (stateQuery.data) setRun(stateQuery.data);
  }, [stateQuery.data]);

  const isRunning = run?.status === "running";

  // Drive the queue: each tick executes the next batch of agents on the server.
  useEffect(() => {
    if (!runId || !isRunning) return;
    let cancelled = false;
    const step = async () => {
      if (ticking.current || cancelled) return;
      ticking.current = true;
      try {
        const next = await tickMutation.mutateAsync({ id: runId });
        if (!cancelled) setRun(next);
      } catch {
        /* a transient tick failure is retried on the next interval */
      } finally {
        ticking.current = false;
      }
    };
    const timer = window.setInterval(step, 700);
    step();
    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [runId, isRunning]);

  // Refresh the history list once a run settles.
  useEffect(() => {
    if (run && run.status !== "running") utils.orchestrator.runs.invalidate();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [run?.status]);

  const start = useCallback(() => {
    startMutation.mutate(
      { objective },
      {
        onSuccess: created => {
          setRun(created);
          setRunId(created.id);
          notify("Orchestration started — agents are working in parallel.");
        },
        onError: error => notify(error.message),
      }
    );
  }, [objective, startMutation, notify]);

  const cancel = () => {
    if (!runId) return;
    cancelMutation.mutate({ id: runId }, {
      onSuccess: next => { setRun(next); notify("Run cancelled."); },
      onError: error => notify(error.message),
    });
  };

  const openRun = (id: number) => {
    setRunId(id);
    setRun(null);
  };

  const tasks = run?.tasks ?? [];
  const summary = run?.summary;
  const doneCount = tasks.filter(task => task.status === "done").length;
  const runningCount = tasks.filter(task => task.status === "running").length;
  const queuedCount = tasks.filter(task => task.status === "queued").length;

  const mergedMetrics = useMemo(() => {
    if (!summary) return [];
    const seen = new Set<string>();
    return summary.metrics.filter(metric => {
      const key = `${metric.agent}:${metric.label}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  }, [summary]);

  const history = runsQuery.data ?? [];

  return (
    <div className="orchestration-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Agent mesh</div>
          <h1>Multi-agent orchestration</h1>
          <p>Five specialist agents read the same live experiment data in parallel, then merge into one decision.</p>
        </div>
        <div className="orch-heading-actions">
          {isRunning ? (
            <Button variant="outline" onClick={cancel} disabled={cancelMutation.isPending}>
              <Square size={14} />Stop run
            </Button>
          ) : (
            <Button onClick={start} disabled={startMutation.isPending}>
              <Play size={15} />{startMutation.isPending ? "Starting…" : "Run orchestration"}
            </Button>
          )}
        </div>
      </section>

      <section className="orch-launcher">
        <div className="orch-launcher-copy">
          <div className="card-kicker"><Network size={14} />OBJECTIVE</div>
          <p>Tell the mesh what decision you need. Every agent works the same objective from its own angle.</p>
        </div>
        <div className="orch-objective-row">
          <input
            className="orch-objective-input"
            value={objective}
            onChange={event => setObjective(event.target.value)}
            placeholder="e.g. Decide whether to ship the leading variant"
            maxLength={200}
            disabled={isRunning}
          />
          <div className="orch-objective-chips">
            {OBJECTIVES.map(option => (
              <button
                key={option}
                className={objective === option ? "selected" : ""}
                onClick={() => setObjective(option)}
                disabled={isRunning}
              >
                {option}
              </button>
            ))}
          </div>
        </div>
      </section>

      {run && (
        <section className="orch-run-bar">
          <div className="orch-run-meta">
            <span className={`orch-run-status status-${run.status}`}>
              {run.status === "running" ? <Loader2 size={12} className="spin" /> : run.status === "completed" ? <Check size={12} /> : <X size={12} />}
              {run.status === "running" ? "Running" : run.status === "completed" ? "Completed" : run.status === "cancelled" ? "Cancelled" : "Failed"}
            </span>
            <strong>{run.objective}</strong>
            <span className="orch-run-counts">
              {doneCount}/{tasks.length} agents · {runningCount} working · {queuedCount} queued
            </span>
          </div>
          <div className="orch-progress">
            <div className="orch-progress-track">
              <span style={{ width: `${run.progress}%` }} />
            </div>
            <b>{run.progress}%</b>
          </div>
        </section>
      )}

      {!run && (
        <section className="orch-empty">
          <div className="orch-empty-orbit">
            {(agentsQuery.data ?? []).map((agent, index) => {
              const Icon = ICONS[agent.icon] ?? Activity;
              return (
                <span key={agent.key} className={`orbit-node orbit-${agent.accent}`} style={{ animationDelay: `${index * 0.35}s` }}>
                  <Icon size={15} />
                </span>
              );
            })}
            <span className="orbit-core"><Network size={20} /></span>
          </div>
          <div>
            <h3>No run yet</h3>
            <p>Start a run and the five agents below will work the queue in parallel. Each one writes its own readout; the mesh merges them into a single recommendation.</p>
          </div>
        </section>
      )}

      {run && (
        <section className="agent-grid">
          {tasks.map((task, index) => (
            <AgentCard key={task.id} task={task} index={index} />
          ))}
        </section>
      )}

      {run && summary && (
        <section className="orch-result-grid">
          <Card className={`orch-verdict tone-${summary.tone}`}>
            <CardContent>
              <div className="card-kicker"><Zap size={14} />COMBINED RESULT</div>
              <h3>{summary.lead ? summary.lead.text : "Agents are still working…"}</h3>
              <p className="orch-verdict-sub">
                {summary.lead ? `Lead recommendation from ${summary.lead.agent}.` : "The merged verdict appears once the first agents report."}
              </p>
              <div className="orch-verdict-stats">
                <div><span>Agents complete</span><strong>{summary.agentsDone}/{tasks.length}</strong></div>
                <div><span>Failed</span><strong className={summary.agentsFailed > 0 ? "negative-value" : ""}>{summary.agentsFailed}</strong></div>
                <div><span>Signals merged</span><strong>{summary.findings.length}</strong></div>
              </div>
              {summary.recommendations.length > 1 && (
                <div className="orch-reco-list">
                  {summary.recommendations.map((item, index) => (
                    <div key={index} className={`orch-reco reco-${item.tone}`}>
                      <span>{item.agent}</span>
                      <p>{item.text}</p>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          <Card className="orch-metrics-card">
            <CardHeader>
              <div className="card-kicker"><Layers3 size={14} />MERGED METRICS</div>
              <CardTitle>What the mesh measured</CardTitle>
              <p className="card-subtitle">Every figure below comes from your live experiment rows.</p>
            </CardHeader>
            <CardContent>
              {mergedMetrics.length === 0 && (
                <div className="empty-state"><Gauge size={20} /><strong>No metrics yet.</strong><span>Agents report as they finish.</span></div>
              )}
              <div className="orch-metric-grid">
                {mergedMetrics.map((metric, index) => (
                  <div key={`${metric.label}-${index}`}>
                    <span>{metric.label}</span>
                    <strong>{metric.value}</strong>
                    <small>{metric.agent}</small>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </section>
      )}

      {run && summary && summary.findings.length > 0 && (
        <Card className="orch-feed-card">
          <CardHeader>
            <div className="card-kicker"><Activity size={14} />AGENT FEED</div>
            <CardTitle>Findings as they land</CardTitle>
            <p className="card-subtitle">Each line is one agent's observation, attributed to the agent that produced it.</p>
          </CardHeader>
          <CardContent>
            <div className="orch-feed">
              {summary.findings.map((finding, index) => (
                <div className="orch-feed-row" key={index}>
                  <span className={`feed-dot feed-${finding.accent}`} />
                  <p>{finding.text}</p>
                  <span className="feed-agent">{finding.agent}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <section className="orch-roster">
        <div className="section-heading-inline">
          <div>
            <div className="card-kicker"><Users size={14} />AGENT ROSTER</div>
            <h2>The five specialists</h2>
          </div>
          <span className="catalog-note">Each agent is deterministic and reads only your workspace data.</span>
        </div>
        <div className="orch-roster-grid">
          {(agentsQuery.data ?? []).map(agent => {
            const Icon = ICONS[agent.icon] ?? Activity;
            return (
              <div className={`roster-card roster-${agent.accent}`} key={agent.key}>
                <span className="roster-icon"><Icon size={15} /></span>
                <strong>{agent.name}</strong>
                <p>{agent.role}</p>
              </div>
            );
          })}
        </div>
      </section>

      {history.length > 0 && (
        <Card className="orch-history-card">
          <CardHeader>
            <div className="card-kicker"><RotateCcw size={14} />RUN HISTORY</div>
            <CardTitle>Recent orchestration runs</CardTitle>
            <p className="card-subtitle">Open any run to see what each agent concluded.</p>
          </CardHeader>
          <CardContent>
            <div className="orch-history">
              {history.map(item => (
                <button
                  key={item.id}
                  className={`orch-history-row ${runId === item.id ? "selected" : ""}`}
                  onClick={() => openRun(item.id)}
                >
                  <span className={`history-status status-${item.status}`} />
                  <div>
                    <strong>{item.objective}</strong>
                    <small>Run #{item.id} · {item.status}</small>
                  </div>
                  <ArrowUpRight size={13} />
                </button>
              ))}
            </div>
          </CardContent>
        </Card>
      )}

      <section className="orch-footnote">
        <CircleHelp size={15} />
        <span>
          Shared PHP hosting has no background workers, so the queue is advanced by the dashboard: each poll executes the next
          batch of agents and saves their output. Close the tab mid-run and the run resumes where it stopped when you reopen it.
        </span>
      </section>
    </div>
  );
}
