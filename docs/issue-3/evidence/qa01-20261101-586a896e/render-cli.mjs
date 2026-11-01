import fs from 'node:fs/promises';
import path from 'node:path';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const browser = await chromium.launch({
    headless: true,
    ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}),
});
const directory = import.meta.dirname;
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'dark' });
await context.route('**/*', route => route.abort());
try {
    for (const [state, source, title] of [
        ['before', 'before-controlled-clock.txt', 'Original assertion fails across a controlled second'],
        ['after', 'after-repeat.txt', 'Latest price timestamp survives mutation: 25 / 25 pass'],
    ]) {
        const page = await context.newPage();
        await page.setContent(`<!doctype html><html lang="en"><meta charset="utf-8"><style>
            * { box-sizing: border-box; } :root { color-scheme: dark; }
            body { margin: 0; padding: 36px; background: #0c111b; color: #e7edf6; font: 18px system-ui; }
            article { border: 1px solid #334155; border-radius: 12px; padding: 28px; background: #111b2a; }
            h1 { margin: 0 0 10px; font-size: 28px; } p { color: #aebdd2; margin: 0 0 24px; }
            pre { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; font: 17px/1.5 Menlo, monospace; }
        </style><article><h1></h1><p>Rendered CLI evidence. Offline fixture. No application UI change.</p><pre></pre></article></html>`);
        await page.locator('h1').evaluate((element, text) => { element.textContent = text; }, `${state.toUpperCase()} · ${title}`);
        await page.locator('pre').evaluate((element, text) => { element.textContent = text; }, await fs.readFile(path.join(directory, source), 'utf8'));
        await page.locator('article').screenshot({ path: path.join(directory, `${state}-cli.png`) });
        await page.close();
    }
} finally {
    await browser.close();
}
