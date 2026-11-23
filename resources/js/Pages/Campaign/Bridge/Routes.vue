<script setup>
import AttentionCard from '@/Components/Bridge/AttentionCard.vue';
import RecentLoads from '@/Components/Bridge/RecentLoads.vue';
import RouteRow from '@/Components/Bridge/RouteRow.vue';
import ShipRow from '@/Components/Bridge/ShipRow.vue';
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import { amount, bridgeHref, clock, commodity, currentFrom, detailBack, elapsed, fullTime, identity, routeParts, shipHref, useSplitView, validNumber, waypoint } from '@/bridgeFormat.js';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ bridge: Object, attention: Object, routes: Object, selectedRoute: Object, fromShip: Object });
const page = usePage();
const split = useSplitView();
const agent = computed(() => props.bridge.agent?.symbol);
const from = computed(() => currentFrom(page.url));
const openedFromShip = computed(() => from.value?.startsWith('ship:') && shipHref(from.value.slice(5), agent.value));
const back = computed(() => props.selectedRoute && detailBack(from.value, 'routes', agent.value, props.fromShip ?? props.selectedRoute.ships?.find(ship => `ship:${ship.symbol}` === from.value)));
const routeGroups = computed(() => [
    { title: 'Stopped', entries: props.routes?.stopped },
    { title: 'Settling', entries: props.routes?.settling },
    { title: 'Running', entries: props.routes?.running },
    { title: 'Mining', entries: props.routes?.mining },
].filter(group => group.entries?.length));
const selected = computed(() => props.selectedRoute);
const held = computed(() => props.attention?.cards?.some(card => card.code === 'drain_pause'));
const statusTone = computed(() => selected.value?.status === 'Running' ? 'bridge-positive' : selected.value?.status === 'Ended' ? '' : 'bridge-negative');
const mining = computed(() => props.routes?.mining?.some(entry => entry.key === selected.value?.key));
const card = computed(() => {
    if (!selected.value || ['Running', 'Ended'].includes(selected.value.status)) return null;
    const grants = new Set(selected.value.ships?.map(ship => ship.grant_id).filter(Boolean));
    const cards = props.attention?.cards || [];
    return cards.find(item => grants.has(item.grant_id)) ?? cards.find(item => item.scope === 'agent' || item.scope === 'engine');
});
const quotes = computed(() => selected.value ? [
    { label: mining.value ? 'Mine at' : 'Buy at', site: selected.value.source, quote: selected.value.prices?.source },
    { label: 'Sell at', site: selected.value.buyer, quote: selected.value.prices?.buyer, first: selected.value.first_seen },
] : []);
</script>

<template>
    <BridgeLayout :bridge="bridge" :title="selected ? commodity(selected.good) : 'Routes'" :tab="selected && openedFromShip ? 'fleet' : undefined" :poll="['routes', 'selectedRoute', 'attention']" :back="back" :routes="routes?.running?.length">
        <template #default="{ now, showReview }">
            <div class="bridge-detail-layout" :class="{ 'bridge-has-selection': selected }">
                <div class="bridge-list-pane">
                    <component :is="selected ? 'h2' : 'h1'" class="bridge-page-title">Routes</component>
                    <p v-if="!routeGroups.length && !routes?.ended?.count" class="bridge-empty">{{ held ? 'No routes are running while the fleet is held.' : 'No routes running yet. The plan is choosing its first trade.' }}</p>
                    <section v-for="group in routeGroups" :key="group.title">
                        <div class="bridge-section-head"><h2>{{ group.title }} · {{ group.entries.length }}</h2></div>
                        <div class="bridge-panel">
                            <RouteRow v-for="entry in group.entries" :key="entry.key || `${entry.good}-${entry.buyer}`" :entry="entry" :agent="agent" :selected="Boolean(selected && entry.key === selected.key)" :preserve="split" />
                        </div>
                    </section>
                    <section v-if="routes?.ended?.count">
                        <div class="bridge-section-head"><h2>Ended this wipe · {{ routes.ended.count }}</h2></div>
                        <div class="bridge-panel">
                            <Link class="bridge-row" :href="bridgeHref('routes.ended', agent)">
                                <span class="bridge-dot" aria-hidden="true"></span><span class="bridge-grow"><strong>Ended routes</strong><span class="bridge-detail">{{ routes.ended.count }} {{ routes.ended.count === 1 ? 'route' : 'routes' }} · net <ShortMoney :value="routes.ended.net" delta /></span></span><span class="bridge-chevron" aria-hidden="true">›</span>
                            </Link>
                        </div>
                    </section>
                    <p v-if="routeGroups.length || routes?.ended?.count" class="bridge-note">A route is one good bought at one market and sold at another. Rate is earnings per hour over the last two hours. Net is sales minus purchases and fuel this wipe.</p>
                </div>
                <div v-if="selected" class="bridge-selected-pane" :aria-label="commodity(selected.good)">
                    <h1 class="bridge-page-title">{{ commodity(selected.good) }}</h1>
                    <p class="bridge-page-sub">
                        <span class="bridge-pill" :class="statusTone"><span aria-hidden="true">●</span> {{ selected.status }}</span>
                        {{ mining ? 'Mine' : 'Buy' }} at {{ waypoint(selected.source) }}, sell at {{ waypoint(selected.buyer) }}
                    </p>
                    <AttentionCard v-if="card" :card="card" :agent="agent" :ship="selected.ships?.find(ship => ship.symbol === identity(card.ship).symbol)" primary @review="showReview" />
                    <dl class="bridge-panel bridge-kpis bridge-route-kpis">
                        <div><dt>Rate</dt><dd><ShortMoney :value="selected.rate" rate /></dd><dd class="bridge-detail">last 2 hours</dd></div>
                        <div><dt>Net</dt><dd><ShortMoney :value="selected.net" delta /></dd><dd class="bridge-detail">this wipe</dd></div>
                        <div><dt>Loads</dt><dd>{{ amount(selected.loads) }}</dd><dd v-if="selected.loads > 0" class="bridge-detail">avg <ShortMoney :value="selected.net / selected.loads" delta /></dd></div>
                    </dl>
                    <section v-if="selected.ships?.length">
                        <div class="bridge-section-head"><h3>Ships on this route · {{ selected.ships.length }}</h3></div>
                        <div class="bridge-panel">
                            <ShipRow v-for="ship in selected.ships" :key="ship.symbol" :ship="ship" :agent="agent" :from="`route:${selected.key}`" :now="now" />
                        </div>
                    </section>
                    <section>
                        <div class="bridge-section-head"><h3>Prices now</h3></div>
                        <dl class="bridge-panel">
                            <div v-for="quote in quotes" :key="quote.label" class="bridge-row">
                                <div class="bridge-grow">
                                    <dt><strong>{{ quote.label }} {{ waypoint(quote.site) }}</strong></dt>
                                    <dd class="bridge-detail">
                                        <time v-if="quote.quote?.observed_at" :datetime="quote.quote.observed_at" :title="fullTime(quote.quote.observed_at)">{{ elapsed(quote.quote.observed_at, now) }} · {{ clock(quote.quote.observed_at) }}</time>
                                        <span v-else>No current quote</span>
                                        <template v-if="validNumber(quote.first)"> · first seen at {{ amount(quote.first) }}</template>
                                    </dd>
                                </div>
                                <dd>{{ amount(quote.quote?.price) }}</dd>
                            </div>
                        </dl>
                    </section>
                    <section>
                        <div class="bridge-section-head"><h3>Last loads</h3><Link v-if="selected.loads && routeParts(selected.key) && !mining" class="bridge-link" :href="bridgeHref('log', agent, { filter: 'routes', route: selected.key })">All {{ amount(selected.loads) }} ›</Link></div>
                        <RecentLoads :rows="selected.recent" :ships="selected.ships" show-ship />
                    </section>
                </div>
            </div>
        </template>
    </BridgeLayout>
</template>
