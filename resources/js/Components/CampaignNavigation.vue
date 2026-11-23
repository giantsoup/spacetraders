<script setup>
import { Link, router } from '@inertiajs/vue3';
defineProps({ selectedAgent: String, campaignAgents: { type: Array, default: () => [] } });
const page = () => route().current('campaign.strategy') ? 'campaign.strategy' : 'campaign.show';
</script>

<template>
    <nav class="campaign-navigation" aria-label="Campaign navigation">
        <Link :href="route('campaign.show', selectedAgent ? { agent: selectedAgent } : {})" :aria-current="route().current('campaign.show') ? 'page' : undefined">Status</Link>
        <Link :href="route('campaign.strategy', selectedAgent ? { agent: selectedAgent } : {})" :aria-current="route().current('campaign.strategy') ? 'page' : undefined">Strategy</Link>
        <label v-if="campaignAgents.length > 1">
            Agent
            <select :value="selectedAgent" @change="router.visit(route(page(), { agent: $event.target.value }))">
                <option v-for="agent in campaignAgents" :key="agent" :value="agent">{{ agent }}</option>
            </select>
        </label>
    </nav>
</template>

<style scoped>
.campaign-navigation { display: flex; gap: 24px; flex-wrap: wrap; align-items: center; border-bottom: 1px solid var(--color-line); }
a { display: grid; align-items: center; min-width: 44px; min-height: 44px; color: var(--color-muted); font-size: 14px; text-decoration: none; }
a[aria-current] { color: var(--color-ink); border-bottom: 2px solid var(--color-accent); font-weight: 650; }
a:focus-visible { outline: 2px solid var(--color-focus); outline-offset: 3px; }
</style>
