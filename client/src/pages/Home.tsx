import { useEffect, useMemo, useState } from "react";
import { trpc, type WorkspaceSettings, type TrackingHealth, type SegmentRow } from "@/lib/trpc";
import { useAuth } from "@/_core/hooks/useAuth";
import {
  Activity,
  ArrowDownRight,
  ArrowUpRight,
  BarChart3,
  Beaker,
  Bell,
  Bookmark,
  Check,
  ChevronDown,
  CircleHelp,
  Clock3,
  Code2,
  FlaskConical,
  Gauge,
  GitBranch,
  Layers3,
  LogOut,
  Lightbulb,
  MoreHorizontal,
  Network,
  Pause,
  Play,
  Plus,
  Search,
  Settings2,
  ShieldCheck,
  Sparkles,
  Target,
  TrendingUp,
  Users,
  X,
  Zap,
  Eye,
  Globe,
  Link2,
} from "lucide-react";
import {
  Area,
  AreaChart,
  CartesianGrid,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import Orchestration from "@/pages/Orchestration";
import {
  V2DashboardPage, V2ExperimentsPage, V2TrackingPage, V2IntegrationsPage,
  V2AiPage, V2CrawlerPage, V2VisualPage, V2CompetitorsPage, V2EcommercePage,
  V2SystemPage, V2OnboardingBanner,
} from "@/pages/V2Pages";

type ExperimentStatus = "Active" | "Draft" | "Paused" | "Completed";
type ExperimentKind = "A/B test" | "Multivariate";
type Plan = "Free" | "Growth" | "Scale";

type Variant = {
  name: string;
  visitors: number;
  conversions: number;
  rate: number;
  uplift: number;
  probability: number;
  color: string;
  isControl?: boolean;
};

type Experiment = {
  id: number;
  name: string;
  kind: ExperimentKind;
  status: ExperimentStatus;
  owner: string;
  updated: string;
  goal: string;
  framework: string;
  pageUrl?: string;
  objective?: string;
  hypothesis?: string;
  observedInsight?: string;
  experienceChange?: string;
  behaviorReason?: string;
  audience?: string;
  conversionEvent?: string;
  traffic: number;
  variants: Variant[];
};

const planLimits: Record<Plan, { experiments: number; variants: number; copy: string }> = {
  Free: { experiments: 3, variants: 2, copy: "Core A/B testing" },
  Growth: { experiments: 10, variants: 5, copy: "Multi-variant insights" },
  Scale: { experiments: 50, variants: 8, copy: "Advanced experimentation" },
};

const statusStyles: Record<ExperimentStatus, string> = {
  Active: "status-active",
  Draft: "status-draft",
  Paused: "status-paused",
  Completed: "status-completed",
};

function formatNumber(value: number) {
  return new Intl.NumberFormat("en-US").format(value);
}

function initials(name: string) {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (!parts.length) return "U";
  return parts.slice(0, 2).map(part => part[0].toUpperCase()).join("");
}

function useIdentity() {
  const meQuery = trpc.auth.me.useQuery();
  const settingsQuery = trpc.settings.get.useQuery();
  const user = meQuery.data ?? null;
  const name = user?.name || user?.email || "You";
  const settings = settingsQuery.data;
  const workspaceName = settings?.workspaceName || "My workspace";
  return { user, name, personInitials: initials(name), workspaceName, workspaceInitials: initials(workspaceName), settings, settingsQuery };
}

function experimentHealth(experiment: Experiment) {
  const visitors = experiment.variants.reduce((sum, variant) => sum + variant.visitors, 0);
  if (experiment.status === "Draft") return { label: "Not launched", sub: "draft", ok: false };
  if (experiment.status === "Paused") return { label: "Paused", sub: experiment.updated, ok: false };
  if (experiment.status === "Completed") return { label: "Completed", sub: experiment.updated, ok: true };
  if (visitors === 0) return { label: "Awaiting data", sub: "no visitors yet", ok: false };
  const counts = experiment.variants.map(variant => variant.visitors);
  const max = Math.max(...counts);
  const min = Math.min(...counts);
  const balanced = max / Math.max(min, 1) <= 2.5;
  return { label: balanced ? "Healthy" : "Check split", sub: `updated ${experiment.updated}`, ok: balanced };
}

type BayesianPrediction = {
  posteriorRate: number;
  lower: number;
  upper: number;
  probabilityToWin: number;
  maturity: number;
  recommendation: string;
};

function normalCdf(value: number) {
  const sign = value < 0 ? -1 : 1;
  const x = Math.abs(value) / Math.sqrt(2);
  const t = 1 / (1 + 0.3275911 * x);
  const polynomial = (((((1.061405429 * t - 1.453152027) * t) + 1.421413741) * t - 0.284496736) * t + 0.254829592) * t;
  return 0.5 * (1 + sign * (1 - polynomial * Math.exp(-x * x)));
}

function predictVariant(variant: Variant, control: Variant): BayesianPrediction {
  const posterior = (item: Variant) => {
    const alpha = item.conversions + 1;
    const beta = Math.max(item.visitors - item.conversions, 0) + 1;
    const total = alpha + beta;
    const mean = alpha / total;
    const variance = (alpha * beta) / (total * total * (total + 1));
    return { mean, variance, total };
  };
  const current = posterior(variant);
  const baseline = posterior(control);
  const standardError = Math.sqrt(current.variance + baseline.variance);
  const z = standardError > 0 ? (current.mean - baseline.mean) / standardError : 0;
  const probabilityToWin = variant.isControl ? 50 : Math.max(1, Math.min(99, normalCdf(z) * 100));
  const interval = 1.96 * Math.sqrt(current.variance);
  const maturity = Math.max(0, Math.min(100, Math.round((variant.visitors / 10000) * 100)));
  const recommendation = maturity < 35 ? "Collect more data" : probabilityToWin >= 90 ? "Likely winner" : probabilityToWin <= 10 ? "Control is leading" : "Inconclusive";
  return { posteriorRate: current.mean * 100, lower: Math.max(0, (current.mean - interval) * 100), upper: Math.min(100, (current.mean + interval) * 100), probabilityToWin: Math.round(probabilityToWin), maturity, recommendation };
}

function BayesianReadout({ experiment, notify }: { experiment: Experiment; notify: (message: string) => void }) {
  const control = experiment.variants.find(variant => variant.isControl) ?? experiment.variants[0];
  const predictions = experiment.variants.map(variant => ({ variant, prediction: predictVariant(variant, control) }));
  const winner = predictions.reduce((best, current) => current.prediction.probabilityToWin > best.prediction.probabilityToWin ? current : best, predictions[0]);
  const uplift = control.rate > 0 ? ((winner.prediction.posteriorRate - predictVariant(control, control).posteriorRate) / predictVariant(control, control).posteriorRate) * 100 : 0;
  const overallMaturity = Math.round(predictions.reduce((sum, item) => sum + item.prediction.maturity, 0) / predictions.length);

  return <Card className="readout-card"><CardHeader><div className="card-kicker"><Target size={14} />BAYESIAN READOUT</div><CardTitle>{experiment.name}</CardTitle><p className="card-subtitle">{experiment.status} · calculated from current visitor and conversion counts</p><div className="data-confidence-label"><span><Activity size={12} />Observed data</span><span>{formatNumber(experiment.variants.reduce((sum, variant) => sum + variant.visitors, 0))} visitors · {formatNumber(experiment.variants.reduce((sum, variant) => sum + variant.conversions, 0))} conversions</span><b>Decision support, not statistical proof</b></div></CardHeader><CardContent><div className="readout-callout"><div className="callout-icon"><Lightbulb size={18} /></div><div><strong>{winner.variant.name} is {winner.prediction.recommendation.toLowerCase()}</strong><p>Posterior conversion estimate <b>{winner.prediction.posteriorRate.toFixed(2)}%</b> with {winner.prediction.probabilityToWin}% probability of beating control.</p></div></div><div className="bayesian-score-grid"><div><span>Probability to win</span><strong>{winner.prediction.probabilityToWin}%</strong></div><div><span>Predicted uplift</span><strong className={uplift >= 0 ? "positive-value" : "negative-value"}>{uplift >= 0 ? "+" : ""}{uplift.toFixed(1)}%</strong></div><div><span>Data maturity</span><strong>{overallMaturity}%</strong></div></div><div className="variant-list">{predictions.map(({ variant, prediction }) => <div className="variant-row" key={variant.name}><div className="variant-label"><i style={{ background: variant.color }} /><span>{variant.name}</span>{variant.isControl ? <Badge variant="outline">control</Badge> : null}</div><div className="variant-metrics"><strong>{prediction.posteriorRate.toFixed(2)}%</strong><span>{prediction.lower.toFixed(2)}–{prediction.upper.toFixed(2)}% credible interval</span></div><div className="variant-probability"><ProgressBar value={prediction.probabilityToWin} color={variant.color} /><span>{prediction.probabilityToWin}%<small>{prediction.recommendation}</small></span></div></div>)}</div><button className="open-results" onClick={() => notify("Bayesian prediction details are ready for this experiment.")}>Open prediction details <ArrowUpRight size={14} /></button></CardContent></Card>;
}

function StatCard({ label, value, change, changeLabel, icon, accent }: { label: string; value: string; change?: string; changeLabel?: string; icon: React.ReactNode; accent: string }) {
  return (
    <Card className="stat-card">
      <CardContent className="p-0">
        <div className="flex items-start justify-between gap-3">
          <div>
            <p className="eyebrow">{label}</p>
            <p className="stat-value">{value}</p>
            {change && (
              <p className={`stat-change ${change.startsWith("-") ? "negative" : ""}`}>
                {change.startsWith("-") ? <ArrowDownRight size={14} /> : <ArrowUpRight size={14} />}
                <span>{change}</span>
                <span className="muted-copy">{changeLabel}</span>
              </p>
            )}
          </div>
          <span className={`stat-icon ${accent}`}>{icon}</span>
        </div>
      </CardContent>
    </Card>
  );
}

function StatusPill({ status }: { status: ExperimentStatus }) {
  return <span className={`status-pill ${statusStyles[status]}`}><span className="status-dot" />{status}</span>;
}

function ProgressBar({ value, color = "#e5643c" }: { value: number; color?: string }) {
  return <div className="progress-track"><span style={{ width: `${Math.min(value, 100)}%`, background: color }} /></div>;
}

function TrackingSetup({ notify }: { notify: (message: string) => void }) {
  const [copied, setCopied] = useState(false);
  const [testing, setTesting] = useState(false);
  const identity = useIdentity();
  const utils = trpc.useUtils();
  const me = trpc.auth.me.useQuery();
  const healthQuery = trpc.tracking.health.useQuery(undefined, { refetchInterval: 10000 });
  const experimentsQuery = trpc.experiments.list.useQuery();
  const health = healthQuery.data;
  const events = health?.events ?? [];
  const activeExperiment = ((experimentsQuery.data ?? []) as Experiment[]).find(item => item.status === "Active");
  const waitingCount = events.filter(event => event.status !== "Receiving").length;
  const snippet = `<script async src="${new URL("tracker.js?i=1", window.location.href).href}" data-project="${me.data?.projectKey ?? ""}"></script>`;

  const copySnippet = async () => {
    try { await navigator.clipboard.writeText(snippet); } catch { /* clipboard may be unavailable in Preview */ }
    setCopied(true);
    notify("Tracking snippet copied to clipboard.");
    window.setTimeout(() => setCopied(false), 2200);
  };

  const testEvent = async () => {
    const project = me.data?.projectKey;
    if (!project) { notify("Sign in again to send a test event."); return; }
    if (!activeExperiment) { notify("Activate an experiment first so the test event has somewhere to land."); return; }
    setTesting(true);
    try {
      const visitorId = "sf_test_" + Array.from({ length: 16 }, () => Math.floor(Math.random() * 16).toString(16)).join("");
      const url = activeExperiment.pageUrl || window.location.href;
      await fetch("api/track.php?i=1&a=decide", { method: "POST", headers: { "Content-Type": "text/plain" }, body: JSON.stringify({ project, visitorId, url, device: "Desktop", paid: false, returning: false }) });
      await fetch("api/track.php?i=1&a=convert", { method: "POST", headers: { "Content-Type": "text/plain" }, body: JSON.stringify({ project, visitorId, event: "conversion" }) });
      utils.tracking.health.invalidate();
      utils.experiments.list.invalidate();
      notify("Test visitor sent through the live tracking pipeline.");
    } catch { notify("Could not reach the tracking endpoint."); }
    setTesting(false);
  };

  return <div className="tracking-page">
    <section className="page-heading"><div><div className="overline"><span className="live-pulse" />Instrumentation</div><h1>Tracking setup</h1><p>Connect your site once, then verify every conversion signal before trusting a result.</p></div><Button onClick={testEvent} disabled={testing}><Zap size={16} />{testing ? "Sending…" : "Send test event"}</Button></section>
    <section className="tracking-status-grid"><Card className="tracking-health-card"><CardContent><div className="tracking-card-top"><div><div className="card-kicker"><Activity size={14} />TRACKING HEALTH</div><CardTitle>{identity.workspaceName}</CardTitle></div><Badge className="status-badge-live"><span className="status-dot" />{health?.scriptDetected ? "Live" : "Waiting"}</Badge></div><div className={`health-check ${health?.scriptDetected ? "" : "pending"}`}><span className="health-check-icon">{health?.scriptDetected ? <Check size={13} /> : <Clock3 size={13} />}</span><div><strong>{health?.scriptDetected ? "Script detected" : "No events yet"}</strong><p>{health?.scriptDetected ? `Last event received ${health.lastEventAgo ?? "recently"}` : "Install the snippet and load a tracked page"}</p></div></div><div className="health-check"><span className="health-check-icon"><Check size={13} /></span><div><strong>Events receiving</strong><p>{formatNumber(health?.eventsLast24h ?? 0)} events in the last 24 hours</p></div></div><div className={`health-check ${waitingCount > 0 ? "pending" : ""}`}><span className="health-check-icon">{waitingCount > 0 ? <Clock3 size={13} /> : <Check size={13} />}</span><div><strong>{waitingCount > 0 ? `${waitingCount} event${waitingCount > 1 ? "s" : ""} need a test` : "All mapped events receiving"}</strong><p>{waitingCount > 0 ? "Send a test event to complete validation" : `${formatNumber(health?.conversions ?? 0)} conversions recorded`}</p></div></div></CardContent></Card><Card className="tracking-next-card"><CardContent><div className="card-kicker"><Target size={14} />NEXT STEP</div><h3>Validate your conversion</h3><p>Trigger your primary CTA or form once on the live site, then return here to confirm the event is mapped.</p><Button variant="outline" onClick={testEvent} disabled={testing}>Test conversion event <ArrowUpRight size={14} /></Button></CardContent></Card></section>
    <section className="tracking-layout"><Card className="snippet-card"><CardHeader><div><div className="card-kicker"><Code2 size={14} />INSTALL SNIPPET</div><CardTitle>Add SignalForge to your site</CardTitle><p className="card-subtitle">Place this once before the closing <code>&lt;/head&gt;</code> tag on every page you want to test.</p></div></CardHeader><CardContent><div className="code-block"><code>{snippet}</code><button onClick={copySnippet} aria-label="Copy tracking snippet">{copied ? <><Check size={14} />Copied</> : <><Code2 size={14} />Copy</>}</button></div><div className="install-hints"><span><Check size={13} />Works with any website</span><span><Check size={13} />Google Tag Manager compatible</span><span><Check size={13} />No personal data collected</span></div><div className="install-tabs"><button className="selected">JavaScript</button><button onClick={() => notify("In GTM, add a Custom HTML tag with this snippet and fire it on All Pages.")}>Google Tag Manager</button><button onClick={() => notify("In WordPress/Webflow, paste the snippet into the site-wide header code area.")}>WordPress / Webflow</button></div></CardContent></Card><Card className="event-map-card"><CardHeader><div><div className="card-kicker"><GitBranch size={14} />CONVERSION EVENTS</div><CardTitle>Event mapping</CardTitle><p className="card-subtitle">Events come from each experiment's conversion goal.</p></div><Badge variant="outline">{events.length} mapped</Badge></CardHeader><CardContent><div className="event-table-head"><span>Event</span><span>Target</span><span>Status</span></div>{events.length === 0 && <div className="empty-state"><GitBranch size={20} /><strong>No conversion events yet.</strong><span>Create an experiment to define one.</span></div>}{events.map(event => <div className="event-table-row" key={event.name}><div><strong>{event.name}</strong><small>{event.type}</small></div><code>{event.selector}</code><span className={`event-status ${event.status === "Receiving" ? "receiving" : "waiting"}`}><span />{event.status}</span></div>)}</CardContent></Card></section>
    <section className="tracking-footnote"><CircleHelp size={15} /><span>Events are sampled by experiment and attributed to the visitor's assigned variant. Use the test-event button before launching to avoid inconclusive results.</span><button onClick={() => { healthQuery.refetch(); notify(health?.lastEventAgo ? `Last event received ${health.lastEventAgo}.` : "No events received yet."); }}>Refresh tracking status <ArrowUpRight size={13} /></button></section>
  </div>;
}

function PredictiveInsights({ notify }: { notify: (message: string) => void }) {
  const [ranModel, setRanModel] = useState("");
  const [resultText, setResultText] = useState("");
  const experimentsQuery = trpc.experiments.list.useQuery();
  const healthQuery = trpc.tracking.health.useQuery();
  const experiments = (experimentsQuery.data ?? []) as Experiment[];
  const visitorRows = healthQuery.data?.visitorRows ?? 0;
  const mappedOutcomes = healthQuery.data?.mappedOutcomes ?? 0;
  const hasData = visitorRows > 0;
  const readyCount = hasData ? 1 : 0;
  const models = [
    { id: "logistic", name: "Logistic regression", family: "Conversion prediction", description: "Predict visitor-level conversion probability from device, source, audience, and experiment assignment.", requirement: "Visitor-level rows + ML compute", color: "blue" },
    { id: "causal", name: "Causal forests", family: "Treatment effects", description: "Estimate how the effect of each variant changes across audience segments without assuming one average lift.", requirement: "Visitor-level rows + ML compute", color: "purple" },
    { id: "uplift", name: "Uplift modeling", family: "Who benefits", description: "Find the visitors most likely to convert because of a variant, not simply visitors who convert anyway.", requirement: "Treatment + outcome labels", color: "orange" },
    { id: "boosting", name: "Gradient boosting", family: "Conversion prediction", description: "Capture nonlinear interactions across traffic source, device, page behavior, and experiment exposure.", requirement: "10k+ labeled visitors + ML compute", color: "green" },
    { id: "survival", name: "Survival analysis", family: "Time to conversion", description: "Model how long visitors take to convert and identify whether a variant accelerates the funnel.", requirement: "Timestamped events + ML compute", color: "slate" },
    { id: "sequential", name: "Sequential Bayesian testing", family: "Continuous monitoring", description: "Update the probability to win as data arrives while controlling the risk of stopping on an early random swing.", requirement: "Aggregate counts", color: "coral" },
  ];

  const runModel = (model: typeof models[number]) => {
    if (model.id !== "sequential") {
      notify(`${model.name} needs an ML runtime that free hosting does not provide. Your ${formatNumber(visitorRows)} visitor rows are ready to export for external analysis.`);
      return;
    }
    const withData = experiments.filter(item => item.variants.some(variant => variant.visitors > 0));
    const target = withData.sort((a, b) => b.variants.reduce((s, v) => s + v.visitors, 0) - a.variants.reduce((s, v) => s + v.visitors, 0))[0];
    if (!target) { notify("No visitor data yet. Install the snippet and let visitors arrive first."); return; }
    const leader = target.variants.filter(variant => !variant.isControl).sort((a, b) => b.probability - a.probability)[0];
    const visitors = target.variants.reduce((sum, variant) => sum + variant.visitors, 0);
    const maturity = Math.min(100, Math.round((visitors / 10000) * 100));
    setRanModel(model.id);
    setResultText(`${target.name}: ${leader?.probability ?? 50}% probability to beat control across ${formatNumber(visitors)} visitors. ${maturity < 35 ? `Continue monitoring until maturity reaches 35% (now ${maturity}%).` : "Maturity is sufficient to consider a decision."}`);
    notify("Sequential Bayesian analysis computed from your live data.");
  };

  return <div className="insights-page">
    <section className="page-heading"><div><div className="overline"><span className="live-pulse" />Decision science</div><h1>Predictive insights</h1><p>Choose the right model for the question you are trying to answer.</p></div><Button onClick={() => notify(`${experiments.length} experiments · ${formatNumber(visitorRows)} visitor rows · ${mappedOutcomes} mapped outcomes. Sequential Bayesian is the model this host can run.`)}><Zap size={16} />Run analysis queue</Button></section>
    <section className="model-readiness"><div className="readiness-score"><div className="readiness-score-ring"><strong>{readyCount}/6</strong><span>models ready</span></div><div><div className="card-kicker"><Gauge size={14} />DATA READINESS</div><h3>{hasData ? "Visitor events are flowing" : "Connect visitor events to unlock prediction"}</h3><p>{hasData ? "Your live counts support sequential Bayesian testing now. The heavier models need an ML runtime that free hosting does not provide." : "Your current experiment counts can support sequential Bayesian testing once visitors arrive. Install the snippet from Tracking setup."}</p><button onClick={() => notify("Open Tracking setup from the sidebar to install the event snippet and map outcomes.")}>Configure event data <ArrowUpRight size={13} /></button></div></div><div className="readiness-metrics"><div><span>Experiments</span><strong>{experiments.length}</strong></div><div><span>Visitor-level rows</span><strong className={hasData ? "" : "warning-value"}>{formatNumber(visitorRows)}</strong></div><div><span>Mapped outcomes</span><strong className={mappedOutcomes > 0 ? "" : "warning-value"}>{mappedOutcomes}</strong></div></div></section>
    <div className="section-heading-inline"><div><div className="card-kicker"><Activity size={14} />MODEL CATALOG</div><h2>Choose a prediction technique</h2></div><span className="catalog-note">Models never replace the underlying experiment readout.</span></div>
    <section className="model-grid">{models.map(model => { const isSequential = model.id === "sequential"; const ready = isSequential && hasData; const status = isSequential ? (hasData ? "Ready to run" : "Needs event data") : (hasData ? "Needs ML compute" : "Needs event data"); return <Card className={`model-card model-${model.color}`} key={model.id}><CardContent><div className="model-card-top"><span className="model-type">{model.family}</span><span className={`model-status ${ready ? "ready" : "locked"}`}>{ready ? <Check size={12} /> : <Clock3 size={12} />}{status}</span></div><h3>{model.name}</h3><p>{model.description}</p><div className="model-requirement"><span>Requires</span><strong>{model.requirement}</strong></div><Button variant={ready ? "default" : "outline"} onClick={() => runModel(model)}>{isSequential ? "Run on current data" : "View data requirements"}{isSequential ? <ArrowUpRight size={13} /> : <CircleHelp size={13} />}</Button>{ranModel === model.id && resultText && <div className="model-result"><Check size={13} /><span>{resultText}</span></div>}</CardContent></Card>; })}</section>
    <section className="model-method-note"><div className="model-method-icon"><Lightbulb size={17} /></div><div><strong>How SignalForge uses these models</strong><p>Start with transparent Bayesian estimates computed from your live counts. Heavier predictive models need an external ML runtime; keep the experiment's causal comparison as the source of truth.</p></div><button onClick={() => notify("Methodology: Bayesian posteriors use a Beta(1,1) prior; probability to beat control comes from the posterior difference.")}>Read methodology <ArrowUpRight size={13} /></button></section>
  </div>;
}

function FrameworkLibrary({ notify }: { notify: (message: string) => void }) {
  const [search, setSearch] = useState("");
  const frameworks = [
    ["MECLABS heuristic", "Diagnosis", "Find the biggest friction or motivation gap affecting conversion."], ["Fogg Behavior Model", "Behavior", "Balance motivation, ability, and prompts to shape action."], ["COM-B Model", "Behavior", "Diagnose capability, opportunity, and motivation barriers."], ["EAST Framework", "Behavior", "Make the desired behavior easy, attractive, social, and timely."], ["HEART", "UX measurement", "Measure happiness, engagement, adoption, retention, and task success."], ["Nielsen Usability Heuristics", "Usability", "Find interface friction through proven usability principles."], ["AIDA", "Messaging", "Move visitors through attention, interest, desire, and action."], ["LIFT Model", "Messaging", "Improve value proposition while reducing anxiety, distraction, and friction."], ["Cialdini Persuasion Principles", "Persuasion", "Apply social proof, authority, scarcity, and other persuasion levers."], ["Hook Model", "Retention", "Design trigger, action, variable reward, and investment loops."], ["PIE Framework", "Prioritization", "Score potential, importance, and ease before choosing a test."], ["PXL Framework", "Prioritization", "Prioritize tests using evidence-backed pattern and effort signals."], ["ICE Prioritization", "Prioritization", "Rank ideas by impact, confidence, and ease."], ["RICE Scoring", "Prioritization", "Compare reach, impact, confidence, and effort across ideas."], ["Bayesian testing", "Statistics", "Update the probability of a variant winning as evidence arrives."], ["Jobs to be Done", "Research", "Frame tests around the job a visitor is trying to accomplish."], ["5C Analysis", "Research", "Review company, customers, competitors, collaborators, and context."], ["7C Conversion Framework", "Research", "Audit credibility, clarity, content, continuity, and conversion cues."], ["Customer Journey Mapping", "Research", "Find experience gaps across the visitor's end-to-end journey."], ["Value Proposition Canvas", "Research", "Align visitor pains and gains with the experience promise."], ["AARRR Funnel", "Growth", "Measure acquisition, activation, retention, referral, and revenue."], ["Customer funnel analysis", "Growth", "Identify the stage where the largest conversion loss occurs."]
  ];
  const visible = frameworks.filter(([name, category, description]) => `${name} ${category} ${description}`.toLowerCase().includes(search.toLowerCase()));
  return <div className="framework-library"><section className="page-heading"><div><div className="overline"><span className="live-pulse" />CRO playbooks</div><h1>Framework library</h1><p>Choose a lens before you write the hypothesis. Each playbook turns a different kind of evidence into a sharper test.</p></div><Button onClick={() => notify("Framework guide saved to your workspace.")}><Bookmark size={16} />Save playbook</Button></section><div className="framework-library-toolbar"><div><strong>{visible.length} playbooks</strong><span>Behavior, research, prioritization, statistics, and growth</span></div><div className="framework-search"><Search size={14} /><input value={search} onChange={event => setSearch(event.target.value)} placeholder="Search frameworks" /></div></div><section className="framework-library-grid">{visible.map(([name, category, description]) => <Card className="framework-library-card" key={name}><CardContent><div className="framework-card-top"><span>{category}</span><Layers3 size={15} /></div><h3>{name}</h3><p>{description}</p><button onClick={() => notify(`${name} selected for the next experiment brief.`)}>Use in experiment <ArrowUpRight size={13} /></button></CardContent></Card>)}</section></div>;
}

function WorkspaceSettings({ notify }: { notify: (message: string) => void }) {
  const identity = useIdentity();
  const utils = trpc.useUtils();
  const healthQuery = trpc.tracking.health.useQuery();
  const saveMutation = trpc.settings.save.useMutation();
  const [form, setForm] = useState<WorkspaceSettings | null>(null);
  useEffect(() => { const loaded = identity.settings; if (loaded) setForm(prev => prev ?? loaded); }, [identity.settings]);
  if (!form) return <div className="settings-page"><section className="page-heading"><div><h1>Workspace settings</h1><p>Loading your workspace…</p></div></section></div>;
  const patch = (next: Partial<WorkspaceSettings>) => setForm({ ...form, ...next });
  const save = () => saveMutation.mutate(form, { onSuccess: () => { utils.settings.get.invalidate(); notify("Workspace settings saved."); }, onError: error => notify(error.message) });
  const ingestionConnected = Boolean(healthQuery.data?.scriptDetected);
  return <div className="settings-page"><section className="page-heading"><div><div className="overline"><span className="live-pulse" />Workspace administration</div><h1>Workspace settings</h1><p>Manage defaults and notifications for your SignalForge workspace.</p></div><Button onClick={save} disabled={saveMutation.isPending}><Check size={16} />{saveMutation.isPending ? "Saving…" : "Save changes"}</Button></section><section className="settings-grid"><Card><CardHeader><div className="card-kicker"><Settings2 size={14} />GENERAL</div><CardTitle>Workspace defaults</CardTitle><p className="card-subtitle">These defaults guide new experiment setup.</p></CardHeader><CardContent><label className="settings-field">Workspace name<input value={form.workspaceName} onChange={event => patch({ workspaceName: event.target.value })} /></label><label className="settings-field">Timezone<select value={form.timezone} onChange={event => patch({ timezone: event.target.value })}><option value="Asia/Kolkata">Asia/Kolkata (IST)</option><option value="UTC">UTC</option><option value="America/New_York">America/New York (ET)</option><option value="Europe/London">Europe/London (GMT)</option></select></label><label className="settings-field">Default conversion goal<select value={form.defaultGoal} onChange={event => patch({ defaultGoal: event.target.value })}><option>Trial starts</option><option>Completed checkout</option><option>Activated workspaces</option><option>Demo requests</option></select></label></CardContent></Card><Card><CardHeader><div className="card-kicker"><Bell size={14} />NOTIFICATIONS</div><CardTitle>Stay informed</CardTitle><p className="card-subtitle">Choose which experiment updates reach your team.</p></CardHeader><CardContent><label className="settings-toggle"><span><strong>Experiment health alerts</strong><small>Tracking failures, sample-ratio mismatch, and data delays.</small></span><input type="checkbox" checked={form.emailUpdates} onChange={event => patch({ emailUpdates: event.target.checked })} /><i /></label><label className="settings-toggle"><span><strong>Weekly experiment digest</strong><small>A summary of active tests, winners, and next actions.</small></span><input type="checkbox" checked={form.weeklyDigest} onChange={event => patch({ weeklyDigest: event.target.checked })} /><i /></label><div className="settings-note"><Lightbulb size={14} /><span>Settings are saved to your workspace database and persist across sessions. Email delivery requires a mail service, which free hosting does not provide.</span></div></CardContent></Card></section><section className="settings-grid"><Card><CardHeader><div className="card-kicker"><TrendingUp size={14} />BUSINESS IMPACT</div><CardTitle>Revenue assumptions</CardTitle><p className="card-subtitle">Used by the Intelligence revenue forecast.</p></CardHeader><CardContent><label className="settings-field">Revenue per conversion ($)<input type="number" min="0" step="0.01" value={form.revenuePerConversion} onChange={event => patch({ revenuePerConversion: Number(event.target.value) })} /></label><label className="settings-field">Monthly visitors<input type="number" min="0" step="1" value={form.monthlyVisitors} onChange={event => patch({ monthlyVisitors: Number(event.target.value) })} /></label><div className="settings-note"><Lightbulb size={14} /><span>Set both values to project revenue impact from your real experiment uplift.</span></div></CardContent></Card><Card><CardHeader><div className="card-kicker"><Code2 size={14} />DATA AND PRIVACY</div><CardTitle>Tracking controls</CardTitle><p className="card-subtitle">Keep production data boundaries explicit.</p></CardHeader><CardContent><div className="settings-status-row"><span>Visitor-level event ingestion</span><Badge variant="outline">{ingestionConnected ? "Connected" : "Not connected"}</Badge></div><div className="settings-status-row"><span>Third-party integrations</span><Badge variant="outline">0 connected</Badge></div><button className="text-link" onClick={() => notify("Open Tracking setup from the sidebar to install the snippet.")}>Open Tracking setup <ArrowUpRight size={13} /></button></CardContent></Card></section><section className="settings-grid"><Card><CardHeader><div className="card-kicker"><ShieldCheck size={14} />ACCESS</div><CardTitle>Workspace access</CardTitle><p className="card-subtitle">The account that owns this workspace.</p></CardHeader><CardContent><div className="member-row"><div className="user-avatar small">{identity.personInitials}</div><div><strong>{identity.name}</strong><small>{identity.user?.role === "admin" ? "Admin · Owner" : "Owner"}{identity.user?.email ? ` · ${identity.user.email}` : ""}</small></div><Badge variant="outline">Active</Badge></div></CardContent></Card></section></div>;
}

function IntelligenceCenter({ notify }: { notify: (message: string) => void }) {
  const identity = useIdentity();
  const experimentsQuery = trpc.experiments.list.useQuery();
  const healthQuery = trpc.tracking.health.useQuery();
  const experiments = (experimentsQuery.data ?? []) as Experiment[];
  const health = healthQuery.data;
  const settings = identity.settings;
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const selected = experiments.find(item => item.id === selectedId) ?? experiments.find(item => item.status === "Active") ?? experiments[0];
  const segmentsQuery = trpc.experiments.segments.useQuery({ id: selected?.id ?? 0 }, { enabled: Boolean(selected) });
  const segments = segmentsQuery.data ?? [];
  const [assistantPrompt, setAssistantPrompt] = useState("");
  const [assistantReply, setAssistantReply] = useState("");
  const [segment, setSegment] = useState("All visitors");

  const control = selected ? (selected.variants.find(variant => variant.isControl) ?? selected.variants[0]) : undefined;
  const leader = selected ? selected.variants.filter(variant => !variant.isControl).sort((a, b) => b.probability - a.probability)[0] : undefined;
  const visitors = selected ? selected.variants.reduce((sum, variant) => sum + variant.visitors, 0) : 0;
  const conversions = selected ? selected.variants.reduce((sum, variant) => sum + variant.conversions, 0) : 0;
  const probability = leader?.probability ?? 50;
  const maturity = Math.min(100, Math.round((visitors / 10000) * 100));
  const counts = selected ? selected.variants.map(variant => variant.visitors) : [];
  const balanced = visitors > 0 && Math.max(...counts) / Math.max(Math.min(...counts), 1) <= 2.5;
  const trackingOk = Boolean(health?.scriptDetected && (health?.eventsLast24h ?? 0) > 0);
  const conversionOk = conversions > 0;
  const maturityOk = maturity >= 35;
  const healthScore = (trackingOk ? 25 : 0) + (balanced ? 25 : 0) + (maturityOk ? 25 : 0) + (conversionOk ? 25 : 0);

  const recommendation = visitors === 0
    ? { title: "Launch to collect data", body: "No visitors have entered this test yet. Activate it and install the snippet to start collecting." }
    : maturity < 35
      ? { title: "Continue collecting data", body: `${leader?.name ?? "The leading variant"} is at ${probability}% probability, but maturity is only ${maturity}%. Keep running before deciding.` }
      : probability >= 90
        ? { title: "Likely winner — consider rollout", body: `${leader?.name ?? "The leading variant"} has ${probability}% probability to beat control with ${maturity}% maturity.` }
        : probability <= 10
          ? { title: "Control is leading", body: `The challenger is unlikely to beat control (${probability}%). Consider stopping or iterating.` }
          : { title: "Inconclusive — keep running", body: `The signal is not yet decisive (${probability}% probability). Collect more visitors.` };
  const visitorsToMaturity = Math.max(0, 3500 - visitors);

  const revenuePerConversion = settings?.revenuePerConversion ?? 0;
  const monthlyVisitors = settings?.monthlyVisitors ?? 0;
  const controlRate = control && control.visitors > 0 ? control.conversions / control.visitors : 0;
  const leaderRate = leader && leader.visitors > 0 ? leader.conversions / leader.visitors : 0;
  const rateDiff = leaderRate - controlRate;
  const additionalConversions = Math.round(monthlyVisitors * rateDiff);
  const monthlyImpact = additionalConversions * revenuePerConversion;
  const revenueConfigured = revenuePerConversion > 0 && monthlyVisitors > 0;
  const money = (value: number) => new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: Math.abs(value) < 10 ? 2 : 0 }).format(value);

  const normUrl = (value?: string) => { try { const parsed = new URL(value ?? ""); return parsed.host.replace(/^www\./, "") + parsed.pathname.replace(/\/$/, ""); } catch { return ""; } };
  const selectedUrl = normUrl(selected?.pageUrl);
  const collisions = selected ? experiments.filter(item => item.id !== selected.id && selectedUrl !== "" && normUrl(item.pageUrl) === selectedUrl) : [];

  const now = new Date();
  const monthName = now.toLocaleString("en-US", { month: "long" });
  const weekDays = Array.from({ length: 7 }, (_, index) => { const day = new Date(); day.setDate(now.getDate() + index); return day; });

  const answer = (question: string) => {
    const q = question.trim().toLowerCase();
    if (!q) return;
    let reply: string;
    if (!selected) reply = "Create an experiment first, then I can ground answers in its live readout.";
    else if (/reliab|trust|significan|confidence|sure/.test(q)) reply = `${selected.name} has ${formatNumber(visitors)} visitors and ${formatNumber(conversions)} conversions. ${leader?.name ?? "The leading variant"} shows ${probability}% probability to beat control at ${maturity}% data maturity. ${maturity < 35 ? "This is still early — keep collecting before deciding." : "The sample is maturing; treat this as decision support, not proof."}`;
    else if (/segment|mobile|desktop|who|paid|returning/.test(q)) { const best = [...segments].filter(row => row.visitors > 0).sort((a, b) => b.lift - a.lift)[0]; reply = best ? `${best.segment} shows the strongest lift at ${best.lift >= 0 ? "+" : ""}${best.lift}% across ${formatNumber(best.visitors)} visitors (${best.signal.toLowerCase()} signal). Segment data comes from device, paid-click, and repeat-visit flags on each exposure.` : "No segment data yet — visitors need to arrive before segment lift can be computed."; }
    else if (/revenue|money|impact|forecast/.test(q)) reply = revenueConfigured ? `At ${money(revenuePerConversion)} per conversion and ${formatNumber(monthlyVisitors)} monthly visitors, the current uplift projects ${money(monthlyImpact)} per month (${money(monthlyImpact * 12)} per year).` : "Set revenue per conversion and monthly visitors in Workspace settings to project revenue impact.";
    else if (/next|what should|idea|test next/.test(q)) reply = collisions.length > 0 ? `First resolve the overlap with ${collisions[0].name} on the same page, then prioritize the biggest friction on ${selected.pageUrl || "the target page"} using the ${selected.framework} lens.` : `Focus on the biggest friction on ${selected.pageUrl || "the target page"}. Use the ${selected.framework} lens to write a hypothesis, and let this test reach ${maturity < 35 ? "35% maturity" : "a decisive probability"} before starting the next one.`;
    else reply = `${selected.name}: ${formatNumber(visitors)} visitors, ${formatNumber(conversions)} conversions, ${probability}% probability to beat control. Recommendation: ${recommendation.title.toLowerCase()}.`;
    setAssistantReply(reply);
    setAssistantPrompt("");
  };

  const exportSegments = async () => {
    const csv = ["Segment,Lift %,Signal,Visitors", ...segments.map(row => `${row.segment},${row.lift},${row.signal},${row.visitors}`)].join("\n");
    try { await navigator.clipboard.writeText(csv); notify("Segment CSV copied to clipboard."); } catch { notify("Clipboard unavailable."); }
  };

  if (!selected) return <div className="intelligence-page"><section className="page-heading"><div><h1>Experiment command center</h1><p>Create an experiment to unlock intelligence.</p></div></section></div>;

  return <div className="intelligence-page">
    <section className="page-heading"><div><div className="overline"><span className="live-pulse" />CRO intelligence</div><h1>Experiment command center</h1><p>Monitor data quality, understand who benefits, and turn signals into the next action.</p></div><div className="intel-heading-actions"><select className="intel-experiment-select" value={selected.id} onChange={event => setSelectedId(Number(event.target.value))}>{experiments.map(item => <option key={item.id} value={item.id}>{item.name}</option>)}</select><Button onClick={() => { healthQuery.refetch(); experimentsQuery.refetch(); segmentsQuery.refetch(); notify(`Health scan complete: ${selected.name} scores ${healthScore}/100.`); }}><Activity size={16} />Run health scan</Button></div></section>
    <section className="intel-top-grid"><Card className="health-score-card"><CardContent><div className="intel-card-heading"><div><div className="card-kicker"><Gauge size={14} />EXPERIMENT HEALTH</div><CardTitle>{selected.name}</CardTitle><p className="card-subtitle">Updated {selected.updated}</p></div><div className="health-score"><strong>{healthScore}</strong><span>/100</span></div></div><ProgressBar value={healthScore} color={healthScore >= 75 ? "#6eaf75" : healthScore >= 50 ? "#f2aa4f" : "#e5643c"} /><div className="health-check-list"><div>{trackingOk ? <Check size={13} /> : <Clock3 size={13} />}<span>Tracking script receiving</span><b className={trackingOk ? "" : "intel-warning"}>{trackingOk ? "Good" : "No recent events"}</b></div><div>{balanced ? <Check size={13} /> : <Clock3 size={13} />}<span>Traffic allocation balanced</span><b className={balanced ? "" : "intel-warning"}>{visitors === 0 ? "No data" : balanced ? "Good" : "Check split"}</b></div><div>{maturityOk ? <Check size={13} /> : <Clock3 size={13} />}<span>Sample-size maturity</span><b className={maturityOk ? "" : "intel-warning"}>{maturityOk ? `${maturity}%` : `Needs more data (${maturity}%)`}</b></div><div>{conversionOk ? <Check size={13} /> : <Clock3 size={13} />}<span>Conversion stability</span><b className={conversionOk ? "" : "intel-warning"}>{conversionOk ? "Good" : "No conversions"}</b></div></div></CardContent></Card><Card className="recommendation-card"><CardContent><div className="card-kicker"><Lightbulb size={14} />RECOMMENDATION</div><h3>{recommendation.title}</h3><p>{recommendation.body}</p><div className="recommendation-meta"><span>Confidence path</span><strong>{visitorsToMaturity > 0 ? `${formatNumber(visitorsToMaturity)} visitors to go` : "Maturity reached"}</strong><span>Expected uplift</span><strong>{leader ? `${leader.uplift >= 0 ? "+" : ""}${leader.uplift}%` : "—"}</strong></div><Button variant="outline" onClick={async () => { try { await navigator.clipboard.writeText(`${recommendation.title}: ${recommendation.body}`); notify("Recommendation copied to clipboard."); } catch { notify("Clipboard unavailable."); } }}>Copy recommendation <ArrowUpRight size={14} /></Button></CardContent></Card></section>
    <section className="intel-two-column"><Card><CardHeader><div className="card-kicker"><Users size={14} />SEGMENT READOUT</div><CardTitle>Who benefits from the variant?</CardTitle><p className="card-subtitle">Variant effect by audience segment</p></CardHeader><CardContent><div className="segment-controls"><select value={segment} onChange={event => setSegment(event.target.value)}>{["All visitors", "Mobile visitors", "Desktop visitors", "Paid traffic", "Returning visitors"].map(label => <option key={label}>{label}</option>)}</select><button onClick={exportSegments}>Export <ArrowUpRight size={12} /></button></div><div className="segment-table"><div className="segment-table-head"><span>Segment</span><span>Lift</span><span>Signal</span></div>{segments.length === 0 && <div className="empty-state"><Users size={18} /><strong>No segment data yet.</strong></div>}{segments.map(row => <div className={`segment-row ${segment === row.segment ? "selected" : ""}`} key={row.segment}><span>{row.segment}</span><strong>{row.lift >= 0 ? "+" : ""}{row.lift}%</strong><span className={`signal-${row.signal.toLowerCase()}`}>{row.signal}</span></div>)}</div></CardContent></Card><Card><CardHeader><div className="card-kicker"><BarChart3 size={14} />MODEL EVALUATION</div><CardTitle>Prediction quality</CardTitle><p className="card-subtitle">Validation metrics for available models</p></CardHeader><CardContent><div className="evaluation-list"><div><span>Sequential Bayesian</span><b>{visitors > 0 ? "Live" : "Awaiting data"}</b><strong>{visitors > 0 ? `${probability}%` : "—"}</strong></div><div><span>Visitor-level models</span><b className="intel-warning">Needs ML compute</b><strong>—</strong></div></div><div className="evaluation-metrics"><div><span>AUC</span><strong>—</strong></div><div><span>Brier score</span><strong>—</strong></div><div><span>Segment lift</span><strong>{segments.length > 0 ? "Ready" : "—"}</strong></div></div><button className="text-link" onClick={() => notify("Probability to win uses a Beta posterior difference. AUC/Brier need an external ML runtime.")}>View evaluation details <ArrowUpRight size={13} /></button></CardContent></Card></section>
    <section className="intel-two-column"><Card><CardHeader><div className="card-kicker"><TrendingUp size={14} />BUSINESS IMPACT</div><CardTitle>Revenue forecast</CardTitle><p className="card-subtitle">Projected from current uplift and conversion value</p></CardHeader><CardContent>{revenueConfigured ? <><div className="revenue-hero"><span>Expected monthly impact</span><strong>{monthlyImpact >= 0 ? "+" : ""}{money(monthlyImpact)}</strong><b>{probability}% probability of positive impact</b></div><div className="revenue-grid"><div><span>Additional conversions</span><strong>{additionalConversions} / month</strong></div><div><span>Revenue per visitor</span><strong>{money(monthlyVisitors > 0 ? monthlyImpact / monthlyVisitors : 0)}</strong></div><div><span>Projected annual impact</span><strong>{money(monthlyImpact * 12)}</strong></div></div></> : <div className="empty-state"><TrendingUp size={20} /><strong>Set revenue assumptions.</strong><span>Add revenue per conversion and monthly visitors in Workspace settings to project impact from your real uplift.</span></div>}<button className="text-link" onClick={() => notify("Open Workspace settings → Revenue assumptions to edit these values.")}>Edit revenue assumptions <Settings2 size={13} /></button></CardContent></Card><Card className="collision-card"><CardHeader><div className="card-kicker"><CircleHelp size={14} />COLLISION DETECTION</div><CardTitle>{collisions.length > 0 ? `${collisions.length} overlapping experiment${collisions.length > 1 ? "s" : ""}` : "No overlapping experiments"}</CardTitle><p className="card-subtitle">{collisions.length > 0 ? "Resolve traffic interference before scaling." : "No other test targets this page URL."}</p></CardHeader><CardContent>{collisions.length > 0 ? <><div className="collision-warning"><Zap size={16} /><div><strong>{collisions[0].name}</strong><p>Shares the page URL {selected.pageUrl} with this test.</p></div></div><div className="collision-actions"><button onClick={() => notify(`${collisions.length} experiment(s) share this page: ${collisions.map(item => item.name).join(", ")}.`)}>Compare audiences</button></div></> : <div className="health-check"><span className="health-check-icon"><Check size={13} /></span><div><strong>Clear to run</strong><p>No other experiment shares this page URL.</p></div></div>}</CardContent></Card></section>
    <section className="intel-bottom-grid"><Card><CardHeader><div className="card-kicker"><Clock3 size={14} />EXPERIMENT CALENDAR</div><CardTitle>{monthName} testing plan</CardTitle><p className="card-subtitle">Your experiments and their current status</p></CardHeader><CardContent><div className="calendar-strip">{weekDays.map((day, index) => <div className={index === 0 ? "today" : ""} key={index}><span>{day.toLocaleString("en-US", { weekday: "narrow" })}</span><strong>{String(day.getDate()).padStart(2, "0")}</strong>{index === 0 && <i />}</div>)}</div>{experiments.slice(0, 3).map((item, index) => <div className="calendar-event" key={item.id}><span className={`event-bar ${index % 2 === 0 ? "orange" : "blue"}`} /><div><strong>{item.name}</strong><small>Updated {item.updated}</small></div><Badge variant="outline">{item.status}</Badge></div>)}<button className="text-link" onClick={() => notify(`${experiments.length} experiments in this workspace.`)}>Open full calendar <ArrowUpRight size={13} /></button></CardContent></Card><Card><CardHeader><div className="card-kicker"><Sparkles size={14} />CRO ASSISTANT</div><CardTitle>Ask about your test</CardTitle><p className="card-subtitle">Rule-based answers grounded in your live readout.</p></CardHeader><CardContent><div className="assistant-suggestions"><button onClick={() => answer("Is the current result reliable?")}>Is this result reliable?</button><button onClick={() => answer("What should we test next?")}>What should we test next?</button></div><div className="assistant-input"><input value={assistantPrompt} onChange={event => setAssistantPrompt(event.target.value)} placeholder="Ask about reliability, segments, revenue, next steps..." onKeyDown={event => { if (event.key === "Enter") answer(assistantPrompt); }} /><button onClick={() => answer(assistantPrompt)}><ArrowUpRight size={14} /></button></div>{assistantReply && <div className="assistant-reply"><Sparkles size={13} /><span>{assistantReply}</span></div>}<button className="visual-editor-button" onClick={() => notify("Variant CSS/JS is editable via the API (experiments.updateVariantCode); a visual editor is not part of this build.")}><Code2 size={14} /><span><strong>Variant code</strong><small>Custom CSS/JS is applied by the tracker</small></span><ArrowUpRight size={14} /></button></CardContent></Card></section>
    <section className="collaboration-bar"><div><div className="card-kicker"><Users size={14} />WORKSPACE</div><strong>{identity.workspaceName}</strong><span> {identity.name} · {experiments.length} experiment{experiments.length === 1 ? "" : "s"} · last activity {selected.updated}</span></div><div className="integration-pills"><button onClick={() => notify("Slack integration requires an outbound webhook, which free hosting does not allow.")}>Slack</button><button onClick={() => notify("PostHog integration requires an external API connection, not available on free hosting.")}>PostHog</button></div></section>
  </div>;
}

export default function Home() {
  const utils = trpc.useUtils();
  const { logout } = useAuth();
  const meQuery = trpc.auth.me.useQuery();
  const identity = useIdentity();
  const experimentsQuery = trpc.experiments.list.useQuery(undefined, { refetchInterval: 10000 });
  const experiments = (experimentsQuery.data ?? []) as Experiment[];
  const createMutation = trpc.experiments.create.useMutation();
  const statusMutation = trpc.experiments.setStatus.useMutation();
  const planMutation = trpc.account.setPlan.useMutation();
  const [activeNav, setActiveNav] = useState("Overview");
  const [statusFilter, setStatusFilter] = useState<"All" | ExperimentStatus>("All");
  const [searchTerm, setSearchTerm] = useState("");
  const [selectedId, setSelectedId] = useState(1);
  const [plan, setPlan] = useState<Plan>((meQuery.data?.plan as Plan | undefined) ?? "Growth");
  // The session query resolves after first render, so adopt the server's plan once it arrives.
  useEffect(() => {
    if (meQuery.data?.plan) setPlan(meQuery.data.plan as Plan);
  }, [meQuery.data?.plan]);
  const [isCreateOpen, setCreateOpen] = useState(false);
  const [isPlanOpen, setPlanOpen] = useState(false);
  const [toast, setToast] = useState("");

  const selectedExperiment = experiments.find(experiment => experiment.id === selectedId) ?? experiments[0];
  const trendQuery = trpc.experiments.trend.useQuery({ id: selectedExperiment?.id ?? 0 }, { enabled: Boolean(selectedExperiment), refetchInterval: 15000 });
  const trendData = trendQuery.data ?? [];
  const currentLimit = planLimits[plan];
  const filteredExperiments = useMemo(() => experiments.filter(experiment => {
    const matchesStatus = statusFilter === "All" || experiment.status === statusFilter;
    const query = searchTerm.trim().toLowerCase();
    return matchesStatus && (!query || `${experiment.name} ${experiment.goal} ${experiment.framework}`.toLowerCase().includes(query));
  }), [experiments, searchTerm, statusFilter]);

  const notify = (message: string) => {
    setToast(message);
    window.setTimeout(() => setToast(""), 2600);
  };

  const updateStatus = (id: number, nextStatus: ExperimentStatus) => {
    statusMutation.mutate({ id, status: nextStatus }, {
      onSuccess: () => { utils.experiments.list.invalidate(); notify(`Experiment ${nextStatus.toLowerCase()}.`); },
      onError: error => notify(error.message),
    });
  };

  const changePlan = (next: Plan) => {
    setPlan(next);
    planMutation.mutate({ plan: next }, { onSuccess: () => { utils.auth.me.invalidate(); } });
  };

  const handleCreated = (experiment: Experiment) => {
    createMutation.mutate({
      name: experiment.name,
      kind: experiment.kind,
      goal: experiment.goal,
      framework: experiment.framework,
      pageUrl: experiment.pageUrl ?? "",
      objective: experiment.objective ?? "",
      hypothesis: experiment.hypothesis ?? "",
      observedInsight: experiment.observedInsight ?? "",
      experienceChange: experiment.experienceChange ?? "",
      behaviorReason: experiment.behaviorReason ?? "",
      audience: experiment.audience ?? "All eligible visitors",
      conversionEvent: experiment.conversionEvent ?? "Click on primary CTA",
      traffic: experiment.traffic,
      variantNames: experiment.variants.map(variant => variant.name),
    }, {
      onSuccess: ({ id }) => { utils.experiments.list.invalidate(); setSelectedId(id); setCreateOpen(false); notify("Draft experiment created."); },
      onError: error => notify(error.message),
    });
  };

  const openCreate = () => {
    if (experiments.length >= currentLimit.experiments) {
      notify(`${plan} plan limit reached. Upgrade to create another experiment.`);
      setPlanOpen(true);
      return;
    }
    setCreateOpen(true);
  };

  if (experimentsQuery.isLoading) {
    return <div style={{ minHeight: "100vh", display: "grid", placeItems: "center", color: "#8a91a2" }}>Loading experiments…</div>;
  }

  // The first-run screen used to replace the ENTIRE shell, which hid the sidebar
  // and made a brand-new workspace a dead end: no way to reach Reporting, the
  // crawler, or anything else until an experiment existed. It is now rendered
  // INSIDE the page body (see firstRun below), so navigation always stays available.
  const firstRun = !selectedExperiment && (activeNav === "Overview" || activeNav === "Experiments");

  const activeExperiments = experiments.filter(item => item.status === "Active");
  const totalVisitors = experiments.reduce((sum, item) => sum + item.variants.reduce((inner, variant) => inner + variant.visitors, 0), 0);
  const totalConversions = experiments.reduce((sum, item) => sum + item.variants.reduce((inner, variant) => inner + variant.conversions, 0), 0);
  const blendedRate = totalVisitors > 0 ? `${((totalConversions / totalVisitors) * 100).toFixed(2)}%` : "—";
  const decisionConfidence = activeExperiments.length > 0
    ? `${(activeExperiments.reduce((sum, item) => sum + Math.max(50, ...item.variants.filter(variant => !variant.isControl).map(variant => variant.probability)), 0) / activeExperiments.length).toFixed(1)}%`
    : "—";
  // selectedExperiment is undefined when the workspace has no experiments yet, and
  // tabs like Agent mesh still render in that state — so every read below is optional.
  const leadVariant = selectedExperiment?.variants.filter(variant => !variant.isControl).sort((a, b) => b.probability - a.probability)[0];
  const leadUplift = leadVariant ? `${leadVariant.uplift >= 0 ? "+" : ""}${leadVariant.uplift}%` : "—";
  const leadProbability = leadVariant ? leadVariant.probability : 50;

  return (
    <div className="app-shell">
      <aside className="sidebar">
        <div className="brand-lockup">
          <div className="brand-mark"><FlaskConical size={20} strokeWidth={2.5} /></div>
          <div><div className="brand-name">SignalForge</div><div className="brand-tagline">Experiment with intent</div></div>
        </div>

        <div className="workspace-switcher">
          <div className="workspace-avatar">{identity.workspaceInitials}</div>
          <div className="workspace-copy"><strong>{identity.workspaceName}</strong><span>{plan} workspace</span></div>
          <ChevronDown size={15} className="muted-icon" />
        </div>

        <div className="sidebar-section-label">Workspace</div>
        <nav className="nav-list">
          {[
            { label: "Overview", icon: Gauge },
            { label: "Experiments", icon: Beaker, count: experiments.filter(item => item.status === "Active").length },
            { label: "Insights", icon: BarChart3 },
            { label: "CRO frameworks", icon: Layers3 },
          ].map(item => {
            const Icon = item.icon;
            return <button key={item.label} className={`nav-item ${activeNav === item.label ? "active" : ""}`} onClick={() => setActiveNav(item.label)}><Icon size={17} /><span>{item.label}</span>{item.count ? <span className="nav-count">{item.count}</span> : null}</button>;
          })}
        </nav>

        <div className="sidebar-section-label second">Improve</div>
        <nav className="nav-list">
          <button className={`nav-item ${activeNav === "Tracking setup" ? "active" : ""}`} onClick={() => setActiveNav("Tracking setup")}><Code2 size={17} /><span>Tracking setup</span><span className="nav-new">New</span></button>
          <button className={`nav-item ${activeNav === "CRO intelligence" ? "active" : ""}`} onClick={() => setActiveNav("CRO intelligence")}><Zap size={17} /><span>CRO intelligence</span></button>
          <button className={`nav-item ${activeNav === "Agent mesh" ? "active" : ""}`} onClick={() => setActiveNav("Agent mesh")}><Network size={17} /><span>Agent mesh</span><span className="nav-new">New</span></button>
        </nav>

        <div className="sidebar-section-label second">Grow</div>
        <nav className="nav-list">
          {[
            { label: "Reporting", icon: BarChart3 },
            { label: "Experiments v2", icon: Beaker },
            { label: "Tracking & analytics", icon: Activity },
            { label: "Integrations", icon: Link2 },
            { label: "AI recommendations", icon: Sparkles },
            { label: "Website crawler", icon: Search },
            { label: "Visual analysis", icon: Eye },
            { label: "Competitors", icon: Globe },
            { label: "Ecommerce", icon: TrendingUp },
            { label: "System health", icon: ShieldCheck },
          ].map(item => {
            const Icon = item.icon;
            return <button key={item.label} className={`nav-item ${activeNav === item.label ? "active" : ""}`} onClick={() => setActiveNav(item.label)}><Icon size={17} /><span>{item.label}</span></button>;
          })}
        </nav>
        <div className="sidebar-section-label second manage-label">Manage</div>
        <nav className="nav-list">
          <button className={`nav-item ${activeNav === "Workspace settings" ? "active" : ""}`} onClick={() => setActiveNav("Workspace settings")}><Settings2 size={17} /><span>Workspace settings</span></button>
          <button className="nav-item" onClick={() => logout()}><LogOut size={17} /><span>Sign out</span></button>
        </nav>

        <div className="sidebar-bottom">
          <div className="upgrade-card">
            <div className="upgrade-spark"><Sparkles size={15} /></div>
            <div><strong>Need more signal?</strong><p>Unlock sequential tests and deeper segments.</p><button onClick={() => { changePlan("Scale"); notify("Scale plan preview enabled."); }}>Explore Scale <ArrowUpRight size={13} /></button></div>
          </div>
          <div className="user-row"><div className="user-avatar">{identity.personInitials}</div><div className="user-copy"><strong>{identity.name}</strong><span>{identity.user?.role === "admin" ? "Admin" : "Member"}</span></div><MoreHorizontal size={17} className="muted-icon" /></div>
        </div>
      </aside>

      <main className="main-content">
        <header className="topbar">
          <div className="crumb"><span>{identity.workspaceName}</span><span className="crumb-slash">/</span><strong>{activeNav}</strong></div>
          <div className="topbar-actions">
            <button className="icon-button" aria-label="Help"><CircleHelp size={18} /></button>
            <button className="icon-button has-alert" aria-label="Notifications"><Bell size={18} /><span /></button>
            <div className="plan-menu-wrap">
              <button className="plan-selector" onClick={() => setPlanOpen(current => !current)}><span className="plan-dot" />{plan} plan<ChevronDown size={14} /></button>
              {isPlanOpen && <div className="plan-menu">{(Object.keys(planLimits) as Plan[]).map(option => <button key={option} onClick={() => { changePlan(option); setPlanOpen(false); notify(`${option} plan selected.`); }}><span><strong>{option}</strong><small>{planLimits[option].copy}</small></span>{plan === option ? <Check size={15} /> : null}</button>)}</div>}
            </div>
          </div>
        </header>

        <div className="page-content">
          {firstRun ? (
            <div className="v2-page">
              <section className="page-heading">
                <div>
                  <div className="overline"><span className="live-pulse" />First run</div>
                  <h1>Create your first experiment</h1>
                  <p>Set up a hypothesis, add variants and install the tracking snippet. Results appear as visitors arrive.</p>
                </div>
              </section>
              <Card className="v2-card">
                <CardContent style={{ padding: 28, textAlign: "center" }}>
                  <div className="v2-empty-icon" style={{ display: "flex", justifyContent: "center", marginBottom: 12 }}><FlaskConical size={28} strokeWidth={2.2} /></div>
                  <p style={{ color: "#6b7385", marginBottom: 18, maxWidth: 440, marginLeft: "auto", marginRight: "auto" }}>
                    Everything else is already available from the sidebar — run a site audit, analyse a competitor, or set up tracking before you create a test.
                  </p>
                  <div style={{ display: "flex", gap: 8, justifyContent: "center", flexWrap: "wrap" }}>
                    <Button onClick={openCreate}><Plus size={16} />Create experiment</Button>
                    <Button variant="ghost" onClick={() => setActiveNav("Website crawler")}><Search size={15} />Run a site audit</Button>
                    <Button variant="ghost" onClick={() => setActiveNav("Tracking setup")}><Code2 size={15} />Tracking setup</Button>
                  </div>
                </CardContent>
              </Card>
            </div>
          ) : activeNav === "Reporting" ? <V2DashboardPage notify={notify} /> : activeNav === "Experiments v2" ? <V2ExperimentsPage notify={notify} /> : activeNav === "Tracking & analytics" ? <V2TrackingPage notify={notify} /> : activeNav === "Integrations" ? <V2IntegrationsPage notify={notify} /> : activeNav === "AI recommendations" ? <V2AiPage notify={notify} /> : activeNav === "Website crawler" ? <V2CrawlerPage notify={notify} /> : activeNav === "Visual analysis" ? <V2VisualPage notify={notify} /> : activeNav === "Competitors" ? <V2CompetitorsPage notify={notify} /> : activeNav === "Ecommerce" ? <V2EcommercePage notify={notify} /> : activeNav === "System health" ? <V2SystemPage notify={notify} /> : activeNav === "Tracking setup" ? <TrackingSetup notify={notify} /> : activeNav === "Insights" ? <PredictiveInsights notify={notify} /> : activeNav === "CRO intelligence" ? <IntelligenceCenter notify={notify} /> : activeNav === "Agent mesh" ? <Orchestration notify={notify} /> : activeNav === "CRO frameworks" ? <FrameworkLibrary notify={notify} /> : activeNav === "Workspace settings" ? <WorkspaceSettings notify={notify} /> : <>
          <V2OnboardingBanner />
          <section className="page-heading">
            <div><div className="overline"><span className="live-pulse" />Live workspace</div><h1>Experiment cockpit</h1><p>Turn visitor behavior into your next confident decision.</p></div>
            <Button className="new-experiment-button" onClick={openCreate}><Plus size={17} />Create experiment</Button>
          </section>

          <section className="onboarding-banner"><div className="onboarding-icon"><Target size={17} /></div><div><strong>Your next step: connect tracking</strong><p>Verify your conversion event before trusting a probability score.</p></div><button onClick={() => setActiveNav("Tracking setup")}>Install tracking <ArrowUpRight size={13} /></button><button className="dismiss-onboarding" aria-label="Dismiss onboarding tip" onClick={event => { (event.currentTarget as HTMLElement).closest(".onboarding-banner")?.classList.add("dismissed"); }}><X size={14} /></button></section>

          <section className="stats-grid">
            <StatCard label="Active experiments" value={String(activeExperiments.length).padStart(2, "0")} icon={<Activity size={20} />} accent="orange" />
            <StatCard label="Visitors in tests" value={formatNumber(totalVisitors)} icon={<Users size={20} />} accent="blue" />
            <StatCard label="Blended conversion" value={blendedRate} icon={<TrendingUp size={20} />} accent="green" />
            <StatCard label="Decision confidence" value={decisionConfidence} icon={<Zap size={20} />} accent="purple" />
          </section>

          <section className="workspace-grid">
            <Card className="experiments-card">
              <CardHeader className="card-header-row">
                <div><CardTitle>Experiments</CardTitle><p className="card-subtitle">Your testing program, at a glance.</p></div>
                <div className="table-actions"><div className="search-field"><Search size={15} /><input placeholder="Search tests" value={searchTerm} onChange={event => setSearchTerm(event.target.value)} /></div><button className="filter-button"><Settings2 size={15} />Filters</button></div>
              </CardHeader>
              <div className="filter-tabs">{(["All", "Active", "Draft", "Paused", "Completed"] as const).map(filter => <button key={filter} className={statusFilter === filter ? "selected" : ""} onClick={() => setStatusFilter(filter)}>{filter}{filter === "Active" ? <span className="filter-count">{experiments.filter(item => item.status === "Active").length}</span> : null}</button>)}</div>
              <CardContent className="table-wrap p-0">
                <table className="experiment-table"><thead><tr><th>Experiment</th><th>Status</th><th>Health</th><th>Primary goal</th><th>Traffic</th><th>Probability</th><th /></tr></thead><tbody>
                  {filteredExperiments.map(experiment => {
                    const control = experiment.variants.find(variant => variant.isControl) ?? experiment.variants[0];
                    const winner = experiment.variants.reduce((best, variant) => predictVariant(variant, control).probabilityToWin > predictVariant(best, control).probabilityToWin ? variant : best, experiment.variants[0]);
                    return <tr key={experiment.id} className={selectedId === experiment.id ? "row-selected" : ""} onClick={() => setSelectedId(experiment.id)}><td><div className="experiment-name-cell"><div className="experiment-icon"><GitBranch size={16} /></div><div><strong>{experiment.name}</strong><span>{experiment.kind} <i /> {experiment.framework}</span></div></div></td><td><StatusPill status={experiment.status} /></td><td><span className="health-chip"><i />{experimentHealth(experiment).label}</span><small className="health-subcopy">{experimentHealth(experiment).sub}</small></td><td><span className="goal-cell">{experiment.goal}</span></td><td><span className="traffic-cell">{experiment.traffic}% <span className="traffic-bar"><span style={{ width: `${experiment.traffic}%` }} /></span></span></td><td><div className="probability-cell"><strong>{predictVariant(winner, control).probabilityToWin}%</strong><span>to win · {formatNumber(experiment.variants.reduce((sum, variant) => sum + variant.visitors, 0))} visitors</span></div></td><td><button className="row-menu" disabled={experiment.status === "Completed"} aria-label={`Actions for ${experiment.name}`} onClick={event => { event.stopPropagation(); updateStatus(experiment.id, experiment.status === "Active" ? "Paused" : "Active"); }}>{experiment.status === "Active" ? <Pause size={15} /> : <Play size={15} />}</button></td></tr>;
                  })}
                </tbody></table>
                {filteredExperiments.length === 0 && <div className="empty-state"><Search size={22} /><strong>No experiments match that filter.</strong><span>Try a different status or search term.</span></div>}
              </CardContent>
              <div className="table-footer"><span>Showing {filteredExperiments.length} of {experiments.length} experiments</span><button onClick={openCreate}>Create another test <Plus size={14} /></button></div>
            </Card>

            <Card className="plan-pulse-card">
              <CardHeader><div className="card-kicker"><Sparkles size={14} />PLAN PULSE</div><CardTitle>{plan} plan capacity</CardTitle><p className="card-subtitle">Stay ahead of your testing limits.</p></CardHeader>
              <CardContent className="plan-content"><div className="capacity-row"><div><span>Experiments</span><strong>{experiments.length} <em>/ {currentLimit.experiments}</em></strong></div><span className="capacity-percent">{Math.round((experiments.length / currentLimit.experiments) * 100)}%</span></div><ProgressBar value={(experiments.length / currentLimit.experiments) * 100} /><div className="capacity-row variant-capacity"><div><span>Max variants / test</span><strong>{selectedExperiment.variants.length} <em>/ {currentLimit.variants}</em></strong></div><span className="capacity-percent">{selectedExperiment.kind === "Multivariate" ? "In use" : "Available"}</span></div><ProgressBar value={(selectedExperiment.variants.length / currentLimit.variants) * 100} color="#5676e8" /><div className="plan-divider" /><div className="included-row"><Check size={15} /><span>Bayesian probability engine</span></div><div className="included-row">{plan === "Free" ? <span className="locked-feature">Upgrade for framework playbooks</span> : <><Check size={15} /><span>Framework playbooks</span></>}</div><div className="included-row">{plan === "Scale" ? <><Check size={15} /><span>Segment-level readouts</span></> : <span className="locked-feature">Scale unlocks segment readouts</span>}</div><button className="manage-plan" onClick={() => { setPlanOpen(true); }}>Manage plan <ArrowUpRight size={14} /></button></CardContent>
            </Card>
          </section>

          <section className="lower-grid">
            <Card className="chart-card">
              <CardHeader className="card-header-row"><div><div className="card-kicker"><Activity size={14} />LIVE SIGNAL</div><CardTitle>{selectedExperiment.name}</CardTitle><p className="card-subtitle">Cumulative conversion rate, last 14 days</p></div><div className="chart-controls"><span className="legend-item"><i className="legend-dot control" />Control</span><span className="legend-item"><i className="legend-dot variant" />{leadVariant?.name ?? "Variant"}</span><button className="period-button">14 days <ChevronDown size={13} /></button></div></CardHeader>
              <CardContent className="chart-content"><div className="chart-summary"><div><span>Current uplift</span><strong>{leadUplift}</strong></div><div><span>Probability to win</span><strong>{leadProbability}%</strong></div><div><span>Visitors</span><strong>{formatNumber(selectedExperiment.variants.reduce((sum, variant) => sum + variant.visitors, 0))}</strong></div></div><div className="chart-area"><ResponsiveContainer width="100%" height={220}><AreaChart data={trendData} margin={{ top: 10, right: 8, left: -20, bottom: 0 }}><defs><linearGradient id="variantFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#e5643c" stopOpacity={0.2} /><stop offset="100%" stopColor="#e5643c" stopOpacity={0} /></linearGradient><linearGradient id="controlFill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#93a0b5" stopOpacity={0.12} /><stop offset="100%" stopColor="#93a0b5" stopOpacity={0} /></linearGradient></defs><CartesianGrid stroke="#e9ebf0" strokeDasharray="4 4" vertical={false} /><XAxis dataKey="day" tick={{ fill: "#8a91a2", fontSize: 11 }} axisLine={false} tickLine={false} /><YAxis domain={[0, "auto"]} tick={{ fill: "#8a91a2", fontSize: 11 }} axisLine={false} tickLine={false} tickFormatter={value => `${value}%`} /><Tooltip contentStyle={{ border: "1px solid #e9ebf0", borderRadius: 10, boxShadow: "0 8px 24px rgba(20, 29, 48, .08)", fontSize: 12 }} formatter={(value: number) => [`${value.toFixed(2)}%`, ""]} /><Area type="monotone" dataKey="control" stroke="#9aa3b4" strokeWidth={2} fill="url(#controlFill)" /><Area type="monotone" dataKey="variant" stroke="#e5643c" strokeWidth={2.5} fill="url(#variantFill)" /></AreaChart></ResponsiveContainer></div></CardContent>
            </Card>

            <BayesianReadout experiment={selectedExperiment} notify={notify} />
          </section>

          <section className="framework-strip"><div className="framework-copy"><span className="framework-icon"><Layers3 size={18} /></span><div><strong>Make your next test sharper</strong><p>Use proven CRO frameworks to write a hypothesis your team can actually learn from.</p></div></div><div className="framework-actions"><span>MECLABS</span><span>LIFT</span><span>PXL</span><span>COM-B</span><span>AARRR</span><button onClick={() => setActiveNav("CRO frameworks")}>Browse playbooks <ArrowUpRight size={14} /></button></div></section>
          </>}
        </div>
      </main>

      {isCreateOpen && <CreateExperimentModal plan={plan} limit={currentLimit} onClose={() => setCreateOpen(false)} onCreated={handleCreated} onUpgrade={() => { setCreateOpen(false); changePlan("Scale"); notify("Scale plan preview enabled."); }} />}
      {toast && <div className="toast"><Check size={15} />{toast}</div>}
    </div>
  );
}

const frameworkGuidance: Record<string, { lens: string; prompt: string; metric: string }> = {
  "MECLABS heuristic": { lens: "Find the largest conversion friction or motivation gap.", prompt: "Because visitors hesitate at [friction], changing [experience] should improve [goal] by making the value clearer.", metric: "Conversion rate and form abandonment" },
  "LIFT Model": { lens: "Balance value proposition, relevance, clarity, urgency, anxiety, and distraction.", prompt: "Reducing [anxiety/distraction] while clarifying [value] should improve [goal] for [audience].", metric: "Conversion rate and interaction depth" },
  "Cialdini Persuasion Principles": { lens: "Use a specific persuasion lever such as social proof, authority, scarcity, or reciprocity.", prompt: "Adding [persuasion principle] should increase [goal] because visitors need [trust cue].", metric: "Conversion rate and trust-signal engagement" },
  "PIE Framework": { lens: "Prioritize potential, importance, and ease before investing in the test.", prompt: "This test is worth running because its potential is [score], importance is [score], and ease is [score].", metric: "Expected uplift, reach, and implementation effort" },
  "PXL Framework": { lens: "Prioritize evidence-backed changes over subjective opinions.", prompt: "Because [research evidence] shows [pattern], changing [element] should improve [goal].", metric: "Conversion rate and evidence confidence" },
  "COM-B Model": { lens: "Diagnose capability, opportunity, and motivation barriers to action.", prompt: "If we improve [capability/opportunity/motivation] for [audience], more visitors will [behavior].", metric: "Task completion and conversion rate" },
  "HEART": { lens: "Choose a UX outcome: happiness, engagement, adoption, retention, or task success.", prompt: "Improving [UX experience] should improve [HEART outcome] for [audience].", metric: "Selected HEART metric plus conversion" },
  "Bayesian testing": { lens: "Make a decision from an updating probability and credible interval.", prompt: "We will continue until the probability to beat control and data maturity support a decision.", metric: "Probability to win and credible interval" },
  "Jobs to be Done": { lens: "Test the job visitors are hiring the experience to accomplish.", prompt: "When [situation], visitors need to [job], so changing [experience] should improve [goal].", metric: "Job completion and conversion rate" },
};

function CreateExperimentModal({ plan, limit, onClose, onCreated, onUpgrade }: { plan: Plan; limit: { experiments: number; variants: number; copy: string }; onClose: () => void; onCreated: (experiment: Experiment) => void; onUpgrade: () => void }) {
  const [step, setStep] = useState(1);
  const [kind, setKind] = useState<ExperimentKind>("A/B test");
  const [name, setName] = useState("");
  const [pageUrl, setPageUrl] = useState("");
  const [urlChecked, setUrlChecked] = useState(false);
  const [urlError, setUrlError] = useState("");
  const [objective, setObjective] = useState("");
  const [hypothesis, setHypothesis] = useState("");
  const [observedInsight, setObservedInsight] = useState("");
  const [experienceChange, setExperienceChange] = useState("");
  const [behaviorReason, setBehaviorReason] = useState("");
  const [goal, setGoal] = useState("Trial starts");
  const [audience, setAudience] = useState("All eligible visitors");
  const [framework, setFramework] = useState("MECLABS heuristic");
  const [conversionEvent, setConversionEvent] = useState("Click on primary CTA");
  const [customEvent, setCustomEvent] = useState("");
  const [variantCount, setVariantCount] = useState(2);
  const [variantNames, setVariantNames] = useState(["Control", "New experience"]);
  const [traffic, setTraffic] = useState(100);

  const canUseMulti = plan !== "Free";
  const maxVariantCount = limit.variants;
  const activeEvent = conversionEvent === "Custom event" ? customEvent.trim() || "Custom conversion event" : conversionEvent;
  const expectedLift = 15;
  const estimatedVisitors = Math.max(1200, Math.round(16000 / Math.max(expectedLift, 1)));
  const estimatedDays = Math.max(3, Math.ceil(estimatedVisitors / 850));
  const guidance = frameworkGuidance[framework] ?? { lens: "Use this lens to connect research to a measurable visitor action.", prompt: "Because [insight], changing [experience] for [audience] should improve [goal].", metric: "Primary conversion goal and supporting behavior" };

  const updateVariantCount = (next: number) => {
    const safeCount = Math.max(2, Math.min(maxVariantCount, next));
    setVariantCount(safeCount);
    setVariantNames(current => Array.from({ length: safeCount }, (_, index) => current[index] ?? (index === 0 ? "Control" : `Variant ${String.fromCharCode(65 + index)}`)));
  };

  const updateVariantName = (index: number, value: string) => {
    setVariantNames(current => current.map((variant, variantIndex) => variantIndex === index ? value : variant));
  };

  // Returns the validation result directly. It used to only call setUrlChecked()
  // and the caller then re-read `urlChecked` from the same render closure, which
  // is still the OLD value (React state updates are async) — so "Continue to
  // variants" always bailed out and the wizard never advanced past step 1.
  const checkPage = (): boolean => {
    const value = pageUrl.trim();
    try {
      const parsed = new URL(value);
      if (!/^https?:$/.test(parsed.protocol)) throw new Error("protocol");
      setUrlError("");
      setUrlChecked(true);
      return true;
    } catch {
      setUrlChecked(false);
      setUrlError("Enter a valid page URL beginning with https://");
      return false;
    }
  };

  const buildHypothesis = () => {
    const audienceText = audience.toLowerCase();
    const goalText = goal.toLowerCase();
    const frameworkText = framework === "Bayesian testing" ? "using an evidence-led decision threshold" : `using the ${framework} lens`;
    const suggestion = observedInsight.trim() && experienceChange.trim() && behaviorReason.trim()
      ? `Because ${observedInsight.trim()}, changing ${experienceChange.trim()} for ${audienceText} should improve ${goalText}, measured by ${activeEvent.toLowerCase()}, because ${behaviorReason.trim()}, ${frameworkText}.`
      : `Because ${audienceText} may hesitate before ${goalText}, changing the experience should improve ${goalText} by making the value clearer and reducing friction, ${frameworkText}.`;
    return suggestion.slice(0, 240);
  };

  const generateHypothesis = () => {
    setHypothesis(buildHypothesis());
  };

  const goToVariants = () => {
    if (!name.trim()) return;
    // Validate synchronously and use the returned value, not the stale state.
    if (!checkPage()) return;
    // Same stale-state trap: setHypothesis() would not be visible to the
    // `hypothesis` variable in this closure, so compute the text directly.
    if (!hypothesis.trim()) setHypothesis(buildHypothesis());
    setStep(2);
  };

  const handleCreate = () => {
    const colors = ["#8d96a8", "#e5643c", "#f2aa4f", "#5676e8", "#6eaf75", "#9a70cf", "#4a9a9a", "#d477a0"];
    onCreated({
      id: Date.now(),
      name: name.trim() || "Untitled experiment",
      kind,
      status: "Draft",
      owner: "You",
      updated: "Just now",
      goal,
      framework,
      pageUrl: pageUrl.trim(),
      objective: objective.trim() || "Validate a higher-converting experience",
      hypothesis,
      observedInsight: observedInsight.trim(),
      experienceChange: experienceChange.trim(),
      behaviorReason: behaviorReason.trim(),
      audience,
      conversionEvent: activeEvent,
      traffic,
      variants: variantNames.slice(0, kind === "A/B test" ? 2 : variantCount).map((variantName, index) => ({
        name: variantName.trim() || (index === 0 ? "Control" : `Variant ${index + 1}`),
        visitors: 0,
        conversions: 0,
        rate: 0,
        uplift: 0,
        probability: index === 0 ? 50 : 0,
        color: colors[index],
        isControl: index === 0,
      })),
    });
  };

  return (
    <div className="modal-backdrop" role="presentation" onMouseDown={event => { if (event.target === event.currentTarget) onClose(); }}>
      <div className="create-modal" role="dialog" aria-modal="true" aria-labelledby="create-heading">
        <div className="modal-header">
          <div><div className="card-kicker"><Sparkles size={14} />GUIDED SETUP</div><h2 id="create-heading">Create an experiment</h2><p>Build a clean brief, configure the signal, then review before launch.</p></div>
          <button className="close-button" onClick={onClose} aria-label="Close"><X size={18} /></button>
        </div>
        <div className="modal-body">
          <div className="step-line">
            {[{ number: 1, label: "Test brief" }, { number: 2, label: "Variants & signal" }, { number: 3, label: "Review" }].map((item, index) => <span key={item.number} className="step-group"><button className={`step ${step >= item.number ? "active" : ""}`} onClick={() => item.number < step ? setStep(item.number) : undefined}>{item.number}</button><span className={`step-label ${step >= item.number ? "active" : ""}`}>{item.label}</span>{index < 2 ? <i /> : null}</span>)}
          </div>

          {step === 1 && <div className="wizard-step">
            <label className="field-label">Experiment name<input value={name} onChange={event => setName(event.target.value)} placeholder="e.g. Pricing page headline" autoFocus /></label>
            <label className="field-label">Test page URL<div className="url-input-row"><input type="url" value={pageUrl} onChange={event => { setPageUrl(event.target.value); setUrlChecked(false); setUrlError(""); }} placeholder="https://yourwebsite.com/pricing" /><Button type="button" variant="outline" size="sm" onClick={checkPage}>Check page</Button></div>{urlChecked ? <span className="field-success"><Check size={12} />Page URL looks valid and is ready for tracking.</span> : <span className="field-hint">Add the page where this experiment will run.</span>}{urlError && <span className="field-error">{urlError}</span>}</label>
            <div className="field-grid"><label className="field-label">Primary conversion goal<select value={goal} onChange={event => setGoal(event.target.value)}><option>Trial starts</option><option>Completed checkout</option><option>Activated workspaces</option><option>Demo requests</option></select></label><label className="field-label">Target audience<select value={audience} onChange={event => setAudience(event.target.value)}><option>All eligible visitors</option><option>New visitors only</option><option>Returning visitors</option><option>Mobile visitors</option></select></label></div>
            <label className="field-label">Objective<input value={objective} onChange={event => setObjective(event.target.value)} placeholder="What decision should this test help you make?" /></label>
            <div className="hypothesis-input-grid"><label className="field-label">Observed insight<input value={observedInsight} onChange={event => setObservedInsight(event.target.value)} placeholder="e.g. Visitors miss the pricing comparison" /></label><label className="field-label">Experience change<input value={experienceChange} onChange={event => setExperienceChange(event.target.value)} placeholder="e.g. Add a comparison summary" /></label></div>
            <label className="field-label">Behavioral reason<input value={behaviorReason} onChange={event => setBehaviorReason(event.target.value)} placeholder="Why should this change affect visitor behavior?" /></label>
            <label className="field-label"><span className="field-label-row"><span>Hypothesis</span><button type="button" className="generate-hypothesis" onClick={generateHypothesis}><Sparkles size={12} />Generate hypothesis</button></span><textarea value={hypothesis} onChange={event => setHypothesis(event.target.value)} placeholder="Because [insight], changing [experience] for [audience] will improve [goal] because [reason]." rows={3} /><span className="field-hint">{hypothesis.length}/240 · Success metric: {goal} via {activeEvent}. Add the three inputs above for a research-grounded hypothesis.</span></label>
            <div className="wizard-footer"><span className="save-note"><Clock3 size={14} />You can save this as a draft at any time</span><Button onClick={goToVariants} disabled={!name.trim() || !pageUrl.trim()}>Continue to variants <ArrowUpRight size={15} /></Button></div>
          </div>}

          {step === 2 && <div className="wizard-step">
            <div className="field-grid"><label className="field-label">Test type<div className="segmented-control"><button className={kind === "A/B test" ? "selected" : ""} onClick={() => setKind("A/B test")}>A/B test</button><button className={`${kind === "Multivariate" ? "selected" : ""} ${!canUseMulti ? "locked" : ""}`} onClick={() => canUseMulti ? setKind("Multivariate") : onUpgrade()}>Multivariate {!canUseMulti && <span>Scale</span>}</button></div></label><label className="field-label">CRO framework<select value={framework} onChange={event => setFramework(event.target.value)}><option>MECLABS heuristic</option><option>Bayesian testing</option><option>ICE prioritization</option><option>Fogg Behavior</option><option>HEART</option><option>AIDA</option><option>Jobs to be Done</option><option>LIFT Model</option><option>PXL Framework</option><option>PIE Framework</option><option>RICE Scoring</option><option>COM-B Model</option><option>Hook Model</option><option>Nielsen Usability Heuristics</option><option>5C Analysis</option><option>7C Conversion Framework</option><option>Cialdini Persuasion Principles</option><option>Customer Journey Mapping</option><option>Value Proposition Canvas</option><option>AARRR Funnel</option><option>EAST Framework</option></select></label></div>
            <div className="framework-guidance"><div className="framework-guidance-icon"><Layers3 size={15} /></div><div><strong>{framework} lens</strong><p>{guidance.lens}</p><small><b>Hypothesis starter:</b> {guidance.prompt}</small><small><b>Measure:</b> {guidance.metric}</small></div></div>
            {kind === "Multivariate" && <div className="variant-control"><div><label className="field-label">Number of variants</label><p>{plan} supports up to {limit.variants}. Control is included automatically.</p></div><div className="number-stepper"><button onClick={() => updateVariantCount(variantCount - 1)}>−</button><strong>{variantCount}</strong><button onClick={() => updateVariantCount(variantCount + 1)}>+</button></div></div>}
            <div className="variant-editor"><div className="variant-editor-heading"><div><strong>Name your experiences</strong><p>Keep the control unchanged and describe what each challenger changes.</p></div><Badge variant="outline">{kind === "A/B test" ? 2 : variantCount} variants</Badge></div>{variantNames.slice(0, kind === "A/B test" ? 2 : variantCount).map((variant, index) => <div className="variant-edit-row" key={index}><span className="variant-edit-dot" style={{ background: ["#8d96a8", "#e5643c", "#f2aa4f", "#5676e8", "#6eaf75"][index] }} /><input value={variant} onChange={event => updateVariantName(index, event.target.value)} /><Badge variant="outline">{index === 0 ? "control" : `variant ${index}`}</Badge></div>)}</div>
            <div className="allocation-section"><div><label className="field-label">Traffic allocation</label><p>Eligible visitors entering this experiment.</p></div><div className="allocation-control"><input type="range" min="10" max="100" value={traffic} onChange={event => setTraffic(Number(event.target.value))} /><strong>{traffic}%</strong></div></div>
            <div className="field-grid"><label className="field-label">Conversion event<select value={conversionEvent} onChange={event => setConversionEvent(event.target.value)}><option>Click on primary CTA</option><option>Form submission</option><option>Purchase completed</option><option>Signup completed</option><option>Page viewed</option><option>Custom event</option></select></label>{conversionEvent === "Custom event" ? <label className="field-label">Event name<input value={customEvent} onChange={event => setCustomEvent(event.target.value)} placeholder="e.g. onboarding_completed" /></label> : <div className="event-helper"><Check size={15} /><span>We will count this event as a conversion for every variant.</span></div>}</div>
            <div className="sample-size-card"><div className="sample-size-icon"><BarChart3 size={17} /></div><div><strong>Plan your read</strong><p>At a {expectedLift}% expected improvement, collect about <b>{estimatedVisitors.toLocaleString()} visitors</b> for roughly <b>{estimatedDays} days</b> before making a decision.</p></div></div>
            <div className="wizard-footer"><Button variant="ghost" onClick={() => setStep(1)}>Back</Button><Button onClick={() => setStep(3)}>Review experiment <ArrowUpRight size={15} /></Button></div>
          </div>}

          {step === 3 && <div className="wizard-step">
            <div className="review-banner"><div className="review-icon"><Check size={18} /></div><div><strong>Your test brief is ready</strong><p>Review the details below before saving the experiment.</p></div></div>
            <div className="review-grid"><div><span>Experiment</span><strong>{name || "Untitled experiment"}</strong></div><div><span>Page</span><strong className="review-url">{pageUrl}</strong></div><div><span>Goal</span><strong>{goal}</strong></div><div><span>Conversion event</span><strong>{activeEvent}</strong></div><div><span>Audience</span><strong>{audience}</strong></div><div><span>Framework</span><strong>{framework}</strong></div></div>
            <div className="review-hypothesis"><span>Hypothesis</span><p>{hypothesis || "No hypothesis added yet. You can edit this draft after creating it."}</p></div>
            <div className="review-variants"><div className="variant-editor-heading"><div><strong>Variants & allocation</strong><p>{traffic}% of eligible traffic · estimated {estimatedVisitors.toLocaleString()} visitors needed</p></div></div>{variantNames.slice(0, kind === "A/B test" ? 2 : variantCount).map((variant, index) => <div className="review-variant-row" key={index}><span className="variant-edit-dot" style={{ background: ["#8d96a8", "#e5643c", "#f2aa4f", "#5676e8", "#6eaf75"][index] }} /><strong>{variant || `Variant ${index + 1}`}</strong><span>{index === 0 ? "Control" : `${Math.round(traffic / (kind === "A/B test" ? 2 : variantCount))}% traffic share`}</span></div>)}</div>
            <div className="wizard-footer"><Button variant="ghost" onClick={() => setStep(2)}>Back</Button><div className="review-actions"><Button variant="outline" onClick={onClose}>Save for later</Button><Button onClick={handleCreate}><Plus size={15} />Create draft</Button></div></div>
          </div>}
        </div>
      </div>
    </div>
  );
}
