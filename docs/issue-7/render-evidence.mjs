import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from 'playwright';

const directory = path.resolve(process.argv[2] || 'docs/issue-7/evidence/2026-11-01-ops01');
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;');
const browser = await chromium.launch({ headless: true });
try {
    const page = await browser.newPage({ viewport: { width: 1120, height: 760 }, colorScheme: 'dark', deviceScaleFactor: 1 });
    await page.route('**/*', route => route.abort());
    for (const phase of ['before', 'after']) {
        const transcript = await fs.readFile(path.join(directory, `${phase}-summary.txt`), 'utf8');
        const html = `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="color-scheme" content="dark"><title>OPS01 ${phase} CLI evidence</title>
<style>*{box-sizing:border-box}body{margin:0;padding:32px;background:#0b1120;color:#e2e8f0;font:16px system-ui}h1{font-size:28px;margin:0 0 10px}p{color:#a8bbd4;margin:0 0 22px}pre{margin:0;padding:24px;background:#111d30;border:1px solid #334761;border-radius:10px;white-space:pre-wrap;overflow-wrap:anywhere;font:16px/1.5 ui-monospace,monospace}footer{margin-top:18px;color:#a8bbd4;font-size:14px}</style>
<h1>OPS01 / ${phase === 'before' ? 'Before' : 'After'}</h1><p>Rendered CLI transcript. Captured command output, not application UI.</p><pre>${escape(transcript)}</pre><footer>Owned offline fixtures · same verification suite · 1120 × 760 viewport<br>QA01 repeat failures are retained separately in both runtime evidence logs.</footer></html>`;
        await fs.writeFile(path.join(directory, `${phase}-cli.html`), html);
        await page.setContent(html);
        if (await page.evaluate(() => document.documentElement.scrollHeight > innerHeight || document.querySelector('footer').getBoundingClientRect().bottom > 500)) throw new Error('CLI evidence exceeds the fixed crop');
        await page.screenshot({ path: path.join(directory, `${phase}-cli.png`), clip: { x: 0, y: 0, width: 1120, height: 500 }, animations: 'disabled' });
    }
} finally {
    await browser.close();
}
