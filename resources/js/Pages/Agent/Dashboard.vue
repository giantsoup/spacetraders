<script setup>
import ContractApproval from '@/Components/ContractApproval.vue';
import ManualCommandForm from '@/Components/ManualCommandForm.vue';
import ManualReadBrowser from '@/Components/ManualReadBrowser.vue';
import AdviserPanel from '@/Components/AdviserPanel.vue';
import FocusSheet from '@/Components/FocusSheet.vue';
import ShipScene from '@/Components/ShipScene.vue';
import ShipReport from '@/Components/ShipReport.vue';
import { shipPresentation, observedNumber, meterPercent } from '@/shipPresentation.js';
import AutomationConsole from '@/Components/AutomationConsole.vue';
import AppShell from '@/Layouts/AppShell.vue';
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { submissionKey } from '@/gameplay.js';
import { formatLocalTime, formatLocalClock, localTimeZone } from '@/dateTime.js';
import { assetName, cargoSummary, readableLabel, readableText } from '@/dataPresentation.js';
import CycleResults from '@/Components/CycleResults.vue';
import DataDetails from '@/Components/DataDetails.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TutorialCourse from '@/Components/TutorialCourse.vue';
import GuidedFlight from '@/Components/GuidedFlight.vue';
import GameConnection from '@/Components/GameConnection.vue';
import ExplorationControls from '@/Components/ExplorationControls.vue';
import OpportunityPanel from '@/Components/OpportunityPanel.vue';
import OperationControls from '@/Components/OperationControls.vue';
import { useGuidedFlight } from '@/Composables/useGuidedFlight.js';

const props = defineProps({ manualReserveFloor: Number, manualCommands: Object, manualReads: Object, foundationEnums: Object, adviser: Object, agent: Object, ships: Array, capabilities: Object, observations: Array, actions: Array, runs: Array, health: Object, intelligence: Object, automationEnabled: Boolean, synthetic: Boolean, localAutomation: Boolean, engine: Object, engineOperator: Boolean, investigations: { type: Array, default: () => [] }, drafts: { type: Array, default: () => [] }, accounting: Object, guidedOffers: { type: Array, default: () => [] } });
const archived = computed(() => props.agent.connection_state === 'reset');
const manualFlightMode = ref('CRUISE');
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
const shipRecord = computed(() => props.ships.find(s => s.symbol === selected.value));
const now = ref(Date.now());
const paused = ref(false);
const hidden = ref(false);
const view = ref('');
const reportSection = ref('systems');
const views = { manualApi: 'Manual API', library: 'API library', adviser: 'Adviser policy', report: 'Ship report', fleet: 'Your fleet', commands: 'Commands', review: 'Review approval', automation: 'Automation', intelligence: 'Intelligence', activity: 'Activity history', run: 'Active run', guide: 'Guided flight' };
const aliases = { manual: 'commands', setup: 'commands', market: 'intelligence', waypoints: 'intelligence', fleet: 'fleet', activity: 'activity', automation: 'automation' };
function readView() {
    const key = window.location.hash.slice(1);
    view.value = views[key] ? key : aliases[key] || '';
}
function openView(key, section) {
    if (key === 'guide' && guiding.value) key = guidedDestination();
    if (section) reportSection.value = section;
    if (view.value === key) return;
    view.value = key;
    window.history.pushState({ shipView: key }, '', `${window.location.pathname}${window.location.search}${key ? `#${key}` : ''}`);
}
function resumeClock() { now.value = Date.now(); hidden.value = document.hidden; }
let clock;
onMounted(() => {
    readView(); resumeClock();
    clock = window.setInterval(() => { now.value = Date.now(); }, 1000);
    window.addEventListener('popstate', readView);
    window.addEventListener('hashchange', readView);
    window.addEventListener('pageshow', resumeClock);
    document.addEventListener('visibilitychange', resumeClock);
});
onUnmounted(() => {
    window.clearInterval(clock);
    window.removeEventListener('popstate', readView);
    window.removeEventListener('hashchange', readView);
    window.removeEventListener('pageshow', resumeClock);
    document.removeEventListener('visibilitychange', resumeClock);
});
const presentation = computed(() => shipPresentation(ship.value, shipRecord.value?.observed_at, props.agent.connection_state, now.value));
const primary = computed(() => archived.value ? { label: 'Review archived activity', view: 'activity' }
    : pending.value ? { label: pending.value.state === 'uncertain' || pending.value.review_required ? 'Review unresolved action' : 'View pending command', view: 'activity' }
    : activeRun.value ? { label: 'View active run', view: 'run' }
    : !ship.value || presentation.value.stale ? { label: 'Refresh observations', sync: true }
    : presentation.value.transit ? { label: 'Destination brief', view: 'intelligence' }
    : { label: 'Choose a command', view: 'commands' });
const waypoints = computed(() => props.observations.filter(o => o.kind === 'waypoint' && o.data.systemSymbol === ship.value?.nav?.systemSymbol));
const arrivalPoint = computed(() => waypoints.value.find(point => point.symbol === ship.value?.nav?.waypointSymbol));
const markets = computed(() => props.observations.filter(o => o.kind === 'market'));
const market = computed(() => markets.value.find(o => o.symbol === ship.value?.nav?.waypointSymbol));
const yards = computed(() => props.observations.filter(o => o.kind === 'shipyard'));
const offers = computed(() => yards.value.flatMap(y => (y.data.ships || []).map(o => ({ ...o, yard: y.symbol }))));
const activeRun = computed(() => props.runs.find(r => r.active_agent_id));
const runStep = computed(() => activeRun.value?.strategy === 'contracts' ? activeRun.value.plan?.contract_plan?.actions?.[activeRun.value.totals?.step_index]?.command || 'Awaiting next observation' : activeRun.value?.totals?.phase || activeRun.value?.state);
const latestRun = computed(() => activeRun.value || props.runs.find(r => r.state !== 'preview'));
const historyCache = ref(props.actions);
const historyCursor = ref(props.actions.at(-1)?.id);
const hasOlderActions = ref(props.actions.length === 40);
const historyBusy = ref(false);
watch(() => props.actions, (actions, previous) => {
    historyCache.value = [...new Map([...historyCache.value, ...actions].map(action => [action.id, action])).values()];
    if (actions[0]?.id !== previous[0]?.id) {
        historyCursor.value = actions.at(-1)?.id;
        hasOlderActions.value = actions.length === 40;
    }
});
const historyActions = computed(() => [...historyCache.value].sort((a, b) => b.id - a.id));
async function loadHistory() {
    if (historyBusy.value || !historyCursor.value) return;
    historyBusy.value = true;
    const cursor = historyCursor.value;
    try {
        const result = await window.axios.get(url('history'), { params: { before: cursor } });
        historyCache.value = [...new Map([...historyCache.value, ...result.data.actions, ...props.actions].map(action => [action.id, action])).values()];
        if (historyCursor.value === cursor) {
            historyCursor.value = result.data.actions.at(-1)?.id;
            hasOlderActions.value = result.data.has_more;
        }
    } catch { error.value = true; notice.value = 'Could not load older history. Try again.'; }
    finally { historyBusy.value = false; }
}
const pending = computed(() => props.actions.find(a => ['pending', 'sending', 'uncertain'].includes(a.state) || a.review_required));
const controlled = computed(() => !archived.value && !!activeRun.value && activeRun.value.ship_symbol === selected.value);
const destination = ref('');
const good = ref('');
const quantity = ref(1);
const priceBound = ref(0);
const reserve = ref(props.manualReserveFloor);
const offerIndex = ref('');
const surveySignature = ref('');
const runSurveySignature = ref('');
const transferTarget = ref('');
const surveys = computed(() => props.observations.filter(o => o.kind === 'survey' && o.data.signed.symbol === ship.value?.nav?.waypointSymbol && !o.data.exhausted && !o.data.unavailable_reason && Date.parse(o.data.signed.expiration) > Date.now()));
const site = ref('');
const buyer = ref('');
const spend = ref(0);
const loss = ref(0);
const cycles = ref(1);
const wearAllowance = ref('');
const minutes = ref(60);
const extractionCount = ref(1);
const extractionHeadroom = ref(1);
const siteSeconds = ref(300);
const busy = ref(false);
const notice = ref('');
const error = ref(false);
const commandPreview = ref(null);
const runPreview = ref(null);
watch(() => props.actions, actions => {
    const observed = actions.find(a => a.id === commandPreview.value?.id);
    if (observed && observed.state !== 'preview') commandPreview.value = null;
});
const previewPanel = ref(null);
const note = ref('');
const reconciliation = ref(null);
const selectedQuote = computed(() => market.value?.data.tradeGoods?.find(q => q.symbol === good.value));
const tradeOptions = computed(() => [...new Map([...(market.value?.data.tradeGoods || []), ...(ship.value?.cargo?.inventory || [])].map(item => [item.symbol, item])).values()]);
const selectedWaypoint = computed(() => waypoints.value.find(w => w.symbol === destination.value));
const navigationHelp = computed(() => {
    if (!destination.value) return 'Select a destination before previewing navigation.';
    if (!selectedWaypoint.value || destination.value === ship.value?.nav?.waypointSymbol) return 'Choose a different waypoint in this system.';
    if (ship.value?.nav?.status === 'DOCKED') return 'Enter orbit first: preview orbit and approve that command.';
    if (ship.value?.nav?.status === 'IN_TRANSIT') return 'Wait for arrival, then synchronize before navigating again.';
    return '';
});
const cash = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString();
const age = value => value && Number.isFinite(Date.parse(value)) ? `${Math.max(0, Math.floor((now.value - Date.parse(value)) / 1000))}s ago` : 'Never observed';
const healthy = value => value && now.value - Date.parse(value) < 90000;
const url = suffix => `/agents/${props.agent.id}/${suffix}`;
const reload = () => new Promise(resolve => router.reload({ only: ['agent', 'ships', 'capabilities', 'observations', 'actions', 'runs', 'health', 'guidedOffers', 'engine', 'investigations', 'drafts', 'accounting', 'intelligence', 'adviser'], onFinish: resolve }));
async function prepareMarket(task) {
    if (archived.value || busy.value || pending.value || activeRun.value || guiding.value) return;
    selected.value = task.ship_symbol;
    destination.value = task.market;
    commandPreview.value = null;
    openView('commands');
    if (ship.value?.nav.waypointSymbol === task.market) {
        await synchronize();
    } else {
        error.value = false;
        notice.value = `Destination set to ${task.market} for ${task.ship_symbol}. Enter orbit if docked, then Preview navigation and review fuel before approving. Synchronize after arrival, then investigate mining again.`;
    }
}
usePoll(5000, { only: ['agent', 'ships', 'capabilities', 'observations', 'actions', 'runs', 'health', 'guidedOffers', 'engine', 'investigations', 'drafts', 'accounting', 'intelligence'] });
const guided = useGuidedFlight(() => page.props.auth.user.id, () => ({
    ...props, selected: selected.value, offers: offers.value, commandPreview: commandPreview.value,
    form: { destination: destination.value, good: good.value, quantity: quantity.value, priceBound: priceBound.value, reserve: reserve.value, site: site.value, buyer: buyer.value, offerIndex: offerIndex.value },
}));
const { session: guidedSession, step: guidedStep, storageAvailable: guidedStorage, finished: guidedFinished } = guided;
const guiding = computed(() => !archived.value && guided.active.value);
const guideAllows = command => !archived.value && (!guiding.value || (guidedStep.value?.kind === 'preview' && guidedStep.value.intent.command === command));
function guidedDestination() {
    const target = guidedStep.value?.target || '';
    if (target === 'guided-coach' || target === 'continue-guidance' || target === 'sync') return 'guide';
    if (target === 'approve' || target === 'cancel-preview') return 'review';
    if (target === 'activity' || target === 'stop') return target === 'stop' ? 'run' : 'activity';
    if (target === 'fleet' || target.startsWith('ship:')) return 'fleet';
    if (target === 'market' || target === 'waypoints') return 'intelligence';
    return 'commands';
}
watch(guiding, (active, previous) => { if (previous && !active && guidedSession.value?.phase !== 'complete') openView('guide'); });
watch([guiding, () => guidedStep.value?.target, () => guidedStep.value?.title], () => {
    if (guiding.value) openView(guidedDestination());
});
watch([commandPreview, runPreview], () => { if (commandPreview.value || runPreview.value) openView('review'); });
async function synchronize() {
    if (await post('sync')) guided.synchronized();
}
function sheetReady() { if (view.value === 'commands' && guidedFinished.value) document.getElementById('manual')?.focus({ preventScroll: true }); }
async function finishTutorial() {
    guided.finish();
    await nextTick();
    openView('commands');
    await nextTick();
    document.getElementById('manual')?.focus({ preventScroll: true });
}

async function post(path, payload = {}, allowBusy = false) {
    if (archived.value || (busy.value && !allowBusy)) return null;
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
    if (['buy', 'sell', 'refuel', 'transfer'].includes(command)) args.units = Number(quantity.value);
    if (['buy', 'sell', 'transfer'].includes(command)) args.symbol = good.value;
    if (command === 'transfer') args.targetShip = transferTarget.value;
    if (command === 'mine_survey') Object.assign(args, { surveySignature: surveySignature.value, surveyWaypoint: ship.value.nav.waypointSymbol });
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
async function previewManual(intent) {
    commandPreview.value = await post('commands/preview', { key: submissionKey(), ...intent });
    if (commandPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function previewExploration(intent) {
    commandPreview.value = await post('commands/preview', { key: submissionKey(), ship_symbol: selected.value, command: intent.command, arguments: { ...intent.arguments, reserve: Number(reserve.value) } });
    if (commandPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function approveCommand() {
    if (!commandPreview.value) return;
    const intent = guiding.value && guidedStep.value?.kind === 'approve' ? guidedStep.value.intent : null;
    guided.trackApproval(commandPreview.value, intent);
    const action = await post(`commands/${commandPreview.value.id}/approve`);
    if (action) { commandPreview.value = null; openView('activity'); }
}
async function previewRun() {
    runPreview.value = await post('runs/preview', { ...(wearAllowance.value === '' ? {} : { wear_allowance: Number(wearAllowance.value) }), ship_symbol: selected.value, site: site.value, market: buyer.value, reserve: Number(reserve.value), max_spend: Number(spend.value), max_loss: Number(loss.value), cycles: Number(cycles.value), minutes: Number(minutes.value), max_extractions: Number(extractionCount.value), ...(Number(extractionCount.value) > 1 ? { extraction_headroom: Number(extractionHeadroom.value), site_seconds: Number(siteSeconds.value) } : {}), ...(runSurveySignature.value ? { survey_signature: runSurveySignature.value } : {}) });
    if (runPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function previewOperation(limits) {
    runPreview.value = await post('runs/preview', limits);
    if (runPreview.value) { await nextTick(); previewPanel.value?.focus(); }
}
async function startRun() {
    if (runPreview.value && await post(`runs/${runPreview.value.id}/start`)) { runPreview.value = null; openView('run'); }
}
async function reconcile(action) {
    reconciliation.value = await post(`commands/${action.id}/reconcile`);
}
function distance(point) {
    const origin = waypoints.value.find(p => p.symbol === ship.value?.nav?.waypointSymbol)?.data;
    return origin ? Math.hypot(point.x - origin.x, point.y - origin.y).toFixed(1) : '?';
}
</script>

<template>
    <Head :title="`${agent.name} · Ship`" />
    <AppShell compact brand="SPACETRADERS" :agent-id="agent.id" skip-to="ship-home" skip-label="Skip to selected ship" :guide-href="archived ? route('guide') : route('agents.guide', agent.id)" class="bridge" :class="{ guiding }">
        <main :aria-busy="busy">
            <section id="ship-home" class="ship-home" tabindex="-1" aria-labelledby="ship-title">
                <header class="ship-identity">
                    <button type="button" class="identity-button" @click="openView('fleet')"><h1 id="ship-title">{{ selected || agent.name }}</h1><span aria-hidden="true">⌄</span><span class="sr-only">Switch owned ship</span></button>
                    <button type="button" class="home-link" @click="openView('report', 'systems')">Report</button>
                </header>
                <ShipScene v-if="ship" :ship-name="selected" :transit="presentation.transit" :progress="presentation.progress" :paused="paused || hidden || !!view" @report="openView('report', 'systems')" />
                <div class="ship-status">
                    <p class="status-location">{{ presentation.status }}</p>
                    <p class="ship-eta" :class="{ 'stationary-label': !presentation.transit }">{{ presentation.transit ? presentation.arrival : ship ? assetName(ship.nav?.status) : 'Synchronize fleet' }}</p>
                    <p v-if="presentation.transit">Arrives <time :datetime="ship.nav?.route?.arrival">{{ formatLocalClock(ship.nav?.route?.arrival) }}</time></p>
                    <p v-else>Action cooldown: {{ presentation.readiness }}</p>
                    <p v-if="presentation.transit && presentation.readiness !== 'Ready'">Cooldown: {{ presentation.readiness }}</p>
                    <p v-if="archived" class="warning" id="archived-heading">Archived agent · World reset. Saved historical state; gameplay disabled.</p><Link v-if="archived" :href="`${route('agents.index')}#archived-agents`" class="app-link">Back to current and archived agents</Link>
                    <p v-else-if="presentation.stale" class="warning">Last known state · {{ agent.connection_state === 'connected' ? 'Observation is stale' : 'Connection unconfirmed' }}</p>
                    <p v-if="pending" class="warning">{{ readableLabel(pending.command) }} · {{ readableLabel(pending.state) }}{{ pending.review_required ? ' · Review required' : '' }}</p>
                    <p v-if="activeRun">{{ activeRun.stop_requested_at ? 'Stop requested · reserved work may settle' : `Automation · ${readableLabel(runStep)}` }}</p>
                </div>
                <div class="resource-list">
                    <button type="button" class="resource-row" @click="openView('report', 'fuel')"><span>Fuel</span><span class="resource-value">{{ observedNumber(ship?.fuel?.current) }} / {{ observedNumber(ship?.fuel?.capacity) }}<span class="resource-meter" aria-hidden="true"><i :style="{ width: `${meterPercent(ship?.fuel?.current, ship?.fuel?.capacity)}%` }" /></span></span><span aria-hidden="true">›</span></button>
                    <button type="button" class="resource-row cargo-row" @click="openView('report', 'cargo')"><span>Cargo</span><span class="resource-value">{{ observedNumber(ship?.cargo?.units) }} / {{ observedNumber(ship?.cargo?.capacity) }}<span class="resource-meter" aria-hidden="true"><i :style="{ width: `${meterPercent(ship?.cargo?.units, ship?.cargo?.capacity)}%` }" /></span></span><span aria-hidden="true">›</span></button>
                    <button type="button" class="resource-row systems-row" @click="openView('report', 'systems')"><span>Systems</span><span class="resource-value">{{ presentation.readiness === 'Ready' ? 'Cooldown ready' : presentation.readiness }}</span><span aria-hidden="true">›</span></button>
                </div>
                <p class="sr-only">Times shown in {{ localTimeZone }} (your local timezone).</p><div class="observation-line"><span>Observed {{ presentation.age }}<span v-if="synthetic"> · Offline practice</span><span v-else> · Live SpaceTraders API</span></span><button type="button" class="motion-control" :aria-pressed="paused" @click="paused = !paused">{{ paused ? 'Resume motion' : 'Pause motion' }}</button></div>
                <div class="home-action"><PrimaryButton :disabled="busy" @click="primary.sync ? synchronize() : openView(primary.view)">{{ primary.label }}</PrimaryButton><DangerButton v-if="activeRun && !archived && !view" :disabled="!!activeRun.stop_requested_at" @click="post(`runs/${activeRun.id}/stop`, {}, true)">{{ activeRun.stop_requested_at ? 'Stop requested' : 'Stop automation' }}</DangerButton></div>
            </section>
            <p v-if="notice" :role="error ? 'alert' : 'status'" class="notice" :class="{ fault: error }">{{ readableText(notice) }}</p>
            <nav class="home-destinations" aria-label="Ship destinations"><button v-for="key in ['commands', 'manualApi', 'library', 'automation', 'adviser', 'intelligence', 'activity', 'guide']" :key="key" type="button" @click="openView(key)">{{ key === 'activity' && actions[0] ? `Activity · ${readableLabel(actions[0].state)}` : views[key] }}</button></nav>
            <FocusSheet :open="!!view" :title="views[view] || 'Ship'" @ready="sheetReady" @close="openView('')">
                <nav class="sheet-tabs" aria-label="Flight deck destinations"><button type="button" @click="openView('')">Back to ship</button><SecondaryButton v-if="['commands', 'intelligence', 'guide'].includes(view)" data-guide="sync" :disabled="archived || busy || (guiding && guidedStep?.kind !== 'sync' && !guidedStep?.canSync && !(guidedStep?.kind === 'blocked' && guidedStep?.target === 'sync'))" @click="synchronize">Synchronize</SecondaryButton></nav>
                <p v-if="notice" :role="error ? 'alert' : 'status'" class="notice" :class="{ fault: error }">{{ readableText(notice) }}</p>
                <SecondaryButton v-if="view === 'commands' && error && notice.includes('Open Automation')" @click="openView('automation')">Open Automation</SecondaryButton>
                <section v-show="view === 'guide'" class="agent-heading"><h2>{{ agent.name }}</h2><GameConnection /><p>Credits {{ cash(agent.credits) }} CR · {{ readableLabel(agent.connection_state) }}</p><SecondaryButton v-if="!archived && guidedFinished" @click="guided.start">Restart tutorial</SecondaryButton><p v-if="!localAutomation">Worker: {{ healthy(health.worker) ? 'recent step' : 'idle or offline' }} · {{ age(health.worker) }}. Scheduler: {{ healthy(health.scheduler) ? 'recent pass' : 'stale or offline' }}. Closing this page does not stop gameplay.</p></section>
                <div v-show="view === 'guide' || guiding" class="guidance-slot">
            <GuidedFlight v-if="!archived && !guidedFinished" :session="guidedSession" :step="guidedStep" :storage-available="guidedStorage" :busy="busy" @reveal="openView(guidedDestination())" @start="guided.start" @pause="guided.pause" @resume="guided.resume" @finish="finishTutorial" @advance="guided.advance" @sync="synchronize" @recover="commandPreview = actions.find(action => action.id === guidedSession.pending.id && action.state === 'preview')" @clear-expired="guided.clearExpired(); commandPreview = null" />
            <TutorialCourse v-if="view === 'guide' && !archived && !guiding && !guidedFinished" :agent="agent" compact />


                </div>
                <ManualCommandForm v-if="view === 'manualApi'" :reserve-floor="manualReserveFloor" :commands="manualCommands" :enums="foundationEnums" :selected="selected" :ships="ships" :disabled="archived || busy || !!pending || guiding" @preview="previewManual" />
                <ManualReadBrowser v-if="view === 'library'" :agent="agent" :ships="ships" :selected="selected" :operations="manualReads" :enums="foundationEnums" :disabled="archived || guiding" />
                <ShipReport v-if="view === 'report'" :ship="ship" :observed-at="shipRecord?.observed_at" :section="reportSection" :readiness="presentation.readiness" :capability="capabilities[selected]" @section="reportSection = $event" />
                <AdviserPanel v-if="view === 'adviser'" :agent="agent" :ships="ships" :selected="selected" :history="adviser" :runs="runs" :investigations="investigations" :disabled="archived || guiding" @refresh="async done => { await reload(); done(); }" />
                <section v-if="view === 'run'"><h3>{{ activeRun ? `Run #${activeRun.id}` : 'No active run' }}</h3><template v-if="activeRun"><p>{{ readableLabel(activeRun.state) }} · {{ readableLabel(runStep) }}</p><p v-if="activeRun.stop_requested_at" role="status">Stop requested. Reserved work may settle; travel continues upstream.</p><p v-if="activeRun.blocked_reason" class="warning">{{ readableText(activeRun.blocked_reason) }}</p><p>Next step <time :datetime="activeRun.next_action_at">{{ formatLocalTime(activeRun.next_action_at) }}</time></p><DangerButton data-guide="stop" :disabled="archived || !!activeRun.stop_requested_at" @click="post(`runs/${activeRun.id}/stop`, {}, true)">{{ activeRun.stop_requested_at ? 'Stop requested' : 'Stop automation' }}</DangerButton><h3>Approved limits</h3><DataDetails :value="activeRun.plan" /><h3>Observed outcomes</h3><DataDetails :value="activeRun.totals" /></template><SecondaryButton @click="openView('automation')">Automation and engine controls</SecondaryButton></section>
                <aside v-show="view === 'fleet'" id="fleet" data-guide="fleet" class="panel fleet" aria-labelledby="fleet-heading">
                    <h2 id="fleet-heading">Fleet</h2>
                    <p v-if="ships.length === 0">{{ archived ? 'No fleet snapshot was saved for this archived agent.' : 'No saved fleet. Synchronize to discover ships and local waypoints.' }}</p>
                    <button v-for="entry in ships" :key="entry.symbol" :data-guide="`ship:${entry.symbol}`" :disabled="guiding && entry.symbol !== guidedSession?.plan?.ship" class="ship-choice" :class="{ selected: selected === entry.symbol }" :aria-pressed="selected === entry.symbol" @click="selected = entry.symbol; destination = ''; commandPreview = null; runPreview = null; if (!guiding) openView('')">
                        <strong>{{ entry.symbol }}</strong><span>{{ assetName(entry.snapshot.registration?.role || 'SHIP') }}</span><span>{{ entry.snapshot.nav?.waypointSymbol }}</span><span>{{ assetName(entry.snapshot.nav?.status) }} · Cargo {{ observedNumber(entry.snapshot.cargo?.units) }}/{{ observedNumber(entry.snapshot.cargo?.capacity) }}</span>
                    </button>
                </aside>

                    <fieldset v-show="view === 'automation'" :disabled="guiding"><legend v-if="guiding">Pause guidance before using automation.</legend><AutomationConsole v-if="localAutomation && !archived" :agent="agent" :ships="ships" :engine="engine" :operator="engineOperator" :investigations="investigations" :drafts="drafts" :runs="runs" :accounting="accounting" :observations="observations" :enabled="automationEnabled" @inspect-run="openView('run')" @prepare-market="prepareMarket" @refresh="async done => { await reload(); done(); }" /></fieldset>
                    <section v-if="view === 'intelligence' && presentation.transit" class="panel" aria-labelledby="destination-brief-heading"><h2 id="destination-brief-heading">Destination brief</h2><p>{{ ship.nav.waypointSymbol }} · {{ assetName(arrivalPoint?.data?.type) }}</p><p>Arrival <time :datetime="ship.nav.route?.arrival">{{ formatLocalTime(ship.nav.route?.arrival) }}</time> · {{ presentation.arrival }}</p><p>{{ arrivalPoint?.data?.traits ? arrivalPoint.data.traits.map(assetName).join(', ') || 'No traits observed' : 'Traits Unknown' }} · Observed {{ age(arrivalPoint?.observed_at) }}</p><p class="muted">Destination facts are saved observations. Arrival and command eligibility require a fresh server check.</p></section>
                    <OpportunityPanel v-show="view === 'intelligence'" :report="intelligence" :selected="selected" />
                    <section v-show="view === 'review'" v-if="!archived && (commandPreview || runPreview)" ref="previewPanel" tabindex="-1" class="panel approval" aria-labelledby="approval-heading">
                        <h2 id="approval-heading">Review {{ commandPreview?.command === 'buy_ship' ? 'ship purchase' : commandPreview ? 'manual command' : `${runPreview.plan.strategy || 'mining'} operation` }}</h2>
                        <template v-if="commandPreview">
                            <p>{{ commandPreview.ship_symbol || agent.name }} · {{ readableLabel(commandPreview.command) }} · Expires <time :datetime="commandPreview.expires_at">{{ formatLocalTime(commandPreview.expires_at) }}</time></p>
                            <template v-if="commandPreview.before_state.estimate.exploration">
                                <p>{{ commandPreview.before_state.estimate.exploration.question }}</p>
                                <dl><dt>Target</dt><dd>{{ commandPreview.arguments.waypointSymbol }}</dd><dt>Action limit</dt><dd>1 {{ commandPreview.command === 'chart' ? 'chart' : 'scan' }}; no follow-up</dd><dt>Travel / spending</dt><dd>0 tank units / 0 CR</dd><dt>Protected reserve</dt><dd>{{ cash(commandPreview.before_state.estimate.reserve) }} CR</dd><dt>Cooldown use</dt><dd>{{ commandPreview.command === 'chart' ? 'None' : `One cooldown, duration unknown; review above ${commandPreview.arguments.maxCooldownSeconds}s` }}</dd><dt>Safe continuation</dt><dd>{{ commandPreview.before_state.estimate.exploration.continuation.continuation }} · {{ commandPreview.before_state.estimate.exploration.continuation.required_fuel }} tank units including reserve</dd></dl>
                                <p>Up to 13 API requests across preview, approval and execution. Zero travel and spending are approved. Continuation travel and refueling need separate approval.</p>
                                <p class="warning">{{ commandPreview.before_state.estimate.exploration.cooldown_policy }} {{ commandPreview.before_state.estimate.exploration.stop }}</p>
                                <details><summary>Inspect exploration limits and continuation</summary><DataDetails :value="commandPreview.before_state.estimate.exploration" /></details>
                            </template>
                            <template v-else>
                            <ContractApproval v-if="commandPreview.before_state.contract" :contract="commandPreview.before_state.contract" :term-index="commandPreview.arguments.term_index" />
                            <template v-if="commandPreview.before_state.estimate.tank_refuel">
                                <dl><dt>Fuel source</dt><dd>{{ commandPreview.before_state.estimate.tank_refuel.source }}</dd><dt>Expected tank change</dt><dd>{{ commandPreview.before_state.estimate.tank_refuel.tank_before }} → {{ commandPreview.before_state.estimate.tank_refuel.tank_after }} / {{ commandPreview.before_state.estimate.tank_refuel.capacity }}</dd><dt>Tank units</dt><dd>{{ commandPreview.before_state.estimate.tank_refuel.tank_units }} · {{ commandPreview.before_state.estimate.tank_refuel.omitted_units ? 'Fill tank; units omitted' : 'Capped to remaining tank space' }}</dd></dl>
                                <p v-if="commandPreview.before_state.estimate.cargo_refuel?.rounding_unknown" class="warning">Partial cargo conversion: server rounding is unknown. Maximum approved cargo exposure {{ commandPreview.before_state.estimate.cargo_refuel.max_cargo_units }} FUEL units; this is a review threshold, not a server cap or exact consumption prediction.</p>
                                <details v-if="commandPreview.before_state.estimate.cargo_refuel"><summary>Tank conversion and protected cargo</summary><DataDetails :value="commandPreview.before_state.estimate.cargo_refuel" /></details>
                            </template>
                            <p v-if="commandPreview.before_state.estimate.effects">{{ commandPreview.before_state.estimate.effects }}</p>
                            <DataDetails :value="commandPreview.arguments" class="command-arguments" />
                            <dl><dt>Observed cost</dt><dd>{{ cash(commandPreview.before_state.estimate.cost_estimate) }} CR</dd><dt>Observed sale</dt><dd>{{ cash(commandPreview.before_state.estimate.sale_estimate) }} CR</dd><dt>Protected reserve</dt><dd>{{ cash(commandPreview.before_state.estimate.reserve) }} CR</dd><dt>Available credits</dt><dd>{{ cash(commandPreview.before_state.credits) }} CR</dd><dt>Free cargo</dt><dd>{{ commandPreview.before_state.estimate.cargo_free }}</dd></dl>
                            <p v-if="commandPreview.before_state.estimate.fuel_estimate">Conservative fuel estimate: {{ commandPreview.before_state.estimate.fuel_estimate }}. Arrival comes from the navigation response.</p>
                            <DataDetails v-if="commandPreview.before_state.estimate.route" :value="commandPreview.before_state.estimate.route" />
                            <details v-if="commandPreview.before_state.estimate.equipment || commandPreview.before_state.estimate.maintenance_quote || commandPreview.before_state.estimate.scrap_quote"><summary>Equipment, condition and quote evidence</summary><DataDetails :value="commandPreview.before_state.estimate" /></details>
                            <details v-if="commandPreview.before_state.estimate.configuration"><summary>Complete offered configuration and route</summary><DataDetails :value="commandPreview.before_state.estimate" /></details>
                            <p class="warning">{{ commandPreview.before_state.estimate.quote_limit_warning }}</p>
                            </template>
                            <div class="button-row approval-actions">
                                <DangerButton v-if="['scrap', 'jettison', 'supply'].includes(commandPreview.command)" data-guide="approve" :disabled="archived || busy" @click="approveCommand">Approve {{ commandPreview.command === 'scrap' ? 'irreversible scrap' : 'permanent cargo loss' }}</DangerButton>
                                <PrimaryButton v-else data-guide="approve" :disabled="archived || busy || (guiding && guidedStep?.kind !== 'approve')" @click="approveCommand">Approve {{ commandPreview.command === 'buy_ship' ? 'purchase' : 'command' }}</PrimaryButton>
                                <DangerButton  :disabled="busy" @click="post(`commands/${commandPreview.id}/stop`); commandPreview = null; openView('commands')">Stop manual command</DangerButton>
                                <SecondaryButton data-guide="cancel-preview" @click="commandPreview = null; openView('commands')">Cancel preview</SecondaryButton>
                            </div>
                        </template>
                        <template v-else>
                            <p class="warning">Mining continues within the approved extraction bounds when buyer prices age during travel. Sale and refuel require fresh prices at the buyer; cargo is retained if the sale is blocked.</p>
                            <p>{{ runPreview.ship_symbol }} · {{ runPreview.plan.route.site }} → {{ runPreview.plan.route.market }}</p>
                            <dl><dt>Trips / duration cap</dt><dd>{{ runPreview.plan.cycles }} / {{ runPreview.plan.minutes }} min</dd><dt>Credit reserve</dt><dd>{{ cash(runPreview.plan.reserve) }}</dd><dt>Maximum fuel spend</dt><dd>{{ cash(runPreview.plan.max_spend) }}</dd><dt>Maximum loss</dt><dd>{{ cash(runPreview.plan.max_loss) }}</dd><dt>Fuel estimate / refuel ceiling</dt><dd>{{ runPreview.plan.route.fuel_estimate }} tank units / {{ cash(runPreview.plan.route.fuel_ceiling) }} CR</dd><dt>Run wear allowance including repairs</dt><dd>{{ runPreview.plan.wear_allowance ?? 'Unknown' }} CR</dd><dt>Starting fuel</dt><dd>{{ runPreview.plan.initial_fuel }}</dd><dt>Quote timestamp</dt><dd><time :datetime="runPreview.plan.route.quote_at">{{ formatLocalTime(runPreview.plan.route.quote_at) }}</time></dd><dt>Known buyers for</dt><dd>{{ runPreview.plan.route.known_buyers.map(assetName).join(', ') }}</dd><dt>Unknown yields</dt><dd>{{ runPreview.plan.route.unknown_yields.map(assetName).join(', ') || 'None observed' }}</dd></dl>
                            <DataDetails :value="runPreview.plan" />
                            <p class="warning">{{ runPreview.plan.strategy === 'mining' ? `Up to ${runPreview.plan.max_extractions || 1} extractions per trip.` : runPreview.plan.strategy === 'scout' ? 'One observation visit, then return home.' : 'Bounded inventory purchases, batch sales and an empty return.' }} Unknown cargo stays aboard. No guaranteed execution price or profitability. Stop on reserve, spending, loss, duration, condition, reset, uncertainty or no progress.</p>
                            <div class="button-row approval-actions"><PrimaryButton :disabled="archived || busy || !automationEnabled || guiding" @click="startRun">Start approved {{ runPreview.plan.strategy || 'mining' }} run</PrimaryButton><SecondaryButton @click="runPreview = null; openView('automation')">Cancel preview</SecondaryButton></div>
                        </template>
                    </section>
                    <section v-show="view === 'commands'" id="manual" tabindex="-1" class="panel" aria-labelledby="manual-heading">
                        <h2 id="manual-heading">Manual commands</h2>
                        <p v-if="controlled || pending" class="warning">{{ controlled ? 'Stop automation, then wait for the reserved action to settle before takeover.' : pending?.review_required ? 'A confirmed transaction needs price review before another command.' : 'An action is pending or uncertain. Settle it before another command.' }}</p>
                        <fieldset :disabled="archived || busy || !ship || controlled || !!pending">
                            <legend class="sr-only">Ship commands</legend>
                            <div class="button-row"><SecondaryButton :disabled="!guideAllows('orbit')" data-guide="preview-orbit" @click="preview('orbit')">Preview orbit</SecondaryButton><SecondaryButton :disabled="!guideAllows('dock')" data-guide="preview-dock" @click="preview('dock')">Preview docking</SecondaryButton><SecondaryButton :disabled="!guideAllows('mine')" data-guide="preview-mine" @click="preview('mine')">Preview mining</SecondaryButton></div>
                            <div class="form-grid">
                                <label>Destination<select v-model="destination" data-guide="destination" :disabled="archived || (guiding && guidedStep?.target !== 'destination')" aria-label="Destination" aria-describedby="navigation-help"><option value="">Select waypoint</option><option v-for="point in waypoints" :key="point.id" :value="point.symbol" :disabled="point.symbol === ship?.nav?.waypointSymbol">{{ point.symbol }} · {{ assetName(point.data.type) }}{{ point.symbol === ship?.nav?.waypointSymbol ? ' · current location' : '' }}</option></select></label>
                                <div class="field-action"><p id="navigation-help" class="muted" aria-live="polite">{{ navigationHelp || `${distance(selectedWaypoint.data)} units away. The server checks fuel and route before approval.` }}</p><SecondaryButton :disabled="!!navigationHelp || !guideAllows('navigate')" aria-describedby="navigation-help" data-guide="preview-navigate" @click="preview('navigate')">Preview navigation</SecondaryButton></div>
                            </div>
                            <div class="form-grid"><label>Trade good<select v-model="good" data-guide="good" :disabled="archived || (guiding && guidedStep?.target !== 'good')" aria-label="Trade good"><option value="">Select good</option><option v-for="quote in tradeOptions" :key="quote.symbol" :value="quote.symbol">{{ assetName(quote) }}</option></select></label><label>Quantity / refuel tank units<input v-model="quantity" data-guide="quantity" :disabled="archived || (guiding && guidedStep?.target !== 'quantity')" type="number" min="1" max="100000" step="1"></label><label>Maximum cost / minimum sale total<input v-model="priceBound" data-guide="price" :disabled="archived || (guiding && guidedStep?.target !== 'price')" type="number" min="0" step="1"></label><label>Protected credits<input v-model="reserve" data-guide="reserve" :disabled="archived || (guiding && guidedStep?.target !== 'reserve')" type="number" min="0" step="1"></label></div>
                            <p v-if="selectedQuote" class="muted">Observed buy {{ selectedQuote.purchasePrice }} · sell {{ selectedQuote.sellPrice }} · transaction volume {{ selectedQuote.tradeVolume }}. Quote {{ age(market.observed_at) }}. Refuel: 100 tank units per market unit. Whole-unit cost rounding is a conservative budget assumption.</p>
                            <div class="button-row"><SecondaryButton :disabled="!guideAllows('buy')" data-guide="preview-buy" @click="preview('buy')">Preview cargo purchase</SecondaryButton><SecondaryButton :disabled="!guideAllows('sell')" data-guide="preview-sell" @click="preview('sell')">Preview cargo sale</SecondaryButton><SecondaryButton :disabled="!guideAllows('refuel')" data-guide="preview-refuel" @click="preview('refuel')">Preview refueling</SecondaryButton></div>
                            <label>Flight mode<select v-model="manualFlightMode"><option v-for="mode in ['DRIFT', 'STEALTH', 'CRUISE', 'BURN']" :key="mode">{{ mode }}</option></select></label><SecondaryButton :disabled="guiding" @click="previewExploration({ command: 'flight_mode', arguments: { flightMode: manualFlightMode } })">Preview flight mode change</SecondaryButton>
                            <ExplorationControls :points="waypoints" :ship="ship" :disabled="guiding" @preview="previewExploration" />
                            <details><summary>Surveys and cargo transfers</summary>
                                <p>Survey deposits are preserved exactly. Duplicate counts are not exact probabilities. Transfers need both ships at the same waypoint in matching states.</p>
                                <SecondaryButton :disabled="!guideAllows('survey')" @click="preview('survey')">Preview survey creation</SecondaryButton>
                                <div class="form-grid"><label>Usable signed survey<select v-model="surveySignature"><option value="">Select survey</option><option v-for="survey in surveys" :key="survey.id" :value="survey.symbol">{{ survey.data.signed.size }} · {{ formatLocalTime(survey.data.signed.expiration) }} · {{ survey.symbol }}</option></select></label><SecondaryButton :disabled="!surveySignature || !guideAllows('mine_survey')" @click="preview('mine_survey')">Preview surveyed extraction</SecondaryButton>
                                <label>Receiving ship<select v-model="transferTarget"><option value="">Select receiving ship</option><option v-for="entry in ships.filter(s => s.symbol !== selected)" :key="entry.symbol" :value="entry.symbol">{{ entry.symbol }}</option></select></label><SecondaryButton :disabled="!transferTarget || !good || !guideAllows('transfer')" @click="preview('transfer')">Preview transfer of selected good and quantity</SecondaryButton></div>
                            </details>
                        </fieldset>
                        <p class="muted">Every command is checked again by the server. Transit, cargo, cooldown, ownership and spending failures appear before send where known.</p>
                    </section>

                    <section v-if="!localAutomation" v-show="view === 'automation'" id="automation" class="panel" aria-labelledby="automation-heading">
                        <h2 id="automation-heading">Mining automation</h2><SecondaryButton @click="openView('commands')">Choose site and buyer in Commands</SecondaryButton>
                        <p v-if="!automationEnabled" class="warning">Starts are disabled in local configuration. Preview remains available.</p>
                        <p v-if="latestRun">Run #{{ latestRun.id }} · {{ readableLabel(latestRun.state) }} · Next due {{ formatLocalTime(latestRun.next_action_at, 'none') }} · Last step {{ age(latestRun.heartbeat_at) }}</p>
                        <p v-if="latestRun?.blocked_reason" class="warning">{{ readableText(latestRun.blocked_reason) }}</p>
                        <fieldset :disabled="archived || busy || !ship || !!activeRun || !!pending"><legend>Bounded trip limits</legend>
                            <div class="form-grid"><label>Protected credit reserve<input v-model="reserve" data-guide="reserve" :disabled="archived || (guiding && guidedStep?.target !== 'reserve')" type="number" min="1" step="1"></label><label>Maximum fuel spending<input v-model="spend" type="number" min="1" step="1"></label><label>Maximum credit loss<input v-model="loss" type="number" min="0" step="1"></label><label>Run wear allowance including repairs in CR<input v-model="wearAllowance" type="number" min="0" step="1" placeholder="Unknown"></label><label>Trip count<input v-model="cycles" type="number" min="1" max="10" step="1"></label><label>Duration cap in minutes<input v-model="minutes" type="number" min="1" max="60" step="1"></label></div>
                            <div class="form-grid"><label>Maximum extractions per trip<input v-model="extractionCount" type="number" min="1" max="10"></label><template v-if="Number(extractionCount) > 1"><label>Required headroom before each extraction<input v-model="extractionHeadroom" type="number" min="1" :max="ship?.cargo.capacity"></label><label>Maximum seconds extracting at the site<input v-model="siteSeconds" type="number" min="1" max="900"></label></template></div>
                            <p v-if="Number(extractionCount) > 1" class="warning">Yield size is uncertain. Choose a conservative headroom allowance; the server may still reject an extraction. Stops at the first count, headroom, site-time, buyer or hazard bound. Fuller loads have no proven live earnings advantage.</p>
                            <label>Optional survey for this run<select v-model="runSurveySignature" aria-label="Optional survey for this run"><option value="">Extract without a survey</option><option v-for="survey in observations.filter(o => o.kind === 'survey' && o.data.signed.symbol === site && !o.data.exhausted && !o.data.unavailable_reason && Date.parse(o.data.signed.expiration) > Date.now())" :key="survey.id" :value="survey.symbol">{{ survey.symbol }} · {{ survey.data.signed.size }} · {{ formatLocalTime(survey.data.signed.expiration) }}</option></select></label>
                            <PrimaryButton :disabled="guiding" @click="previewRun">Preview mining run</PrimaryButton>
                        </fieldset>
                        <p class="muted">Enter explicit credit bounds. Preview computes the minimum reserve from observed fuel quotes and current credits. Unknown prices require an approved observation trip. One extraction is the default; longer loads use the explicit limits above. Mining never buys cargo or discards goods.</p>
                        <div v-if="latestRun" class="outcomes"><h3>Recorded outcomes</h3><dl><dt>Completed trips</dt><dd>{{ latestRun.totals.cycles }}</dd><dt>Run credit contribution</dt><dd>{{ cash(latestRun.totals.credit_delta) }} CR</dd><dt>Agent balance change</dt><dd>{{ cash(latestRun.totals.agent_balance_delta ?? latestRun.totals.credit_delta) }} CR</dd><dt>Other local commands</dt><dd>{{ cash(latestRun.totals.other_credit_delta ?? 0) }} CR</dd><dt>Sale proceeds</dt><dd>{{ cash(latestRun.totals.sale_proceeds) }} CR</dd><dt>Fuel purchased</dt><dd>{{ latestRun.totals.fuel_units }} tank units / {{ cash(latestRun.totals.fuel_cost) }} CR</dd><dt>Trip cash contribution</dt><dd>{{ cash(latestRun.totals.cash_contribution) }} CR</dd><dt>Starting / ending fuel</dt><dd>{{ latestRun.plan.initial_fuel }} / {{ latestRun.totals.ending_fuel ?? 'pending' }}</dd><dt>Elapsed time</dt><dd>{{ latestRun.totals.elapsed_seconds ?? 'pending' }} seconds</dd></dl><dl class="cargo-totals"><dt>Extracted cargo</dt><dd>{{ cargoSummary(latestRun.totals.extracted) }}</dd><dt>Sold cargo</dt><dd>{{ cargoSummary(latestRun.totals.sold) }}</dd><dt>Unsold cargo</dt><dd>{{ cargoSummary(latestRun.totals.unsold) }}</dd></dl><p class="muted">Cash contribution deducts inventory acquisition and purchased fuel. Unmatched location, fuel, inventory and unmeasured wear prevent treating it as economic profit.</p><CycleResults :accounting="latestRun.totals.accounting" /></div>
                    </section>

                    <OperationControls v-show="view === 'automation'" :selected="selected" :report="intelligence" :disabled="archived || busy || !ship || !!activeRun || !!pending || guiding" @preview="previewOperation" />

                    <section data-guide="setup" v-show="view === 'commands'" id="setup" class="panel" aria-labelledby="setup-heading">
                        <h2 id="setup-heading">One-time mining setup</h2>
                        <div class="form-grid"><label>Mining site<select v-model="site" data-guide="site" :disabled="archived || (guiding && guidedStep?.target !== 'site')" aria-label="Mining site"><option value="">Select site</option><option v-for="point in waypoints.filter(p => ['ASTEROID', 'ASTEROID_FIELD', 'ENGINEERED_ASTEROID'].includes(p.data.type))" :key="point.id" :value="point.symbol">{{ point.symbol }}</option></select></label><label>Sale and refueling market<select v-model="buyer" data-guide="buyer" :disabled="archived || (guiding && guidedStep?.target !== 'buyer')" aria-label="Sale and refueling market"><option value="">Select buyer</option><option v-for="point in markets" :key="point.id" :value="point.symbol">{{ point.symbol }} · {{ point.data.tradeGoods ? 'observed quotes' : 'prices unknown' }}</option></select></label></div>
                        <p v-if="Object.values(capabilities).includes(null)" class="good">An operational miner is already available. Setup purchase is skipped.</p>
                        <template v-else><p>Buy a complete verified miner only. Select a site and buyer above. Approve scouting navigation to the shipyard first. Outfitting is a separate follow-up when no affordable complete miner exists.</p><label>Observed shipyard offer<select v-model="offerIndex" data-guide="offer" :disabled="archived || (guiding && guidedStep?.target !== 'offer')" aria-label="Observed shipyard offer"><option value="">Select offer</option><option v-for="(offer, index) in offers" :key="`${offer.yard}-${offer.type}`" :value="String(index)">{{ assetName(offer) }} · {{ offer.yard }} · {{ cash(offer.purchasePrice) }} CR</option></select></label><p>Use the maximum-cost and protected-credit fields in Manual commands. Approval is separate from starting automation.</p><SecondaryButton :disabled="archived || busy || !ship || !!pending || !!activeRun || !guideAllows('buy_ship')" data-guide="preview-buy_ship" @click="preview('buy_ship')">Preview one-time miner purchase</SecondaryButton></template>
                    </section>
                    <section v-show="view === 'intelligence'" id="waypoints" class="panel" aria-labelledby="map-heading"><h2 id="map-heading">Local waypoints</h2><div class="table-scroll"><table><caption class="sr-only">Known same-system waypoints</caption><thead><tr><th>Waypoint</th><th>Type / traits</th><th>Coordinates</th><th>Distance</th></tr></thead><tbody><tr v-for="point in waypoints" :key="point.id"><td><button class="text-button" @click="destination = point.symbol; openView('commands')">{{ point.symbol }}</button></td><td>{{ assetName(point.data.type) }}<br><span class="muted">{{ point.data.traits_observed === false ? 'Traits unknown' : (point.data.traits || []).map(assetName).join(', ') || 'No traits observed' }}</span></td><td>{{ point.data.x }}, {{ point.data.y }}</td><td>{{ distance(point.data) }}</td></tr></tbody></table></div></section>
                    <section data-guide="market" v-show="view === 'intelligence'" id="market" class="panel" aria-labelledby="market-heading"><h2 id="market-heading">Cargo and market</h2><p>Market {{ ship?.nav?.waypointSymbol || 'unknown' }} · Quote {{ age(market?.observed_at) }}. Missing prices are unknown.</p><div class="table-scroll"><table><caption class="sr-only">Cargo and local market prices</caption><thead><tr><th>Good</th><th>Aboard</th><th>Buy</th><th>Sell</th><th>Volume</th></tr></thead><tbody><tr v-for="quote in market?.data.tradeGoods || []" :key="quote.symbol"><td>{{ assetName(quote) }}</td><td>{{ Array.isArray(ship?.cargo?.inventory) ? ship.cargo.inventory.find(i => i.symbol === quote.symbol)?.units ?? 0 : 'Unknown' }}</td><td>{{ cash(quote.purchasePrice) }}</td><td>{{ cash(quote.sellPrice) }}</td><td>{{ quote.tradeVolume }}</td></tr><tr v-for="item in ship?.cargo?.inventory?.filter(i => !market?.data.tradeGoods?.some(g => g.symbol === i.symbol)) || []" :key="item.symbol"><td>{{ assetName(item) }}</td><td>{{ item.units }}</td><td colspan="3">Unknown</td></tr></tbody></table></div></section>
                    <section data-guide="activity" v-show="view === 'activity'" id="activity" class="panel" tabindex="-1" aria-labelledby="activity-heading"><h2 id="activity-heading">Activity history</h2><p v-if="!actions.length">No commands recorded. Preview a command to begin.</p><ol class="activity"><li v-for="action in historyActions" :key="action.id"><div><strong>#{{ action.id }} {{ readableLabel(action.command) }}</strong> <span>{{ readableLabel(action.state) }}{{ action.review_required ? ' · Review required' : '' }} · {{ readableLabel(action.origin) }} · {{ action.ship_symbol }}</span></div><p>{{ readableText(action.message || 'Awaiting approval') }} <span v-if="action.api_code">API {{ action.api_code }}</span><span v-if="action.outcome?.credit_delta !== undefined"> · {{ cash(action.outcome.credit_delta) }} CR</span></p><small class="muted"><time :datetime="action.created_at">{{ formatLocalTime(action.created_at) }}</time></small><details v-if="action.outcome?.response"><summary>Authoritative command response</summary><DataDetails :value="action.outcome.response" /></details><div v-if="action.outcome?.exploration"><p>{{ action.outcome.exploration.next }}</p><p v-if="action.outcome.exploration.cooldown">Cooldown ends <time :datetime="action.outcome.exploration.cooldown.expiration">{{ formatLocalTime(action.outcome.exploration.cooldown.expiration) }}</time></p><details><summary>Exploration evidence</summary><DataDetails :value="action.outcome.exploration" /></details></div><DangerButton v-if="action.origin === 'manual' && ['preview', 'pending', 'sending'].includes(action.state)" :disabled="busy" @click="post(`commands/${action.id}/stop`, {}, true)">Stop manual command #{{ action.id }}</DangerButton><SecondaryButton v-if="action.state === 'preview'" :disabled="archived || busy" @click="commandPreview = action">Review saved preview #{{ action.id }}</SecondaryButton><div v-if="action.state === 'uncertain' || action.review_required"><SecondaryButton :disabled="archived || busy" @click="reconcile(action)">Reconcile #{{ action.id }}</SecondaryButton><details v-if="action.outcome"><summary>Inspect reconciliation evidence</summary><DataDetails :value="action.outcome" /></details><div v-if="action.outcome?.reconciled_at"><label>Operator reconciliation note<input v-model="note" minlength="10" maxlength="500"></label><DangerButton :disabled="archived || busy || note.length < 10" @click="post(`commands/${action.id}/acknowledge`, { acknowledge: true, note })">Acknowledge reviewed outcome and end run</DangerButton><p class="warning">Confirmed transactions keep their result. Unproven outcomes stay labeled as unresolved adjustments. Neither is replayed; review releases manual control.</p></div></div></li></ol><SecondaryButton v-if="hasOlderActions" :disabled="historyBusy" @click="loadHistory">Load older commands</SecondaryButton><p v-if="reconciliation" class="sr-only" role="status">{{ reconciliation.message }}</p></section>

            </FocusSheet>
        </main>
    </AppShell>
</template>
<style scoped>
.guidance-slot { display: contents; }
.bridge main { max-width: 640px; margin: auto; padding: 0 max(16px, env(safe-area-inset-right)) max(20px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
.ship-home { display: flex; flex-direction: column; min-height: calc(100svh - 56px - env(safe-area-inset-top)); }
.ship-identity { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.identity-button { flex: 1; min-width: 0; display: flex; align-items: center; gap: 8px; text-align: left; min-height: 52px; background: none; color: var(--color-ink); border: 0; }
h1 { min-width: 0; font-size: 20px; font-weight: 650; line-height: 1.25; overflow-wrap: anywhere; }
.home-link { flex-shrink: 0; white-space: nowrap; min-height: 44px; min-width: 72px; padding: 8px; background: none; color: var(--color-accent); }
.ship-status { padding: 8px 4px 16px; }
.bridge .ship-status p { margin: 4px 0; color: var(--color-muted); }
.bridge .ship-status .ship-eta { font-size: 64px; font-weight: 650; line-height: 1.1; letter-spacing: -.035em; font-variant-numeric: tabular-nums; color: var(--color-ink); }
.bridge .ship-status .stationary-label { font-size: 30px; letter-spacing: -.02em; }
.bridge .ship-status .warning { color: var(--color-focus); }
.resource-list { background: var(--color-field); border-radius: 14px; }
.resource-row { display: flex; align-items: center; gap: 16px; width: 100%; min-height: 56px; padding: 10px 16px; background: none; color: var(--color-ink); text-align: left; border: 0; }
.resource-row + .resource-row { border-top: 1px solid #b2c8e61c; }
.resource-row > :last-child { color: var(--color-muted); }
.resource-value { margin-left: auto; color: var(--color-muted); text-align: right; }
.resource-meter { display: block; margin: 5px 0 0 auto; width: 72px; height: 4px; border-radius: 4px; background: #29374a; }
.resource-meter i { display: block; height: 4px; background: var(--color-accent); border-radius: 4px; }
.cargo-row i { background: var(--color-cargo); }
.observation-line { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--color-muted); font-size: 12px; padding: 0 8px; }
.motion-control { white-space: nowrap; flex-shrink: 0; min-height: 44px; min-width: 44px; background: none; color: var(--color-muted); text-decoration: underline; }
.home-action { display: grid; gap: 12px; margin-top: auto; padding-top: 12px; }
.home-action .app-button { width: 100%; min-height: 52px; border-radius: 16px; }
.home-destinations { display: flex; justify-content: center; flex-wrap: wrap; gap: 4px 16px; padding: 20px 0 4px; border-top: 1px solid var(--color-line); margin-top: 16px; }
.home-destinations button { background: none; border: 0; color: var(--color-muted); min-height: 44px; padding: 8px 0; }
.panel { padding: 16px 0; min-width: 0; border-bottom: 1px solid var(--color-line); }
.bridge h2 { font-size: 20px; font-weight: 650; margin: 0 0 16px; }
.bridge h3 { font-size: 18px; font-weight: 650; margin: 20px 0 12px; }
.bridge p { margin: 8px 0; }
.muted,.health { color: var(--color-muted); }
.warning { color: var(--color-focus); }
.good { color: var(--color-accent); }
.ship-choice { display: grid; width: 100%; min-height: 44px; padding: 16px; margin-bottom: 12px; gap: 4px; text-align: left; border: 1px solid var(--color-line); border-radius: 14px; background: var(--color-field); color: var(--color-ink); }
.ship-choice.selected { border-color: #9ae6c6; }
.ship-choice span { color: var(--color-muted); font-size: 14px; }
.bridge label { display: grid; gap: 8px; font-size: 15px; }
.bridge input,.bridge select { display: block; width: 100%; min-height: 44px; border: 1px solid #67829f; background: var(--color-field); color: var(--color-ink); padding: 10px; border-radius: 12px; font: inherit; }
.form-grid { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 18px; margin: 20px 0; }
.field-action { align-self: end; }
.button-row { display: flex; flex-wrap: wrap; align-items: center; gap: 16px; margin: 18px 0; }
.approval-actions { margin: 24px 0 0; }
.command-arguments { margin: 16px 0; padding: 4px 14px; background: var(--color-field); }
.bridge fieldset { min-width: 0; }
.bridge fieldset:disabled { opacity: .65; }
.bridge dl:not(.data-details) { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 10px 16px; font-size: 15px; }
.bridge dt { color: var(--color-muted); }
.bridge dd { text-align: right; overflow-wrap: anywhere; }
.notice { padding: 16px; border: 1px solid var(--color-accent); border-radius: 14px; background: #123429; }
.notice.fault { background: #421f2b; border-color: var(--color-fault); color: #ffdde4; }
.bridge details { margin: 12px 0; }
.bridge summary { color: var(--color-accent); min-height: 44px; }
.table-scroll { overflow-x: auto; }
.bridge table { width: 100%; border-collapse: collapse; font-size: 14px; }
.bridge th { text-align: left; color: var(--color-muted); }
.bridge th,.bridge td { padding: 12px 8px; border-bottom: 1px solid var(--color-line); vertical-align: top; }
.bridge .text-button { min-height: 44px; background: none; color: var(--color-accent); text-decoration: underline; }
.activity { list-style: none; display: grid; gap: 16px; }
.activity li { border-bottom: 1px solid var(--color-line); padding-bottom: 16px; }
.activity li>div:first-child { display: flex; flex-wrap: wrap; gap: 8px; justify-content: space-between; }
.activity span { color: var(--color-muted); }
.agent-heading { display: grid; gap: 8px; }
@media(max-height:719px) { .bridge .ship-status .ship-eta { font-size: 56px; } .bridge .ship-status .stationary-label { font-size: 28px; } }
@media(max-height:600px) { .systems-row { display: none; } .ship-status { padding-bottom: 12px; } .bridge .ship-status .ship-eta { font-size: 48px; } .bridge .ship-status .stationary-label { font-size: 26px; } .resource-row { min-height: 50px; } }
@media(max-width:680px) { .form-grid { grid-template-columns: minmax(0,1fr); } .bridge table { min-width: 440px; } }
@media(orientation:landscape) and (max-height:500px) { .ship-home { min-height: 0; } }
</style>
