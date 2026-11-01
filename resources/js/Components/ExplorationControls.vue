<script setup>
import { computed, ref } from 'vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';

const props = defineProps({ points: Array, ship: Object, disabled: Boolean });
const emit = defineEmits(['preview']);
const target = ref('');
const question = ref('MARKETPLACE');
const threshold = ref(300);
const candidates = computed(() => props.points.filter(point => point.data.traits_observed === false || (!point.data.traits_observed && point.data.traits?.some(trait => trait.symbol === 'UNCHARTED'))));
function preview(command) {
    emit('preview', { command, arguments: { waypointSymbol: target.value, question: question.value, maxCooldownSeconds: Number(threshold.value) } });
}
</script>

<template>
    <details>
        <summary>Hidden-trait exploration</summary>
        <p>Answer one question with one scan or chart. Each approval allows zero travel and zero spending, with at most 13 API requests across preview, approval and execution. Reassess afterward.</p>
        <p>Scanning needs a Sensor Array. Range and cooldown duration are unknown. A missing target stays unknown. Charting reveals your current waypoint to everyone and may reward credits; no reward is assumed in the reserve.</p>
        <fieldset :disabled="disabled">
            <legend class="sr-only">Exploration question and limits</legend>
            <div class="exploration-fields">
                <label>Unresolved waypoint<select v-model="target" aria-label="Unresolved waypoint"><option value="">Select hidden traits</option><option v-for="point in candidates" :key="point.symbol" :value="point.symbol">{{ point.symbol }}</option></select></label>
                <label>Decision-changing question<select v-model="question" aria-label="Decision-changing question"><option value="MARKETPLACE">Marketplace? Consider a directory read.</option><option value="SHIPYARD">Shipyard? Consider inspecting offers.</option></select></label>
                <label>Cooldown review threshold in seconds<input aria-label="Cooldown review threshold in seconds" v-model="threshold" type="number" min="0" max="900" step="1"></label>
            </div>
            <p>If the trait is present, inspect the directory or offers before planning a visit. If absent, exclude this candidate from that scouting decision.</p>
            <p>The threshold pauses for review if exceeded. It cannot limit the server cooldown. Orbit and an expired cooldown are required by this client. A verified affordable fuel continuation is required before approval.</p>
            <div class="exploration-buttons"><SecondaryButton :disabled="!target" @click="preview('scan_waypoints')">Preview waypoint scan</SecondaryButton><SecondaryButton :disabled="!target || target !== ship?.nav?.waypointSymbol" @click="preview('chart')">Preview chart</SecondaryButton></div>
        </fieldset>
        <p v-if="!candidates.length">No unresolved trait candidates in this saved system. Catalogue completeness does not prove hidden traits were observed.</p>
    </details>
</template>

<style scoped>
summary { cursor: pointer; color: var(--color-accent); min-height: 44px; padding: 12px 0; }
p { margin: 12px 0; }
.exploration-fields { display: grid; gap: 12px; }
label { display: grid; gap: 6px; min-width: 0; }
select, input { width: 100%; background: var(--color-field); color: var(--color-ink); border: 1px solid var(--color-line); border-radius: 8px; min-height: 44px; }
.exploration-buttons { display: flex; flex-wrap: wrap; gap: 12px; }
</style>
