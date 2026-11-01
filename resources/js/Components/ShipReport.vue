<script setup>
import { computed } from 'vue';
import { assetName, readableText } from '@/dataPresentation.js';
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
import { observedNumber as number } from '@/shipPresentation.js';
import DataDetails from '@/Components/DataDetails.vue';
const props = defineProps({ ship: Object, observedAt: String, section: String, readiness: String, capability: String });
defineEmits(['section']);
const equipment = computed(() => {
    const parts = new Map();
    for (const part of [...(props.ship?.mounts || []), ...(props.ship?.modules || [])]) {
        if (parts.has(part.symbol)) parts.get(part.symbol).count++;
        else parts.set(part.symbol, { ...part, count: 1 });
    }
    return [...parts.values()];
});
const percent = value => Number.isFinite(value) ? `${Math.round(value * 100)}%` : 'Unknown';
</script>
<template>
    <div class="ship-report">
        <p>{{ ship?.symbol || 'No ship selected' }} · {{ assetName(ship?.registration?.role) }}</p>
        <nav aria-label="Report sections" class="sheet-tabs"><button v-for="key in ['fuel', 'cargo', 'systems']" :key="key" type="button" :aria-pressed="section === key" @click="$emit('section', key)">{{ key === 'fuel' ? 'Fuel' : key === 'cargo' ? 'Cargo' : 'Systems' }}</button></nav>
        <section v-if="section === 'fuel'" aria-labelledby="report-fuel">
            <h3 id="report-fuel">Fuel tank</h3>
            <dl><dt>Observed tank units</dt><dd>{{ number(ship?.fuel?.current) }} / {{ number(ship?.fuel?.capacity) }}</dd><dt>Last consumed units</dt><dd>{{ number(ship?.fuel?.consumed?.amount) }}</dd></dl>
            <p>Tank units differ from FUEL cargo. Range and refueling cost require a fresh server preview.</p>
        </section>
        <section v-if="section === 'cargo'" aria-labelledby="report-cargo">
            <h3 id="report-cargo">Cargo hold</h3>
            <dl><dt>Used / capacity</dt><dd>{{ number(ship?.cargo?.units) }} / {{ number(ship?.cargo?.capacity) }}</dd><dt>Free space</dt><dd>{{ number(Number.isFinite(ship?.cargo?.capacity) && Number.isFinite(ship?.cargo?.units) ? ship.cargo.capacity - ship.cargo.units : null) }}</dd></dl>
            <p v-if="!ship?.cargo?.inventory">Inventory Unknown</p><p v-else-if="!ship.cargo.inventory.length">The hold is empty.</p>
            <ul v-else class="report-inventory"><li v-for="item in ship.cargo.inventory" :key="item.symbol"><span>{{ assetName(item) }}</span><strong>{{ number(item.units) }}</strong></li></ul>
        </section>
        <section v-if="section === 'systems'" aria-labelledby="report-systems">
            <h3 id="report-systems">Readiness and systems</h3>
            <dl><dt>Action cooldown</dt><dd>{{ readiness }}</dd><dt>Flight mode</dt><dd>{{ assetName(ship?.nav?.flightMode) }}</dd><dt>Crew aboard</dt><dd>{{ number(ship?.crew?.current) }}</dd><dt>Crew required</dt><dd>{{ number(ship?.crew?.required) }}</dd><dt>Crew capacity</dt><dd>{{ number(ship?.crew?.capacity) }}</dd></dl>
            <p>{{ capability ? readableText(capability) : 'Mining capability checked by the server; other action prerequisites still apply.' }}</p>
            <h3>Components</h3>
            <div v-for="part in ['frame', 'reactor', 'engine']" :key="part" class="report-component"><h4>{{ assetName(ship?.[part]?.symbol || part) }}</h4><dl><dt>Condition</dt><dd>{{ percent(ship?.[part]?.condition) }}</dd><dt>Integrity</dt><dd>{{ percent(ship?.[part]?.integrity) }}</dd></dl></div>
            <h3>Installed equipment</h3><p v-if="!ship?.modules || !ship?.mounts">Some equipment observations are Unknown.</p>
            <ul class="equipment-list"><li v-for="part in equipment" :key="part.symbol"><span>{{ assetName(part) }}<template v-if="part.count > 1"> × {{ part.count }}</template></span><p v-if="part.description">{{ readableText(part.description) }}</p></li></ul>
        </section>
        <p class="app-muted">Observed <time v-if="observedAt" :datetime="observedAt">{{ formatLocalTime(observedAt) }}</time><span v-else>Unknown</span>. {{ localTimeZone }}.</p>
        <details><summary>Selected API fields</summary><DataDetails :value="ship || {}" /></details>
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
.equipment-list { padding-left: 20px; }
</style>
