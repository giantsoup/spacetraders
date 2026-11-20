<script setup>
import FocusSheet from '@/Components/FocusSheet.vue';
import StrategyBranches from '@/Components/StrategyBranches.vue';
import { computed, nextTick, ref, watch } from 'vue';
const props = defineProps({ graph: { type: Object, required: true } });
const current = ref(props.graph.root);
const selected = ref([]);
const history = ref([]);
const stepsOpen = ref(false);
const heading = ref(null);
const moving = ref(false);
const chapter = computed(() => props.graph.nodes[current.value]);
const index = computed(() => props.graph.chapters.indexOf(current.value));
watch(() => props.graph, graph => { current.value = graph.root; selected.value = []; history.value = []; });
function select(depth, target) {
    selected.value = selected.value[depth] === target ? selected.value.slice(0, depth) : [...selected.value.slice(0, depth), target];
}
async function show(id, record = true) {
    stepsOpen.value = false;
    if (record && id !== current.value) history.value.push(current.value);
    selected.value = [];
    moving.value = true;
    current.value = id;
    // Let the shared focus sheet close and restore its opener before focusing the chapter.
    await nextTick();
    await nextTick();
    heading.value.focus();
    heading.value.scrollIntoView({ block: 'nearest' });
}
async function back() {
    if (selected.value.length) {
        const disclosure = Array.from(heading.value.parentElement.querySelectorAll('button[aria-expanded="true"]')).at(-1);
        selected.value = selected.value.slice(0, -1);
        await nextTick();
        disclosure?.focus();
        return;
    }
    if (history.value.length) show(history.value.pop(), false);
}
</script>

<template>
    <div class="strategy-walkthrough">
        <nav class="rail" aria-label="Walkthrough steps">
            <p class="rail-title">Steps</p>
            <ol class="steps-list">
                <li v-for="(id, step) in graph.chapters" :key="id">
                    <button class="step-button" type="button" :aria-current="current === id ? 'step' : undefined" @click="show(id)"><span class="ordinal">{{ step + 1 }}</span><span>{{ graph.nodes[id].title }}</span></button>
                </li>
            </ol>
        </nav>
        <div class="reading">
            <div class="position"><span>Step {{ index + 1 }} of {{ graph.chapters.length }} · Example decisions</span><button class="text-button open-steps" type="button" aria-haspopup="dialog" @click="stepsOpen = true">Steps</button></div>
            <section :key="current" class="story" :class="{ moving }" @animationend.self="moving = false" :aria-labelledby="`strategy-${current}`">
                <h2 :id="`strategy-${current}`" ref="heading" class="story-title" tabindex="-1">{{ chapter.title }}</h2>
                <p class="explanation">{{ chapter.summary }}</p>
                <ol v-if="chapter.groups?.length" class="phase-list" aria-label="Playbook phase order">
                    <li v-for="(group, phase) in chapter.groups" :key="group.id" class="phase">
                        <h3>{{ phase + 1 }}. {{ group.title }}</h3>
                        <h4>{{ group.exitLabel }}</h4>
                        <ul v-if="group.exits.length"><li v-for="exit in group.exits" :key="exit">{{ exit }}</li></ul>
                        <p v-if="group.note" class="phase-note">{{ group.note }}</p>
                        <h4>Activity order</h4>
                        <ol><li v-for="(activity, activityIndex) in group.activities" :key="activityIndex">{{ activity.label }}<p v-if="activity.condition" class="activity-condition">{{ activity.condition }}</p></li></ol>
                    </li>
                </ol>
                <h3 v-if="chapter.groups?.length && chapter.links.length" class="pass-heading">Pass outcomes</h3>
                <StrategyBranches :nodes="graph.nodes" :links="chapter.links" :selected="selected" @select="select" />
            </section>
            <nav class="pager" aria-label="Reading navigation">
                <button type="button" class="text-button" :disabled="!selected.length && !history.length" @click="back">← Back</button>
                <button type="button" class="text-button next" :disabled="index === graph.chapters.length - 1" @click="show(graph.chapters[index + 1])">Next step →</button>
            </nav>
        </div>
        <FocusSheet :open="stepsOpen" title="Steps" @close="stepsOpen = false">
            <ol class="steps-list">
                <li v-for="(id, step) in graph.chapters" :key="id"><button class="step-button" type="button" :aria-current="current === id ? 'step' : undefined" @click="show(id)"><span class="ordinal">{{ step + 1 }}</span><span>{{ graph.nodes[id].title }}</span></button></li>
            </ol>
        </FocusSheet>
    </div>
</template>

<style scoped>
.strategy-walkthrough { --strategy-ink: #eef5f7; --strategy-muted: #a5b9c8; --strategy-mint: #a6f3d0; --strategy-cold: #7391aa; --strategy-rule: #2b4051; --strategy-wait: #ffd697; --strategy-stop: #ffb5c3; color: var(--strategy-ink); min-width: 0; font: 15px/1.5 system-ui, sans-serif; }
.reading, .story { min-width: 0; }
.position { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--strategy-muted); margin: 13px 0 4px; font-size: 12px; min-height: 44px; }
.text-button { min-width: 44px; min-height: 44px; border: 0; background: transparent; white-space: normal; color: var(--strategy-mint); font: inherit; font-weight: 650; padding: 0 2px; }
.text-button:disabled { color: var(--strategy-muted); opacity: .55; cursor: default; }
.story-title { font-size: clamp(25px, 5vw, 34px); font-weight: 750; letter-spacing: -.035em; line-height: 1.16; margin: 0; }
.explanation { color: var(--strategy-muted); margin: 8px 0 18px; max-width: 58ch; }
.pager { display: flex; justify-content: space-between; gap: 12px; padding-top: 7px; margin-top: 17px; border-top: 1px solid var(--strategy-rule); }
.steps-list, .phase-list { list-style: none; padding: 0; margin: 0; }
.phase-list { margin: 0 0 20px 1px; border-left: 1px solid var(--strategy-rule); }
.phase { padding: 0 0 20px 16px; position: relative; }
.phase::before { content: ''; position: absolute; left: -4px; top: 9px; width: 7px; height: 7px; border-radius: 50%; background: var(--strategy-mint); }
.phase h3 { margin: 0; font-size: 16px; line-height: 1.4; font-weight: 750; }
.phase h4 { font-size: 13px; color: var(--strategy-muted); font-weight: 680; margin: 6px 0 0; }
.phase ol, .phase ul { margin: 3px 0 0; padding-left: 19px; font-size: 14px; }
.phase ol { list-style: decimal; }
.phase ul { list-style: disc; }
.phase ol li::marker { color: var(--strategy-mint); }
.pass-heading { font-size: 13px; color: var(--strategy-muted); font-weight: 680; margin: 17px 0 5px; }
.phase-note, .activity-condition { font-size: 13px; color: var(--strategy-muted); margin: 5px 0; }
.step-button { width: 100%; min-width: 44px; min-height: 49px; display: grid; grid-template-columns: 2ch minmax(0, 1fr); gap: 9px; align-items: center; text-align: left; background: none; color: var(--strategy-ink); border: 0; border-radius: 0; padding: 8px 3px; font: inherit; }
.step-button .ordinal { font: 600 12px ui-monospace, monospace; color: var(--strategy-muted); }
.step-button[aria-current] { color: var(--strategy-mint); font-weight: 710; }
.steps-list li + li { border-top: 1px solid var(--strategy-rule); }
.rail { display: none; }
.rail-title { font-size: 13px; color: var(--strategy-muted); font-weight: 650; margin: 0 0 8px; }
button:focus-visible { outline: 2px solid var(--strategy-wait); outline-offset: 3px; }
.moving { animation: strategy-slide 180ms ease-out; }
@keyframes strategy-slide { from { opacity: .35; transform: translateX(8px); } to { opacity: 1; transform: none; } }
@media(prefers-reduced-motion:reduce) { .moving { animation: none; } }
@media(min-width:760px) { .strategy-walkthrough { display: grid; grid-template-columns: 175px minmax(0, 1fr); gap: 28px; margin-top: 18px; }.rail { display: block; border-right: 1px solid var(--strategy-rule); padding-right: 16px; }.open-steps { display: none; }.position { margin-top: 0; } }
</style>
