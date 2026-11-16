<script setup>
import { computed } from 'vue';
import { assetName, readableText } from '@/dataPresentation.js';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { observedNumber as number } from '@/shipPresentation.js';
import { resourceSummary } from '@/shipFacts.js';
import DataDetails from '@/Components/DataDetails.vue';
import ShipDetails from '@/Components/ShipDetails.vue';
const props = defineProps({ ship: Object, observedAt: String, section: String, readiness: String, capability: String });
defineEmits(['section']);
const fuel = computed(() => resourceSummary(props.ship?.fuel?.current, props.ship?.fuel?.capacity, 'No fuel tank'));
const cargo = computed(() => resourceSummary(props.ship?.cargo?.units, props.ship?.cargo?.capacity, 'No cargo hold'));
const crew = computed(() => resourceSummary(props.ship?.crew?.current, props.ship?.crew?.capacity, 'No crew capacity'));
const miningPolicy = computed(() => !Array.isArray(props.ship?.mounts) || !Array.isArray(props.ship?.modules)
    ? 'Equipment not fully observed; this is not evidence of absent equipment.'
    : props.capability ? readableText(props.capability) : 'No blocking reason recorded.');
</script>
<template>
    <div class="ship-report">
        <p>{{ ship?.symbol || 'No ship selected' }} · {{ assetName(ship?.registration?.role) }}</p>
        <nav aria-label="Report sections" class="sheet-tabs"><button v-for="key in ['fuel', 'cargo', 'systems']" :key="key" type="button" :aria-pressed="section === key" @click="$emit('section', key)">{{ key === 'fuel' ? 'Fuel' : key === 'cargo' ? 'Cargo' : 'Systems' }}</button></nav>
        <section v-if="section === 'fuel'" aria-labelledby="report-fuel">
            <h3 id="report-fuel">{{ ship?.fuel?.capacity === 0 ? 'Fuel' : 'Fuel tank' }}</h3>
            <dl><dt>Observed tank units</dt><dd>{{ fuel.text }}</dd><dt>Last consumed units</dt><dd>{{ number(ship?.fuel?.consumed?.amount) }}</dd></dl>
            <p>Tank units differ from FUEL cargo. Range and refueling cost require a fresh server preview.</p>
        </section>
        <section v-if="section === 'cargo'" aria-labelledby="report-cargo">
            <h3 id="report-cargo">{{ ship?.cargo?.capacity === 0 ? 'Cargo' : 'Cargo hold' }}</h3>
            <dl><dt>Used / capacity</dt><dd>{{ cargo.text }}</dd><template v-if="cargo.percent !== null"><dt>Free space</dt><dd>{{ number(ship.cargo.capacity - ship.cargo.units) }}</dd></template></dl>
            <p v-if="ship?.cargo?.capacity === 0">No cargo hold.</p>
            <p v-else-if="!Array.isArray(ship?.cargo?.inventory)">Inventory not observed.</p>
            <p v-else-if="!ship.cargo.inventory.length">{{ cargo.hasCapacity ? 'The hold is empty.' : 'No inventory recorded.' }}</p>
            <ul v-if="Array.isArray(ship?.cargo?.inventory) && ship.cargo.inventory.length" class="report-inventory"><li v-for="(item, index) in ship.cargo.inventory" :key="index"><span>{{ assetName(item) }} · {{ item.symbol }}</span><strong>{{ number(item.units) }}</strong></li></ul>
        </section>
        <section v-if="section === 'systems'" aria-labelledby="report-systems">
            <h3 id="report-systems">Cooldown and systems</h3>
            <dl><dt>Action cooldown</dt><dd>{{ readiness }}</dd><dt>Flight mode</dt><dd>{{ assetName(ship?.nav?.flightMode) }}</dd><dt>Crew aboard / capacity</dt><dd>{{ crew.text }}</dd><dt>Crew required</dt><dd>{{ number(ship?.crew?.required) }}</dd></dl>
            <p>Mining client-policy check: {{ miningPolicy }} This is not general ship readiness or action eligibility.</p>
            <ShipDetails :key="ship?.symbol" :ship="ship" />
        </section>
        <p class="app-muted">Observed <time v-if="observedAt" :datetime="observedAt">{{ formatLocalTime(observedAt) }}</time><span v-else>Unknown</span>. {{ localTimeZone }}.</p>
        <details><summary>Selected API fields</summary><DataDetails :value="ship || {}" context="ship" /></details>
    </div>
</template>
<style scoped>
.ship-report h3 { font-size: 20px; margin: 24px 0 12px; }
.ship-report h4 { margin: 12px 0; }
.ship-report p { margin: 16px 0; }
.ship-report dl { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 12px; }
.ship-report dd { text-align: right; }
.ship-report dt { color: var(--color-muted); }
.report-inventory { list-style: none; padding: 0; }
.report-inventory li { display: flex; justify-content: space-between; gap: 16px; padding: 16px 0; border-bottom: 1px solid var(--color-line); }
.ship-report { min-width: 0; overflow-wrap: anywhere; }
.ship-report summary { min-height: 44px; box-sizing: border-box; padding: 12px 0; cursor: pointer; }
.ship-report summary:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }
</style>
