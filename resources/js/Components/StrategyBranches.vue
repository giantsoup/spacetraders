<script setup>
import { useId } from 'vue';
defineProps({ nodes: { type: Object, required: true }, links: { type: Array, required: true }, selected: { type: Array, required: true }, depth: { type: Number, default: 0 } });
const emit = defineEmits(['select']);
const id = useId();
function select(event, depth, target) {
    event.currentTarget.focus({ preventScroll: true });
    emit('select', depth, target);
}
</script>

<template>
    <ul class="branch-list">
        <li v-for="(link, index) in links" :key="link.target" class="branch" :class="{ revealing: selected[depth] === link.target }">
            <button class="fork" type="button" :aria-expanded="selected[depth] === link.target" :aria-controls="selected[depth] === link.target ? `${id}-${index}` : undefined" :data-tone="link.tone" @click="select($event, depth, link.target)">
                <span class="condition">{{ link.condition }}</span><span class="result">{{ link.label }}</span><span class="indicator" aria-hidden="true">{{ selected[depth] === link.target ? '−' : '+' }}</span>
            </button>
            <div v-if="selected[depth] === link.target" :id="`${id}-${index}`" class="outcome">
                <h3>{{ nodes[link.target].title }}</h3>
                <p>{{ nodes[link.target].summary }}</p>
                <StrategyBranches v-if="nodes[link.target].links.length" :nodes="nodes" :links="nodes[link.target].links" :selected="selected" :depth="depth + 1" @select="(level, target) => emit('select', level, target)" />
            </div>
        </li>
    </ul>
</template>

<style scoped>
.branch-list { list-style: none; margin: 0 0 0 1px; padding: 0 0 0 18px; border-left: 1px solid var(--strategy-rule); }
.branch { position: relative; min-width: 0; }
.branch + .branch { border-top: 1px solid var(--strategy-rule); }
.fork { width: 100%; display: grid; grid-template-columns: minmax(0, 1fr) auto 12px; align-items: center; gap: 8px; text-align: left; min-height: 49px; font: inherit; font-size: 14px; border: 0; border-radius: 0; color: var(--strategy-ink); background: transparent; padding: 10px 0; }
.fork::before { content: ''; position: absolute; left: -19px; top: 25px; width: 15px; border-top: 1px solid var(--strategy-cold); transform-origin: left; }
.fork[aria-expanded=true]::before { border-color: var(--strategy-mint); }
.revealing > .fork[aria-expanded=true]::before { animation: strategy-trace 240ms ease-out; }
.condition { font-weight: 645; overflow-wrap: anywhere; }
.result { color: var(--strategy-mint); font-size: 12px; }
.fork[data-tone=wait] .result { color: var(--strategy-wait); }
.fork[data-tone=pause] .result { color: var(--strategy-stop); }
.indicator { color: var(--strategy-cold); font-family: ui-monospace, monospace; }
.outcome { padding: 0 0 16px; color: var(--strategy-muted); max-width: 58ch; animation: strategy-reveal 200ms ease-out; }
.outcome h3 { margin: 0 0 4px; font-size: 14px; color: var(--strategy-ink); font-weight: 690; }
.outcome p { margin: 0; font-size: 14px; }
.outcome .branch-list { margin-top: 12px; }
.fork:focus-visible { outline: 2px solid var(--strategy-wait); outline-offset: 3px; }
@keyframes strategy-reveal { from { opacity: .25; transform: translateY(5px); } to { opacity: 1; transform: none; } }
@keyframes strategy-trace { from { transform: scaleX(.1); } to { transform: scaleX(1); } }
@media(prefers-reduced-motion:reduce) { .outcome, .revealing > .fork[aria-expanded=true]::before { animation: none; } }
</style>
