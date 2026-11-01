import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import fs from 'node:fs/promises';
import { createServer } from 'node:net';
import path from 'node:path';
import { shipView } from './ship-browser-navigation.mjs';
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
const output = path.resolve(process.env.QA_ARTIFACT_DIR || path.join(root, 'storage/app/mvp-evidence/native-ui/browser'));
await fs.mkdir(output, { recursive: true });
const browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_EXECUTABLE } : {}) });
const server = spawn(php, ['-S', `127.0.0.1:${port}`, path.join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], { cwd: path.join(root, 'public'), env, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', value => { serverLog += value; });
server.stderr.on('data', value => { serverLog += value; });

const errors = [], steps = [], layouts = [];
let failure;
try {
    await once(server.stderr, 'data');
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        if (new URL(route.request().url()).hostname === 'fonts.bunny.net') return route.fulfill({ status: 200, contentType: 'text/css', body: '' });
        return route.abort();
    });
    const page = await context.newPage();
    await page.clock.install();
    page.on('pageerror', error => errors.push(error.message));
    page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${new URL(response.url()).pathname}`); });
    const button = name => page.getByRole('button', { name, exact: true });
    const post = async (name, suffix) => {
        const waiting = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith(suffix));
        await page.locator('dialog[open]').getByRole('button',{name,exact:true}).click(); const response = await waiting;
        assert.equal(response.status(), 202, await response.text()); return response.json();
    };
    await page.goto(origin + '/login');
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await button('Log in').click(); await page.waitForURL('**/agents');
    const deck = `${origin}/agents/${auth.agent_id}`;
    await page.goto(deck);
    await shipView(page, 'guide'); await post('Synchronize', '/sync'); await button('Done').click();
    const initial = cli("echo json_encode(App\\Models\\Ship::first()->snapshot);");
    const update = async (snapshot, age = 0, connection = 'connected') => {
        const encoded = Buffer.from(JSON.stringify(snapshot)).toString('base64');
        cli(`$ship=App\\Models\\Ship::first(); $ship->update(['snapshot'=>json_decode(base64_decode('${encoded}'),true),'symbol'=>json_decode(base64_decode('${encoded}'),true)['symbol'],'observed_at'=>now()->subSeconds(${age})]); App\\Models\\Agent::first()->update(['connection_state'=>'${connection}']); echo '{}';`);
        await page.goto(deck); await page.locator('#ship-title').waitFor();
    };
    const capture = async (name, fullPage = false) => { await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)))); await page.screenshot({ path: path.join(output, `${name}.png`), animations: 'disabled', fullPage }); };
    const fit = async (state, width, height) => {
        await page.setViewportSize({ width, height });
        const boxes = await page.locator('.resource-row:not(.systems-row), .home-action > button, .ship-identity').evaluateAll(elements => elements.map(el => {
            const b = el.getBoundingClientRect(); return { text: el.textContent.trim(), x: b.x, y: b.y, right: b.right, bottom: b.bottom, height: b.height };
        }));
        assert.ok(boxes.every(b => b.x >= 0 && b.right <= width && b.y >= 0 && b.bottom <= height && b.height >= 44), JSON.stringify({ state, width, height, boxes }));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
        layouts.push({ state, width, height, boxes }); await capture(`${state}-${width}x${height}`);
    };
    for (const state of ['docked', 'orbit', 'transit', 'cooldown']) {
        const snapshot = structuredClone(initial);
        snapshot.nav.status = state === 'orbit' || state === 'cooldown' ? 'IN_ORBIT' : state === 'transit' ? 'IN_TRANSIT' : 'DOCKED';
        if (state === 'transit') snapshot.nav.route = { departureTime: new Date(Date.now() - 60000).toISOString(), arrival: new Date(Date.now() + 138000).toISOString() };
        if (state === 'cooldown') snapshot.cooldown = { remainingSeconds: 50, expiration: new Date(Date.now() + 50000).toISOString() };
        await update(snapshot);
        for (const [w, h] of [[320,667], [375,548], [390,844]]) await fit(state,w,h);
        if (state === 'transit') { await button('Destination brief').click(); await page.getByRole('heading',{name:'Destination brief',exact:true}).waitFor(); await capture('destination-brief'); await button('Done').click(); }
    }
    steps.push('Measured ship identity, Fuel, Cargo and primary action fully inside all three portrait viewports in docked, orbit, transit and cooldown states.');
    await update(initial); await page.setViewportSize({ width: 390, height: 844 });
    const reportButton = button('Report'); await reportButton.click();
    const dialog = page.getByRole('dialog', { name: 'Ship report', exact: true }); await dialog.waitFor();
    assert.equal(await dialog.locator(':focus').count(), 1);
    for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); assert.equal(await dialog.evaluate(el => el.contains(document.activeElement)), true); }
    await capture('report-systems'); await page.keyboard.press('Escape');
    assert.equal(await reportButton.evaluate(el => el === document.activeElement), true);
    await page.locator('.scene-ship').click(); await dialog.waitFor(); await button('Done').click();
    for (const section of ['Fuel', 'Cargo']) {
        await page.locator('.resource-list').getByRole('button', { name: new RegExp(`^${section}`) }).click();
        await page.getByRole('heading', { name: section === 'Fuel' ? 'Fuel tank' : 'Cargo hold', exact: true }).waitFor();
        if (section === 'Cargo') await page.getByText('The hold is empty.', { exact: true }).waitFor();
        await capture(`report-${section.toLowerCase()}`); await button('Done').click();
    }
    await shipView(page, 'commands'); await page.goBack(); await dialog.waitFor({ state: 'hidden' }); await page.goForward();
    await page.getByRole('dialog', { name: 'Commands', exact: true }).waitFor(); await button('Done').click();
    await button('Menu').click(); await page.getByRole('navigation', { name: 'Main navigation' }).getByRole('link', { name: 'Profile', exact: true }).waitFor(); await page.keyboard.press('Escape');
    assert.equal(await button('Menu').evaluate(el => el === document.activeElement), true);
    steps.push('Report and ship share the report; Fuel/Cargo deep links, explicit Done, Escape, native focus containment/return, browser Back/Forward and Menu verified.');
    await shipView(page, 'commands');
    const action = await post('Preview orbit', '/commands/preview');
    await page.getByRole('region', { name: 'Review manual command', exact: true }).waitFor(); await capture('command-approval');
    await post('Approve command', `/commands/${action.id}/approve`);
    await page.getByRole('dialog', { name: 'Activity history', exact: true }).waitFor();
    assert.equal(cli(`echo json_encode(App\\Models\\ShipAction::find(${action.id})->state->value);`), 'pending');
    execFileSync(php, ['artisan', 'queue:work', 'database', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe' });
    await page.reload(); await page.locator('#activity').getByText(/Confirmed · Manual/).waitFor(); await capture('confirmed-activity');
    steps.push('Manual preview, exact approval, queued intent, real finite worker, confirmed persistence and activity navigation verified.');
    for (const state of ['pending', 'sending', 'uncertain', 'confirmed']) {
        cli(`App\\Models\\ShipAction::first()->update(['state'=>'${state}','review_required'=>${state === 'confirmed' ? 'true' : 'false'}]); echo '{}';`);
        await page.goto(deck); await button(state === 'uncertain' || state === 'confirmed' ? 'Review unresolved action' : 'View pending command').waitFor(); await capture(`action-${state}`);
        await shipView(page, 'commands'); assert.equal(await page.locator('#manual fieldset').evaluateAll(els => els.length > 0 && els.every(el => el.matches(':disabled'))), true);
    }
    await shipView(page, 'activity'); await post(`Reconcile #${action.id}`, `/commands/${action.id}/reconcile`);
    await page.locator('#activity').getByText('Confirmed · Review required', { exact: false }).waitFor();
    cli(`$a=App\\Models\\ShipAction::first(); $a->update(['review_required'=>false]); for($i=0;$i<44;$i++) { $c=$a->replicate(); $c->key=(string)Illuminate\\Support\\Str::uuid(); $c->save(); } echo '{}';`);
    await page.reload(); await button('Load older commands').click();
    await page.waitForFunction(() => document.querySelectorAll('#activity li').length === 45);
    assert.equal(await page.locator('#activity li').count(), 45); await capture('complete-history');
    const newest = cli("$a=App\\Models\\ShipAction::latest('id')->first(); for($i=0;$i<50;$i++) { $c=$a->replicate(); $c->key=(string)Illuminate\\Support\\Str::uuid(); $c->save(); } App\\Models\\ShipAction::first()->update(['state'=>'preview']); echo json_encode($c->id);");
    await page.waitForFunction(id => document.querySelector('#activity li strong')?.textContent.startsWith(`#${id} `), newest);
    await button('Load older commands').click();
    await page.waitForFunction(() => document.querySelectorAll('#activity li').length === 95);
    await button('Load older commands').click();
    await button(`Review saved preview #${action.id}`).click();
    const oldPreview = page.getByRole('region', {name:'Review manual command',exact:true}); await oldPreview.waitFor();
    await page.waitForResponse(r => r.request().method() === 'GET' && new URL(r.url()).pathname === `/agents/${auth.agent_id}`);
    assert.equal(await oldPreview.isVisible(),true,'An older preview remains reviewable after the latest-page poll');
    await button('Cancel preview').click();
    steps.push('Pending/sending/uncertain/confirmed-with-review states block duplicates. Read-only reconciliation retains confirmed review. History retains all 95 synthetic commands across a 50-command polling update, refreshes older states and keeps older saved previews open.');
    const run = cli(`$a=App\\Models\\Agent::first(); $r=App\\Models\\AutomationRun::create(['agent_id'=>$a->id,'active_agent_id'=>$a->id,'reset_id'=>$a->reset_id,'ship_symbol'=>'LOCAL-1','state'=>'waiting','plan'=>['cycles'=>1,'reserve'=>1000,'max_spend'=>100,'minutes'=>60],'totals'=>[],'expires_at'=>now()->addHour()]); echo json_encode($r->id);`);
    await page.goto(deck); await button('View active run').click();
    await post('Stop automation', `/runs/${run}/stop`); await page.getByText('Stop requested. Reserved work may settle; travel continues upstream.', { exact: true }).waitFor(); await capture('run-stop-requested');
    cli("App\\Models\\AutomationRun::query()->update(['active_agent_id'=>null,'state'=>'stopped']); App\\Models\\ShipAction::query()->delete(); echo '{}';");
    steps.push('Active-run approved limits and real Stop request retain Stop requested while ownership settles.');
    const due = structuredClone(initial); due.nav.status = 'IN_TRANSIT'; due.nav.route = { departureTime: new Date(Date.now()-120000).toISOString(), arrival: new Date(Date.now()-1000).toISOString() }; due.cooldown = { expiration: new Date(Date.now()-1000).toISOString(), remainingSeconds: 1 };
    await update(due); await page.getByText('Checking arrival', { exact: true }).waitFor(); await capture('arrival-due');
    const travelling = structuredClone(initial); travelling.nav.status = 'IN_TRANSIT'; travelling.nav.route = { departureTime: new Date(Date.now()-60000).toISOString(), arrival: new Date(Date.now()+60000).toISOString() };
    await update(travelling);
    const calls = JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8')).calls.length;
    await button('Pause motion').click(); const deadline = await page.locator('.ship-status time').getAttribute('datetime');
    const beforeTick = await page.locator('.ship-eta').innerText();
    await page.clock.fastForward(10000);
    assert.notEqual(await page.locator('.ship-eta').innerText(), beforeTick, 'Pausing decoration does not freeze the deadline countdown');
    await button('Resume motion').click();
    await page.evaluate(() => { Object.defineProperty(document, 'hidden', { configurable:true, get:()=>true }); document.dispatchEvent(new Event('visibilitychange')); });
    assert.equal(await page.locator('.scene-ship svg').evaluate(el=>getComputedStyle(el).animationPlayState),'paused');
    await page.clock.fastForward(51000);
    await page.evaluate(() => { delete document.hidden; document.dispatchEvent(new Event('visibilitychange')); window.dispatchEvent(new Event('pageshow')); });
    assert.equal(await page.locator('.ship-status time').getAttribute('datetime'), deadline); assert.equal(await page.getByText('Checking arrival', { exact: true }).isVisible(), true);
    assert.equal(JSON.parse(await fs.readFile(`${directory}/world.json`, 'utf8')).calls.length, calls, 'Ticks and lifecycle events generate no upstream requests');
    await page.emulateMedia({ reducedMotion: 'reduce' }); assert.equal(await page.locator('.scene-ship svg').evaluate(el => getComputedStyle(el).animationName), 'none');
    await page.clock.setSystemTime(new Date());
    await page.clock.resume();
    steps.push('Arrival due remains Checking; presentation pause/resume and lifecycle events preserve deadlines and issue no gameplay writes; reduced motion disables animation.');
    await update(initial, 600, 'error'); await page.getByText(/Last known state/).waitFor(); await capture('stale-disconnected');
    const missing = structuredClone(initial); delete missing.nav.flightMode; delete missing.fuel; delete missing.crew; delete missing.cooldown; delete missing.frame.condition; delete missing.cargo.inventory;
    await update(missing); assert.match(await page.locator('.resource-list').innerText(), /Unknown/); await button('Report').click(); await page.getByText('Crew aboard', { exact: true }).waitFor(); await capture('missing-fields');
    const zero = structuredClone(initial); zero.fuel = { current: 0, capacity: 0 }; await update(zero); assert.equal(await page.locator('.resource-meter i').first().evaluate(el => el.style.width), '0%');
    await capture('zero-fuel-capacity');
    const long = structuredClone(initial); long.symbol = 'LOCAL_WITH_A_VERY_LONG_SHIP_SYMBOL-123456789'; long.nav.waypointSymbol = 'X1-VERY-LONG-WAYPOINT-SYMBOL-123456789'; await update(long);
    for (const [width,height] of [[844,390],[1440,1000],[320,667]]) {
        await page.setViewportSize({width,height}); assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true); await page.locator('.cargo-row').scrollIntoViewIfNeeded(); await capture(`long-${width}`, true);
    }
    await page.addStyleTag({content: 'html { font-size: 24px; } .app-shell, .app-button, .resource-row, .home-link { font-size: 24px; }'});
    await page.locator('.cargo-row').scrollIntoViewIfNeeded(); await page.locator('.cargo-row').click(); await button('Done').click(); await capture('enlarged-text', true);
    cli("App\\Models\\Ship::query()->delete(); echo '{}';"); await page.goto(deck); await page.getByText('No ship observed', {exact:true}).waitFor(); await button('Refresh observations').waitFor(); await capture('no-ship');
    cli("App\\Models\\Agent::first()->update(['connection_state'=>'reset']); echo '{}';"); await page.reload(); await page.getByText('Archived agent · World reset.', {exact:false}).waitFor(); await shipView(page,'commands'); assert.equal(await page.locator('#manual fieldset').evaluateAll(els => els.length > 0 && els.every(el => el.matches(':disabled'))),true); await capture('archived');
    steps.push('Missing fields, zero-capacity fuel, long symbols, landscape/wide/enlarged text scrolling, no-ship and archive states verified.');
    assert.deepEqual(await page.locator('[id]').evaluateAll(els => { const ids=els.map(e=>e.id); return ids.filter((id,i)=>ids.indexOf(id)!==i); }), []);
    assert.deepEqual(errors, []);
} catch (error) { failure = error; }
finally {
    const result = { passed: !failure, steps, layouts, errors, failure: failure?.stack?.replaceAll(auth.password,'[redacted]'), browser: browser.version(), origin, fixture: directory };
    if (failure) { const page = browser.contexts()[0]?.pages()[0]; if (page) await page.screenshot({path:path.join(output,'failure.png'),fullPage:true}); }
    await fs.writeFile(path.join(output, 'server.log'), serverLog.replaceAll(auth.password, '[redacted]'));
    await fs.writeFile(path.join(output, 'results.json'), JSON.stringify(result,null,2));
    await browser.close(); server.kill(); await once(server,'exit'); console.log(JSON.stringify({...result,layouts:result.layouts.length,output},null,2));
}
if (failure) process.exitCode=1;
