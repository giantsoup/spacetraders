<script setup>
import ShortMoney from '@/Components/Bridge/ShortMoney.vue';
import { commodity, routeHref, tone, validNumber, waypoint } from '@/bridgeFormat.js';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ entry: { type: Object, required: true }, agent: String, from: String, selected: Boolean, preserve: Boolean, brief: Boolean });
const href = computed(() => routeHref(props.entry.key, props.agent, props.from));
const statusTone = computed(() => props.entry.status?.toLowerCase().startsWith('running') ? 'bridge-positive'
    : props.entry.status?.toLowerCase().startsWith('ended') ? '' : 'bridge-negative');
const shipCount = computed(() => Array.isArray(props.entry.ships) ? props.entry.ships.length : props.entry.ships);
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href || undefined" :preserve-state="href ? preserve : undefined" :preserve-scroll="href ? preserve : undefined" class="bridge-row" :aria-current="selected ? 'true' : undefined">
        <span class="bridge-dot" :class="statusTone" aria-hidden="true"></span>
        <span class="bridge-grow">
            <strong>{{ commodity(entry.good) }} · {{ entry.source ? waypoint(entry.source) : 'Unknown source' }} → {{ waypoint(entry.buyer) }}</strong>
            <span class="bridge-detail">
                <span :class="statusTone">{{ entry.status }}</span>
                <template v-if="shipCount"> · {{ shipCount }} {{ shipCount === 1 ? 'ship' : 'ships' }}</template>
                <template v-if="brief && validNumber(entry.last_net)"> · last load <ShortMoney :value="entry.last_net" delta /></template>
                <template v-else-if="validNumber(entry.net)"> · net <ShortMoney :class="tone(entry.net)" :value="entry.net" delta /></template>
                <template v-if="entry.status === 'Ended' && validNumber(entry.loads)"> · {{ entry.loads }} {{ entry.loads === 1 ? 'load' : 'loads' }}</template>
            </span>
        </span>
        <ShortMoney v-if="validNumber(entry.rate)" :value="entry.rate" rate />
        <span v-if="href && !selected" class="bridge-chevron" aria-hidden="true">›</span>
    </component>
</template>
