<script setup>
import ShipName from '@/Components/Bridge/ShipName.vue';
import { clock, fullTime } from '@/bridgeFormat.js';
import { Link } from '@inertiajs/vue3';
defineProps({ entry: { type: Object, required: true } });
</script>

<template>
    <component :is="entry.link ? Link : 'div'" :href="entry.link || undefined" class="bridge-row bridge-log-row">
        <time v-if="entry.at" class="bridge-detail" :datetime="entry.at" :title="fullTime(entry.at)">{{ clock(entry.at) }}</time>
        <span class="bridge-grow">
            <strong><span v-if="entry.severity === 'problem'" class="bridge-negative"><span aria-hidden="true">⛔</span> Problem · </span>{{ entry.title }}</strong>
            <span v-if="entry.detail" class="bridge-detail">{{ entry.detail }}</span>
            <span v-if="entry.ship?.symbol && !entry.title?.includes(entry.ship.symbol) && !entry.detail?.includes(entry.ship.symbol)" class="bridge-detail"><ShipName :ship="entry.ship" /></span>
        </span>
        <span v-if="entry.link" class="bridge-chevron" aria-hidden="true">›</span>
    </component>
</template>
