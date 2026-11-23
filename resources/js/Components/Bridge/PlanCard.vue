<script setup>
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { label, strategyHref } from '@/bridgeFormat.js';

const props = defineProps({ plan: { type: Object, required: true }, agent: String });
const phases = computed(() => (props.plan.phases || []).map(phase => typeof phase === 'string' ? { key: phase, label: label(phase) } : {
    key: phase.key || phase.name || phase.id,
    label: phase.label || label(phase.name || phase.key),
    state: phase.state,
}));
const active = computed(() => phases.value.findIndex(phase => phase.key === props.plan.phase));
</script>

<template>
    <section>
        <div class="bridge-section-head"><h2>Plan</h2></div>
        <div class="bridge-panel">
            <div v-if="active >= 0" class="bridge-row">
                <ol class="bridge-phase-list">
                    <li v-for="(phase, index) in phases" :key="phase.key || index" :aria-current="index === active ? 'step' : undefined"
                        :class="{ 'bridge-positive': index <= active, 'bridge-phase-active': index === active }">
                        <span v-if="index < active" aria-hidden="true">✓ </span>
                        <span v-else-if="index === active" aria-hidden="true">● </span>{{ phase.label }}
                    </li>
                </ol>
            </div>
            <p v-if="active === -1 && plan.phase" class="bridge-row">{{ label(plan.phase) }}</p>
            <Link class="bridge-row" :href="strategyHref(agent)"><span class="bridge-grow"><strong class="bridge-positive">How C0 decides</strong><span class="bridge-detail">The plan’s checks and next steps</span></span><span class="bridge-chevron" aria-hidden="true">›</span></Link>
            <p v-if="plan.pending" class="bridge-row bridge-detail">{{ plan.pending }}</p>
            <details v-if="plan.budget_detail" class="bridge-budget">
                <summary class="bridge-row">{{ plan.budget }}</summary>
                <p v-if="typeof plan.budget_detail === 'string'">{{ plan.budget_detail }}</p>
                <ul v-else><li v-for="(detail, index) in plan.budget_detail" :key="index">{{ detail }}</li></ul>
            </details>
            <p v-else-if="plan.budget" class="bridge-row bridge-detail">{{ plan.budget }}</p>
        </div>
    </section>
</template>
