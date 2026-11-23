<script setup>
import ShipName from '@/Components/Bridge/ShipName.vue';
import { amount, clock, commodity, fullTime, identity, signed, tone, validNumber } from '@/bridgeFormat.js';
import { computed } from 'vue';

const props = defineProps({ rows: { type: Array, default: () => [] }, ships: { type: Array, default: () => [] }, showShip: Boolean });
const shipsBySymbol = computed(() => new Map(props.ships.map(ship => [ship.symbol, ship])));
</script>

<template>
    <div v-if="rows.length" class="bridge-panel">
        <div v-for="entry in rows" :key="entry.id || entry.at" class="bridge-row bridge-log-row">
            <time v-if="entry.at" class="bridge-detail" :datetime="entry.at" :title="fullTime(entry.at)">{{ clock(entry.at) }}</time>
            <span class="bridge-grow">
                <strong v-if="showShip && entry.ship"><ShipName :ship="shipsBySymbol.get(identity(entry.ship).symbol) || entry.ship" /></strong>
                <strong v-else>{{ entry.kind === 'recovery_result' ? 'Recovery sale' : (commodity(entry.good) || 'Load completed') }}</strong>
                <span v-if="validNumber(entry.units)" class="bridge-detail">{{ amount(entry.units) }} {{ entry.units === 1 ? 'unit' : 'units' }}</span>
            </span>
            <span :class="tone(entry.net)">{{ signed(entry.net) }}</span>
        </div>
    </div>
    <p v-else class="bridge-empty">No completed loads yet.</p>
</template>
