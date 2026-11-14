"""Deterministic analysis for the five SignalForge agents.

Every figure produced here is computed from the dataset the PHP backend sends --
live rows from experiments / variants / visitor_events. The language model never
invents a number: it only writes the narrative around figures computed in this
module. That split is what keeps the output trustworthy when the model is wrong.

The maths mirrors infinityfree/api/orchestrator.php so the LangGraph engine and the
local PHP engine report identical numbers for the same data.
"""
from __future__ import annotations

import math
import time
from typing import Any


def _f(value: Any, default: float = 0.0) -> float:
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def _i(value: Any, default: int = 0) -> int:
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def normal_cdf(value: float) -> float:
    """Abramowitz-Stegun approximation, matching the PHP implementation."""
    sign = -1.0 if value < 0 else 1.0
    x = abs(value) / math.sqrt(2.0)
    t = 1.0 / (1.0 + 0.3275911 * x)
    poly = (
        ((((1.061405429 * t - 1.453152027) * t) + 1.421413741) * t - 0.284496736) * t
        + 0.254829592
    ) * t
    return 0.5 * (1.0 + sign * (1.0 - poly * math.exp(-x * x)))


def posterior(visitors: float, conversions: float) -> tuple[float, float]:
    alpha = conversions + 1.0
    beta = max(visitors - conversions, 0.0) + 1.0
    total = alpha + beta
    return alpha / total, (alpha * beta) / (total * total * (total + 1.0))


def prob_to_beat(v_visitors: float, v_conversions: float, c_visitors: float, c_conversions: float) -> float:
    mean_a, var_a = posterior(v_visitors, v_conversions)
    mean_b, var_b = posterior(c_visitors, c_conversions)
    se = math.sqrt(var_a + var_b)
    z = (mean_a - mean_b) / se if se > 0 else 0.0
    return max(1.0, min(99.0, normal_cdf(z) * 100.0))


def _fmt(n: float) -> str:
    return f"{int(round(n)):,}"


def _signed(value: float) -> str:
    return f"{value:+.1f}%"


# ------------------------------------------------------------------ dataset access


def experiments(data: dict[str, Any]) -> list[dict[str, Any]]:
    rows = data.get("experiments") or []
    return [r for r in rows if isinstance(r, dict)]


def variants_for(data: dict[str, Any], experiment_id: Any) -> list[dict[str, Any]]:
    """PHP encodes the per-experiment map with string keys, so normalise both sides."""
    table = data.get("variants") or {}
    if not isinstance(table, dict):
        return []
    rows = table.get(str(experiment_id))
    if rows is None:
        rows = table.get(experiment_id)  # type: ignore[arg-type]
    if not isinstance(rows, list):
        return []
    return [r for r in rows if isinstance(r, dict)]


def control_of(variants: list[dict[str, Any]]) -> dict[str, Any] | None:
    for variant in variants:
        if _i(variant.get("isControl")) == 1:
            return variant
    return variants[0] if variants else None


def lead_of(variants: list[dict[str, Any]]) -> dict[str, Any] | None:
    control = control_of(variants)
    lead = None
    for variant in variants:
        if control is not None and _i(variant.get("id")) == _i(control.get("id")):
            continue
        if lead is None or _i(variant.get("probability")) > _i(lead.get("probability")):
            lead = variant
    return lead


def total_visitors(variants: list[dict[str, Any]]) -> int:
    return sum(_i(v.get("visitors")) for v in variants)


def _empty(headline: str, metrics: list[dict[str, str]], findings: list[str], recommendation: str, tone: str = "info") -> dict[str, Any]:
    return {
        "headline": headline,
        "metrics": metrics,
        "findings": findings,
        "recommendation": recommendation,
        "tone": tone,
    }


# ------------------------------------------------------------------ agents


def signal_analyst(data: dict[str, Any]) -> dict[str, Any]:
    analysed = decisive = contested = 0
    best: dict[str, Any] | None = None
    findings: list[str] = []

    for experiment in experiments(data):
        variants = variants_for(data, experiment.get("id"))
        if not variants:
            continue
        control = control_of(variants)
        if control is None or _i(control.get("visitors")) <= 0:
            continue
        lead = lead_of(variants)
        if lead is None:
            continue
        analysed += 1
        # Prefer the probability/uplift the PHP side precomputed, but never trust
        # their absence: a caller that sends only raw visitors/conversions would
        # otherwise be reported as "0% probability, +0.0% uplift" on data that
        # clearly shows a winner. Recompute from the counts in that case.
        if lead.get("probability") is None or lead.get("uplift") is None:
            control_visitors = _i(control.get("visitors"))
            control_conversions = _i(control.get("conversions"))
            lead_visitors = _i(lead.get("visitors"))
            lead_conversions = _i(lead.get("conversions"))
            control_rate = control_conversions / control_visitors if control_visitors > 0 else 0.0
            lead_rate = lead_conversions / lead_visitors if lead_visitors > 0 else 0.0
            if lead.get("uplift") is None:
                uplift = ((lead_rate - control_rate) / control_rate) * 100.0 if control_rate > 0 else 0.0
            else:
                uplift = _f(lead.get("uplift"))
            if lead.get("probability") is None:
                probability = int(round(prob_to_beat(lead_visitors, lead_conversions, control_visitors, control_conversions)))
            else:
                probability = _i(lead.get("probability"))
        else:
            probability = _i(lead.get("probability"))
            uplift = _f(lead.get("uplift"))
        if probability >= 90:
            decisive += 1
        elif probability >= 60:
            contested += 1
        if best is None or uplift > _f(best.get("uplift")):
            best = {
                "name": experiment.get("name"),
                "variant": lead.get("name"),
                "uplift": uplift,
                "probability": probability,
            }
        findings.append(
            f"{experiment.get('name')}: {lead.get('name')} at {probability}% probability, "
            f"{_signed(uplift)} uplift on {_fmt(total_visitors(variants))} visitors."
        )

    if analysed == 0:
        return _empty(
            "No experiment has enough traffic to score yet",
            [
                {"label": "Experiments scored", "value": "0"},
                {"label": "Decisive signals", "value": "0"},
                {"label": "Best uplift", "value": "\u2014"},
            ],
            ["Activate an experiment and let the tracker collect exposures before this agent can score a signal."],
            "Install the tracking snippet, then launch one A/B test so the analyst has exposures to read.",
        )

    if decisive > 0:
        headline = f"{decisive} experiment{' carries' if decisive == 1 else 's carry'} a decisive signal"
    elif contested > 0:
        headline = f"{contested} experiment{' is' if contested == 1 else 's are'} still contested"
    else:
        headline = "Every scored experiment is still inconclusive"

    if best is not None:
        recommendation = (
            f"Strongest read: {best['variant']} in \"{best['name']}\" at {best['probability']}% probability. "
            + ("Roll it out and start the next test." if best["probability"] >= 90 else "Keep collecting before you commit.")
        )
    else:
        recommendation = "Keep the current tests running."

    return _empty(
        headline,
        [
            {"label": "Experiments scored", "value": str(analysed)},
            {"label": "Decisive signals", "value": str(decisive)},
            {"label": "Best uplift", "value": _signed(_f(best.get("uplift"))) if best else "\u2014"},
        ],
        findings,
        recommendation,
        "good" if decisive > 0 else ("warn" if contested > 0 else "info"),
    )


def segment_scout(data: dict[str, Any]) -> dict[str, Any]:
    labels = ["All visitors", "Mobile visitors", "Desktop visitors", "Paid traffic", "Returning visitors"]
    buckets = {label: {"v": 0, "x": 0, "lv": 0, "lx": 0} for label in labels}

    for experiment in experiments(data):
        experiment_id = experiment.get("id")
        variants = variants_for(data, experiment_id)
        if not variants:
            continue
        control = control_of(variants)
        lead = lead_of(variants)
        if control is None or lead is None:
            continue
        for row in data.get("segments") or []:
            if not isinstance(row, dict):
                continue
            if _i(row.get("experimentId")) != _i(experiment_id):
                continue
            variant_id = _i(row.get("variantId"))
            is_control = variant_id == _i(control.get("id"))
            is_lead = variant_id == _i(lead.get("id"))
            if not is_control and not is_lead:
                continue
            n = _i(row.get("n"))
            is_conversion = row.get("type") == "conversion"
            matches = ["All visitors"]
            if row.get("device") == "Mobile":
                matches.append("Mobile visitors")
            if row.get("device") == "Desktop":
                matches.append("Desktop visitors")
            if _i(row.get("isPaid")) == 1:
                matches.append("Paid traffic")
            if _i(row.get("isReturning")) == 1:
                matches.append("Returning visitors")
            for label in matches:
                bucket = buckets[label]
                if is_control:
                    bucket["x" if is_conversion else "v"] += n
                else:
                    bucket["lx" if is_conversion else "lv"] += n

    rows: list[dict[str, Any]] = []
    best: dict[str, Any] | None = None
    for label in labels:
        b = buckets[label]
        control_rate = b["x"] / b["v"] if b["v"] > 0 else 0.0
        lead_rate = b["lx"] / b["lv"] if b["lv"] > 0 else 0.0
        lift = ((lead_rate - control_rate) / control_rate) * 100.0 if control_rate > 0 else 0.0
        visitors = b["v"] + b["lv"]
        signal = "Strong" if visitors >= 200 else ("Moderate" if visitors >= 50 else ("Early" if visitors >= 10 else "Inconclusive"))
        rows.append({"segment": label, "lift": round(lift, 1), "signal": signal, "visitors": visitors, "controlRate": control_rate})
        if label != "All visitors" and visitors >= 10 and control_rate > 0:
            if best is None or lift > _f(best.get("lift")):
                best = {"segment": label, "lift": round(lift, 1), "visitors": visitors, "signal": signal}

    findings: list[str] = []
    for row in rows:
        if row["segment"] == "All visitors":
            continue
        if row["controlRate"] <= 0:
            findings.append(
                f"{row['segment']}: {_fmt(row['visitors'])} visitors, but the control has no conversions here yet \u2014 no lift to report."
            )
            continue
        findings.append(
            f"{row['segment']}: {_signed(row['lift'])} lift across {_fmt(row['visitors'])} visitors ({row['signal'].lower()} signal)."
        )

    if best is None:
        # Keep the per-segment detail: "no segment qualifies" is itself a finding, and
        # dropping the rows would hide why (e.g. the control has no conversions yet).
        detail = findings or ["No segment has any exposures yet."]
        return _empty(
            "Not enough segment traffic to compare audiences",
            [
                {"label": "Segments read", "value": str(len(rows))},
                {"label": "Strongest segment", "value": "\u2014"},
                {"label": "Segment lift", "value": "\u2014"},
            ],
            detail
            + ["Each segment needs at least 10 visitors and at least one control conversion before a lift figure means anything."],
            "Let the active test collect more exposures, then re-run this agent.",
        )

    return _empty(
        f"{best['segment']} shows the strongest response at {_signed(_f(best['lift']))}",
        [
            {"label": "Segments read", "value": str(len(rows))},
            {"label": "Strongest segment", "value": str(best["segment"])},
            {"label": "Segment lift", "value": _signed(_f(best["lift"]))},
        ],
        findings,
        f"Target {str(best['segment']).lower()} first: the variant moves them "
        f"{'up' if _f(best['lift']) >= 0 else 'down'} by {abs(_f(best['lift']))}% on {_fmt(best['visitors'])} visitors.",
        "good" if _f(best["lift"]) > 0 else "warn",
    )


def tracking_auditor(data: dict[str, Any]) -> dict[str, Any]:
    issues: list[str] = []
    score = 100
    last_ts = data.get("lastEventTs")

    if last_ts is None:
        issues.append("No tracking event has ever been received for this workspace.")
        score -= 60
    else:
        age_hours = (time.time() - _f(last_ts)) / 3600.0
        if age_hours > 48:
            issues.append(f"Last event arrived {_ago(_f(last_ts))} \u2014 the snippet may have been removed.")
            score -= 35
        elif age_hours > 24:
            issues.append(f"No event in the last 24 hours (last seen {_ago(_f(last_ts))}).")
            score -= 15

    active = starved = mismatched = 0
    for experiment in experiments(data):
        if experiment.get("status") != "Active":
            continue
        active += 1
        variants = variants_for(data, experiment.get("id"))
        if not variants:
            continue
        visitors = total_visitors(variants)
        conversions = sum(_i(v.get("conversions")) for v in variants)
        counts = [_i(v.get("visitors")) for v in variants]
        if visitors >= 50 and conversions == 0:
            starved += 1
            issues.append(
                f"\"{experiment.get('name')}\" has {_fmt(visitors)} visitors but zero conversions \u2014 check the conversion event."
            )
            score -= 12
        if counts and min(counts) > 0 and max(counts) / min(counts) > 1.5:
            mismatched += 1
            issues.append(
                f"\"{experiment.get('name')}\" traffic split is uneven ({max(counts)} vs {min(counts)} exposures)."
            )
            score -= 10

    if active == 0:
        issues.append("No experiment is currently active, so no new data is being collected.")
        score -= 10
    if not issues:
        issues.append("Event flow, conversion coverage and traffic split all look healthy.")
    score = max(0, min(100, score))

    if score >= 85:
        headline, tone = "Tracking pipeline is healthy", "good"
    elif score >= 60:
        headline, tone = "Tracking needs attention", "warn"
    else:
        headline, tone = "Tracking is broken or idle", "bad"

    if score >= 85:
        recommendation = "Leave instrumentation alone and keep collecting. Re-audit after the next experiment launches."
    elif starved > 0:
        recommendation = "Fix the conversion event on the starved experiment before reading any probability score."
    else:
        recommendation = "Re-check the snippet install and confirm events are still arriving."

    return _empty(
        headline,
        [
            {"label": "Health score", "value": f"{score}/100"},
            {"label": "Events (24h)", "value": _fmt(_i(data.get("events24h")))},
            {"label": "Active tests", "value": str(active)},
        ],
        issues,
        recommendation,
        tone,
    )


def _ago(timestamp: float) -> str:
    minutes = max(0, int((time.time() - timestamp) / 60))
    if minutes < 1:
        return "just now"
    if minutes < 60:
        return f"{minutes} min ago"
    hours = minutes // 60
    if hours < 24:
        return f"{hours} hr ago"
    if hours < 48:
        return "yesterday"
    return time.strftime("%b %d", time.gmtime(timestamp))


def sample_planner(data: dict[str, Any]) -> dict[str, Any]:
    rows: list[dict[str, Any]] = []
    findings: list[str] = []
    total_needed = 0
    slowest: dict[str, Any] | None = None
    exposures_7d = _i(data.get("exposures7d"))
    daily = exposures_7d / 7.0 if exposures_7d > 0 else 0.0

    for experiment in experiments(data):
        if experiment.get("status") != "Active":
            continue
        variants = variants_for(data, experiment.get("id"))
        if not variants:
            continue
        control = control_of(variants)
        if control is None:
            continue
        control_visitors = _i(control.get("visitors"))
        baseline = _i(control.get("conversions")) / control_visitors if control_visitors > 0 else 0.0
        if baseline <= 0:
            baseline = 0.05
        delta = baseline * 0.15
        needed_per_variant = int(math.ceil(16 * baseline * (1 - baseline) / (delta * delta)))
        needed_per_variant = max(200, min(200000, needed_per_variant))
        needed_total = needed_per_variant * len(variants)
        have = total_visitors(variants)
        remaining = max(0, needed_total - have)
        total_needed += remaining
        days = int(math.ceil(remaining / daily)) if daily > 0 else None

        rows.append({"experiment": experiment.get("name"), "have": have, "needed": needed_total, "remaining": remaining, "days": days})
        tail = (
            f" \u2014 {_fmt(remaining)} to go" + (f" (~{days} days at the current rate)" if days is not None else "")
            if remaining > 0
            else " \u2014 sample target met"
        )
        findings.append(f"{experiment.get('name')}: {_fmt(have)} of {_fmt(needed_total)} visitors{tail}.")
        if slowest is None or (days is not None and (slowest.get("days") is None or days > slowest["days"])):
            slowest = {"name": experiment.get("name"), "days": days}

    if not rows:
        return _empty(
            "No active experiment to plan a sample for",
            [
                {"label": "Active tests", "value": "0"},
                {"label": "Visitors still needed", "value": "\u2014"},
                {"label": "Longest runway", "value": "\u2014"},
            ],
            ["Sample planning only covers experiments in the Active state."],
            "Activate a test, then re-run the planner to get a visitor target and a day estimate.",
        )

    headline = (
        f"{_fmt(total_needed)} more visitors needed across {len(rows)} active test{'s' if len(rows) != 1 else ''}"
        if total_needed > 0
        else "Every active test has met its sample target"
    )
    runway = f"{slowest['days']} days" if slowest and slowest.get("days") is not None else "\u2014"
    recommendation = (
        f"Hold decisions until the sample target is met; the slowest test is \"{slowest['name'] if slowest else 'unknown'}\"."
        if total_needed > 0
        else "Samples are mature \u2014 move to a decision on the leading variant."
    )

    return _empty(
        headline,
        [
            {"label": "Active tests", "value": str(len(rows))},
            {"label": "Visitors still needed", "value": _fmt(total_needed)},
            {"label": "Longest runway", "value": runway},
        ],
        findings,
        recommendation,
        "warn" if total_needed > 0 else "good",
    )


def brief_writer(data: dict[str, Any]) -> dict[str, Any]:
    candidate: dict[str, Any] | None = None
    candidate_score: float | None = None

    for experiment in experiments(data):
        variants = variants_for(data, experiment.get("id"))
        visitors = total_visitors(variants)
        lead = lead_of(variants) if variants else None
        probability = _i(lead.get("probability")) if lead else 50
        score = (1000 if experiment.get("status") == "Active" else 0) + (100 - abs(probability - 50)) - min(visitors, 500) / 10.0
        if candidate_score is None or score > candidate_score:
            candidate = experiment
            candidate_score = score

    if candidate is None:
        return _empty(
            "No experiment to build a brief from",
            [
                {"label": "Briefs drafted", "value": "0"},
                {"label": "Framework", "value": "\u2014"},
                {"label": "Target page", "value": "\u2014"},
            ],
            ["A brief needs at least one experiment with a hypothesis and a target page."],
            "Create an experiment first; the writer will then turn its weakest signal into the next test.",
        )

    variants = variants_for(data, candidate.get("id"))
    lead = lead_of(variants) if variants else None
    framework = candidate.get("framework") or "MECLABS heuristic"
    page = candidate.get("pageUrl") or "the target page"
    goal = candidate.get("goal") or "the primary conversion"
    audience = candidate.get("audience") or "all eligible visitors"
    insight = candidate.get("observedInsight") or f"visitors hesitate before {goal}"
    change = candidate.get("experienceChange") or "clarify the value proposition above the fold"
    reason = candidate.get("behaviorReason") or "clearer value reduces the effort of deciding"

    hypothesis = (
        f"Because {_lcfirst(insight)}, changing the experience to {_lcfirst(change)} for {str(audience).lower()} "
        f"should improve {str(goal).lower()} because {_lcfirst(reason)}, using the {framework} lens."
    )

    return _empty(
        f"Next brief drafted for \"{candidate.get('name')}\"",
        [
            {"label": "Framework", "value": str(framework)},
            {"label": "Target page", "value": str(page)},
            {"label": "Current probability", "value": f"{_i(lead.get('probability')) if lead else 50}%"},
        ],
        [
            f"Hypothesis: {hypothesis}",
            f"Primary metric: {goal} on {page}.",
            f"Audience: {audience}.",
        ],
        f"Run this as the next test once \"{candidate.get('name')}\" reaches its sample target.",
        "info",
    )


def _lcfirst(value: str) -> str:
    return value[:1].lower() + value[1:] if value else value


AGENT_ANALYSERS = {
    "signal-analyst": signal_analyst,
    "segment-scout": segment_scout,
    "tracking-auditor": tracking_auditor,
    "sample-planner": sample_planner,
    "brief-writer": brief_writer,
}


def analyse(agent_key: str, data: dict[str, Any]) -> dict[str, Any]:
    analyser = AGENT_ANALYSERS.get(agent_key)
    if analyser is None:
        return _empty(
            "Unknown agent",
            [],
            ["This agent key is not registered in the orchestrator."],
            "Remove the task and start a new run.",
            "bad",
        )
    return analyser(data)
