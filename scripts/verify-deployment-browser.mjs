import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { shipView } from './ship-browser-navigation.mjs';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || path.join(root, 'scripts/herd-php');
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixture = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = fixture.match(/Owned fixture directory: (.+)/)[1];
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
console.log(JSON.stringify({ origin, ownedDirectory: directory }));
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `ops03_${port}`, CACHE_DRIVER: 'file', CACHE_STORE: 'file', CACHE_PATH: `${directory}/cache`, SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, SPACETRADERS_DEPLOYMENT_PATH: `${directory}/deployment`, SPACETRADERS_RELEASE: 'browser-fixture', LOG_CHANNEL: 'stderr' };
for (const child of ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app']) await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const control = (...args) => {
    try { return JSON.parse(execFileSync(php, ['artisan', 'spacetraders:deployment', ...args], { cwd: root, env, encoding: 'utf8' })); }
    catch (error) { if (error.status === 1) return JSON.parse(error.stdout); throw error; }
};
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/ops03/browser'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
const checks = [];
try {
    await once(server.stderr, 'data');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const button = name => page.getByRole('button', { name, exact: true });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    const deck = `${origin}/agents/${auth.agent_id}`;
    await page.goto(deck);
    await shipView(page, 'commands');
    const previewResponse = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/commands/preview'));
    await button('Preview orbit').click();
    const preview = await previewResponse;
    assert.equal(preview.status(), 202);
    const action = await preview.json();
    const csrf = decodeURIComponent((await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN').value);
    const before = cli(`echo json_encode(['jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->get(),'action'=>App\\Models\\ShipAction::find(${action.id})->only(['state','expires_at'])]);`);
    control('close', '--generation=browser-maintenance');
    const rejected = await context.request.post(`${origin}/agents/${auth.agent_id}/commands/${action.id}/approve`, { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, data: {} });
    assert.equal(rejected.status(), 503);
    assert.equal(rejected.headers()['retry-after'], '5');
    assert.match((await rejected.json()).message, /Deployment in progress/);
    assert.deepEqual(cli(`echo json_encode(['jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->get(),'action'=>App\\Models\\ShipAction::find(${action.id})->only(['state','expires_at'])]);`), before);
    checks.push('Authenticated browser write receives 503 before approval; preview and queue remain unchanged.');
    assert.equal((await page.reload()).status(), 503);
    await page.getByRole('heading', { name: 'Deployment in progress' }).waitFor();
    assert.equal(await page.locator('html').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    const painted = () => page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await painted();
    await page.screenshot({ path: path.join(output, 'maintenance-390.png') });
    await page.setViewportSize({ width: 1440, height: 900 });
    await painted();
    await page.screenshot({ path: path.join(output, 'maintenance-1440.png') });
    checks.push('Phone and desktop maintenance screens remain dark under a light OS preference and fit the viewport.');
    if (process.env.QA_PREVIEW_HOLD === '1') {
        await fs.writeFile(`${directory}/preview.json`, JSON.stringify({ origin, ownedDirectory: directory }));
        const deadline = Date.now() + 90000;
        while (Date.now() < deadline) {
            try { await fs.access(`${directory}/preview.done`); break; } catch { await new Promise(resolve => setTimeout(resolve, 100)); }
        }
    }
    control('open', '--generation=browser-maintenance');
    await page.goto(deck);
    const resumedCsrf = decodeURIComponent((await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN').value);
    const approved = await context.request.post(`${origin}/agents/${auth.agent_id}/commands/${action.id}/approve`, { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': resumedCsrf }, data: {} });
    assert.equal(approved.status(), 202, await approved.text());
    const pending = cli(`echo json_encode(['jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->get(),'state'=>App\\Models\\ShipAction::find(${action.id})->state->value]);`);
    assert.equal(pending.state, 'pending');
    control('close', '--generation=browser-pending');
    execFileSync(php, ['artisan', 'queue:work', 'database', '--once', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env });
    assert.deepEqual(cli(`echo json_encode(['jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->get(),'state'=>App\\Models\\ShipAction::find(${action.id})->state->value]);`), pending);
    control('open', '--generation=browser-pending');
    execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env });
    await page.goto(deck);
    await shipView(page, 'activity');
    await page.locator('#activity').getByText(/Confirmed · Manual/).waitFor();
    await page.screenshot({ path: path.join(output, 'resumed-confirmed.png'), fullPage: true });
    assert.equal(cli(`echo json_encode(App\\Models\\ShipAction::find(${action.id})->state->value);`), 'confirmed');
    assert.equal(JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8')).writes.length, 1);
    assert.deepEqual(errors, []);
    checks.push('Reopen preserves the browser session and approval; paused real worker retains pending bytes, resumed worker confirms one fake mutation.');
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify({ checks, ownedDirectory: directory, origin, errors }, null, 2));
    console.log(JSON.stringify({ checks, output }, null, 2));
} finally {
    const status = control('status');
    if (status.phase !== 'open' && ['browser-maintenance', 'browser-pending'].includes(status.generation)) control('open', `--generation=${status.generation}`);
    await fs.writeFile(path.join(output, 'server.log'), serverLog.replaceAll(auth.password, '[redacted]'));
    await browser.close();
    server.kill('SIGTERM');
    await once(server, 'exit');
}
