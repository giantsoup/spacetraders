<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { submissionKey } from '@/gameplay.js';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TutorialCourse from '@/Components/TutorialCourse.vue';
import GuidedFlight from '@/Components/GuidedFlight.vue';
import GameConnection from '@/Components/GameConnection.vue';
import { useGuidedFlight } from '@/Composables/useGuidedFlight.js';

const props = defineProps({ agent: Object, ships: Array, capabilities: Object, observations: Array, actions: Array, runs: Array, health: Object, automationEnabled: Boolean, synthetic: Boolean, guidedOffers: { type: Array, default: () => [] } });
const selected = ref(props.ships[0]?.symbol || '');
const page = usePage();
const selectionKey = computed(() => `spacetraders:ship:${page.props.auth.user.id}:${props.agent.id}:${props.agent.reset_id || 'unknown'}`);
function restoreSelection() {
    let saved;
    try { saved = window.sessionStorage.getItem(selectionKey.value); } catch { /* Selection remains usable without browser storage. */ }
    selected.value = props.ships.find(entry => entry.symbol === saved)?.symbol || props.ships.find(entry => entry.symbol === selected.value)?.symbol || props.ships[0]?.symbol || '';
}
onMounted(restoreSelection);
watch([selectionKey, () => props.ships.map(entry => entry.symbol).join(',')], restoreSelection);
watch(selected, value => {
    try { window.sessionStorage.setItem(selectionKey.value, value); } catch { /* No persistence when storage is disabled. */ }
});
const ship = computed(() => props.ships.find(s => s.symbol === selected.value)?.snapshot);
const waypoints = computed(() => props.observations.filter(o => o.kind === 'waypoint' && o.data.systemSymbol === ship.value?.nav.systemSymbol));
const markets = computed(() => props.observations.filter(o => o.kind === 'market'));
const market = computed(() => markets.value.find(o => o.symbol === ship.value?.nav.waypointSymbol));
const yards = computed(() => props.observations.filter(o => o.kind === 'shipyard'));
const offers = computed(() => yards.value.flatMap(y => (y.data.ships || []).map(o => ({ ...o, yard: y.symbol }))));
const activeRun = computed(() => props.runs.find(r => r.active_agent_id));
const latestRun = computed(() => activeRun.value || props.runs.find(r => r.state !== 'preview'));
const pending = computed(() => props.actions.find(a => ['pending', 'sending', 'uncertain'].includes(a.state) || a.review_required));
const controlled = computed(() => !!activeRun.value && activeRun.value.ship_symbol === selected.value);
const destination = ref('');
const good = ref('');
const quantity = ref(1);
const priceBound = ref(0);
const reserve = ref(0);
const offerIndex = ref('');
const site = ref('');
const buyer = ref('');
const spend = ref(0);
const loss = ref(0);
const cycles = ref(1);
const minutes = ref(60);
const busy = ref(false);
const notice = ref('');
const error = ref(false);
const commandPreview = ref(null);
const runPreview = ref(null);
const previewPanel = ref(null);
const note = ref('');
const reconciliation = ref(null);
const selectedQuote = computed(() => market.value?.data.tradeGoods?.find(q => q.symbol === good.value));
const selectedWaypoint = computed(() => waypoints.value.find(w => w.symbol === destination.value));
const navigationHelp = computed(() => {
    if (!destination.value) return 'Select a destination before previewing navigation.';
    if (!selectedWaypoint.value || destination.value === ship.value?.nav.waypointSymbol) return 'Choose a different waypoint in this system.';
    if (ship.value?.nav.status === 'DOCKED') return 'Enter orbit first: preview orbit and approve that command.';
    if (ship.value?.nav.status === 'IN_TRANSIT') return 'Wait for arrival, then synchronize before navigating again.';
    return '';
});
const cash = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString();
const age = value => value ? `${Math.max(0, Math.floor((Date.now() - Date.parse(value)) / 1000))}s ago` : 'Never observed';
const healthy = value => value && Date.now() - Date.parse(value) < 90000;
const url = suffix => `/agents/${props.agent.id}/${suffix}`;
const reload = () => new Promise(resolve => router.reload({ only: ['agent', 'ships', 'capabilities', 'observations', 'actions', 'runs', 'health', 'guidedOffers'], onFinish: resolve }));
usePoll(5000, { only: ['agent', 'ships', 'capabilities', 'observations', 'actions', 'runs', 'health', 'guidedOffers'] });
const guided = useGuidedFlight(() => page.props.auth.user.id, () => ({
    ...props, selected: selected.value, offers: offers.value, commandPreview: commandPreview.value,
    form: { destination: destination.value, good: good.value, quantity: quantity.value, priceBound: priceBound.value, reserve: reserve.value, site: site.value, buyer: buyer.value, offerIndex: offerIndex.value },
}));
const { session: guidedSession, step: guidedStep, active: guiding, storageAvailable: guidedStorage } = guided;
const guideAllows = command => !guiding.value || (guidedStep.value?.kind === 'preview' && guidedStep.value.intent.command === command);
async function synchronize() {
    if (await post('sync')) guided.synchronized();
}

async function post(path, payload = {}, allowBusy = false) {
    if (busy.value && !allowBusy) return null;
    if (!allowBusy) busy.value = true;
    error.value = false;
    notice.value = '';
    try {
        const result = await window.axios.post(url(path), payload, { headers: { Accept: 'application/json' } });
        notice.value = result.data.message || 'Intent saved. Follow its status in Activity history.';
        await reload();
        return result.data;
    } catch (failure) {
        error.value = true;
        const validation = failure.response?.data?.errors;
        notice.value = validation ? Object.values(validation).flat().join(' ') : failure.response?.data?.message || 'Local request failed. Refresh local state before retrying.';
        await reload();
        return null;
    } finally {
        if (!allowBusy) busy.value = false;
    }
}
async function preview(command) {
    if (command === 'navigate' && navigationHelp.value) {
        error.value = true;
        notice.value = navigationHelp.value;
        return;
    }
    const args = {};
    if (command === 'navigate') args.waypointSymbol = destination.value;
    if (['buy', 'sell', 'refuel'].includes(command)) args.units = Number(quantity.value);
    if (['buy', 'sell'].includes(command)) args.symbol = good.value;
    if (['buy', 'refuel', 'buy_ship'].includes(command)) args.maxTotal = Number(priceBound.value);
    if (command === 'sell') args.minTotal = Number(priceBound.value);
    args.reserve = Number(reserve.value);
    if (command === 'buy_ship') {
        const offer = offers.value[Number(offerIndex.value)];
        if (!offer) { error.value = true; notice.value = 'Select a complete shipyard offer.'; return; }
        Object.assign(args, { shipType: offer.type, waypointSymbol: offer.yard, site: site.value, market: buyer.value });
    }
    commandPreview.value = await post('commands/preview', { key: submissionKey(), ship_symbol: selected.value, command, arguments: args });
    if (commandPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function approveCommand() {
    if (!commandPreview.value) return;
    const intent = guiding.value && guidedStep.value?.kind === 'approve' ? guidedStep.value.intent : null;
    guided.trackApproval(commandPreview.value, intent);
    const action = await post(`commands/${commandPreview.value.id}/approve`);
    if (action) commandPreview.value = null;
}
async function previewRun() {
    runPreview.value = await post('runs/preview', { ship_symbol: selected.value, site: site.value, market: buyer.value, reserve: Number(reserve.value), max_spend: Number(spend.value), max_loss: Number(loss.value), cycles: Number(cycles.value), minutes: Number(minutes.value) });
    if (runPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function startRun() {
    if (runPreview.value && await post(`runs/${runPreview.value.id}/start`)) runPreview.value = null;
}
async function reconcile(action) {
    reconciliation.value = await post(`commands/${action.id}/reconcile`);
}
function distance(point) {
    const origin = waypoints.value.find(p => p.symbol === ship.value?.nav.waypointSymbol)?.data;
    return origin ? Math.hypot(point.x - origin.x, point.y - origin.y).toFixed(1) : '?';
}
</script>

<template>
    <Head :title="`${agent.name} · Flight deck`" />
    <div class="bridge" :class="{ guiding }">
        <a href="#manual" class="skip">Skip to command controls</a>
        <header class="topbar">
            <div><Link :href="route('agents.index')" class="brand">ST // FLIGHT DECK</Link><span class="muted"> LOCAL COMMAND TERMINAL</span></div>
            <nav aria-label="Account"><Link :href="route('agents.index')">Agents</Link><Link :href="route('agents.guide', agent.id)">Player guide</Link><Link :href="route('profile.edit')">Profile</Link><Link :href="route('logout')" method="post" as="button">Log out</Link></nav>
        </header>
        <main>
            <GameConnection />
            <p v-if="synthetic" class="notice warning" role="status">OFFLINE FIXTURE · Commands use synthetic SpaceTraders responses. No live gameplay.</p>
            <section class="agent-heading" aria-labelledby="agent-heading">
                <div><p class="eyebrow">AGENT ONLINE RECORD</p><h1 id="agent-heading">{{ agent.name }}</h1><p class="muted">Reset {{ agent.reset_id || 'unknown' }} · {{ agent.connection_state }} · Snapshot {{ age(agent.synced_at) }}</p></div>
                <div class="balance"><span class="muted">AVAILABLE CREDITS</span><strong>{{ cash(agent.credits) }} <small>CR</small></strong></div>
                <SecondaryButton data-guide="sync" :disabled="busy || (guiding && guidedStep?.kind !== 'sync' && !guidedStep?.canSync && !(guidedStep?.kind === 'blocked' && guidedStep?.target === 'sync'))" @click="synchronize">Synchronize</SecondaryButton>
            </section>

            <div class="control-strip" aria-live="polite">
                <div><strong>{{ ship?.symbol || 'No ship selected' }}</strong> · {{ ship?.nav.status || 'Synchronize to discover fleet' }} <span v-if="controlled">· AUTOMATION OWNS SHIP</span><span v-if="pending"> · {{ pending.command }} {{ pending.review_required ? 'review required' : pending.state }}</span></div>
                <DangerButton v-if="activeRun" data-guide="stop" @click="post(`runs/${activeRun.id}/stop`, {}, true)">Stop automation</DangerButton>
                <span v-else class="lamp">Manual control</span>
            </div>
            <p v-if="notice" :role="error ? 'alert' : 'status'" class="notice" :class="{ fault: error }">{{ notice }}</p>
            <p class="health">Worker: {{ healthy(health.worker) ? 'recent step' : 'idle or offline' }} · last {{ age(health.worker) }}. Scheduler: {{ healthy(health.scheduler) ? 'recent pass' : 'stale or offline' }} · last {{ age(health.scheduler) }}. Closing this page does not stop the worker. Mac sleep pauses execution.</p>

            <GuidedFlight :session="guidedSession" :step="guidedStep" :storage-available="guidedStorage" :busy="busy" @start="guided.start" @pause="guided.pause" @resume="guided.resume" @advance="guided.advance" @sync="synchronize" @recover="commandPreview = actions.find(action => action.id === guidedSession.pending.id && action.state === 'preview')" @clear-expired="guided.clearExpired(); commandPreview = null" />
            <TutorialCourse v-if="!guiding" :agent="agent" compact />

            <div class="deck-grid">
                <aside id="fleet" data-guide="fleet" class="panel fleet" aria-labelledby="fleet-heading">
                    <h2 id="fleet-heading">01 / Fleet</h2>
                    <p v-if="ships.length === 0">No saved fleet. Synchronize to discover ships and local waypoints.</p>
                    <button v-for="entry in ships" :key="entry.symbol" :data-guide="`ship:${entry.symbol}`" :disabled="guiding && entry.symbol !== guidedSession?.plan?.ship" class="ship-choice" :class="{ selected: selected === entry.symbol }" :aria-pressed="selected === entry.symbol" @click="selected = entry.symbol; destination = ''; commandPreview = null; runPreview = null">
                        <strong>{{ entry.symbol }}</strong><span>{{ entry.snapshot.registration?.role || 'SHIP' }}</span><span>{{ entry.snapshot.nav.waypointSymbol }}</span><span>{{ entry.snapshot.nav.status }} · Cargo {{ entry.snapshot.cargo.units }}/{{ entry.snapshot.cargo.capacity }}</span>
                    </button>
                    <template v-if="ship">
                        <h3>Selected ship</h3>
                        <dl><dt>Fuel</dt><dd>{{ ship.fuel.current }} / {{ ship.fuel.capacity }}</dd><dt>Cargo</dt><dd>{{ ship.cargo.units }} / {{ ship.cargo.capacity }}</dd><dt>Crew</dt><dd>{{ ship.crew.current }} / {{ ship.crew.required }} required</dd><dt>Flight mode</dt><dd>{{ ship.nav.flightMode }}</dd><dt>Arrival</dt><dd>{{ ship.nav.status === 'IN_TRANSIT' ? ship.nav.route.arrival : 'Stationary' }}</dd><dt>Cooldown</dt><dd>{{ Date.parse(ship.cooldown.expiration) > Date.now() ? ship.cooldown.expiration : 'Ready' }}</dd></dl>
                        <p :class="capabilities[selected] ? 'warning' : 'good'">{{ capabilities[selected] || 'Mining equipment operational' }}</p>
                        <h3>Installed equipment</h3><ul class="compact"><li v-for="part in [...ship.mounts, ...ship.modules]" :key="part.symbol">{{ part.symbol }}</li></ul>
                        <h3>Condition / integrity</h3><p v-for="part in ['frame', 'reactor', 'engine']" :key="part">{{ part }} {{ Math.round(ship[part].condition * 100) }}% / {{ Math.round(ship[part].integrity * 100) }}%</p>
                    </template>
                </aside>

                <div class="work-panels">
                    <section v-if="commandPreview || runPreview" ref="previewPanel" tabindex="-1" class="panel approval" aria-labelledby="approval-heading">
                        <h2 id="approval-heading">Review {{ commandPreview?.command === 'buy_ship' ? 'one-time ship purchase' : commandPreview ? 'manual command' : 'mining trip' }}</h2>
                        <template v-if="commandPreview">
                            <p>{{ commandPreview.ship_symbol }} · {{ commandPreview.command }} · Expires {{ commandPreview.expires_at }}</p>
                            <pre>{{ JSON.stringify(commandPreview.arguments, null, 2) }}</pre>
                            <dl><dt>Observed cost</dt><dd>{{ cash(commandPreview.before_state.estimate.cost_estimate) }} CR</dd><dt>Observed sale</dt><dd>{{ cash(commandPreview.before_state.estimate.sale_estimate) }} CR</dd><dt>Protected reserve</dt><dd>{{ cash(commandPreview.before_state.estimate.reserve) }} CR</dd><dt>Available credits</dt><dd>{{ cash(commandPreview.before_state.credits) }} CR</dd><dt>Free cargo</dt><dd>{{ commandPreview.before_state.estimate.cargo_free }}</dd></dl>
                            <p v-if="commandPreview.before_state.estimate.fuel_estimate">Conservative fuel estimate: {{ commandPreview.before_state.estimate.fuel_estimate }}. Arrival comes from the navigation response.</p>
                            <details v-if="commandPreview.before_state.estimate.configuration"><summary>Complete offered configuration and route</summary><pre>{{ JSON.stringify(commandPreview.before_state.estimate, null, 2) }}</pre></details>
                            <p class="warning">{{ commandPreview.before_state.estimate.quote_limit_warning }} Scouting movement also requires this explicit approval.</p>
                            <PrimaryButton data-guide="approve" :disabled="busy || (guiding && guidedStep?.kind !== 'approve')" @click="approveCommand">Approve {{ commandPreview.command === 'buy_ship' ? 'one-time purchase' : 'command' }}</PrimaryButton>
                            <SecondaryButton data-guide="cancel-preview" @click="commandPreview = null">Cancel preview</SecondaryButton>
                        </template>
                        <template v-else>
                            <p>{{ runPreview.ship_symbol }} · {{ runPreview.plan.route.site }} → {{ runPreview.plan.route.market }}</p>
                            <dl><dt>Trips / duration cap</dt><dd>{{ runPreview.plan.cycles }} / {{ runPreview.plan.minutes }} min</dd><dt>Credit reserve</dt><dd>{{ cash(runPreview.plan.reserve) }}</dd><dt>Maximum fuel spend</dt><dd>{{ cash(runPreview.plan.max_spend) }}</dd><dt>Maximum loss</dt><dd>{{ cash(runPreview.plan.max_loss) }}</dd><dt>Fuel estimate / refuel ceiling</dt><dd>{{ runPreview.plan.route.fuel_estimate }} tank units / {{ cash(runPreview.plan.route.fuel_ceiling) }} CR</dd><dt>Starting fuel</dt><dd>{{ runPreview.plan.initial_fuel }}</dd><dt>Quote timestamp</dt><dd>{{ runPreview.plan.route.quote_at }}</dd><dt>Known buyers for</dt><dd>{{ runPreview.plan.route.known_buyers.join(', ') }}</dd><dt>Unknown yields</dt><dd>{{ runPreview.plan.route.unknown_yields.join(', ') || 'None observed' }}</dd></dl>
                            <p class="warning">One extraction per trip. Unknown cargo stays aboard and blocks the run. No guaranteed execution price or profitability. Stop on reserve, spending, loss, duration, condition, reset, uncertain write or no progress. The first trip stops unless you explicitly preview more trips.</p>
                            <PrimaryButton :disabled="busy || !automationEnabled || guiding" @click="startRun">Start approved mining run</PrimaryButton><SecondaryButton @click="runPreview = null">Cancel preview</SecondaryButton>
                        </template>
                    </section>
                    <section id="manual" class="panel" aria-labelledby="manual-heading">
                        <h2 id="manual-heading">02 / Manual commands</h2>
                        <p v-if="controlled || pending" class="warning">{{ controlled ? 'Stop automation, then wait for the reserved action to settle before takeover.' : pending?.review_required ? 'A confirmed transaction needs price review before another command.' : 'An action is pending or uncertain. Settle it before another command.' }}</p>
                        <fieldset :disabled="busy || !ship || controlled || !!pending">
                            <legend class="sr-only">Ship commands</legend>
                            <div class="button-row"><SecondaryButton :disabled="!guideAllows('orbit')" data-guide="preview-orbit" @click="preview('orbit')">Preview orbit</SecondaryButton><SecondaryButton :disabled="!guideAllows('dock')" data-guide="preview-dock" @click="preview('dock')">Preview docking</SecondaryButton><SecondaryButton :disabled="!guideAllows('mine')" data-guide="preview-mine" @click="preview('mine')">Preview mining</SecondaryButton></div>
                            <div class="form-grid">
                                <label>Destination<select v-model="destination" data-guide="destination" :disabled="guiding && guidedStep?.target !== 'destination'" aria-label="Destination" aria-describedby="navigation-help"><option value="">Select waypoint</option><option v-for="point in waypoints" :key="point.id" :value="point.symbol" :disabled="point.symbol === ship?.nav.waypointSymbol">{{ point.symbol }} · {{ point.data.type }}{{ point.symbol === ship?.nav.waypointSymbol ? ' · current location' : '' }}</option></select></label>
                                <div class="field-action"><p id="navigation-help" class="muted" aria-live="polite">{{ navigationHelp || `${distance(selectedWaypoint.data)} units away. The server checks fuel and route before approval.` }}</p><SecondaryButton :disabled="!!navigationHelp || !guideAllows('navigate')" aria-describedby="navigation-help" data-guide="preview-navigate" @click="preview('navigate')">Preview navigation</SecondaryButton></div>
                            </div>
                            <div class="form-grid"><label>Trade good<select v-model="good" data-guide="good" :disabled="guiding && guidedStep?.target !== 'good'" aria-label="Trade good"><option value="">Select good</option><option v-for="quote in market?.data.tradeGoods || []" :key="quote.symbol" :value="quote.symbol">{{ quote.symbol }}</option></select></label><label>Quantity / refuel tank units<input v-model="quantity" data-guide="quantity" :disabled="guiding && guidedStep?.target !== 'quantity'" type="number" min="1" max="100000" step="1"></label><label>Maximum cost / minimum sale total<input v-model="priceBound" data-guide="price" :disabled="guiding && guidedStep?.target !== 'price'" type="number" min="0" step="1"></label><label>Protected credits<input v-model="reserve" data-guide="reserve" :disabled="guiding && guidedStep?.target !== 'reserve'" type="number" min="0" step="1"></label></div>
                            <p v-if="selectedQuote" class="muted">Observed buy {{ selectedQuote.purchasePrice }} · sell {{ selectedQuote.sellPrice }} · transaction volume {{ selectedQuote.tradeVolume }}. Quote {{ age(market.observed_at) }}. Refuel: 100 tank units per market unit, rounded up.</p>
                            <div class="button-row"><SecondaryButton :disabled="!guideAllows('buy')" data-guide="preview-buy" @click="preview('buy')">Preview cargo purchase</SecondaryButton><SecondaryButton :disabled="!guideAllows('sell')" data-guide="preview-sell" @click="preview('sell')">Preview cargo sale</SecondaryButton><SecondaryButton :disabled="!guideAllows('refuel')" data-guide="preview-refuel" @click="preview('refuel')">Preview refueling</SecondaryButton></div>
                        </fieldset>
                        <p class="muted">Every command is checked again by the server. Transit, cargo, cooldown, ownership and spending failures appear before send where known.</p>
                    </section>

                    <section id="automation" class="panel" aria-labelledby="automation-heading">
                        <h2 id="automation-heading">03 / Mining automation</h2>
                        <p v-if="!automationEnabled" class="warning">Starts are disabled in local configuration. Preview remains available.</p>
                        <p v-if="latestRun">Run #{{ latestRun.id }} · {{ latestRun.state }} · Next due {{ latestRun.next_action_at || 'none' }} · Last step {{ age(latestRun.heartbeat_at) }}</p>
                        <p v-if="latestRun?.blocked_reason" class="warning">{{ latestRun.blocked_reason }}</p>
                        <fieldset :disabled="busy || !ship || !!activeRun || !!pending"><legend>Bounded trip limits</legend>
                            <div class="form-grid"><label>Mining site<select v-model="site" data-guide="site" :disabled="guiding && guidedStep?.target !== 'site'" aria-label="Mining site"><option value="">Select site</option><option v-for="point in waypoints.filter(p => ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'].includes(p.data.type))" :key="point.id" :value="point.symbol">{{ point.symbol }}</option></select></label><label>Sale and refueling market<select v-model="buyer" data-guide="buyer" :disabled="guiding && guidedStep?.target !== 'buyer'" aria-label="Sale and refueling market"><option value="">Select buyer</option><option v-for="point in markets" :key="point.id" :value="point.symbol">{{ point.symbol }} · {{ point.data.tradeGoods ? 'observed quotes' : 'prices unknown' }}</option></select></label><label>Protected credit reserve<input v-model="reserve" data-guide="reserve" :disabled="guiding && guidedStep?.target !== 'reserve'" type="number" min="1" step="1"></label><label>Maximum fuel spending<input v-model="spend" type="number" min="1" step="1"></label><label>Maximum credit loss<input v-model="loss" type="number" min="0" step="1"></label><label>Trip count<input v-model="cycles" type="number" min="1" max="10" step="1"></label><label>Duration cap in minutes<input v-model="minutes" type="number" min="1" max="60" step="1"></label></div>
                            <PrimaryButton :disabled="guiding" @click="previewRun">Preview mining run</PrimaryButton>
                        </fieldset>
                        <p class="muted">Enter explicit credit bounds. Preview computes the minimum reserve from observed fuel quotes and current credits. Unknown prices require an approved scouting navigation and synchronization. One extraction uses the empty cargo hold; the bot never buys cargo or jettisons goods.</p>
                        <div v-if="latestRun" class="outcomes"><h3>Recorded outcomes</h3><dl><dt>Completed trips</dt><dd>{{ latestRun.totals.cycles }}</dd><dt>Run credit contribution</dt><dd>{{ cash(latestRun.totals.credit_delta) }} CR</dd><dt>Agent balance change</dt><dd>{{ cash(latestRun.totals.agent_balance_delta ?? latestRun.totals.credit_delta) }} CR</dd><dt>Other local commands</dt><dd>{{ cash(latestRun.totals.other_credit_delta ?? 0) }} CR</dd><dt>Sale proceeds</dt><dd>{{ cash(latestRun.totals.sale_proceeds) }} CR</dd><dt>Fuel purchased</dt><dd>{{ latestRun.totals.fuel_units }} tank units / {{ cash(latestRun.totals.fuel_cost) }} CR</dd><dt>Trip cash contribution</dt><dd>{{ cash(latestRun.totals.cash_contribution) }} CR</dd><dt>Starting / ending fuel</dt><dd>{{ latestRun.plan.initial_fuel }} / {{ latestRun.totals.ending_fuel ?? 'pending' }}</dd><dt>Elapsed time</dt><dd>{{ latestRun.totals.elapsed_seconds ?? 'pending' }} seconds</dd></dl><p>Extracted {{ JSON.stringify(latestRun.totals.extracted) }} · Sold {{ JSON.stringify(latestRun.totals.sold) }} · Unsold {{ JSON.stringify(latestRun.totals.unsold || {}) }}</p><p class="muted">Cash contribution is proceeds minus purchased fuel. Existing fuel and retained inventory prevent treating it as economic profit.</p></div>
                    </section>

                    <section data-guide="setup" id="setup" class="panel" aria-labelledby="setup-heading">
                        <h2 id="setup-heading">04 / One-time mining setup</h2>
                        <p v-if="Object.values(capabilities).includes(null)" class="good">An operational miner is already available. Setup purchase is skipped.</p>
                        <template v-else><p>Buy a complete verified miner only. Select a site and buyer above. Approve scouting navigation to the shipyard first. Outfitting is a separate follow-up when no affordable complete miner exists.</p><label>Observed shipyard offer<select v-model="offerIndex" data-guide="offer" :disabled="guiding && guidedStep?.target !== 'offer'" aria-label="Observed shipyard offer"><option value="">Select offer</option><option v-for="(offer, index) in offers" :key="`${offer.yard}-${offer.type}`" :value="String(index)">{{ offer.type }} · {{ offer.yard }} · {{ cash(offer.purchasePrice) }} CR</option></select></label><p>Use the maximum-cost and protected-credit fields in Manual commands. Approval is separate from starting automation.</p><SecondaryButton :disabled="busy || !ship || !!pending || !!activeRun || !guideAllows('buy_ship')" data-guide="preview-buy_ship" @click="preview('buy_ship')">Preview one-time miner purchase</SecondaryButton></template>
                    </section>
                    <section id="waypoints" class="panel" aria-labelledby="map-heading"><h2 id="map-heading">05 / Local waypoints</h2><div class="table-scroll"><table><caption class="sr-only">Known same-system waypoints</caption><thead><tr><th>Waypoint</th><th>Type / traits</th><th>Coordinates</th><th>Distance</th></tr></thead><tbody><tr v-for="point in waypoints" :key="point.id"><td><button class="text-button" @click="destination = point.symbol">{{ point.symbol }}</button></td><td>{{ point.data.type }}<br><span class="muted">{{ point.data.traits.map(t => t.symbol).join(', ') }}</span></td><td>{{ point.data.x }}, {{ point.data.y }}</td><td>{{ distance(point.data) }}</td></tr></tbody></table></div></section>
                    <section data-guide="market" id="market" class="panel" aria-labelledby="market-heading"><h2 id="market-heading">06 / Cargo and market</h2><p>Market {{ ship?.nav.waypointSymbol || 'unknown' }} · Quote {{ age(market?.observed_at) }}. Missing prices are unknown.</p><div class="table-scroll"><table><caption class="sr-only">Cargo and local market prices</caption><thead><tr><th>Good</th><th>Aboard</th><th>Buy</th><th>Sell</th><th>Volume</th></tr></thead><tbody><tr v-for="quote in market?.data.tradeGoods || []" :key="quote.symbol"><td>{{ quote.symbol }}</td><td>{{ ship?.cargo.inventory.find(i => i.symbol === quote.symbol)?.units || 0 }}</td><td>{{ cash(quote.purchasePrice) }}</td><td>{{ cash(quote.sellPrice) }}</td><td>{{ quote.tradeVolume }}</td></tr><tr v-for="item in ship?.cargo.inventory.filter(i => !market?.data.tradeGoods?.some(g => g.symbol === i.symbol)) || []" :key="item.symbol"><td>{{ item.symbol }}</td><td>{{ item.units }}</td><td colspan="3">Unknown</td></tr></tbody></table></div></section>
                    <section data-guide="activity" id="activity" class="panel" aria-labelledby="activity-heading"><h2 id="activity-heading">07 / Activity history</h2><p v-if="!actions.length">No commands recorded. Preview a command to begin.</p><ol class="activity"><li v-for="action in actions" :key="action.id"><div><strong>#{{ action.id }} {{ action.command }}</strong> <span>{{ action.state }}{{ action.review_required ? ' · Review required' : '' }} · {{ action.origin }} · {{ action.ship_symbol }}</span></div><p>{{ action.message || 'Awaiting approval' }} <span v-if="action.api_code">API {{ action.api_code }}</span><span v-if="action.outcome?.credit_delta !== undefined"> · {{ cash(action.outcome.credit_delta) }} CR</span></p><small class="muted">{{ action.created_at }}</small><div v-if="action.state === 'uncertain' || action.review_required"><SecondaryButton :disabled="busy" @click="reconcile(action)">Reconcile #{{ action.id }}</SecondaryButton><details v-if="action.outcome"><summary>Inspect reconciliation evidence</summary><pre>{{ JSON.stringify(action.outcome, null, 2) }}</pre></details><div v-if="action.outcome?.reconciled_at"><label>Operator reconciliation note<input v-model="note" minlength="10" maxlength="500"></label><DangerButton :disabled="busy || note.length < 10" @click="post(`commands/${action.id}/acknowledge`, { acknowledge: true, note })">Acknowledge reviewed outcome and end run</DangerButton><p class="warning">Confirmed transactions keep their result. Unproven outcomes stay labeled as unresolved adjustments. Neither is replayed; review releases manual control.</p></div></div></li></ol><pre v-if="reconciliation" class="sr-only">{{ reconciliation.message }}</pre></section>
                </div>
            </div>
            <footer>LOCAL MVP · Game state is controlled by Laravel · No automatic registration or restart</footer>
        </main>
    </div>
</template>

<style scoped>
.bridge{color-scheme:dark;background:#0a111c;color:#e4edf7;min-height:100vh;font:15px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;overflow-wrap:anywhere}.bridge *{box-sizing:border-box}.bridge main{max-width:1480px;margin:auto;padding:24px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:20px 28px;border-bottom:2px solid #34455d;background:#101b2b}.topbar nav{display:flex;flex-wrap:wrap;max-width:100%;gap:20px}.brand{color:#a6f3d0;font-weight:800;letter-spacing:.08em}.muted,.health{color:#aebed2}.eyebrow{font-size:12px;letter-spacing:.12em;color:#a6f3d0}.agent-heading{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:20px;margin-bottom:22px}.bridge h1{font-size:32px;font-weight:800;letter-spacing:.06em;line-height:1.3}.bridge h2{font-size:17px;letter-spacing:.06em;font-weight:800;color:#a6f3d0;margin-bottom:18px;border-bottom:1px solid #34455d;padding-bottom:12px}.bridge h3{font-weight:700;color:#cfdbeb;margin:20px 0 8px}.balance{display:grid;gap:5px}.balance strong{font-size:28px;color:#fce4a6}.balance small{font-size:14px}.control-strip{position:sticky;top:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:15px;padding:14px 18px;background:#18283b;border:2px solid #58708b;box-shadow:4px 4px 0 #05090f;margin-bottom:18px}.lamp,.good{color:#a6f3d0}.health{font-size:13px;margin-bottom:20px}.deck-grid{display:grid;grid-template-columns:280px minmax(0,1fr);gap:22px}.panel{border:2px solid #34455d;background:#101b2b;padding:22px;box-shadow:5px 5px 0 #05090f;min-width:0}.work-panels{display:grid;gap:22px;min-width:0}.fleet{align-self:start}.ship-choice{display:grid;text-align:left;width:100%;padding:13px;margin-bottom:12px;background:#142338;border:2px solid #34455d;color:#e4edf7;gap:4px}.ship-choice span{font-size:12px;color:#b5c6dc}.ship-choice.selected{border-color:#9ae6c6;background:#1c3840}.bridge button{font-family:inherit}.bridge button:focus-visible,.bridge a:focus-visible,.bridge input:focus-visible,.bridge select:focus-visible,.bridge summary:focus-visible{outline:3px solid #f7dc91;outline-offset:4px}.bridge :deep(button){border-radius:0;min-height:44px;text-transform:none;letter-spacing:0;padding:10px 14px;font-size:13px;border:1px solid #7593ac;color:#f0f5ff;background:#243c54;white-space:normal}.bridge :deep(button:hover:not(:disabled)){background:#365574}.bridge :deep(button:disabled){opacity:.5;cursor:not-allowed}.bridge :deep(button.bg-red-600){background:#8e263b;border-color:#ffb4bf;color:#fff}.bridge :deep(button.bg-red-600:hover){background:#aa3049}.bridge label{display:grid;gap:7px;font-size:13px;color:#c9d7e8}.bridge input,.bridge select{display:block;border:1px solid #67829f;background:#0b1523;color:#f0f5ff;padding:10px;width:100%;min-height:44px;border-radius:0;font:inherit}.form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin:20px 0}.field-action{align-self:end}.button-row{display:flex;flex-wrap:wrap;gap:10px;margin:14px 0}.bridge fieldset{min-width:0}.bridge legend{font-size:13px;color:#aebed2}.bridge fieldset:disabled{opacity:.65}.bridge p{margin:8px 0}.bridge dl{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:6px 16px;font-size:13px}.bridge dt{color:#aebed2}.bridge dd{text-align:right;overflow-wrap:anywhere}.compact{font-size:12px;list-style:square;padding-left:18px}.notice{padding:16px;border:2px solid #91dfc0;background:#123429}.notice.fault{background:#421f2b;border-color:#f1a4b4;color:#ffdde4}.warning{color:#f8d99b}.approval{scroll-margin-top:110px;border-color:#d3b77c;background:#202635}.bridge pre{font:12px/1.6 ui-monospace,monospace;white-space:pre-wrap;overflow-wrap:anywhere;max-height:320px;overflow:auto;padding:12px;background:#08111d}.bridge details{margin:12px 0}.bridge summary{cursor:pointer;color:#a6f3d0;min-height:36px}.table-scroll{overflow-x:auto}.bridge table{width:100%;border-collapse:collapse;font-size:13px}.bridge th{text-align:left;color:#adc7dc;background:#142338}.bridge th,.bridge td{padding:12px;border-bottom:1px solid #34455d;vertical-align:top}.bridge .text-button{background:transparent;border:0;color:#a6f3d0;text-decoration:underline;padding:0}.activity{list-style:none;display:grid;gap:16px}.activity li{border-bottom:1px solid #34455d;padding-bottom:16px}.activity li>div:first-child{display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between}.activity span{color:#b6c8dc;font-size:13px}.activity p{font-size:13px}.outcomes{border-top:1px solid #34455d;margin-top:18px}.bridge section[id],.bridge aside[id]{scroll-margin-top:110px}.bridge footer{color:#aebed2;font-size:12px;padding:30px 0}.skip{position:fixed;top:-100px;left:15px;z-index:99;background:#e4edf7;color:#0a111c;padding:10px}.skip:focus{top:10px}@media(max-width:900px){.deck-grid{grid-template-columns:220px minmax(0,1fr)}.panel{padding:16px}.form-grid{grid-template-columns:1fr}}@media(max-width:680px){.bridge main{padding:14px}.topbar{padding:16px;align-items:start;flex-direction:column}.topbar .muted{display:none}.deck-grid{grid-template-columns:minmax(0,1fr)}.fleet{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.fleet>h2,.fleet>h3,.fleet>dl,.fleet>p,.fleet>ul{grid-column:1/-1}.control-strip{font-size:12px;padding:10px;align-items:start}.control-strip>div{max-width:60%}.agent-heading{gap:15px}.balance strong{font-size:24px}.panel{box-shadow:3px 3px 0 #05090f}.bridge h1{font-size:27px}.bridge dl{gap:7px 12px}.bridge th,.bridge td{padding:9px}.bridge table{min-width:480px}}
</style>
