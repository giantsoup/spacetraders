import { shipView } from './ship-browser-navigation.mjs';
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
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `exploration_${port}`, CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/exp01-evidence/browser'));
await fs.mkdir(output, { recursive: true });
const world = async change => {
    const value = JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8'));
    change(value);
    await fs.writeFile(parsed.SPACETRADERS_FIXTURE_PATH, JSON.stringify(value));
};
await world(value => {
    const point = { symbol: 'X1-TEST-HIDDEN', systemSymbol: 'X1-TEST', type: 'PLANET', x: 1, y: 0, orbitals: [], traits: [{ symbol: 'UNCHARTED' }] };
    value.waypoints.push(point);
    value.scan_waypoints = [{ ...point, traits: [{ symbol: 'UNCHARTED' }, { symbol: 'MARKETPLACE' }] }];
    value.chart_traits = [{ symbol: 'SHIPYARD' }];
    value.ships.push({ ...structuredClone(value.ships[0]), symbol: 'LOCAL-2' });
    value.ships[0].nav.waypointSymbol = point.symbol;
    value.ships[0].nav.status = 'IN_ORBIT';
    value.ships[0].mounts.push({ symbol: 'MOUNT_SENSOR_ARRAY_I', requirements: { crew: 1, power: 1 } });
    value.verify_worker_storage = true;
});
cli("app(App\\Gameplay\\AgentSynchronizer::class)->sync(App\\Models\\Agent::firstOrFail()); echo '{}';");
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stderr.on('data', chunk => { serverLog += chunk.toString(); });
await once(server.stderr, 'data');
if (process.argv.includes('--serve-only')) {
    console.log(JSON.stringify({ origin, fixture: directory }));
    process.on('SIGTERM', () => { server.kill('SIGTERM'); process.exit(0); });
    process.on('SIGINT', () => { server.kill('SIGTERM'); process.exit(0); });
    await new Promise(() => {});
}
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
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
const actions = () => cli("echo App\\Models\\ShipAction::orderBy('id')->get()->toJson();");
const worker = () => execFileSync(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
async function click(name, suffix, status = 202) {
    const waiting = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(suffix));
    await button(name).click();
    const response = await waiting;
    const result = await response.json();
    assert.equal(response.status(), status, result.message || JSON.stringify(result.errors));
    return result;
}
async function controls() {
    await shipView(page, 'commands');
    const details = page.locator('details').filter({ has: page.locator('summary', { hasText: /^Hidden-trait exploration$/ }) });
    if (!(await details.evaluate(element => element.open))) await details.locator('summary').click();
    await page.getByLabel('Protected credits', { exact: true }).fill('1000');
    await page.getByLabel('Unresolved waypoint', { exact: true }).selectOption('X1-TEST-HIDDEN');
    await details.locator('summary').scrollIntoViewIfNeeded();
}
async function resetKnowledge() {
    await world(value => {
        value.ships[0].cooldown = { remainingSeconds: 0, expiration: '2020-01-01T00:00:00Z' };
        value.waypoints[2].traits = [{ symbol: 'UNCHARTED' }];
        delete value.waypoints[2].chart;
        value.failure = null;
    });
    cli("App\\Models\\GameObservation::where('symbol','X1-TEST-HIDDEN')->delete(); app(App\\Gameplay\\AgentSynchronizer::class)->sync(App\\Models\\Agent::firstOrFail()); echo '{}';");
    await page.reload();
}
async function shot(name) {
    assert.equal(await page.locator('.app-shell').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: path.join(output, name + '.png'), animations: 'disabled' });
}
try {
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    await controls();
    await shot('controls-desktop');
    const stopped = await click('Preview waypoint scan', '/commands/preview');
    await page.getByRole('heading', { name: 'Review manual command' }).waitFor();
    await click('Stop exploration', `/commands/${stopped.id}/stop`);
    assert.equal(actions().at(-1).state, 'rejected');
    worker();
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH)).writes.length, 0);
    steps.push('Saved preview stopped through CSRF-protected HTTP; real worker sent nothing.');
    await controls();
    const pendingStop = await click('Preview waypoint scan', '/commands/preview');
    await click('Approve command', `/commands/${pendingStop.id}/approve`);
    await click(`Stop exploration #${pendingStop.id}`, `/commands/${pendingStop.id}/stop`);
    worker();
    assert.equal(actions().at(-1).state, 'rejected');
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH)).writes.length, 0);
    steps.push('Approved pending scan stopped before worker reservation; queued job sent nothing.');

    await controls();
    const scan = await click('Preview waypoint scan', '/commands/preview');
    await page.getByRole('heading', { name: 'Review manual command' }).waitFor();
    assert.equal(scan.before_state.estimate.exploration.travel_fuel, 0);
    assert.equal(scan.before_state.estimate.exploration.spend, 0);
    assert.match(scan.before_state.estimate.exploration.question, /marketplace/);
    await shot('approval-desktop');
    await page.setViewportSize({ width: 390, height: 844 });
    await shot('approval-mobile');
    await click('Approve command', `/commands/${scan.id}/approve`);
    cli(`App\\Jobs\\ExecuteShipCommand::dispatch(${scan.id}); echo '{}';`);
    const competing = [0, 1].map(() => spawn(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' }));
    const exits = await Promise.all(competing.map(child => once(child, 'exit')));
    assert.ok(exits.every(([code]) => code === 0));
    await page.reload();
    assert.equal(actions().at(-1).state, 'confirmed');
    assert.equal(actions().at(-1).outcome.exploration.answered, true);
    const resultWorld = JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH));
    assert.equal(resultWorld.writes.length, 1);
    assert.equal(cli("echo json_encode(Illuminate\\Support\\Facades\\DB::table('failed_jobs')->count());"), 0);
    assert.equal(resultWorld.worker_storage.synchronous, 2);
    assert.equal(resultWorld.worker_storage.fullfsync, 1);
    assert.notEqual(resultWorld.worker_storage.pid, process.pid);
    await shipView(page, 'activity');
    await page.locator('p').filter({ hasText: /^Question answered\. Reassess directories and routes under a new approval\.$/ }).last().waitFor();
    await shot('scan-result-mobile');
    await shipView(page, 'intelligence');
    await page.getByText('Listing, scan and chart evidence', { exact: true }).click();
    await shot('evidence-mobile');
    steps.push('Question, limits and continuation approved on mobile; separate worker stored scan traits and source evidence.');

    await resetKnowledge();
    await world(value => { value.scan_waypoints = []; });
    await controls();
    const partial = await click('Preview waypoint scan', '/commands/preview');
    await click('Approve command', `/commands/${partial.id}/approve`);
    worker();
    await page.reload();
    await shipView(page, 'activity');
    await page.locator('p').filter({ hasText: /^Target was not returned\. Traits remain unknown\. Stop and reassess; no automatic rescan\.$/ }).last().waitFor();
    await shot('partial-result-mobile');
    assert.equal(actions().at(-1).outcome.exploration.answered, false);
    steps.push('Empty scan coverage leaves target unresolved and authorizes no follow-up.');

    await resetKnowledge();
    await controls();
    const chart = await click('Preview chart', '/commands/preview');
    await click('Approve command', `/commands/${chart.id}/approve`);
    worker();
    await page.reload();
    assert.equal(actions().at(-1).state, 'confirmed');
    assert.equal(actions().at(-1).outcome.credit_delta, 100);
    await shipView(page, 'activity');
    await shot('chart-result-mobile');
    steps.push('Chart at current location stores separate chart evidence and the synthetic reward.');

    await resetKnowledge();
    await controls();
    const lost = await click('Preview chart', '/commands/preview');
    await click('Approve command', `/commands/${lost.id}/approve`);
    await world(value => { value.failure = 'timeout_after'; });
    worker();
    await page.reload();
    await shipView(page, 'activity');
    assert.equal(actions().at(-1).state, 'uncertain');
    await click(`Reconcile #${lost.id}`, `/commands/${lost.id}/reconcile`);
    assert.equal(actions().at(-1).state, 'uncertain');
    await shot('uncertain-result-mobile');
    steps.push('Lost chart response remains uncertain after read-only reconciliation, without replay.');
    const writesBefore = JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH)).writes.length;
    worker();
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH)).writes.length, writesBefore);
    await page.getByLabel('Operator reconciliation note', { exact: true }).fill('Reviewed synthetic chart. Attribution remains unresolved.');
    await click('Acknowledge reviewed outcome and end run', `/commands/${lost.id}/acknowledge`);
    await resetKnowledge();
    await controls();
    const reset = await click('Preview chart', '/commands/preview');
    await click('Approve command', `/commands/${reset.id}/approve`);
    await world(value => { value.reset = '2026-11-01-new-fixture'; });
    worker();
    await page.reload();
    assert.equal(actions().at(-1).state, 'rejected');
    await shot('reset-mobile');
    steps.push('World reset rejects pending chart before POST and archives the agent.');
    assert.deepEqual(errors, []);
    const result = { result: 'pass', steps, errors, fixture: directory, origin, evidence: output, browser: await browser.version(), timezone: 'America/Los_Angeles', colorScheme: 'light OS preference, dark app' };
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify(result, null, 2));
    console.log(JSON.stringify(result, null, 2));
} catch (error) {
    await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true });
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ message: error.message.replaceAll(auth.password, '[redacted]'), errors }, null, 2));
    throw error;
} finally {
    await fs.writeFile(path.join(output, 'server.log'), serverLog);
    await browser.close();
    server.kill('SIGTERM');
}
