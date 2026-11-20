import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, readFile, rm, writeFile, realpath } from 'node:fs/promises';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../../', import.meta.url));
const evidence = process.env.CAMPAIGN_STATUS_EVIDENCE || path.join(tmpdir(), 'campaign-status-evidence');
await mkdir(evidence, { recursive: true });
const directory = await realpath(await mkdtemp(path.join(tmpdir(), 'campaign-status-browser-')));
for (const folder of ['sessions', 'views']) await mkdir(path.join(directory, folder));
await writeFile(path.join(directory, 'campaign.sqlite'), '');
const php = process.env.HERD_PHP || 'C:\\Users\\taylo\\.config\\herd\\bin\\php85\\php.exe';
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
function seed(empty = false) {
    const result = spawnSync(php, [router, ...(empty ? ['--empty'] : [])], { cwd: root, env, windowsHide: true, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stdout + result.stderr);
}
seed();
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const { port } = reservation.address();
await new Promise(resolve => reservation.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', 'public', router], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', chunk => { serverLog += chunk; });
server.stderr.on('data', chunk => { serverLog += chunk; });
let browser;
const errors = [];
const external = [];
try {
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            const response = await fetch(`${origin}/campaign`);
            assert.equal(response.status, 200, await response.text());
            break;
        } catch (error) {
            if (attempt === 39 || server.exitCode !== null) throw new Error(`${error}\n${serverLog}`);
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
    browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH } : {}) });
    const context = await browser.newContext();
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin !== origin) {
            external.push(route.request().url());
            return route.abort();
        }
        return route.continue();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    const results = [];
    async function capture(name, width) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto(`${origin}/campaign`, { waitUntil: 'networkidle' });
        await page.getByRole('heading', { name: 'Campaign status', exact: true }).waitFor();
        const layout = await page.evaluate(() => ({
            width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
            headings: [...document.querySelectorAll('section.app-panel > h2, section.app-panel > div > h2')].map(heading => heading.textContent),
            campaignCurrent: document.querySelector('nav a[href$="/campaign"]')?.getAttribute('aria-current'),
            gutter: getComputedStyle(document.querySelector('main')).paddingLeft,
            actionHeights: [...document.querySelectorAll('.app-form-actions button')].map(button => button.getBoundingClientRect().height),
        }));
        assert.ok(layout.scrollWidth <= width, JSON.stringify(layout));
        assert.deepEqual(layout.headings, name === 'empty' ? ['Status'] : ['Status', 'Now', 'This wipe', 'Ships', 'Recent loads', 'Recent events']);
        assert.equal(layout.campaignCurrent, 'page');
        for (const height of layout.actionHeights) assert.ok(height >= 44);
        if (width === 390) assert.equal(layout.gutter, '16px');
        const screenshot = path.join(evidence, `${name}-${width}.png`);
        await page.screenshot({ path: screenshot, fullPage: true });
        results.push({ name, ...layout, screenshot });
    }
    await capture('running', 390);
    await capture('running', 1280);
    await page.getByRole('button', { name: 'Pause campaign', exact: true }).click();
    await page.getByRole('button', { name: 'Resume campaign', exact: true }).waitFor();
    assert.equal(await page.getByRole('status').filter({ hasText: 'Campaign paused.' }).count(), 1);
    await capture('paused', 390);
    await page.getByRole('button', { name: 'Resume campaign', exact: true }).click();
    await page.getByRole('button', { name: 'Pause campaign', exact: true }).waitFor();
    assert.equal(await page.getByRole('status').filter({ hasText: 'Campaign resumed;' }).count(), 1);
    const matrixChecks = [];
    if (process.env.CAMPAIGN_SHIP_MATRIX === '1') {
        const { scenarios } = JSON.parse(await readFile(path.join(root, 'tests/Fixtures/ship-information.json'), 'utf8'));
        const fleet = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Ships', exact: true }) });
        const card = symbol => fleet.locator(':scope > ul > li').filter({ has: page.getByRole('heading', { name: symbol, exact: true }) });
        await page.setViewportSize({ width: 390, height: 900 });
        await page.evaluate(() => document.activeElement?.blur());
        await card('QA-1').screenshot({ path: path.join(evidence, 'probe-overview-390.png') });
        for (const width of [320, 390, 820]) {
            await page.setViewportSize({ width, height: 900 });
            for (const scenario of scenarios) {
                const ship = card(scenario.ship.symbol);
                await ship.locator('.ship-details summary').evaluateAll(summaries => {
                    for (const summary of summaries) if (!summary.parentElement.open) summary.click();
                });
                const exact = JSON.parse(await ship.locator('.ship-details pre').innerText());
                assert.equal(exact.symbol, scenario.ship.symbol);
                for (const kind of ['mounts', 'modules']) {
                    if (Array.isArray(scenario.ship[kind])) {
                        assert.equal(exact[kind].length, scenario.ship[kind].length);
                        const equipment = ship.locator('.ship-equipment').nth(kind === 'mounts' ? 0 : 1);
                        assert.equal(await equipment.locator(':scope > ol > li').count(), scenario.ship[kind].length);
                        for (const part of scenario.ship[kind]) assert.ok((await equipment.innerText()).includes(part.symbol));
                    }
                }
                const dimensions = await ship.evaluate(element => ({
                    overflow: element.scrollWidth > element.clientWidth,
                    shortSummaries: [...element.querySelectorAll('summary')].filter(summary => summary.getBoundingClientRect().height < 44).map(summary => summary.textContent),
                }));
                assert.equal(dimensions.overflow, false, scenario.id + ' at ' + width);
                assert.deepEqual(dimensions.shortSummaries, [], scenario.id + ': touch targets');
                assert.ok(!(await ship.innerText()).includes('private-'), 'Excluded values cannot reach any disclosure');
                if (scenario.id === 'distinct-duplicates') {
                    assert.equal(exact.frame.quality, 3);
                    assert.equal(exact.frame.condition, 0.991);
                    assert.equal(exact.frame.integrity, 0.994);
                    assert.equal(exact.mounts[0].strength, 2);
                    assert.equal(exact.mounts[1].strength, 9);
                    assert.equal(exact.mounts[1].requirements.power, 7);
                }
            }
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
            await page.evaluate(() => document.activeElement?.blur());
            await card('QA-DISTINCT-DUPLICATES').locator('.ship-equipment').first().evaluate(element => element.scrollIntoView({ block: 'start' }));
            await page.screenshot({ path: path.join(evidence, 'equipment-' + width + '.png') });
            matrixChecks.push({ width, scenarios: scenarios.length, overflow: false, minSummaryHeight: 44 });
        }
        await page.setViewportSize({ width: 390, height: 900 });
        const duplicate = card('QA-DISTINCT-DUPLICATES');
        const outer = duplicate.locator('.ship-details');
        const summary = outer.locator(':scope > summary');
        await summary.focus();
        await page.keyboard.press('Enter');
        assert.equal(await outer.evaluate(element => element.open), false);
        await page.keyboard.press('Space');
        assert.equal(await outer.evaluate(element => element.open), true);
        await outer.locator(':scope > details').filter({ has: page.getByText('Exact observed values (JSON)', { exact: true }) }).locator(':scope > summary').scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(evidence, 'expanded-details-390.png') });
    }
    // Advance the browser clock to prove usePoll requests fresh props.
    await page.clock.install();
    const poll = page.waitForResponse(response => response.url().endsWith('/campaign') && response.request().headers()['x-inertia'] === 'true');
    await page.clock.fastForward(15001);
    assert.equal((await poll).status(), 200);
    if (process.env.CAMPAIGN_SHIP_MATRIX === '1') {
        await page.clock.runFor(100);
        const duplicate = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Ships', exact: true }) }).locator(':scope > ul > li').filter({ has: page.getByRole('heading', { name: 'QA-DISTINCT-DUPLICATES', exact: true }) });
        assert.equal(await duplicate.locator('.ship-details').evaluate(element => element.open), true);
        const exact = JSON.parse(await duplicate.locator('.ship-details pre').innerText());
        assert.equal(exact.mounts[0].requirements.power, 2);
        assert.equal(exact.mounts[1].requirements.power, 7);
    }
    seed(true);
    await capture('empty', 390);
    assert.equal(await page.getByText('No campaign approved.', { exact: true }).count(), 1);
    assert.equal(await page.getByRole('button', { name: /(?:Pause|Resume) campaign/ }).count(), 0);
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    await writeFile(path.join(evidence, 'results.json'), JSON.stringify({ results, matrixChecks, errors, external, pauseResume: 'passed', poll: 'passed' }, null, 2));
    console.log(JSON.stringify({ evidence, results, errors, external }, null, 2));
} finally {
    await browser?.close();
    if (server.exitCode === null) {
        const stopped = once(server, 'exit');
        server.kill();
        await stopped;
    }
    await writeFile(path.join(evidence, 'server.log'), serverLog);
    assert.equal(path.dirname(directory), await realpath(tmpdir()));
    assert.ok(path.basename(directory).startsWith('campaign-status-browser-'));
    await rm(directory, { recursive: true, force: true });
}
