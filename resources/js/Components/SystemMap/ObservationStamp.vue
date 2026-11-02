<script setup>
import { computed } from 'vue';
import { formatLocalTime } from '@/dateTime.js';
const props = defineProps({ at: String, now: Number, seconds: Number });
const age = computed(() => props.at ? Math.max(0, Math.floor((props.now - Date.parse(props.at)) / 1000)) : null);
const text = computed(() => age.value === null ? 'Unknown observation age' : `${age.value < 60 ? `${age.value}s` : age.value < 3600 ? `${Math.floor(age.value / 60)}m` : age.value < 86400 ? `${Math.floor(age.value / 3600)}h` : `${Math.floor(age.value / 86400)}d`} ago`);
</script>
<template><span class="map-stamp" :class="{ stale: age !== null && age > seconds }" :title="formatLocalTime(at)">{{ text }}{{ age !== null && age > seconds ? ' · Stale' : '' }}<time v-if="at" :datetime="at">{{ formatLocalTime(at) }}</time></span></template>
