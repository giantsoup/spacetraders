<script setup>
import { computed } from 'vue';
import DataDetails from '@/Components/DataDetails.vue';
import ShipEquipmentList from '@/Components/ShipEquipmentList.vue';
import { resourceSummary, equipmentSummary } from '@/shipFacts.js';

const props = defineProps({
    ship: { type: Object, default: null },
    unlistedFields: { type: Array, default: () => [] },
});
const exactValues = computed(() => JSON.stringify(props.ship ?? null, null, 2));
</script>

<template>
    <details class="ship-details">
        <summary>Ship details</summary>
        <p v-if="!ship" class="app-muted">Ship details not observed.</p>
        <details v-for="part in ['frame', 'reactor', 'engine']" :key="part">
            <summary>{{ part === 'frame' ? 'Frame' : part === 'reactor' ? 'Reactor' : 'Engine' }} · {{ ship?.[part]?.name || ship?.[part]?.symbol || 'Not observed' }}</summary>
            <DataDetails :value="ship?.[part] ?? null" context="ship" :path="part" />
        </details>
        <details v-for="kind in ['mounts', 'modules']" :key="kind">
            <summary>{{ kind === 'mounts' ? 'Mounts' : 'Modules' }} · {{ equipmentSummary(ship?.[kind]) }}</summary>
            <ShipEquipmentList :equipment="ship?.[kind]" :kind="kind" />
        </details>
        <details>
            <summary>Crew · {{ resourceSummary(ship?.crew?.current, ship?.crew?.capacity, 'No crew capacity').text }}</summary>
            <DataDetails :value="ship?.crew ?? null" context="ship" path="crew" />
        </details>
        <details>
            <summary>Cargo · {{ resourceSummary(ship?.cargo?.units, ship?.cargo?.capacity, 'No cargo hold').text }}</summary>
            <p v-if="ship?.cargo?.capacity === 0">No cargo hold.</p>
            <p v-else-if="!Array.isArray(ship?.cargo?.inventory)">Inventory not observed.</p>
            <p v-else-if="!ship.cargo.inventory.length">No inventory recorded.</p>
            <DataDetails :value="ship?.cargo ?? null" context="ship" path="cargo" />
        </details>
        <details>
            <summary>Navigation and route</summary>
            <DataDetails :value="ship?.nav ?? null" context="ship" path="nav" />
        </details>
        <details>
            <summary>Fuel · {{ resourceSummary(ship?.fuel?.current, ship?.fuel?.capacity, 'No fuel tank').text }}</summary>
            <DataDetails :value="ship?.fuel ?? null" context="ship" path="fuel" />
        </details>
        <details>
            <summary>Action cooldown</summary>
            <DataDetails :value="ship?.cooldown ?? null" context="ship" path="cooldown" />
        </details>
        <details>
            <summary>All observed ship fields</summary>
            <DataDetails :value="ship" context="ship" />
        </details>
        <details>
            <summary>Exact observed values (JSON)</summary>
            <pre>{{ exactValues }}</pre>
        </details>
        <details v-if="unlistedFields.length">
            <summary>Excluded field names · {{ unlistedFields.length }}</summary>
            <p>These paths were not included in the safe ship details. Only field names are shown; their values are excluded.</p>
            <ul><li v-for="path in unlistedFields" :key="path"><code>{{ path }}</code></li></ul>
        </details>
    </details>
</template>

<style scoped>
.ship-details { min-width: 0; overflow-wrap: anywhere; }
.ship-details details { border-top: 1px solid var(--color-line); }
.ship-details summary { min-height: 44px; box-sizing: border-box; padding: 12px 0; cursor: pointer; }
.ship-details summary:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }
.ship-details details > :not(summary) { margin-bottom: 12px; }
.ship-details pre { max-width: 100%; min-width: 0; white-space: pre-wrap; overflow-wrap: anywhere; font-size: 12px; padding: 12px; background: var(--color-panel); }
.ship-details ul { padding-left: 20px; }
</style>
