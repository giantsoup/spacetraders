import { shipView } from './ship-browser-navigation.mjs';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import path from 'node:path';
import { lessons, tutorialKey } from '../resources/js/tutorial.js';

// Creates its own offline database, fake upstream, browser context, and finite worker sessions.
const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fixtureOutput = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = fixtureOutput.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory, 'A newly owned offline fixture is required.');
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `tutorial_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array' };
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const identities = cli("$a=App\\Models\\Agent::first(); $b=$a->replicate(); $b->name='SECOND'; $b->canonical_identity=null; $b->save(); echo json_encode(['user'=>$a->user_id,'agent'=>$a->only(['id','reset_id']),'second'=>$b->id]);");
const world = JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8'));
world.ships.push({ ...structuredClone(world.ships[0]), symbol: 'LOCAL-2' });
await fs.writeFile(`${directory}/world.json`, JSON.stringify(world));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/tutorial'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', data => { serverLog += data; });
server.stderr.on('data', data => { serverLog += data; });
await once(server.stderr, 'data');
const context = await browser.newContext({ viewport: { width: 1440, height: 1050 }, colorScheme: 'light' });
const localOnly = route => {
    const url = new URL(route.request().url());
    if (url.origin === origin) return route.continue();
    if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
    return route.abort();
};
await context.route('**/*', localOnly);
const page = await context.newPage();
page.setDefaultTimeout(12000);
const errors = [], badResponses = [], results = [];
page.on('pageerror', error => errors.push(error.message));
page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
page.on('response', response => { if (response.status() >= 400) badResponses.push(`${response.status()} ${new URL(response.url()).pathname}`); });
const agentGuide = `${origin}/agents/${auth.agent_id}/guide`;
const deck = `${origin}/agents/${auth.agent_id}`;
const button = name => page.getByRole('button', { name, exact: true });
const course = () => page.getByRole('region', { name: 'Early-game tutorial', exact: true });
const record = async (name, work) => { await work(); results.push(name); console.log(`PASS ${name}`); };
const choose = async id => {
    await page.getByRole('navigation', { name: 'Tutorial lessons', exact: true }).getByRole('button', { name: lessons.find(lesson => lesson.id === id).title, exact: false }).click();
    await page.waitForFunction(title => document.activeElement?.textContent === title, lessons.find(lesson => lesson.id === id).title);
};
const shot = async name => {
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.screenshot({ path: path.join(output, name), fullPage: true, animations: 'disabled' });
};
const state = () => cli("echo json_encode(['actions'=>App\\Models\\ShipAction::orderBy('id')->get(),'agent'=>App\\Models\\Agent::first(),'ship'=>App\\Models\\Ship::first()->snapshot]);");
async function post(label, suffix) {
    const [received] = await Promise.all([
        page.waitForResponse(response => new URL(response.url()).pathname.endsWith(suffix) && response.request().method() === 'POST'),
        button(label).click(),
    ]);
    const data = await received.json();
    assert.equal(received.status(), 202, `${label}: ${data.message || JSON.stringify(data.errors)}`);
    return data;
}
async function refreshDeck() { await page.goto(deck); await page.getByRole('heading', { name: 'LOCAL-1', exact: true }).waitFor(); await shipView(page, 'commands'); }
async function sync() { await shipView(page, 'guide'); await post('Synchronize', '/sync'); await refreshDeck(); }
async function command(label) {
    await shipView(page, 'commands');
    const action = await post(label, '/commands/preview');
    await post('Approve command', `/commands/${action.id}/approve`);
    execFileSync(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
    await refreshDeck();
    const saved = state().actions.find(entry => entry.id === action.id);
    assert.equal(saved.state, 'confirmed', saved.message);
}
let failure;
try {
    await record('login, normal guide entry, keyboard focus and preparation persistence', async () => {
        await page.goto(`${origin}/login`);
        await page.getByLabel('Email', { exact: true }).fill(auth.email);
        await page.getByLabel('Password', { exact: true }).fill(auth.password);
        await button('Log in').click();
        await page.waitForURL('**/agents');
        await page.getByRole('link', { name: 'Start the new player guide', exact: false }).click();
        await course().getByRole('checkbox').check();
        await button('Next lesson').click();
        await page.waitForFunction(() => document.activeElement?.textContent === 'Connect your first agent');
        await page.reload();
        await course().getByText('1 of 10 lessons checked off', { exact: true }).waitFor();
        await page.getByRole('heading', { name: 'Connect your first agent', exact: true }).waitFor();
        assert.equal(state().actions.length, 0, 'Guide reading and checklists must not create commands');
    });
    await record('agent-scoped progress, lesson links, flight-deck checklist and real manual mining loop', async () => {
        await page.getByRole('navigation', { name: 'Tutorial agent' }).getByRole('link', { name: 'LOCAL', exact: true }).click();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        await choose('agent');
        await course().getByText('LOCAL is already saved.', { exact: false }).waitFor();
        assert.equal(await course().getByRole('link', { name: 'Open flight deck', exact: false }).getAttribute('href'), deck);
        await choose('observe');
        await page.getByRole('link', { name: 'Open fleet', exact: false }).click();
        await page.waitForURL('**#fleet');
        await shipView(page, 'guide');
        await button('Show lesson').click();
        await page.getByRole('heading', { name: 'Synchronize and read your ship', exact: true }).waitFor();
        await course().getByRole('checkbox').check();
        await button('Next lesson').click();
        await shipView(page, 'guide');
        await page.getByRole('link', { name: 'Full player guide' }).click();
        await page.getByRole('heading', { name: 'Choose a working miner', exact: true }).waitFor();
        await choose('travel');
        await page.getByRole('link', { name: 'Open manual commands', exact: false }).click();
        await sync();
        await shipView(page, 'fleet');
        await page.getByRole('button', { name: 'LOCAL-2', exact: false }).click();
        await shipView(page, 'guide');
        await button('Show lesson').click();
        await page.getByRole('link', { name: 'Open manual commands', exact: false }).click();
        assert.ok((await page.locator('.ship-choice.selected').textContent()).includes('LOCAL-2'));
        await shipView(page, 'guide');
        await page.getByRole('link', { name: 'Full player guide' }).click();
        await page.getByRole('link', { name: 'Open manual commands', exact: false }).click();
        assert.ok((await page.locator('.ship-choice.selected').textContent()).includes('LOCAL-2'));
        await shipView(page, 'fleet');
        await page.getByRole('button', { name: 'LOCAL-1', exact: false }).click();
        await command('Preview orbit');
        await shipView(page, 'commands');
        await page.getByLabel('Destination', { exact: true }).selectOption('X1-TEST-PORT');
        await command('Preview navigation');
        await sync();
        assert.equal(state().ship.nav.waypointSymbol, 'X1-TEST-PORT');
        await shipView(page, 'commands');
        await page.getByLabel('Destination', { exact: true }).selectOption('X1-TEST-ROCK');
        await command('Preview navigation');
        await sync();
        await command('Preview mining');
        assert.ok(state().ship.cargo.units > 0);
        await command('Preview docking');
        await sync();
        const cargo = state().ship.cargo.inventory[0];
        const saleTotal = 4 * world.goods.find(good => good.symbol === cargo.symbol).sellPrice;
        await page.getByLabel('Trade good', { exact: true }).selectOption(cargo.symbol);
        await page.getByLabel('Quantity / refuel tank units', { exact: true }).fill('4');
        await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill(String(saleTotal));
        await page.getByLabel('Protected credits', { exact: true }).fill('2000');
        const credits = state().agent.credits;
        await command('Preview cargo sale');
        assert.equal(state().agent.credits, credits + saleTotal);
        while (state().ship.cargo.units > 0) {
            const remaining = state().ship.cargo.inventory[0];
            const quote = world.goods.find(good => good.symbol === remaining.symbol);
            const units = Math.min(remaining.units, quote.tradeVolume);
            await page.getByLabel('Trade good', { exact: true }).selectOption(remaining.symbol);
            await page.getByLabel('Quantity / refuel tank units', { exact: true }).fill(String(units));
            await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill(String(units * quote.sellPrice));
            await command('Preview cargo sale');
        }
        assert.equal(state().ship.cargo.units, 0);
        const tankSpace = state().ship.fuel.capacity - state().ship.fuel.current;
        assert.ok(tankSpace > 0);
        await page.getByLabel('Quantity / refuel tank units', { exact: true }).fill(String(tankSpace));
        await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill('100');
        await command('Preview refueling');
        assert.equal(state().ship.fuel.current, state().ship.fuel.capacity);
        await shipView(page, 'guide');
        await button('Show lesson').click();
        await shot('flight-deck-lesson.png');
    });
    await record('complete, undo, resume, reset cancellation and confirmed checklist reset', async () => {
        await page.goto(agentGuide);
        for (const lesson of lessons) { await choose(lesson.id); await course().getByRole('checkbox').check(); }
        await course().getByRole('heading', { name: 'Early-game checklist complete', exact: true }).waitFor();
        await page.reload();
        await course().getByText('10 of 10 lessons checked off', { exact: true }).waitFor();
        await shot('guide-complete-desktop.png');
        await course().getByRole('checkbox').uncheck();
        await course().getByText('9 of 10 lessons checked off', { exact: true }).waitFor();
        await button('Reset checklist').click();
        await button('Keep progress').click();
        await course().getByText('9 of 10 lessons checked off', { exact: true }).waitFor();
        await button('Reset checklist').click();
        await button('Clear checklist').click();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        await course().getByRole('heading', { name: 'Meet your flight deck', exact: true }).waitFor();
        await page.waitForFunction(() => document.activeElement?.textContent === 'Meet your flight deck');
    });
    await record('agent/reset boundaries, malformed and unavailable storage, cross-tab updates', async () => {
        await choose('sell');
        await course().getByRole('checkbox').check();
        await page.getByRole('navigation', { name: 'Tutorial agent' }).getByRole('link', { name: 'SECOND', exact: true }).click();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        await page.goto(agentGuide);
        await course().getByText('1 of 10 lessons checked off', { exact: true }).waitFor();
        const key = tutorialKey(identities.user, identities.agent);
        const other = await context.newPage();
        await other.goto(agentGuide);
        await other.getByRole('region', { name: 'Early-game tutorial' }).getByRole('checkbox').uncheck();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        await other.close();
        await page.evaluate(key => localStorage.setItem(key, '{invalid-json'), key);
        await page.reload();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        await course().getByText('Browser storage is unavailable or unreadable.', { exact: false }).waitFor();
        await course().getByRole('checkbox').check();
        const stored = await page.evaluate(key => JSON.parse(localStorage.getItem(key)), key);
        assert.deepEqual(stored.completed, ['orientation']);
        cli("App\\Models\\Agent::first()->update(['reset_id'=>'2026-11-01']); echo json_encode(true);");
        const nextWorld = JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8'));
        nextWorld.reset = '2026-11-01';
        await fs.writeFile(`${directory}/world.json`, JSON.stringify(nextWorld));
        await page.reload();
        await course().getByText('0 of 10 lessons checked off', { exact: true }).waitFor();
        const blockedContext = await browser.newContext();
        await blockedContext.route('**/*', localOnly);
        await blockedContext.addInitScript(() => { Object.defineProperty(Storage.prototype, 'setItem', { value() { throw new DOMException('Disabled', 'SecurityError'); } }); });
        await blockedContext.addCookies(await context.cookies());
        const blocked = await blockedContext.newPage();
        await blocked.goto(agentGuide);
        await blocked.getByRole('region', { name: 'Early-game tutorial' }).getByRole('checkbox').check();
        await blocked.getByText('Browser storage is unavailable or unreadable.', { exact: false }).waitFor();
        assert.equal(await blocked.getByRole('checkbox').isChecked(), true);
        await blockedContext.close();
    });
    await record('dark appearance, mobile reflow, reference keyboard interaction and narrow flight deck', async () => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto(`${origin}/agents`);
        await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Player guide', exact: true }).click();
        await page.waitForURL('**/guide');
        await page.getByRole('navigation', { name: 'Tutorial agent' }).getByRole('link', { name: 'LOCAL', exact: true }).click();
        await choose('sell');
        assert.equal(await page.locator('.guide-page').evaluate(element => getComputedStyle(element).backgroundColor), 'rgb(7, 13, 23)');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        const glossary = page.getByText('Flight-deck glossary', { exact: true });
        await glossary.focus();
        await page.keyboard.press('Enter');
        assert.equal(await glossary.evaluate(element => element.parentElement.open), true);
        await shot('guide-mobile.png');
        await page.screenshot({ path: path.join(output, 'guide-mobile-viewport.png'), animations: 'disabled' });
        await page.setViewportSize({ width: 320, height: 800 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        await refreshDeck();
        await shipView(page, 'guide');
        await button('Show lesson').click();
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
        await shot('flight-deck-mobile.png');
    });
    assert.deepEqual(errors, [], 'Browser runtime/console errors');
    assert.deepEqual(badResponses, [], 'Unexpected failing HTTP responses');
} catch (error) {
    failure = error;
    await shot('failure.png').catch(() => {});
} finally {
    const browserVersion = browser.version();
    await browser.close();
    server.kill('SIGTERM');
    await once(server, 'exit');
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify({ status: failure ? 'failed' : 'pass', results, errors, badResponses, fixture: directory, browser: `Chromium ${browserVersion}`, provider: 'Owned offline FakeSpaceTraders', failure: failure ? String(failure.message).replaceAll(auth.password, '[redacted]') : null }, null, 2));
    await fs.writeFile(path.join(output, 'server.log'), serverLog);
}
if (failure) {
    failure.message = String(failure.message).replaceAll(auth.password, '[redacted]');
    if (failure.stack) failure.stack = String(failure.stack).replaceAll(auth.password, '[redacted]');
    throw failure;
}
console.log(`Tutorial evidence: ${output}`);
