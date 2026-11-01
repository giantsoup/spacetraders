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
assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = `http://127.0.0.1:${port}`;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `agents_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array' };
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
cli(`$owner=App\\Models\\Agent::first()->user_id;
    foreach (['reset'=>'RESET_PILOT','invalid_credential'=>'TOKEN_PILOT','unsynchronized'=>'NEW_PILOT'] as $state=>$name) {
        App\\Models\\Agent::create(['user_id'=>$owner,'name'=>$name,'token'=>'synthetic-offline-only','account_id'=>'synthetic-account','faction'=>'COSMIC','connection_state'=>$state,'reset_id'=>'2026-10-25']);
    }
    echo json_encode(true);`);
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/agents'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
const errors = [], steps = [];
try {
    await once(server.stderr, 'data');
    const context = await browser.newContext({ viewport: { width: 1440, height: 1100 }, colorScheme: 'light', locale: 'en-US', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
        if (url.hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => { if (response.status() >= 400) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    const card = name => page.getByRole('listitem').filter({ has: page.getByRole('heading', { name, exact: true }) });
    await page.goto(`${origin}/login`);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/agents');
    await page.getByRole('heading', { name: 'Agents', exact: true }).waitFor();
    assert.equal(await page.getByRole('listitem').count(), 4);
    await card('LOCAL').getByText('Synchronized', { exact: true }).waitFor();
    await card('LOCAL').getByText('100,000 CR', { exact: true }).waitFor();
    await card('RESET_PILOT').getByText('World reset', { exact: true }).first().waitFor();
    await card('TOKEN_PILOT').getByText('Token needs attention', { exact: true }).waitFor();
    await card('NEW_PILOT').getByText('Never', { exact: true }).waitFor();
    assert.equal(await card('NEW_PILOT').getByText('0 CR', { exact: true }).count(), 0);
    assert.equal(await page.locator('a button, button a').count(), 0, 'Interactive controls must not be nested');
    const time = await card('LOCAL').locator('time').evaluate(element => ({ utc: element.dateTime, text: element.textContent }));
    assert.match(time.utc, /Z$/);
    assert.match(time.text, /P[DS]T$/);
    assert.equal(await page.locator('.agents-page').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
    await page.keyboard.press('Tab');
    assert.equal(await page.locator('.skip').evaluate(element => element === document.activeElement), true);
    assert.equal(await page.locator('.skip').evaluate(element => getComputedStyle(element).outlineStyle), 'solid');
    await page.keyboard.press('Enter');
    assert.equal(await page.locator('main').evaluate(element => element === document.activeElement), true);
    await page.getByRole('heading', { name: 'Agents', exact: true }).click();
    assert.equal(await page.locator('.skip').evaluate(element => element.getBoundingClientRect().bottom <= 0), true, 'Skip link hides when focus moves into the page');
    steps.push('Owned login, all four connection states, unknown balance, UTC/local time, dark mode in a light browser, keyboard skip link');

    await page.goto(`${origin}/agents`);
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1100 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true, `No horizontal overflow at ${width}px`);
        const cards = await page.locator('.agent-card').evaluateAll(elements => elements.map(element => {
            const box = element.getBoundingClientRect();
            return { width: box.width, scroll: element.scrollWidth, client: element.clientWidth };
        }));
        assert.ok(cards.every(box => box.width > 250 && box.scroll <= box.client), `Cards remain visible at ${width}px`);
        await page.screenshot({ path: path.join(output, `agents-${width}.png`), fullPage: true, animations: 'disabled' });
    }
    steps.push('Desktop, tablet, 390px and 320px layouts without clipping');

    await page.setViewportSize({ width: 1440, height: 1100 });
    await page.getByRole('link', { name: 'Open flight deck for LOCAL', exact: true }).click();
    await page.waitForURL(`**/agents/${auth.agent_id}`);
    await page.getByRole('heading', { name: 'LOCAL', exact: true }).waitFor();
    await page.goto(`${origin}/agents`);
    await page.getByRole('link', { name: 'Import current token for RESET_PILOT', exact: true }).click();
    await page.getByLabel('Agent token', { exact: true }).waitFor();
    assert.equal(await page.getByLabel('Account token', { exact: true }).count(), 0);
    await page.getByRole('button', { name: 'Back to Create New Agent', exact: true }).click();
    await page.getByLabel('Account token', { exact: true }).waitFor();
    await page.getByRole('button', { name: 'Already have an Agent?', exact: true }).click();
    await page.getByLabel('Agent token', { exact: true }).fill('synthetic-offline-only');
    await page.getByRole('button', { name: 'Save Existing Agent', exact: true }).click();
    await page.waitForURL('**/agents');
    assert.equal(await page.getByRole('listitem').count(), 4, 'Import updates the same agent without duplicates');
    steps.push('Flight deck navigation, direct recovery/import link, form switching, successful offline import');

    await page.getByRole('link', { name: 'Register new agent', exact: true }).click();
    await page.getByLabel('Account token', { exact: true }).waitFor();
    await page.goto(`${origin}/agents`);
    await page.getByRole('link', { name: 'Start the new player guide', exact: true }).click();
    await page.waitForURL('**/guide');
    await page.getByRole('heading', { name: 'Your first flight. Your first sale.', exact: true }).waitFor();
    await page.goto(`${origin}/agents`);
    await page.getByRole('link', { name: 'Profile', exact: true }).click();
    await page.waitForURL('**/profile');
    steps.push('Registration, player guide and profile navigation');

    cli('App\\Models\\Agent::query()->delete(); echo json_encode(true);');
    await page.goto(`${origin}/agents`);
    await page.getByRole('heading', { name: 'No agents connected yet', exact: true }).waitFor();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: path.join(output, 'agents-empty.png'), fullPage: true, animations: 'disabled' });
    await page.getByRole('link', { name: 'Begin guided agent setup', exact: true }).click();
    await page.waitForURL('**/agents/create?guided=1');
    await page.getByRole('button', { name: 'I already have a SpaceTraders agent', exact: true }).waitFor();
    await page.goto(`${origin}/agents`);
    await page.getByRole('button', { name: 'Log out', exact: true }).click();
    await page.waitForURL(origin + '/');
    await page.goto(`${origin}/agents`);
    await page.waitForURL('**/login');
    steps.push('Empty state, guided setup and POST logout with authentication required afterward');
    assert.deepEqual(errors, []);
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify({ passed: true, steps, errors }, null, 2));
    console.log(JSON.stringify({ passed: true, steps, output }, null, 2));
} catch (error) {
    await fs.writeFile(path.join(output, 'failure.json'), JSON.stringify({ error: error.message, steps, errors, serverLog }, null, 2));
    throw error;
} finally {
    await browser.close();
    server.kill();
    await once(server, 'exit');
}
