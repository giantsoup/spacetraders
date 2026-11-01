<script setup>
import { readableLabel } from '@/dataPresentation.js';
defineProps({ accounting: Object });
const number = value => value === null || value === undefined ? 'Unknown' : Number(value).toLocaleString(undefined, { maximumFractionDigits: 3 });
</script>

<template>
    <section v-if="accounting" class="cycle-results" aria-label="Complete-cycle accounting">
        <h4>Complete-cycle accounting · {{ readableLabel(accounting.state) }}</h4>
        <dl>
            <dt>Observed operating cash</dt><dd>{{ number(accounting.cash_contribution) }} CR</dd>
            <dt>Wear basis</dt><dd>{{ readableLabel(accounting.wear_basis || 'unmeasured') }} · {{ number(accounting.wear_cost_or_allowance) }} CR</dd>
            <dt>Net after wear</dt><dd>{{ number(accounting.net_after_wear) }} CR</dd>
            <dt>Comparable net per elapsed hour</dt><dd>{{ number(accounting.net_credits_per_hour) }} CR/h</dd>
            <dt>Realized net per elapsed hour</dt><dd>{{ number(accounting.realized_net_per_hour) }} CR/h</dd>
            <dt>Ship-hours / net per ship-hour</dt><dd>{{ number(accounting.ship_hours) }} / {{ number(accounting.income_per_ship_hour) }} CR</dd>
            <dt>Sold units per elapsed hour</dt><dd>{{ number(accounting.sold_units_per_elapsed_hour) }}</dd>
        </dl>
        <p v-if="accounting.state === 'estimated_comparable'">The cycle is closed using your wear allowance. The net rate remains an estimate.</p>
        <ul v-if="accounting.unresolved?.length"><li v-for="reason in accounting.unresolved" :key="reason">{{ reason }}</li></ul>
    </section>
</template>

<style scoped>
.cycle-results { margin: 16px 0; border-top: 1px solid var(--color-line); padding-top: 12px; }
h4 { color: var(--color-accent); font-weight: 700; }
dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px 16px; margin: 14px 0; }
dt, p { color: var(--color-muted); }
dd { overflow-wrap: anywhere; color: var(--color-ink); }
ul { padding-left: 20px; list-style: disc; color: var(--color-muted); }
@media (max-width: 550px) { dl { grid-template-columns: 1fr; gap: 4px; } dd { margin-bottom: 10px; } }
</style>
