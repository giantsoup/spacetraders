<script setup>
import DataDetails from '@/Components/DataDetails.vue';
import { equipmentSummary } from '@/shipFacts.js';

defineProps({
    equipment: { type: Array, default: null },
    kind: { type: String, required: true },
});
</script>

<template>
    <div class="ship-equipment">
        <p>{{ equipmentSummary(equipment) }}</p>
        <ol v-if="Array.isArray(equipment) && equipment.length">
            <li v-for="(part, index) in equipment" :key="index">
                <details>
                    <summary>{{ index + 1 }}. {{ part?.name || part?.symbol || 'Unidentified equipment' }}<span v-if="part?.name && part?.symbol"> · {{ part.symbol }}</span></summary>
                    <DataDetails :value="part" context="ship" :path="kind + '[' + index + ']'" />
                </details>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.ship-equipment { min-width: 0; overflow-wrap: anywhere; }
.ship-equipment p { margin: 12px 0; }
.ship-equipment ol { list-style: none; padding: 0; margin: 0; }
.ship-equipment li + li { border-top: 1px solid var(--color-line); }
.ship-equipment summary { min-height: 44px; box-sizing: border-box; padding: 12px 0; cursor: pointer; }
.ship-equipment summary:focus-visible { outline: 2px solid var(--color-accent); outline-offset: 2px; }
</style>
