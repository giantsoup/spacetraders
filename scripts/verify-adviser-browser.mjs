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
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `adviser_${port}`, CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/adv01-evidence/browser'));
await fs.mkdir(output, { recursive: true });
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
const state = () => cli("echo json_encode(['runs'=>App\\Models\\AutomationRun::latest('id')->get(),'actions'=>App\\Models\\ShipAction::orderBy('id')->get(),'ships'=>App\\Models\\Ship::all(),'policies'=>App\\Models\\AdviserPolicy::orderBy('id')->get(),'decisions'=>App\\Models\\AdviserDecision::orderBy('id')->get(),'jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->count()]);");
async function click(name, suffix) {
    const waiting = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname.endsWith(suffix));
    if (name === 'Synchronize') await shipView(page, 'guide');
    await button(name).click();
    const response = await waiting;
    const result = await response.json();
    assert.equal(response.status(), 202, result.message || JSON.stringify(result.errors));
    return result;
}
const waitMessage = text => page.getByRole('status').filter({ hasText: text }).waitFor();
async function openAdviser() { await shipView(page, 'adviser'); }
async function savePolicy() {
    await page.getByLabel('I reviewed this one-ship scope and expiry. Execution remains disabled.', { exact: true }).check();
    return click('Save policy grant', '/adviser/policies');
}
async function screenshot(width, label) {
    await page.setViewportSize({ width, height: width === 320 ? 667 : 950 });
    await openAdviser();
    assert.equal(await page.locator('.app-shell').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    assert.equal(await page.locator('#adviser').evaluate(element => element.scrollWidth <= element.clientWidth + 1), true);
    await page.locator('#adviser-heading').scrollIntoViewIfNeeded();
    await page.screenshot({ path: path.join(output, `${label}-${width}.png`), animations: 'disabled' });
}
try {
    await serverReady;
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    await openAdviser();
    await page.getByLabel('Maximum activity spending in CR', { exact: true }).fill('100');
    await page.getByLabel('Maximum activity cash loss in CR', { exact: true }).fill('100');
    await page.getByLabel('Maximum activity travel fuel', { exact: true }).fill('100');
    await page.getByLabel('Maximum refuel tank units', { exact: true }).fill('100');
    await page.getByLabel('Permit one-extraction mining trials with unknown earnings', { exact: true }).check();
    assert.equal(await page.getByLabel('Policy ship').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(17, 27, 44)');
    await screenshot(390, 'grant');
    const firstGrant = await savePolicy();
    assert.equal(firstGrant.version, 1);
    assert.equal(firstGrant.terms.exploration.enabled, false);
    const csrf = await page.request.post(`${origin}/agents/${auth.agent_id}/adviser/assess`, { headers: { 'Sec-Fetch-Site': 'cross-site', Origin: 'https://outside.invalid', Accept: 'application/json' }, data: { policy_id: firstGrant.id } });
    assert.equal(csrf.status(), 419);
    steps.push('Reviewed one-ship grant persisted with explicit bounds; cross-site request without CSRF rejected with 419.');
    await click('Collect bounded observations', '/adviser/collect');
    await waitMessage('Bounded observation collection finished.');
    const firstDecision = await click('Assess saved evidence', '/adviser/assess');
    assert.equal(firstDecision.selection.rule, 'explicit_unknown_income_trial');
    assert.ok(firstDecision.candidates.some(c => c.activity === 'mining' && c.classification === 'feasible' && c.base_net === null && c.conservative_net === null));
    assert.ok(firstDecision.candidates.some(c => c.classification === 'unsupported'));
    assert.ok(firstDecision.candidates.some(c => c.activity === 'scout' && c.classification === 'blocked' && c.question));
    assert.equal(state().jobs, 0);
    assert.equal(state().actions.length, 0);
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8')).writes.length, 0);
    assert.equal(await page.locator('#adviser').innerText().then(text => text.includes('America/Los_Angeles')), true);
    assert.ok(await page.locator('#adviser time').count() >= 4);
    steps.push('Real bounded observation handlers and immutable assessment show unknown mining returns and named blocked exploration; no jobs, writes or commands.');
    for (const width of [1440, 390, 320]) {
        await screenshot(width, 'decisions');
        await page.locator(`[data-candidate="${firstDecision.selection.candidate_id}"]`).first().screenshot({ path: path.join(output, `selected-candidate-${width}.png`), animations: 'disabled' });
    }
    await page.reload();
    await openAdviser();
    await page.getByRole('heading', { name: /^Assessment #/ }).waitFor();
    const unchanged = state().decisions[0];
    assert.equal(unchanged.evidence_hash, firstDecision.evidence_hash);
    assert.deepEqual(unchanged.candidates, firstDecision.candidates);

    await page.getByText('Review and grant a new policy version', { exact: true }).click();
    await page.getByLabel('Scout', { exact: true }).check();
    await page.getByLabel('Permit exploration when due and funded above the protected balance', { exact: true }).check();
    for (const [label, value] of [['Exploration maximum cost in CR', '100'], ['Exploration maximum travel fuel', '100'], ['Exploration maximum seconds', '1000'], ['Minimum seconds between exploration activities', '600']]) await page.getByLabel(label, { exact: true }).fill(value);
    const replacement = await savePolicy();
    assert.equal(replacement.version, 2);
    const explored = await click('Assess saved evidence', '/adviser/assess');
    assert.equal(explored.selection.rule, 'due_exploration');
    steps.push('Explicit replacement revoked the old grant and selected a named, funded, bounded exploration question.');

    const run = cli(`$a=App\\Models\\Agent::find(${auth.agent_id}); $r=app(App\\Gameplay\\AutomationRunner::class)->preview($a,'LOCAL-1',['site'=>'X1-TEST-ROCK','market'=>'X1-TEST-ROCK','reserve'=>10000,'max_spend'=>100,'max_loss'=>100,'minutes'=>30,'cycles'=>1]); app(App\\Gameplay\\AutomationRunner::class)->start($a,$r); echo $r->fresh()->toJson();`);
    await page.reload();
    await openAdviser();
    assert.equal(await button('Stop current activity').isEnabled(), true);
    await click('Pause automatic decisions', `/adviser/policies/${replacement.id}/pause`);
    assert.equal(state().runs[0].stop_requested_at, null);
    assert.equal(await button('Assess saved evidence').isEnabled(), false);
    await click('Stop current activity', `/runs/${run.id}/stop`);
    assert.ok(state().runs[0].stop_requested_at);
    execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
    assert.equal(state().runs[0].state, 'stopped');
    assert.equal(state().runs[0].active_agent_id, null);
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8')).writes.length, 0);
    steps.push('Pause left the existing approved run running; Stop persisted separately and a real finite database worker released it without gameplay writes.');
    await page.reload();
    await openAdviser();
    await screenshot(390, 'paused');
    const manual = cli(`$a=App\\Models\\Agent::find(${auth.agent_id}); $e=app(App\\Gameplay\\ShipCommandExecutor::class); $c=$e->preview($a,'LOCAL-1','orbit',[],(string)Illuminate\\Support\\Str::uuid()); $e->approve($a,$c); echo $c->fresh()->toJson();`);
    execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
    assert.equal(state().actions.find(a => a.id === manual.id).state, 'confirmed');
    assert.equal(JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8')).writes.length, 1);
    assert.equal(state().decisions.length, 2);
    steps.push('The shared executor still confirmed one separately approved synthetic manual orbit through a real worker after policy Pause.');
    assert.deepEqual(errors, []);
    const result = { result: 'pass', steps, errors, fixture: directory, evidence: output, php: cli('echo json_encode(PHP_VERSION);') };
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
