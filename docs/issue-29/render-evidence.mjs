import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';

const root = path.resolve(import.meta.dirname, '../..');
const output = path.join(import.meta.dirname, 'evidence/2026-11-01-mob01');
const baseline = 'f58177bab2cba7d5af69c8443fb6c72f26caa2ae';
const source = 'docs/ios-app-plan.md';
const mode = process.argv[2];
assert.ok(['before', 'after'].includes(mode), 'Use before or after.');
const { default: MarkdownIt } = await import(process.env.MARKDOWN_IT_MODULE || 'markdown-it');
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const md = new MarkdownIt({ html: false });
const slug = text => text.toLowerCase().replace(/[^\p{L}\p{N}\s_-]/gu, '').replace(/\s/g, '-');
md.renderer.rules.heading_open = (tokens, index) => {
  const headingText = tokens[index + 1].content;
  return `<${tokens[index].tag} id="${escape(slug(headingText))}">`;
};
const defaultLink = md.renderer.rules.link_open || ((tokens, index, options, env, self) => self.renderToken(tokens, index, options));
md.renderer.rules.link_open = (tokens, index, options, env, self) => {
  const href = tokens[index].attrGet('href');
  if (href && ! /^(?:[a-z]+:|#)/i.test(href)) {
    const target = path.resolve(path.dirname(path.join(root, env.sourcePath)), href);
    tokens[index].attrSet('href', mode === 'before'
      ? `https://github.com/giantsoup/spacetraders/blob/${baseline}/${path.relative(root, target)}`
      : path.relative(output, target));
  }
  return defaultLink(tokens, index, options, env, self);
};
const content = mode === 'before'
  ? execFileSync('git', ['show', `${baseline}:${source}`], { cwd: root, encoding: 'utf8' })
  : await fs.readFile(path.join(root, source), 'utf8');
const heading = mode === 'before' ? 'Decisions for later' : 'First release decisions';
const section = content.split(`## ${heading}\n`)[1]?.split('\n## ')[0];
assert.ok(section, `Missing ${heading}`);
const sha256 = createHash('sha256').update(content).digest('hex');
const escape = value => md.utils.escapeHtml(value);
const css = `:root{color-scheme:dark}*{box-sizing:border-box}body{margin:0;background:#070d17;color:#e8eef6;font:17px/1.6 -apple-system,BlinkMacSystemFont,Arial,sans-serif}main{max-width:1040px;margin:auto;padding:28px 36px}header{border-bottom:1px solid #34445b;padding-bottom:18px;margin-bottom:24px;color:#b1c0d3;font-size:14px}h1{font-size:30px;line-height:1.25}h2{font-size:24px;line-height:1.3;margin:28px 0 16px}h3{font-size:20px}a{color:#a6f3d0}code{font:14px/1.5 monospace;overflow-wrap:anywhere}pre{white-space:pre-wrap}table{border-collapse:collapse;width:100%;font-size:16px}th,td{padding:11px 14px;border:1px solid #34445b;text-align:left;vertical-align:top}th{background:#162337}li{margin:9px 0}#focus{padding:8px 20px 20px;background:#0d1524;border:1px solid #34445b;border-radius:12px}#focus h2{margin-top:16px}footer{color:#b1c0d3;font-size:14px;margin-top:24px}`;
function documentHtml(text, title, focus = false, sourcePath = source) {
  const env = { sourcePath };
  const rendered = focus ? `<section id="focus">${md.render(`## ${heading}\n${section}`, env)}</section>` : md.render(text, env);
  const digest = createHash('sha256').update(text).digest('hex');
  return `<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${escape(title)}</title><style>${css}</style><main><header>MOB01 · ${mode.toUpperCase()} · Rendered document evidence, not an iPhone app<br>${escape(sourcePath)} · ${mode === 'before' ? baseline : 'working document SHA-256 ' + digest.slice(0, 16)}</header>${rendered}<footer>Offline document rendering. No mobile client, engine deployment, or gameplay is represented.</footer></main></html>`;
}
await fs.mkdir(output, { recursive: true });
await fs.writeFile(path.join(output, `${mode}-plan.md`), content);
await fs.writeFile(path.join(output, `${mode}-focus.html`), documentHtml(content, heading, true));
await fs.writeFile(path.join(output, `${mode}-context.html`), documentHtml(content, 'iPhone release plan'));
let verifiedLocalLinks = 0;
const renderedSources = { [source]: sha256 };
if (mode === 'after') {
  const documents = [source, 'docs/adr/0001-first-iphone-release.md',
    'docs/plan-tasks/mob01-define-the-first-iphone-release-workflow-and-technical-decisions.md', 'docs/issue-29/README.md', 'README.md'];
  const decisionPath = documents[1];
  const decisionText = await fs.readFile(path.join(root, decisionPath), 'utf8');
  renderedSources[decisionPath] = createHash('sha256').update(decisionText).digest('hex');
  await fs.writeFile(path.join(output, 'decision.html'), documentHtml(decisionText, 'First iPhone release decision', false, decisionPath));
  for (const filename of documents) {
    const text = await fs.readFile(path.join(root, filename), 'utf8');
    for (const match of text.matchAll(/!?\[[^\]]*\]\(([^\s)]+)\)/g)) {
      const href = match[1];
      if (/^[a-z]+:/i.test(href)) continue;
      const [relative, anchor] = href.split('#');
      const target = relative ? path.resolve(path.dirname(path.join(root, filename)), relative) : path.join(root, filename);
      await fs.access(target);
      if (anchor && target.endsWith('.md')) {
        const targetText = await fs.readFile(target, 'utf8');
        const headings = [...targetText.matchAll(/^#{1,6} (.+)$/gm)].map(m => slug(m[1]));
        assert.ok(headings.includes(anchor), `Missing anchor ${href} in ${filename}`);
      }
      verifiedLocalLinks++;
    }
  }
}
const externalRequests = [];
const errors = [];
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
try {
  const context = await browser.newContext({ viewport: { width: 1120, height: 900 }, deviceScaleFactor: 1, colorScheme: 'light', timezoneId: 'UTC' });
  await context.route('**/*', route => {
    if (route.request().url().startsWith('file:')) return route.continue();
    externalRequests.push(route.request().url());
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  const layouts = [];
  for (const kind of ['focus', 'context', ...(mode === 'after' ? ['decision'] : [])]) {
    await page.goto(pathToFileURL(path.join(output, kind === 'decision' ? 'decision.html' : `${mode}-${kind}.html`)).href);
    await page.evaluate(() => document.fonts.ready);
    assert.equal(await page.evaluate(() => getComputedStyle(document.documentElement).colorScheme), 'dark');
    const layout = await page.evaluate(() => ({ width: innerWidth, height: innerHeight, scrollWidth: document.documentElement.scrollWidth }));
    assert.ok(layout.scrollWidth <= layout.width, 'Document overflows horizontally.');
    layouts.push({ kind, ...layout });
    if (kind === 'focus') await page.locator('#focus').screenshot({ path: path.join(output, `${mode}-focus.png`) });
    else if (kind === 'context') await page.screenshot({ path: path.join(output, `${mode}-context.png`) });
  }
  assert.deepEqual(externalRequests, []);
  assert.deepEqual(errors, []);
  await fs.writeFile(path.join(output, `${mode}-render.json`), JSON.stringify({
    mode, source, baseline, sourceSha256: sha256, renderedSources, browser: browser.version(), viewport: { width: 1120, height: 900 },
    deviceScaleFactor: 1, osPreference: 'light', renderedTheme: 'dark', externalRequests, errors, layouts, verifiedLocalLinks,
  }, null, 2) + '\n');
  console.log(`${mode}: dark document screenshots captured; no overflow, page errors, or external requests.`);
} finally {
  await browser.close();
}
