<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DataDetails from '@/Components/DataDetails.vue';

const props = defineProps({ agentId: Number, cycles: Array });
const references = computed(() => props.cycles.filter(row => ['realized_comparable', 'estimated_comparable'].includes(row.accounting.state)));
const fields = {
    base_net: 'Base fleet net after all costs and wear in CR', conservative_net: 'Conservative fleet net after all costs and wear in CR',
    elapsed_seconds: 'Fleet elapsed seconds', ship_seconds: 'Total occupied ship-seconds',
    ship_capital: 'Added ship capital in CR', equipment_capital: 'Added equipment capital in CR',
    production_units: 'Units produced during one hauler round trip', round_trip_seconds: 'Hauler round trip in seconds',
    hauler_capacity: 'Hauler cargo capacity in units', buyer_capacity: 'Buyer capacity during one round trip in units',
};
const form = reactive(Object.fromEntries(Object.keys(fields).map(key => [key, ''])));
const reference = ref(''), note = ref(''), acknowledged = ref(false), busy = ref(false), error = ref(''), result = ref(null);
const number = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 });
watch([form, reference, note, acknowledged], () => { result.value = null; });
async function compare() {
    busy.value = true;
    error.value = '';
    result.value = null;
    try {
        const inputs = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, value === '' ? null : Number(value)]));
        result.value = (await window.axios.post(`/agents/${props.agentId}/economics/investment`, { ...inputs, reference_run: Number(reference.value), basis_note: note.value, costs_complete: acknowledged.value })).data;
    } catch (failure) {
        error.value = Object.values(failure.response?.data?.errors || {}).flat().join(' ') || failure.response?.data?.message || 'Comparison failed.';
    } finally { busy.value = false; }
}
</script>

<template>
    <details class="fleet-comparison"><summary>Compare a fleet investment</summary>
        <p>Enter a base case and a conservative case for the same closed cycle as the reference. Include positioning, return, fuel replacement, inventory and wear. Leave unknown inputs blank. These calculations do not save a policy or approve a purchase.</p>
        <p v-if="!references.length">A comparable completed current-assets run is required before calculating incremental payback.</p>
        <details v-else :open="!result"><summary>Operating case inputs</summary><form @submit.prevent="compare">
            <div class="comparison-fields">
                <label>Reference closed cycle<select v-model="reference" aria-label="Reference closed cycle" required><option value="">Select a measured cycle</option><option v-for="row in references" :key="row.run_id" :value="row.run_id">Run {{ row.run_id }} · {{ row.site }} → {{ row.market }} · {{ row.ship }}</option></select></label>
                <label v-for="(label, key) in fields" :key="key">{{ label }}<input v-model="form[key]" type="number" step="1" :min="key.endsWith('_net') ? undefined : 0" :required="key === 'elapsed_seconds'"></label>
                <label>Source and assumptions<textarea v-model="note" required minlength="10" maxlength="500"></textarea></label>
            </div>
            <label class="acknowledgement"><input v-model="acknowledged" type="checkbox" required> Both net cases include all operating costs and wear, with matched position, fuel and inventory.</label>
            <PrimaryButton :disabled="busy">Calculate incremental payback</PrimaryButton>
        </form></details>
        <p v-if="error" role="alert">{{ error }}</p>
        <section v-if="result" aria-label="Fleet investment estimate">
            <h4>Fleet investment · {{ result.ready ? 'Operator estimate' : 'Unresolved' }}</h4><p>{{ result.reason }}</p>
            <dl class="result-grid">
                <dt>Added ship / equipment capital</dt><dd>{{ number(result.ship_capital) }} / {{ number(result.equipment_capital) }} CR</dd>
                <dt>Best measured current-assets rate</dt><dd>{{ number(result.current_assets_net_per_hour) }} CR/h · run {{ result.alternative_run ?? 'Unknown' }}</dd>
                <dt>Base / conservative fleet rate</dt><dd>{{ number(result.base_net_per_hour) }} / {{ number(result.conservative_net_per_hour) }} CR/h</dd>
                <dt>Base / conservative incremental rate</dt><dd>{{ number(result.incremental_net_per_hour) }} / {{ number(result.conservative_incremental_net_per_hour) }} CR/h</dd>
                <dt>Base / conservative payback</dt><dd>{{ number(result.payback_hours) }} / {{ number(result.conservative_payback_hours) }} hours</dd>
                <dt>Ship-hours / net per ship-hour</dt><dd>{{ number(result.ship_hours) }} / {{ number(result.income_per_ship_hour) }} CR</dd>
                <dt>Throughput</dt><dd>{{ number(result.throughput_units_per_hour) }} units/h</dd>
            </dl>
            <details><summary>Calculation basis and inputs</summary><p>{{ result.basis }}</p><DataDetails :value="result.inputs" /></details>
        </section>
    </details>
</template>

<style scoped>
p { margin: 12px 0; color: var(--color-muted); }
summary { min-height: 44px; color: var(--color-accent); cursor: pointer; }
.comparison-fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; margin: 16px 0; }
label { display: grid; gap: 6px; color: var(--color-ink); }
input, select, textarea { width: 100%; min-width: 0; background: var(--color-field); color: var(--color-ink); border: 1px solid #67829f; border-radius: 10px; padding: 10px; min-height: 44px; font: inherit; }
input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 3px; }
.acknowledgement { display: flex; align-items: center; margin: 16px 0; }
.acknowledgement input { width: 24px; min-height: 24px; flex-shrink: 0; }
.result-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; margin: 16px 0; }
.result-grid dt { color: var(--color-muted); }
.result-grid dd { overflow-wrap: anywhere; }
h4 { font-weight: 700; color: var(--color-accent); margin-top: 16px; }
@media (max-width: 650px) { .comparison-fields { grid-template-columns: 1fr; } }
@media (max-width: 450px) { .result-grid { grid-template-columns: 1fr; gap: 4px; } .result-grid dd { margin-bottom: 12px; } }
</style>
