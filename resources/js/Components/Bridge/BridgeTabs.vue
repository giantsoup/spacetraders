<script setup>
import { Link } from '@inertiajs/vue3';
import { bridgeHref } from '@/bridgeFormat.js';

defineProps({ tab: String, agent: String, needsYou: Number });
const tabs = [
    { key: 'brief', label: 'Brief', icon: 'M4 5h16M4 12h16M4 19h10' },
    { key: 'routes', label: 'Routes', icon: 'M7 17c6-1 4-9 10-10' },
    { key: 'fleet', label: 'Fleet', icon: 'm3 15 9-9 9 9M7 19h10' },
    { key: 'log', label: 'Log', icon: 'M12 7v5l3 2' },
];
</script>

<template>
    <nav class="bridge-tabs" aria-label="Bridge sections">
        <Link v-for="item in tabs" :key="item.key" :href="bridgeHref(item.key, agent)" :aria-current="tab === item.key ? 'page' : undefined">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle v-if="item.key === 'routes'" cx="5" cy="18" r="2" />
                <circle v-if="item.key === 'routes'" cx="19" cy="6" r="2" />
                <circle v-if="item.key === 'log'" cx="12" cy="12" r="9" />
                <path :d="item.icon" />
            </svg>
            <span>{{ item.label }}</span>
            <template v-if="item.key === 'brief' && needsYou > 0">
                <span class="bridge-badge" aria-hidden="true">{{ needsYou }}</span>
                <span class="sr-only">, {{ needsYou }} {{ needsYou === 1 ? 'item needs' : 'items need' }} you</span>
            </template>
        </Link>
    </nav>
</template>
