# SignalForge delivery outcomes

Accounts, experiments, variants, event ingestion (tracker) and live results are backed by PHP + MySQL (see infinityfree/). Remaining preview-only areas: Insights model runs, Intelligence Center panels, workspace settings, per-variant code editor UI, and full experiment-detail routing.

## Experiment workspace and lifecycle
- [x] Provide a clear experiment workspace with draft, active, paused, and completed tests.
- [ ] Support the complete launch, pause, resume, end, and review lifecycle. MVP currently supports draft launch and active/paused toggles; completed tests are read-only.

## Guided CRO experiment creation
- [x] Provide guided experiment creation based on CRO frameworks, including hypothesis, objective, target audience, and primary conversion goal.
- [x] Support standard A/B tests and plan-gated multi-variant experiments.
- [ ] Support full post-creation variant configuration with editable names, control designation, and editable test details. MVP creates a control plus generated variant names.
- [x] Provide traffic allocation controls for assigning eligible users across experiment variants.
- [x] Provide conversion event setup in the guided wizard and retain the selected event on a draft. Production event ingestion remains follow-up work.

## Probability and results
- [x] Show statistical result fields including visitors, conversions, conversion rate, uplift, and probability of outperforming the control.
- [x] Calculate a Bayesian Beta-Binomial-style posterior estimate from visitor and conversion counts, including credible intervals, probability to beat control, data maturity, predicted uplift, and a recommendation.
- [ ] Provide a dedicated experiment-level and variant-level results route with historical comparisons. MVP provides the selected-readout comparison card and signal chart in the dashboard.

## Tracking and event instrumentation
- [x] Provide a Tracking setup workspace with an installable JavaScript snippet, health indicators, event mappings, and a test-event action.
- [ ] Connect the instrumentation UI to durable production event ingestion and server-side experiment attribution.

## Predictive modeling
- [x] Provide an Insights model catalog covering logistic regression, causal forests, uplift modeling, gradient boosting, survival analysis, and sequential Bayesian testing.
- [x] Run sequential Bayesian analysis on current aggregate counts with a clear updated readout.
- [ ] Train the five visitor-level models after event ingestion supplies assignments, outcomes, covariates, and timestamps.

## CRO Intelligence Center
- [x] Provide experiment health diagnostics, data-readiness score, actionable recommendations, segment readouts, model evaluation summary, revenue forecast, collision warning, experiment calendar, AI CRO assistant entry point, visual-editor entry point, collaboration activity, and integration shortcuts.
- [ ] Connect these preview-backed panels to durable event data, trained model artifacts, real revenue sources, scheduled notifications, collaboration records, and third-party integrations.

## UX refinement
- [x] Group sidebar navigation into Workspace, Improve, and Manage areas and clarify the primary Create experiment action.
- [x] Add onboarding guidance, experiment-row health and sample context, explicit observed-data labels, dismissible guidance, and responsive mobile navigation behavior.
- [x] Make Workspace settings functional with workspace defaults, timezone, conversion-goal, notification toggles, tracking status, and access controls.

## CRO framework library
- [x] Expand the experiment framework selector with MECLABS, Fogg, COM-B, EAST, HEART, Nielsen, AIDA, LIFT, Cialdini, Hook, PIE, PXL, ICE, RICE, Bayesian, Jobs to be Done, 5C, 7C, Customer Journey Mapping, Value Proposition Canvas, AARRR, and customer-funnel playbooks.
- [x] Provide a searchable framework library page with categories, descriptions, and a Use in experiment action.
- [x] Show framework-specific guidance in the wizard with a decision lens, hypothesis starter, and recommended measurement metric.
- [x] Generate a usable hypothesis description from the selected goal and audience, with an explicit Generate hypothesis action and editable output.
- [x] Capture observed insight, experience change, and behavioral reason fields, then persist them with the draft and show the selected success metric.

## Usability improvements
- [x] Use a three-step guided flow for the test brief, variants and signal, and final review.
- [x] Validate the page URL format before continuing and show a clear success or error message.
- [x] Allow editable variant names, conversion-event selection, and sample-size/duration guidance before review.

## Plan-based entitlements
- [x] Enforce the configured plan limit for experiment count, gate multivariate testing on Free, and apply plan-based maximum variant capacity in the guided setup.
- [ ] Persist subscription entitlements and enforce them server-side for multi-user production workspaces.
