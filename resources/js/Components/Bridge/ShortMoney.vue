<script setup>
import { computed } from 'vue';
import { amount, shortAmount, signed, validNumber } from '@/bridgeFormat.js';
const props = defineProps({ value: Number, delta: Boolean, rate: Boolean });
const formatted = computed(() => props.delta ? signed(props.value, shortAmount) : shortAmount(props.value));
const full = computed(() => validNumber(props.value)
    ? `${props.delta ? signed(props.value) : amount(props.value)} credits${props.rate ? ' per hour' : ''}`
    : undefined);
</script>

<template>
    <span :title="full" :aria-label="full" :role="full ? 'group' : undefined"><span :aria-hidden="full ? 'true' : undefined">{{ formatted }}{{ rate && full ? '/h' : '' }}</span></span>
</template>
