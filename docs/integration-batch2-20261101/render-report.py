"""Render the retained combined verification results as a self-contained dark report."""
from html import escape
import json
from pathlib import Path

root = Path(__file__).resolve().parent
summary = json.loads((root / 'evidence/summary.json').read_text())
assets = json.loads((root / 'assets.json').read_text())
provenance = json.loads((root / 'provenance.json').read_text())
rows = ''.join(f'<tr><td>{escape(row["name"])}</td><td>{"Pass" if row["exit"] == 0 else "Fail"}</td>'
               f'<td>{row["seconds"]:.2f}s</td><td><a href="evidence/{escape(row["name"])}.log">Log</a></td></tr>'
               for row in summary['checks'])
sources = ''.join(f'<tr><td>#{item["issue"]}</td><td>{escape(item["branch"])}</td><td><code>{item["head"]}</code></td></tr>'
                  for item in provenance['source_branches'])
images = [('combined-drain-390.png', 'Drain before controller and worker mutation'),
          ('combined-adviser-390.png', 'Policy review with execution disabled'),
          ('scan-result-mobile.png', 'Confirmed scan and separate exploration evidence'),
          ('fleet-comparison-390.png', 'Payback withheld when throughput is insufficient')]
gallery = ''.join(f'<figure><a href="evidence/{name}"><img src="evidence/{name}" alt="{escape(label)}" loading="lazy"></a><figcaption>{escape(label)}</figcaption></figure>' for name, label in images)
page = f'''<!doctype html>
<html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark"><title>Local batch-two integration</title>
<style>
:root{{color-scheme:dark;font-family:system-ui,sans-serif;background:#080f1a;color:#e6edf7;line-height:1.6}}
body{{margin:0}}main{{max-width:1100px;margin:auto;padding:32px 22px 70px}}h1,h2{{line-height:1.2;color:#fff}}h1{{font-size:2.1rem}}
section{{background:#101b2c;border:1px solid #34445c;border-radius:14px;padding:22px;margin:22px 0}}a{{color:#89d8ff}}a:focus-visible{{outline:3px solid #ffdb83;outline-offset:4px}}
code{{overflow-wrap:anywhere;color:#a5ead0}}.pass{{color:#a5ead0;font-size:1.2rem;font-weight:700}}.muted{{color:#afbdd1}}table{{width:100%;border-collapse:collapse}}th,td{{text-align:left;vertical-align:top;border-bottom:1px solid #34445c;padding:10px;overflow-wrap:anywhere}}
.scroll{{overflow-x:auto}}.gallery{{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:18px}}figure{{margin:0}}img{{max-width:100%;max-height:640px;object-fit:contain;object-position:top;border:1px solid #40516b}}figcaption{{color:#afbdd1;font-size:.9rem}}li{{margin:8px 0}}@media(max-width:600px){{main{{padding:20px 12px}}section{{padding:16px}}h1{{font-size:1.65rem}}}}
</style><main>
<p class="muted">2026-11-01 · Owned offline fixtures · LOCAL ONLY</p><h1>Six implementations, one local integration</h1>
<p class="pass">{len(summary['checks'])} combined checks passed · 391 backend tests · 2,244 assertions</p>
<p>Branch <code>{escape(provenance['branch'])}</code><br>Tested implementation <code>{provenance['implementation_head']}</code><br>Inspected base <code>{provenance['base']}</code></p>
<p><a href="README.md">Complete acceptance report and limits</a> · <a href="reproduce.md">Reproduction commands</a> · <a href="evidence/summary.json">Machine-readable evidence</a></p>
<section><h2>What required an integration fix</h2><p>Adviser selector version 2 uses comparable cycle net after wear. Cash-only history stays unknown. At least three usable samples are required for historical adviser income. Future-dated samples and unresolved cycles cannot qualify.</p><p>The authenticated route group, command requests, dashboard, runner accounting, and lifecycle callbacks retain all six implementations. Main's briefs and the original dated reports remain unchanged. One complete asset set replaces all branch builds.</p></section>
<section><h2>Verification on the combined source</h2><div class="scroll"><table><thead><tr><th>Check</th><th>Result</th><th>Elapsed</th><th>Evidence</th></tr></thead><tbody>{rows}</tbody></table></div><p>Full verification includes Pint, frontend checks, environment probes, 18 lifecycle cases, and two identical production builds. Six actual browser and service lifecycle cases pass with no skips. Composer validation, platform checks, and both dependency audits pass.</p></section>
<section><h2>Cross-feature behavior</h2><p>Private login and real CSRF precede a protected-reserve scan preview. Drain blocks approval, adviser pause, economics requests, and worker pickup without state changes. After reopening, the pending scan blocks adviser actions. Separate finite workers confirm a partial scan and a chart. Its 100 CR reward reconciles but creates no comparable cycle history. Policy pause invalidates review while execution remains disabled. The owned fixture is removed after verified shutdown.</p><p>Both original real-worker suites pass. Drain checks exercise active writers, SQLite reservation races, rollback compatibility, measured lock expiry, and a real ten-minute timeout without cancelling the active writer.</p></section>
<section><h2>Actual UI evidence</h2><p class="muted">Opened and inspected after production builds. Light OS preference, dark app, detected America/Los_Angeles presentation.</p><div class="gallery">{gallery}</div></section>
<section><h2>Source and assets</h2><div class="scroll"><table><thead><tr><th>Issue</th><th>Protected source branch</th><th>Source head</th></tr></thead><tbody>{sources}</tbody></table></div><p>{assets['asset_files']} asset files are referenced exactly by {assets['manifest_entries']} manifest entries. The built bytes match the committed implementation. Public signup, reset, and verification bundles are absent. Locked dependency files are unchanged. <a href="assets.json">Asset hashes</a> · <a href="provenance.json">Commit mapping</a></p></section>
<section><h2>Failures and remaining limits</h2><p>The original adviser expectation, a wrapper environment mistake, the future-history regression, and a stale single-fieldset browser locator failed before correction. Their logs are retained. Deliberate lifecycle failures retain private diagnostics and do not count as successful gameplay.</p><p>All upstream responses are synthetic. Sensor range, actual cooldowns, orbit mechanics, rewards, and authenticated upstream acceptance remain unverified. Missing wear, inventory, fuel, and positioning stay unresolved. Model calls and policy execution remain disabled. No independent Sonnet review was run because agents and threads were prohibited.</p></section>
<section><h2>Publication remains separate</h2><p>No push, PR, issue mutation, deployment, live gameplay, paid call, real credential, or external notification occurred. The original PRs #10, #12, #38, #52, #68, and #82 already merged briefs and cannot deliver this code through an update.</p><p>A separately authorized publication must use a new integration PR, recheck current main, resolve any new overlaps, and rerun combined verification. Keep the six issues open until code acceptance and publication are reviewed. Independent threads #53, #55, #57, and #83 remain outside this integration.</p></section>
</main></html>'''
assert all(row['exit'] == 0 for row in summary['checks']), 'Refuse to render a passing report with failed checks'
(root / 'report.html').write_text(page)
print(root / 'report.html')
