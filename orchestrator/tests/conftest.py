import os
import time

import pytest


@pytest.fixture(scope="session", autouse=True)
def heuristic_mode():
    """Force deterministic narrative so tests never need a network or an API key."""
    keys = ("SIGNALFORGE_LLM_MODE", "GROQ_API_KEY", "SIGNALFORGE_API_TOKEN", "SIGNALFORGE_MAX_ATTEMPTS")
    old = {key: os.environ.get(key) for key in keys}
    os.environ["SIGNALFORGE_LLM_MODE"] = "heuristic"
    for key in ("GROQ_API_KEY", "SIGNALFORGE_API_TOKEN"):
        os.environ.pop(key, None)
    yield
    for key, value in old.items():
        if value is None:
            os.environ.pop(key, None)
        else:
            os.environ[key] = value


@pytest.fixture(scope="session")
def dataset():
    """A realistic workspace snapshot: two active tests with traffic, one draft."""
    now = int(time.time())
    experiments = [
        {
            "id": 1, "name": "Pricing page hero rewrite", "kind": "A/B test", "status": "Active",
            "goal": "Trial starts", "framework": "MECLABS heuristic", "pageUrl": "https://example.com/pricing",
            "objective": "Lift trial starts", "hypothesis": "h", "observedInsight": "visitors hesitate at the price table",
            "experienceChange": "move the value prop above the fold", "behaviorReason": "less effort to decide",
            "audience": "All visitors", "conversionEvent": "trial_start", "traffic": 100,
        },
        {
            "id": 2, "name": "Checkout CTA colour", "kind": "A/B test", "status": "Active",
            "goal": "Purchases", "framework": "Fogg behaviour model", "pageUrl": "https://example.com/checkout",
            "objective": "Lift purchases", "hypothesis": "h", "observedInsight": "the CTA blends in",
            "experienceChange": "raise CTA contrast", "behaviorReason": "salience drives action",
            "audience": "Mobile visitors", "conversionEvent": "purchase", "traffic": 100,
        },
        {
            "id": 3, "name": "Onboarding email cadence", "kind": "Multivariate", "status": "Draft",
            "goal": "Activation", "framework": "Jobs to be done", "pageUrl": "",
            "objective": "", "hypothesis": "", "observedInsight": "", "experienceChange": "",
            "behaviorReason": "", "audience": "", "conversionEvent": "activated", "traffic": 50,
        },
    ]
    # String keys on purpose: PHP encodes an int-keyed array as a JSON object.
    variants = {
        "1": [
            {"id": 11, "name": "Control", "isControl": True, "color": "#8d96a8", "visitors": 520, "conversions": 52, "rate": 10.0, "uplift": 0.0, "probability": 50},
            {"id": 12, "name": "Value-first hero", "isControl": False, "color": "#e5643c", "visitors": 505, "conversions": 78, "rate": 15.45, "uplift": 54.5, "probability": 96},
        ],
        "2": [
            {"id": 21, "name": "Control", "isControl": True, "color": "#8d96a8", "visitors": 300, "conversions": 18, "rate": 6.0, "uplift": 0.0, "probability": 50},
            {"id": 22, "name": "High-contrast CTA", "isControl": False, "color": "#5676e8", "visitors": 295, "conversions": 24, "rate": 8.14, "uplift": 35.6, "probability": 78},
        ],
        "3": [
            {"id": 31, "name": "Control", "isControl": True, "color": "#8d96a8", "visitors": 0, "conversions": 0, "rate": 0.0, "uplift": 0.0, "probability": 50},
            {"id": 32, "name": "Two emails", "isControl": False, "color": "#f2aa4f", "visitors": 0, "conversions": 0, "rate": 0.0, "uplift": 0.0, "probability": 50},
        ],
    }
    segments = []
    for exp, control_id, lead_id in [(1, 11, 12), (2, 21, 22)]:
        for variant_id, base in [(control_id, 0.10), (lead_id, 0.155)]:
            for device, share in [("Mobile", 0.6), ("Desktop", 0.4)]:
                for paid in (0, 1):
                    for returning in (0, 1):
                        n = int(500 * share * 0.5 * 0.5)
                        segments.append({
                            "experimentId": exp, "variantId": variant_id, "type": "exposure",
                            "device": device, "isPaid": paid, "isReturning": returning, "n": n,
                        })
                        segments.append({
                            "experimentId": exp, "variantId": variant_id, "type": "conversion",
                            "device": device, "isPaid": paid, "isReturning": returning, "n": int(n * base),
                        })
    return {
        "experiments": experiments,
        "variants": variants,
        "segments": segments,
        "lastEventTs": now - 3600,
        "events24h": 420,
        "events7d": 2100,
        "eventTotal": 4200,
        "exposures7d": 1900,
    }


@pytest.fixture(scope="session")
def empty_dataset():
    """A brand-new workspace: no experiments, no events."""
    return {
        "experiments": [],
        "variants": {},
        "segments": [],
        "lastEventTs": None,
        "events24h": 0,
        "events7d": 0,
        "eventTotal": 0,
        "exposures7d": 0,
    }
