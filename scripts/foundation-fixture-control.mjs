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
    $w=Tests\\Support\\FakeSpaceTraders::world(); $w['foundation']=true;
    $a=['reserve'=>1000]; $orbit=in_array($command,['mine','mine_survey','survey','siphon','navigate','scan_systems','scan_waypoints','scan_ships','chart','jump','warp']);
    if($orbit) $w['ships'][0]['nav']['status']='IN_ORBIT';
    $w['waypoints'][0]['faction']=['symbol'=>'COSMIC'];
    switch($command) {
      case 'buy_ship': $w['offer']['type']='SHIP_LIGHT_HAULER'; $w['offer']['mounts']=[]; $a+=['shipType'=>'SHIP_LIGHT_HAULER','waypointSymbol'=>'X1-TEST-ROCK','maxTotal'=>20000]; break;
      case 'buy': case 'sell': case 'jettison': case 'transfer': case 'supply':
        $w['ships'][0]['cargo']=['capacity'=>40,'units'=>3,'inventory'=>[['symbol'=>'IRON_ORE','units'=>3]]];
        $a+=['symbol'=>'IRON_ORE','units'=>1,'maxTotal'=>30,'minTotal'=>0,'acknowledgeLoss'=>true,'targetShip'=>'LOCAL-2','waypointSymbol'=>'X1-TEST-ROCK'];
        if($command==='transfer') $w['ships'][]=Tests\\Support\\FakeSpaceTraders::ship('LOCAL-2');
        if($command==='supply') $w['waypoints'][0]['isUnderConstruction']=true; break;
      case 'contract_accept': case 'contract_deliver': case 'contract_fulfill':
        $a+=['contractId'=>'contract-1','symbol'=>'IRON_ORE','units'=>1,'term_index'=>0];
        if($command!=='contract_accept') $w['contracts'][0]['accepted']=true;
        if($command==='contract_deliver') { $w['ships'][0]['nav']['waypointSymbol']='X1-TEST-PORT'; $w['ships'][0]['cargo']=['capacity'=>40,'units'=>10,'inventory'=>[['symbol'=>'IRON_ORE','units'=>10]]]; }
        if($command==='contract_fulfill') $w['contracts'][0]['terms']['deliver'][0]['unitsFulfilled']=10; break;
      case 'negotiate': $w['contracts']=[]; break;
      case 'scrap': $a+=['confirmShip'=>'LOCAL-1','acknowledgeLoss'=>true,'minTotal'=>0]; break;
      case 'repair': $w['ships'][0]['frame']['condition']=0.5; $a+=['maxTotal'=>100]; break;
      case 'install_mount': case 'remove_mount': case 'install_module': case 'remove_module':
        $symbol=str_ends_with($command,'mount')?'MOUNT_MINING_LASER_I':($command==='remove_module'?'MODULE_MINERAL_PROCESSOR_I':'MODULE_GAS_PROCESSOR_I');
        if(str_starts_with($command,'install')) $w['ships'][0]['cargo']=['capacity'=>40,'units'=>1,'inventory'=>[['symbol'=>$symbol,'units'=>1]]];
        $a+=['symbol'=>$symbol,'maxTotal'=>100]; break;
      case 'survey': $w['ships'][0]['mounts'][]=['symbol'=>'MOUNT_SURVEYOR_I','requirements'=>[]]; break;
      case 'mine_survey': case 'mine': $a+=['surveySignature'=>'browser-signed','surveyWaypoint'=>'X1-TEST-ROCK']; break;
      case 'siphon': $w['waypoints'][0]['type']='GAS_GIANT'; $w['ships'][0]['mounts'][]=['symbol'=>'MOUNT_GAS_SIPHON_I','requirements'=>[]]; $w['ships'][0]['modules'][]=['symbol'=>'MODULE_GAS_PROCESSOR_I','requirements'=>[]]; break;
      case 'refine': $w['ships'][0]['modules'][]=['symbol'=>'MODULE_ORE_REFINERY_I','requirements'=>[]]; $w['ships'][0]['cargo']=['capacity'=>200,'units'=>100,'inventory'=>[['symbol'=>'IRON_ORE','units'=>100]]]; $a+=['produce'=>'IRON']; break;
      case 'flight_mode': $a+=['flightMode'=>'BURN']; break;
      case 'navigate': $a+=['waypointSymbol'=>'X1-TEST-PORT','maxFuel'=>5,'maxSeconds'=>60,'acknowledgeUnknown'=>true]; break;
      case 'refuel': $w['ships'][0]['fuel']['current']=0; $w['ships'][0]['cargo']=['capacity'=>40,'units'=>2,'inventory'=>[['symbol'=>'FUEL','units'=>2]]]; $a+=['units'=>37,'fromCargo'=>true,'protectedFuel'=>0,'maxCargoUnits'=>1,'maxTotal'=>0]; break;
      case 'scan_systems': case 'scan_waypoints': case 'scan_ships': $w['ships'][0]['mounts'][]=['symbol'=>'MOUNT_SENSOR_ARRAY_I','requirements'=>[]]; $a+=['maxCooldownSeconds'=>300]; break;
      case 'chart': $w['waypoints'][0]['traits']=[['symbol'=>'UNCHARTED']]; $a+=['waypointSymbol'=>'X1-TEST-ROCK']; break;
      case 'jump': case 'warp':
        $w['waypoints'][0]['type']='JUMP_GATE'; $w['waypoints'][0]['isUnderConstruction']=false;
        $w['waypoints'][]=['symbol'=>'X1-NEXT-GATE','systemSymbol'=>'X1-NEXT','type'=>'JUMP_GATE','x'=>10,'y'=>10,'traits'=>[],'isUnderConstruction'=>false];
        $w['ships'][0]['modules'][]=['symbol'=>$command==='jump'?'MODULE_JUMP_DRIVE_I':'MODULE_WARP_DRIVE_I','requirements'=>[]];
        $a+=['waypointSymbol'=>'X1-NEXT-GATE','maxTotal'=>100,'maxFuel'=>10,'maxSeconds'=>60,'acknowledgeUnknown'=>true]; break;
    }
    file_put_contents(config('gameplay.fixture_path'),json_encode($w));
    $agent=App\\Models\\Agent::firstOrFail(); app(App\\Gameplay\\AgentSynchronizer::class)->sync($agent);
    App\\Models\\GameObservation::where('agent_id',$agent->id)->where('kind','survey')->where('symbol','browser-signed')->delete();
    app(App\\Gameplay\\SurveyPool::class)->save($agent,['signature'=>'browser-signed','symbol'=>'X1-TEST-ROCK','deposits'=>[['symbol'=>'IRON_ORE']],'expiration'=>now()->addHour()->toIso8601String(),'size'=>'SMALL'],'LOCAL-1');
    echo json_encode(['command'=>$command,'arguments'=>array_intersect_key($a,array_flip(App\\Gameplay\\ManualCommands::OPERATIONS[$command][2]))]);`;
    console.log(execFileSync(php, ['-r', `require 'vendor/autoload.php'; $app=require 'bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`, action], { cwd: root, env, encoding: 'utf8' }));
}
