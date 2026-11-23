<script setup>
import AttentionCard from '@/Components/Bridge/AttentionCard.vue';
import ProbeSummaryRow from '@/Components/Bridge/ProbeSummaryRow.vue';
import RecentLoads from '@/Components/Bridge/RecentLoads.vue';
import ShipName from '@/Components/Bridge/ShipName.vue';
import ShipRow from '@/Components/Bridge/ShipRow.vue';
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import ShipScene from '@/Components/ShipScene.vue';
import BridgeLayout from '@/Layouts/BridgeLayout.vue';
import { amount, clock, commodity, currentFrom, detailBack, fullTime, identity, routeHref, routeParts, until, useSplitView, waypoint } from '@/bridgeFormat.js';
import { flightDeckLocation, shipPresentation } from '@/shipPresentation.js';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ bridge: Object, attention: Object, fleet: Object, selectedShip: Object });
const page = usePage();
const split = useSplitView();
const agent = computed(() => props.bridge.agent?.symbol);
const from = computed(() => currentFrom(page.url));
const openedFromRoute = computed(() => from.value?.startsWith('route:') && routeParts(from.value.slice(6)));
const back = computed(() => props.selectedShip && detailBack(from.value, 'fleet', agent.value));
const groups = computed(() => props.fleet?.groups?.filter(group => group.count) || []);
const ship = computed(() => props.selectedShip);
const showStatus = computed(() => ship.value?.group === 'Stopped' || ship.value?.nav_status !== 'IN_TRANSIT');
const location = computed(() => flightDeckLocation(ship.value?.details, ship.value?.locations || []));
const card = computed(() => {
    if (ship.value?.group !== 'Stopped') return null;
    const cards = props.attention?.cards || [];
    return cards.find(item => identity(item.ship).symbol === ship.value.symbol || (item.grant_id && item.grant_id === ship.value.grant_id))
        ?? cards.find(item => item.scope === 'agent');
});
const routeKey = computed(() => routeParts(ship.value?.route_key));

function present(now) {
    return shipPresentation(ship.value.details, ship.value.observed_at, props.bridge.agent?.connection_state, now);
}

function sceneLabel(now) {
    const presentation = present(now);
    return `${identity(ship.value).name} ${presentation.transit ? 'in transit to ' + waypoint(location.value.symbol) : presentation.status.toLowerCase()}`;
}
</script>

<template>
    <BridgeLayout :bridge="bridge" :title="ship?.name || 'Fleet'" :tab="ship && openedFromRoute ? 'routes' : undefined" :poll="['fleet', 'selectedShip', 'attention']" :back="back" :ships="fleet?.count">
        <template #default="{ now, showReview }">
            <div class="bridge-detail-layout" :class="{ 'bridge-has-selection': ship }">
                <div class="bridge-list-pane">
                    <component :is="ship ? 'h2' : 'h1'" class="bridge-page-title">Fleet<template v-if="fleet?.count"> · {{ fleet.count }} ships</template></component>
                    <p v-if="!groups.length" class="bridge-empty">No ships to show for this wipe.</p>
                    <section v-for="group in groups" :key="group.name">
                        <div class="bridge-section-head"><h2>{{ group.name }} · {{ group.count }}</h2></div>
                        <div class="bridge-panel">
                            <ProbeSummaryRow v-if="group.name === 'Probes' && fleet?.probes" :probes="fleet.probes" :agent="agent" />
                            <ShipRow v-for="entry in group.ships" :key="entry.symbol" :ship="entry" :agent="agent" :now="now" :preserve="split" :selected="ship?.symbol === entry.symbol" />
                        </div>
                    </section>
                </div>
                <div v-if="ship" class="bridge-selected-pane" :aria-label="ship.name">
                    <h1 class="bridge-page-title">{{ ship.name }}</h1>
                    <p class="bridge-page-sub"><span class="bridge-callsign">{{ ship.symbol }}</span><span v-if="ship.purpose" class="bridge-pill">{{ ship.purpose }}</span><span v-if="ship.owner && ship.owner !== 'C0'" class="bridge-detail">arm {{ ship.owner }}</span></p>
                    <AttentionCard v-if="card" :card="card" :agent="agent" :ship="card.ship ? ship : undefined" primary @review="showReview" />
                    <figure v-if="ship.details" class="bridge-panel bridge-ship-scene" role="img" :aria-label="sceneLabel(now)">
                        <ShipScene :location="location" :reset="bridge.agent?.reset_id" :pose="present(now).pose" :transit="present(now).transit" :progress="present(now).progress" compact :interactive="false" />
                    </figure>
                    <dl class="bridge-panel bridge-ship-facts">
                        <div class="bridge-row"><dt>Now</dt><dd>{{ showStatus ? (ship.status_text || present(now).status) : 'In transit to ' + waypoint(location.symbol) }}</dd></div>
                        <div v-if="ship.arrival_at && ship.nav_status === 'IN_TRANSIT'" class="bridge-row"><dt>Arrival</dt><dd><time :datetime="ship.arrival_at" :title="fullTime(ship.arrival_at)">{{ until(ship.arrival_at, now) }} · {{ clock(ship.arrival_at) }}</time></dd></div>
                        <div v-if="routeKey" class="bridge-ship-route">
                            <dt>Route</dt>
                            <dd><Link class="bridge-link" :href="routeHref(ship.route_key, agent, `ship:${ship.symbol}`)">{{ commodity(routeKey.good) }} · {{ waypoint(routeKey.source) }} → {{ waypoint(routeKey.buyer) }} <span aria-hidden="true">›</span></Link></dd>
                        </div>
                        <div class="bridge-row"><dt>Cargo</dt><dd>{{ amount(ship.cargo?.units) }} / {{ amount(ship.cargo?.capacity) }}</dd></div>
                        <div class="bridge-row"><dt>Fuel</dt><dd>{{ amount(ship.fuel?.current) }} / {{ amount(ship.fuel?.capacity) }}</dd></div>
                    </dl>
                    <section v-if="ship.totals">
                        <div class="bridge-section-head"><h3>This wipe</h3></div>
                        <dl class="bridge-panel bridge-kpis bridge-route-kpis">
                            <div><dt>Loads</dt><dd>{{ amount(ship.totals.loads) }}</dd></div>
                            <div><dt>Net</dt><dd><ShortMoney :value="ship.totals.net" delta /></dd></div>
                            <div><dt>Last load</dt><dd><ShortMoney :value="ship.totals.last_net" delta /></dd><dd v-if="ship.totals.last_at" class="bridge-detail"><time :datetime="ship.totals.last_at" :title="fullTime(ship.totals.last_at)">{{ clock(ship.totals.last_at) }}</time></dd></div>
                        </dl>
                    </section>
                    <section>
                        <div class="bridge-section-head"><h3>Recent</h3></div>
                        <RecentLoads :rows="ship.recent" />
                    </section>
                    <Link v-if="ship.report_href" class="bridge-link bridge-report-link" :href="ship.report_href">Full ship report on the flight deck ›</Link>
                </div>
            </div>
        </template>
    </BridgeLayout>
</template>
