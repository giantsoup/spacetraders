import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory?.startsWith('/private/var/') || directory?.startsWith('/var/') || directory?.startsWith('/tmp/'));
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `opportunity_${port}`, CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/opportunity-evidence'));
await fs.mkdir(output, { recursive: true });
const world = async change => {
    const value = JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8'));
    change(value);
    await fs.writeFile(parsed.SPACETRADERS_FIXTURE_PATH, JSON.stringify(value));
};
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
const serverReady = once(server.stderr, 'data');
const errors = [], steps = [];
const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === origin) return route.continue();
    if (new URL(route.request().url()).hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
    return route.abort();
});
const page = await context.newPage();
page.setDefaultTimeout(15000);
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('response', response => { if (response.status() >= 500) errors.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
const button = name => page.getByRole('button', { name, exact: true });
const state = () => cli("echo json_encode(['runs'=>App\\Models\\AutomationRun::latest('id')->get(),'actions'=>App\\Models\\ShipAction::orderBy('id')->get(),'ships'=>App\\Models\\Ship::all()]);");
async function click(name, suffix) {
    const waiting = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(suffix));
    await button(name).click();
    const response = await waiting;
    const result = await response.json();
    assert.equal(response.status(), 202, result.message || JSON.stringify(result.errors));
    return result;
}
async function drain() {
    for (let pass = 0; pass < 45 && state().runs.some(run => run.active_agent_id); pass++) {
        cli("App\\Models\\AutomationRun::whereNotNull('active_agent_id')->update(['next_action_at'=>now(),'last_dispatched_at'=>null]); Illuminate\\Support\\Facades\\Artisan::call('spacetraders:dispatch-due'); echo '{}';");
        execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
        if (state().runs[0]?.state === 'blocked') throw new Error(state().runs[0].blocked_reason);
    }
    assert.equal(state().runs[0].state, 'completed');
    await page.reload();
}
async function limits() {
    for (const [label, value] of [['Protected reserve in CR', '1000'], ['Maximum total spending in CR', '1000'], ['Maximum cash drawdown in CR', '1000'], ['Maximum travel fuel in tank units', '30']]) await page.getByLabel(label, { exact: true }).fill(value);
}
try {
    await serverReady;
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    await click('Synchronize', '/sync');
    await page.getByRole('heading', { name: 'Local intelligence and opportunities' }).waitFor();
    assert.match(await page.locator('#opportunities').innerText(), /Prices unknown/);
    assert.match(await page.locator('#opportunities').innerText(), /Complete known geography; hidden traits remain unresolved/);
    steps.push('Coverage and independently dated market evidence rendered.');
    await limits();
    await page.getByLabel('Observation question', { exact: true }).selectOption('X1-TEST-PORT');
    const scout = await click('Preview observation trip', '/runs/preview');
    await page.getByRole('heading', { name: 'Review scout operation' }).waitFor();
    assert.equal(await page.locator('.approval').evaluate(element => element === document.activeElement), true);
    await click('Start approved scout run', `/runs/${scout.id}/start`);
    await drain();
    assert.equal(state().runs[0].totals.visits, 1);
    steps.push('One purposeful scout visit collected prices, returned home, refueled and ended through real workers.');
    await world(value => {
        const observer = structuredClone(value.ships[0]);
        observer.symbol = 'LOCAL-2';
        observer.nav.waypointSymbol = 'X1-TEST-PORT';
        value.ships.push(observer);
        value.market_goods = { 'X1-TEST-PORT': structuredClone(value.goods) };
        value.market_goods['X1-TEST-PORT'][0].sellPrice = 60;
    });
    await click('Synchronize', '/sync');
    await page.getByLabel('Operation', { exact: true }).selectOption('trade');
    await limits();
    await page.getByLabel('Buyer', { exact: true }).selectOption('X1-TEST-PORT');
    await page.getByLabel('Trade symbol', { exact: true }).fill('IRON_ORE');
    await page.getByLabel('Total inventory units', { exact: true }).fill('10');
    await page.getByLabel('Maximum inventory exposure in CR', { exact: true }).fill('500');
    await page.getByLabel('Minimum sale price per owned unit', { exact: true }).fill('20');
    const trade = await click('Preview trade cycle', '/runs/preview');
    await page.getByRole('heading', { name: 'Review trade operation' }).waitFor();
    await click('Start approved trade run', `/runs/${trade.id}/start`);
    await drain();
    assert.equal(state().runs[0].totals.purchase_cost, 300);
    assert.equal(state().runs[0].totals.cash_contribution, 295);
    steps.push('Trade bought and sold 4/4/2 batches, returned home, and deducted acquisition and fuel.');
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1100 });
        await page.locator('#opportunities').scrollIntoViewIfNeeded();
        assert.equal(await page.locator('.app-shell').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(output, `intelligence-${width}.png`), animations: 'disabled' });
        await page.getByRole('heading', { name: 'Bounded scouting and trading' }).scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `operations-${width}.png`), animations: 'disabled' });
    }
    assert.deepEqual(errors, []);
    const result = { result: 'pass', steps, errors, fixture: directory, evidence: output };
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
} catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true });
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message.replaceAll(auth.password, '[redacted]'), errors }, null, 2));
    throw error;
} finally {
    await browser.close();
    server.kill('SIGTERM');
}
