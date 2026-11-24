<script setup>
import { Link } from '@inertiajs/vue3';
import { bridgeHref } from '@/bridgeFormat.js';
defineProps({ probes: { type: Object, required: true }, agent: String, from: String });
</script>

<template>
    <Link v-if="probes.count" :href="bridgeHref('probes', agent, { from })" class="bridge-row">
        <span class="bridge-dot" aria-hidden="true"></span>
        <span class="bridge-grow">
            <strong>Probes · {{ probes.count }}</strong>
            <span v-if="probes.groups?.length" class="bridge-detail">{{ probes.groups.filter(group => group.count).map(group => `${group.count} ${group.name.toLowerCase()}`).join(' · ') }}</span>
        </span>
        <span class="bridge-chevron" aria-hidden="true">›</span>
    </Link>
</template>
