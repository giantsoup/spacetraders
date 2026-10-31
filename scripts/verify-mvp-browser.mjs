import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';

const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const php = process.env.PHP_BIN || 'php';
const root = path.resolve(import.meta.dirname, '..');
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, encoding: 'utf8' }));
const metadata = cli("echo json_encode(['fixture'=>config('gameplay.fixture_path'),'origin'=>config('app.url'),'fresh'=>Tests\\Support\\FakeSpaceTraders::world(),'setupUsed'=>App\\Models\\ShipAction::where('command','buy_ship')->exists(),'active'=>App\\Models\\AutomationRun::whereNotNull('active_agent_id')->exists(),'pending'=>App\\Models\\ShipAction::whereIn('state',['pending','sending','uncertain'])->orWhere('review_required',true)->exists()]);");
assert.ok(metadata.fixture && metadata.fresh.symbol === 'LOCAL', 'An explicitly configured synthetic provider is required.');
assert.equal(metadata.active || metadata.pending, false, 'Settle existing owned fixture gameplay before this check.');
assert.equal(metadata.setupUsed, false, 'This journey needs a fresh fixture: the one-time setup purchase is already recorded. Create a new owned fixture before rerunning.');
const fixtureDirectory = path.dirname(await fs.realpath(metadata.fixture));
const auth = JSON.parse(await fs.readFile(path.join(fixtureDirectory, 'browser.json'), 'utf8'));
assert.ok(Number.isSafeInteger(auth.agent_id) && auth.agent_id > 0, 'Fixture must have one saved synthetic agent.');
const origin = process.env.QA_URL || metadata.origin;
assert.ok(new URL(origin).hostname.endsWith('.test') || ['localhost', '127.0.0.1'].includes(new URL(origin).hostname));
assert.equal(new URL(origin).origin, new URL(metadata.origin).origin, 'Use this checkout\'s configured local origin.');
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/browser-replay'));
await fs.mkdir(output, { recursive: true, mode: 0o700 });
let journeyError;
const results = [], diagnostics = { pageErrors: [], console: [], responses: [], deniedExternal: [], failedLocal: [] };
const state = () => cli("echo json_encode(['run'=>App\\Models\\AutomationRun::latest('id')->first(),'actions'=>App\\Models\\ShipAction::latest('id')->limit(20)->get(),'credits'=>App\\Models\\Agent::first()->credits]);");
const world = async mutate => {
    const data = JSON.parse(await fs.readFile(metadata.fixture, 'utf8'));
    if (mutate) { mutate(data); await fs.writeFile(metadata.fixture, JSON.stringify(data), { mode: 0o600 }); }
    return data;
};
await fs.writeFile(metadata.fixture, JSON.stringify(metadata.fresh), { mode: 0o600 });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
await context.route('**/*', route => {
    if (new URL(route.request().url()).origin === new URL(origin).origin) return route.continue();
    diagnostics.deniedExternal.push(new URL(route.request().url()).hostname);
    return route.abort();
});
const page = await context.newPage();
page.setDefaultTimeout(15000);
page.on('pageerror', error => diagnostics.pageErrors.push(error.message));
page.on('console', message => { if (message.type() === 'error') diagnostics.console.push(message.text()); });
page.on('response', response => { if (response.status() >= 400) diagnostics.responses.push({ status: response.status(), path: new URL(response.url()).pathname }); });
page.on('requestfailed', request => {
    if (new URL(request.url()).origin === new URL(origin).origin) diagnostics.failedLocal.push({ path: new URL(request.url()).pathname, error: request.failure()?.errorText });
});
const button = label => page.getByRole('button', { name: label, exact: true });
const record = async (name, work) => { await work(); results.push({ scenario: name, status: 'pass' }); console.log(`PASS ${name}`); };
async function clickResponse(label, suffix) {
    const response = page.waitForResponse(r => new URL(r.url()).pathname.endsWith(suffix) && r.request().method() === 'POST');
    await button(label).click();
    const received = await response;
    const data = await received.json();
    assert.equal(received.status(), 202, data.message || JSON.stringify(data.errors));
    return data;
}
async function refresh() {
    await page.reload();
    await page.getByRole('heading', { level: 1, name: 'LOCAL', exact: true }).waitFor();
}
async function drain() {
    execFileSync(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, stdio: 'pipe' });
    await refresh();
}
async function sync() { await clickResponse('Synchronize', '/sync'); await refresh(); }
async function command(label) {
    const action = await clickResponse(label, '/commands/preview');
    assert.equal(await page.locator('.approval').evaluate(panel => panel === document.activeElement), true);
    await clickResponse('Approve command', `/commands/${action.id}/approve`);
    await drain();
    const saved = state().actions.find(a => a.id === action.id);
    assert.equal(saved.state, 'confirmed', saved.message);
    return saved;
}
async function limits(reserve = '2000') {
    await page.getByLabel('Mining site', { exact: true }).selectOption('X1-TEST-ROCK');
    await page.getByLabel('Sale and refueling market', { exact: true }).selectOption('X1-TEST-ROCK');
    await page.getByLabel('Protected credit reserve', { exact: true }).fill(reserve);
    await page.getByLabel('Maximum fuel spending', { exact: true }).fill('100');
    await page.getByLabel('Maximum credit loss', { exact: true }).fill('100');
}
async function shot(name, fullPage = false) { await page.screenshot({ path: path.join(output, name), fullPage, animations: 'disabled' }); }
try {
    await record('login and explicit synchronization', async () => {
        await page.goto(origin + '/login');
        await page.getByLabel('Email', { exact: true }).fill(auth.email);
        await page.getByLabel('Password', { exact: true }).fill(auth.password);
        await button('Log in').click();
        await page.waitForURL('**/agents');
        await page.goto(`${origin}/agents/${auth.agent_id}`);
        await sync();
        const hide = button('Hide badge for this session');
        if (await hide.count()) await hide.click();
    });
    await record('keyboard skip link and visible focus', async () => {
        await page.goto(`${origin}/agents/${auth.agent_id}`);
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.textContent.trim()), 'Skip to command controls');
        assert.equal(await page.evaluate(() => getComputedStyle(document.activeElement).outlineWidth), '3px');
        await shot('01-skip-focus.png');
        await page.keyboard.press('Enter');
        assert.equal(new URL(page.url()).hash, '#manual');
    });
    await record('manual orbit and mining with persisted reload', async () => {
        await command('Preview orbit');
        await command('Preview mining');
        await refresh();
        assert.ok((await world()).ships[0].cargo.units > 0);
        await shot('02-manual-mine-reload.png', true);
    });
    await record('docking and cargo sale in permitted batches', async () => {
        await command('Preview docking');
        while ((await world()).ships[0].cargo.units) {
            const data = await world(), item = data.ships[0].cargo.inventory[0], quote = data.goods.find(g => g.symbol === item.symbol);
            const units = Math.min(item.units, quote.tradeVolume);
            await page.getByLabel('Trade good', { exact: true }).selectOption(item.symbol);
            await page.getByLabel('Quantity / refuel tank units', { exact: true }).fill(String(units));
            await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill(String(units * quote.sellPrice));
            await command('Preview cargo sale');
        }
        assert.equal((await world()).credits, 100240);
    });
    await record('numeric reserve blocker, mining preview and durable offline Stop', async () => {
        await limits('1');
        const response = page.waitForResponse(r => new URL(r.url()).pathname.endsWith('/runs/preview'));
        await button('Preview mining run').click();
        const denied = await response;
        assert.equal(denied.status(), 422);
        assert.match((await denied.json()).message, /at least 1003 CR/);
        await limits();
        const preview = await clickResponse('Preview mining run', '/runs/preview');
        await shot('03-run-preview.png', true);
        await clickResponse('Start approved mining run', `/runs/${preview.id}/start`);
        await refresh();
        assert.equal(await page.locator('#manual fieldset').evaluate(element => element.disabled), true);
        await button('Stop automation').scrollIntoViewIfNeeded();
        await shot('04-active-stop.png');
        const writes = (await world()).writes.length;
        await clickResponse('Stop automation', `/runs/${preview.id}/stop`);
        await drain();
        assert.equal(state().run.state, 'stopped');
        assert.equal((await world()).writes.length, writes);
        assert.equal(await page.locator('#manual fieldset').evaluate(element => element.disabled), false);
    });
    await record('complete bounded mining trip through database worker and dispatch', async () => {
        await limits();
        const preview = await clickResponse('Preview mining run', '/runs/preview');
        await clickResponse('Start approved mining run', `/runs/${preview.id}/start`);
        for (let step = 0; step < 15 && state().run.active_agent_id; step++) {
            execFileSync(php, ['artisan', 'spacetraders:dispatch-due'], { cwd: root, stdio: 'pipe' });
            await drain();
        }
        const run = state().run;
        assert.equal(run.state, 'completed', run.blocked_reason);
        assert.deepEqual(run.totals.extracted, run.totals.sold);
        assert.equal(run.totals.cycles, 1);
        await page.getByRole('heading', { name: 'Recorded outcomes', exact: true }).scrollIntoViewIfNeeded();
        await shot('05-trip-outcomes.png', true);
    });
    await record('lost response, uncertainty and idempotent reconciliation', async () => {
        await world(w => { w.failure = 'timeout_after'; });
        const action = await clickResponse('Preview orbit', '/commands/preview');
        await clickResponse('Approve command', `/commands/${action.id}/approve`);
        await drain();
        assert.equal(state().actions.find(a => a.id === action.id).state, 'uncertain');
        await page.locator('.activity').scrollIntoViewIfNeeded();
        await shot('06-uncertain.png', true);
        const writes = (await world()).writes.length;
        await clickResponse(`Reconcile #${action.id}`, `/commands/${action.id}/reconcile`);
        await refresh();
        assert.equal(state().actions.find(a => a.id === action.id).state, 'confirmed');
        assert.equal((await world()).writes.length, writes);
    });
    await record('separately approved one-time complete miner purchase', async () => {
        await world(w => { w.ships[0].mounts = []; });
        await sync();
        await limits();
        await page.getByLabel('Observed shipyard offer', { exact: true }).selectOption('0');
        await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill('20000');
        await page.getByLabel('Protected credits', { exact: true }).fill('2000');
        const preview = await clickResponse('Preview one-time miner purchase', '/commands/preview');
        await shot('07-setup-preview.png', true);
        await clickResponse('Approve one-time purchase', `/commands/${preview.id}/approve`);
        await drain();
        assert.equal((await world()).ships.length, 2);
        assert.equal((await world()).writes.filter(w => w.path === '/my/ships').length, 1);
    });
    await record('dark narrow layout, contained tables and activity', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth), 390);
        assert.equal(await page.locator('.bridge').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
        await shot('08-narrow-full.png', true);
        assert.deepEqual(diagnostics.pageErrors, []);
    });
} catch (error) {
    const message = String(error.message).replaceAll(auth.password, '[redacted]');
    results.push({ scenario: 'browser journey', status: 'failed', message });
    await shot('failed.png', true).catch(() => {});
    journeyError = error;
    error.message = message;
    if (error.stack) error.stack = String(error.stack).replaceAll(auth.password, '[redacted]');
    throw error;
} finally {
    let cleanupError;
    try {
        const cleanup = cli(`$runs=App\\Models\\AutomationRun::where('agent_id',${auth.agent_id})->whereNotNull('active_agent_id')->get(); foreach($runs as $run){app(App\\Gameplay\\MiningRunner::class)->stop($run);} echo json_encode(['stop_requested_for'=>$runs->modelKeys()]);`);
        if (cleanup.stop_requested_for.length) {
            execFileSync(php, ['artisan', 'spacetraders:dispatch-due'], { cwd: root, stdio: 'pipe' });
            execFileSync(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, stdio: 'pipe' });
        }
        const remaining = cli(`echo json_encode(App\\Models\\AutomationRun::where('agent_id',${auth.agent_id})->whereNotNull('active_agent_id')->exists());`);
        assert.equal(remaining, false, 'Cleanup could not settle automation; inspect saved state before takeover.');
        diagnostics.cleanup = cleanup;
    } catch (error) {
        cleanupError = error;
        diagnostics.cleanup = { error: String(error.message).replaceAll(auth.password, '[redacted]'), stopUnverified: true };
    }
    try {
        await fs.writeFile(path.join(output, 'results.json'), JSON.stringify({ results, diagnostics, origin, engine: await browser.version(), fakeProvider: true }, null, 2));
    } finally {
        await browser.close();
    }
    if (cleanupError && !journeyError) {
        cleanupError.message = String(cleanupError.message).replaceAll(auth.password, '[redacted]');
        if (cleanupError.stack) cleanupError.stack = String(cleanupError.stack).replaceAll(auth.password, '[redacted]');
        throw cleanupError;
    }
}
