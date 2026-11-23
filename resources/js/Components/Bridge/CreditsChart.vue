<script setup>
import { computed } from 'vue';
import { amount, fullTime, shortAmount, timestamp, validNumber } from '@/bridgeFormat.js';
const props = defineProps({ segments: { type: Array, default: () => [] } });
const plot = computed(() => {
    const segments = props.segments.map(segment => segment.map(point => ({ ...point, time: timestamp(point.at) }))
        .filter(point => Number.isFinite(point.time) && validNumber(point.credits))).filter(segment => segment.length);
    const points = segments.flat();
    if (!points.length) return null;
    const first = points[0];
    const last = points.at(-1);
    const min = Math.min(...points.map(point => point.credits));
    const max = Math.max(...points.map(point => point.credits));
    const x = point => last.time === first.time ? 240 : 4 + 472 * (point.time - first.time) / (last.time - first.time);
    const y = point => max === min ? 56 : 108 - 104 * (point.credits - min) / (max - min);
    return { segments, first, last, min, max, x, y };
});
</script>

<template>
    <figure v-if="plot" class="bridge-chart bridge-panel" :aria-label="`Credits this wipe. ${amount(plot.first.credits)} on ${fullTime(plot.first.at)} through ${amount(plot.last.credits)} on ${fullTime(plot.last.at)}.`" role="img">
        <figcaption>Credits this wipe</figcaption>
        <div class="bridge-chart-content" aria-hidden="true">
            <div class="bridge-chart-axis"><span :title="amount(plot.max)">{{ shortAmount(plot.max) }}</span><span v-if="plot.min !== plot.max" :title="amount(plot.min)">{{ shortAmount(plot.min) }}</span></div>
            <svg viewBox="0 0 480 112" preserveAspectRatio="none" focusable="false">
                <line v-for="y in [4, 56, 108]" :key="y" x1="0" :y1="y" x2="480" :y2="y" class="bridge-chart-grid" vector-effect="non-scaling-stroke" />
                <template v-for="segment in plot.segments" :key="segment[0].at">
                    <circle v-if="segment.length === 1" :cx="plot.x(segment[0])" :cy="plot.y(segment[0])" r="3" fill="currentColor"/>
                    <polyline v-else :points="segment.map(point => `${plot.x(point)},${plot.y(point)}`).join(' ')" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" />
                </template>
            </svg>
        </div>
        <div class="bridge-chart-dates bridge-detail" aria-hidden="true">
            <time :datetime="plot.first.at">{{ fullTime(plot.first.at) }}</time>
            <time v-if="plot.last.at !== plot.first.at" :datetime="plot.last.at">{{ fullTime(plot.last.at) }}</time>
        </div>
    </figure>
</template>
