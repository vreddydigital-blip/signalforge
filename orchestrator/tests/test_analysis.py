"""Analysis tests: the maths must match the PHP engine and never invent figures."""
from app import analysis


def test_prob_to_beat_is_symmetric_at_equal_traffic():
    assert round(analysis.prob_to_beat(500, 50, 500, 50), 4) == 50.0


def test_prob_to_beat_is_bounded():
    assert analysis.prob_to_beat(100000, 20000, 100, 0) <= 99.0
    assert analysis.prob_to_beat(100, 0, 100000, 20000) >= 1.0


def test_variants_for_accepts_string_and_int_keys():
    data = {"variants": {"4": [{"id": 1}]}}
    assert analysis.variants_for(data, 4) == [{"id": 1}]
    assert analysis.variants_for(data, "4") == [{"id": 1}]
    assert analysis.variants_for(data, 99) == []


def test_lead_of_skips_control():
    variants = [
        {"id": 1, "isControl": 1, "probability": 50},
        {"id": 2, "isControl": 0, "probability": 88},
        {"id": 3, "isControl": 0, "probability": 71},
    ]
    assert analysis.lead_of(variants)["id"] == 2


def test_segment_without_control_conversions_reports_no_lift():
    """A segment where the control never converts must not print a 0% lift."""
    data = {
        "experiments": [{"id": 1, "name": "T", "status": "Active"}],
        "variants": {
            "1": [
                {"id": 1, "name": "Control", "isControl": True, "visitors": 100, "conversions": 0, "probability": 50, "uplift": 0.0},
                {"id": 2, "name": "Challenger", "isControl": False, "visitors": 100, "conversions": 5, "probability": 80, "uplift": 0.0},
            ]
        },
        "segments": [
            {"experimentId": 1, "variantId": 1, "type": "exposure", "device": "Mobile", "isPaid": 0, "isReturning": 0, "n": 60},
            {"experimentId": 1, "variantId": 2, "type": "exposure", "device": "Mobile", "isPaid": 0, "isReturning": 0, "n": 60},
            {"experimentId": 1, "variantId": 2, "type": "conversion", "device": "Mobile", "isPaid": 0, "isReturning": 0, "n": 4},
        ],
        "lastEventTs": None, "events24h": 0, "events7d": 0, "eventTotal": 0, "exposures7d": 0,
    }
    output = analysis.segment_scout(data)
    mobile = [f for f in output["findings"] if f.startswith("Mobile")]
    assert mobile and "no lift to report" in mobile[0]


def test_tracking_auditor_penalises_a_dead_pipeline():
    output = analysis.tracking_auditor({
        "experiments": [], "variants": {}, "segments": [], "lastEventTs": None,
        "events24h": 0, "events7d": 0, "eventTotal": 0, "exposures7d": 0,
    })
    # 100 - 60 (no event ever) - 10 (no active test) = 30, matching orchestrator.php.
    assert output["metrics"][0]["value"] == "30/100"
    assert output["tone"] == "bad"


def test_unknown_agent_key_is_reported_not_crashed():
    output = analysis.analyse("nope", {})
    assert output["tone"] == "bad"
    assert "not registered" in output["findings"][0]
