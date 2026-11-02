<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import FocusSheet from '@/Components/FocusSheet.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
const props = defineProps({ open: Boolean, agent: Object, ship: Object, point: Object, system: String });
defineEmits(['close']);
const busy = ref(''), notice = ref(''), error = ref(false);
watch(() => props.open, () => { notice.value = ''; error.value = false; });
async function read(operation) {
    if (busy.value || props.agent.connection_state === 'reset') return;
    busy.value = operation; notice.value = ''; error.value = false;
    const symbol = props.point.symbol;
    try {
        await window.axios.post(route('game.reads', props.agent.id), { operation, parameters: { systemSymbol: props.system, waypointSymbol: symbol } });
        await new Promise(resolve => router.reload({ only: ['map'], onFinish: resolve }));
        notice.value = operation === 'get-waypoint' && props.point.traits === null ? 'Details saved. Traits are still unknown. Only a chart or scan can reveal uncharted traits.' : `Saved the returned information for ${symbol}. Missing fields remain unknown.`;
    } catch (cause) {
        error.value = true;
        notice.value = cause.response?.data?.message || 'Information could not be fetched. Your stored observations are still available. Try again when the connection allows.';
        if (cause.response?.data?.retry_after) notice.value += ` Try again in ${cause.response.data.retry_after} seconds.`;
    } finally { busy.value = ''; }
}
</script>
<template>
    <FocusSheet :open="open" title="Get location information" @close="$emit('close')">
        <div class="location-information">
            <p class="task-identity">{{ point.symbol }} · {{ ship?.symbol }}</p>
            <p>Fetch one API read and save its returned fields. This does not move your ship or use a scan cooldown. Uncharted traits stay hidden.</p>
            <p v-if="agent.connection_state === 'reset'" role="status">Archived agent. New information is unavailable.</p>
            <ul class="information-gaps"><li>Traits: {{ point.traits === null ? 'Unknown, discovery may be needed' : 'Stored, fetch details to check for updates' }}</li><li>Market prices: {{ point.market.prices?.data.tradeGoods?.length ? 'Stored, may need refreshing' : 'Unknown' }}</li><li>Shipyard offers: {{ point.shipyard.offers?.data.ships?.length ? 'Stored, may need refreshing' : 'Unknown' }}</li></ul>
            <fieldset :disabled="busy || agent.connection_state === 'reset'">
                <legend class="map-sr">Choose a read to fetch</legend>
                <PrimaryButton @click="read('get-waypoint')">{{ busy === 'get-waypoint' ? 'Fetching…' : 'Fetch waypoint details' }}</PrimaryButton>
                <SecondaryButton v-if="point.market.presence === 'observed'" @click="read('get-market')">{{ busy === 'get-market' ? 'Fetching…' : 'Fetch market information' }}</SecondaryButton>
                <SecondaryButton v-if="point.shipyard.presence === 'observed'" @click="read('get-shipyard')">{{ busy === 'get-shipyard' ? 'Fetching…' : 'Fetch shipyard information' }}</SecondaryButton>
            </fieldset>
            <p>Market prices and detailed ship offers require a ship at the location. A remote read may return only a directory.</p>
            <p v-if="notice" :role="error ? 'alert' : 'status'" :class="{ warning: error }">{{ notice }}</p>
            <hr v-if="point.traits === null">
            <h3 v-if="point.traits === null">Discover hidden traits</h3>
            <p v-if="point.traits === null">Chart your current location, or scan nearby locations with a Sensor Array. Each command needs its own preview and approval. A scan may not include this location.</p>
            <Link v-if="point.traits === null && ship && agent.connection_state !== 'reset'" class="map-button" :href="route('agents.show', { agent: agent.id, ship: ship.symbol, map_action: 'discover', map_waypoint: point.symbol }) + '#mapTask'">Review discovery options</Link>
        </div>
    </FocusSheet>
</template>
<style scoped>
.information-gaps { padding-left: 20px; list-style: disc; margin: 0 0 16px; } li { margin: 8px 0; } p { margin: 0 0 16px; } .task-identity { font-weight: 700; color: var(--color-accent); } fieldset { display: grid; gap: 12px; min-width: 0; margin-bottom: 16px; } button { justify-content: center; } hr { border-color: var(--color-line); margin: 24px 0; } h3 { font-weight: 700; margin-bottom: 12px; } .warning { color: var(--color-warning); }
</style>
