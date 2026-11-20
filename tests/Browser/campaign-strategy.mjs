import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdtemp, mkdir, writeFile, rm, realpath } from 'node:fs/promises';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium, webkit } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const evidence = process.env.CAMPAIGN_STRATEGY_EVIDENCE || await mkdtemp(path.join(tmpdir(), 'campaign-strategy-evidence-'));
const directory = await realpath(await mkdtemp(path.join(tmpdir(), 'campaign-strategy-browser-')));
await mkdir(evidence, { recursive: true });
for (const folder of ['sessions', 'views']) await mkdir(path.join(directory, folder));
await writeFile(path.join(directory, 'campaign.sqlite'), '');
const php = process.env.HERD_PHP || 'php';
const env = {
    ...process.env,
    APP_ENV: 'testing', APP_DEBUG: 'false', APP_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    APP_CONFIG_CACHE: path.join(directory, 'config.php'), APP_ROUTES_CACHE: path.join(directory, 'routes.php'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(directory, 'campaign.sqlite'), DATABASE_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
    SPACETRADERS_LOCAL_ENGINE_ENABLED: 'false', SPACETRADERS_AUTOMATION_ENABLED: 'false', SPACETRADERS_FIXTURE_PATH: '',
    CAMPAIGN_STATUS_BROWSER_DIRECTORY: directory,
};
const router = path.join(root, 'tests/Browser/campaign-status.php');
function seed(state) {
    const result = spawnSync(php, [router, `--strategy-${state}`], { cwd: root, env, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stdout + result.stderr);
}
seed('approved');
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const { port } = reservation.address();
await new Promise(resolve => reservation.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', router], { cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', chunk => { serverLog += chunk; });
server.stderr.on('data', chunk => { serverLog += chunk; });
const errors = [], external = [], results = [];
let browser;
async function pageFor(browser) {
    const context = await browser.newContext({ viewport: { width: 402, height: 874 }, deviceScaleFactor: 3, hasTouch: true, isMobile: true });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== origin) { external.push(route.request().url()); return route.abort(); }
        return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    return page;
}
async function open(page) {
    await page.goto(`${origin}/campaign/strategy`, { waitUntil: 'networkidle' });
    await page.getByRole('heading', { name: 'Authority', exact: true }).waitFor();
}
async function shot(page, name, fullPage = false) {
    await page.screenshot({ path: path.join(evidence, `${name}.png`), fullPage, animations: 'disabled' });
}
async function jump(page, title) {
    if (await page.getByRole('button', { name: 'Steps', exact: true }).isVisible()) {
        await page.getByRole('button', { name: 'Steps', exact: true }).tap();
        await page.getByRole('dialog', { name: 'Steps', exact: true }).getByRole('button', { name: new RegExp(title) }).tap();
    } else {
        await page.getByRole('navigation', { name: 'Walkthrough steps' }).getByRole('button', { name: new RegExp(title) }).click();
    }
    assert.ok(await page.locator('.story-title').evaluate(node => node === document.activeElement));
}
async function layout(page, label) {
    const value = await page.evaluate(() => ({
        width: innerWidth, height: innerHeight, scrollWidth: document.documentElement.scrollWidth,
        shortTargets: [...document.querySelectorAll('button, .campaign-navigation a')].filter(node => node.getClientRects().length).filter(node => {
            const rect = node.getBoundingClientRect(); return rect.height < 44 || rect.width < 44;
        }).map(node => node.textContent.trim()),
    }));
    assert.ok(value.scrollWidth <= value.width, JSON.stringify(value));
    assert.deepEqual(value.shortTargets, []);
    results.push({ label, ...value });
}
try {
    for (let attempt = 0; attempt < 40; attempt++) {
        try { const response = await fetch(`${origin}/campaign/strategy`); assert.equal(response.status, 200, await response.text()); break; }
        catch (error) { if (attempt === 39 || server.exitCode !== null) throw new Error(`${error}\n${serverLog}`); await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    console.log(`Offline app: ${origin}/campaign/strategy; evidence: ${evidence}`);
    await writeFile(path.join(evidence, 'server.json'), JSON.stringify({ origin, directory, root }, null, 2));
    browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const page = await pageFor(browser);
    await open(page);
    await layout(page, 'opening touch DPR 3');
    await shot(page, 'opening-402x874');
    await shot(page, 'opening-full-402', true);
    const branch = page.getByRole('button', { name: /Engine unavailable/ });
    await branch.tap();
    assert.ok(await branch.evaluate(node => node === document.activeElement));
    assert.ok(await page.getByRole('heading', { name: 'Wait for the engine', exact: true }).isVisible());
    assert.match(await page.locator('.outcome').evaluate(node => getComputedStyle(node).animationName), /strategy-reveal/);
    assert.match(await branch.evaluate(node => getComputedStyle(node, '::before').animationName), /strategy-trace/);
    await shot(page, 'expanded-wait-402x874');
    await branch.tap();
    assert.equal(await page.locator('.outcome').count(), 0);
    await branch.tap();
    await page.getByRole('button', { name: /Back/ }).tap();
    assert.equal(await page.locator('.outcome').count(), 0);
    assert.ok(await branch.evaluate(node => node === document.activeElement));
    assert.ok(await page.getByRole('heading', { name: 'Authority', exact: true }).isVisible());
    const opener = page.getByRole('button', { name: 'Steps', exact: true });
    await opener.tap();
    const sheet = page.getByRole('dialog', { name: 'Steps', exact: true });
    await shot(page, 'steps-402x874');
    await page.keyboard.press('Escape');
    await sheet.waitFor({ state: 'hidden' });
    assert.ok(await opener.evaluate(node => node === document.activeElement));
    await opener.tap();
    await sheet.getByRole('button', { name: 'Done', exact: true }).tap();
    assert.ok(await opener.evaluate(node => node === document.activeElement));
    await opener.tap();
    const steps = await sheet.locator('li button').allTextContents();
    assert.equal(steps.length, 8);
    await page.keyboard.press('Escape');
    for (const title of ['Authority', 'World and agent', 'Safety and current work', 'Cargo first', 'Phase and activity', 'Trade selection', 'Proof or scale', 'Start and learn']) {
        await jump(page, title);
        const forks = await page.locator('.story > .branch-list > .branch > .fork').all();
        for (const fork of forks) {
            await fork.tap();
            assert.equal(await fork.getAttribute('aria-expanded'), 'true');
            assert.ok(await fork.evaluate(node => node === document.activeElement));
            assert.equal(await page.locator('.outcome').count(), 1);
            await fork.tap();
            assert.equal(await page.locator('.outcome').count(), 0);
        }
    }
    assert.ok(await page.getByRole('button', { name: /Next step/ }).isDisabled());
    await jump(page, 'Cargo first');
    await page.getByRole('button', { name: /No buyer meets the cargo cost floor/ }).tap();
    await shot(page, 'cargo-fallback-402x874');
    await jump(page, 'Trade selection');
    await page.getByRole('button', { name: /Quotes lack enough freshness/ }).tap();
    await shot(page, 'stale-quote-402x874');
    await jump(page, 'Proof or scale');
    await page.getByRole('button', { name: /No qualifying proof/ }).tap();
    await shot(page, 'proof-402x874');
    await page.getByRole('button', { name: /Proof meets 80%/ }).tap();
    await shot(page, 'scale-402x874');
    await jump(page, 'Phase and activity');
    const phases = page.getByRole('list', { name: 'Playbook phase order' });
    assert.equal(await phases.locator('.phase ol').first().evaluate(node => getComputedStyle(node).listStyleType), 'decimal');
    assert.equal(await phases.locator('.phase ul').first().evaluate(node => getComputedStyle(node).listStyleType), 'disc');
    assert.deepEqual(await phases.locator('h3').allTextContents(), ['1. Discover', '2. Earn', '3. Wind-down']);
    assert.ok(await phases.getByText(/1 eligible trade/).isVisible());
    assert.ok(await page.getByRole('heading', { name: 'Pass outcomes', exact: true }).isVisible());
    await shot(page, 'phases-full-402', true);
    await shot(page, 'phases-402x874');
    await page.getByRole('button', { name: /The final hour begins/ }).tap();
    await shot(page, 'wind-down-402x874');
    await page.getByRole('button', { name: /Back/ }).tap();
    assert.ok(await page.getByRole('heading', { name: 'Phase and activity', exact: true }).isVisible());
    await page.getByRole('button', { name: /Back/ }).tap();
    assert.ok(await page.getByRole('heading', { name: 'Proof or scale', exact: true }).isVisible());
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.getByRole('button', { name: /No qualifying proof/ }).tap();
    assert.equal(await page.locator('.outcome').evaluate(node => getComputedStyle(node).animationName), 'none');
    await page.getByRole('button', { name: /Next step/ }).tap();
    assert.equal(await page.locator('.story').evaluate(node => getComputedStyle(node).animationName), 'none');
    // Complete reading order is independent of branch outcomes.
    await open(page);
    for (let step = 1; step < 8; step++) {
        await page.getByRole('button', { name: /Next step/ }).tap();
        assert.ok((await page.locator('.position').innerText()).includes(`Step ${step + 1} of 8`));
    }
    assert.ok(await page.getByRole('button', { name: /Next step/ }).isDisabled());
    await jump(page, 'Phase and activity');
    for (const viewport of [{ width: 320, height: 874 }, { width: 874, height: 402 }, { width: 728, height: 874 }, { width: 1280, height: 900 }]) {
        await page.setViewportSize(viewport);
        await layout(page, `phase reflow ${viewport.width}x${viewport.height}`);
        await shot(page, `phases-${viewport.width}x${viewport.height}`, true);
    }
    await jump(page, 'Authority');
    await shot(page, 'desktop-1280x900');
    await page.setViewportSize({ width: 402, height: 874 });
    await page.addStyleTag({ content: '.strategy-page { font-size: 200%; } .strategy-page :is(p,button,li,span) { font-size: 1em !important; }' });
    await layout(page, 'enlarged text 200%');
    await shot(page, 'enlarged-text-402', true);
    await open(page);
    // Keyboard activation and focus restoration use the actual app controls.
    await opener.focus();
    await page.keyboard.press('Enter');
    await sheet.waitFor();
    const sheetButtons = sheet.getByRole('button');
    await sheetButtons.last().focus();
    await page.keyboard.press('Tab');
    assert.ok(await sheetButtons.first().evaluate(node => node === document.activeElement));
    await page.keyboard.press('Escape');
    assert.ok(await opener.evaluate(node => node === document.activeElement));
    await page.getByRole('navigation', { name: 'Campaign navigation', exact: true }).getByRole('link', { name: 'Status', exact: true }).tap();
    await page.getByRole('heading', { name: 'Campaign status', exact: true }).waitFor();
    await page.getByRole('navigation', { name: 'Campaign navigation', exact: true }).getByRole('link', { name: 'Strategy', exact: true }).tap();
    await page.getByRole('heading', { name: 'Authority', exact: true }).waitFor();
    for (const state of ['pending', 'paused', 'expired', 'example', 'no-trade']) {
        seed(state);
        await open(page);
        const source = await page.locator('.strategy-source').innerText();
        assert.ok(source.includes({ pending: 'Replacement queued · offline-queued', paused: 'Paused C0 revision', expired: 'Expired C0 revision', example: 'Bundled example · unapproved', 'no-trade': 'offline-survey' }[state]));
        await shot(page, `source-${state}-402x874`);
        if (state === 'no-trade') {
            assert.ok((await page.locator('.position').innerText()).includes('Step 1 of 6'));
            await jump(page, 'Phase and activity');
            assert.ok(await page.getByText('Eligible when: At least 1,000 CR are available', { exact: true }).isVisible());
            await shot(page, 'no-trade-phase-402', true);
            await jump(page, 'Start and learn');
            await page.getByRole('button', { name: /Configured activities yield/ }).tap();
            assert.ok(await page.getByText(/Mining uses a bounded investigation/).isVisible());
            assert.ok(await page.getByRole('button', { name: /Next step/ }).isDisabled());
            await shot(page, 'no-trade-final-402x874');
        }
    }
    // Inject a separate, strategy-neutral graph through a real Inertia navigation.
    // This proves recursive outcomes without adding test-only flags to the product.
    await page.goto(`${origin}/campaign`, { waitUntil: 'networkidle' });
    const graph = {
        root: 'start', chapters: ['start', 'finish'], nodes: {
            start: { id: 'start', kind: 'chapter', title: 'Choose a route', summary: 'A different explanation.', groups: [], links: [{ condition: 'Take the coast', label: 'Explore', target: 'coast', tone: 'continue' }] },
            coast: { id: 'coast', kind: 'outcome', title: 'Along the coast', summary: 'Choose another detail.', links: [{ condition: 'Stop at the harbor', label: 'Wait', target: 'harbor', tone: 'wait' }] },
            harbor: { id: 'harbor', kind: 'outcome', title: 'At the harbor', summary: 'This example path ends here.', links: [] },
            finish: { id: 'finish', kind: 'chapter', title: 'Finish the visit', summary: 'The last reading step.', groups: [], links: [] },
        },
    };
    await page.route(`${origin}/campaign/strategy`, async route => {
        const response = await route.fetch();
        const body = await response.json();
        body.props.graph = graph;
        await route.fulfill({ response, json: body });
    });
    await page.getByRole('navigation', { name: 'Campaign navigation', exact: true }).getByRole('link', { name: 'Strategy', exact: true }).tap();
    await page.getByRole('heading', { name: 'Choose a route', exact: true }).waitFor();
    assert.ok((await page.locator('.position').innerText()).includes('Step 1 of 2'));
    await page.getByRole('button', { name: /Take the coast/ }).tap();
    await page.getByRole('button', { name: /Stop at the harbor/ }).tap();
    assert.ok(await page.getByRole('heading', { name: 'At the harbor', exact: true }).isVisible());
    await page.getByRole('button', { name: /Back/ }).tap();
    assert.equal(await page.getByRole('heading', { name: 'At the harbor', exact: true }).count(), 0);
    assert.ok(await page.getByRole('heading', { name: 'Along the coast', exact: true }).isVisible());
    await page.getByRole('button', { name: /Next step/ }).tap();
    assert.ok(await page.getByRole('heading', { name: 'Finish the visit', exact: true }).isVisible());
    assert.ok(await page.getByRole('button', { name: /Next step/ }).isDisabled());
    await page.unroute(`${origin}/campaign/strategy`);
    let webkitResult;
    try {
        const safari = await webkit.launch({ headless: true, ...(process.env.PLAYWRIGHT_WEBKIT_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_WEBKIT_EXECUTABLE_PATH } : {}) });
        try {
            seed('approved');
            const safariPage = await pageFor(safari);
            await open(safariPage);
            await jump(safariPage, 'Proof or scale');
            await safariPage.getByRole('button', { name: /No qualifying proof/ }).tap();
            assert.ok(await safariPage.getByRole('heading', { name: 'Run a small proof', exact: true }).isVisible());
            await layout(safariPage, 'WebKit touch');
            await shot(safariPage, 'webkit-proof-402x874');
            webkitResult = 'Passed touch opening, jump, proof branch, focus and reflow.';
        } finally { await safari.close(); }
    } catch (error) {
        if (!/Executable doesn't exist|browserType.launch/.test(error.message)) throw error;
        webkitResult = `Unavailable: ${error.message.split('\n')[0]}`;
    }
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    await writeFile(path.join(evidence, 'results.json'), JSON.stringify({ root, evidence, touch: { width: 402, height: 874, deviceScaleFactor: 3, hasTouch: true, isMobile: true }, results, webkit: webkitResult, errors, external }, null, 2));
    console.log(`PASS: all chapters and branches; reading/history; sources; no-trade graph; focus; keyboard; motion; 44px targets; reflow; navigation. WebKit: ${webkitResult}`);
    console.log(`Evidence: ${evidence}`);
} catch (error) {
    await writeFile(path.join(evidence, 'server.log'), serverLog);
    throw error;
} finally {
    await browser?.close();
    server.kill();
    await Promise.race([once(server, 'exit'), new Promise(resolve => setTimeout(resolve, 2000))]);
    await rm(directory, { recursive: true, force: true });
}
