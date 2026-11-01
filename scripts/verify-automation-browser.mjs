import { shipView } from './ship-browser-navigation.mjs';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory);
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `automation_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr', SPACETRADERS_LOCAL_ENGINE_ENABLED: 'true', SPACETRADERS_LOCAL_HOST: '127.0.0.1', SPACETRADERS_OPERATOR_ID: '1', SPACETRADERS_PHP_BINARY: execFileSync(php, ['-r', 'echo PHP_BINARY;'], { encoding: 'utf8' }) };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/local-automation'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
const errors = [], steps = [];
let failure, installed = false;
const world = async edit => {
    const file = `${directory}/world.json`;
    const value = JSON.parse(await fs.readFile(file, 'utf8'));
    if (edit) { edit(value); await fs.writeFile(file, JSON.stringify(value)); }
    return value;
};
async function waitFor(check, seconds = 20) {
    const deadline = Date.now() + seconds * 1000;
    while (!await check()) {
        if (Date.now() >= deadline) throw new Error('Timed out waiting for offline state');
        await new Promise(resolve => setTimeout(resolve, 200));
    }
}
try {
    await once(server.stderr, 'data');
    execFileSync(php, ['artisan', 'spacetraders:engine-service', 'install'], { cwd: root, env, encoding: 'utf8' });
    installed = true;
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error' && !message.text().includes('422')) errors.push(message.text()); });
    const button = name => page.getByRole('button', { name, exact: true });
    const post = async (name, suffix, status = 202) => {
        const response = page.waitForResponse(r => new URL(r.url()).pathname.endsWith(suffix) && r.request().method() === 'POST');
        const [received] = await Promise.all([response, button(name).click()]);
        assert.equal(received.status(), status, await received.text());
        return received.json();
    };
    const screenshot = async name => {
        await shipView(page, 'automation');
        for (const width of [1440, 390, 320]) {
            await page.setViewportSize({ width, height: 1100 });
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No horizontal overflow at ${width}`);
            const ids = await page.locator('[id]').evaluateAll(nodes => nodes.map(node => node.id));
            assert.equal(ids.length, new Set(ids).size);
            await page.screenshot({ path: path.join(output, `${name}-${width}.png`), fullPage: true });
            const panel = name.includes('preview') ? page.locator('.automation-review') : page.getByRole('region', { name: 'Run monitor and outcome', exact: true });
            await panel.screenshot({ path: path.join(output, `${name}-panel-${width}.png`), style: '.control-strip, .skip { visibility: hidden !important; }' });
        }
        await page.setViewportSize({ width: 1440, height: 1100 });
    };
    const investigate = async strategy => {
        await page.getByRole('radio', { name: strategy, exact: true }).check();
        await post('Investigate opportunities', '/investigations');
        await page.getByText(/Investigation #\d+ · Complete/).waitFor({ timeout: 30000 });
    };
    const stage = async name => {
        await button(name).click();
        await post('Stage plan', '/drafts');
        await page.getByText(/Saved draft #\d+ · Revision 1/).waitFor();
    };
    const waitRun = async state => {
        await waitFor(() => cli(`$r=App\\Models\\AutomationRun::whereNotNull('started_at')->latest('id')->first(); echo json_encode($r?->state->value === '${state}');`), 100);
        await page.reload();
    };
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    await shipView(page, 'automation');
    assert.equal(await page.locator('html').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    await investigate('Mining');
    assert.equal((await world()).writes.length, 0);
    await stage('Select LOCAL-1 X1-TEST-ROCK via X1-TEST-ROCK');
    await page.reload();
    await page.getByText(/Saved draft #\d+ · Revision 1/).waitFor();
    await post('Preview automation', '/preview');
    await page.getByRole('heading', { name: 'Review automation preview', exact: true }).waitFor();
    assert.equal(await button('Approve and start').isDisabled(), true);
    steps.push('Engine-off read-only mining investigation, trial explanation, staging and reload recovery');
    console.log('PASS mining investigation and staged reload');
    await post('Start local engine', '/engine/start');
    await waitFor(() => cli("echo json_encode(App\\Models\\LocalEngine::find(1)?->state === 'running');"));
    await page.reload();
    assert.equal((await world()).writes.length, 0);
    await post('Preview automation', '/preview');
    await world(w => { w.goods[0].sellPrice++; });
    await post('Approve and start', '/start', 422);
    await page.getByRole('alert').filter({ hasText: /changed/ }).waitFor();
    assert.equal((await world()).writes.length, 0);
    steps.push('Start authorizes no gameplay and changed-price approval is rejected');
    await world(w => { w.goods[0].sellPrice--; });
    await post('Preview automation', '/preview');
    await waitFor(() => button('Approve and start').isEnabled());
    await page.keyboard.press('Tab');
    await button('Approve and start').focus();
    assert.equal(await button('Approve and start').evaluate(el => getComputedStyle(el).outlineStyle), 'solid');
    await page.keyboard.press('Tab');
    assert.equal(await button('Refresh preview').evaluate(el => el === document.activeElement), true);
    await screenshot('mining-preview');
    await post('Approve and start', '/start');
    await page.reload();
    await waitRun('completed');
    assert.equal((await world()).writes.filter(w => w.path.endsWith('/extract')).length, 1);
    assert.equal(cli("echo json_encode(App\\Models\\AutomationRun::latest('id')->first()->totals['cash_contribution']);"), 240);
    steps.push('Complete mining trial through actual owned engine; one extraction, confirmed contribution and keyboard review');
    console.log('PASS complete mining flow');
    await investigate('Contracts');
    await stage('Select LOCAL-1 contract-1 via X1-TEST-ROCK');
    await post('Preview automation', '/preview');
    assert.equal(await button('Approve and start').isDisabled(), true);
    const acceptance = page.getByLabel('I explicitly approve accepting this contract and its continuing delivery obligation.');
    await acceptance.check();
    assert.equal(await acceptance.isChecked(), true);
    assert.equal(await acceptance.evaluate(el => getComputedStyle(el).appearance), 'auto');
    assert.equal(await button('Approve and start').isEnabled(), true);
    await screenshot('contract-preview');
    await post('Approve and start', '/start');
    await page.reload();
    await waitRun('completed');
    const result = cli("$r=App\\Models\\AutomationRun::where('strategy','contracts')->latest('id')->first(); echo json_encode($r->totals);");
    assert.equal(result.receipts, 1600); assert.equal(result.acquisition_cost, 300); assert.equal(result.cash_contribution, 1300);
    assert.equal((await world()).writes.filter(w => w.path.endsWith('/accept')).length, 1);
    assert.equal((await world()).writes.filter(w => w.path.endsWith('/fulfill')).length, 1);
    await screenshot('contract-outcome');
    steps.push('Complete offered contract with explicit acceptance, three purchase batches, delivery, fulfillment and separate confirmed accounting');
    console.log('PASS complete contract flow');
    // Validation and errors are checked through the real server, without an HTML form hiding invalid input.
    await page.getByLabel('Automation protected reserve', { exact: true }).fill('-1');
    await post('Save draft changes', '/update', 422);
    assert.equal(await page.getByLabel('Automation protected reserve', { exact: true }).getAttribute('aria-invalid'), 'true');
    await page.getByRole('alert').filter({ hasText: /reserve/ }).waitFor();
    steps.push('Server validation is readable, focuses the error and marks invalid fields');
    await shipView(page, 'commands');
    await post('Preview orbit', '/commands/preview');
    const old = cli("echo json_encode(App\\Models\\ShipAction::latest('id')->first()->id);");
    const writes = (await world()).writes.length;
    await shipView(page, 'automation');
    await shipView(page, 'automation');
    await post('End session', '/engine/end');
    await waitFor(() => cli("echo json_encode(App\\Models\\LocalEngine::find(1)?->state === 'off');"));
    await page.reload();
    assert.equal(cli(`echo json_encode(App\\Models\\ShipAction::find(${old})->state->value);`), 'rejected');
    await post('Start local engine', '/engine/start');
    await waitFor(() => cli("echo json_encode(App\\Models\\LocalEngine::find(1)?->state === 'running');"));
    await page.reload();
    assert.equal((await world()).writes.length, writes);
    steps.push('End session cancels an unsent manual preview; restart replays no approval');
    // A fresh mining run from the port can be stopped before any new reservation.
    await investigate('Mining');
    await stage('Select LOCAL-1 X1-TEST-ROCK via X1-TEST-PORT');
    await post('Preview automation', '/preview');
    await post('Approve and start', '/start');
    await button('Run details and Stop').click();
    await post('Stop automation', '/stop');
    await waitRun('stopped');
    assert.equal(cli("echo json_encode(is_int(App\\Models\\AutomationRun::latest('id')->first()->totals['elapsed_seconds']));"), true);
    assert.equal(cli("echo json_encode(App\\Models\\LocalEngine::find(1)->state);"), 'running');
    await screenshot('stopped-run');
    steps.push('Visible Stop prevents further run reservations while the engine remains available for manual commands');
    await shipView(page, 'automation');
    await shipView(page, 'automation');
    await post('End session', '/engine/end');
    await waitFor(() => cli("echo json_encode(App\\Models\\LocalEngine::find(1)?->state === 'off');"));
    assert.deepEqual(errors, []);
} catch (error) { failure = error; }
finally {
    let cleanupFailure;
    try {
        if (installed) {
            cli("app(App\\Gameplay\\LocalEngineManager::class)->end(); echo json_encode(true);");
            await waitFor(() => cli("$e=App\\Models\\LocalEngine::find(1); echo json_encode(!$e || !app(App\\Gameplay\\LocalEngineManager::class)->alive($e->processes['supervisor'] ?? null));"), 50);
            execFileSync(php, ['artisan', 'spacetraders:engine-service', 'uninstall'], { cwd: root, env, encoding: 'utf8' });
        }
    } catch (error) { cleanupFailure = error.message; failure ||= error; }
    const result = { passed: !failure, steps, errors, failure: failure?.message?.replaceAll(auth.password, '[redacted]'), cleanupFailure, serviceRemoved: !cleanupFailure, browser: browser.version(), fixture: directory };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(result, null, 2));
    await fs.writeFile(path.join(output, 'server.log'), serverLog.replaceAll(auth.password, '[redacted]'));
    if (failure) { const pages = browser.contexts().flatMap(c => c.pages()); if (pages[0]) await pages[0].screenshot({ path: path.join(output, 'failure.png'), fullPage: true }); }
    await browser.close(); server.kill(); await once(server, 'exit');
    console.log(JSON.stringify({ ...result, output }, null, 2));
}
if (failure) throw failure;
