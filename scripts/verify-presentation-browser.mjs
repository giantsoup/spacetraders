import { shipView } from './ship-browser-navigation.mjs';
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { assetName, readableText, readableLabel, readableValue, cargoSummary } from '../resources/js/dataPresentation.js';

assert.equal(readableLabel('waypointSymbol'), 'Destination');
assert.equal(readableLabel('configuration_mismatch'), 'Configuration mismatch');
assert.equal(readableValue(1750, 'reserve'), '1,750 CR');
assert.equal(readableValue(0, 'maxTotal'), '0 CR');
assert.equal(readableValue(0, 'scouting_cost'), '0 CR');
assert.equal(readableValue(1000, 'minimum_reserve'), '1,000 CR');
assert.equal(readableValue(false), 'No');
assert.equal(readableValue(null), 'Unknown');
assert.equal(cargoSummary({ IRON_ORE: 8, COPPER_ORE: 0 }), '8 iron ore, 0 copper ore');
assert.equal(cargoSummary({}), 'None');
assert.equal(cargoSummary(null), 'Unknown');
for (const [symbol, name] of Object.entries({
    MOUNT_SENSOR_ARRAY_II: 'Mount: Sensor Array II',
    MOUNT_GAS_SIPHON_II: 'Mount: Gas Siphon II',
    MOUNT_MINING_LASER_II: 'Mount: Mining Laser II',
    MOUNT_SURVEYOR_II: 'Mount: Surveyor II',
    MODULE_CARGO_HOLD_II: 'Module: Cargo Hold II',
    MODULE_CREW_QUARTERS_I: 'Module: Crew Quarters I',
    MODULE_MINERAL_PROCESSOR_I: 'Module: Mineral Processor I',
    MODULE_GAS_PROCESSOR_I: 'Module: Gas Processor I',
    REACTOR_FUSION_I: 'Reactor: Fusion I',
    IRON_ORE: 'Iron Ore', AI_MAINFRAMES: 'AI Mainframes',
    ASTEROID_FIELD: 'Asteroid Field', FUTURE_ASSET_IV: 'Future Asset IV',
    'GIANT_SOUP-1': 'GIANT_SOUP-1', 'X1-GK66-A1': 'X1-GK66-A1',
})) assert.equal(assetName(symbol), name);
assert.equal(assetName({ symbol: 'MOUNT_MINING_LASER_II', name: 'Mining Laser II' }), 'Mount: Mining Laser II');
assert.equal(assetName({ type: 'SHIP_MINING_DRONE' }), 'Ship: Mining Drone');
assert.equal(readableText('GIANT_SOUP-1 needs MODULE_CARGO_HOLD_II.'), 'GIANT_SOUP-1 needs Module: Cargo Hold II.');


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
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: `${directory}/no-config.php`, SESSION_COOKIE: `pages_${port}`, CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: `${directory}/storage`, LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) {
    await fs.mkdir(`${env.LARAVEL_STORAGE_PATH}/${child}`, { recursive: true });
}
const cli = code => JSON.parse(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(`${directory}/browser.json`, 'utf8'));
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/presentation'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });

const errors = [], steps = [];
let failure;
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
    page.setDefaultTimeout(10000);
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (['error', 'warning'].includes(message.type())) errors.push(message.text()); });
    page.on('response', response => { if (response.status() >= 400) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    const button = name => page.getByRole('button', { name, exact: true });
    const post = async (name, suffix) => {
        const response = page.waitForResponse(response => new URL(response.url()).pathname.endsWith(suffix) && response.request().method() === 'POST');
        if (name === 'Synchronize') await shipView(page, 'guide');
        else if (name.startsWith('Preview ')) await shipView(page, 'commands');
        await button(name).click();
        const received = await response;
        assert.equal(received.status(), 202, await received.text());
        return received.json();
    };
    const noJson = async () => {
        assert.equal(await page.locator('main pre').count(), 0);
        assert.doesNotMatch(await page.locator('main').innerText(), /\[object Object\]|\{\s*"[a-z_]+"\s*:/);
    };
    const buttonsFit = async () => {
        const layout = await page.locator('.approval-actions').evaluate(group => {
            const boxes = [...group.querySelectorAll('button')].map(button => {
                const rect = button.getBoundingClientRect();
                return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom, height: rect.height };
            });
            return { boxes, overflow: document.documentElement.scrollWidth > innerWidth };
        });
        assert.equal(layout.overflow, false);
        assert.ok(layout.boxes.every(box => box.height >= 44 && box.left >= 0 && box.right <= page.viewportSize().width));
        const [a, b] = layout.boxes;
        assert.ok(b.left - a.right >= 12 || b.top - a.bottom >= 12, 'Buttons and their focus rings have room between them');
    };
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    await page.goto(`${origin}/agents/${auth.agent_id}`);
    const equipmentWorld = JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8'));
    equipmentWorld.ships[0].mounts.push(
        { requirements: { crew: 0, power: 0 }, symbol: 'MOUNT_SENSOR_ARRAY_II', name: 'Sensor Array II' },
        { requirements: { crew: 0, power: 0 }, symbol: 'MOUNT_GAS_SIPHON_II' }, { requirements: { crew: 0, power: 0 }, symbol: 'MOUNT_SURVEYOR_II' },
    );
    equipmentWorld.ships[0].modules.push(
        { requirements: { crew: 0, power: 0 }, symbol: 'MODULE_CREW_QUARTERS_I' }, { requirements: { crew: 0, power: 0 }, symbol: 'MODULE_CREW_QUARTERS_I' },
        { requirements: { crew: 0, power: 0 }, symbol: 'MODULE_GAS_PROCESSOR_I' },
    );
    await fs.writeFile(`${directory}/world.json`, JSON.stringify(equipmentWorld));
    await post('Synchronize', '/sync');
    await page.getByRole('button', { name: 'Back to ship', exact: true }).click();
    await page.getByRole('button', { name: 'Report', exact: true }).click();
    const equipment = page.locator('.equipment-list');
    await equipment.getByText('Mount: Sensor Array II', { exact: true }).waitFor();
    await equipment.getByText('Module: Crew Quarters I × 2', { exact: true }).waitFor();
    assert.equal(await equipment.locator('li').count(), 8);
    assert.doesNotMatch(await equipment.innerText(), /MOUNT_|MODULE_/);
    await shipView(page, 'intelligence');
    assert.match(await page.locator('#market').innerText(), /Iron Ore/);
    assert.match(await page.locator('#waypoints').innerText(), /Asteroid Field/);
    await shipView(page, 'commands');
    const tradeGood = page.getByLabel('Trade good', { exact: true });
    await tradeGood.selectOption({ label: 'Iron Ore' });
    assert.equal(await tradeGood.inputValue(), 'IRON_ORE', 'Friendly labels retain exact API values');
    await page.locator('#manual').screenshot({ path: path.join(output, 'equipment-names.png') });
    steps.push('Asset names preserve categories, tiers, official names, duplicate quantities and raw select values');
    await page.getByLabel('Protected credits', { exact: true }).fill('1750');
    await post('Preview orbit', '/commands/preview');
    const approval = page.getByRole('region', { name: 'Review manual command', exact: true });
    await approval.waitFor();
    assert.equal(await approval.locator('.command-arguments').innerText(), 'Protected credit reserve\n1,750 CR');
    const primary = await button('Approve command').evaluate(element => ({ color: getComputedStyle(element).color, background: getComputedStyle(element).backgroundColor }));
    assert.equal(primary.background, 'rgb(166, 243, 208)');
    assert.equal(primary.color, 'rgb(10, 36, 29)');
    const shipBorder = await page.locator('.ship-choice.selected').evaluate(element => getComputedStyle(element).borderColor);
    assert.equal(shipBorder, 'rgb(154, 230, 198)', 'Selected ship retains its own highlight');
    await page.keyboard.press('Tab');
    await button('Approve command').focus();
    assert.equal(await button('Approve command').evaluate(element => getComputedStyle(element).outlineStyle), 'solid');
    for (const width of [1440, 768, 390, 320]) {
        await page.setViewportSize({ width, height: 1100 });
        await buttonsFit();
        await noJson();
        await approval.screenshot({ path: path.join(output, `orbit-review-${width}.png`) });
    }
    steps.push('Orbit arguments use readable labels and credit units; primary, secondary, selected-ship, focus and responsive button spacing verified at four widths');
    await button('Cancel preview').click();
    await page.setViewportSize({ width: 1440, height: 1100 });
    const worldFile = `${directory}/world.json`;
    const world = JSON.parse(await fs.readFile(worldFile, 'utf8'));
    world.ships[0].mounts = [];
    await fs.writeFile(worldFile, JSON.stringify(world));
    await post('Synchronize', '/sync');
    await shipView(page, 'commands');
    await page.getByLabel('Mining site', { exact: true }).selectOption('X1-TEST-ROCK');
    await page.getByLabel('Sale and refueling market', { exact: true }).selectOption('X1-TEST-ROCK');
    await page.getByLabel('Observed shipyard offer', { exact: true }).selectOption('0');
    await page.getByLabel('Maximum cost / minimum sale total', { exact: true }).fill('25000');
    await post('Preview one-time miner purchase', '/commands/preview');
    await page.getByText('Complete offered configuration and route', { exact: true }).click();
    const configuration = page.locator('.approval details');
    await configuration.getByText('Complete test miner', { exact: true }).waitFor();
    await configuration.getByText('Ship: Mining Drone', { exact: true }).waitFor();
    assert.ok((await configuration.innerText()).includes('20,000 CR'));
    assert.ok((await configuration.innerText()).includes('Mount: Mining Laser I'));
    assert.ok((await configuration.innerText()).includes('100%'));
    assert.ok((await configuration.innerText()).includes('Iron Ore'));
    assert.ok(await configuration.locator('dl dl').count() > 0);
    await noJson();
    await configuration.screenshot({ path: path.join(output, 'ship-configuration.png') });
    await button('Cancel preview').click();
    steps.push('Real one-time ship purchase preview renders nested equipment, arrays, route, percentages and costs as readable details');

    cli(`$agent=App\\Models\\Agent::firstOrFail();
        App\\Models\\AutomationRun::create(['agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'ship_symbol'=>'LOCAL-1','state'=>'completed','expires_at'=>now()->addHour(),'plan'=>['initial_fuel'=>100],'totals'=>['cycles'=>1,'credit_delta'=>200,'sale_proceeds'=>250,'fuel_units'=>10,'fuel_cost'=>50,'cash_contribution'=>200,'extracted'=>['IRON_ORE'=>10],'sold'=>['IRON_ORE'=>8],'unsold'=>['IRON_ORE'=>2]]]);
        App\\Models\\ShipAction::create(['agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'ship_symbol'=>'LOCAL-1','key'=>'presentation-evidence','command'=>'sell','origin'=>'manual','state'=>'uncertain','arguments'=>['symbol'=>'IRON_ORE','units'=>8],'expires_at'=>now()->addHour(),'outcome'=>['reconciled_at'=>'2026-11-01T09:30:00Z','credit_delta'=>0,'price_discrepancy'=>false,'optional_note'=>null,'transactions'=>[],'operator_note'=>'A note with <b>markup</b> stays text.']]);
        echo json_encode(true);`);
    await page.reload();
    await shipView(page, 'automation');
    await page.getByRole('heading', { name: 'Recorded outcomes', exact: true }).waitFor();
    assert.match(await page.locator('.cargo-totals').innerText(), /10 iron ore[\s\S]*8 iron ore[\s\S]*2 iron ore/);
    await shipView(page, 'activity');
    const evidence = page.locator('#activity details');
    assert.equal(await evidence.getAttribute('open'), null);
    await evidence.locator('summary').click();
    await evidence.getByText('Nov 1, 2026, 1:30:00 AM PST', { exact: true }).waitFor();
    assert.equal(await evidence.locator('time').getAttribute('datetime'), '2026-11-01T09:30:00Z');
    await evidence.getByText('0 CR', { exact: true }).waitFor();
    await evidence.getByText('No', { exact: true }).waitFor();
    await evidence.getByText('Unknown', { exact: true }).waitFor();
    await evidence.getByText('None recorded', { exact: true }).waitFor();
    await evidence.getByText('A note with <b>markup</b> stays text.', { exact: true }).waitFor();
    assert.equal(await evidence.locator('b').count(), 0, 'Evidence is escaped as text');
    for (const width of [1440, 390, 320]) {
        await page.setViewportSize({ width, height: 1100 });
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await noJson();
        await evidence.screenshot({ path: path.join(output, `reconciliation-${width}.png`) });
    }
    steps.push('Mining cargo counts and reconciliation preserve zero, false, unknown and empty values; escaped text, local DST timestamps and unchanged UTC datetime verified');
    await page.goto(`${origin}/agents/${auth.agent_id}?guided=1`);
    await page.locator('.guided-coach').waitFor();
    assert.equal(await page.locator('.guided-coach button:not(.app-button)').count(), 0);
    await noJson();
    assert.deepEqual(errors, []);
} catch (error) {
    failure = error;
} finally {
    const result = { passed: !failure, steps, errors, failure: failure?.message, browser: browser.version(), fixture: directory };
    await fs.writeFile(path.join(output, 'result.json'), JSON.stringify(result, null, 2));
    await browser.close();
    server.kill();
    await once(server, 'exit');
    console.log(JSON.stringify({ ...result, output }, null, 2));
}
if (failure) throw failure;
