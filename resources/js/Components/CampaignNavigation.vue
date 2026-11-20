<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <nav class="campaign-navigation" aria-label="Campaign navigation">
        <Link :href="route('campaign.show')" :aria-current="route().current('campaign.show') ? 'page' : undefined">Status</Link>
        <Link :href="route('campaign.strategy')" :aria-current="route().current('campaign.strategy') ? 'page' : undefined">Strategy</Link>
    </nav>
</template>

<style scoped>
.campaign-navigation { display: flex; gap: 24px; border-bottom: 1px solid var(--color-line); }
a { display: grid; align-items: center; min-width: 44px; min-height: 44px; color: var(--color-muted); font-size: 14px; text-decoration: none; }
a[aria-current] { color: var(--color-ink); border-bottom: 2px solid var(--color-accent); font-weight: 650; }
a:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 3px; }
</style>
