<script setup>
import { computed, reactive, ref, watch } from 'vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
const props = defineProps({ commands: Object, enums: Object, selected: String, ships: Array, disabled: Boolean });
const emit = defineEmits(['preview']);
const command = ref('orbit');
const form = reactive({ units: 1, symbol: 'IRON_ORE', waypointSymbol: '', shipType: 'SHIP_PROBE', maxTotal: 0, minTotal: 0, reserve: 0, targetShip: '', surveySignature: '', surveyWaypoint: '', question: 'MARKETPLACE', maxCooldownSeconds: 300, flightMode: 'CRUISE', contractId: '', term_index: 0, acknowledgeLoss: false, confirmShip: '', produce: 'IRON', fromCargo: false, protectedFuel: 0, maxCargoUnits: '', maxFuel: '', maxSeconds: '', acknowledgeUnknown: false });
const needsShip = computed(() => !['contract_accept', 'contract_fulfill'].includes(command.value));
const definition = computed(() => props.commands[command.value]);
const groups = computed(() => [...new Set(Object.values(props.commands).map(row => row[1]))]);
const labels = { units: 'Units', symbol: 'Cargo or equipment symbol', waypointSymbol: 'Destination waypoint', shipType: 'Offered ship type', maxTotal: 'Maximum total spending in CR', minTotal: 'Minimum sale total in CR', reserve: 'Protected credits in CR', targetShip: 'Receiving owned ship', surveySignature: 'Signed survey signature', surveyWaypoint: 'Survey waypoint', question: 'Exploration question', maxCooldownSeconds: 'Cooldown review threshold in seconds', flightMode: 'Flight mode', contractId: 'Contract ID', term_index: 'Delivery term index, starting at 0', confirmShip: 'Type the exact ship symbol to scrap', acknowledgeLoss: 'I acknowledge permanent loss of the selected ship, equipment or cargo', produce: 'Requested product', fromCargo: 'Use FUEL cargo', protectedFuel: 'Protected FUEL cargo units', maxCargoUnits: 'Maximum FUEL cargo loss for review', maxFuel: 'Maximum tank fuel use for review', maxSeconds: 'Maximum travel seconds for review', acknowledgeUnknown: 'I acknowledge uncalibrated travel and no verified continuation' };
const numbers = ['protectedFuel', 'maxCargoUnits', 'maxFuel', 'maxSeconds', 'units', 'maxTotal', 'minTotal', 'reserve', 'maxCooldownSeconds', 'term_index'];
watch(command, () => { form.acknowledgeLoss = false; });
function preview() {
    const args = Object.fromEntries(definition.value[2].filter(field => form[field] !== '').map(field => [field, numbers.includes(field) ? Number(form[field]) : form[field]]));
    emit('preview', { command: command.value, ship_symbol: props.selected || '', arguments: args });
}
</script>

<template>
    <section class="panel manual-api" aria-labelledby="manual-api-title">
        <h2 id="manual-api-title">Manual API commands</h2>
        <p>Choose an exact action for {{ selected || 'this agent' }}. Each preview is read-only. Approval sends one durable intent; it never starts a strategy.</p>
        <p>Use the API library to inspect current offers, contract terms, equipment, quotes and destinations first.</p>
        <form @submit.prevent="preview"><fieldset :disabled="disabled"><legend>Action and limits</legend>
            <label>Manual action<select v-model="command"><optgroup v-for="group in groups" :key="group" :label="group"><option v-for="(entry, key) in Object.fromEntries(Object.entries(commands).filter(([, row]) => row[1] === group))" :key="key" :value="key">{{ entry[0] }}</option></optgroup></select></label>
            <div class="fields"><label v-for="field in definition[2]" :key="field" :class="{ acknowledgement: field === 'acknowledgeLoss' }">{{ labels[field] || field }}
                <input v-if="['acknowledgeLoss', 'fromCargo', 'acknowledgeUnknown'].includes(field)" v-model="form[field]" :name="field" type="checkbox" :required="field === 'acknowledgeLoss'">
                <select v-else-if="field === 'produce'" v-model="form[field]" :name="field"><option v-for="product in ['IRON', 'COPPER', 'SILVER', 'GOLD', 'ALUMINUM', 'PLATINUM', 'URANITE', 'MERITIUM', 'FUEL']" :key="product">{{ product }}</option></select>
                <select v-else-if="field === 'shipType'" v-model="form[field]" :name="field"><option v-for="type in enums.ShipType" :key="type">{{ type }}</option></select>
                <select v-else-if="field === 'flightMode'" v-model="form[field]" :name="field"><option value="">Keep current mode</option><option v-for="mode in ['DRIFT', 'STEALTH', 'CRUISE', 'BURN']" :key="mode">{{ mode }}</option></select>
                <select v-else-if="field === 'question'" v-model="form[field]" :name="field"><option>MARKETPLACE</option><option>SHIPYARD</option></select>
                <select v-else-if="field === 'targetShip'" v-model="form[field]" :name="field" required><option value="">Select receiving ship</option><option v-for="ship in ships.filter(row => row.symbol !== selected)" :key="ship.symbol">{{ ship.symbol }}</option></select>
                <input v-else-if="numbers.includes(field)" v-model="form[field]" :name="field" type="number" :min="field === 'units' ? 1 : 0" :max="field === 'term_index' ? 19 : 1000000000" step="1" :required="!['maxFuel', 'maxSeconds', 'maxCargoUnits', 'units'].includes(field) || (field === 'units' && command !== 'refuel')">
                <input v-else v-model="form[field]" :name="field" :required="!(command === 'mine' && ['surveySignature', 'surveyWaypoint'].includes(field))" :maxlength="field === 'surveySignature' ? 255 : 80">
            </label></div>
            <p v-if="command.startsWith('contract_')">Review exact terms, deadlines and rewards in the API library. Acceptance creates obligations. Delivery term index selects the displayed obligation.</p>
            <p v-if="command === 'buy_ship'" class="warning">Choose a freshly observed shipyard offer. This purchase has no mining prerequisite. Prices are observations, and SpaceTraders provides no server spending ceiling.</p>
            <p v-if="command === 'scrap'" class="warning">Scrap destroys this exact ship permanently. Assume all cargo and installed equipment are lost. The quote does not reserve a price. History remains; a missing ship alone cannot prove an uncertain scrap succeeded.</p>
            <p v-if="command === 'jettison'" class="warning">Jettison permanently destroys the selected quantity without payment. Protected contract cargo remains aboard.</p>
            <p v-if="command === 'refuel'">Leave Units blank to fill the tank. Cargo conversion requires a zero cash ceiling and explicit cargo loss threshold for partial units. Server rounding remains a live acceptance gate.</p>
            <p v-if="['navigate', 'warp'].includes(command)" class="warning">For uncalibrated modes or zero-capacity tanks, enter fuel/time review thresholds and acknowledge the unknown continuation. These values cannot cap server consumption or arrival. Mode change needs its own approval.</p>
            <p v-if="needsShip && !selected" role="status">Select an owned ship in Fleet, or refresh Owned fleet in the API library. Contract acceptance and fulfillment can act for the agent without a ship.</p>
            <PrimaryButton type="submit" :disabled="needsShip && !selected">Preview selected action</PrimaryButton>
        </fieldset></form>
    </section>
</template>

<style scoped>
h2 { color: var(--color-accent); font-weight: 800; } p { margin: 12px 0; } legend { color: var(--color-muted); margin-bottom: 12px; } fieldset { min-width: 0; }
label { display: grid; gap: 8px; margin-bottom: 16px; } .fields { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
input, select { width: 100%; min-height: 44px; background: var(--color-field); color: var(--color-ink); border: 1px solid #67829f; border-radius: 10px; padding: 10px; font: inherit; } input[type=checkbox] { width: 24px; height: 24px; min-height: 24px; }
input:focus-visible, select:focus-visible { outline: 3px solid var(--color-focus); outline-offset: 3px; } .warning { color: #f8d99b; }
@media (max-width: 650px) { .fields { grid-template-columns: 1fr; } }
</style>
