// Controls only the disposable world created by verify-foundation-preview.mjs.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
const root = process.cwd();
const session = JSON.parse(await fs.readFile(`${root}/storage/app/foundation-preview/session.json`));
const directory = await fs.realpath(session.directory);
const parent = path.dirname(directory);
assert.equal(path.dirname(parent), await fs.realpath(os.tmpdir()));
assert.ok(path.basename(parent).startsWith('spacetraders-verification-'));
assert.ok(path.basename(directory).startsWith('spacetraders-fixture-'));
await fs.access(`${parent}/.verification-owner`);
const php = process.env.PHP_BIN || `${root}/scripts/herd-php`;
const parsed = JSON.parse(execFileSync(php, ['-r', 'require "vendor/autoload.php"; echo json_encode(Dotenv\\Dotenv::parse(file_get_contents($argv[1])));', `${directory}/.env`], { cwd: root, encoding: 'utf8' }));
assert.equal(parsed.DB_DATABASE, `${directory}/database.sqlite`);
assert.equal(parsed.SPACETRADERS_FIXTURE_PATH, `${directory}/world.json`);
const storage = `${parent}/storage`;
const env = { ...process.env, ...parsed, DATABASE_URL: '', APP_URL: session.origin, LARAVEL_STORAGE_PATH: storage,
    APP_CONFIG_CACHE: `${parent}/no-config.php`, APP_SERVICES_CACHE: `${parent}/services.php`, APP_PACKAGES_CACHE: `${parent}/packages.php`,
    APP_ROUTES_CACHE: `${parent}/routes.php`, VIEW_COMPILED_PATH: `${storage}/framework/views`, SPACETRADERS_LOCAL_ENGINE_ENABLED: 'false' };
const cli = code => execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`], { cwd: root, env, encoding: 'utf8' });
const action = process.argv[2];
if (action === 'worker') {
    execFileSync(php, ['artisan', 'queue:work', 'database', '--queue=spacetraders', '--stop-when-empty', '--sleep=0', '--tries=1', '--timeout=120'], { cwd: root, env, stdio: 'pipe', timeout: 20000 });
    console.log(cli(`echo json_encode(App\\Models\\ShipAction::latest('id')->first()->only(['id','command','state','review_required','message']));`));
} else if (action === 'status') {
    console.log(cli(`$w=json_decode(file_get_contents(config('gameplay.fixture_path')),true); echo json_encode(['writes'=>count($w['writes']),'latest'=>App\\Models\\ShipAction::latest('id')->first()?->only(['command','state','arguments','before_state'])]);`));
} else if (action === 'routes') {
    console.log(execFileSync(php, ['artisan', 'route:list', '--json', '-v'], { cwd: root, env, encoding: 'utf8' }));
} else if (action === 'finish') {
    await fs.writeFile(`${directory}/done`, 'shared preview finished');
} else {
    assert.match(action || '', /^[a-z_]+$/);
    // JSON argument enters PHP via argv, never shell interpolation.
    const code = `
    $command=$argv[1]; if (!isset(App\\Gameplay\\ManualCommands::OPERATIONS[$command])) throw new RuntimeException('Unknown command');
    if (App\\Models\\ShipAction::whereIn('state',['sending','uncertain','pending'])->exists()) throw new RuntimeException('Settle prior fixture command first');
    [$w,$a]=Tests\\Support\\FoundationScenario::make($command,$argv[2]??'standard');
    file_put_contents(config('gameplay.fixture_path'),json_encode($w));
    $agent=App\\Models\\Agent::firstOrFail(); app(App\\Gameplay\\AgentSynchronizer::class)->sync($agent);
    App\\Models\\GameObservation::where('agent_id',$agent->id)->where('kind','survey')->where('symbol','browser-signed')->delete();
    app(App\\Gameplay\\SurveyPool::class)->save($agent,['signature'=>'browser-signed','symbol'=>'X1-TEST-ROCK','deposits'=>[['symbol'=>'IRON_ORE']],'expiration'=>now()->addHour()->toIso8601String(),'size'=>'SMALL'],'LOCAL-1');
    echo json_encode(['command'=>$command,'arguments'=>array_intersect_key($a,array_flip(App\\Gameplay\\ManualCommands::OPERATIONS[$command][2]))]);`;
    console.log(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`, action, process.argv[3] || 'standard'], { cwd: root, env, encoding: 'utf8' }));
}
