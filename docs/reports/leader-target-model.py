"""Work backward from an 8B SpaceTraders cash target; no gameplay."""
import importlib.util
import json
import sys
from pathlib import Path
from dataclasses import replace

HERE = Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location("weekly_model", HERE / "weekly-earnings-model.py")
model = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = model
spec.loader.exec_module(model)

TARGET = 8_000_000_000
cases = [
    model.SCENARIOS[1],
    replace(model.SCENARIOS[2], name="Broader fleet", hauler_net_per_hour=200_000,
            total_market_ceiling_per_hour=60_000_000, requests_per_active_ship_hour=12),
    replace(model.SCENARIOS[2], name="Higher yield fleet", hauler_net_per_hour=300_000,
            total_market_ceiling_per_hour=55_000_000, requests_per_active_ship_hour=15),
]
results = [model.simulate(s) for s in cases]
assert results[1]["end_cash"] >= TARGET
assert results[2]["end_cash"] >= TARGET
for s, r in zip(cases, results):
    assert r["haulers"] + 1 <= r["active_ship_limit_from_assumed_requests"]
    assert abs(model.START_CASH + r["net_operating_earnings"]
        - r["ship_capex"] - r["probe_capex"] - r["end_cash"]) < .02
payload = {"target": TARGET, "starting_cash": model.START_CASH,
    "required_average_cash_gain_per_hour": (TARGET-model.START_CASH)/168,
    "naive_measured_command_ship_equivalents": (TARGET-model.START_CASH)/168/670384,
    "assumptions": [model.asdict(s) for s in cases], "results": results,
    "warning": "Conditional scenarios, not demonstrated routes or inferred leader strategies."
}
out = HERE / "weekly-earnings-results"
out.mkdir(exist_ok=True)
(out / "leader-target.json").write_text(json.dumps(payload, indent=2), encoding="utf-8")
print(json.dumps([{k:r[k] for k in ["scenario", "end_cash", "haulers", "probes", "ship_capex", "probe_capex", "last_purchase_hour"]} for r in results], indent=2))

colors = ["var(--chart-3)","var(--chart-2)","var(--chart-1)"]
lines=[]
for r,c in zip(results,colors):
    pts = " ".join(f'{48+d["hour"]/168*580:.2f},{220-d["cash_after_float_release"]/9e9*190:.2f}' for d in [{"hour":0,"cash_after_float_release":175000}]+r["history"])
    lines.append(f'<polyline fill="none" stroke="{c}" stroke-width="3" points="{pts}"/>')
rows="".join(f'<tr><td>{r["scenario"]}</td><td>{r["end_cash"]/1e9:.2f}B</td><td>{r["haulers"]} / {r["probes"]}</td><td>{r["last_purchase_hour"]:g}h</td></tr>' for r in results)
html=f'''<!doctype html><html><head><meta charset="utf-8"><style>body{{margin:0}}.panel{{padding:16px;background:var(--card);border-radius:var(--radius);border-left:12px solid var(--chart-2);font:14px var(--font-sans);color:var(--foreground)}}.tag{{color:var(--chart-2);font:12px var(--font-mono);letter-spacing:1px}}h2{{margin:8px 0;font-size:24px}}p{{line-height:1.55}}.muted{{color:var(--muted-foreground);font-size:12px}}svg{{width:100%;height:255px}}svg text{{font:12px var(--font-mono);fill:var(--muted-foreground)}}table{{width:100%;border-collapse:collapse;font-size:12px}}th,td{{padding:9px 5px;text-align:left;border-bottom:1px solid var(--border)}}.scroll{{overflow:auto}}</style></head><body><section class="panel"><div class="tag">LCARS // EIGHT BILLION REQUIREMENT</div><h2>Scale profit, market access and reliability together</h2><p><b>8B ? 168h ? 47.6M CR/h</b> average cash gain. Expansion spending and a slow opening require a higher operating rate later.</p><svg viewBox="0 0 660 255" aria-label="Conditional cash trajectories for planning and two eight-billion scenarios" role="img"><line x1="48" y1="220" x2="628" y2="220" stroke="var(--border)"/><line x1="48" y1="51.1" x2="628" y2="51.1" stroke="var(--border)" stroke-dasharray="5 5"/><text x="2" y="55">8B</text><text x="2" y="224">0</text><text x="48" y="245">Day 0</text><text x="590" y="245">Day 7</text>{"".join(lines)}</svg><div class="scroll"><table><tr><th>Scenario</th><th>Week cash</th><th>Haulers / probes</th><th>Fleet bought by</th></tr>{rows}</table></div><p><b>Broader fleet:</b> 200k net per hauler-hour; 60M/h fleet market capacity; 95% uptime; 12 earning requests per ship-hour. <b>Higher yield:</b> 300k per hauler-hour; 55M/h market capacity; 95% uptime; 15 earning requests per ship-hour.</p><p class="muted">Purple: earlier planning case. Orange: broader fleet. Blue: higher yield fleet. All start from 175k CR. Haulers start at 280k in the two target cases, increasing 1% with each purchase; each reserves 150k for cargo. One probe per four haulers costs 30k. One hour of discovery, half-hour deployment, and the earlier model's reinvestment/payback rules apply. Half the assumed request budget is reserved for other work.</p><p><b>These are requirements to test, not a discovered winning strategy.</b> Both target cases assume enough independent profitable routes, fast fleet acquisition, and near-continuous operation. Terminal cash releases reserved cargo capital at par and assigns no resale value to ships.</p></section></body></html>'''
(out / "leader-target.html").write_text(html,encoding="utf-8")
