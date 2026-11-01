import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const [phase, directory] = process.argv.slice(2);
assert.ok(['before', 'after'].includes(phase) && directory, 'Usage: node scripts/render-opt01-evidence.mjs before|after EVIDENCE_DIR');
const rows = (await readFile(path.join(directory, `${phase}.jsonl`), 'utf8')).trim().split('\n').map(JSON.parse);
assert.deepEqual(rows.map(row => row.markets), [2, 4, 8]);
if (phase === 'after') {
    const before = (await readFile(path.join(directory, 'before.jsonl'), 'utf8')).trim().split('\n').map(JSON.parse);
    rows.forEach((row, i) => {
        for (const key of ['markets', 'opportunities', 'route_options', 'report_sha256', 'options_sha256', 'quote_at']) {
            assert.equal(row[key], before[i][key], `Changed ${key} for ${row.markets} markets`);
        }
        assert.equal(row.report_listing_selects, 0);
        assert.equal(row.options_listing_selects, 1);
    });
}
const html = `<!doctype html><html lang="en"><meta charset="utf-8"><title>OPT01 ${phase} performance evidence</title>
<style>
:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#0b1220;color:#e5edf8;font:18px/1.5 system-ui}main{margin:24px;padding:30px;background:#111d30;border:1px solid #34445c;border-radius:14px}h1{margin:8px 0;font-size:30px}.label{color:#91bfff;font-size:14px;letter-spacing:1px;text-transform:uppercase}p{color:#b4c4db;margin:10px 0 20px}table{width:100%;border-collapse:collapse}th,td{text-align:right;padding:12px;border-bottom:1px solid #34445c}th:first-child,td:first-child{text-align:left}th{font-size:15px;color:#b4c4db}.bar{height:9px;background:#7eb3ff;border-radius:4px;margin-top:8px;min-width:2px}.foot{margin-top:24px;font-size:15px;color:#b4c4db}code{color:#c9ddff}strong{color:#a8efc7}
</style><main id="evidence"><div class="label">Captured PHPUnit + SQLite query evidence · ${phase}</div>
<h1>Fuel-market listing queries</h1><p>Real OpportunityAssessor::report and FuelPlanner::options paths.<br>Synthetic agent/reset only. No product UI change or live gameplay.</p>
<table><thead><tr><th>Fresh markets</th><th>Report listing SELECTs</th><th>Options listing SELECTs</th><th>Opportunities</th></tr></thead><tbody>
${rows.map(row => `<tr><td>${row.markets}</td><td>${row.report_listing_selects}<div class="bar" style="width:${row.report_listing_selects / 72}px"></div></td><td>${row.options_listing_selects}</td><td>${row.opportunities}</td></tr>`).join('')}
</tbody></table><div class="foot">${phase === 'after' ? '<strong>Full report and options hashes match BEFORE at every size.</strong><br>Report reuses its existing observation read; standalone options load listings once.' : 'Initial PR baseline: 27372602b2bf. Runtime code unchanged for this capture.<br>Listing SELECTs repeat inside fuel legs and nested report planning.'}<br>Quote timestamp: <code>${rows[0].quote_at}</code><br>Same owned fixture, frozen clock, viewport 1100 × 760, device scale 1.<br>Source: <code>${phase}.jsonl</code> · Raw test run: <code>${phase}-tests.txt</code></div></main></html>`;
await writeFile(path.join(directory, `${phase}.html`), html);
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_EXECUTABLE || undefined });
try {
    const page = await browser.newPage({ viewport: { width: 1100, height: 760 }, deviceScaleFactor: 1, colorScheme: 'dark' });
    await page.route('**/*', route => route.abort());
    await page.setContent(html);
    await page.locator('#evidence').screenshot({ path: path.join(directory, `${phase}.png`) });
} finally {
    await browser.close();
}
console.log(`${phase}: rendered captured query evidence; ${phase === 'after' ? 'all result hashes and query bounds verified' : 'baseline recorded'}.`);
