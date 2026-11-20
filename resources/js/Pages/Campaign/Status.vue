<script setup>
import CampaignNavigation from '@/Components/CampaignNavigation.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import ShipScene from '@/Components/ShipScene.vue';
import ShipDetails from '@/Components/ShipDetails.vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { formatLocalClock, formatLocalTime } from '@/dateTime.js';
import { readableLabel, readableText } from '@/dataPresentation.js';
import { flightDeckLocation, shipPresentation } from '@/shipPresentation.js';
import { resourceSummary, equipmentSummary } from '@/shipFacts.js';
import { Head, useForm, usePoll } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps({
    grant: { type: Object, default: null },
    episode: { type: Object, default: null },
    health: { type: Object, required: true },
    currentRun: { type: Object, default: null },
    lastDecision: { type: Object, default: null },
    loads: { type: Array, default: () => [] },
    credits: { type: Array, default: () => [] },
    events: { type: Array, default: () => [] },
    ships: { type: Array, default: () => [] },
    updatedAt: { type: String, required: true },
    status: { type: String, default: null },
});

usePoll(15000);
const now = ref(Date.now());
let ticker;
onMounted(() => { ticker = setInterval(() => { now.value = Date.now(); }, 1000); });
onUnmounted(() => clearInterval(ticker));
const shipScenes = computed(() => props.ships.map(ship => ({
    ...ship,
    presentation: shipPresentation(ship.details ?? ship, ship.observed_at, 'connected', now.value),
    location: flightDeckLocation(ship.details ?? ship, ship.locations),
})));
const form = useForm({});
const number = new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 });
const amount = value => value === null || value === undefined ? 'Unknown' : `${number.format(value)} CR`;
const count = value => value === null || value === undefined ? 'Unknown' : number.format(value);
const netTone = value => value > 0 ? 'positive' : value < 0 ? 'negative' : '';
const label = value => value ? readableText(readableLabel(value)) : 'Unknown';
const state = computed(() => {
    if (!props.grant) return { label: 'Idle', tone: 'neutral', why: 'No campaign approved.' };
    if (props.grant.paused_at) return { label: 'Paused', tone: 'warning', why: label(props.grant.pause_reason) };
    if (problems.value.length) return { label: 'Needs attention', tone: 'warning', why: 'Review the health problems below.' };
    if (!props.grant.active) return { label: 'Idle', tone: 'neutral', why: 'Campaign authority is inactive.' };
    if (props.grant.wait_reason) return { label: 'Waiting', tone: 'neutral', why: label(props.grant.wait_reason) };
    if (!props.episode) return { label: 'Waiting', tone: 'neutral', why: 'Waiting for the first decision.' };
    if (props.currentRun?.blocked_reason) return { label: 'Waiting', tone: 'neutral', why: label(props.currentRun.blocked_reason) };
    if (props.currentRun?.state === 'waiting') return { label: 'Waiting', tone: 'neutral', why: 'Waiting for the next run step.' };
    if (props.currentRun && props.health.engine.state === 'running') return { label: 'Running', tone: 'good', why: 'The engine is carrying out the current run.' };
    return { label: 'Idle', tone: 'neutral', why: 'Waiting for the next decision.' };
});
const problems = computed(() => props.health.problems.filter(problem => !(props.grant?.paused_at && problem.key.startsWith('campaign_paused:'))));
const recentCredits = computed(() => props.credits.slice(-8));
const post = action => form.post(route(`campaign.${action}`), { preserveScroll: true });
</script>

<template>
    <Head title="Campaign status" />
    <AuthenticatedLayout>
        <CampaignNavigation />
        <template #header>
            <p class="app-eyebrow">CAMPAIGN AUTOPILOT</p>
            <h1>Campaign status</h1>
            <p class="app-muted">What the weekly autopilot is doing now. Refreshes every 15 seconds.</p>
        </template>

        <div class="app-stack campaign-status">
            <section class="app-panel" aria-labelledby="status-heading">
                <div class="status-heading">
                    <h2 id="status-heading">Status</h2>
                    <span class="status" :class="state.tone">{{ state.label }}</span>
                </div>
                <p :class="{ 'app-muted': !grant }">{{ state.why }}</p>
                <p v-if="!grant" class="app-muted">Approve a playbook with <code>php artisan campaign:approve &lt;revision&gt;</code>.</p>
                <ul v-if="problems.length" class="problems">
                    <li v-for="problem in problems" :key="problem.key">{{ label(problem.message) }}</li>
                </ul>
                <dl>
                    <div><dt>Engine</dt><dd>{{ label(health.engine.state) }}</dd></div>
                    <div><dt>Heartbeat</dt><dd><time v-if="health.engine.heartbeat_at" :datetime="health.engine.heartbeat_at">{{ formatLocalTime(health.engine.heartbeat_at) }}</time><span v-else>Never</span></dd></div>
                    <template v-if="grant">
                        <div><dt>Grant</dt><dd>#{{ grant.id }}</dd></div>
                        <div><dt>Revision</dt><dd>{{ grant.revision.name }} v{{ grant.revision.version }} · {{ grant.revision.hash }}</dd></div>
                        <div><dt>Expires</dt><dd><time v-if="grant.expires_at" :datetime="grant.expires_at">{{ formatLocalTime(grant.expires_at) }}</time><span v-else>No expiry</span></dd></div>
                        <div><dt>Last directed</dt><dd><time v-if="grant.last_directed_at" :datetime="grant.last_directed_at">{{ formatLocalTime(grant.last_directed_at) }}</time><span v-else>Never</span></dd></div>
                        <div v-if="grant.paused_at"><dt>Paused at</dt><dd><time :datetime="grant.paused_at">{{ formatLocalTime(grant.paused_at) }}</time></dd></div>
                        <div v-if="grant.pending_grant"><dt>Pending grant</dt><dd>#{{ grant.pending_grant }} · Waiting for a safe point</dd></div>
                    </template>
                </dl>
                <div v-if="grant && (grant.active || grant.paused_at)" class="app-form-actions">
                    <PrimaryButton v-if="grant.paused_at" :disabled="form.processing" @click="post('resume')">Resume campaign</PrimaryButton>
                    <DangerButton v-else :disabled="form.processing" @click="post('pause')">Pause campaign</DangerButton>
                </div>
                <p v-if="form.errors.campaign" class="app-error" role="status">{{ form.errors.campaign }}</p>
                <p v-else-if="status" class="app-success" role="status">{{ status }}</p>
                <p class="app-muted updated">Updated <time :datetime="updatedAt">{{ formatLocalTime(updatedAt) }}</time></p>
            </section>

            <section v-if="grant" class="app-panel" aria-labelledby="now-heading">
                <h2 id="now-heading">Now</h2>
                <p v-if="!episode" class="app-muted">Waiting for the first decision.</p>
                <dl v-else>
                    <div><dt>Phase</dt><dd>{{ label(episode.phase) }}</dd></div>
                    <div><dt>Current run</dt><dd>{{ currentRun ? `#${currentRun.id} · ${label(currentRun.strategy)}` : 'No active run.' }}</dd></div>
                    <template v-if="currentRun">
                        <div><dt>Run state</dt><dd>{{ label(currentRun.state) }}</dd></div>
                        <div><dt>Run phase</dt><dd>{{ label(currentRun.phase) }}<span v-if="currentRun.step !== null"> · Step {{ count(currentRun.step) }}</span></dd></div>
                        <div><dt>Ship</dt><dd>{{ currentRun.ship }}</dd></div>
                        <div><dt>Started</dt><dd><time v-if="currentRun.started_at" :datetime="currentRun.started_at">{{ formatLocalTime(currentRun.started_at) }}</time><span v-else>Not started</span></dd></div>
                        <div v-if="currentRun.blocked_reason"><dt>Blocked reason</dt><dd>{{ label(currentRun.blocked_reason) }}</dd></div>
                        <template v-if="currentRun.plan">
                            <div><dt>Good</dt><dd>{{ label(currentRun.plan.good) }}</dd></div>
                            <div><dt>Source</dt><dd>{{ currentRun.plan.source || 'Unknown' }}</dd></div>
                            <div><dt>Buyer</dt><dd>{{ currentRun.plan.buyer || 'Unknown' }}</dd></div>
                            <div><dt>Units</dt><dd>{{ count(currentRun.plan.units) }}</dd></div>
                        </template>
                    </template>
                </dl>
                <template v-if="lastDecision">
                    <h3>Last decision</h3>
                    <dl>
                        <div><dt>When</dt><dd><time :datetime="lastDecision.at">{{ formatLocalTime(lastDecision.at) }}</time></dd></div>
                        <div><dt>Choice</dt><dd>{{ label(lastDecision.choice) }}</dd></div>
                        <div v-if="lastDecision.run_id"><dt>Selected run</dt><dd>#{{ lastDecision.run_id }}</dd></div>
                    </dl>
                    <h3 v-if="lastDecision.rejections.length">Rejected choices</h3>
                    <dl v-if="lastDecision.rejections.length">
                        <div v-for="reason in lastDecision.rejections" :key="reason.reason"><dt>{{ label(reason.reason) }}</dt><dd>{{ count(reason.count) }}</dd></div>
                    </dl>
                </template>
                <p v-else-if="episode" class="app-muted">Waiting for the first decision.</p>
            </section>

            <section v-if="grant" class="app-panel" aria-labelledby="wipe-heading">
                <h2 id="wipe-heading">This wipe</h2>
                <template v-if="episode">
                    <dl>
                        <div><dt>Episode</dt><dd>#{{ episode.id }}</dd></div>
                        <div><dt>World reset</dt><dd>{{ episode.reset_id }}</dd></div>
                        <div><dt>Agent</dt><dd>{{ episode.agent ? `${episode.agent.name} #${episode.agent.id}` : 'Unknown' }}</dd></div>
                        <div><dt>Opened</dt><dd><time :datetime="episode.opened_at">{{ formatLocalTime(episode.opened_at) }}</time></dd></div>
                        <div><dt>Next reset</dt><dd><time :datetime="episode.next_reset_at">{{ formatLocalTime(episode.next_reset_at) }}</time></dd></div>
                        <div><dt>Hours elapsed</dt><dd>{{ count(episode.hours_elapsed) }}</dd></div>
                        <div><dt>Start credits</dt><dd>{{ amount(episode.start_credits) }}</dd></div>
                        <div><dt>Current credits</dt><dd>{{ amount(episode.current_credits) }}</dd></div>
                        <div><dt>Net credit change</dt><dd :class="netTone(episode.net)">{{ amount(episode.net) }}</dd></div>
                    </dl>
                    <p class="app-muted">Credit change is cash, not economic profit.</p>
                    <h3>Credit trend <span v-if="recentCredits.length" class="app-muted">· since {{ formatLocalClock(recentCredits[0].at) }}</span></h3>
                    <ol v-if="recentCredits.length" class="trend">
                        <li v-for="sample in recentCredits" :key="sample.at"><time :datetime="sample.at" :title="formatLocalTime(sample.at)">{{ formatLocalClock(sample.at) }}</time><span>{{ amount(sample.credits) }}</span></li>
                    </ol>
                    <p v-else class="app-muted">No credit samples yet.</p>
                </template>
                <p v-else class="app-muted">Waiting for the first decision.</p>
            </section>

            <section v-if="grant" class="app-panel" aria-labelledby="ships-heading">
                <h2 id="ships-heading">Ships</h2>
                <ul v-if="ships.length" class="records">
                    <li v-for="ship in shipScenes" :key="ship.symbol">
                        <h3>{{ ship.symbol }}</h3>
                        <ShipScene :location="ship.location" :reset="episode?.reset_id" :pose="ship.presentation.pose" :transit="ship.presentation.transit" :progress="ship.presentation.progress" compact :interactive="false" />
                        <dl>
                            <div><dt>Campaign role</dt><dd>{{ label(ship.role) }}</dd></div>
                            <div><dt>Registration role</dt><dd>{{ ship.details?.registration?.role ?? 'Not observed' }}</dd></div>
                            <div><dt>Frame</dt><dd>{{ ship.details?.frame?.name || ship.details?.frame?.symbol || 'Not observed' }}<template v-if="ship.details?.frame?.name && ship.details?.frame?.symbol"> · {{ ship.details.frame.symbol }}</template></dd></div>
                            <div><dt>Waypoint</dt><dd>{{ ship.waypoint || 'Unknown' }}</dd></div>
                            <div><dt>Navigation</dt><dd>{{ label(ship.nav_status) }}</dd></div>
                            <div><dt>Fuel</dt><dd>{{ resourceSummary(ship.fuel?.current, ship.fuel?.capacity, 'No fuel tank').text }}</dd></div>
                            <div><dt>Cargo</dt><dd>{{ resourceSummary(ship.cargo?.units, ship.cargo?.capacity, 'No cargo hold').text }}</dd></div>
                            <div><dt>Crew aboard / capacity</dt><dd>{{ resourceSummary(ship.details?.crew?.current, ship.details?.crew?.capacity, 'No crew capacity').text }}</dd></div>
                            <div><dt>Crew required</dt><dd>{{ count(ship.details?.crew?.required) }}</dd></div>
                            <div><dt>Mounts</dt><dd>{{ equipmentSummary(ship.details?.mounts) }}</dd></div>
                            <div><dt>Modules</dt><dd>{{ equipmentSummary(ship.details?.modules) }}</dd></div>
                            <div><dt>Cooldown readiness</dt><dd>{{ ship.presentation.age === 'Unknown' ? 'Not observed' : ship.presentation.stale ? 'Last observed: ' + ship.presentation.readiness : ship.presentation.readiness }}</dd></div>
                            <div><dt>Ship observed</dt><dd><time v-if="ship.observed_at" :datetime="ship.observed_at">{{ formatLocalTime(ship.observed_at) }}</time><span v-else>Not observed</span><span v-if="ship.observed_at && ship.presentation.stale"> · Last known state</span></dd></div>
                        </dl>
                        <ShipDetails :ship="ship.details" :unlisted-fields="ship.unlisted_fields" />
                    </li>
                </ul>
                <p v-else class="app-muted">No fleet ships saved yet.</p>
            </section>

            <section v-if="grant" class="app-panel" aria-labelledby="loads-heading">
                <h2 id="loads-heading">Recent loads</h2>
                <ul v-if="loads.length" class="records">
                    <li v-for="(load, index) in loads" :key="index">
                        <h3>{{ label(load.good) }} · {{ label(load.mode) }}</h3>
                        <time :datetime="load.at">{{ formatLocalTime(load.at) }}</time>
                        <dl>
                            <div><dt>Buyer</dt><dd>{{ load.buyer || 'Unknown' }}</dd></div>
                            <div><dt>Units</dt><dd>{{ count(load.units) }}</dd></div>
                            <div><dt>Predicted margin</dt><dd>{{ amount(load.predicted_margin) }}</dd></div>
                            <div><dt>Net</dt><dd :class="netTone(load.net)">{{ amount(load.net) }}</dd></div>
                            <div><dt>Result</dt><dd :class="{ negative: load.losing }">{{ load.losing ? 'Losing load' : 'Not losing' }}</dd></div>
                        </dl>
                    </li>
                </ul>
                <p v-else class="app-muted">No completed loads yet.</p>
            </section>

            <section v-if="grant" class="app-panel" aria-labelledby="events-heading">
                <h2 id="events-heading">Recent events</h2>
                <ul v-if="events.length" class="records">
                    <li v-for="(event, index) in events" :key="index">
                        <h3>{{ label(event.kind) }}</h3>
                        <time :datetime="event.at">{{ formatLocalTime(event.at) }}</time>
                        <p v-if="event.summary">{{ label(event.summary) }}</p>
                    </li>
                </ul>
                <p v-else class="app-muted">No campaign events yet.</p>
            </section>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
.status-heading { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.status { padding: 4px 8px; border: 1px solid #58708b; font-size: 12px; }
.status.good { background: #123029; color: var(--color-accent); border-color: #78c6a4; }
.status.neutral { background: #18283b; color: #c9d7e8; }
.status.warning { background: #302819; border-color: #bd9e61; color: #ffe09a; }
.campaign-status h2 { margin-bottom: 12px; }
.campaign-status h3 { margin-top: 20px; font-size: 15px; font-weight: 700; }
.campaign-status .ship-scene { margin: 12px 0; }
.campaign-status dl { display: grid; gap: 14px; margin: 24px 0; font-size: 13px; }
.campaign-status dl > div { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr); gap: 12px; }
.campaign-status dt, .records time { color: var(--color-muted); }
.campaign-status dd { text-align: right; min-width: 0; overflow-wrap: anywhere; }
.records > li { min-width: 0; overflow-wrap: anywhere; }
.positive { color: var(--color-accent); }
.negative { color: var(--color-fault); }
.problems { color: #ffe09a; }
.problems { padding-left: 20px; margin-top: 16px; }
.updated { margin-top: 20px; font-size: 12px; }
.records, .trend { list-style: none; padding: 0; }
.records > li + li { margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--color-line); }
.records time, .trend { font-size: 13px; }
.trend { margin-top: 12px; }
.trend li { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr); gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--color-line); }
.trend span { text-align: right; }
</style>
