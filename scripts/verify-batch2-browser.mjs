import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { withBrowserVerification } from './browser-verification.mjs';
import { waitUntil } from './verification-lifecycle.mjs';
import { shipView } from './ship-browser-navigation.mjs';

const root = path.resolve(import.meta.dirname, '..');
const php = process.env.PHP_BIN || path.join(root, 'scripts/herd-php');
const output = path.resolve(process.env.QA_ARTIFACT_DIR || 'storage/app/integration-evidence/combined-browser');
await withBrowserVerification({ root, php, output, persistentCache: true }, async ({ browser, origin, auth, cli, execute, env, parsed }) => {
    const target = 'X1-TEST-HIDDEN';
    const world = async edit => {
        const value = JSON.parse(await fs.readFile(parsed.SPACETRADERS_FIXTURE_PATH, 'utf8'));
        if (edit) { edit(value); await fs.writeFile(parsed.SPACETRADERS_FIXTURE_PATH, JSON.stringify(value)); }
        return value;
    };
    await world(w => {
        w.waypoints.push({ symbol: target, systemSymbol: 'X1-TEST', type: 'PLANET', x: 1, y: 0, orbitals: [], traits: [{ symbol: 'UNCHARTED' }] });
        w.ships.push({ ...structuredClone(w.ships[0]), symbol: 'LOCAL-2' });
        w.ships[0].nav.waypointSymbol = target;
        w.ships[0].nav.status = 'IN_ORBIT';
        w.ships[0].mounts.push({ symbol: 'MOUNT_SENSOR_ARRAY_I', requirements: { crew: 1, power: 1 } });
        w.scan_waypoints = [];
        w.chart_traits = [{ symbol: 'MARKETPLACE' }];
    });
    const sync = () => cli("app(App\\Gameplay\\AgentSynchronizer::class)->sync(App\\Models\\Agent::firstOrFail()); echo '{}';");
    sync();
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, colorScheme: 'light', timezoneId: 'America/Los_Angeles' });
    await context.route('**/*', route => new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto(`${origin}/login`);
    assert.equal((await context.request.get(`${origin}/register`)).status(), 404);
    await page.getByLabel('Email', { exact: true }).fill(auth.email);
    await page.getByLabel('Password', { exact: true }).fill(auth.password);
    await page.getByRole('button', { name: 'Log in', exact: true }).click();
    await page.waitForURL('**/agents');
    const deck = `${origin}/agents/${auth.agent_id}`;
    const csrf = decodeURIComponent((await context.cookies()).find(cookie => cookie.name === 'XSRF-TOKEN').value);
    const post = async (suffix, data = {}, status = 202) => {
        const response = await context.request.post(deck + suffix, { headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrf }, data });
        assert.equal(response.status(), status, await response.text());
        return response.json();
    };
    const forbidden = await context.request.post(deck + '/adviser/policies', { headers: { Accept: 'application/json', 'Sec-Fetch-Site': 'cross-site', Origin: 'https://outside.invalid' }, data: {} });
    assert.equal(forbidden.status(), 419);
    const policy = await post('/adviser/policies', {
        previous_id: null, ship_symbol: 'LOCAL-1', system_symbol: 'X1-TEST', expires_at: new Date(Date.now() + 3600000).toISOString().replace('.000Z', 'Z').replace(/\.\d{3}Z$/, 'Z'), acknowledge: true,
        terms: { activities: ['wait', 'read', 'sale', 'refuel', 'mining'], cargo: [], reserve: 1000, max_spend: 100, max_loss: 100, max_seconds: 1800, max_fuel: 100, refuel_units: 100, allow_trial: true, api_request_cap: 40,
            exploration: { enabled: false, max_cost: 0, max_fuel: 0, max_seconds: 0, interval_seconds: 0 } },
    });
    const argumentsFor = reserve => ({ waypointSymbol: target, question: 'MARKETPLACE', maxCooldownSeconds: 300, reserve });
    const preview = (command, reserve = 1000, status = 202) => post('/commands/preview', { key: randomUUID(), ship_symbol: 'LOCAL-1', command, arguments: argumentsFor(reserve) }, status);
    await preview('scan_waypoints', 1000000000, 422);
    const scan = await preview('scan_waypoints');
    const state = () => cli("echo json_encode(['actions'=>App\\Models\\ShipAction::orderBy('id')->get(),'jobs'=>Illuminate\\Support\\Facades\\DB::table('jobs')->get(),'policies'=>App\\Models\\AdviserPolicy::all(),'decisions'=>App\\Models\\AdviserDecision::all()]);");
    const before = state();
    cli("app(App\\Gameplay\\DeploymentGate::class)->close('batch2'); echo '{}';");
    await post(`/commands/${scan.id}/approve`, {}, 503);
    await post(`/adviser/policies/${policy.id}/pause`, {}, 503);
    await post('/economics/investment', {}, 503);
    execute(['artisan', 'queue:work', 'database', '--queue=spacetraders', '--once', '--sleep=0'], env);
    assert.deepEqual(state(), before);
    assert.equal((await page.goto(deck)).status(), 503);
    assert.equal(await page.locator('html').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    await page.screenshot({ path: path.join(output, 'combined-drain-390.png') });
    if (process.env.QA_PREVIEW_HOLD === '1') {
        console.log(`Owned combined preview: ${origin}`);
        await waitUntil(() => fs.access(path.join(output, 'preview.done')).then(() => true, () => false), 60000);
    }
    cli("app(App\\Gameplay\\DeploymentGate::class)->open('batch2'); echo '{}';");
    await post(`/commands/${scan.id}/approve`);
    const decision = await post('/adviser/assess', { policy_id: policy.id, investigation_id: null });
    assert.ok(decision.candidates.filter(c => !['wait', 'read', 'contracts'].includes(c.activity)).every(c => c.classification !== 'feasible'));
    const worker = () => execute(['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], env);
    worker(); worker();
    assert.equal(state().actions.find(a => a.id === scan.id).state, 'confirmed');
    assert.equal((await world()).writes.length, 1);
    await world(w => { w.ships[0].cooldown.expiration = new Date(Date.now() - 1000).toISOString(); });
    sync();
    const chart = await preview('chart');
    await post(`/commands/${chart.id}/approve`); worker();
    const settled = cli("$a=App\\Models\\Agent::firstOrFail(); echo json_encode(['accounting'=>app(App\\Gameplay\\CreditAccounting::class)->summary($a),'history'=>app(App\\Gameplay\\CycleAccounting::class)->history($a)]);");
    assert.equal(settled.accounting.confirmed_change, 100);
    assert.equal(settled.accounting.unexplained_change, 0);
    assert.deepEqual(settled.history, []);
    assert.equal((await world()).writes.length, 2);
    await post(`/adviser/policies/${policy.id}/pause`);
    const history = await (await context.request.get(deck + '/adviser/history')).json();
    assert.equal(history.execution_enabled, false); assert.equal(history.model_enabled, false);
    assert.equal(history.decisions[0].status, 'policy_invalidated');
    assert.equal(state().jobs.length, 0);
    await page.goto(deck); await shipView(page, 'activity');
    assert.equal(await page.locator('.app-shell').evaluate(el => getComputedStyle(el).colorScheme), 'dark');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    await page.screenshot({ path: path.join(output, 'combined-activity-390.png'), fullPage: true });
    await shipView(page, 'adviser');
    await page.screenshot({ path: path.join(output, 'combined-adviser-390.png'), fullPage: true });
    assert.deepEqual(errors, []);
    return { checks: ['private login and real CSRF', 'reserve rejection before exploration', 'drain gates scan approval, adviser pause, economics and worker pickup without mutation', 'pending scan blocks adviser actions', 'partial scan and chart use finite durable workers without replay', 'chart reward reconciles but is excluded from cycle income', 'policy pause invalidates review without enabling execution', 'dark mobile activity and adviser under light OS'], errors };
});
