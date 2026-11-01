<script setup>
import { computed, reactive, ref } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({ selected: String, report: Object, disabled: Boolean });
const emit = defineEmits(['preview']);
const strategy = ref('scout');
const form = reactive({ destination: '', market: '', symbol: '', units: 1, reserve: 0, max_spend: 0, max_loss: 0, max_inventory: 0, min_margin: 1, min_sell_price: 1, max_fuel: 0, minutes: 15, wear_allowance: '' });
const tasks = computed(() => (props.report?.observation_tasks || []).filter(task => task.ship === props.selected && !['Hidden traits', 'Missing goods directory'].includes(task.fact)));
function preview() {
    const limits = { strategy: strategy.value, ship_symbol: props.selected };
    for (const name of ['reserve', 'max_spend', 'max_loss', 'max_fuel', 'minutes']) limits[name] = Number(form[name]);
    if (form.wear_allowance !== '') limits.wear_allowance = Number(form.wear_allowance);
    if (strategy.value === 'scout') Object.assign(limits, { destination: form.destination, max_visits: 1 });
    else {
        Object.assign(limits, { market: form.market, symbol: form.symbol });
        for (const name of ['units', 'max_inventory', 'min_margin', 'min_sell_price']) limits[name] = Number(form[name]);
    }
    emit('preview', limits);
}
</script>

<template>
    <section class="panel" aria-labelledby="operations-heading">
        <h2 id="operations-heading">Bounded scouting and trading</h2>
        <p>Uses the selected ship and the same command, stop and reconciliation controls as mining. Begin stationary with empty cargo at a verified fuel seller. Each operation returns home and ends; another requires a new preview.</p>
        <fieldset :disabled="disabled"><legend>Choose an operation and approve its limits</legend>
            <div class="form-grid"><label>Operation<select v-model="strategy" aria-label="Operation"><option value="scout">Observe one market and return</option><option value="trade">Buy, sell in batches, return</option></select></label>
                <label v-if="strategy === 'scout'">Observation question<select v-model="form.destination" aria-label="Observation question"><option value="">Select missing evidence</option><option v-for="task in tasks" :key="task.destination" :value="task.destination">{{ task.destination }} · {{ task.fact }}</option></select></label>
                <template v-else><label>Buyer<select v-model="form.market" aria-label="Buyer"><option value="">Select buyer</option><option v-for="market in report?.markets || []" :key="market.symbol" :value="market.symbol">{{ market.symbol }} · {{ market.state }}</option></select></label><label>Trade symbol<input v-model="form.symbol" placeholder="IRON_ORE" pattern="[A-Z0-9_]+"></label><label>Total inventory units<input v-model="form.units" type="number" min="1" max="100000"></label><label>Maximum inventory exposure in CR<input v-model="form.max_inventory" type="number" min="1"></label><label>Minimum cycle cash margin in CR<input v-model="form.min_margin" type="number" min="1"></label><label>Minimum sale price per owned unit<input v-model="form.min_sell_price" type="number" min="1"></label></template>
                <label>Protected reserve in CR<input v-model="form.reserve" type="number" min="1"></label><label>Maximum total spending in CR<input v-model="form.max_spend" type="number" min="1"></label><label>Maximum cash drawdown in CR<input v-model="form.max_loss" type="number" min="0"></label><label>Maximum travel fuel in tank units<input v-model="form.max_fuel" type="number" min="1"></label><label>Duration cap in minutes<input v-model="form.minutes" type="number" min="1" max="60"></label>
            <label>Run wear allowance including repairs in CR<input v-model="form.wear_allowance" type="number" min="0" step="1" placeholder="Unknown"></label>
            </div>
            <p>Optional allowance for the entire bounded run. Leave blank to keep wear unresolved. This changes reporting only.</p>
            <p v-if="strategy === 'trade'" class="warning">Cash drawdown includes bought inventory before sale. A deteriorating spread stops purchases and retains owned cargo for review. Quotes cannot guarantee the execution price.</p>
            <p v-else class="muted">One purposeful visit. A remote directory refresh precedes movement; if it answers the question, movement stops. Unknown information benefit and displaced earnings remain unpriced.</p>
            <PrimaryButton @click="preview">Preview {{ strategy === 'scout' ? 'observation trip' : 'trade cycle' }}</PrimaryButton>
        </fieldset>
    </section>
</template>

<style scoped>
h2 { color: var(--color-accent); font-weight: 800; margin-bottom: 18px; }
p { margin: 12px 0; }
fieldset { min-width: 0; }
fieldset:disabled { opacity: .65; }
legend, .muted { color: var(--color-muted); }
.warning { color: #f8d99b; }
.form-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; margin: 20px 0; }
label { display: grid; gap: 7px; font-size: 15px; color: var(--color-ink); }
input, select { border: 1px solid #67829f; background: var(--color-field); color: var(--color-ink); padding: 10px; width: 100%; min-height: 44px; border-radius: 12px; font: inherit; }
input:focus-visible, select:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 4px; }
@media (max-width: 900px) { .form-grid { grid-template-columns: 1fr; } }
</style>
