<script setup>
import AppShell from '@/Layouts/AppShell.vue';
import CampaignNavigation from '@/Components/CampaignNavigation.vue';
import StrategyWalkthrough from '@/Components/StrategyWalkthrough.vue';
import { Head } from '@inertiajs/vue3';
defineProps({ selectedAgent: String, campaignAgents: Array, source: { type: Object, required: true }, graph: { type: Object, required: true } });
</script>

<template>
    <Head title="Campaign strategy" />
    <AppShell compact class="campaign-strategy-shell">
        <main id="main-content" tabindex="-1" class="strategy-page">
            <CampaignNavigation :campaign-agents="campaignAgents" :selected-agent="selectedAgent" />
            <header class="strategy-heading">
                <h1>How C0 decides</h1>
                <p>Explore what the campaign checks and what it does next.</p>
                <div class="strategy-source">
                    <strong :class="{ warning: source.example || source.status !== 'Approved C0 revision' }">{{ source.status }}</strong><span>{{ source.name }}</span><code>{{ source.hash }}</code>
                    <span v-if="source.pauseReason" class="source-note">Pause reason: {{ source.pauseReason.replaceAll('_', ' ') }}</span>
                    <span v-if="source.pending" class="source-note">Replacement queued · {{ source.pending.name }}. {{ source.pending.notice }}</span>
                </div>
            </header>
            <StrategyWalkthrough :graph="graph" />
        </main>
    </AppShell>
</template>

<style scoped>
.campaign-strategy-shell :deep(.app-brand) { font-size: 12px; letter-spacing: .03em; }
.strategy-page { width: min(100%, 1080px); margin: 0 auto; padding: 0 20px max(24px, env(safe-area-inset-bottom)); overflow-wrap: anywhere; }
.strategy-heading { padding-top: 24px; }
h1 { margin: 0; font: 780 clamp(32px, 6vw, 46px)/1.1 system-ui, sans-serif; letter-spacing: -.05em; }
.strategy-heading > p { margin: 8px 0 16px; color: #a5b9c8; font: 15px/1.5 system-ui, sans-serif; }
.strategy-source { display: flex; gap: 5px 10px; flex-wrap: wrap; align-items: baseline; padding-bottom: 16px; border-bottom: 1px solid #2b4051; font: 12px/1.5 system-ui, sans-serif; color: #a5b9c8; }
.strategy-source strong { color: #a6f3d0; font-weight: 660; }
.strategy-source .warning, .source-note { color: #ffd697; }
.strategy-source code { color: #eef5f7; font: 11px ui-monospace, monospace; }
.source-note { width: 100%; }
@media(max-width:360px) { .strategy-page { padding-inline: 14px; } }
@media(min-width:760px) { .strategy-page { padding-inline: 28px; } }
</style>
