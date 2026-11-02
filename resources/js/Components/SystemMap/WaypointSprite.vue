<script setup>
import { computed } from 'vue';
import { waypointSprite } from '@/waypointSprites.js';
const props = defineProps({ point: Object, reset: String });
const sprite = computed(() => waypointSprite(props.point, props.reset));
</script>
<template>
    <svg viewBox="0 0 24 24" aria-hidden="true" shape-rendering="crispEdges" :data-family="sprite.family">
        <rect v-for="pixel in sprite.pixels" :key="`${pixel.x}:${pixel.y}`" :x="pixel.x" :y="pixel.y" width="1" height="1" :fill="pixel.fill" />
    </svg>
</template>
