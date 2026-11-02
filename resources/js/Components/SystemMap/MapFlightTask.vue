<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatLocalTime } from '@/dateTime.js';
import { assetName, readableLabel } from '@/dataPresentation.js';
const props = defineProps({ task: Object, agentId: Number, ship: Object, disabled: Boolean, blockedReason: String, reserveFloor: Number, now: Number, actions: Array });
const emit = defineEmits(['preview', 'sync', 'stop', 'reconcile', 'activity']);
const reserve = ref(props.reserveFloor), cooldown = ref(300), question = ref('MARKETPLACE'), manualLimits = ref(false), maxFuel = ref(''), maxSeconds = ref(''), acknowledge = ref(false);
const current = computed(() => props.ship?.nav?.waypointSymbol === props.task.point.symbol);
const transit = computed(() => props.ship?.nav?.status === 'IN_TRANSIT');
const docked = computed(() => props.ship?.nav?.status === 'DOCKED');
const orbital = computed(() => props.ship?.nav?.status === 'IN_ORBIT');
const sensor = computed(() => props.ship?.mounts?.some(mount => mount.symbol.startsWith('MOUNT_SENSOR_ARRAY_')));
const ready = computed(() => Number.isFinite(Date.parse(props.ship?.cooldown?.readyAt)) && props.now >= Date.parse(props.ship.cooldown.readyAt));
const action = computed(() => props.actions.find(row => row.ship_symbol === props.task.ship_symbol));
const navReason = computed(() => transit.value ? 'In transit. Arrival must be confirmed by a fresh ship observation.' : current.value ? 'This is your observed location. Choose another location on the map to travel.' : docked.value ? 'Enter orbit first. Orbit and travel are separate approvals.' : !orbital.value ? 'Refresh ship status to confirm orbit before travel.' : '');
const scanReason = computed(() => props.task.point.traits !== null ? 'Traits are already known. Fetch waypoint details to check for updates.' : !sensor.value ? 'No Sensor Array installed on this ship.' : !orbital.value ? 'Enter orbit before scanning.' : !ready.value ? 'Cooldown readiness is not confirmed yet. Refresh ship status after the deadline.' : '');
const requiresManualLimits = computed(() => props.ship?.nav?.flightMode !== 'CRUISE' || props.ship?.fuel?.capacity === 0);
const chartReason = computed(() => !current.value ? 'Travel to this location before charting it.' : props.task.point.traits !== null ? 'Traits are already known. Charting only works at an uncharted location.' : !orbital.value ? 'Enter orbit before charting.' : !ready.value ? 'Refresh ship status after cooldown readiness.' : '');
function preview(command) {
    const args = { reserve: reserve.value === '' ? props.reserveFloor : Number(reserve.value) };
    if (command === 'navigate' || command === 'chart') args.waypointSymbol = props.task.point.symbol;
    if (['scan_waypoints', 'chart'].includes(command)) Object.assign(args, { waypointSymbol: props.task.point.symbol, question: question.value, maxCooldownSeconds: Number(cooldown.value) });
    if (command === 'navigate' && manualLimits.value) Object.assign(args, { maxFuel: Number(maxFuel.value), maxSeconds: Number(maxSeconds.value), acknowledgeUnknown: acknowledge.value });
    emit('preview', { command, arguments: args });
}
</script>
<template>
    <section class="map-flight-task" aria-label="Map action">
        <p class="task-identity">{{ task.ship_symbol }} → {{ task.point.symbol }}</p>
        <p>{{ assetName(ship?.nav?.status) }} · Observed at {{ ship?.nav?.waypointSymbol || 'Unknown' }} · Fuel {{ ship?.fuel?.current ?? 'Unknown' }} / {{ ship?.fuel?.capacity ?? 'Unknown' }} · {{ assetName(ship?.nav?.flightMode) }}</p>
        <Link class="task-return" :href="route('agents.map', { agent: agentId, ship: task.ship_symbol, location: task.point.symbol })">Back to selected map location</Link>
        <p v-if="blockedReason" role="status" class="warning">{{ blockedReason }} <button type="button" class="task-return" @click="emit('activity')">Review activity</button></p>
        <section v-if="action" class="task-status" aria-label="Latest ship action">
            <p>#{{ action.id }} · {{ readableLabel(action.command) }} · {{ readableLabel(action.state) }}</p>
            <p v-if="action.message">{{ action.message }}</p>
            <p v-if="action.command === 'navigate' && action.state === 'confirmed'">The navigation response was confirmed. Transit and arrival still depend on fresh ship status.</p>
            <div class="task-buttons"><SecondaryButton v-if="['preview', 'pending', 'sending'].includes(action.state)" :disabled="disabled" @click="emit('stop', action)">Stop manual command</SecondaryButton><SecondaryButton v-if="action.state === 'uncertain' || action.review_required" :disabled="disabled" @click="emit('reconcile', action)">Reconcile action</SecondaryButton><SecondaryButton @click="emit('activity')">Activity history</SecondaryButton></div>
        </section>
        <SecondaryButton :disabled="disabled" @click="emit('sync')">Refresh ship status</SecondaryButton>
        <p v-if="orbital && action?.command === 'orbit' && action?.state === 'confirmed'" role="status">Orbit confirmed. A new {{ task.action === 'travel' ? 'travel' : 'discovery' }} preview is available.</p>
        <p v-if="transit" role="status">In transit. Arrival unconfirmed. Refresh after {{ formatLocalTime(ship.nav.route?.arrival) }}. The client clock never confirms arrival.</p>
        <fieldset :disabled="disabled || !!blockedReason">
            <legend class="sr-only">Review one command</legend>
            <section v-if="docked && !transit">
                <h3>1. Enter orbit</h3><p>Approve orbit first, then return here for a fresh {{ task.action === 'travel' ? 'travel' : 'discovery' }} preview. Nothing continues automatically.</p>
                <PrimaryButton @click="preview('orbit')">Preview entering orbit</PrimaryButton>
            </section>
            <template v-if="task.action === 'travel'">
                <h3>{{ docked ? '2. ' : '' }}Travel to {{ task.point.symbol }}</h3>
                <p v-if="navReason" id="map-travel-reason">{{ navReason }}</p>
                <p>Preview checks the route, fuel and protected credits with the server. Approval queues one navigation command, with no automatic refuel or return trip.</p>
                <label>Protected credits in CR<input v-model="reserve" type="number" min="0" max="1000000000" step="1"></label>
                <p v-if="requiresManualLimits" class="warning">This ship needs explicit fuel and time review limits below, with acknowledgement, before travel can be previewed.</p>
                <label class="task-check"><input v-model="manualLimits" type="checkbox">Use explicit fuel and time review limits</label>
                <form @submit.prevent="preview('navigate')">
                    <div v-if="manualLimits">
                        <p class="warning">Use this only after reviewing the route. These thresholds cannot cap server fuel use or travel time. No continuation is verified; the ship can be stranded.</p>
                        <label>Maximum tank fuel use for review<input v-model="maxFuel" type="number" min="0" :max="ship?.fuel?.current ?? 0" step="1" required></label>
                        <label>Maximum travel seconds for review<input v-model="maxSeconds" type="number" min="1" max="86400" step="1" required></label>
                        <label class="task-check"><input v-model="acknowledge" type="checkbox" required>I acknowledge uncalibrated travel and no verified continuation</label>
                    </div>
                    <PrimaryButton type="submit" :disabled="!!navReason" aria-describedby="map-travel-reason">Preview travel</PrimaryButton>
                </form>
            </template>
            <template v-else>
                <label>What do you want to learn?<select v-model="question"><option value="MARKETPLACE">Is there a marketplace?</option><option value="SHIPYARD">Is there a shipyard?</option></select></label>
                <p>A verified affordable fuel continuation is required. One command answers this question, then stops. No travel or spending is approved.</p>
                <label>Protected credits in CR<input v-model="reserve" type="number" min="0" max="1000000000" step="1"></label>
                <h3>Chart this location</h3><p>Reveals hidden traits at your current uncharted location and publishes a chart. No Sensor Array is required. The client requires orbit and ready cooldown.</p>
                <p id="map-chart-reason">{{ chartReason }}</p>
                <PrimaryButton :disabled="!!chartReason" aria-describedby="map-chart-reason" @click="preview('chart')">Preview charting location</PrimaryButton>
                <h3>Scan nearby locations</h3><p>One Sensor Array scan from the ship's current location. It can reveal uncharted traits in range. Range is unknown and {{ task.point.symbol }} may not be returned. No automatic rescan.</p>
                <p id="map-scan-reason">{{ scanReason }}</p>
                <form @submit.prevent="preview('scan_waypoints')">
                    <label>Cooldown review threshold in seconds<input v-model="cooldown" type="number" min="0" max="900" step="1" required></label>
                    <p>This is a review threshold, not a server cap. The response determines the cooldown.</p>
                    <PrimaryButton type="submit" :disabled="!!scanReason" aria-describedby="map-scan-reason">Preview nearby scan</PrimaryButton>
                </form>
            </template>
        </fieldset>
    </section>
</template>
<style scoped>
.task-identity { color: var(--color-accent); font-weight: 800; font-size: 18px; } p { margin: 12px 0; } h3 { margin: 24px 0 12px; font-weight: 800; } .task-return { display: inline-flex; align-items: center; min-height: 44px; text-decoration: underline; color: var(--color-accent); } fieldset { min-width: 0; } label { display: grid; gap: 8px; margin: 16px 0; } select, input[type=number] { width: 100%; min-height: 44px; background: var(--color-field); color: var(--color-ink); border: 1px solid #67829f; border-radius: 10px; padding: 10px; font: inherit; } .task-check { grid-template-columns: 24px 1fr; align-items: start; min-height: 44px; } input[type=checkbox] { width: 24px; height: 24px; background-color: var(--color-field); border: 1px solid #67829f; color: #a3f0d3; } input:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 3px; } .warning { color: #f8d99b; } .task-status { border: 1px solid var(--color-line); border-radius: 12px; padding: 12px; margin: 16px 0; } .task-buttons { display: flex; flex-wrap: wrap; gap: 8px; }
</style>
