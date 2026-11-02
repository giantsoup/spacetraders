<script setup>
import { formatLocalTime, localTimeZone } from '@/dateTime.js';
defineProps({ contract: Object, termIndex: Number });
</script>

<template>
    <section aria-label="Contract obligations and rewards" class="contract-approval">
        <h3>Contract {{ contract.id }} · {{ contract.type }}</h3>
        <dl><dt>Acceptance deadline</dt><dd>{{ formatLocalTime(contract.deadlineToAccept || contract.expiration) }}</dd><dt>Delivery deadline</dt><dd>{{ formatLocalTime(contract.terms.deadline) }}</dd><dt>Payment on acceptance</dt><dd>{{ contract.terms.payment.onAccepted }} CR</dd><dt>Payment on fulfillment</dt><dd>{{ contract.terms.payment.onFulfilled }} CR</dd><dt>Status</dt><dd>{{ contract.fulfilled ? 'Fulfilled' : contract.accepted ? 'Accepted' : 'Offered' }}</dd></dl>
        <p>Dates shown in {{ localTimeZone }}. Review every obligation before approval.</p>
        <ol><li v-for="(term, index) in contract.terms.deliver" :key="index"><strong>{{ termIndex === index ? 'Selected delivery · ' : '' }}Term {{ index }}</strong>: {{ term.tradeSymbol }} to {{ term.destinationSymbol }} · {{ term.unitsFulfilled }} / {{ term.unitsRequired }} delivered · {{ term.unitsRequired - term.unitsFulfilled }} remaining.</li></ol>
    </section>
</template>

<style scoped>
.contract-approval { border: 1px solid var(--color-accent); border-radius: 10px; padding: 16px; margin: 16px 0; overflow-wrap: anywhere; }
h3 { color: var(--color-accent); font-weight: 800; } dl { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px 16px; margin: 16px 0; } li { margin-top: 12px; } p { color: var(--color-muted); }
@media (max-width: 650px) { dl { grid-template-columns: 1fr; } }
</style>
