"""Deterministic SpaceTraders scenarios, not a statistical forecast.
Run: python docs/reports/weekly-earnings-model.py --accounting docs/reports/one-hour-trade-test-2026-11-14-evidence/accounting-summary.json
Standard library only. Does not contact the game or execute gameplay.
"""
from __future__ import annotations
import argparse
import csv
import hashlib
import json
import math
from dataclasses import asdict, dataclass, replace
from pathlib import Path

@dataclass(frozen=True)
class Scenario:
    name: str
    command_net_per_hour: float
    hauler_net_per_hour: float
    first_hauler_price: float
    cargo_float_per_hauler: float
    uptime: float
    discovery_hours: float
    total_market_ceiling_per_hour: float
    requests_per_active_ship_hour: float
    deployment_hours: float = 1.0
    ship_price_growth: float = 0.01

SCENARIOS = [
    Scenario("Cautious", 150_000, 37_000, 500_000, 100_000, .70, 6, 1_500_000, 90, 2),
    Scenario("Planning", 300_000, 100_000, 350_000, 100_000, .85, 3, 8_000_000, 30, 1),
    Scenario("Stretch", 500_000, 200_000, 280_000, 150_000, .95, 1, 30_000_000, 15, .5),
]
START_CASH = 175_000
CASH_RESERVE = 20_000
COMMAND_FLOAT = 100_000
PROBE_PRICE = 30_000
HAULERS_PER_PROBE = 4
REQUESTS_PER_SECOND = 2  # Prior official guidance, not a runtime measurement.
TRADING_REQUEST_SHARE = .50  # Other half reserved for observations, status, retries.
STEP = .25  # Quarter-hour accounting/decision boundaries.

MEASURED = {
    "start_cash": 175_000, "end_cash": 845_384,
    "sales": 1_425_093, "cargo_purchases": 753_845, "fuel": 864,
    "net_gain": 670_384, "cutoff_cash": 845_528, "post_cutoff_fuel": 144,
    "window_hours": 1, "independent_runs": 1, "confirmed_actions": 102,
}

def simulate(s: Scenario, hours: float = 168, grow: bool = True):
    assert hours > 0 and 0 <= s.uptime <= 1 and s.hauler_net_per_hour > 0
    cash = START_CASH - COMMAND_FLOAT
    earned = ship_spend = probe_spend = hauler_float = 0.0
    ships = []  # Activation times; command ship is already owned.
    probes = 0
    request_budget = REQUESTS_PER_SECOND * 3600 * TRADING_REQUEST_SHARE
    active_ship_limit = max(1, int(request_budget // s.requests_per_active_ship_hour))
    # Buy only enough capacity to reach either the market or request ceiling.
    market_hauler_limit = max(0, math.ceil(
        (s.total_market_ceiling_per_hour - s.command_net_per_hour) / s.hauler_net_per_hour))
    buy_limit = min(active_ship_limit - 1, market_hauler_limit)
    history = []
    events = []
    now = 0.0
    while now < hours - 1e-9:
        dt = min(STEP, hours - now)
        active_haulers = sum(t <= now + 1e-9 for t in ships)
        rate = 0.0
        if now >= s.discovery_hours:
            # Assumes identical traders on distinct viable routes. Correlated market
            # limits cap combined profit; uptime applies once to the fleet.
            rate = min(s.command_net_per_hour + active_haulers * s.hauler_net_per_hour,
                       s.total_market_ceiling_per_hour) * s.uptime
        cash += rate * dt
        earned += rate * dt
        now += dt
        if grow and now >= s.discovery_hours:
            while len(ships) < buy_limit:
                price = s.first_hauler_price * (1 + s.ship_price_growth) ** len(ships)
                new_probes = math.ceil((len(ships) + 1) / HAULERS_PER_PROBE)
                support = (new_probes - probes) * PROBE_PRICE
                setup = price + support
                # Requiring full payback within HALF remaining productive hours
                # leaves a buffer. New ships also reserve their own cargo float.
                marginal_rate = max(0, min(s.hauler_net_per_hour,
                    s.total_market_ceiling_per_hour - s.command_net_per_hour
                    - len(ships) * s.hauler_net_per_hour)) * s.uptime
                remaining = hours - now - s.deployment_hours
                payback = setup / marginal_rate if marginal_rate else math.inf
                if remaining <= 0 or payback > remaining * .5:
                    break
                if cash - setup - s.cargo_float_per_hauler < CASH_RESERVE:
                    break
                cash -= setup + s.cargo_float_per_hauler
                ship_spend += price
                probe_spend += support
                hauler_float += s.cargo_float_per_hauler
                probes = new_probes
                ships.append(now + s.deployment_hours)
                events.append({"hour": now, "hauler_number": len(ships),
                               "ship_price": price, "support_cost": support,
                               "payback_hours": payback})
        # Every credit is either cash, trade float, or explicitly spent.
        assert abs(cash + COMMAND_FLOAT + hauler_float
                   - (START_CASH + earned - ship_spend - probe_spend)) < .02
        assert cash >= CASH_RESERVE - .01
        history.append({"hour": now, "cash": cash,
                        "cash_after_float_release": cash + COMMAND_FLOAT + hauler_float,
                        "net_operating_earnings": earned, "haulers_bought": len(ships),
                        "active_haulers": active_haulers, "net_rate": rate})
    # Float is a reusable cash allocation, not consumed inventory. Closing it at
    # par assumes no cargo loss/markdown; terminal return costs are not simulated.
    end_cash = cash + COMMAND_FLOAT + hauler_float
    assert abs(end_cash - START_CASH - (earned - ship_spend - probe_spend)) < .02
    return {"scenario": s.name, "hours": hours, "end_cash": end_cash,
            "cash_gain_after_capex": end_cash - START_CASH,
            "net_operating_earnings": earned, "ship_capex": ship_spend,
            "probe_capex": probe_spend, "trading_float_released": COMMAND_FLOAT + hauler_float,
            "cash_before_float_release": cash, "haulers": len(ships), "probes": probes,
            "active_ship_limit_from_assumed_requests": active_ship_limit,
            "hauler_purchase_limit": buy_limit,
            "request_budget_per_hour": request_budget,
            "last_purchase_hour": events[-1]["hour"] if events else None,
            "daily": [h for h in history if abs(h["hour"] % 24) < 1e-8],
            "history": history, "purchases": events}

def verify():
    assert MEASURED["sales"] - MEASURED["cargo_purchases"] - MEASURED["fuel"] == MEASURED["net_gain"]
    assert MEASURED["end_cash"] - MEASURED["start_cash"] == MEASURED["net_gain"]
    assert MEASURED["cutoff_cash"] - MEASURED["post_cutoff_fuel"] == MEASURED["end_cash"]
    # Independently solvable constant-rate case verifies time and accounting.
    flat = simulate(replace(SCENARIOS[1], discovery_hours=0, uptime=1), 24, grow=False)
    assert flat["end_cash"] == START_CASH + 300_000 * 24
    idle = simulate(replace(SCENARIOS[1], uptime=0))
    assert idle["end_cash"] == START_CASH and idle["haulers"] == 0
    # Half-step convergence bounds the error introduced by decision cadence.
    global STEP
    coarse = simulate(SCENARIOS[1])["end_cash"]
    original_step = STEP
    STEP = .125
    fine = simulate(SCENARIOS[1])["end_cash"]
    STEP = original_step
    assert abs(fine - coarse) / fine < .02
    return {"accounting": "passed", "constant_rate": "passed", "zero_uptime": "passed",
            "step_comparison": {"quarter_hour": coarse, "eighth_hour": fine,
                                "relative_difference": abs(fine-coarse)/fine}}

def render_report(payload):
    results = payload["results"]
    colors = ["var(--chart-3)", "var(--chart-2)", "var(--chart-1)"]
    maximum = 5_000_000_000
    svg = ['<svg viewBox="0 0 660 280" role="img" aria-label="Modeled cash grows to 0.10, 0.96 and 4.35 billion credits over seven days">']
    for value in range(6):
        y = 238 - value / 5 * 210
        svg.append(f'<line x1="55" y1="{y}" x2="642" y2="{y}" class="grid"/><text x="3" y="{y+4}">{value}B</text>')
    for day in range(8):
        x = 55 + day/7 * 587
        svg.append(f'<text x="{x-8}" y="265">{day}d</text>')
    for r, color in zip(results, colors):
        points = [(0, START_CASH)] + [(d["hour"], d["cash_after_float_release"]) for d in r["history"]]
        coordinates = ' '.join(f'{55+h/168*587:.2f},{238-v/maximum*210:.2f}' for h,v in points)
        svg.append(f'<polyline points="{coordinates}" fill="none" stroke="{color}" stroke-width="3"><title>{r["scenario"]}: {r["end_cash"]:,.0f} CR</title></polyline>')
    svg.append('</svg>')
    rows = ''.join(f'<tr><td>{r["scenario"]}</td><td>{r["end_cash"]/1e6:,.1f}M</td><td>{r["haulers"]} / {r["probes"]}</td><td>{(r["ship_capex"]+r["probe_capex"])/1e6:,.1f}M</td></tr>' for r in results)
    assumptions = ''.join(f'<tr><td>{s["name"]}</td><td>{s["command_net_per_hour"]/1000:g}k / {s["hauler_net_per_hour"]/1000:g}k</td><td>{s["uptime"]:.0%}</td><td>{s["total_market_ceiling_per_hour"]/1e6:g}M/h</td><td>{s["requests_per_active_ship_hour"]:g}</td></tr>' for s in payload["scenarios"])
    sensitivity = ''.join(f'<tr><td>{r["change"]}</td><td>{r["end_cash"]/1e6:,.1f}M</td></tr>' for r in payload["sensitivity"])
    daily = ''.join(f'<tr><td>{d["hour"]/24:g}</td><td>{d["cash_after_float_release"]/1e6:,.1f}M</td><td>{d["haulers_bought"]}</td></tr>' for d in results[1]["daily"])
    checks = payload["verification"]["step_comparison"]
    return f'''<!doctype html><html><head><meta charset="utf-8"><style>
    *{{box-sizing:border-box}}body{{margin:0}}.panel{{background:var(--card);padding:16px;border-left:12px solid var(--chart-2);border-radius:var(--radius);font:14px var(--font-sans);color:var(--foreground)}}
    .tag{{font:12px var(--font-mono);letter-spacing:1px;color:var(--chart-2)}}h2{{font-size:25px;margin:9px 0}}h3{{font-size:16px;margin:22px 0 10px}}p,li{{line-height:1.55}}.muted{{color:var(--muted-foreground);font-size:12px}}svg{{width:100%;height:280px}}svg text{{fill:var(--muted-foreground);font:12px var(--font-mono)}}.grid{{stroke:var(--border)}}table{{border-collapse:collapse;width:100%;font-variant-numeric:tabular-nums;font-size:12px}}th,td{{padding:9px 7px;text-align:left;border-bottom:1px solid var(--border)}}th{{color:var(--muted-foreground)}}.scroll{{overflow-x:auto}}.legend{{display:flex;gap:16px;flex-wrap:wrap;font-size:12px}}.formula{{font:12px var(--font-mono);padding:12px;background:var(--secondary);border-radius:var(--radius);overflow-wrap:anywhere}}.callout{{padding:12px;border-radius:var(--radius);background:var(--secondary)}}
    </style></head><body><section class="panel"><div class="tag">LCARS // FLEET ECONOMICS // CONDITIONAL FORECAST</div><h2>A full week of fleet growth</h2><p class="callout"><b>Planning target: 500M?1B CR.</b> The model's central inputs yield <b>958M CR</b>. This requires discovering enough profitable routes and operating a fleet reliably; only our single-ship hour was measured.</p>
    <p class="muted">Full future 168-hour wipe ? starts with 175,000 CR ? no gameplay performed ? scenarios are not probabilities or a confidence interval.</p>
    {''.join(svg)}<div class="legend"><span style="color:{colors[0]}">? Cautious</span><span style="color:{colors[1]}">? Planning</span><span style="color:{colors[2]}">? Stretch</span></div>
    <h3>Week-end cash after expansion spending</h3><div class="scroll"><table><tr><th>Scenario</th><th>Cash</th><th>Haulers / probes</th><th>Capital spent</th></tr>{rows}</table></div><p class="muted">One existing command ship is additional. No resale value assigned to ships or probes. Cash assumes all reserved trade float is released at par; it excludes a separately modeled final return journey. The planning case holds 950.6M cash before releasing 7.8M of trade float.</p>
    <h3>Assumptions that determine the result</h3><div class="scroll"><table><tr><th>Scenario</th><th>Command / hauler net per hour</th><th>Uptime</th><th>Fleet market cap</th><th>Requests / ship-hour</th></tr>{assumptions}</table></div><p class="muted">Rates are assumed operating profit after fuel and routine running costs, before expansion spending. The 37k hauler input comes from an earlier community account; it is not our measured rate. Other fleet rates, request efficiency, uptime and market ceilings are planning assumptions.</p>
    <p>Ship prices start at 500k / 350k / 280k CR, respectively, and rise 1% per purchase. Each new hauler reserves 100k / 100k / 150k for cargo. Each four haulers require a 30k probe. Discovery takes 6 / 3 / 1 hours; deployment takes 2 / 1 / 0.5 hours. A 20k cash reserve is retained. Half the documented 2 requests/second budget is allocated to earning ships.</p>
    <div class="formula">Hourly net = uptime ? min(command net + active haulers ? hauler net, market ceiling)<br>Week-end cash = 175,000 + operating earnings ? ship purchases ? probe purchases<br>Buy only if setup cost / marginal hourly net ? half of productive hours remaining.</div>
    <h3>Planning-case calculation</h3><p>175,000 + 999,175,000 ? 40,303,268.27 ? 600,000 = <b>958,446,731.73 CR</b>.</p><div class="scroll"><table><tr><th>Day</th><th>Cash + reserved trade float</th><th>Haulers bought</th></tr>{daily}</table></div>
    <h3>Change one planning assumption</h3><table><tr><th>Change</th><th>Week-end cash</th></tr>{sensitivity}</table>
    <h3>What we actually measured</h3><p>One late-wipe hour earned <b>670,384 CR</b> after fuel, with existing ships. Simple repetition would earn <b>112,624,512 CR</b> in 168 hours before expansion spending. That is an arithmetic reference, not evidence the rate persists. We have no independent repeat runs or fleet-scale measurements.</p>
    <p>Scaling stops helping when routes saturate, prices move, requests run out, or new ships cannot repay their cost. The model assumes profitable routes can be found up to its ceiling. It does not simulate individual markets, gate construction, exploration capital, outages as separate events, cargo losses, or a bad next-wipe layout. Actual earnings can fall below the cautious case.</p>
    <h3>Verification</h3><p class="muted">Measured ledger identities, constant-rate control, zero-uptime control, and every-step cash conservation passed. Halving the decision interval changed the planning estimate by {checks["relative_difference"]:.2%}. This verifies arithmetic and model consistency, not the assumptions.</p>
    <p class="muted">Evidence: accounting-summary.json from the November 14 live run; <a href="https://github.com/Gemberkoekje/projects/blob/f1fb192705ab2a2847ccc18faca8cbb6873e3492/SpaceTraders/PLAN.md">pinned SPECTER plan</a>. Official request guidance: <a href="https://docs.spacetraders.io/api-guide/rate-limits">SpaceTraders rate limits</a>. Full inputs and timelines are in forecast.json; daily values are in daily.csv.</p></section></body></html>'''


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path,
                        default=Path(__file__).with_name("weekly-earnings-results"))
    parser.add_argument("--accounting", type=Path, required=True)
    args = parser.parse_args()
    args.output_dir.mkdir(parents=True, exist_ok=True)
    source_check = {"path": str(args.accounting), "available": args.accounting.exists()}
    if args.accounting.exists():
        raw = args.accounting.read_bytes()
        observed = json.loads(raw)
        assert observed["starting_cash"] == MEASURED["start_cash"]
        assert observed["cash"] == MEASURED["end_cash"]
        assert observed["receipt_credit_delta"] == MEASURED["net_gain"]
        assert observed["unexplained_cash_difference"] == 0
        assert sum(observed["confirmed_action_count"].values()) == MEASURED["confirmed_actions"]
        transactions = observed["transactions"]
        assert len({t["id"] for t in transactions}) == len(transactions)
        assert sum(t["totalPrice"] for t in transactions if t["command"] == "sell") == MEASURED["sales"]
        assert sum(t["totalPrice"] for t in transactions if t["command"] == "buy") == MEASURED["cargo_purchases"]
        assert sum(t["totalPrice"] for t in transactions if t["command"] == "refuel") == MEASURED["fuel"]
        source_check["unique_transactions_reconciled"] = len(transactions)
        source_check["sha256"] = hashlib.sha256(raw).hexdigest()
    checks = verify()
    results = [simulate(s) for s in SCENARIOS]
    sensitivities = []
    base = SCENARIOS[1]
    for label, variant in [
        ("Planning inputs", base),
        ("Half hauler earnings", replace(base, hauler_net_per_hour=base.hauler_net_per_hour/2)),
        ("Double ship prices", replace(base, first_hauler_price=base.first_hauler_price*2)),
        ("Half market capacity", replace(base, total_market_ceiling_per_hour=base.total_market_ceiling_per_hour/2)),
        ("Twice requests per ship", replace(base, requests_per_active_ship_hour=base.requests_per_active_ship_hour*2)),
        ("Uptime falls to 50 percent", replace(base, uptime=.5)),
    ]:
        r = simulate(variant)
        sensitivities.append({"change": label, "end_cash": r["end_cash"], "haulers": r["haulers"]})
    payload = {"model_kind": "conditional deterministic scenarios; no probabilities assigned",
        "horizon": "Full future 168-hour wipe from 175000 CR, not current partial wipe",
        "measured": MEASURED, "scenarios": [asdict(s) for s in SCENARIOS],
        "shared_assumptions": {"start_cash": START_CASH, "cash_reserve": CASH_RESERVE,
          "command_cargo_float": COMMAND_FLOAT, "probe_price": PROBE_PRICE,
          "haulers_per_probe": HAULERS_PER_PROBE, "api_requests_per_second": REQUESTS_PER_SECOND,
          "trading_request_share": TRADING_REQUEST_SHARE, "decision_step_hours": STEP,
          "purchase_payback_fraction_of_remaining_time": .5},
        "linear_single_ship_reference": MEASURED["net_gain"]*168,
        "verification": checks, "measured_source": source_check,
        "assumption_status": "Fleet rates, market ceilings, uptime, request efficiency, ship prices and layout are unvalidated planning inputs.",
        "omissions": ["individual market simulation", "gate and exploration capital",
            "cargo losses", "ship/probe resale", "separate terminal travel costs"],
        "sensitivity": sensitivities, "results": results}
    (args.output_dir / "forecast.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
    (args.output_dir / "forecast.html").write_text(render_report(payload), encoding="utf-8")
    with (args.output_dir / "daily.csv").open("w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["scenario", "day", "cash_after_float_release", "haulers_bought"])
        writer.writeheader()
        for r in results:
            for d in r["daily"]:
                writer.writerow({"scenario": r["scenario"], "day": d["hour"]/24,
                    "cash_after_float_release": round(d["cash_after_float_release"],2),
                    "haulers_bought": d["haulers_bought"]})
    print(json.dumps({"checks": checks, "results": [{k:v for k,v in r.items()
        if k not in ["history","purchases","daily"]} for r in results],
        "sensitivity": sensitivities, "output_dir": str(args.output_dir)}, indent=2))

if __name__ == "__main__":
    main()
