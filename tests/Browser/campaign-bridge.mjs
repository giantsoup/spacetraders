import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { once } from 'node:events';
import { mkdir, mkdtemp, readFile, realpath, rm, writeFile } from 'node:fs/promises';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import { chromium, webkit } from 'playwright';

const root = path.resolve(import.meta.dirname, '../..');
const evidence = path.resolve(process.env.CAMPAIGN_BRIDGE_EVIDENCE || path.join(root, 'storage/app/mvp-evidence/bridge'));
const php = process.env.PHP_BIN || 'php';
const fixtureRouter = path.join(import.meta.dirname, 'campaign-bridge.php');
const originOverride = process.env.CAMPAIGN_BRIDGE_ORIGIN;
const credentialsFile = process.env.CAMPAIGN_BRIDGE_CREDENTIALS;
const launch = { headless: true };
const bridge = '/campaign/bridge';
const scope = '?agent=GIANTSOUP';
const phone = { width: 402, height: 874 };
const route = bridge + '/routes/ICE_WATER/X1-SK70-KX3/X1-SK70-D46';
const ship = bridge + '/fleet/GIANTSOUP-9';
const screenshots = [];
const firstActions = {};
let serverLog = '';
let origin, browser, directory, server;

async function startFixture() {
    directory = await realpath(await mkdtemp(path.join(tmpdir(), 'campaign-bridge-')));
    for (const folder of ['framework/sessions', 'framework/views', 'framework/cache/data', 'logs']) {
        await mkdir(path.join(directory, 'storage', folder), { recursive: true });
    }
    await writeFile(path.join(directory, 'campaign.sqlite'), '');
    const socket = createServer();
    socket.listen(0, '127.0.0.1');
    await once(socket, 'listening');
    const { port } = socket.address();
    await new Promise(resolve => socket.close(resolve));
    origin = 'http://127.0.0.1:' + port;
    const env = {
        ...process.env,
        APP_ENV: 'local', APP_DEBUG: 'false', APP_URL: origin, APP_KEY: 'base64:' + randomBytes(32).toString('base64'),
        APP_CONFIG_CACHE: path.join(directory, 'config.php'), APP_ROUTES_CACHE: path.join(directory, 'routes.php'),
        DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(directory, 'campaign.sqlite'), DATABASE_URL: '',
        CACHE_STORE: 'array', SESSION_DRIVER: 'file', SESSION_COOKIE: 'bridge_loopback_' + port, SESSION_DOMAIN: '', SESSION_SECURE_COOKIE: 'false', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
        SPACETRADERS_LOCAL_ENGINE_ENABLED: 'false', SPACETRADERS_AUTOMATION_ENABLED: 'false', SPACETRADERS_OPERATOR_ID: '1',
        SPACETRADERS_LOCK_PATH: path.join(directory, 'locks'), SPACETRADERS_REQUEST_BUDGET_PATH: path.join(directory, 'budget'),
        SPACETRADERS_FIXTURE_PATH: path.join(directory, 'world.json'), LARAVEL_STORAGE_PATH: path.join(directory, 'storage'),
        CAMPAIGN_BRIDGE_FIXTURE_DIRECTORY: directory,
    };
    execFileSync(php, [fixtureRouter], { cwd: root, env });
    server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'public', fixtureRouter], { cwd: root, env, stdio: ['ignore', 'pipe', 'pipe'] });
    server.stderr.on('data', chunk => { serverLog += chunk; });
    await once(server.stderr, 'data');
    return JSON.parse(await readFile(path.join(directory, 'credentials.json'), 'utf8'));
}

async function capture(page, name, fullPage = true) {
    const file = path.join(evidence, name + '.png');
    await page.screenshot({ path: file, fullPage, animations: 'disabled' });
    screenshots.push(file);
}

async function layout(page, description) {
    assert.equal(await page.locator('main').count(), 1, description + ': one main');
    assert.equal(await page.locator('h1').count(), 1, description + ': one primary heading');
    const bounds = await page.evaluate(() => {
        const ids = [...document.querySelectorAll('[id]')].map(element => element.id);
        return { width: innerWidth, scrollWidth: document.documentElement.scrollWidth, duplicateIds: ids.filter((id, index) => ids.indexOf(id) !== index) };
    });
    assert.ok(bounds.scrollWidth <= bounds.width, description + ': ' + JSON.stringify(bounds));
    assert.deepEqual(bounds.duplicateIds, [], description + ': unique IDs');
}

async function bridgePage(page, url, name) {
    const response = await page.goto(origin + url);
    assert.equal(response.status(), 200, url);
    await page.getByRole('navigation', { name: 'Bridge sections' }).waitFor();
    await layout(page, name);
}

async function login(page, credentials) {
    await page.goto(origin + bridge + scope);
    await page.getByLabel('Email', { exact: true }).fill(credentials.email);
    await page.getByLabel('Password', { exact: true }).fill(credentials.password);
    const attempt = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/login', { timeout: 10000 }).catch(() => null);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    try {
        await page.waitForURL(origin + bridge + scope, { timeout: 10000 });
    } catch (error) {
        const response = await attempt;
        throw new Error('Fixture login ended at ' + page.url() + ' (POST ' + (response?.status() ?? 'missing') + ' → ' + (response?.headers().location ?? 'missing') + '): ' + await page.locator('main').innerText(), { cause: error });
    }
}

try {
    await mkdir(evidence, { recursive: true });
    let credentials;
    if (originOverride) {
        assert.equal(originOverride, 'http://spacetraders-dev.test', 'Only the development Herd host may use the live fixture');
        assert.ok(credentialsFile, 'Provide the synthetic fixture credential file');
        origin = originOverride;
        credentials = JSON.parse(await readFile(credentialsFile, 'utf8'));
        assert.equal(credentials.email, 'bridge@example.test');
    } else {
        credentials = await startFixture();
    }
    const worldFile = path.join(directory ?? path.dirname(credentialsFile), 'world.json');
    const upstreamCalls = async () => JSON.parse(await readFile(worldFile, 'utf8')).calls.length;
    const upstreamBefore = await upstreamCalls();
    const webkitMode = process.env.CAMPAIGN_BRIDGE_WEBKIT === '1';
    browser = await (webkitMode ? webkit : chromium).launch(launch);
    const context = await browser.newContext({ viewport: phone, hasTouch: true, colorScheme: 'dark', timezoneId: process.env.CAMPAIGN_BRIDGE_TIME_ZONE || 'America/Los_Angeles', reducedMotion: 'reduce' });
    const external = [];
    const failures = [];
    const rateReads = [];
    await context.route('**/*', request => {
        const url = new URL(request.request().url());
        if (url.origin === origin) return request.continue();
        external.push(url.origin + url.pathname);
        return request.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => failures.push(String(error)));
    page.on('response', response => {
        const url = new URL(response.url());
        if (response.status() >= 500) failures.push(response.status() + ' ' + url.pathname);
    });
    page.on('request', request => {
        const url = new URL(request.url());
        if (url.pathname === '/rate-limit' && request.headers().referer?.includes(bridge)) rateReads.push(url.pathname);
    });

    await login(page, credentials);
    const manifestUrl = await page.locator('link[rel="manifest"]').getAttribute('href');
    const manifestResponse = await context.request.get(new URL(manifestUrl, origin).href);
    assert.equal(manifestResponse.status(), 200);
    const manifest = await manifestResponse.json();
    assert.equal(manifest.start_url, bridge);
    assert.equal(manifest.display, 'standalone');
    for (const icon of manifest.icons) {
        const response = await context.request.get(new URL(icon.src, origin).href);
        assert.equal(response.status(), 200);
        assert.match(response.headers()['content-type'], /^image\/png/);
    }
    const tabs = page.getByRole('navigation', { name: 'Bridge sections' });
    const handsetViews = [
        ['brief', bridge], ['routes', bridge + '/routes'], ['route-detail', route],
        ['ended', bridge + '/routes/ended'], ['fleet', bridge + '/fleet'],
        ['ship-detail', ship], ['probes', bridge + '/fleet/probes'], ['log', bridge + '/log'],
    ];
    for (const [name, url] of handsetViews) {
        await bridgePage(page, url + scope, name);
        assert.equal(await tabs.getByRole('link').count(), 4);
        await capture(page, 'iphone-17-pro-' + name);
    }
    assert.deepEqual(rateReads.splice(0), [], 'Bridge pages do not request the shell budget widget');

    for (const [name, viewport] of [
        ['narrow', { width: 360, height: 780 }],
        ['safari', { width: 402, height: 760 }],
        ['ipad-portrait', { width: 1032, height: 1376 }],
        ['ipad-landscape', { width: 1376, height: 1032 }],
        ['desktop', { width: 1440, height: 900 }],
    ]) {
        await page.setViewportSize(viewport);
        await bridgePage(page, bridge + scope, name);
        assert.equal(await tabs.evaluate(element => getComputedStyle(element).position === 'fixed'), viewport.width < 768);
        if (viewport.width <= 402) {
            const action = page.locator('.bridge-attention').first().locator('.bridge-card-actions :is(a, button)').first();
            const actionBox = await action.boundingBox();
            const bar = await tabs.boundingBox();
            assert.ok(actionBox && bar && actionBox.y >= 0 && actionBox.y + actionBox.height <= bar.y, name + ': first action is visible above tabs');
            firstActions[name] = await action.innerText();
            assert.equal(await tabs.getByRole('link').evaluateAll(links => links.every(link => {
                const box = link.getBoundingClientRect();
                return box.width >= 44 && box.height >= 44;
            })), true, 'phone tabs have 44px targets');
        }
        await capture(page, name + '-brief', false);
        if (name === 'narrow') {
            await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
            await layout(page, 'narrow with 200% text');
        }
        if (name === 'safari' && !webkitMode) {
            const devtools = await context.newCDPSession(page);
            await devtools.send('Emulation.setSafeAreaInsetsOverride', { insets: { top: 0, left: 0, right: 0, bottom: 34 } });
            assert.equal(await tabs.evaluate(element => getComputedStyle(element).paddingBottom), '34px');
            await capture(page, 'iphone-17-pro-safe-area', false);
            await devtools.send('Emulation.setSafeAreaInsetsOverride', { insets: { top: 0, left: 0, right: 0, bottom: 0 } });
            await devtools.detach();
        }
        if (name.startsWith('ipad')) {
            await bridgePage(page, route + scope, name + ' route');
            assert.equal(await page.locator('.bridge-detail-layout').evaluate(element => getComputedStyle(element).display), name === 'ipad-landscape' ? 'grid' : 'block');
            if (name === 'ipad-landscape') {
                assert.equal(await page.locator('.bridge-list-pane a[aria-current="true"]').count(), 1);
            }
            await capture(page, name + '-route');
            await bridgePage(page, ship + scope, name + ' ship');
            await capture(page, name + '-ship');
        }
    }

    await page.setViewportSize(phone);
    await bridgePage(page, bridge + scope, 'engine health on Brief');
    const engineStale = await page.getByRole('heading', { name: "Engine isn't responding", exact: true }).isVisible();
    await bridgePage(page, route + scope, 'engine health on Route Detail');
    const routeEngineFooter = await page.locator('.bridge-engine').innerText();
    assert.match(routeEngineFooter, engineStale ? /^Engine isn’t responding/ : /^Engine running · heartbeat /,
        'Route Detail footer agrees with the engine heartbeat on Brief');
    const switcher = page.getByRole('button', { name: /Agent GIANTSOUP.*1 other agent needs you/i });
    await switcher.click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('link', { name: /SOUPFLEET/ }).click();
    await page.waitForURL(origin + bridge + '/routes?agent=SOUPFLEET');
    assert.equal(await tabs.getByRole('link', { name: 'Routes', exact: true }).getAttribute('aria-current'), 'page');
    await bridgePage(page, bridge + '?agent=SOUPFLEET', 'agent drain');
    await page.getByRole('heading', { name: 'SOUPFLEET held back for host rate limits' }).waitFor();
    await capture(page, 'iphone-17-pro-drain');

    await bridgePage(page, bridge + scope, 'Strategy round trip');
    await page.getByRole('link', { name: /How C0 decides/ }).click();
    await page.waitForURL(origin + '/campaign/strategy?agent=GIANTSOUP&from=bridge');
    await layout(page, 'Strategy');
    await capture(page, 'iphone-17-pro-strategy');
    await page.getByRole('link', { name: /Bridge/ }).click();
    await page.waitForURL(origin + bridge + scope);
    await page.getByRole('button', { name: 'Menu', exact: true }).click();
    await dialog.getByRole('link', { name: 'Classic status', exact: true }).click();
    await page.waitForURL(origin + '/campaign?agent=GIANTSOUP');
    await capture(page, 'iphone-17-pro-classic');
    await page.getByRole('link', { name: /Try Bridge/ }).click();
    await page.waitForURL(origin + bridge + scope);

    await page.getByRole('button', { name: 'Review Light Hauler #10', exact: true }).click();
    await dialog.getByRole('heading', { name: 'Resume Light Hauler #10?' }).waitFor();
    await dialog.getByText('No usable route remained.', { exact: true }).waitFor();
    await dialog.getByText('The director reported 5 consecutive failures.', { exact: true }).waitFor();
    await capture(page, 'iphone-17-pro-director-error-review', false);
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });

    const review = page.getByRole('button', { name: /Review Light Hauler #9/ });
    await review.click();
    const heading = dialog.getByRole('heading', { name: 'Resume Light Hauler #9?' });
    await heading.waitFor();
    const pauseTime = dialog.locator('time');
    assert.equal(await pauseTime.innerText(), await pauseTime.evaluate(element => new Intl.DateTimeFormat(undefined, {
        year: 'numeric', month: 'short', day: 'numeric',
        hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short',
    }).format(new Date(element.dateTime))), 'The sheet renders pause evidence in the browser time zone');
    assert.equal(await heading.evaluate(element => document.activeElement === element), true);
    await page.keyboard.press('Shift+Tab');
    assert.match(await page.locator(':focus').innerText(), /Resume Light Hauler #9/);
    await page.keyboard.press('Tab');
    assert.equal(await page.locator(':focus').innerText(), 'Done');
    assert.deepEqual(await dialog.locator('form button').allTextContents(), ['Keep paused', 'Resume Light Hauler #9']);
    await capture(page, 'iphone-17-pro-review', false);
    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'hidden' });
    assert.equal(await review.evaluate(element => document.activeElement === element), true);

    const csrf = await context.request.post(origin + bridge + '/arms/' + credentials.arm + '/resume', {
        data: { agent: 'GIANTSOUP', reset_id: credentials.reset, expected: {} }, maxRedirects: 0,
    });
    assert.equal(csrf.status(), 419, 'An authenticated POST without a CSRF token is rejected');

    const actionPath = '**/campaign/bridge/arms/' + credentials.arm + '/resume*';
    let releasePost;
    const postGate = new Promise(resolve => { releasePost = resolve; });
    const stalePost = async request => {
        const body = request.request().postDataJSON();
        await postGate;
        await request.continue({ postData: JSON.stringify({ ...body, expected: {} }) });
    };
    await page.route(actionPath, stalePost);
    await review.click();
    await heading.waitFor();
    await dialog.getByRole('button', { name: 'Resume Light Hauler #9', exact: true }).click();
    const working = dialog.getByRole('button', { name: 'Working…' });
    await working.first().waitFor();
    assert.equal(await working.evaluateAll(buttons => buttons.every(button => button.disabled)), true);
    releasePost();
    await dialog.getByRole('alert').getByText('This changed since you opened the review.', { exact: false }).waitFor();
    await capture(page, 'iphone-17-pro-review-refused', false);
    await page.unroute(actionPath, stalePost);
    await page.keyboard.press('Escape');

    await bridgePage(page, bridge + '/log' + scope, 'Log paging');
    const earlier = page.getByRole('button', { name: /Show earlier/ }).or(page.getByRole('link', { name: /Show earlier/ })).first();
    await earlier.click();
    await earlier.waitFor({ state: 'hidden' });

    await page.clock.install();
    await bridgePage(page, bridge + scope, 'polling');
    const failedPolls = [];
    const pollUrl = url => url.pathname === bridge;
    await page.route(pollUrl, request => {
        if (request.request().headers()['x-inertia-partial-data']?.includes('bridge')) {
            failedPolls.push(request.request().url());
            return request.abort();
        }
        return request.continue();
    });
    await page.clock.runFor(50000);
    const stale = page.getByText('Not updated since', { exact: false }).first();
    await stale.waitFor();
    assert.ok(failedPolls.length >= 1, 'Inertia tried to refresh after missed polls');
    await page.unroute(pollUrl);
    await page.clock.setSystemTime(new Date());
    const refreshed = page.waitForResponse(response => response.request().headers()['x-inertia-partial-data']?.includes('bridge'));
    await page.clock.runFor(16000);
    assert.equal((await refreshed).status(), 200);
    await stale.waitFor({ state: 'hidden' });

    if (!originOverride) {
        await bridgePage(page, bridge + '?agent=SOUPFLEET', 'reviewed budget hold');
        await page.getByRole('button', { name: 'Review SOUPFLEET', exact: true }).click();
        const confirmFleet = dialog.getByRole('button', { name: 'Resume the fleet', exact: true });
        const submitted = page.waitForRequest(request => request.method() === 'POST' && new URL(request.url()).pathname === bridge + '/fleet/resume');
        await confirmFleet.click();
        assert.match((await submitted).postDataJSON().budget_hold_id, /^[a-f0-9]{32}$/);
        await page.getByText('The budget hold cleared.', { exact: false }).waitFor();
        await dialog.waitFor({ state: 'hidden' });
    }

    assert.deepEqual(external, [], 'No browser requests left the fixture host');
    assert.deepEqual(failures, [], 'No JavaScript or server errors');
    const upstream = await upstreamCalls() - upstreamBefore;
    assert.equal(upstream, 0, 'Bridge journeys did not invoke the synthetic upstream transport');
    await writeFile(path.join(evidence, 'bridge-browser-results.json'), JSON.stringify({ origin, screenshots, failedPolls: failedPolls.length, upstream, firstActions, routeEngineFooter }, null, 2));
    console.log('Bridge browser checks passed on ' + origin + '. Evidence: ' + evidence);
} catch (error) {
    if (serverLog) console.error(serverLog.slice(-5000));
    throw error;
} finally {
    if (browser) await browser.close();
    if (server?.exitCode === null) {
        server.kill();
        await once(server, 'exit');
    }
    if (directory) await rm(directory, { recursive: true, force: true });
}
