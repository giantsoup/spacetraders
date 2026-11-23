<script setup>
import AlsoWatching from '@/Components/Bridge/AlsoWatching.vue';
import AttentionCard from '@/Components/Bridge/AttentionCard.vue';
import CreditsBox from '@/Components/Bridge/CreditsBox.vue';
import CreditsChart from '@/Components/Bridge/CreditsChart.vue';
import LogRow from '@/Components/Bridge/LogRow.vue';
import PlanCard from '@/Components/Bridge/PlanCard.vue';
import ProbeSummaryRow from '@/Components/Bridge/ProbeSummaryRow.vue';
import RouteRow from '@/Components/Bridge/RouteRow.vue';
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import { bridgeHref, clock, identity, scheduledTime, timestamp, until, validNumber } from '@/bridgeFormat.js';
import { Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ bridge: Object, attention: Object, plan: Object, routes: Object, fleet: Object, log: Object, chart: Array });
const agent = computed(() => props.bridge.agent?.symbol);
const strip = computed(() => props.bridge.creditsStrip);
const groups = computed(() => (props.fleet?.groups || []).filter(group => group.count));
const shipsBySymbol = computed(() => new Map(groups.value.flatMap(group => group.ships || []).map(ship => [ship.symbol, ship])));
const counts = computed(() => new Map(groups.value.map(group => [group.name.toLowerCase(), group.count])));
const ships = computed(() => props.fleet?.count || 0);
const working = computed(() => Math.max(0, ships.value - (counts.value.get('stopped') || 0) - (counts.value.get('idle') || 0)));
const probes = computed(() => props.fleet?.probes);
const cards = computed(() => props.attention?.cards || []);
const operations = computed(() => [...(props.routes?.stopped || []), ...(props.routes?.settling || []), ...(props.routes?.running || [])]);
const resetAt = computed(() => props.bridge.nextResetAt);
const waiting = computed(() => !props.bridge.agent?.reset_id || props.bridge.resetUnconfirmed);
const engineStopped = computed(() => cards.value.some(card => card.code === 'engine_heartbeat_stale'));
const fleetPaused = computed(() => cards.value.find(card => card.code === 'operator_pause' && card.scope === 'agent'));
const held = computed(() => cards.value.some(card => card.code === 'drain_pause' || card.code?.startsWith('drain_pause:')));
const primaryLoss = computed(() => cards.value.some(card => card.code === 'consecutive_losing_loads' && ['primary', 'main_campaign', 'main campaign'].includes(card.scope)));
const summary = computed(() => {
    if (!agent.value) return { status: 'Idle', icon: '○', message: 'no campaign is currently authorized.' };
    if (waiting.value) return { status: 'Waiting', icon: '○', message: props.bridge.resetUnconfirmed
        ? 'we’re waiting to confirm the current wipe.'
        : `standing by for the new wipe${resetAt.value ? ' at ' + scheduledTime(resetAt.value) : ''}.` };
    if (engineStopped.value) return { status: 'Stopped', icon: '⛔', message: 'the engine isn’t responding. Nothing is being directed.' };
    if (held.value) return { status: 'Stopped', icon: '⛔', message: `${agent.value} is held back to protect the request budget.` };
    if (fleetPaused.value) return { status: 'Paused', icon: '■', message: `the fleet is paused on your order${fleetPaused.value.at ? ' since ' + clock(fleetPaused.value.at) : ''}.` };
    if (primaryLoss.value) return { status: 'Stopped', icon: '⛔', message: 'the main campaign paused after two losing loads.' };
    if (cards.value.length) return { status: 'Needs you', icon: '⚠', message: `${cards.value.length} ${cards.value.length === 1 ? 'item needs' : 'items need'} your attention.`, showRate: true };
    const activity = ships.value > 0 && ships.value === working.value ? `all ${ships.value} ${ships.value === 1 ? 'ship is' : 'ships are'} working.`
        : working.value > 0 ? `${working.value} of ${ships.value} ships are working.` : 'the plan is getting started.';
    return { status: 'All clear', icon: '●', message: activity, showRate: true };
});

const refreshingChart = ref(false);
let loadedSample = props.bridge.latestSampleId;
watch(() => props.chart, () => { loadedSample = props.bridge.latestSampleId; });
watch(() => [props.bridge.latestSampleId, props.bridge.updatedAt], ([id]) => {
    if (!agent.value || refreshingChart.value || loadedSample === id) return;
    refreshingChart.value = true;
    router.reload({
        only: ['chart'],
        onSuccess: () => { loadedSample = id; },
        onNetworkError: () => false,
        onHttpException: () => false,
        onFinish: () => { refreshingChart.value = false; },
    });
});
</script>

<template>
    <BridgeLayout :bridge="bridge" title="Brief" :poll="['attention', 'plan', 'routes', 'fleet', 'log']" :polling-paused="refreshingChart">
        <template #default="{ now, showReview }">
            <div class="bridge-brief-grid">
                <div class="bridge-brief-first">
                    <CreditsBox v-if="agent && !waiting" :strip="strip" :updated-at="bridge.updatedAt" :data-as-of="bridge.dataAsOf" :now="now" full>
                        <dl class="bridge-kpis bridge-credits-kpis">
                            <div><dt>Ships</dt><dd>{{ ships }}</dd><dd v-if="counts.has('stopped') || counts.has('idle')" class="bridge-detail">{{ [counts.has('stopped') && counts.get('stopped') + ' stopped', counts.has('idle') && counts.get('idle') + ' idle'].filter(Boolean).join(' · ') }}</dd></div>
                            <div><dt>Routes</dt><dd>{{ routes?.running?.length || 0 }}</dd><dd class="bridge-detail">running<template v-if="routes?.stopped?.length"> · {{ routes.stopped.length }} stopped</template></dd></div>
                            <div><dt>Reset</dt><dd>{{ until(resetAt, now) }}</dd><dd v-if="scheduledTime(resetAt)" class="bridge-detail">{{ scheduledTime(resetAt) }}</dd></div>
                        </dl>
                    </CreditsBox>

                    <p class="bridge-summary">
                        Admiral, {{ summary.message }}
                        <template v-if="summary.showRate && validNumber(routes?.rate) && routes.rate !== 0">
                            We’re {{ routes.rate > 0 ? 'earning' : 'losing' }} <ShortMoney :value="Math.abs(routes.rate)" /> an hour.
                        </template>
                    </p>
                    <p class="bridge-summary-detail">
                        <span class="bridge-pill" :class="{ 'bridge-positive': summary.status === 'All clear', 'bridge-negative': ['Stopped', 'Paused'].includes(summary.status), 'bridge-warning': summary.status === 'Needs you' }"><span aria-hidden="true">{{ summary.icon }}</span> {{ summary.status }}</span>
                        <span v-if="summary.showRate && ships && (cards.length || working !== ships)" class="bridge-detail">{{ working }} of {{ ships }} ships working.</span>
                        <span v-if="!agent || waiting" class="bridge-detail" :class="{ 'bridge-warning': now - timestamp(bridge.updatedAt) > 45000 }">
                            <template v-if="now - timestamp(bridge.updatedAt) > 45000">Not updated since <time :datetime="bridge.updatedAt">{{ clock(bridge.updatedAt) }}</time> — reconnecting</template>
                            <template v-else-if="bridge.updatedAt">Updated <time :datetime="bridge.updatedAt">{{ clock(bridge.updatedAt) }}</time></template>
                        </span>
                    </p>
                    <p v-if="bridge.status" class="bridge-flash" role="status" tabindex="-1">{{ bridge.status }}</p>
                    <p v-if="!agent">Approve a playbook on the PC with <code>campaign:approve</code>.</p>
                    <section v-else>
                        <div class="bridge-section-head"><h2>Needs you<template v-if="cards.length"> ({{ cards.length }})</template></h2></div>
                        <div v-if="cards.length" class="bridge-attention-list">
                            <AttentionCard v-for="(card, index) in cards" :key="`${card.code}-${card.grant_id ?? index}`" :card="card" :primary="index === 0" :agent="agent" :ship="shipsBySymbol.get(identity(card.ship).symbol)" @review="showReview" />
                        </div>
                        <p v-else class="bridge-empty">Nothing needs you.</p>
                    </section>
                    <AlsoWatching v-if="agent" :items="attention?.watching" />
                </div>

                <div v-if="agent" class="bridge-brief-more">
                    <CreditsChart :segments="chart" />
                    <section v-if="!waiting" class="bridge-operations">
                        <div class="bridge-section-head"><h2>Operations</h2><Link :href="bridgeHref('routes', agent)" class="bridge-link">Routes ›</Link></div>
                        <div v-if="operations.length || routes?.ended?.count || probes?.count || routes?.mining?.length" class="bridge-panel">
                            <RouteRow v-for="entry in operations" :key="entry.key || `${entry.good}-${entry.buyer}`" :entry="entry" :agent="agent" from="brief" brief />
                            <Link v-if="routes?.ended?.count" class="bridge-row" :href="bridgeHref('routes.ended', agent, { from: 'brief' })">
                                <span class="bridge-dot" aria-hidden="true"></span><span class="bridge-grow"><strong>Ended routes</strong><span class="bridge-detail">{{ routes.ended.count }} {{ routes.ended.count === 1 ? 'route' : 'routes' }} · net <ShortMoney :value="routes.ended.net" delta /></span></span><span class="bridge-chevron" aria-hidden="true">›</span>
                            </Link>
                            <ProbeSummaryRow v-if="probes" :probes="probes" :agent="agent" from="brief" />
                            <template v-if="routes?.mining?.length">
                                <h3 class="bridge-row">Mining</h3>
                                <RouteRow v-for="entry in routes.mining" :key="entry.key" :entry="entry" :agent="agent" from="brief" brief />
                            </template>
                        </div>
                        <p v-else class="bridge-empty">{{ held ? 'No routes are running while the fleet is held.' : 'No routes running yet. The plan is choosing its first trade.' }}</p>
                    </section>
                    <section v-if="!waiting && ships" class="bridge-fleet-summary">
                        <div class="bridge-section-head"><h2>Fleet</h2><Link :href="bridgeHref('fleet', agent)" class="bridge-link">Fleet ›</Link></div>
                        <div class="bridge-panel bridge-fleet-bars">
                            <div class="bridge-fleet-bar" aria-hidden="true">
                                <i v-for="group in groups" :key="group.name" :class="group.name.toLowerCase().replaceAll(' ', '-')" :style="{ flexGrow: group.count }"></i>
                            </div>
                            <ul class="bridge-fleet-key"><li v-for="group in groups" :key="group.name"><i :class="group.name.toLowerCase().replaceAll(' ', '-')" aria-hidden="true"></i>{{ group.count }} {{ group.name.toLowerCase() }}</li></ul>
                        </div>
                    </section>
                    <PlanCard v-if="plan" class="bridge-brief-plan" :plan="plan" :agent="agent" />
                    <section v-if="!waiting" class="bridge-log-preview">
                        <div class="bridge-section-head"><h2>Log</h2><Link :href="bridgeHref('log', agent)" class="bridge-link">Log ›</Link></div>
                        <div v-if="log?.rows?.length" class="bridge-panel">
                            <LogRow v-for="entry in log.rows" :key="entry.id" :entry="entry" />
                        </div>
                        <p v-else class="bridge-empty">No events logged this wipe.</p>
                    </section>
                </div>
            </div>
        </template>
        <template #footer="{ showReview }">
            <div v-if="agent" class="bridge-pause-footer">
                <button type="button" class="bridge-danger-quiet" aria-haspopup="dialog" @click="showReview('fleet_pause', null, $event.currentTarget)">Pause the whole fleet…</button>
                <p class="bridge-detail">Main campaign and every ship of {{ agent }}.</p>
            </div>
        </template>
    </BridgeLayout>
</template>
