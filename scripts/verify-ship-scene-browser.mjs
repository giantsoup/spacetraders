import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { pathToFileURL } from 'node:url';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || 'php';
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE || 'playwright');
const created = execFileSync(php, ['scripts/mvp-fixture.php', '--isolated'], { cwd: root, encoding: 'utf8' });
const directory = created.match(/Owned fixture directory: (.+)/)?.[1];
assert.ok(directory);
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', directory + '/.env'], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.DB_DATABASE, directory + '/database.sqlite');
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, directory + '/world.json');
const socket = createServer();
socket.listen(0, '127.0.0.1');
await once(socket, 'listening');
const port = socket.address().port;
await new Promise(resolve => socket.close(resolve));
const origin = 'http://127.0.0.1:' + port;
const env = { ...process.env, ...parsed, APP_URL: origin, APP_CONFIG_CACHE: directory + '/no-config.php', SESSION_COOKIE: 'scenes_' + port, CACHE_DRIVER: 'array', CACHE_STORE: 'array', SESSION_DRIVER: 'file', LARAVEL_STORAGE_PATH: directory + '/storage', LOG_CHANNEL: 'stderr' };
for (const child of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) await fs.mkdir(env.LARAVEL_STORAGE_PATH + '/' + child, { recursive: true });
const cli = code => JSON.parse(execFileSync(php, ['-r', "require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); " + code], { cwd: root, env, encoding: 'utf8' }));
const auth = JSON.parse(await fs.readFile(directory + '/browser.json', 'utf8'));
const pilot = cli("$user=App\\Models\\User::first(); $agent=App\\Models\\Agent::first(); $agent->update(['reset_id'=>'2026-11-08']); echo json_encode(['user'=>$user->id,'ship'=>App\\Models\\Ship::orderBy('id')->first()->snapshot]);");
env.SPACETRADERS_OPERATOR_ID = String(pilot.user);
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'docs/ship-status-graphics/evidence'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', '127.0.0.1:' + port, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'], windowsHide: true });
const started = once(server.stderr, 'data');
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });
const errors = [], checks = [], screenshots = [], layouts = [], blocked = [];
const instant = Date.parse('2026-11-15T20:00:00Z');
const iso = offset => new Date(instant + offset).toISOString();
const points = {
    PLANET: { symbol: 'X1-PZ83-A1', type: 'PLANET', traits: [{ symbol: 'OCEAN', description: 'oceans' }] },
    ORBITAL_STATION: { symbol: 'X1-PZ83-A2', type: 'ORBITAL_STATION', traits: [] },
    ASTEROID: { symbol: 'X1-PZ83-ROCK', type: 'ASTEROID', traits: [] },
    JUMP_GATE: { symbol: 'X1-PZ83-GATE', type: 'JUMP_GATE', traits: [] },
};
const destination = { symbol: 'X1-PZ83-H47', type: 'PLANET', traits: [{ symbol: 'VOLCANIC' }] };
const encode = value => Buffer.from(JSON.stringify(value)).toString('base64');
cli("$agent=App\\Models\\Agent::first(); foreach(json_decode(base64_decode('" + encode([...Object.values(points), destination]) + "'),true) as $point) { App\\Models\\GameObservation::create(['agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'kind'=>'waypoint','symbol'=>$point['symbol'],'observed_at'=>now(),'data'=>$point+['systemSymbol'=>'X1-PZ83']]); } echo '{}';");
let upstreamCalls;
const snapshotFor = (pose, point = points.PLANET) => {
    const snapshot = structuredClone(pilot.ship);
    snapshot.nav = { ...snapshot.nav, systemSymbol: 'X1-PZ83', waypointSymbol: point.symbol, status: { docked: 'DOCKED', orbit: 'IN_ORBIT', transit: 'IN_TRANSIT', arriving: 'IN_TRANSIT' }[pose], route: { departureTime: iso(-120000), arrival: iso(pose === 'arriving' ? -1000 : 120000), destination: { symbol: destination.symbol, type: destination.type } } };
    if (pose === 'unknown') snapshot.nav.waypointSymbol = null;
    return snapshot;
};
let page, failure;
try {
    await started;
    const referenceContext = await browser.newContext({ viewport: { width: 402, height: 874 }, reducedMotion: 'reduce' });
    await referenceContext.route('**/*', route => route.request().url().startsWith('file:') ? route.continue() : route.abort());
    for (const round of ['round-1-all-states', 'round-2-centered-phone']) {
        const reference = await referenceContext.newPage();
        await reference.goto(pathToFileURL(path.join(root, 'docs/ship-status-graphics', round + '.html')).href);
        const name = 'approved-' + round + '-402.png';
        await reference.screenshot({ path: path.join(output, name), fullPage: true });
        screenshots.push(name);
    }
    await referenceContext.close();
    const context = await browser.newContext({ viewport: { width: 402, height: 874 }, timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        blocked.push(route.request().url());
        return route.abort();
    });
    page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 500) errors.push(response.status() + ' ' + new URL(response.url()).pathname); });
    await page.clock.install({ time: new Date(instant) });
    await page.clock.pauseAt(new Date(instant));
    const settle = () => page.clock.runFor(50);
    const button = name => page.getByRole('button', { name, exact: true });
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click();
    await page.waitForURL('**/agents');
    cli("App\\Models\\Agent::first()->update(['connection_state'=>'connected']); echo '{}';");
    upstreamCalls = JSON.parse(await fs.readFile(directory + '/world.json', 'utf8')).calls.length;
    const deck = origin + '/agents/' + auth.agent_id;
    const update = async (pose, point = points.PLANET, override) => {
        const snapshot = override || snapshotFor(pose, point);
        cli("App\\Models\\Ship::orderBy('id')->first()->update(['snapshot'=>json_decode(base64_decode('" + encode(snapshot) + "'),true),'observed_at'=>'" + iso(0) + "']); echo '{}';");
        await page.goto(deck);
        await page.locator('.ship-scene').waitFor();
        await settle();
        assert.equal(await page.locator('.ship-scene').getAttribute('data-pose'), pose);
        return snapshot;
    };
    const capture = async name => {
        await page.screenshot({ path: path.join(output, name), fullPage: true, animations: 'disabled' });
        screenshots.push(name);
    };
    const fit = async (pose, width, height, expectedHeight) => {
        const boxes = await page.locator('.ship-scene').evaluate(scene => {
            const box = selector => scene.querySelector(selector).getBoundingClientRect().toJSON();
            return { scene: scene.getBoundingClientRect().toJSON(), ship: box('.scene-ship'), art: box('.scene-location'), label: box('.scene-location-label'), scrollWidth: document.documentElement.scrollWidth };
        });
        const inside = box => box.left >= boxes.scene.left - 1 && box.right <= boxes.scene.right + 1 && box.top >= boxes.scene.top - 1 && box.bottom <= boxes.scene.bottom + 1;
        const overlaps = boxes.ship.left < boxes.label.right && boxes.ship.right > boxes.label.left && boxes.ship.top < boxes.label.bottom && boxes.ship.bottom > boxes.label.top;
        assert.equal(boxes.scene.height, expectedHeight, JSON.stringify({ pose, width, height, boxes }));
        assert.ok(inside(boxes.ship), 'Ship control stays inside scene: ' + JSON.stringify({ pose, width, height, boxes }));
        assert.ok(inside(boxes.art) && inside(boxes.label), 'Location and label fit: ' + JSON.stringify({ pose, width, height, boxes }));
        assert.ok(boxes.ship.height >= 44);
        assert.equal(overlaps, false, 'Ship control avoids label: ' + JSON.stringify({ pose, width, height, boxes }));
        assert.ok(boxes.scrollWidth <= width);
        layouts.push({ pose, width, height, boxes });
    };
    for (const [width, height, sceneHeight] of [[402,874,230], [402,700,230], [402,620,128], [820,1180,230], [1280,800,230], [402,560,72]]) {
        await page.setViewportSize({ width, height });
        for (const pose of ['docked', 'orbit', 'transit', 'arriving', 'unknown']) {
            await update(pose);
            await fit(pose, width, height, sceneHeight);
            await capture('flight-' + pose + '-' + width + 'x' + height + '.png');
        }
    }
    checks.push('Five poses, control bounds, 44px targets, label separation and no horizontal scroll at all six viewports; 230/128/72px breakpoints.');
    await page.setViewportSize({ width: 402, height: 874 });
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await update('transit');
    const centre = async () => { const box = await page.locator('.scene-ship').boundingBox(); return box.x + box.width / 2; };
    const before = await centre();
    await page.clock.fastForward(30000);
    assert.ok(await centre() > before + 10, 'Ship centre advances with route progress');
    checks.push('Playwright clock advances transit ship rightwards.');
    for (const height of [874,620]) {
        await page.setViewportSize({ width: 402, height });
        for (const point of Object.values(points)) {
            await update('docked', point);
            const port = await page.locator('.ship-scene').evaluate(scene => {
                const art = scene.querySelector('.scene-location svg');
                const rows = [...art.querySelectorAll('rect')].filter(pixel => +pixel.getAttribute('y') >= 10 && +pixel.getAttribute('y') <= 13);
                const minX = rows.length ? Math.min(...rows.map(pixel => +pixel.getAttribute('x'))) : 6;
                const edge = art.getBoundingClientRect().left + minX * art.getBoundingClientRect().width / 24;
                const ship = scene.querySelector('.scene-ship svg').getBoundingClientRect();
                const clamp = scene.querySelector('.scene-clamp').getBoundingClientRect();
                const scale = ship.width / 32;
                return { edge, nose: ship.left + 28 * scale, clamp: clamp.toJSON(), gap: 3 * scale, shipY: ship.top + ship.height / 2, sceneY: scene.getBoundingClientRect().top + scene.clientHeight / 2 };
            });
            assert.ok(Math.abs(port.edge - port.clamp.right) <= 2, JSON.stringify(port));
            assert.ok(Math.abs(port.nose - port.clamp.left) <= 2, JSON.stringify(port));
            assert.ok(Math.abs(port.edge - port.nose - port.gap) <= 2, JSON.stringify(port));
            assert.ok(Math.abs(port.shipY - port.sceneY) <= 1);
            await capture('dock-port-' + point.type.toLowerCase() + '-402x' + height + '.png');
        }
    }
    checks.push('Docked nose, 3K gap and clamp meet computed sprite port for planet, station, asteroid and jump gate at S=9 and S=5.');
    await page.setViewportSize({ width: 402, height: 874 });
    for (const pose of ['docked', 'orbit', 'transit']) {
        await page.emulateMedia({ reducedMotion: 'no-preference' });
        await update(pose);
        const animated = page.locator('.ship-scene');
        const expected = pose === 'docked' ? 1 : pose === 'orbit' ? 3 : 2;
        assert.equal(await animated.evaluate(scene => scene.getAnimations({ subtree: true }).length), expected);
        await button('Pause motion').click();
        await settle();
        assert.equal(await animated.evaluate(scene => scene.getAnimations({ subtree: true }).every(animation => animation.playState === 'paused')), true);
        const times = await animated.evaluate(async scene => {
            const animations = scene.getAnimations({ subtree: true });
            await Promise.all(animations.map(animation => animation.ready));
            return animations.map(animation => animation.currentTime);
        });
        await page.clock.runFor(1000);
        assert.deepEqual(await animated.evaluate(scene => scene.getAnimations({ subtree: true }).map(animation => animation.currentTime)), times);
        await button('Resume motion').click();
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await animated.evaluate(scene => scene.getAnimations({ subtree: true }).length), 0);
    }
    checks.push('Pause freezes every animation/currentTime; reduced motion removes port blink, both ring paths, bob and flame in docked, orbit and transit.');
    await page.emulateMedia({ reducedMotion: 'no-preference' });
    const nearArrival = snapshotFor('transit');
    const clockNow = await page.evaluate(() => Date.now());
    nearArrival.nav.route.departureTime = new Date(clockNow - 10000).toISOString();
    nearArrival.nav.route.arrival = new Date(clockNow + 2000).toISOString();
    await update('transit', points.PLANET, nearArrival);
    await page.clock.fastForward(3000);
    assert.equal(await page.locator('.ship-scene').getAttribute('data-pose'), 'arriving');
    checks.push('Expired flight-deck countdown switches to arriving without confirming arrival.');
    const docked = snapshotFor('docked');
    const travelling = snapshotFor('transit');
    const campaignNow = await page.evaluate(() => Date.now());
    travelling.nav.route.departureTime = new Date(campaignNow - 10000).toISOString();
    travelling.nav.route.arrival = new Date(campaignNow + 10000).toISOString();
    cli("$user=App\\Models\\User::first(); $agent=App\\Models\\Agent::first(); $ships=App\\Models\\Ship::orderBy('id')->get(); $docked=json_decode(base64_decode('" + encode(docked) + "'),true); $docked['registration']['role']='COMMAND'; $ships[0]->update(['snapshot'=>$docked]); $transit=json_decode(base64_decode('" + encode(travelling) + "'),true); $transit['symbol']='LOCAL-2'; $transit['registration']['role']='SATELLITE'; App\\Models\\Ship::updateOrCreate(['agent_id'=>$agent->id,'symbol'=>'LOCAL-2'],['snapshot'=>$transit,'observed_at'=>now()]); $revision=app(App\\Gameplay\\CampaignPlaybook::class)->import(file_get_contents('campaigns/weekly-default.yaml')); $grant=app(App\\Gameplay\\CampaignControl::class)->approve($revision,$user); App\\Models\\CampaignEpisode::create(['campaign_grant_id'=>$grant->id,'agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'phase'=>'earn','start_credits'=>100000,'opened_at'=>now(),'next_reset_at'=>now()->addDay()]); echo '{}';");
    for (const [width, height] of [[402,874], [1280,800], [402,560]]) {
        await page.setViewportSize({ width, height });
        await page.goto(origin + '/campaign');
        await page.locator('.ship-scene').first().waitFor();
        await settle();
        assert.equal(await page.locator('.ship-scene').count(), 2);
        assert.deepEqual(await page.locator('.ship-scene').evaluateAll(scenes => scenes.map(scene => scene.dataset.pose)), ['docked', 'transit']);
        assert.deepEqual(await page.locator('.scene-location').evaluateAll(locations => locations.map(location => location.dataset.locationSymbol)), [points.PLANET.symbol, destination.symbol]);
        assert.equal(await page.locator('.ship-scene').evaluateAll(scenes => scenes.every(scene => scene.clientHeight === 128 && scene.classList.contains('compact'))), true);
        assert.equal(await page.locator('.ship-scene button, .ship-scene a, .ship-scene [tabindex], .ship-scene input').count(), 0);
        assert.deepEqual(await page.locator('[aria-labelledby="ships-heading"] li').evaluateAll(ships => ships.map(ship => [...ship.querySelectorAll('dt')].map(term => term.textContent))), [['Role', 'Waypoint', 'Navigation', 'Fuel', 'Cargo'], ['Role', 'Waypoint', 'Navigation', 'Fuel', 'Cargo']]);
        assert.equal(await page.locator('.ship-scene .scene-location svg').first().evaluate(sprite => sprite.getBoundingClientRect().width), 120);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.deepEqual(await page.locator('[id]').evaluateAll(elements => { const ids = elements.map(element => element.id); return ids.filter((id, index) => ids.indexOf(id) !== index); }), []);
        if (height !== 560) await capture('campaign-' + width + 'x' + height + '.png');
    }
    const campaignShip = page.locator('.scene-ship').nth(1);
    const start = (await campaignShip.boundingBox()).x;
    let polls = 0;
    page.on('request', request => { if (new URL(request.url()).pathname === '/campaign') polls++; });
    await page.clock.fastForward(3000);
    assert.ok((await campaignShip.boundingBox()).x > start);
    assert.equal(polls, 0, 'Campaign scene ticker moves between polls');
    await page.clock.fastForward(8000);
    assert.equal(await page.locator('.ship-scene').nth(1).getAttribute('data-pose'), 'arriving');
    assert.equal(polls, 0, 'Campaign arrival pose changes before the first poll');
    await page.goto(deck);
    await settle();
    checks.push('Campaign renders one non-interactive compact scene per ship, destination in transit, unique IDs, all text retained, and ticker advances then expires between 15-second polls.');
    assert.equal(JSON.parse(await fs.readFile(directory + '/world.json', 'utf8')).calls.length, upstreamCalls, 'Scene reads and ticks never invoke the synthetic API');
    assert.deepEqual(errors, []);
} catch (error) { failure = error; }
finally {
    if (failure && page) await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true }).catch(() => {});
    if (!failure) await fs.rm(path.join(output, 'failure.png'), { force: true });
    const result = { passed: !failure, checks, layouts, screenshots, errors, blockedExternalRequests: blocked.length, failure: failure?.stack?.replaceAll(auth.password, '[redacted]'), browser: browser.version() };
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify(result, null, 2) + '\n');
    await fs.writeFile(path.join(directory, 'scene-server.log'), serverLog.replaceAll(auth.password, '[redacted]'));
    await browser.close();
    server.kill();
    await once(server, 'exit');
    console.log(JSON.stringify({ ...result, layouts: layouts.length, output }, null, 2));
}
if (failure) process.exitCode = 1;

