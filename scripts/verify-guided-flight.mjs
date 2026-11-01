import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chooseFlight, flightStep, guidedKey, newFlight, restoreFlight, settleFlight } from '../resources/js/guidedFlight.js';

const world = JSON.parse(execFileSync(process.env.PHP_BIN || 'php', ['-r', 'require "vendor/autoload.php"; require "tests/Support/FakeSpaceTraders.php"; echo json_encode(Tests\\Support\\FakeSpaceTraders::world());'], { encoding: 'utf8' }));
const context = () => ({
    agent: { id: 1, reset_id: world.reset, credits: world.credits },
    ships: world.ships.map(ship => ({ symbol: ship.symbol, snapshot: structuredClone(ship) })),
    capabilities: { 'LOCAL-1': null }, observations: [
        ...world.waypoints.map(data => ({ kind: 'waypoint', symbol: data.symbol, data })),
        ...world.waypoints.map(point => ({ kind: 'market', symbol: point.symbol, data: { symbol: point.symbol, tradeGoods: structuredClone(world.goods) } })),
    ], actions: [], runs: [], selected: 'LOCAL-1', commandPreview: null, offers: [],
    form: { reserve: 0, quantity: 1, priceBound: 0, destination: '', good: '' },
});
const ctx = context();
const plan = chooseFlight(ctx).plan;
assert.deepEqual(plan, { kind: 'mining', ship: 'LOCAL-1', site: 'X1-TEST-ROCK', market: 'X1-TEST-ROCK', reserve: 1000, fuel: 0 });
assert.deepEqual(chooseFlight({ ...ctx, observations: [...ctx.observations].reverse() }).plan, plan);
const session = { ...newFlight(), plan, phase: 'extract' };
assert.equal(flightStep(session, ctx).target, 'reserve');
ctx.form.reserve = plan.reserve;
assert.equal(flightStep(session, ctx).target, 'preview-orbit');
ctx.ships[0].snapshot.nav.status = 'IN_ORBIT';
assert.equal(flightStep(session, ctx).target, 'preview-mine');
ctx.commandPreview = { id: 7, ship_symbol: 'LOCAL-1', command: 'mine', arguments: { reserve: plan.reserve }, expires_at: '2099-01-01' };
assert.equal(flightStep(session, ctx).kind, 'approve');
ctx.commandPreview.command = 'buy';
assert.equal(flightStep(session, ctx).target, 'cancel-preview');
ctx.commandPreview.command = 'mine';
ctx.commandPreview.expires_at = '2000-01-01';
assert.equal(flightStep(session, ctx).target, 'cancel-preview');
ctx.commandPreview = null;
const pending = { ...session, pending: { id: 7, command: 'mine', next: 'inbound' } };
const action = { id: 7, command: 'mine', ship_symbol: 'LOCAL-1', state: 'pending', arguments: {}, outcome: { yield: { symbol: 'IRON_ORE', units: 8 } } };
assert.equal(settleFlight(pending, action), pending, 'Queue submission cannot advance a lesson');
assert.equal(settleFlight(pending, { ...action, id: 8, state: 'confirmed' }), pending, 'Unrelated receipts cannot advance a lesson');
assert.equal(settleFlight(pending, { ...action, state: 'confirmed', review_required: true }), pending);
const settled = settleFlight(pending, { ...action, state: 'confirmed' });
assert.equal(settled.phase, 'inbound');
assert.equal(settled.extracted, 8);
assert.deepEqual(settled.receipts, [7]);
assert.equal(settleFlight(settled, { ...action, state: 'confirmed' }), settled);
assert.ok(settleFlight(pending, { ...action, state: 'confirmed', outcome: { yield: { units: 0 } } }).failure);
assert.ok(settleFlight(pending, { ...action, state: 'rejected' }).failure);
const rejected = settleFlight(pending, { ...action, state: 'rejected' });
assert.equal(rejected.pending, null);
assert.equal(settleFlight(rejected, { ...action, state: 'rejected' }), rejected, 'Failure settlement is idempotent');
assert.ok(settleFlight({ ...pending, pending: { id: 7, command: 'buy_ship', next: 'sync' } }, { ...action, command: 'buy_ship', state: 'confirmed', outcome: { configuration_mismatch: true } }).failure);
const recordedPreview = { ...pending, pending: { ...pending.pending, args: { reserve: 1000 } } };
const previewContext = { ...ctx, actions: [{ ...action, state: 'preview', expires_at: '2099-01-01' }] };
assert.equal(flightStep(recordedPreview, previewContext).kind, 'recover');
previewContext.actions[0].expires_at = '2000-01-01';
assert.equal(flightStep(recordedPreview, previewContext).kind, 'expired');
assert.equal(flightStep(session, { ...ctx, actions: [{ id: 9, state: 'uncertain' }] }).kind, 'blocked');
assert.equal(flightStep(session, { ...ctx, runs: [{ active_agent_id: 1 }] }).target, 'stop');

const saleContext = context();
saleContext.form.reserve = plan.reserve;
saleContext.ships[0].snapshot.cargo = { capacity: 40, units: 8, inventory: [{ symbol: 'IRON_ORE', units: 8 }] };
const sale = { ...session, phase: 'sell', extracted: 8 };
let step = flightStep(sale, saleContext);
assert.deepEqual(step.intent.args, { symbol: 'IRON_ORE', units: 4, minTotal: 100, reserve: 1000 });
assert.equal(step.target, 'good');
saleContext.form.good = 'IRON_ORE';
assert.equal(flightStep(sale, saleContext).target, 'quantity');
saleContext.form.quantity = 4;
assert.equal(flightStep(sale, saleContext).target, 'price');
saleContext.form.priceBound = 100;
assert.equal(flightStep(sale, saleContext).target, 'preview-sell');
saleContext.ships[0].snapshot.cargo.units = 7;
assert.equal(flightStep(sale, saleContext).kind, 'blocked', 'External cargo changes must pause guidance');
saleContext.ships[0].snapshot.cargo.units = 8;
saleContext.ships[0].snapshot.nav.waypointSymbol = 'X1-TEST-PORT';
assert.equal(flightStep(sale, saleContext).kind, 'blocked');
const fuelContext = context();
fuelContext.form.reserve = plan.reserve;
fuelContext.ships[0].snapshot.fuel.current = 50;
assert.deepEqual(flightStep({ ...session, phase: 'refuel' }, fuelContext).intent.args, { units: 50, maxTotal: 5, reserve: 1000 });
const arrival = context();
arrival.ships[0].snapshot.nav.status = 'IN_TRANSIT';
arrival.ships[0].snapshot.nav.route.arrival = '2030-01-01T00:00:00Z';
assert.equal(flightStep({ ...session, phase: 'outbound' }, arrival, Date.parse('2029-01-01')).kind, 'wait');
assert.equal(flightStep({ ...session, phase: 'outbound' }, arrival, Date.parse('2031-01-01')).kind, 'sync');
assert.equal(flightStep({ ...session, phase: 'review' }, context()).kind, 'blocked', 'Reading alone cannot complete the trip');
assert.equal(flightStep({ ...session, phase: 'review', extracted: 8, sold: 8, receipts: [7, 8] }, context()).next, 'complete');

const scout = context();
scout.observations = scout.observations.filter(observation => observation.kind !== 'market');
assert.equal(chooseFlight(scout).plan.kind, 'scout');
assert.ok(chooseFlight(scout, world.waypoints.map(point => point.symbol)).reason, 'Do not repeat fruitless scouting forever');
const noMiner = context();
noMiner.capabilities['LOCAL-1'] = 'Missing laser';
noMiner.guidedOffers = [{ yard: 'X1-TEST-ROCK', configuration: structuredClone(world.offer) }];
noMiner.offers = [{ ...structuredClone(world.offer), yard: 'X1-TEST-ROCK' }];
assert.equal(chooseFlight(noMiner).plan.kind, 'setup');
const setupSession = { ...newFlight(), phase: 'outbound', plan: chooseFlight(noMiner).plan };
assert.equal(flightStep(setupSession, noMiner).kind, 'sync', 'Missing setup quote timestamps require synchronization');
noMiner.observations.forEach(observation => { observation.observed_at = new Date().toISOString(); });
assert.equal(flightStep(setupSession, noMiner).kind, 'field');
const unaffordable = context();
unaffordable.agent.credits = 0;
assert.ok(chooseFlight(unaffordable).reason);
const occupiedMiner = context();
occupiedMiner.ships[0].snapshot.cargo.units = 1;
occupiedMiner.guidedOffers = noMiner.guidedOffers;
assert.ok(chooseFlight(occupiedMiner).reason.includes('already an operational miner'));
const incompleteBuyer = context();
incompleteBuyer.observations.filter(observation => observation.kind === 'market').forEach(observation => { observation.data.tradeGoods = observation.data.tradeGoods.filter(good => good.symbol !== 'COPPER_ORE'); });
assert.ok(chooseFlight(incompleteBuyer).reason, 'Every potential yield needs a known buyer before extraction');
assert.equal(restoreFlight({ phase: 'invented' }), null);
assert.equal(restoreFlight({ ...newFlight(), phase: 'sell' }), null);
assert.deepEqual(restoreFlight(newFlight()), newFlight());
assert.equal(new Set([guidedKey(1, ctx.agent), guidedKey(2, ctx.agent), guidedKey(1, { ...ctx.agent, id: 2 }), guidedKey(1, { ...ctx.agent, reset_id: 'new' })]).size, 4);
console.log('PASS: deterministic planning, exact fields, approval matching, confirmed receipts, batching, refuel units, setup/scouting, blockers and progress isolation.');
