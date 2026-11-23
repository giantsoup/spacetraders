<script setup>
import ShipName from '@/Components/Bridge/ShipName.vue';
import { clock, commodity, elapsed, identity, routeParts, shipHref, until, waypoint } from '@/bridgeFormat.js';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
const props = defineProps({ ship: { type: [Object, String], required: true }, agent: String, from: String, selected: Boolean, preserve: Boolean, now: Number });
const href = computed(() => shipHref(identity(props.ship).symbol, props.agent, props.from));
const route = computed(() => routeParts(props.ship.route_key));
const right = computed(() => props.ship.arrival_at && props.ship.nav_status === 'IN_TRANSIT' && props.now
    ? until(props.ship.arrival_at, props.now) : props.ship.right);
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href || undefined" :preserve-state="href ? preserve : undefined" :preserve-scroll="href ? preserve : undefined" class="bridge-row" :aria-current="selected ? 'true' : undefined">
        <span class="bridge-grow">
            <strong><ShipName :ship="ship" /></strong>
            <span v-if="ship.status_text || ship.status || ship.purpose" class="bridge-detail">{{ ship.status_text || ship.status || ship.purpose }}<template v-if="route"> · {{ commodity(route.good) }} {{ waypoint(route.source) }} → {{ waypoint(route.buyer) }}</template><template v-if="ship.idle_since && now"> · <time :datetime="ship.idle_since" :title="clock(ship.idle_since)">{{ elapsed(ship.idle_since, now) }}</time></template></span>
        </span>
        <span v-if="right" class="bridge-row-value">{{ right }}</span>
        <span v-if="href && !selected" class="bridge-chevron" aria-hidden="true">›</span>
    </component>
</template>
