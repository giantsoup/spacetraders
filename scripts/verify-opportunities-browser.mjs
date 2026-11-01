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
    if (name === 'Synchronize') await shipView(page, 'guide');
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
    await shipView(page, 'automation');
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
    await shipView(page, 'intelligence');
    await page.getByRole('heading', { name: 'Local intelligence and opportunities' }).waitFor();
    assert.match(await page.locator('#opportunities').innerText(), /Prices unknown/);
    assert.match(await page.locator('#opportunities').innerText(), /Complete known geography; hidden traits remain unresolved/);
    steps.push('Coverage and independently dated market evidence rendered.');
    await limits();
    await page.getByLabel('Observation question', { exact: true }).selectOption('X1-TEST-PORT');
    const scout = await click('Preview observation trip', '/runs/preview');
    await page.getByRole('heading', { name: 'Review scout operation' }).waitFor();
    assert.equal(await page.getByRole('dialog', {name:'Review approval',exact:true}).evaluate(element => element.contains(document.activeElement)), true);
    await click('Start approved scout run', `/runs/${scout.id}/start`);
    await drain();
    assert.equal(state().runs[0].totals.visits, 1);
    assert.equal(state().runs[0].totals.accounting.state, 'unresolved');
    assert.equal(state().runs[0].totals.accounting.net_credits_per_hour, null);
    assert.ok(state().runs[0].totals.accounting.unresolved.includes('Wear and repair exposure is unmeasured.'));
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
    await shipView(page, 'automation');
    await page.getByLabel('Operation', { exact: true }).selectOption('trade');
    await limits();
    await page.getByLabel('Buyer', { exact: true }).selectOption('X1-TEST-PORT');
    await page.getByLabel('Trade symbol', { exact: true }).fill('IRON_ORE');
    await page.getByLabel('Total inventory units', { exact: true }).fill('10');
    await page.getByLabel('Maximum inventory exposure in CR', { exact: true }).fill('500');
    await page.getByLabel('Minimum sale price per owned unit', { exact: true }).fill('20');
    await page.getByRole('region', { name: 'Bounded scouting and trading' }).getByLabel('Run wear allowance including repairs in CR', { exact: true }).fill('25');
    const trade = await click('Preview trade cycle', '/runs/preview');
    await page.getByRole('heading', { name: 'Review trade operation' }).waitFor();
    await click('Start approved trade run', `/runs/${trade.id}/start`);
    await drain();
    assert.equal(state().runs[0].totals.purchase_cost, 300);
    assert.equal(state().runs[0].totals.cash_contribution, 295);
    assert.equal(state().runs[0].plan.wear_allowance, 25);
    assert.equal(state().runs[0].totals.accounting.state, 'estimated_comparable');
    assert.equal(state().runs[0].totals.accounting.net_after_wear, 270);
    assert.equal(state().runs[0].totals.accounting.realized_net_per_hour, null);
    steps.push('Trade bought and sold 4/4/2 batches, returned home, and deducted acquisition and fuel.');
    await shipView(page, 'intelligence');
    await page.getByText(/^Travel calibration ·/).click();
    await page.getByRole('img', { name: /Fuel prediction error by distance/ }).waitFor();
    const [download] = await Promise.all([page.waitForEvent('download'), button('Download evidence summary').click()]);
    await download.saveAs(path.join(output, 'evidence-summary.json'));
    const evidence = JSON.parse(await fs.readFile(path.join(output, 'evidence-summary.json'), 'utf8'));
    assert.ok(evidence.total_travel_samples >= 4);
    assert.ok(evidence.travel.every(row => row.mode === 'CRUISE' && row.distance === 5 && row.sample_count > 0));
    assert.ok(evidence.request_usage.account.requests > 0);
    assert.ok(evidence.request_usage.host.requests >= evidence.request_usage.account.requests);
    assert.equal(evidence.request_usage.account.rate_limits, 0);
    const cycle = evidence.cycles.find(row => row.run_id === trade.id);
    assert.equal(cycle.accounting.state, 'estimated_comparable');
    assert.equal(cycle.accounting.net_after_wear, 270);
    assert.equal(cycle.capital.total, null, 'Imported capital remains unknown');
    await page.getByText(/^Complete-cycle outcomes ·/).click();
    await page.getByText(`Run ${trade.id} · LOCAL-1 · trade · completed`, { exact: true }).click();
    await page.getByRole('heading', { name: /Complete-cycle accounting · Estimated comparable/ }).waitFor();
    const comparisons = page.locator('.fleet-comparison');
    await comparisons.getByText('Compare a fleet investment', { exact: true }).click();
    await comparisons.getByLabel('Reference closed cycle', { exact: true }).selectOption(String(trade.id));
    const baseline = cycle.accounting.net_credits_per_hour;
    const inputs = {
        'Base fleet net after all costs and wear in CR': String(Math.ceil(baseline) + 2000),
        'Conservative fleet net after all costs and wear in CR': String(Math.ceil(baseline) + 1000),
        'Fleet elapsed seconds': '3600', 'Total occupied ship-seconds': '7200', 'Added ship capital in CR': '40000', 'Added equipment capital in CR': '10000',
        'Units produced during one hauler round trip': '20', 'Hauler round trip in seconds': '600', 'Hauler cargo capacity in units': '30', 'Buyer capacity during one round trip in units': '25',
    };
    for (const [label, value] of Object.entries(inputs)) await comparisons.getByLabel(label, { exact: true }).fill(value);
    await comparisons.getByLabel('Source and assumptions', { exact: true }).fill('Synthetic case only: matched trade endpoints, empty inventory, full tanks, all fleet operating costs and wear included.');
    await comparisons.getByRole('checkbox').check();
    const beforeComparison = state().actions.length;
    let compared = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/economics/investment'));
    await comparisons.getByRole('button', { name: 'Calculate incremental payback' }).click();
    const positive = await (await compared).json();
    assert.equal(positive.ready, true);
    assert.equal(positive.alternative_run, trade.id);
    assert.equal(positive.capital, 50000);
    assert.ok(positive.payback_hours > 0);
    assert.ok(positive.conservative_payback_hours > positive.payback_hours);
    await comparisons.getByRole('region', { name: 'Fleet investment estimate' }).waitFor();
    await comparisons.getByRole('region', { name: 'Fleet investment estimate' }).screenshot({ path: path.join(output, 'fleet-comparison-desktop.png'), animations: 'disabled' });
    await comparisons.getByText('Operating case inputs', { exact: true }).click();
    await comparisons.getByLabel('Buyer capacity during one round trip in units', { exact: true }).fill('10');
    compared = page.waitForResponse(response => new URL(response.url()).pathname.endsWith('/economics/investment'));
    await comparisons.getByRole('button', { name: 'Calculate incremental payback' }).click();
    const constrained = await (await compared).json();
    assert.equal(constrained.ready, false);
    assert.equal(constrained.payback_hours, null);
    assert.equal(state().actions.length, beforeComparison, 'Report comparison issues no gameplay writes');
    await fs.writeFile(path.join(output, 'fleet-comparisons.json'), JSON.stringify({ positive, constrained }, null, 2));
    steps.push('Missing wear stays unresolved; entered wear reaches real workers, report and download. Fleet comparison uses incremental gain and withholds payback when buyer throughput is insufficient.');
    await page.getByText('API request usage', { exact: true }).click();
    await page.getByText(/Host and account budgets share/).waitFor();
    steps.push('Travel inputs, error analysis and summary export render without changing the conservative route policy.');

    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 1100 });
        await shipView(page, 'intelligence');
        await page.locator('#opportunities').scrollIntoViewIfNeeded();
        assert.equal(await page.locator('.app-shell').evaluate(element => getComputedStyle(element).colorScheme), 'dark');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.screenshot({ path: path.join(output, `intelligence-${width}.png`), animations: 'disabled' });
        await shipView(page, 'automation');
        await page.getByRole('heading', { name: 'Bounded scouting and trading' }).scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `operations-${width}.png`), animations: 'disabled' });
        await shipView(page, 'intelligence');
        await page.getByRole('heading', { name: 'Our travel and mining evidence' }).scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `evidence-${width}.png`), animations: 'disabled' });
        await page.getByText('API request usage', { exact: true }).scrollIntoViewIfNeeded();
        await page.screenshot({ path: path.join(output, `api-usage-${width}.png`), animations: 'disabled' });
        await page.getByText('API request usage', { exact: true }).locator('..').screenshot({ path: path.join(output, `api-usage-panel-${width}.png`), animations: 'disabled' });
        await comparisons.getByRole('region', { name: 'Fleet investment estimate' }).screenshot({ path: path.join(output, `fleet-comparison-${width}.png`), animations: 'disabled' });
        await page.getByRole('region', { name: 'Complete-cycle accounting' }).last().screenshot({ path: path.join(output, `cycle-accounting-${width}.png`), animations: 'disabled' });

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
