import assert from 'node:assert/strict';
import { spawn, spawnSync } from 'node:child_process';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { once } from 'node:events';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:net';
import { chromium } from 'playwright';

const root = fileURLToPath(new URL('../', import.meta.url));
const evidence = process.env.FLEET_PURCHASE_EVIDENCE || path.join(tmpdir(), 'fleet-purchase-evidence');
await mkdir(evidence, { recursive: true });
const directory = await mkdtemp(path.join(tmpdir(), 'fleet-purchase-browser-'));
for (const folder of ['sessions', 'views', 'logs']) await mkdir(path.join(directory, folder));
await writeFile(path.join(directory, 'campaign.sqlite'), '');
const php = process.env.HERD_PHP || 'C:/Users/taylo/.config/herd/bin/php85/php.exe';
const env = {
    ...process.env, APP_ENV: 'testing', APP_DEBUG: 'false',
    APP_KEY: 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
    APP_CONFIG_CACHE: path.join(directory, 'config.php'), APP_ROUTES_CACHE: path.join(directory, 'routes.php'),
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.join(directory, 'campaign.sqlite'), DATABASE_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'file', QUEUE_CONNECTION: 'sync', BCRYPT_ROUNDS: '4',
    LOG_CHANNEL: 'stderr', SPACETRADERS_LOCAL_ENGINE_ENABLED: 'false', SPACETRADERS_AUTOMATION_ENABLED: 'false',
    SPACETRADERS_FIXTURE_PATH: '', CAMPAIGN_STATUS_BROWSER_DIRECTORY: directory,
};
function execute(args) {
    const result = spawnSync(php, args, { cwd: root, env, windowsHide: true, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stdout + result.stderr);
    return result.stdout;
}
const router = path.join(root, 'tests/Browser/campaign-status.php');
const cli = code => JSON.parse(execute(['-r', String.raw`require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); Illuminate\Support\Facades\Http::preventStrayRequests(); Illuminate\Support\Facades\Http::fake(fn () => throw new RuntimeException('Outbound HTTP denied.')); ` + code]));
execute([router]);
const fixture = cli(String.raw`
    $agent=App\Models\Agent::firstOrFail(); $agent->update(['connection_state'=>'connected','credits'=>100000]);
    $world=Tests\Support\FakeSpaceTraders::world();
    $ship=App\Models\Ship::firstOrFail(); $snapshot=$world['ships'][0]; $snapshot['symbol']=$ship->symbol;
    $snapshot['registration']['role']='COMMAND'; $ship->update(['snapshot'=>$snapshot]);
    App\Models\Ship::create(['agent_id'=>$agent->id,'symbol'=>'OFFLINE-2','observed_at'=>now(),'snapshot'=>array_replace($snapshot,['symbol'=>'OFFLINE-2'])]);
    $episode=App\Models\CampaignEpisode::firstOrFail(); $episode->update(['state'=>['role_ships'=>['command'=>$ship->symbol]]]);
    $run=App\Models\AutomationRun::firstOrFail(); $plain=$run->replicate(); $plain->fill(['campaign_episode_id'=>null,'ship_symbol'=>'OFFLINE-2'])->save();
    foreach (['X1-TEST-ROCK','X1-TEST-PORT'] as $yard) {
        $offer=array_replace($world['offer'],['type'=>'SHIP_LIGHT_HAULER','name'=>'Light Hauler']);
        App\Models\GameObservation::create(['agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'kind'=>'shipyard','symbol'=>$yard,'observed_at'=>now(),'data'=>['ships'=>[$offer]]]);
    }
    $action=App\Models\ShipAction::create(['agent_id'=>$agent->id,'reset_id'=>$agent->reset_id,'ship_symbol'=>$ship->symbol,'origin'=>'manual',
        'key'=>'offline-purchase','command'=>'buy_ship','state'=>'preview','arguments'=>['shipType'=>'SHIP_LIGHT_HAULER','waypointSymbol'=>'X1-TEST-ROCK','maxTotal'=>21000,'reserve'=>0],
        'before_state'=>['credits'=>100000,'estimate'=>['purchasePrice'=>20000,'credits_after'=>80000,'protected_in_flight'=>0,'cost_estimate'=>20000,'reserve'=>0,'cargo_free'=>40]],
        'expires_at'=>now()->addHour()]);
    $ordinary=$action->replicate(); $ordinary->fill(['key'=>'offline-cargo','command'=>'buy','arguments'=>['symbol'=>'IRON_ORE','units'=>1,'maxTotal'=>30,'reserve'=>2000]])->save();
    echo json_encode(['agent'=>$agent->id,'action'=>$action->toArray(),'ordinary'=>$ordinary->id]);
`);
const reservation = createServer();
await new Promise(resolve => reservation.listen(0, '127.0.0.1', resolve));
const { port } = reservation.address();
await new Promise(resolve => reservation.close(resolve));
const origin = 'http://127.0.0.1:' + port;
const server = spawn(php, ['-S', '127.0.0.1:' + port, '-t', 'public', router], { cwd: root, env, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
let serverLog = '';
server.stdout.on('data', chunk => { serverLog += chunk; });
server.stderr.on('data', chunk => { serverLog += chunk; });
let browser;
let page;
const errors = [];
const external = [];
try {
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            assert.equal((await fetch(origin + '/campaign')).status, 200);
            break;
        } catch (error) {
            if (attempt === 39 || server.exitCode !== null) throw new Error(String(error) + serverLog.slice(-6000));
            await new Promise(resolve => setTimeout(resolve, 100));
        }
    }
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ viewport: { width: 1280, height: 960 }, reducedMotion: 'reduce' });
    await context.route('**/*', route => {
        if (new URL(route.request().url()).origin === origin) return route.continue();
        external.push(route.request().url());
        return route.abort();
    });
    page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    assert.equal((await page.goto(origin + '/agents/' + fixture.agent + '#commands')).status(), 200);
    const panel = page.getByRole('region', { name: 'Buy ship', exact: true });
    const offer = panel.getByRole('combobox');
    const maximum = panel.getByLabel('Maximum purchase cost in CR', { exact: true });
    await offer.locator('option', { hasText: 'X1-TEST-ROCK' }).waitFor({ state: 'attached' });
    const options = await offer.locator('option').evaluateAll(elements => elements.map(element => ({ value: element.value, text: element.textContent })));
    const local = options.find(option => option.text.includes('X1-TEST-ROCK'));
    const remote = options.find(option => option.text.includes('X1-TEST-PORT'));
    await offer.selectOption(local.value);
    assert.equal(await maximum.inputValue(), '20000');
    await maximum.fill('21000');
    await page.clock.install();
    cli(String.raw`$yard=App\Models\GameObservation::where('kind','shipyard')->where('symbol','X1-TEST-ROCK')->firstOrFail(); $data=$yard->data; $data['ships'][0]['purchasePrice']=20500; array_unshift($data['ships'],array_replace($data['ships'][0],['type'=>'SHIP_PROBE','name'=>'Probe','purchasePrice'=>10000])); $yard->update(['data'=>$data]); echo '{}';`);
    const poll = page.waitForResponse(response => response.url().includes('/agents/' + fixture.agent) && response.request().headers()['x-inertia'] === 'true');
    await page.clock.fastForward(5001);
    assert.equal((await poll).status(), 200);
    await offer.locator('option', { hasText: '20,500' }).waitFor({ state: 'attached' });
    assert.equal(await maximum.inputValue(), '21000', 'Polling must preserve an operator-edited maximum');
    assert.match(await offer.locator('option:checked').textContent(), /Light Hauler.*X1-TEST-ROCK/);
    if (remote) {
        await offer.selectOption(remote.value);
        assert.equal(await panel.getByRole('button', { name: 'Preview ship purchase', exact: true }).isDisabled(), true);
        await panel.getByText(/selected ship must be at X1-TEST-PORT/i).waitFor();
        await offer.selectOption(local.value);
        assert.equal(await maximum.inputValue(), '20500');
    }
    assert.equal(await panel.getByLabel('Protected credits in CR', { exact: true }).inputValue(), '0');
    await panel.screenshot({ path: path.join(evidence, 'sol-r1-buy-ship.png') });
    await page.goto(origin + '/agents/' + fixture.agent + '#fleet');
    const runs = page.getByRole('region', { name: 'All active runs', exact: true });
    assert.equal(await runs.getByText('Campaign', { exact: true }).count(), 1);
    await runs.getByRole('button', { name: /Stop run #.*return ship to campaign director/i }).waitFor();
    await runs.getByText(/campaign will derive a fresh plan/i).waitFor();
    await runs.screenshot({ path: path.join(evidence, 'sol-r1-active-runs.png') });
    await page.goto(origin + '/agents/' + fixture.agent + '#activity');
    await page.getByRole('button', { name: 'Review saved preview #' + fixture.action.id, exact: true }).click();
    const dialog = page.getByRole('dialog');
    await dialog.waitFor();
    const terms = await dialog.locator('dt:visible').allTextContents();
    assert.deepEqual(terms, ['Ship type', 'Shipyard', 'Purchase price', 'Current credits', 'Credits after purchase', 'Explicit reserve', 'Protected in-flight amount']);
    await dialog.getByText('Ship: Light Hauler', { exact: true }).waitFor();
    await dialog.getByText('20,000 CR', { exact: true }).waitFor();
    await dialog.getByText('80,000 CR', { exact: true }).waitFor();
    await dialog.screenshot({ path: path.join(evidence, 'sol-r1-purchase-review.png') });
    await page.goto(origin + '/agents/' + fixture.agent + '#activity');
    await page.getByRole('button', { name: 'Review saved preview #' + fixture.ordinary, exact: true }).click();
    await dialog.getByText('Observed sale', { exact: true }).waitFor();
    await dialog.getByText('Free cargo', { exact: true }).waitFor();
    await page.goto(origin + '/campaign');
    const fleetShip = page.getByRole('listitem').filter({ has: page.getByRole('heading', { name: 'OFFLINE-2', exact: true }) });
    await fleetShip.getByText('Fleet', { exact: true }).waitFor();
    assert.deepEqual(errors, []);
    assert.deepEqual(external, []);
    console.log('PASS fleet offer polling, location guard, purchase review, campaign run labels and pinned status roles. Evidence: ' + evidence);
} catch (error) {
    console.error(JSON.stringify({ errors, body: await page?.locator('body').innerText().catch(() => '') }));
    await page?.screenshot({ path: path.join(evidence, 'sol-r1-failure.png'), fullPage: true });
    throw error;
} finally {
    await browser?.close();
    if (server.exitCode === null) {
        const stopped = once(server, 'exit');
        server.kill();
        await stopped;
    }
    await writeFile(path.join(evidence, 'fleet-server.log'), serverLog);
    assert.equal(path.dirname(directory), path.resolve(tmpdir()));
    assert.ok(path.basename(directory).startsWith('fleet-purchase-browser-'));
    await rm(directory, { recursive: true, force: true });
}
