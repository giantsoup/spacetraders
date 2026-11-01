import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import path from 'node:path';
import { guidedKey } from '../resources/js/guidedFlight.js';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const scenario = process.env.QA_GUIDED_SCENARIO || 'mining';
assert.ok(['mining', 'setup', 'uncertain', 'rejected', 'recovery'].includes(scenario));
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory);
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `guided_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array' };
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const identities = cli("echo json_encode(['user'=>App\\Models\\Agent::first()->user_id,'agent'=>App\\Models\\Agent::first()->only(['id','reset_id'])]);");
const worldFile = `${directory}/world.json`;
const world = async mutate => {
    const value = JSON.parse(await fs.readFile(worldFile, 'utf8'));
    if (mutate) { mutate(value); await fs.writeFile(worldFile, JSON.stringify(value)); }
    return value;
};
await world(value => {
    if (scenario === 'setup') value.ships[0].mounts = [];
    else {
        const observer = { ...structuredClone(value.ships[0]), symbol: 'LOCAL-2', mounts: [] };
        value.ships[0].nav.waypointSymbol = 'X1-TEST-PORT';
        value.ships[0].fuel.current = 80;
        value.ships.push(observer);
    }
});
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/guided', scenario));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
await once(server.stderr, 'data');
const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, colorScheme: 'light' });
await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === origin) return route.continue();
    if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
    return route.abort();
});
const page = await context.newPage();
page.setDefaultTimeout(15000);
const errors = [], responses = [], steps = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('response', response => { if (response.status() >= 400) responses.push(`${response.status()} ${new URL(response.url()).pathname}`); });
const coach = page.getByRole('complementary', { name: 'Guided first flight' });
const button = name => page.getByRole('button', { name, exact: true });
const key = guidedKey(identities.user, identities.agent);
const session = () => page.evaluate(key => JSON.parse(localStorage.getItem(key)), key);
const state = () => cli("echo json_encode(['actions'=>App\\Models\\ShipAction::orderBy('id')->get(),'ships'=>App\\Models\\Ship::all(),'agent'=>App\\Models\\Agent::first()]);");
const reload = async () => { await page.reload(); await coach.waitFor(); };
const drain = () => execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
const shot = name => page.screenshot({ path: path.join(output, name), animations: 'disabled' });
let failure;
try {
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    if (scenario === 'mining') {
        await page.goto(`${origin}/agents/create?guided=1`);
        await button('I already have a SpaceTraders agent').click();
        await page.locator('[data-guide="agent-token"].guided-target').waitFor();
        await page.getByLabel('Agent token', { exact: true }).fill('synthetic-offline-only');
        await button('Continue guided flight').click();
        await page.locator('[data-guide="save-agent"].guided-target').waitFor();
        await shot('01-guided-import.png');
        await world(value => { value.failure = 'read_401'; });
        await button('Save Existing Agent').click();
        await coach.getByRole('heading', { name: 'Paste your agent token' }).waitFor();
        assert.equal(await page.getByLabel('Agent token', { exact: true }).inputValue(), '');
        assert.equal(await button('Continue guided flight').isDisabled(), true);
        await world(value => { value.failure = null; });
        await page.getByLabel('Agent token', { exact: true }).fill('synthetic-offline-only');
        await button('Continue guided flight').click();
        await button('Save Existing Agent').click();
        await page.waitForURL(`**/agents/${auth.agent_id}?guided=1`);
    } else await page.goto(`${origin}/agents/${auth.agent_id}?guided=1`);
    await coach.getByRole('heading', { name: 'Your guided first trip' }).waitFor();
    await page.getByRole('complementary', { name: 'Game connection' }).getByText('Offline practice', { exact: true }).waitFor();
    assert.equal(await page.getByText('Live SpaceTraders API', { exact: true }).count(), 0, 'An offline fixture must not present itself as live gameplay');
    assert.equal(await button('Preview mining').isDisabled(), true, 'Unrelated commands must be disabled');
    await shot('02-guided-start.png');
    let paused = false, reloaded = false, injected = false, completed = false, recovered = false, previewRecovered = false;
    for (let count = 0; count < 140; count++) {
        const kind = await coach.getAttribute('data-step-kind');
        const title = await coach.getByRole('heading').textContent();
        const phase = await coach.getAttribute('data-step-phase');
        steps.push({ kind, title, phase });
        if (kind === 'complete') { completed = true; break; }
        if (kind === 'blocked') {
            if (['uncertain', 'rejected'].includes(scenario) && injected) {
                assert.equal((await session()).phase, 'extract');
                assert.equal(await button('Preview mining').isDisabled(), true);
                const writes = (await world()).writes.length;
                await shot('blocked-command.png');
                await reload();
                await coach.getByRole('heading', { name: 'Resolve this before continuing' }).waitFor();
                drain();
                assert.equal((await world()).writes.length, writes, 'A blocked command must not be replayed');
                break;
            }
            throw new Error(`Unexpected blocked step: ${await coach.textContent()}`);
        }
        if (kind === 'read' || kind === 'plan') {
            await coach.getByRole('button', { name: kind === 'plan' ? 'Use this route' : title === 'Review your confirmed results' ? 'Finish guided flight' : 'Continue guided flight', exact: true }).click();
        } else if (kind === 'field') {
            const expected = title.match(/^Set .+ to (.+)$/)?.[1];
            assert.ok(expected, title);
            const target = page.locator('.guided-target');
            await target.waitFor();
            assert.equal(await target.count(), 1);
            if (await target.evaluate(element => element.tagName) === 'SELECT') await target.selectOption(expected);
            else await target.fill(expected);
            if (!paused && scenario === 'mining') {
                await button('Pause guidance').click();
                assert.equal(await page.locator('.guided-target').count(), 0);
                await button('Resume guided flight').click();
                paused = true;
            }
        } else if (kind === 'select') await page.locator('.guided-target').click();
        else if (kind === 'preview') {
            if (!reloaded && scenario === 'mining' && phase === 'outbound') {
                await reload();
                reloaded = true;
                continue;
            }
            const [response] = await Promise.all([page.waitForResponse(response => response.url().endsWith('/commands/preview') && response.request().method() === 'POST'), page.locator('.guided-target').click()]);
            const preview = await response.json();
            assert.equal(response.status(), 202, preview.message);
            assert.equal(preview.ship_symbol, (await session()).plan.ship);
            await page.locator('[data-guide="approve"].guided-target').waitFor();
            if (scenario === 'mining' && phase === 'sell') await shot('03-highlighted-sale-approval.png');
        } else if (kind === 'approve') {
            if (scenario === 'recovery' && !previewRecovered && phase === 'outbound') {
                await page.route('**/commands/*/approve', route => route.abort('failed'), { times: 1 });
                await page.locator('.guided-target').click();
                await page.waitForFunction(key => !!JSON.parse(localStorage.getItem(key))?.pending, key);
                await reload();
                await button('Restore saved preview').click();
                await page.locator('[data-guide="approve"].guided-target').waitFor();
                await shot('restored-preview.png');
                const receiptId = (await session()).pending.id;
                assert.ok(Number.isSafeInteger(receiptId));
                cli(`App\\Models\\ShipAction::findOrFail(${receiptId})->update(['expires_at'=>now()->subMinute()]); echo json_encode(true);`);
                await reload();
                await button('Clear expired preview tracking').click();
                assert.equal((await session()).pending, null);
                previewRecovered = true;
                continue;
            }
            if (scenario === 'recovery' && !recovered && phase === 'extract') {
                let committed;
                const persisted = new Promise(resolve => { committed = resolve; });
                await page.route('**/commands/*/approve', async route => {
                    await route.fetch();
                    committed();
                    await route.abort('failed');
                }, { times: 1 });
                await page.locator('.guided-target').click();
                await persisted;
                assert.ok((await session()).pending, 'Known action ID must be saved before the approval response');
                await button('Pause guidance').click();
                await reload();
                await button('Resume guided flight').click();
                drain();
                await reload();
                recovered = true;
                continue;
            }
            const [response] = await Promise.all([page.waitForResponse(response => /\/commands\/\d+\/approve$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST'), page.locator('.guided-target').click()]);
            const action = await response.json();
            assert.equal(response.status(), 202, action.message);
            await page.waitForFunction(key => !!JSON.parse(localStorage.getItem(key))?.pending, key);
            assert.equal((await session()).phase, phase, 'Approval alone cannot advance the phase');
            if (!injected && ['uncertain', 'rejected'].includes(scenario) && action.command === 'mine') {
                await world(value => { value.failure = scenario === 'uncertain' ? 'timeout_after' : '401'; });
                injected = true;
            }
            drain();
            await reload();
        } else if (kind === 'sync') {
            const [response] = await Promise.all([page.waitForResponse(response => response.url().endsWith('/sync') && response.request().method() === 'POST'), page.locator('.guided-target').click()]);
            assert.equal(response.status(), 202, await response.text());
            await page.waitForFunction(() => !document.querySelector('[data-guide="sync"]').disabled || document.querySelector('.guided-coach')?.dataset.stepKind !== 'sync');
        } else if (kind === 'wait') {
            drain();
            await reload();
        } else throw new Error(`Unknown step ${kind}: ${title}`);
        await page.waitForFunction(previous => {
            const panel = document.querySelector('.guided-coach');
            return panel && (`${panel.dataset.stepKind}:${panel.querySelector('h2')?.textContent}` !== previous);
        }, `${kind}:${title}`);
    }
    if (['mining', 'setup', 'recovery'].includes(scenario)) {
        assert.ok(completed, 'Guided trip must finish within the bounded journey');
        const saved = await session();
        const snapshot = state();
        assert.ok(saved.extracted > 0 && saved.extracted === saved.sold);
        assert.equal(saved.pending, null);
        const ship = snapshot.ships.find(ship => ship.symbol === saved.plan.ship).snapshot;
        assert.equal(ship.cargo.units, 0);
        assert.equal(ship.fuel.current, ship.fuel.capacity);
        assert.equal(ship.nav.status, 'DOCKED');
        assert.ok(saved.receipts.every(id => snapshot.actions.some(action => action.id === id && action.state === 'confirmed')));
        assert.equal(snapshot.actions.filter(action => action.command === 'buy_ship' && action.state === 'confirmed').length, scenario === 'setup' ? 1 : 0);
        await shot('04-guided-complete.png');
        await reload();
        await coach.getByRole('heading', { name: 'Guided first trip complete' }).waitFor();
        if (scenario === 'recovery') {
            assert.ok(recovered && previewRecovered);
            assert.equal(snapshot.actions.filter(action => action.command === 'mine' && action.state === 'confirmed').length, 1, 'Lost approval response must not cause a duplicate extraction');
        }
    } else assert.ok(injected, 'Fault injection was exercised');
    if (['mining', 'setup'].includes(scenario)) {
        await page.setViewportSize({ width: 390, height: 844 });
        await button('Restart guidance').click();
        await button('Restart instructions').click();
        await button('Continue guided flight').click();
        await button('Show highlighted control').click();
        const visibleTarget = () => page.locator('.guided-target').evaluate(element => {
            const bounds = element.getBoundingClientRect();
            const hit = document.elementFromPoint(bounds.left + bounds.width / 2, bounds.top + bounds.height / 2);
            return bounds.top >= 0 && bounds.bottom <= innerHeight && (hit === element || element.contains(hit));
        });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await visibleTarget(), true, 'Instructions must not cover the highlighted button');
        const [sync] = await Promise.all([page.waitForResponse(response => response.url().endsWith('/sync') && response.request().method() === 'POST'), button('Synchronize').click()]);
        assert.equal(sync.status(), 202);
        await button('Use this route').click();
        for (let index = 0; index < 4 && await coach.getAttribute('data-step-kind') === 'read'; index++) await button('Continue guided flight').click();
        await button('Show highlighted control').click();
        assert.equal(await visibleTarget(), true, 'Sticky instructions must not cover a highlighted field below the coach');
        await shot('05-guided-mobile.png');
    }
    assert.deepEqual(errors.filter(error => scenario !== 'recovery' || !error.includes('net::ERR_FAILED')), []);
    assert.deepEqual(responses, []);
} catch (error) {
    failure = error;
    await shot('failure.png').catch(() => {});
} finally {
    const version = browser.version();
    await browser.close();
    server.kill('SIGTERM');
    await once(server, 'exit');
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify({ status: failure ? 'failed' : 'pass', scenario, steps, errors, responses, fixture: directory, browser: version, failure: failure ? String(failure.message).replaceAll(auth.password, '[redacted]') : null }, null, 2));
    await fs.writeFile(path.join(output, 'server.log'), serverLog);
}
if (failure) {
    failure.message = String(failure.message).replaceAll(auth.password, '[redacted]');
    if (failure.stack) failure.stack = String(failure.stack).replaceAll(auth.password, '[redacted]');
    throw failure;
}
console.log(`PASS guided ${scenario}: ${steps.length} explicit steps; ${output}`);
