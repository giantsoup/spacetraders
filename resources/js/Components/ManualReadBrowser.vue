<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import DataDetails from '@/Components/DataDetails.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { readableLabel } from '@/dataPresentation.js';

const props = defineProps({ agent: Object, ships: Array, selected: String, operations: Object, enums: Object, disabled: Boolean });
const operation = ref('get-status');
const form = reactive({ systemSymbol: '', waypointSymbol: '', shipSymbol: '', factionSymbol: 'COSMIC', agentSymbol: '', contractId: '', page: 1, limit: 10, type: '', traits: [], traitForm: 'array' });
const definition = computed(() => props.operations[operation.value]);
const result = ref(null), error = ref(''), busy = ref(false), results = ref(null);
const labels = { systemSymbol: 'System', waypointSymbol: 'Waypoint', shipSymbol: 'Owned ship', factionSymbol: 'Faction', agentSymbol: 'Public agent symbol', contractId: 'Contract ID' };
const rows = computed(() => Array.isArray(result.value?.data) ? result.value.data : null);
watch(operation, () => { result.value = null; error.value = ''; form.page = 1; });
watch(() => props.selected, value => {
    form.shipSymbol = value;
    const ship = props.ships.find(row => row.symbol === value)?.snapshot;
    form.systemSymbol = ship?.nav?.systemSymbol || '';
    form.waypointSymbol = ship?.nav?.waypointSymbol || '';
    result.value = null; form.page = 1;
}, { immediate: true });
async function read(page = form.page, continuation = false) {
    const saved = continuation ? result.value?.parameters : null;
    busy.value = true; error.value = ''; result.value = null;
    let parameters = Object.fromEntries(definition.value[1].map(field => [field, form[field]]));
    if (definition.value[2]) Object.assign(parameters, { page: Number(page), limit: Number(form.limit) });
    if (operation.value === 'get-system-waypoints') {
        if (form.type) parameters.type = form.type;
        if (form.traits.length) parameters.traits = form.traitForm === 'scalar' ? form.traits[0] : [...form.traits];
    }
    if (saved) parameters = { ...saved, page: Number(page) };
    try {
        const response = await window.axios.post(route('game.reads', props.agent.id), { operation: operation.value, parameters });
        result.value = response.data; form.page = Number(page);
        await nextTick(); results.value?.focus();
    } catch (cause) { error.value = cause.response?.data?.message || 'Read unavailable. Retry this page when the connection or request budget allows.'; }
    finally { busy.value = false; }
}
</script>

<template>
    <section class="panel read-browser" aria-labelledby="read-browser-title">
        <h2 id="read-browser-title">API library</h2>
        <p>Refresh one named read at a time. Missing prices or fields remain unknown. Public agent profiles give no authority to command their ships.</p>
        <form @submit.prevent="read()">
            <fieldset :disabled="busy || disabled"><legend>Choose information to inspect</legend>
                <label>Read operation<select v-model="operation"><option v-for="(entry, id) in operations" :key="id" :value="id">{{ entry[0] }}</option></select></label>
                <div class="fields">
                    <label v-for="field in definition[1]" :key="field">{{ labels[field] }}
                        <select v-if="field === 'shipSymbol'" v-model="form[field]" required><option value="">Select owned ship</option><option v-for="ship in ships" :key="ship.symbol">{{ ship.symbol }}</option></select>
                        <select v-else-if="field === 'factionSymbol'" v-model="form[field]" required><option v-for="faction in enums.FactionSymbol" :key="faction">{{ faction }}</option></select>
                        <input v-else v-model="form[field]" required maxlength="80" :placeholder="field === 'systemSymbol' ? 'X1-AB12' : field === 'waypointSymbol' ? 'X1-AB12-C3' : ''">
                    </label>
                    <template v-if="definition[2]"><label>Page<input v-model="form.page" type="number" min="1" max="100000000" required></label><label>Results per page<input v-model="form.limit" type="number" min="1" max="20" required></label></template>
                    <template v-if="operation === 'get-system-waypoints'">
                        <label>Waypoint type<select v-model="form.type"><option value="">All types</option><option v-for="type in enums.WaypointType" :key="type">{{ type }}</option></select></label>
                        <label>Trait filters<select v-model="form.traits" multiple size="5"><option v-for="trait in enums.WaypointTraitSymbol" :key="trait">{{ trait }}</option></select></label>
                        <label>Trait input<select v-model="form.traitForm"><option value="array">All selected traits</option><option value="scalar">First trait as scalar</option></select></label>
                    </template>
                </div>
                <PrimaryButton type="submit">{{ busy ? 'Reading…' : 'Refresh selected read' }}</PrimaryButton>
            </fieldset>
        </form>
        <p v-if="error" role="alert" class="warning">{{ error }}</p>
        <section v-if="result" ref="results" tabindex="-1" aria-label="Read results" class="results">
            <p>Observed <time :datetime="result.observed_at">{{ formatLocalTime(result.observed_at) }}</time> · {{ localTimeZone }}. {{ result.source === 'official_api' ? 'API response' : result.source }}</p>
            <p v-if="result.no_content">HTTP 204. No active cooldown was returned.</p>
            <p v-if="result.deadline">Readiness {{ formatLocalTime(result.deadline.readyAt) }} · {{ readableLabel(result.deadline.deadlineSource) }}. Raw expiration stays unchanged below.</p>
            <p v-if="['get-repair-ship', 'get-scrap-ship'].includes(operation)" class="warning">This quote does not reserve a price. Execution requires a separate preview and approval.</p>
            <p v-if="result.meta">Page {{ result.meta.page }} · {{ result.meta.total }} total · This response contains only this page.</p>
            <div v-if="result.meta" class="buttons"><SecondaryButton :disabled="busy || result.meta.page <= 1" @click="read(result.meta.page - 1, true)">Previous page</SecondaryButton><SecondaryButton :disabled="busy || result.meta.page * result.meta.limit >= result.meta.total" @click="read(result.meta.page + 1, true)">Next page</SecondaryButton></div>
            <p v-if="rows?.length === 0">No results on this page.</p>
            <ol v-if="rows"><li v-for="(row, index) in rows" :key="row.symbol || row.id || index"><details><summary>{{ row.symbol || row.id || `Item ${index + 1}` }} <span v-if="row.type">· {{ readableLabel(row.type) }}</span><span v-if="row.isUnderConstruction"> · Under construction</span></summary><DataDetails :value="row" /></details></li></ol>
            <DataDetails v-else-if="!result.no_content" :value="result.data" />
        </section>
    </section>
</template>

<style scoped>
h2 { color: var(--color-accent); font-weight: 800; } p { margin: 12px 0; }
fieldset { min-width: 0; } legend { color: var(--color-muted); margin-bottom: 12px; }
label { display: grid; gap: 8px; margin-bottom: 16px; } .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
input, select { width: 100%; min-height: 44px; background: var(--color-field); color: var(--color-ink); border: 1px solid #67829f; border-radius: 10px; padding: 10px; font: inherit; }
input:focus-visible, select:focus-visible, summary:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 3px; }
li { padding: 12px 0; border-bottom: 1px solid #40546b; } summary { cursor: pointer; min-height: 44px; overflow-wrap: anywhere; } .warning { color: #f8d99b; } .buttons { display: flex; gap: 12px; flex-wrap: wrap; } .results { margin-top: 24px; }
@media (max-width: 650px) { .fields { grid-template-columns: 1fr; } }
</style>
