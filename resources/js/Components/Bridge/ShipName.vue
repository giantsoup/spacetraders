<script setup>
import { computed } from 'vue';
import { identity } from '@/bridgeFormat.js';
const props = defineProps({ ship: [Object, String] });
const name = computed(() => identity(props.ship));
</script>

<template>
    <span v-if="name.name" class="bridge-name">{{ name.name }}</span>
    <span v-if="name.symbol && name.symbol !== name.name" class="bridge-callsign"> {{ name.symbol }}</span>
</template>
