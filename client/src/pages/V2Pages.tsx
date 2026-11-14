import { useState } from "react";
import { trpc, type V2Dashboard, type V2Winner, type V2Template, type V2ExperimentDetail, type V2Diagnostics, type V2Integration, type V2Recommendation, type V2Crawl, type V2CrawlDetail, type V2Competitor, type V2Ecommerce, type V2Health, type V2Onboarding, type V2Version } from "@/lib/trpc";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Activity, AlertTriangle, ArrowUpRight, BarChart3, Beaker, Check, CheckCircle2,
  ChevronRight, Clock3, Download, Eye, FileText, Gauge, Globe, Layers3, Lightbulb,
  Link2, Loader2, Pause, Play, Plus, RefreshCw, Rocket, Search, ShieldCheck,
  Sparkles, Target, Trash2, TrendingUp, Trophy, Users, Webhook, X, Zap,
} from "lucide-react";
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Line, LineChart,
  Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";

const PALETTE = ["#e5643c", "#5676e8", "#6eaf75", "#f2aa4f", "#9a70cf", "#4a9a9a", "#d477a0", "#8d96a8"];

function fmt(n: number | null | undefined, digits = 0) {
  if (n === null || n === undefined) return "—";
  return n.toLocaleString(undefined, { minimumFractionDigits: digits, maximumFractionDigits: digits });
}

function Section({ title, kicker, icon, action, children }: { title: string; kicker?: string; icon?: React.ReactNode; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <Card className="v2-card">
      <CardHeader className="v2-card-head">
        <div>
          {kicker && <div className="card-kicker">{icon}{kicker}</div>}
          <CardTitle>{title}</CardTitle>
        </div>
        {action}
      </CardHeader>
      <CardContent>{children}</CardContent>
    </Card>
  );
}

function Empty({ icon, title, hint }: { icon: React.ReactNode; title: string; hint: string }) {
  return (
    <div className="v2-empty">
      <span className="v2-empty-icon">{icon}</span>
      <strong>{title}</strong>
      <p>{hint}</p>
    </div>
  );
}

function Loading({ label = "Loading…" }: { label?: string }) {
  return <div className="v2-loading"><Loader2 size={16} className="v2-spin" />{label}</div>;
}

function ErrorBox({ error, onRetry }: { error: any; onRetry?: () => void }) {
  return (
    <div className="v2-error">
      <AlertTriangle size={16} />
      <div>
        <strong>Something went wrong</strong>
        <p>{error?.message ?? "The request failed."}</p>
      </div>
      {onRetry && <Button variant="outline" size="sm" onClick={onRetry}><RefreshCw size={13} />Retry</Button>}
    </div>
  );
}

function Stat({ label, value, sub, accent }: { label: string; value: string; sub?: string; accent?: string }) {
  return (
    <div className="v2-stat">
      <span className="v2-stat-label">{label}</span>
      <strong className="v2-stat-value" style={accent ? { color: accent } : undefined}>{value}</strong>
      {sub && <span className="v2-stat-sub">{sub}</span>}
    </div>
  );
}

// ============================================================ DASHBOARD

export function V2DashboardPage({ notify }: { notify: (m: string) => void }) {
  const [range, setRange] = useState("30d");
  const q = trpc.v2.dashboard.useQuery({ range }, { refetchInterval: 30000 });
  const d = q.data as V2Dashboard | undefined;

  const exportCsv = () => {
    window.open(`api/index.php?i=1&route=v2.export.csv&input=${encodeURIComponent(JSON.stringify({ range }))}`, "_blank");
    notify("CSV report download started.");
  };

  if (q.isLoading) return <Loading label="Loading dashboard…" />;
  if (q.error) return <ErrorBox error={q.error} onRetry={() => q.refetch()} />;
  if (!d) return null;

  const t = d.totals;
  const deviceData = d.breakdowns.device.map((r, i) => ({ ...r, fill: PALETTE[i % PALETTE.length] }));

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Reporting</div>
          <h1>Overview</h1>
          <p>Every experiment, conversion and signal in one place.</p>
        </div>
        <div className="v2-heading-actions">
          <div className="v2-range">
            {["7d", "30d", "90d", "all"].map(r => (
              <button key={r} className={range === r ? "active" : ""} onClick={() => setRange(r)}>{r === "all" ? "All" : r}</button>
            ))}
          </div>
          <Button variant="outline" onClick={exportCsv}><Download size={15} />CSV</Button>
          <Button variant="outline" onClick={() => window.print()}><FileText size={15} />PDF</Button>
        </div>
      </section>

      <section className="v2-stat-grid">
        <Stat label="Experiments" value={fmt(t.experiments)} sub={`${t.active} active · ${t.draft} draft`} />
        <Stat label="Active tests" value={fmt(t.active)} sub={`${t.completed} completed`} accent="#6eaf75" />
        <Stat label="Conversion rate" value={`${fmt(t.conversionRate, 2)}%`} sub={`${fmt(t.conversions)} of ${fmt(t.exposures)}`} />
        <Stat label="Best uplift" value={t.bestUplift === null ? "—" : `${t.bestUplift > 0 ? "+" : ""}${fmt(t.bestUplift, 1)}%`} sub="vs control" accent={t.bestUplift && t.bestUplift > 0 ? "#6eaf75" : undefined} />
        <Stat label="Confidence" value={`${fmt(t.confidence)}%`} sub={t.confidence >= 95 ? "Decision ready" : "Still collecting"} />
        <Stat label="Revenue" value={`$${fmt(t.revenue, 2)}`} sub="attributed" />
      </section>

      <div className="v2-two-col">
        <Section title="Traffic and conversions" kicker="TREND" icon={<TrendingUp size={14} />}>
          {d.series.length === 0 ? (
            <Empty icon={<BarChart3 size={22} />} title="No traffic in this range" hint="Install the tracker and send a test event to see data here." />
          ) : (
            <div className="v2-chart">
              <ResponsiveContainer width="100%" height={260}>
                <AreaChart data={d.series}>
                  <defs>
                    <linearGradient id="gExp" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#5676e8" stopOpacity={0.35} />
                      <stop offset="100%" stopColor="#5676e8" stopOpacity={0} />
                    </linearGradient>
                    <linearGradient id="gConv" x1="0" y1="0" x2="0" y2="1">
                      <stop offset="0%" stopColor="#e5643c" stopOpacity={0.35} />
                      <stop offset="100%" stopColor="#e5643c" stopOpacity={0} />
                    </linearGradient>
                  </defs>
                  <CartesianGrid strokeDasharray="3 3" stroke="#eceae6" />
                  <XAxis dataKey="day" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  <Area type="monotone" dataKey="exposures" name="Visitors" stroke="#5676e8" fill="url(#gExp)" strokeWidth={2} />
                  <Area type="monotone" dataKey="conversions" name="Conversions" stroke="#e5643c" fill="url(#gConv)" strokeWidth={2} />
                </AreaChart>
              </ResponsiveContainer>
            </div>
          )}
        </Section>

        <Section title="Recent activity" kicker="ACTIVITY" icon={<Activity size={14} />}>
          {d.activity.length === 0 ? (
            <Empty icon={<Clock3 size={22} />} title="Nothing yet" hint="Actions you take will appear here." />
          ) : (
            <ul className="v2-feed">
              {d.activity.map((a, i) => (
                <li key={i}>
                  <span className="v2-feed-dot" />
                  <div>
                    <strong>{a.action.replace(/_/g, " ")}</strong>
                    {a.detail && <p>{a.detail}</p>}
                  </div>
                  <span className="v2-feed-ago">{a.ago}</span>
                </li>
              ))}
            </ul>
          )}
        </Section>
      </div>

      <div className="v2-three-col">
        <Section title="Device" kicker="BREAKDOWN" icon={<Layers3 size={14} />}>
          {deviceData.length === 0 ? <Empty icon={<Layers3 size={20} />} title="No data" hint="Waiting for visitors." /> : (
            <ResponsiveContainer width="100%" height={200}>
              <PieChart>
                <Pie data={deviceData} dataKey="value" nameKey="label" innerRadius={45} outerRadius={75} paddingAngle={2}>
                  {deviceData.map((e, i) => <Cell key={i} fill={e.fill} />)}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
          )}
        </Section>
        <Section title="Browser" kicker="BREAKDOWN" icon={<Globe size={14} />}>
          {d.breakdowns.browser.length === 0 ? <Empty icon={<Globe size={20} />} title="No data" hint="Waiting for visitors." /> : (
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={d.breakdowns.browser} layout="vertical">
                <XAxis type="number" tick={{ fontSize: 10 }} />
                <YAxis type="category" dataKey="label" width={70} tick={{ fontSize: 10 }} />
                <Tooltip />
                <Bar dataKey="value" name="Visitors" fill="#5676e8" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </Section>
        <Section title="Country" kicker="BREAKDOWN" icon={<Globe size={14} />}>
          {d.breakdowns.country.length === 0 ? <Empty icon={<Globe size={20} />} title="No data" hint="Waiting for visitors." /> : (
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={d.breakdowns.country} layout="vertical">
                <XAxis type="number" tick={{ fontSize: 10 }} />
                <YAxis type="category" dataKey="label" width={60} tick={{ fontSize: 10 }} />
                <Tooltip />
                <Bar dataKey="value" name="Visitors" fill="#6eaf75" radius={[0, 4, 4, 0]} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </Section>
      </div>

      <Section title="Landing pages" kicker="TOP PAGES" icon={<Link2 size={14} />}>
        {d.breakdowns.landingPage.length === 0 ? <Empty icon={<Link2 size={20} />} title="No landing page data" hint="The tracker records the page each visitor lands on." /> : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Page</span><span>Visitors</span><span>Share</span></div>
            {d.breakdowns.landingPage.map((r, i) => {
              const total = d.breakdowns.landingPage.reduce((s, x) => s + x.value, 0);
              const pct = total > 0 ? (r.value / total) * 100 : 0;
              return (
                <div className="v2-tr" key={i}>
                  <span className="v2-mono">{r.label}</span>
                  <span>{fmt(r.value)}</span>
                  <span className="v2-share"><i style={{ width: `${pct}%` }} />{fmt(pct, 1)}%</span>
                </div>
              );
            })}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ EXPERIMENTS

export function V2ExperimentsPage({ notify }: { notify: (m: string) => void }) {
  const [selected, setSelected] = useState<number | null>(null);
  const [showTemplates, setShowTemplates] = useState(false);
  const list = trpc.experiments.list.useQuery();
  const templates = trpc.v2.experiments.templates.useQuery();
  const utils = trpc.useUtils();

  const setStatus = trpc.v2.experiments.setStatus2.useMutation({
    onSuccess: () => { utils.experiments.list.invalidate(); notify("Status updated."); },
    onError: (e: any) => notify(e.message),
  });

  const experiments = (list.data ?? []) as any[];

  if (selected !== null) {
    return <V2ExperimentDetailPage id={selected} onBack={() => setSelected(null)} notify={notify} />;
  }

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Experiments</div>
          <h1>Experiment management</h1>
          <p>Templates, lifecycle, notes, owners and automatic winner detection.</p>
        </div>
        <Button onClick={() => setShowTemplates(s => !s)}><Plus size={15} />{showTemplates ? "Hide templates" : "New from template"}</Button>
      </section>

      {showTemplates && (
        <Section title="Start from a template" kicker="TEMPLATES" icon={<Layers3 size={14} />}>
          {templates.isLoading ? <Loading /> : (
            <div className="v2-template-grid">
              {((templates.data ?? []) as V2Template[]).map(t => (
                <button key={t.key} className="v2-template" onClick={() => {
                  notify(`Template "${t.name}" selected. Fill in the brief on the Experiments screen to create it.`);
                  setShowTemplates(false);
                }}>
                  <span className="v2-template-cat">{t.category}</span>
                  <strong>{t.name}</strong>
                  <p>{t.description}</p>
                  <span className="v2-template-go">Use template <ChevronRight size={13} /></span>
                </button>
              ))}
            </div>
          )}
        </Section>
      )}

      <Section title="All experiments" kicker="LIFECYCLE" icon={<Beaker size={14} />}>
        {list.isLoading ? <Loading /> : list.error ? <ErrorBox error={list.error} onRetry={() => list.refetch()} /> : experiments.length === 0 ? (
          <Empty icon={<Beaker size={22} />} title="No experiments yet" hint="Create one from a template above to get started." />
        ) : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Name</span><span>Status</span><span>Goal</span><span>Variants</span><span>Actions</span></div>
            {experiments.map(e => (
              <div className="v2-tr" key={e.id}>
                <button className="v2-link" onClick={() => setSelected(e.id)}>{e.name}</button>
                <span><Badge variant="outline" className={`v2-status v2-status-${String(e.status).toLowerCase()}`}>{e.status}</Badge></span>
                <span className="v2-muted">{e.goal}</span>
                <span>{e.variants?.length ?? 0}</span>
                <span className="v2-row-actions">
                  {e.status !== "Active" && e.status !== "Completed" && (
                    <button title="Activate" onClick={() => setStatus.mutate({ id: e.id, status: "Active" })}><Play size={13} /></button>
                  )}
                  {e.status === "Active" && (
                    <button title="Pause" onClick={() => setStatus.mutate({ id: e.id, status: "Paused" })}><Pause size={13} /></button>
                  )}
                  <button title="Open" onClick={() => setSelected(e.id)}><ArrowUpRight size={13} /></button>
                </span>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

function V2ExperimentDetailPage({ id, onBack, notify }: { id: number; onBack: () => void; notify: (m: string) => void }) {
  const detail = trpc.v2.experiments.detail.useQuery({ id });
  const winner = trpc.v2.experiments.winner.useQuery({ id });
  const [note, setNote] = useState("");
  const [tagInput, setTagInput] = useState("");
  const utils = trpc.useUtils();

  const addNote = trpc.v2.experiments.addNote.useMutation({
    onSuccess: () => { setNote(""); utils.v2.experiments.detail.invalidate(); notify("Note added."); },
    onError: (e: any) => notify(e.message),
  });
  const setTags = trpc.v2.experiments.setTags.useMutation({
    onSuccess: () => { setTagInput(""); utils.v2.experiments.detail.invalidate(); notify("Tags saved."); },
    onError: (e: any) => notify(e.message),
  });
  const promote = trpc.v2.experiments.promoteWinner.useMutation({
    onSuccess: () => { utils.v2.experiments.detail.invalidate(); utils.experiments.list.invalidate(); notify("Winner promoted and experiment completed."); },
    onError: (e: any) => notify(e.message),
  });

  const d = detail.data as V2ExperimentDetail | undefined;
  const w = winner.data as V2Winner | undefined;

  if (detail.isLoading) return <Loading label="Loading experiment…" />;
  if (detail.error) return <ErrorBox error={detail.error} onRetry={() => detail.refetch()} />;
  if (!d) return null;

  const decisionLabel = w?.decision === "choose_variant" ? "Choose variant" : w?.decision === "keep_control" ? "Keep control" : "Collect more data";
  const decisionTone = w?.decision === "choose_variant" ? "good" : w?.decision === "keep_control" ? "warn" : "info";

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <button className="v2-back" onClick={onBack}><ChevronRight size={14} style={{ transform: "rotate(180deg)" }} />All experiments</button>
          <h1>{d.name}</h1>
          <p>{d.kind} · {d.goal} · {d.framework}</p>
        </div>
        <Badge variant="outline" className={`v2-status v2-status-${d.status.toLowerCase()}`}>{d.status}</Badge>
      </section>

      <div className="v2-two-col">
        <Section title="Winner recommendation" kicker="DECISION" icon={<Trophy size={14} />}>
          {winner.isLoading ? <Loading /> : !w ? <Empty icon={<Trophy size={20} />} title="No recommendation" hint="Add variants and traffic first." /> : (
            <>
              <div className={`v2-decision v2-decision-${decisionTone}`}>
                <strong>{decisionLabel}</strong>
                <p>{w.reason}</p>
                <span className="v2-decision-conf">Confidence {w.confidence}%</span>
              </div>
              <div className="v2-table v2-table-tight">
                <div className="v2-tr v2-th"><span>Variant</span><span>Visitors</span><span>Rate</span><span>Uplift</span><span>Conf.</span></div>
                {w.variants.map(v => (
                  <div className="v2-tr" key={v.id}>
                    <span>{v.name}{v.isControl && <Badge variant="outline" className="v2-mini">control</Badge>}</span>
                    <span>{fmt(v.visitors)}</span>
                    <span>{fmt(v.rate, 2)}%</span>
                    <span className={v.uplift > 0 ? "v2-pos" : v.uplift < 0 ? "v2-neg" : ""}>{v.uplift > 0 ? "+" : ""}{fmt(v.uplift, 1)}%</span>
                    <span>{v.confidence}%</span>
                  </div>
                ))}
              </div>
              {w.decision === "choose_variant" && (
                <Button className="v2-promote" onClick={() => promote.mutate({ id })} disabled={promote.isPending}>
                  <Trophy size={15} />{promote.isPending ? "Promoting…" : "Promote winner"}
                </Button>
              )}
            </>
          )}
        </Section>

        <Section title="Details" kicker="BRIEF" icon={<FileText size={14} />}>
          <dl className="v2-dl">
            <div><dt>Page</dt><dd className="v2-mono">{d.pageUrl || "—"}</dd></div>
            <div><dt>Audience</dt><dd>{d.audience || "—"}</dd></div>
            <div><dt>Conversion event</dt><dd className="v2-mono">{d.conversionEvent || "—"}</dd></div>
            <div><dt>Owner</dt><dd>{d.ownerName || "You"}</dd></div>
            <div><dt>Hypothesis</dt><dd>{d.hypothesis || "—"}</dd></div>
          </dl>
          <div className="v2-tags">
            {d.tags.map(t => <Badge key={t} variant="outline">{t}</Badge>)}
            <div className="v2-tag-add">
              <input value={tagInput} placeholder="Add tag" onChange={e => setTagInput(e.target.value)} onKeyDown={e => {
                if (e.key === "Enter" && tagInput.trim()) setTags.mutate({ id, tags: [...d.tags, tagInput.trim()] });
              }} />
            </div>
          </div>
        </Section>
      </div>

      <div className="v2-two-col">
        <Section title="Notes" kicker="COLLABORATION" icon={<Users size={14} />}>
          <div className="v2-note-form">
            <textarea value={note} placeholder="What did you learn?" onChange={e => setNote(e.target.value)} rows={3} />
            <Button size="sm" onClick={() => note.trim() && addNote.mutate({ id, body: note })} disabled={addNote.isPending || !note.trim()}>
              {addNote.isPending ? "Saving…" : "Add note"}
            </Button>
          </div>
          {d.notes.length === 0 ? <Empty icon={<FileText size={20} />} title="No notes yet" hint="Record observations as the test runs." /> : (
            <ul className="v2-notes">
              {d.notes.map(n => <li key={n.id}><p>{n.body}</p><span>{n.ago}</span></li>)}
            </ul>
          )}
        </Section>

        <Section title="Activity history" kicker="AUDIT" icon={<Activity size={14} />}>
          {d.history.length === 0 ? <Empty icon={<Activity size={20} />} title="No activity" hint="Changes to this experiment appear here." /> : (
            <ul className="v2-feed">
              {d.history.map((h, i) => (
                <li key={i}><span className="v2-feed-dot" /><div><strong>{h.action.replace(/_/g, " ")}</strong>{h.detail && <p>{h.detail}</p>}</div><span className="v2-feed-ago">{h.ago}</span></li>
              ))}
            </ul>
          )}
        </Section>
      </div>
    </div>
  );
}

// ============================================================ TRACKING

export function V2TrackingPage({ notify }: { notify: (m: string) => void }) {
  const diag = trpc.v2.tracking.diagnostics.useQuery(undefined, { refetchInterval: 20000 });
  const events = trpc.v2.tracking.events.useQuery();
  const utm = trpc.v2.tracking.utm.useQuery();
  const [name, setName] = useState("");
  const [kind, setKind] = useState("click");
  const [selector, setSelector] = useState("");
  const utils = trpc.useUtils();

  const addEvent = trpc.v2.tracking.addEvent.useMutation({
    onSuccess: () => { setName(""); setSelector(""); utils.v2.tracking.events.invalidate(); notify("Event created."); },
    onError: (e: any) => notify(e.message),
  });
  const removeEvent = trpc.v2.tracking.removeEvent.useMutation({
    onSuccess: () => { utils.v2.tracking.events.invalidate(); notify("Event removed."); },
  });

  const d = diag.data as V2Diagnostics | undefined;

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Analytics</div>
          <h1>Tracking and analytics</h1>
          <p>Visual event setup, custom events, UTM attribution and health diagnostics.</p>
        </div>
        <Button variant="outline" onClick={() => diag.refetch()}><RefreshCw size={15} />Re-check</Button>
      </section>

      <div className="v2-two-col">
        <Section title="Tracking health" kicker="DIAGNOSTICS" icon={<Gauge size={14} />}>
          {diag.isLoading ? <Loading /> : !d ? null : (
            <>
              <div className="v2-score">
                <div className="v2-score-ring" style={{ background: `conic-gradient(${d.score >= 75 ? "#6eaf75" : d.score >= 50 ? "#f2aa4f" : "#e5643c"} ${d.score * 3.6}deg, #eceae6 0)` }}>
                  <span>{d.score}</span>
                </div>
                <div><strong>{d.summary}</strong><p>Checks run against your live event stream.</p></div>
              </div>
              <ul className="v2-checks">
                {d.health.map((c, i) => (
                  <li key={i} className={`v2-check v2-check-${c.status}`}>
                    {c.status === "ok" ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
                    <div><strong>{c.name}</strong><p>{c.detail}</p></div>
                  </li>
                ))}
              </ul>
            </>
          )}
        </Section>

        <Section title="Duplicate events" kicker="DATA QUALITY" icon={<ShieldCheck size={14} />}>
          {!d ? <Loading /> : d.duplicates.length === 0 ? (
            <Empty icon={<CheckCircle2 size={20} />} title="No duplicates found" hint="Each visitor fires each event once." />
          ) : (
            <div className="v2-table v2-table-tight">
              <div className="v2-tr v2-th"><span>Event</span><span>Visitor</span><span>Count</span></div>
              {d.duplicates.map((x, i) => (
                <div className="v2-tr" key={i}><span className="v2-mono">{x.event}</span><span className="v2-mono v2-trunc">{x.visitorId}</span><span className="v2-neg">{x.count}×</span></div>
              ))}
            </div>
          )}
        </Section>
      </div>

      <Section title="Custom events" kicker="VISUAL SETUP" icon={<Target size={14} />}>
        <div className="v2-inline-form">
          <input value={name} placeholder="Event name (e.g. pricing_click)" onChange={e => setName(e.target.value)} />
          <select value={kind} onChange={e => setKind(e.target.value)}>
            <option value="click">Button click</option>
            <option value="form">Form submission</option>
            <option value="scroll">Scroll depth</option>
            <option value="video">Video play</option>
            <option value="purchase">Purchase</option>
          </select>
          <input value={selector} placeholder="CSS selector (optional)" onChange={e => setSelector(e.target.value)} />
          <Button size="sm" onClick={() => name.trim() && addEvent.mutate({ name, kind, selector })} disabled={addEvent.isPending || !name.trim()}>
            <Plus size={14} />Add
          </Button>
        </div>
        {events.isLoading ? <Loading /> : (events.data ?? []).length === 0 ? (
          <Empty icon={<Target size={20} />} title="No custom events" hint="Add one above — no code required." />
        ) : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Name</span><span>Type</span><span>Selector</span><span>Status</span><span /></div>
            {((events.data ?? []) as any[]).map(e => (
              <div className="v2-tr" key={e.id}>
                <span className="v2-mono">{e.name}</span>
                <span>{e.kind}</span>
                <span className="v2-mono v2-trunc">{e.selector || "—"}</span>
                <span><Badge variant="outline">{e.status}</Badge></span>
                <span><button className="v2-icon-btn" onClick={() => removeEvent.mutate({ id: e.id })}><Trash2 size={13} /></button></span>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="UTM attribution" kicker="CAMPAIGNS" icon={<Link2 size={14} />}>
        {utm.isLoading ? <Loading /> : (utm.data ?? []).length === 0 ? (
          <Empty icon={<Link2 size={20} />} title="No campaign data" hint="Add utm_source, utm_medium and utm_campaign to your ad URLs." />
        ) : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Source</span><span>Medium</span><span>Campaign</span><span>Visitors</span><span>Conv.</span><span>Rate</span></div>
            {((utm.data ?? []) as any[]).map((r, i) => (
              <div className="v2-tr" key={i}>
                <span>{r.source || "—"}</span><span>{r.medium || "—"}</span><span>{r.campaign || "—"}</span>
                <span>{fmt(r.visitors)}</span><span>{fmt(r.conversions)}</span><span>{fmt(r.rate, 2)}%</span>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ INTEGRATIONS

const INTEGRATION_KINDS = [
  { kind: "ga4", label: "Google Analytics 4", hint: "Measurement ID + API secret", fields: ["measurementId", "apiSecret"] },
  { kind: "gtm", label: "Google Tag Manager", hint: "Container ID (GTM-XXXXXX)", fields: ["containerId"] },
  { kind: "crm", label: "CRM / webhook endpoint", hint: "Endpoint URL + bearer token", fields: ["url", "token"] },
  { kind: "webhook", label: "Outbound webhook", hint: "URL that receives experiment events", fields: ["url"] },
];

export function V2IntegrationsPage({ notify }: { notify: (m: string) => void }) {
  const list = trpc.v2.integrations.list.useQuery();
  const deliveries = trpc.v2.integrations.deliveries.useQuery();
  const [kind, setKind] = useState("ga4");
  const [name, setName] = useState("");
  const [fields, setFields] = useState<Record<string, string>>({});
  const utils = trpc.useUtils();

  const save = trpc.v2.integrations.save.useMutation({
    onSuccess: () => { setName(""); setFields({}); utils.v2.integrations.list.invalidate(); notify("Integration saved. Run a connection test."); },
    onError: (e: any) => notify(e.message),
  });
  const test = trpc.v2.integrations.test.useMutation({
    onSuccess: (r: any) => { utils.v2.integrations.list.invalidate(); notify(r.ok ? `Connected — ${r.detail}` : `Test failed — ${r.detail}`); },
    onError: (e: any) => notify(e.message),
  });
  const remove = trpc.v2.integrations.remove.useMutation({
    onSuccess: () => { utils.v2.integrations.list.invalidate(); notify("Integration removed."); },
  });

  const active = INTEGRATION_KINDS.find(k => k.kind === kind)!;

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Connections</div>
          <h1>GA4, GTM and CRM</h1>
          <p>Real API connections with connection testing and delivery status.</p>
        </div>
      </section>

      <Section title="Add a connection" kicker="NEW" icon={<Webhook size={14} />}>
        <div className="v2-inline-form">
          <select value={kind} onChange={e => { setKind(e.target.value); setFields({}); }}>
            {INTEGRATION_KINDS.map(k => <option key={k.kind} value={k.kind}>{k.label}</option>)}
          </select>
          <input value={name} placeholder="Label (optional)" onChange={e => setName(e.target.value)} />
        </div>
        <div className="v2-inline-form">
          {active.fields.map(f => (
            <input key={f} value={fields[f] ?? ""} placeholder={f} type={f === "apiSecret" || f === "token" ? "password" : "text"}
              onChange={e => setFields(s => ({ ...s, [f]: e.target.value }))} />
          ))}
          <Button size="sm" onClick={() => save.mutate({ kind, name: name || active.label, config: fields })} disabled={save.isPending}>
            {save.isPending ? "Saving…" : "Save connection"}
          </Button>
        </div>
        <p className="v2-hint">{active.hint}. Secrets are stored server-side and never returned to the browser.</p>
      </Section>

      <Section title="Connections" kicker="STATUS" icon={<Link2 size={14} />}>
        {list.isLoading ? <Loading /> : (list.data ?? []).length === 0 ? (
          <Empty icon={<Link2 size={20} />} title="No connections yet" hint="Add GA4, GTM, a CRM endpoint or a webhook above." />
        ) : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Name</span><span>Type</span><span>Status</span><span>Last sync</span><span>Actions</span></div>
            {((list.data ?? []) as V2Integration[]).map(i => (
              <div className="v2-tr" key={i.id}>
                <span>{i.name}</span>
                <span className="v2-muted">{i.kind.toUpperCase()}</span>
                <span><Badge variant="outline" className={`v2-status v2-status-${i.status === "connected" ? "active" : i.status === "error" ? "paused" : "draft"}`}>{i.status}</Badge></span>
                <span className="v2-muted">{i.lastSyncAt ? new Date(i.lastSyncAt).toLocaleString() : "—"}</span>
                <span className="v2-row-actions">
                  <button title="Test connection" onClick={() => test.mutate({ id: i.id })} disabled={test.isPending}><Zap size={13} /></button>
                  <button title="Remove" onClick={() => remove.mutate({ id: i.id })}><Trash2 size={13} /></button>
                </span>
              </div>
            ))}
          </div>
        )}
        {((list.data ?? []) as V2Integration[]).some(i => i.lastError) && (
          <div className="v2-error v2-error-soft">
            <AlertTriangle size={15} />
            <div><strong>Last error</strong><p>{((list.data ?? []) as V2Integration[]).find(i => i.lastError)?.lastError}</p></div>
          </div>
        )}
      </Section>

      <Section title="Webhook deliveries" kicker="LOG" icon={<Activity size={14} />}>
        {deliveries.isLoading ? <Loading /> : (deliveries.data ?? []).length === 0 ? (
          <Empty icon={<Webhook size={20} />} title="No deliveries yet" hint="Webhooks fire on experiment started, conversion recorded and winner selected." />
        ) : (
          <div className="v2-table v2-table-tight">
            <div className="v2-tr v2-th"><span>Event</span><span>Status</span><span>Code</span><span>Attempts</span><span>When</span></div>
            {((deliveries.data ?? []) as any[]).map(d => (
              <div className="v2-tr" key={d.id}>
                <span className="v2-mono">{d.event}</span>
                <span><Badge variant="outline">{d.status}</Badge></span>
                <span>{d.responseCode ?? "—"}</span><span>{d.attempts}</span><span className="v2-muted">{d.ago}</span>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ AI

export function V2AiPage({ notify }: { notify: (m: string) => void }) {
  const recs = trpc.v2.ai.recommendations.useQuery();
  const copy = trpc.v2.ai.copy.useQuery();
  const [context, setContext] = useState("");
  const [copyKind, setCopyKind] = useState("headline");
  const utils = trpc.useUtils();

  const gen = trpc.v2.ai.generateRecommendations.useMutation({
    onSuccess: (r: any) => { utils.v2.ai.recommendations.invalidate(); notify(`${r.created} recommendations generated (${r.source === "ai" ? "AI" : "heuristic fallback"}).`); },
    onError: (e: any) => notify(e.message),
  });
  const setStatus = trpc.v2.ai.setRecommendationStatus.useMutation({
    onSuccess: () => { utils.v2.ai.recommendations.invalidate(); notify("Recommendation updated."); },
  });
  const genCopy = trpc.v2.ai.generateCopy.useMutation({
    onSuccess: (r: any) => { utils.v2.ai.copy.invalidate(); notify(`3 copy options generated (${r.source === "ai" ? "AI" : "heuristic fallback"}).`); },
    onError: (e: any) => notify(e.message),
  });
  const setCopyStatus = trpc.v2.ai.setCopyStatus.useMutation({
    onSuccess: () => { utils.v2.ai.copy.invalidate(); notify("Copy status updated."); },
  });

  const impactTone = (i: string) => i === "high" ? "#e5643c" : i === "medium" ? "#f2aa4f" : "#8d96a8";

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Intelligence</div>
          <h1>AI recommendations</h1>
          <p>Prioritised CRO actions with impact estimates and an approval workflow.</p>
        </div>
        <Button onClick={() => gen.mutate({})} disabled={gen.isPending}>
          <Sparkles size={15} />{gen.isPending ? "Generating…" : "Generate recommendations"}
        </Button>
      </section>

      <Section title="Recommendations" kicker="PRIORITISED" icon={<Lightbulb size={14} />}>
        {recs.isLoading ? <Loading /> : (recs.data ?? []).length === 0 ? (
          <Empty icon={<Lightbulb size={20} />} title="No recommendations yet" hint="Click Generate to analyse your crawl and experiment data." />
        ) : (
          <div className="v2-recs">
            {((recs.data ?? []) as V2Recommendation[]).map(r => (
              <div className={`v2-rec v2-rec-${r.status}`} key={r.id}>
                <div className="v2-rec-head">
                  <span className="v2-rec-priority" style={{ background: impactTone(r.impact) }}>{r.priority}</span>
                  <div>
                    <strong>{r.title}</strong>
                    <p>{r.why}</p>
                  </div>
                </div>
                <div className="v2-rec-meta">
                  <Badge variant="outline">Impact: {r.impact}</Badge>
                  <Badge variant="outline">Effort: {r.effort}</Badge>
                  <Badge variant="outline">{r.source === "ai" ? "AI" : "Heuristic"}</Badge>
                  <Badge variant="outline">{r.status}</Badge>
                </div>
                <div className="v2-rec-actions">
                  {r.status !== "approved" && <Button size="sm" variant="outline" onClick={() => setStatus.mutate({ id: r.id, status: "approved" })}><Check size={13} />Approve</Button>}
                  {r.status !== "rejected" && <Button size="sm" variant="outline" onClick={() => setStatus.mutate({ id: r.id, status: "rejected" })}><X size={13} />Reject</Button>}
                  {r.status === "approved" && <Button size="sm" variant="outline" onClick={() => setStatus.mutate({ id: r.id, status: "done" })}><CheckCircle2 size={13} />Mark done</Button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>

      <Section title="Variant copy generator" kicker="COPY" icon={<Sparkles size={14} />}>
        <div className="v2-inline-form">
          <select value={copyKind} onChange={e => setCopyKind(e.target.value)}>
            <option value="headline">Headline</option>
            <option value="cta">CTA</option>
            <option value="form">Form</option>
            <option value="product description">Product description</option>
          </select>
          <input value={context} placeholder="What is the page about?" onChange={e => setContext(e.target.value)} />
          <Button size="sm" onClick={() => genCopy.mutate({ kind: copyKind, context })} disabled={genCopy.isPending}>
            {genCopy.isPending ? "Writing…" : "Generate 3 options"}
          </Button>
        </div>
        {copy.isLoading ? <Loading /> : (copy.data ?? []).length === 0 ? (
          <Empty icon={<Sparkles size={20} />} title="No copy yet" hint="Generate options, then approve the ones you want to publish." />
        ) : (
          <div className="v2-copy-list">
            {((copy.data ?? []) as any[]).map(c => (
              <div className="v2-copy" key={c.id}>
                <div><Badge variant="outline">{c.kind}</Badge><p>{c.content}</p></div>
                <div className="v2-copy-actions">
                  <Badge variant="outline">{c.status}</Badge>
                  {c.status === "draft" && <button className="v2-icon-btn" title="Approve" onClick={() => setCopyStatus.mutate({ id: c.id, status: "approved" })}><Check size={13} /></button>}
                  {c.status === "approved" && <button className="v2-icon-btn" title="Publish" onClick={() => setCopyStatus.mutate({ id: c.id, status: "published" })}><Rocket size={13} /></button>}
                </div>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ CRAWLER

export function V2CrawlerPage({ notify }: { notify: (m: string) => void }) {
  const [url, setUrl] = useState("");
  const [openId, setOpenId] = useState<number | null>(null);
  const list = trpc.v2.crawl.list.useQuery();
  const utils = trpc.useUtils();

  const start = trpc.v2.crawl.start.useMutation({
    onSuccess: (r: any) => {
      utils.v2.crawl.list.invalidate();
      if (r.ok) { notify(`Crawl finished — score ${r.score}, ${r.issues} issues.`); setOpenId(r.id); }
      else notify(r.error ?? "Crawl failed.");
    },
    onError: (e: any) => notify(e.message),
  });

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Audits</div>
          <h1>Website crawler</h1>
          <p>Page-by-page audits with severity, mobile, accessibility, SEO and conversion checks.</p>
        </div>
      </section>

      <Section title="Run an audit" kicker="NEW CRAWL" icon={<Search size={14} />}>
        <div className="v2-inline-form">
          <input value={url} placeholder="https://your-site.com" onChange={e => setUrl(e.target.value)} />
          <Button onClick={() => url.trim() && start.mutate({ siteUrl: url.trim() })} disabled={start.isPending}>
            {start.isPending ? <><Loader2 size={14} className="v2-spin" />Crawling…</> : <><Search size={14} />Crawl now</>}
          </Button>
        </div>
        <p className="v2-hint">The crawler fetches the live page and runs real checks against the HTML — no simulated results.</p>
      </Section>

      {openId !== null && <V2CrawlDetail id={openId} onClose={() => setOpenId(null)} />}

      <Section title="Crawl history" kicker="HISTORY" icon={<Clock3 size={14} />}>
        {list.isLoading ? <Loading /> : (list.data ?? []).length === 0 ? (
          <Empty icon={<Search size={20} />} title="No crawls yet" hint="Run your first audit above." />
        ) : (
          <div className="v2-table">
            <div className="v2-tr v2-th"><span>Site</span><span>Status</span><span>Score</span><span>Pages</span><span>When</span><span /></div>
            {((list.data ?? []) as V2Crawl[]).map(c => (
              <div className="v2-tr" key={c.id}>
                <span className="v2-mono v2-trunc">{c.siteUrl}</span>
                <span><Badge variant="outline">{c.status}</Badge></span>
                <span className={c.score !== null && c.score < 60 ? "v2-neg" : "v2-pos"}>{c.score ?? "—"}</span>
                <span>{c.pagesCrawled}</span>
                <span className="v2-muted">{c.ago}</span>
                <span><button className="v2-icon-btn" onClick={() => setOpenId(c.id)}><Eye size={13} /></button></span>
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

function V2CrawlDetail({ id, onClose }: { id: number; onClose: () => void }) {
  const q = trpc.v2.crawl.detail.useQuery({ id });
  const cmp = trpc.v2.crawl.compare.useQuery({ id });
  const d = q.data as V2CrawlDetail | undefined;
  const c = cmp.data as any;

  const sevTone: Record<string, string> = { critical: "#c0392b", high: "#e5643c", medium: "#f2aa4f", low: "#8d96a8" };

  if (q.isLoading) return <Loading label="Loading audit…" />;
  if (!d) return null;

  return (
    <Section title={`Audit — ${d.siteUrl}`} kicker="RESULTS" icon={<FileText size={14} />}
      action={<Button variant="outline" size="sm" onClick={onClose}><X size={13} />Close</Button>}>
      <div className="v2-sev-row">
        {(["critical", "high", "medium", "low"] as const).map(s => (
          <div className="v2-sev" key={s} style={{ borderColor: sevTone[s] }}>
            <strong style={{ color: sevTone[s] }}>{d.counts[s]}</strong>
            <span>{s}</span>
          </div>
        ))}
        <div className="v2-sev v2-sev-score"><strong>{d.score ?? "—"}</strong><span>score</span></div>
      </div>

      {c?.hasPrevious && (
        <div className="v2-compare">
          <strong>Compared to previous crawl</strong>
          <p>Score {c.scoreBefore} → {c.scoreAfter}</p>
          {c.fixed?.length > 0 && <p className="v2-pos">Fixed: {c.fixed.join(", ")}</p>}
          {c.new?.length > 0 && <p className="v2-neg">New: {c.new.join(", ")}</p>}
        </div>
      )}

      {d.issues.length === 0 ? (
        <Empty icon={<CheckCircle2 size={20} />} title="No issues found" hint="This page passed every check." />
      ) : (
        <ul className="v2-issues">
          {d.issues.map(i => (
            <li key={i.id} className="v2-issue">
              <span className="v2-issue-sev" style={{ background: sevTone[i.severity] }}>{i.severity}</span>
              <div><strong>{i.title}</strong><p>{i.detail}</p><span className="v2-issue-cat">{i.category}</span></div>
            </li>
          ))}
        </ul>
      )}
    </Section>
  );
}

// ============================================================ VISUAL

export function V2VisualPage({ notify }: { notify: (m: string) => void }) {
  const [url, setUrl] = useState("");
  const [viewport, setViewport] = useState("desktop");
  const list = trpc.v2.visual.list.useQuery();
  const utils = trpc.useUtils();

  const capture = trpc.v2.visual.capture.useMutation({
    onSuccess: (r: any) => { utils.v2.visual.list.invalidate(); notify(`Analysis complete — ${r.findings.length} findings.`); },
    onError: (e: any) => notify(e.message),
  });

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Visual</div>
          <h1>Rendering and visual analysis</h1>
          <p>Desktop and mobile layout checks, with annotations and assigned fixes.</p>
        </div>
      </section>

      <Section title="Analyse a page" kicker="CAPTURE" icon={<Eye size={14} />}>
        <div className="v2-inline-form">
          <input value={url} placeholder="https://your-site.com" onChange={e => setUrl(e.target.value)} />
          <select value={viewport} onChange={e => setViewport(e.target.value)}>
            <option value="desktop">Desktop</option>
            <option value="mobile">Mobile</option>
          </select>
          <Button onClick={() => url.trim() && capture.mutate({ pageUrl: url.trim(), viewport })} disabled={capture.isPending}>
            {capture.isPending ? <><Loader2 size={14} className="v2-spin" />Analysing…</> : <><Eye size={14} />Analyse</>}
          </Button>
        </div>
        <p className="v2-hint">Layout findings are derived from the live HTML. Pixel screenshots require a rendering service, which this host does not provide — the findings are real, the image is not.</p>
      </Section>

      <Section title="Analyses" kicker="HISTORY" icon={<Layers3 size={14} />}>
        {list.isLoading ? <Loading /> : (list.data ?? []).length === 0 ? (
          <Empty icon={<Eye size={20} />} title="No analyses yet" hint="Analyse a page above." />
        ) : (
          <div className="v2-visual-list">
            {((list.data ?? []) as any[]).map(v => (
              <div className="v2-visual" key={v.id}>
                <div className="v2-visual-head">
                  <span className="v2-mono v2-trunc">{v.pageUrl}</span>
                  <Badge variant="outline">{v.viewport}</Badge>
                </div>
                {v.findings.length === 0 ? <p className="v2-muted">No layout problems detected.</p> : (
                  <ul className="v2-findings">
                    {v.findings.map((f: any, i: number) => (
                      <li key={i}><span className="v2-issue-sev" style={{ background: f.severity === "critical" ? "#c0392b" : f.severity === "high" ? "#e5643c" : "#f2aa4f" }}>{f.severity}</span><div><strong>{f.title}</strong><p>{f.detail}</p></div></li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ COMPETITORS

export function V2CompetitorsPage({ notify }: { notify: (m: string) => void }) {
  const [name, setName] = useState("");
  const [url, setUrl] = useState("");
  const list = trpc.v2.competitors.list.useQuery();
  const utils = trpc.useUtils();

  const add = trpc.v2.competitors.add.useMutation({
    onSuccess: () => { setName(""); setUrl(""); utils.v2.competitors.list.invalidate(); notify("Competitor saved."); },
    onError: (e: any) => notify(e.message),
  });
  const scan = trpc.v2.competitors.scan.useMutation({
    onSuccess: (r: any) => {
      utils.v2.competitors.list.invalidate();
      if (r.ok) notify(r.isFirst ? "First scan saved as baseline." : `Scan complete — ${r.changes.length} changes since last scan.`);
      else notify(r.error ?? "Scan failed.");
    },
    onError: (e: any) => notify(e.message),
  });
  const remove = trpc.v2.competitors.remove.useMutation({
    onSuccess: () => { utils.v2.competitors.list.invalidate(); notify("Competitor removed."); },
  });

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Market</div>
          <h1>Competitor comparison</h1>
          <p>Messaging, offers, CTAs, trust signals and pricing — with changes since the last scan.</p>
        </div>
      </section>

      <Section title="Add a competitor" kicker="NEW" icon={<Plus size={14} />}>
        <div className="v2-inline-form">
          <input value={name} placeholder="Name" onChange={e => setName(e.target.value)} />
          <input value={url} placeholder="https://competitor.com" onChange={e => setUrl(e.target.value)} />
          <Button size="sm" onClick={() => name.trim() && url.trim() && add.mutate({ name, url })} disabled={add.isPending}>
            <Plus size={14} />Add
          </Button>
        </div>
      </Section>

      <Section title="Saved competitors" kicker="SCANS" icon={<Globe size={14} />}>
        {list.isLoading ? <Loading /> : (list.data ?? []).length === 0 ? (
          <Empty icon={<Globe size={20} />} title="No competitors saved" hint="Add one above, then run a scan." />
        ) : (
          <div className="v2-comp-list">
            {((list.data ?? []) as V2Competitor[]).map(c => (
              <div className="v2-comp" key={c.id}>
                <div className="v2-comp-head">
                  <div><strong>{c.name}</strong><p className="v2-mono v2-trunc">{c.url}</p></div>
                  <div className="v2-row-actions">
                    <button title="Scan now" onClick={() => scan.mutate({ id: c.id })} disabled={scan.isPending}><RefreshCw size={13} /></button>
                    <button title="Remove" onClick={() => remove.mutate({ id: c.id })}><Trash2 size={13} /></button>
                  </div>
                </div>
                {c.lastScan ? (
                  c.lastScan.changes.length === 0 ? <p className="v2-muted">No changes since the previous scan.</p> : (
                    <ul className="v2-changes">
                      {c.lastScan.changes.map((ch: any, i: number) => (
                        <li key={i}>
                          <strong>{ch.field}</strong>
                          {ch.from !== undefined ? <span>{ch.from} → {ch.to}</span> : (
                            <span>
                              {ch.added?.length > 0 && <em className="v2-pos">+{ch.added.join(", ")}</em>}
                              {ch.removed?.length > 0 && <em className="v2-neg">−{ch.removed.join(", ")}</em>}
                            </span>
                          )}
                        </li>
                      ))}
                    </ul>
                  )
                ) : <p className="v2-muted">Not scanned yet.</p>}
              </div>
            ))}
          </div>
        )}
      </Section>
    </div>
  );
}

// ============================================================ ECOMMERCE

export function V2EcommercePage({ notify }: { notify: (m: string) => void }) {
  const [range, setRange] = useState("30d");
  const q = trpc.v2.ecommerce.metrics.useQuery({ range });
  const d = q.data as V2Ecommerce | undefined;

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Commerce</div>
          <h1>Ecommerce</h1>
          <p>Revenue per visitor, AOV, cart abandonment and checkout completion.</p>
        </div>
        <div className="v2-range">
          {["7d", "30d", "90d", "all"].map(r => <button key={r} className={range === r ? "active" : ""} onClick={() => setRange(r)}>{r === "all" ? "All" : r}</button>)}
        </div>
      </section>

      {q.isLoading ? <Loading /> : !d ? null : (
        <>
          <section className="v2-stat-grid">
            <Stat label="Revenue" value={`$${fmt(d.totals.revenue, 2)}`} />
            <Stat label="Revenue / visitor" value={`$${fmt(d.totals.revenuePerVisitor, 2)}`} />
            <Stat label="Avg order value" value={`$${fmt(d.totals.averageOrderValue, 2)}`} sub={`${fmt(d.totals.orders)} orders`} />
            <Stat label="Cart abandonment" value={`${fmt(d.totals.cartAbandonment, 1)}%`} accent={d.totals.cartAbandonment > 70 ? "#e5643c" : undefined} />
            <Stat label="Checkout completion" value={`${fmt(d.totals.checkoutCompletion, 1)}%`} />
            <Stat label="Product conversion" value={`${fmt(d.totals.productConversionRate, 2)}%`} />
          </section>

          <Section title="Revenue trend" kicker="DAILY" icon={<TrendingUp size={14} />}>
            {d.series.length === 0 ? (
              <Empty icon={<BarChart3 size={20} />} title="No commerce data" hint="Record daily metrics to populate this view." />
            ) : (
              <ResponsiveContainer width="100%" height={260}>
                <LineChart data={d.series}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#eceae6" />
                  <XAxis dataKey="day" tick={{ fontSize: 11 }} />
                  <YAxis tick={{ fontSize: 11 }} />
                  <Tooltip />
                  <Legend />
                  <Line type="monotone" dataKey="revenue" name="Revenue" stroke="#6eaf75" strokeWidth={2} />
                  <Line type="monotone" dataKey="orders" name="Orders" stroke="#5676e8" strokeWidth={2} />
                </LineChart>
              </ResponsiveContainer>
            )}
          </Section>
        </>
      )}
    </div>
  );
}

// ============================================================ SYSTEM

export function V2SystemPage({ notify }: { notify: (m: string) => void }) {
  const health = trpc.v2.health.system.useQuery(undefined, { refetchInterval: 30000 });
  const version = trpc.v2.system.version.useQuery();
  const backups = trpc.v2.backup.list.useQuery();
  const audit = trpc.v2.audit.list.useQuery();
  const utils = trpc.useUtils();

  const runJobs = trpc.v2.jobs.run.useMutation({
    onSuccess: (r: any) => { utils.v2.health.system.invalidate(); notify(`Jobs ran: ${r.ran.join("; ")}`); },
    onError: (e: any) => notify(e.message),
  });
  const makeBackup = trpc.v2.backup.create.useMutation({
    onSuccess: (r: any) => {
      utils.v2.backup.list.invalidate();
      if (r.ok) notify(`Backup created: ${r.filename} (${Math.round(r.sizeBytes / 1024)} KB)`);
      else notify(r.error ?? "Backup failed.");
    },
    onError: (e: any) => notify(e.message),
  });

  const h = health.data as V2Health | undefined;
  const v = version.data as V2Version | undefined;

  return (
    <div className="v2-page">
      <section className="page-heading">
        <div>
          <div className="overline"><span className="live-pulse" />Operations</div>
          <h1>System health</h1>
          <p>Database, tracker, AI service, storage and scheduled jobs.</p>
        </div>
        <Button variant="outline" onClick={() => health.refetch()}><RefreshCw size={15} />Re-check</Button>
      </section>

      {health.isLoading ? <Loading /> : !h ? null : (
        <>
          <section className="v2-stat-grid">
            <Stat label="Overall" value={h.overall} accent={h.overall === "healthy" ? "#6eaf75" : h.overall === "degraded" ? "#f2aa4f" : "#e5643c"} />
            <Stat label="Health score" value={`${h.score}%`} />
            <Stat label="App version" value={v?.appVersion ?? "—"} sub={v?.upToDate ? "Schema up to date" : "Schema needs update"} />
            <Stat label="Tables" value={fmt(v?.tables)} sub={v?.missingTables?.length ? `${v.missingTables.length} missing` : "All present"} />
          </section>

          <Section title="Components" kicker="CHECKS" icon={<Gauge size={14} />}>
            <ul className="v2-checks">
              {h.checks.map((c, i) => (
                <li key={i} className={`v2-check v2-check-${c.status}`}>
                  {c.status === "ok" ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
                  <div><strong>{c.component}</strong><p>{c.detail}{c.latencyMs !== null ? ` · ${c.latencyMs}ms` : ""}</p></div>
                </li>
              ))}
            </ul>
          </Section>

          <div className="v2-two-col">
            <Section title="Scheduled jobs" kicker="BACKGROUND" icon={<Clock3 size={14} />}
              action={<Button size="sm" variant="outline" onClick={() => runJobs.mutate({ job: "all" })} disabled={runJobs.isPending}>
                {runJobs.isPending ? "Running…" : "Run now"}
              </Button>}>
              {h.jobs.length === 0 ? <Empty icon={<Clock3 size={20} />} title="No jobs have run" hint="Click Run now to activate scheduled experiments and retry webhooks." /> : (
                <div className="v2-table v2-table-tight">
                  <div className="v2-tr v2-th"><span>Job</span><span>Status</span><span>Runs</span><span>Last run</span></div>
                  {h.jobs.map(j => (
                    <div className="v2-tr" key={j.key}>
                      <span className="v2-mono">{j.key}</span>
                      <span><Badge variant="outline">{j.status ?? "—"}</Badge></span>
                      <span>{j.runs}</span>
                      <span className="v2-muted">{j.lastRun ? new Date(j.lastRun).toLocaleString() : "—"}</span>
                    </div>
                  ))}
                </div>
              )}
              <p className="v2-hint">Free hosting has no reliable cron. Use Run now, or point an external scheduler at this page's job endpoint.</p>
            </Section>

            <Section title="Backups" kicker="DATA" icon={<Download size={14} />}
              action={<Button size="sm" variant="outline" onClick={() => makeBackup.mutate({})} disabled={makeBackup.isPending}>
                {makeBackup.isPending ? "Creating…" : "Create backup"}
              </Button>}>
              {backups.isLoading ? <Loading /> : (backups.data ?? []).length === 0 ? (
                <Empty icon={<Download size={20} />} title="No backups yet" hint="Create one before making big changes." />
              ) : (
                <div className="v2-table v2-table-tight">
                  <div className="v2-tr v2-th"><span>File</span><span>Size</span><span>When</span></div>
                  {((backups.data ?? []) as any[]).map((b, i) => (
                    <div className="v2-tr" key={i}><span className="v2-mono v2-trunc">{b.filename}</span><span>{Math.round(b.sizeBytes / 1024)} KB</span><span className="v2-muted">{b.ago}</span></div>
                  ))}
                </div>
              )}
            </Section>
          </div>

          <Section title="Audit log" kicker="SECURITY" icon={<ShieldCheck size={14} />}>
            {audit.isLoading ? <Loading /> : (audit.data ?? []).length === 0 ? (
              <Empty icon={<ShieldCheck size={20} />} title="No audit entries" hint="Security-relevant actions are recorded here." />
            ) : (
              <div className="v2-table v2-table-tight">
                <div className="v2-tr v2-th"><span>Action</span><span>Target</span><span>IP</span><span>When</span></div>
                {((audit.data ?? []) as any[]).map((a, i) => (
                  <div className="v2-tr" key={i}><span className="v2-mono">{a.action}</span><span className="v2-trunc">{a.target ?? "—"}</span><span className="v2-mono">{a.ip}</span><span className="v2-muted">{a.ago}</span></div>
                ))}
              </div>
            )}
          </Section>
        </>
      )}
    </div>
  );
}

// ============================================================ ONBOARDING

export function V2OnboardingBanner() {
  const q = trpc.v2.onboarding.get.useQuery();
  const [dismissed, setDismissed] = useState(false);
  const d = q.data as V2Onboarding | undefined;

  if (dismissed || !d || d.percent === 100) return null;

  return (
    <div className="v2-onboard">
      <div className="v2-onboard-head">
        <div>
          <strong>Get set up — {d.completed} of {d.total} done</strong>
          <p>Finish these steps to unlock reliable results.</p>
        </div>
        <button className="v2-icon-btn" onClick={() => setDismissed(true)}><X size={14} /></button>
      </div>
      <div className="v2-onboard-bar"><i style={{ width: `${d.percent}%` }} /></div>
      <ul className="v2-onboard-steps">
        {d.steps.map(s => (
          <li key={s.key} className={s.done ? "done" : ""}>
            {s.done ? <CheckCircle2 size={14} /> : <span className="v2-onboard-dot" />}
            {s.label}
          </li>
        ))}
      </ul>
    </div>
  );
}
